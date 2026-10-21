"""Behaviour tests for the employee app's Leave (?ews_view=vacation) and Overtime pages: what the
employee and the manager see. The request/approval logic itself is pinned by e2e_leave.py and
e2e_overtime.py.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_view.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html.parser, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, results, wp, HERE  # noqa: E402

LEAVE = '/app/?ews_view=vacation'
OVERTIME = '/app/?ews_view=overtime'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_leave_requests','ews_leave_balances','ews_early_leave_requests','ews_overtime_requests','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21 WHERE name='Annual Leave'");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_feature_overtime',1,false);
        update_option('ews_early_leave_monthly_minutes',100,false); update_option('ews_early_leave_max_minutes',90,false);
        foreach(['vacation','overtime'] as $k){$wf=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key=%s",$k));
            $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".(int)$wf); $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]);}
    """)


def add_leaves(n):
    php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        $lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
        for($i=0;$i<%d;$i++){$d=date('Y-m-d',strtotime('+'.(40+$i*3).' days'));
            $wpdb->insert($p.'ews_leave_requests',['employee_id'=>$eid,'leave_type_id'=>$lt,'start_date'=>$d,'end_date'=>$d,'requested_days'=>1,'status'=>'Rejected','requested_by'=>1,'requested_at'=>date('Y-m-d H:i:s',time()-$i*60)]);}
    """ % n)


class Cards(html.parser.HTMLParser):
    """Records, for every <h3>, how many .ews-vac-card / .ews-ot-card elements enclose it."""
    VOID = {'input', 'br', 'img', 'meta', 'link', 'hr', 'source', 'area', 'col', 'embed', 'param', 'track', 'wbr'}

    def __init__(self):
        super().__init__()
        self.stack, self.h3 = [], {}
        self.in_h3 = None

    def handle_starttag(self, tag, attrs):
        if tag in self.VOID:
            return
        cls = dict(attrs).get('class') or ''
        self.stack.append('card' if re.search(r'\bews-(vac|ot)-card\b', cls) else tag)
        if tag == 'h3':
            self.in_h3 = ''

    def handle_endtag(self, tag):
        if tag in self.VOID:
            return
        if tag == 'h3' and self.in_h3 is not None:
            self.h3[self.in_h3.strip()] = self.stack.count('card')
            self.in_h3 = None
        for i in range(len(self.stack) - 1, -1, -1):
            if self.stack[i] == tag or (tag == 'div' and self.stack[i] == 'card'):
                del self.stack[i:]
                break

    def handle_data(self, data):
        if self.in_h3 is not None:
            self.in_h3 += data


def nesting(page):
    p = Cards()
    p.feed(page)
    return p.h3


seed()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
eid = ids()['eid']

# ---------------------------------------------------------------- employee: Leave page
st, page, _ = emp.req(LEAVE)
check('the Leave page opens with the request form and balances', st == 200 and 'name="leave_type_id"' in page and 'Annual Leave Available' in page, st)
check('the page script and styles are files', 'assets/js/leave.js' in page and '.ews-vac{' not in page and 'ews-vac-start"),b=' not in page)
check('the Early Leave allowance is shown in rounded hours', 'Monthly allowance: 1.67 hours' in page and '1.6666' not in page, re.findall(r'Monthly allowance: [^·]*', page))
add_leaves(6)
st, page, _ = emp.req(LEAVE)
check('six requests: no "View all" link', page.count('class="ews-vac-row"') == 6 and 'leave_history=all' not in page, page.count('class="ews-vac-row"'))
add_leaves(1)
st, page, _ = emp.req(LEAVE)
check('seven requests: six shown and a "View all" link', page.count('class="ews-vac-row"') == 6 and 'leave_history=all' in page, page.count('class="ews-vac-row"'))
st, page, _ = emp.req(LEAVE + '&leave_history=all')
check('...which lists all seven', page.count('class="ews-vac-row"') == 7)

st, page, _ = emp.req(LEAVE + '&leave_sent=1')
check('a result is shown once (as the pop-up), not again inside the page', 'class="ews-ux-modal"' in page and 'Leave request submitted successfully.' not in page)
st, page, _ = emp.req(LEAVE + '&early_error=office_only')
check('an Early Leave error opens one dialog, not two', page.count('class="ews-ux-modal"') == 1 and 'id="ews-early-leave-modal"' not in page, (page.count('class="ews-ux-modal"'), 'id="ews-early-leave-modal"' in page))

# ---------------------------------------------------------------- manager: pending lists
php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    $lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->insert($p.'ews_leave_requests',['employee_id'=>$eid,'leave_type_id'=>$lt,'start_date'=>'%(d)s','end_date'=>'%(d)s','requested_days'=>1,'status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);
    $wpdb->insert($p.'ews_early_leave_requests',['employee_id'=>$eid,'work_date'=>'%(d)s','leave_minutes'=>45,'reason'=>'Dentist','status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);
    $wpdb->insert($p.'ews_overtime_requests',['employee_id'=>$eid,'overtime_date'=>'%(d)s','start_time'=>'17:00:00','end_time'=>'18:30:00','requested_minutes'=>90,'reason'=>'Release','status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);
""" % {'d': d(10)})
st, page, _ = adm.req(LEAVE)
cards = nesting(page)
check('the manager sees the pending leave request', 'Pending Leave Requests' in cards and 'Emp One' in page)
check('an empty "Cancellation Requests" card says so', re.search(r'Cancellation Requests</h3>.*?No Cancellation Requests', page, re.S) is not None)
check('...and is closed, so the next card is not nested inside it', cards.get('Pending Early Leave') == 1, cards)

# ---------------------------------------------------------------- Overtime page
st, page, _ = emp.req(OVERTIME)
check('the Overtime page opens', st == 200 and 'name="overtime_date"' in page, st)
check('a request shows its duration as "1 h 30 min"', '1 h 30 min' in page, re.findall(r'class="ews-ot-meta">([^<]*)', page))
check('the overtime script is a file', 'assets/js/overtime.js' in page and 'ews-ot-start"),e=' not in page and '.ews-ot{' not in page)
st, page, _ = emp.req(OVERTIME + '&overtime_sent=1.5')
check('the "submitted" result is shown once', 'class="ews-ux-modal"' in page and 'You are requesting 1.5' not in page)
st, page, _ = adm.req(OVERTIME)
check('the manager sees the pending overtime request', 'Pending Overtime Requests' in page and 'Release' in page)
php("update_option('ews_feature_overtime',0,false);")
st, page, _ = emp.req(OVERTIME)
check('with Overtime switched off the page says so', 'Overtime Requests are currently disabled.' in page and 'name="overtime_date"' not in page)
php("update_option('ews_feature_overtime',1,false);")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

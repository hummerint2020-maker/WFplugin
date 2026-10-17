"""Leave types, planned and unplanned (3.31.99, owner decisions 2026-10-14, docs/tasks/approvals.md):
planned leave asked before its day (as every leave was), unplanned leave on the day or up to the days
the type allows after it, the Leaves page settings (saved, Audit Log), the app's date limits and hint,
and what is left at year end (not carried by default, all, or up to N days; the base days are not
compounded year after year).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_types.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, today, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
EID = ids()['eid']
php("""foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_approval_requests','ews_approval_steps','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
$wpdb->query("DELETE FROM {$p}ews_leave_types WHERE name IN ('Casual','Sick','Annual Plus')");
$wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0,carry_mode='none',carry_max=0 WHERE name='Annual Leave'");
update_option('ews_working_days',[0,1,2,3,4,5,6],false);
$wf=$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='vacation'");
$wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]);""")
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')


def tid(name):
    r = q("SELECT id FROM {p}ews_leave_types WHERE name='%s'" % name)
    return int(r[0]['id']) if r else 0


def leaves_nonce(page_html, type_id=0):
    forms = re.findall(r'<form method="post".*?</form>', page_html, re.S)
    for f in forms:
        if 'value="ews_leave_type_save"' in f and 'name="id" value="%d"' % type_id in f:
            return re.search(r'name="_wpnonce" value="([^"]+)"', f).group(1)
    return ''


st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-leaves')
check('the Leaves page has Kind and "Left at year end" for each type, and the add form', page.count('name="kind"') >= 2 and page.count('name="carry_mode"') >= 2 and 'Planned: asked before the day' in page)
check('...existing types are planned, nothing carried (as before)', re.search(r'<option value="planned" selected', page) and re.search(r'<option value="none" selected', page))
n = leaves_nonce(page, 0)
st, r, _ = adm.post('ews_leave_type_save', _wpnonce=n, id=0, name='Casual', annual_entitlement=6, deduct_balance=1, paid_percent=100, kind='unplanned', after_days=3, carry_mode='none', carry_max=0)
st, r, _ = adm.post('ews_leave_type_save', _wpnonce=n, id=0, name='Sick', annual_entitlement=15, deduct_balance=1, paid_percent=100, kind='unplanned', after_days=0, carry_mode='max', carry_max=5)
st, r, _ = adm.post('ews_leave_type_save', _wpnonce=n, id=0, name='Annual Plus', annual_entitlement=10, deduct_balance=1, paid_percent=100, kind='planned', after_days=9, carry_mode='all', carry_max=0)
t = {x['name']: x for x in q("SELECT name,kind,after_days,carry_mode,carry_max FROM {p}ews_leave_types WHERE name IN ('Casual','Sick','Annual Plus')")}
check('types saved: Casual unplanned up to 3 days after; Sick on the day only, up to 5 carried; Annual Plus planned, all carried',
      t.get('Casual', {}).get('kind') == 'unplanned' and t['Casual']['after_days'] == '3' and t['Sick']['after_days'] == '0' and t['Sick']['carry_mode'] == 'max'
      and float(t['Sick']['carry_max']) == 5 and t['Annual Plus']['kind'] == 'planned' and t['Annual Plus']['after_days'] == '0' and t['Annual Plus']['carry_mode'] == 'all', t)
a = q("SELECT details FROM {p}ews_audit_log WHERE action='leave_type_update'")
check('...in the Audit Log with the rules', any('Casual · unplanned up to 3 day(s) after · carry none' in x['details'] for x in a), a)
st, r, _ = adm.post('ews_leave_type_save', _wpnonce=n, id=0, name='Weird', annual_entitlement=1, deduct_balance=1, kind='sometimes', after_days=999, carry_mode='half', carry_max=-3)
w = q("SELECT kind,after_days,carry_mode,carry_max FROM {p}ews_leave_types WHERE name='Weird'")[0]
check('unknown values fall back to planned / not carried', w['kind'] == 'planned' and int(w['after_days']) == 0 and w['carry_mode'] == 'none' and float(w['carry_max']) == 0, w)
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_types WHERE name='Weird'\");")

# ---------------------------------------------------------------- asking
nc = emp.nonce('ews_vacation_request_create')


def ask(name, start, end=None):
    st, r, _ = emp.post('ews_vacation_request_create', _wpnonce=nc, leave_type_id=tid(name), start_date=start, end_date=end or start, reason='test')
    return r


def clear():
    php("$wpdb->query(\"DELETE FROM {$p}ews_leave_requests\"); $wpdb->query(\"UPDATE {$p}ews_leave_balances SET pending=0,used=0\");")


check('planned: today is refused (as before)', ask('Annual Leave', today.isoformat()).get('leave_error') == 'date')
check('planned: tomorrow is accepted', ask('Annual Leave', d(1)).get('leave_sent') == '1')
clear()
check('unplanned: today is accepted', ask('Casual', today.isoformat()).get('leave_sent') == '1')
clear()
check('unplanned: 3 days ago is accepted (up to 3 after)', ask('Casual', d(-3), d(-2)).get('leave_sent') == '2')
clear()
r = ask('Casual', d(-4))
check('unplanned: 4 days ago is refused as too late', r.get('leave_error') == 'too_late', r)
st, page, _ = emp.req('/app/?ews_view=vacation&leave_error=too_late')
check('...with its message', 'too long after them' in page)
check('unplanned on the day only: yesterday is refused, today accepted', ask('Sick', d(-1)).get('leave_error') == 'too_late' and ask('Sick', today.isoformat()).get('leave_sent') == '1')
clear()
check('unplanned: a future day is fine too', ask('Casual', d(5)).get('leave_sent') == '1')
clear()
check('unplanned uses its own balance (6 days): 7 days is refused', ask('Casual', d(-2), d(4)).get('leave_error') == 'balance')
check('...and the annual balance is untouched by unplanned leave', float(q("SELECT pending FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d" % (EID, tid('Annual Leave')))[0]['pending']) == 0)

st, page, _ = emp.req('/app/?ews_view=vacation')
opt = re.search(r'<option value="%d" data-earliest="([^"]+)" data-hint="([^"]+)"' % tid('Casual'), page)
check('the app: each type carries its first allowed day and a hint (Casual: 3 days ago)', opt and opt.group(1) == d(-3) and 'up to 3 days after it' in opt.group(2), opt and opt.groups())
opt = re.search(r'<option value="%d" data-earliest="([^"]+)" data-hint="([^"]+)"' % tid('Annual Leave'), page)
check('...planned: tomorrow, "before the day starts"', opt and opt.group(1) == d(1) and 'before the day starts' in opt.group(2), opt and opt.groups())
check('...the hint is shown on the page and the script that sets the dates is loaded', 'id="ews-vac-kind"' in page and 'leave.js' in page)

# ---------------------------------------------------------------- year end
Y = today.year
php("""$wpdb->query("DELETE FROM {$p}ews_leave_balances WHERE leave_year>=%d");
foreach([['Annual Leave',21,15,0,0],['Sick',15,4,1,0],['Annual Plus',13,3,0,3],['Casual',6,1,0,0]] as [$n,$e,$u,$pe,$c]){
  $t=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_leave_types WHERE name=%%s",$n));
  $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_leave_balances WHERE employee_id=%%d AND leave_type_id=%%d",%d,$t));
  $wpdb->insert($p.'ews_leave_balances',['employee_id'=>%d,'leave_type_id'=>$t,'leave_year'=>%d,'entitlement'=>$e,'used'=>$u,'pending'=>$pe,'carried'=>$c]);
}""" % (Y, EID, EID, Y))
st, r, _ = emp.post('ews_vacation_request_create', _wpnonce=nc, leave_type_id=tid('Annual Leave'), start_date='%d-01-10' % (Y + 1), end_date='%d-01-10' % (Y + 1), reason='next year')
bal = {x['name']: {k: (float(v) if k != 'name' else v) for k, v in x.items()} for x in q("SELECT t.name,b.entitlement,b.carried FROM {p}ews_leave_balances b JOIN {p}ews_leave_types t ON t.id=b.leave_type_id WHERE b.employee_id=%d AND b.leave_year=%d" % (EID, Y + 1))}
check('next year, not carried (the default): Annual starts again at 21, the 6 days left are not added', bal.get('Annual Leave', {}).get('entitlement') == 21 and bal['Annual Leave']['carried'] == 0, bal)
for i, name in enumerate(('Sick', 'Annual Plus', 'Casual')):
    day = '%d-01-%02d' % (Y + 1, 12 + i)
    emp.post('ews_vacation_request_create', _wpnonce=nc, leave_type_id=tid(name), start_date=day, end_date=day, reason='next year')
bal = {x['name']: {k: (float(v) if k != 'name' else v) for k, v in x.items()} for x in q("SELECT t.name,b.entitlement,b.carried FROM {p}ews_leave_balances b JOIN {p}ews_leave_types t ON t.id=b.leave_type_id WHERE b.employee_id=%d AND b.leave_year=%d" % (EID, Y + 1))}
check('up to 5 carried: Sick had 10 left (15 − 4 used − 1 pending), 5 carried → 20', bal.get('Sick', {}).get('entitlement') == 20 and bal['Sick']['carried'] == 5, bal.get('Sick'))
check('all carried: Annual Plus (10 + 3 carried last year) had 10 left → 10 base + 10 = 20, not 13 + 10', bal.get('Annual Plus', {}).get('entitlement') == 20 and bal['Annual Plus']['carried'] == 10, bal.get('Annual Plus'))
check('not carried: Casual starts again at 6', bal.get('Casual', {}).get('entitlement') == 6, bal.get('Casual'))

php("""$wpdb->query("DELETE FROM {$p}ews_leave_types WHERE name IN ('Casual','Sick','Annual Plus')");
foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots'] as $t) $wpdb->query("DELETE FROM {$p}$t");""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

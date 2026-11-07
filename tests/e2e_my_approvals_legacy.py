"""My approvals: requests decided outside an approval workflow (QA 6, owner decisions 2026-11-06,
docs/tasks/legacy-approval-cards.md, mockup docs/mockups/approval-reasons/). Overtime and corrections whose workflow is
"Not set yet", leave requests and cancellations while leave approvals are off, and early leave are cards in My approvals
for the people their handlers let decide them today, decided through those same handlers; a request with an open engine
level shows once, as its engine card; the self rule holds; no note field where the path keeps none.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_my_approvals_legacy.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402


def session_nonce(s, uid, action):
    """The nonce this logged-in session would get for an action (its form only shows with a registered face)."""
    jar = [h for h in s.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
    c = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
    return php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(%d); echo wp_create_nonce('%s');" % (json.dumps(urllib.parse.unquote(c.value)), uid, action)).strip().splitlines()[-1]

PAGE = '/wp-admin/admin.php?page=ews31-approvals'
wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_overtime_requests','ews_early_leave_requests','ews_attendance_corrections','ews_schedule_swaps','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_audit_log','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_approval_allow_self','ews_approval_self_rule','ews_approval_deadlines','ews_approval_settings','ews_approval_reject_note','ews_approval_overdue','ews_feature_my_approvals'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_feature_overtime',1,false); update_option('ews_feature_corrections',1,false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','big','sami')");
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID,'admin_uid'=>(int)get_user_by('login','admin')->ID];
    foreach(['mona'=>'Mona Manager','big'=>'Big Boss','sami'=>'Sami Colleague'] as $login=>$name){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    foreach([[$eid,$out['mona']],[$out['mona'],$out['big']],[$out['sami'],$out['mona']]] as [$e,$s]) $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>$e,'relationship_type'=>'supervisor','related_employee_id'=>$s,'active'=>1]);
    $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0 WHERE id=$type");
    foreach([$eid,$out['big']] as $e) $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$e,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);
    $out['type']=$type;
    echo wp_json_encode($out);
""").splitlines()[-1])
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
S = {k: Session(k, k + 'pass') for k in ('mona', 'big', 'sami')}


def card(page, key):
    m = re.search(r'data-approval-card="ews-approval-workflow-%s".*?(?=data-approval-card=|<div id="ews-self-decisions")' % key, page, re.S)
    return m.group(0) if m else ''


def save(key, mode, **levels):
    st, page, _ = adm.req(PAGE)
    m = re.search(r'name="_wpnonce" value="([^"]+)"', card(page, key))
    data = {'action': 'ews_approval_workflow_save', '_wpnonce': m.group(1) if m else 'x', 'workflow_key': key, 'approval_mode': mode, 'self_rule': 'allow', 'remind_hours': 0, 'escalate_hours': 0, 'overdue_hours': 0}
    data.update({k: str(v) for k, v in levels.items()})
    st, body, h = adm.req('/wp-admin/admin-post.php', data)
    return urllib.parse.unquote(html.unescape(h.get('Location', '')))


def inbox(sess, extra=''):
    return sess.req('/app/?ews_view=approvals' + extra)[1]


def items(page):
    return re.findall(r'<article class="wfo-apv-item[^"]*" data-apv-item="(\d+)"[^>]*>(.*?)</article>', page, re.S)


def form_of(page, approval_id):
    for i, body in items(page):
        if int(i) == approval_id:
            f = re.search(r'<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>', body, re.S)
            return {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f.group(1))} if f else {}
    return {}


def ar_id(entity, entity_id):
    r = q("SELECT id,status FROM {p}ews_approval_requests WHERE entity_type='%s' AND entity_id=%d" % (entity, entity_id))
    return (int(r[0]['id']), r[0]['status']) if r else (0, '')


def leave(sess, start, end=None):
    n = sess.nonce('ews_vacation_request_create')
    st, qs, _ = sess.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=end or start, reason='family trip')
    r = q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s' ORDER BY id DESC LIMIT 1" % start)
    return int(r[0]['id']) if r else 0


def start_approval(key, entity, entity_id, emp_id):
    return php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'approval_start');$m->setAccessible(true);$r=$m->invoke($o,'%s','%s',%d,%d);echo is_wp_error($r)?'ERR '.$r->get_error_code():$r;""" % (key, entity, entity_id, emp_id)).splitlines()[-1]



def card_of(page, key):
    m = re.search(r'<article class="wfo-apv-item[^"]*" data-apv-item="%s"[^>]*>(.*?)</article>' % re.escape(key), page, re.S)
    return html.unescape(m.group(1)) if m else ''


def legacy_form(page, key):
    m = re.search(r'<article class="wfo-apv-item[^"]*" data-apv-item="%s"[^>]*>.*?<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>' % re.escape(key), page, re.S)
    return {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', m.group(1))} if m else {}


def decide(sess, page, key, decision, note=''):
    f = legacy_form(page, key)
    f['decision'] = decision
    if note:
        f['note'] = note
    st, body, h = sess.req('/wp-admin/admin-post.php', f)
    return f, h.get('Location', '')


# Two deciders of today's paths: "tim" with Manage Sign In / Out, "setty" with Manage Settings (no employee records).
caps = json.loads(php("""$out=[];foreach(['tim'=>'ews_manage_time','setty'=>'ews_manage_settings'] as $login=>$cap){
    $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');$u=new WP_User($uid);$u->set_role('subscriber');$u->add_cap($cap);$out[$login]=(int)$uid;
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_employees WHERE domain_name=%s",$login));
    $wpdb->insert($p.'ews_employees',['name'=>ucfirst($login),'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);}
    update_option('ews_feature_my_approvals',1,false);update_option('ews_feature_overtime',1,false);update_option('ews_feature_corrections',1,false);
    $wpdb->query("DELETE FROM {$p}ews_early_leave_requests");
    echo wp_json_encode($out);""").splitlines()[-1])
tim, setty = Session('tim', 'timpass'), Session('setty', 'settypass')
APV = '/app/?ews_view=approvals'

# ---------------------------------------------------------------- overtime, workflow "Not set yet"
n = emp.nonce('ews_overtime_request_create', 'overtime')
emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(3), start_time='18:00', end_time='20:00', reason='month end')
oid = int(q("SELECT id FROM {p}ews_overtime_requests ORDER BY id DESC LIMIT 1")[0]['id'])
check('the overtime started no approval (workflow not set)', ar_id('overtime_request', oid) == (0, ''), ar_id('overtime_request', oid))
page = tim.req(APV)[1]
key = 'legacy-overtime_request-%d' % oid
c = card_of(page, key)
check('Manage Sign In / Out sees it in My approvals: "No approval workflow", who decides and why, no level, no note field',
      c and 'No approval workflow' in c and 'Level ' not in c and 'name="note"' not in c and 'Anyone who can manage Sign In / Out' in c and 'not set yet' in c, c[:600])
check('...counted in the menu', re.search(r'ews_view=approvals', tim.req('/app/')[1]) is not None)
check('...a manager without that permission does not see it', key not in S['mona'].req(APV)[1])
st, qs, _ = S['mona'].post('ews_overtime_request_respond', _wpnonce=session_nonce(S['mona'], ids['mona_uid'], 'ews_overtime_respond'), request_id=oid, decision='approve', approval_step=0)
check('...and cannot decide it by posting the form', qs.get('overtime_error') == 'access' and q("SELECT status FROM {p}ews_overtime_requests WHERE id=%d" % oid)[0]['status'] == 'Pending', qs)
f, loc = decide(tim, page, key, 'approve')
row = q("SELECT status,reviewed_by FROM {p}ews_overtime_requests WHERE id=%d" % oid)[0]
check('approving it there: the overtime handler approves it (reviewed by tim), back in My approvals', row['status'] == 'Approved' and row['reviewed_by'] == str(caps['tim']) and 'ews_view=approvals' in loc and 'overtime_error' not in loc, (row, loc, f))
check('...the card is gone', key not in tim.req(APV)[1])
st, body, h = tim.req('/wp-admin/admin-post.php', f)
check('...posting it again: already decided, nothing changes', 'overtime_done=1' in h.get('Location', '') and q("SELECT reviewed_by FROM {p}ews_overtime_requests WHERE id=%d" % oid)[0]['reviewed_by'] == str(caps['tim']), h.get('Location'))

# self rule: Mona, with Manage Sign In / Out, and her own overtime
php("$u=get_user_by('login','mona');$u->add_cap('ews_manage_time');update_option('ews_approval_self_rule',['overtime'=>'admins'],false);")
mona_ot = int(php("$wpdb->insert($p.'ews_overtime_requests',['employee_id'=>%d,'overtime_date'=>'%s','start_time'=>'18:00:00','end_time'=>'19:00:00','requested_minutes'=>60,'status'=>'Pending','requested_by'=>%d,'requested_at'=>current_time('mysql')]);echo $wpdb->insert_id;" % (ids['mona'], d(4), ids['mona_uid'])).splitlines()[-1])
check('her own overtime is not on her cards (the rule sends it to administrators)', 'legacy-overtime_request-%d' % mona_ot not in S['mona'].req(APV)[1])
check('...an administrator sees it', 'legacy-overtime_request-%d' % mona_ot in adm.req(APV)[1])
php("update_option('ews_approval_self_rule',['overtime'=>'allow'],false);")
check('...with the rule "they decide it", she sees it', 'legacy-overtime_request-%d' % mona_ot in S['mona'].req(APV)[1])
php("$u=get_user_by('login','mona');$u->remove_cap('ews_manage_time');delete_option('ews_approval_self_rule');")

# an open engine level: one card only, the engine one
eng = int(php("$wpdb->insert($p.'ews_overtime_requests',['employee_id'=>%d,'overtime_date'=>'%s','start_time'=>'18:00:00','end_time'=>'19:00:00','requested_minutes'=>60,'status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);echo $wpdb->insert_id;" % (ids['emp'], d(5))).splitlines()[-1])
save('overtime', 'LEVEL_1', level_1_type='SPECIFIC_USER', level_1_user_ref='Tim · #%d' % caps['tim'])
started = start_approval('overtime', 'overtime_request', eng, ids['emp'])
php("$wpdb->query(\"UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0 WHERE workflow_key='overtime'\");")
page = tim.req(APV)[1]
check('a request started on a workflow later switched off shows once, as its engine card (not a legacy one)', not started.startswith('ERR') and 'legacy-overtime_request-%d' % eng not in page
      and 'data-apv-item="%s"' % started.strip() in page, (started, 'legacy-overtime_request-%d' % eng in page))

# ---------------------------------------------------------------- early leave
el = int(php("$wpdb->insert($p.'ews_early_leave_requests',['employee_id'=>%d,'work_date'=>'%s','leave_minutes'=>60,'reason'=>'doctor','status'=>'Pending','requested_by'=>%d,'requested_at'=>current_time('mysql')]);echo $wpdb->insert_id;" % (ids['emp'], d(6), ids['emp_uid'])).splitlines()[-1])
page = tim.req(APV)[1]
key = 'legacy-early_leave-%d' % el
c = card_of(page, key)
check('early leave is a card too (Leave tone, "Early leave")', c and 'Early leave' in c and 'No approval workflow' in c, c[:400])
f, loc = decide(tim, page, key, 'reject')
check('...rejecting it there: rejected by its handler, back in My approvals', q("SELECT status FROM {p}ews_early_leave_requests WHERE id=%d" % el)[0]['status'] == 'Rejected' and 'ews_view=approvals' in loc, loc)

# ---------------------------------------------------------------- a correction, workflow not set
cid = int(php("""$wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>%d,'work_date'=>'%s','type'=>'out','sign_out_at'=>'%s 17:30:00','reason'=>'forgot','status'=>'pending','source'=>'employee','requested_by'=>%d,'requested_at'=>current_time('mysql')]);echo $wpdb->insert_id;""" % (ids['emp'], d(-1), d(-1), ids['emp_uid'])).splitlines()[-1])
page = tim.req(APV)[1]
key = 'legacy-attendance_correction-%d' % cid
c = card_of(page, key)
check('a correction is a card for Manage Sign In / Out, with its note field (a reason is needed to reject)', c and 'No approval workflow' in c and 'name="note"' in c, c[:500])
f, loc = decide(tim, page, key, 'reject')
check('...rejecting without a reason is refused by its handler', 'cx_error=note' in loc and q("SELECT status FROM {p}ews_attendance_corrections WHERE id=%d" % cid)[0]['status'] == 'pending', loc)
f, loc = decide(tim, page, key, 'reject', 'not on that day')
check('...with a reason it is rejected', q("SELECT status FROM {p}ews_attendance_corrections WHERE id=%d" % cid)[0]['status'] == 'rejected', loc)

# ---------------------------------------------------------------- leave, approvals off
php("$wpdb->query(\"UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0 WHERE workflow_key='vacation'\");")
lid = leave(emp, d(20), d(21))
page = setty.req(APV)[1]
key = 'legacy-leave-%d' % lid
check('a leave request with leave approvals off: Manage Settings sees it', lid and card_of(page, key) and 'No approval workflow' in card_of(page, key), lid)
check('...Manage Sign In / Out alone does not', key not in tim.req(APV)[1])
f, loc = decide(setty, page, key, 'approve')
check('...approving it there: approved by the leave handler', q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lid)[0]['status'] == 'Approved' and 'ews_view=approvals' in loc, loc)
php("$wpdb->update($p.'ews_leave_requests',['cancellation_status'=>'Pending'],['id'=>%d]);" % lid)
page = setty.req(APV)[1]
key = 'legacy-leave_cancellation-%d' % lid
c = card_of(page, key)
check('its cancellation is a card too, with no waiting time (no "asked on" time is kept)', c and 'Leave cancellation' in c and 'Waiting ' not in c, c[:500])
f, loc = decide(setty, page, key, 'reject')
check('...rejecting the cancellation there: the leave stays approved', q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % lid)[0] == {'status': 'Approved', 'cancellation_status': 'Rejected'}, q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % lid))
st, body, h = setty.req('/wp-admin/admin-post.php', f)
check('...a second decision: back with "already decided", not a bare page', st in (301, 302, 303) and 'leave_error=decided' in h.get('Location', ''), (st, h.get('Location')))

# ---------------------------------------------------------------- feature off: nothing
php("update_option('ews_feature_my_approvals',0,false);")
check('with My approvals off nobody gets the cards', 'data-apv-item="legacy-' not in tim.req(APV)[1])

php("""$wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('tim','setty')");foreach(['tim','setty'] as $l){$u=get_user_by('login',$l);if($u){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($u->ID);}}
delete_option('ews_feature_my_approvals');update_option('ews_feature_overtime',0,false);update_option('ews_feature_corrections',0,false);
$wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
foreach(['ews_leave_requests','ews_overtime_requests','ews_early_leave_requests','ews_attendance_corrections','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
foreach(['ews_approval_self_rule','ews_approval_allow_self','ews_approval_overdue','ews_approval_deadlines','ews_approval_reject_note'] as $o) delete_option($o);""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

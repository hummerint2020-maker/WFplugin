"""The approval engine (3.31.100; 3.31.101: a level with nobody to decide it never refuses the request, owner decisions 2026-10-14, docs/tasks/approvals.md): up to four
levels in every request type, the new approvers (manager's manager, department manager, HR, Finance),
a condition per level ("only if above N days / hours", skipped otherwise), the rule for a requester
who is their own approver (they decide / the next level / administrators), and reminders and moving a
request up after working hours (hourly job). The Approval Workflows page: the types list, HR, Finance
and the working day, the levels, the path. Driven through Leave and Overtime requests.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_approval_engine.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-approvals'

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_overtime_requests','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_audit_log','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_approval_allow_self','ews_approval_self_rule','ews_approval_deadlines','ews_approval_settings'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_feature_overtime',1,false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','big','dina','hana')");
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID,'admin_uid'=>(int)get_user_by('login','admin')->ID];
    foreach(['mona'=>'Mona Manager','big'=>'Big Boss','dina'=>'Dina Dept','hana'=>'Hana HR'] as $login=>$name){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE code='eng-e2e'");
    $wpdb->insert($p.'ews_departments',['name'=>'Engine E2E','code'=>'eng-e2e','manager_employee_id'=>$out['dina'],'active'=>1]);
    $wpdb->update($p.'ews_employees',['department_id'=>(int)$wpdb->insert_id],['id'=>$eid]);
    $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0 WHERE id=$type");
    foreach([$eid,$out['mona']] as $e) $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$e,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);
    $out['type']=$type;
    echo wp_json_encode($out);
""").splitlines()[-1])
for who, sup in (('emp', 'mona'), ('mona', 'big')):
    php("""$o=$GLOBALS['wpdb'];$o->insert($p.'ews_employee_relationships',['employee_id'=>%d,'relationship_type'=>'supervisor','related_employee_id'=>%d,'active'=>1]);""" % (ids[who], ids[sup]))

adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
S = {k: Session(k, k + 'pass') for k in ('mona', 'big', 'dina', 'hana')}


def card(page, key):
    m = re.search(r'data-approval-card="ews-approval-workflow-%s".*?(?=data-approval-card=|<div id="ews-self-decisions")' % key, page, re.S)
    return m.group(0) if m else ''


def save(key, mode, self_rule='allow', remind=0, escalate=0, **levels):
    st, page, _ = adm.req(PAGE)
    m = re.search(r'name="_wpnonce" value="([^"]+)"', card(page, key))
    data = {'action': 'ews_approval_workflow_save', '_wpnonce': m.group(1) if m else 'x', 'workflow_key': key, 'approval_mode': mode,
            'self_rule': self_rule, 'remind_hours': remind, 'escalate_hours': escalate}
    data.update({k: str(v) for k, v in levels.items()})
    st, body, h = adm.req('/wp-admin/admin-post.php', data)
    return urllib.parse.unquote(html.unescape(h.get('Location', '')))


def clear():
    php("""foreach(['ews_leave_requests','ews_leave_schedule_snapshots','ews_overtime_requests','ews_approval_requests','ews_approval_steps','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("UPDATE {$p}ews_leave_balances SET pending=0,used=0");""")


def leave(sess, start, end=None):
    n = sess.nonce('ews_vacation_request_create')
    st, qs, _ = sess.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=end or start, reason='x')
    lid = q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s' ORDER BY id DESC LIMIT 1" % start)
    return qs, int(lid[0]['id']) if lid else 0


def steps(entity_id, entity='leave'):
    ar = q("SELECT id,status,current_step FROM {p}ews_approval_requests WHERE entity_type='%s' AND entity_id=%d" % (entity, entity_id))
    if not ar:
        return None, []
    return ar[0], q("SELECT id,step_order,status,approver_wp_user_id,resolver_type,rerouted,skip_reason,pending_since,reminded_at,escalations,escalated_from_wp_user_id FROM {p}ews_approval_steps WHERE approval_request_id=%s ORDER BY step_order" % ar[0]['id'])


def decide(sess, lid, decision='approve'):
    n = sess.nonce('ews_vacation_request_respond')
    return sess.post('ews_vacation_request_respond', _wpnonce=n, request_id=lid, decision=decision)


def leave_status(lid):
    r = q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lid)
    return r[0]['status'] if r else None


def tick(now):
    return php("""$o=new EWS_Manager_V31_1();echo wp_json_encode($o->approval_deadlines_tick('%s'));""" % now).splitlines()[-1]


# ---------------------------------------------------------------- the page
st, page, _ = adm.req(PAGE)
check('the page lists every request type with "Set up"', 'data-apv-types' in page and all('data-apv-type="%s"' % k in page for k in ('vacation', 'overtime', 'face_reset', 'attendance_correction', 'shift_swap')) and page.count('>Set up</a>') == 5)
check('...nobody decides: "Waits" everywhere by default', page.count('<td>Waits</td>') == 5)
c = card(page, 'vacation')
check('a type has four levels and offers Levels 1 to 4', all('data-level="%d"' % i in c for i in (1, 2, 3, 4)) and '<option value="LEVEL_4"' in c and 'data-level="5"' not in c)
check('...with every approver: direct manager, manager\'s manager, department manager, team manager, HR, Finance, a person',
      all('value="%s"' % v in c for v in ('SUPERVISOR', 'MANAGERS_MANAGER', 'DEPARTMENT_MANAGER', 'TEAM_MANAGER', 'HR', 'FINANCE', 'SPECIFIC_EMPLOYEE', 'SPECIFIC_USER')) and "Manager&#039;s manager" in c)
check('...a condition per level in days for Leave, in hours for Overtime, none for the others',
      c.count('name="level_1_when"') == 1 and ' days</span>' in c and ' hours</span>' in card(page, 'overtime') and 'level_1_when' not in card(page, 'face_reset') and 'level_1_when' not in card(page, 'shift_swap'))
check('...reminder and move-up hours (0 = never), the requester rule, the path', 'name="remind_hours" value="0"' in c and 'name="escalate_hours" value="0"' in c and 'data-apv-path' in c and 'name="self_rule" value="allow" checked' in c)
check('the HR, Finance and working day card, 09:00–17:00 by default', 'id="ews-approval-settings"' in page and 'name="day_start" value="09:00"' in page and 'name="day_end" value="17:00"' in page)

n = re.search(r'name="_wpnonce" value="([^"]+)"><input type="hidden" name="action" value="ews_approval_settings_save"', page).group(1)
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_approval_settings_save', '_wpnonce': n, 'hr_user_ref': 'x · #%d' % ids['hana_uid'], 'finance_user_ref': '', 'day_start': '17:00', 'day_end': '09:00'})
check('a working day that ends before it starts is refused', 'approval_error=1' in h.get('Location', '') and php("echo wp_json_encode(get_option('ews_approval_settings'));").endswith('false'))
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_approval_settings_save', '_wpnonce': n, 'hr_user_ref': 'Hana · #%d' % ids['hana_uid'], 'finance_user_ref': '', 'day_start': '09:00', 'day_end': '17:00'})
s = json.loads(php("echo wp_json_encode(get_option('ews_approval_settings'));").splitlines()[-1])
check('HR is saved (Finance not set), in the Audit Log', s.get('hr_user') == ids['hana_uid'] and s.get('finance_user') == 0 and q("SELECT details FROM {p}ews_audit_log WHERE action='approval_settings'"), s)

# ---------------------------------------------------------------- three levels, the third only above 3 days
loc = save('vacation', 'LEVEL_3', level_1_type='SUPERVISOR', level_2_type='MANAGERS_MANAGER', level_3_type='HR', level_3_when='above', level_3_min='3')
w = q("SELECT step_order,resolver_type,min_amount FROM {p}ews_approval_workflow_steps s JOIN {p}ews_approval_workflows w ON w.id=s.workflow_id WHERE w.workflow_key='vacation' ORDER BY step_order")
check('Leave saved with three levels: direct manager, manager\'s manager, HR only above 3 days', 'approval_saved=1' in loc and [(x['resolver_type'], x['min_amount']) for x in w][:2] == [('SUPERVISOR', None), ('MANAGERS_MANAGER', None)]
      and w[2]['resolver_type'] == 'HR' and float(w[2]['min_amount']) == 3, (loc, w))
st, page, _ = adm.req(PAGE)
check('...the path shows the condition', 'only if above 3 days; skipped otherwise' in card(page, 'vacation'))

clear()
qs, lid = leave(emp, d(3), d(4))
ar, st_ = steps(lid)
check('2 days: level 3 (HR) is skipped, the direct manager decides first', ar and ar['status'] == 'WAITING_FOR_LEVEL_1' and [x['status'] for x in st_] == ['PENDING', 'WAITING', 'SKIPPED']
      and st_[2]['skip_reason'] == 'condition' and st_[0]['approver_wp_user_id'] == str(ids['mona_uid']) and st_[1]['approver_wp_user_id'] == str(ids['big_uid']) and st_[0]['pending_since'], (qs, ar, st_))
decide(S['mona'], lid)
decide(S['big'], lid)
ar, st_ = steps(lid)
check('...manager, then the manager\'s manager approve: the leave is approved (HR never asked)', ar['status'] == 'APPROVED' and leave_status(lid) == 'Approved', (ar, st_))

qs, lid = leave(emp, d(6), d(10))
ar, st_ = steps(lid)
check('5 days: all three levels, HR third', [x['status'] for x in st_] == ['PENDING', 'WAITING', 'WAITING'] and st_[2]['approver_wp_user_id'] == str(ids['hana_uid']), st_)
decide(S['mona'], lid)
ar, st_ = steps(lid)
check('...level 1 approves: level 2 waits, since now', ar['status'] == 'WAITING_FOR_LEVEL_2' and st_[1]['status'] == 'PENDING' and st_[1]['pending_since'], (ar, st_))
qs2 = decide(S['hana'], lid)[1]
check('...HR cannot decide before its level', steps(lid)[0]['status'] == 'WAITING_FOR_LEVEL_2')
decide(S['big'], lid)
decide(S['hana'], lid)
ar, st_ = steps(lid)
check('...the manager\'s manager, then HR: approved', ar['status'] == 'APPROVED' and leave_status(lid) == 'Approved' and [x['status'] for x in st_] == ['APPROVED'] * 3, (ar, st_))

# ---------------------------------------------------------------- four levels, department manager, missing people
# 3.31.101: a level with nobody to decide it never refuses the request: the next level decides, or
# administrators when it was the last one.
clear()
save('vacation', 'LEVEL_4', level_1_type='DEPARTMENT_MANAGER', level_2_type='TEAM_MANAGER', level_3_type='FINANCE', level_4_type='SUPERVISOR')
st, page, _ = adm.req(PAGE)
check('the page warns that Finance is not set yet', 'data-apv-missing' in card(page, 'vacation') and 'the next level decides instead' in card(page, 'vacation'))
qs, lid = leave(emp, d(3))
ar, st_ = steps(lid)
check('four levels: the department manager first; no team manager and Finance not set → both skipped, the direct manager is level 4',
      qs.get('leave_sent') == '1' and ar and [x['status'] for x in st_] == ['PENDING', 'SKIPPED', 'SKIPPED', 'WAITING'] and [x['skip_reason'] for x in st_] == [None, 'missing', 'missing', None]
      and st_[0]['approver_wp_user_id'] == str(ids['dina_uid']) and st_[3]['approver_wp_user_id'] == str(ids['mona_uid']), (qs, ar, st_))
decide(S['dina'], lid)
ar, st_ = steps(lid)
check('...the department manager approves: straight to level 4', ar['status'] == 'WAITING_FOR_LEVEL_4' and st_[3]['status'] == 'PENDING', (ar, st_))
decide(S['mona'], lid)
check('...who approves it: approved', steps(lid)[0]['status'] == 'APPROVED' and leave_status(lid) == 'Approved')

clear()
save('vacation', 'LEVEL_2', level_1_type='SUPERVISOR', level_2_type='FINANCE')
qs, lid = leave(emp, d(3))
ar, st_ = steps(lid)
check('the last level has nobody (Finance not set): administrators decide it', ar and [x['rerouted'] for x in st_] == ['0', '1'] and st_[1]['approver_wp_user_id'] is None and st_[1]['skip_reason'] is None, st_)
decide(S['mona'], lid)
ar, st_ = steps(lid)
admin_note = q("SELECT title FROM {p}ews_notifications WHERE user_id=%d AND title='Approval needed'" % ids['admin_uid'])
check('...at level 2 the administrators are told', ar['status'] == 'WAITING_FOR_LEVEL_2' and admin_note, (ar, admin_note))
n = adm.admin_request_nonce('leave', lid)
adm.req('/wp-admin/admin-post.php', {'action': 'ews31_requests_decision', '_wpnonce': n or 'x', 'request_type': 'leave', 'request_id': lid, 'decision': 'approve'})
check('...and an administrator approves it on the Requests Hub', steps(lid)[0]['status'] == 'APPROVED' and leave_status(lid) == 'Approved', steps(lid))

clear()
save('vacation', 'LEVEL_1', self_rule='admins', level_1_type='SUPERVISOR')
php("$wpdb->insert($p.'ews_leave_balances',['employee_id'=>%d,'leave_type_id'=>%d,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);" % (ids['big'], ids['type']))
qs, lid = leave(S['big'], d(3))
ar, st_ = steps(lid)
check('someone with no manager (the owner\'s case, one level = direct manager) can ask: administrators decide, they are told',
      qs.get('leave_sent') == '1' and ar and ar['status'] == 'WAITING_FOR_LEVEL_1' and st_[0]['rerouted'] == '1' and st_[0]['approver_wp_user_id'] is None
      and q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='Approval needed'" % ids['admin_uid']), (qs, ar, st_))

clear()
save('vacation', 'LEVEL_2', level_1_type='DEPARTMENT_MANAGER', level_2_type='SUPERVISOR')
php("$wpdb->update($p.'ews_employees',['department_id'=>null],['id'=>%d]);" % ids['emp'])
qs, lid = leave(emp, d(3))
ar, st_ = steps(lid)
check('no department for this employee: level 1 skipped, the direct manager decides', ar and ar['status'] == 'WAITING_FOR_LEVEL_2' and st_[0]['skip_reason'] == 'missing' and st_[1]['approver_wp_user_id'] == str(ids['mona_uid']), (qs, st_))
php("$wpdb->query(\"UPDATE {$p}ews_employees SET department_id=(SELECT id FROM {$p}ews_departments WHERE code='eng-e2e') WHERE id=%d\");" % ids['emp'])

# attendance corrections go through the same engine
php("update_option('ews_feature_corrections',1,false);")
save('attendance_correction', 'LEVEL_1', level_1_type='SUPERVISOR')
cid = php("""wp_set_current_user(%d);$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'approval_start');$m->setAccessible(true);
$r=$m->invoke($o,'attendance_correction','attendance_correction',987654,%d);echo is_wp_error($r)?'ERR '.$r->get_error_code():$r;""" % (ids['big_uid'], ids['big'])).splitlines()[-1]
ar, st_ = steps(987654, 'attendance_correction')
check('an attendance correction from someone with no manager is accepted too: administrators decide it', not cid.startswith('ERR') and ar and st_[0]['rerouted'] == '1', (cid, st_))
php("update_option('ews_feature_corrections',0,false); $wpdb->query(\"UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0 WHERE workflow_key='attendance_correction'\");")

# ---------------------------------------------------------------- nothing applies
clear()
save('vacation', 'LEVEL_2', level_1_type='SUPERVISOR', level_1_when='above', level_1_min='5', level_2_type='HR', level_2_when='above', level_2_min='10')
qs, lid = leave(emp, d(3))
ar, st_ = steps(lid)
check('one day with every level only above 5 / 10 days: approved at once', qs.get('leave_sent') == '1' and ar['status'] == 'APPROVED' and leave_status(lid) == 'Approved' and [x['status'] for x in st_] == ['SKIPPED', 'SKIPPED'], (qs, ar, st_))
check('..."Always" saves no condition', save('vacation', 'LEVEL_1', level_1_type='SUPERVISOR', level_1_when='always', level_1_min='5') and
      q("SELECT min_amount FROM {p}ews_approval_workflow_steps s JOIN {p}ews_approval_workflows w ON w.id=s.workflow_id WHERE w.workflow_key='vacation'")[0]['min_amount'] is None)

# ---------------------------------------------------------------- the requester is the approver
clear()
loc = save('vacation', 'LEVEL_2', self_rule='next', level_1_type='SUPERVISOR', level_2_type='SPECIFIC_EMPLOYEE', level_2_employee_ref='Mona Manager · #%d' % ids['mona'])
check('"It goes to the next level" is saved and in the Audit Log', 'approval_saved=1' in loc and q("SELECT details FROM {p}ews_audit_log WHERE action='approval_self_setting'")[-1]['details'] == 'vacation=next level')
qs, lid = leave(S['mona'], d(3))
ar, st_ = steps(lid)
check('Mona asks: level 1 is Big Boss, level 2 is Mona herself, the last → administrators decide it', ar and st_[0]['approver_wp_user_id'] == str(ids['big_uid']) and st_[1]['rerouted'] == '1' and st_[1]['approver_wp_user_id'] is None, st_)
clear()
save('vacation', 'LEVEL_2', self_rule='next', level_1_type='SPECIFIC_EMPLOYEE', level_1_employee_ref='Mona Manager · #%d' % ids['mona'], level_2_type='SUPERVISOR')
qs, lid = leave(S['mona'], d(3))
ar, st_ = steps(lid)
check('...level 1 is Mona herself: skipped, her manager (level 2) decides', ar and ar['status'] == 'WAITING_FOR_LEVEL_2' and st_[0]['status'] == 'SKIPPED' and st_[0]['skip_reason'] == 'self' and st_[1]['status'] == 'PENDING', (ar, st_))
decide(S['big'], lid)
check('...and approves it', steps(lid)[0]['status'] == 'APPROVED' and leave_status(lid) == 'Approved')
save('vacation', 'LEVEL_1', self_rule='admins', level_1_type='SUPERVISOR')
st, page, _ = adm.req(PAGE)
check('"It goes to administrators" shows as chosen', 'name="self_rule" value="admins" checked' in card(page, 'vacation'))

# ---------------------------------------------------------------- reminders and moving up
clear()
check('with no hours set the hourly job does nothing', tick('2026-01-01 10:00:00') == '{"reminded":0,"moved":0}')
loc = save('vacation', 'LEVEL_1', remind=4, escalate=4, level_1_type='SUPERVISOR')
dl = json.loads(php("echo wp_json_encode(get_option('ews_approval_deadlines'));").splitlines()[-1])
check('a reminder at the move up is dropped; the hours are in the Audit Log', dl.get('vacation') == {'remind': 0, 'escalate': 4} and q("SELECT details FROM {p}ews_audit_log WHERE action='approval_deadlines'")[-1]['details'] == 'vacation · remind 0 h · move up 4 h', dl)
save('vacation', 'LEVEL_1', remind=2, escalate=6, level_1_type='SUPERVISOR')
st, page, _ = adm.req(PAGE)
check('...saved as 2 and 6, shown in the types list', 'Reminder after 2 h · moves up after 6 h' in page and 'name="remind_hours" value="2"' in card(page, 'vacation'))
qs, lid = leave(emp, d(3))
ar, st_ = steps(lid)
php("$wpdb->query(\"UPDATE {$p}ews_approval_steps SET pending_since='2026-10-14 16:00:00' WHERE id=%s\");" % st_[0]['id'])
check('16:00 → 18:00: one working hour, nothing yet', tick('2026-10-14 18:00:00') == '{"reminded":0,"moved":0}')
check('...next day 10:00: two working hours, Mona is reminded', tick('2026-10-15 10:00:00') == '{"reminded":1,"moved":0}'
      and q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='A request waits for you'" % ids['mona_uid']))
check('...once', tick('2026-10-15 11:00:00') == '{"reminded":0,"moved":0}' and steps(lid)[1][0]['reminded_at'])
check('...14:00: six working hours, it moves up to her manager', tick('2026-10-15 14:00:00') == '{"reminded":0,"moved":1}')
ar, st_ = steps(lid)
check('...the step now waits for Big Boss, counting again from now, and keeps who it was for', st_[0]['approver_wp_user_id'] == str(ids['big_uid']) and st_[0]['escalations'] == '1'
      and st_[0]['escalated_from_wp_user_id'] == str(ids['mona_uid']) and st_[0]['pending_since'] == '2026-10-15 14:00:00' and st_[0]['reminded_at'] is None, st_)
check('...Mona is told it moved up; Big Boss is told it waits for him; the Audit Log has it',
      q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='A request moved up'" % ids['mona_uid']) and q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='A request waits for you'" % ids['big_uid'])
      and 'vacation · level 1 · user #%d → employee #%d' % (ids['mona_uid'], ids['big']) in q("SELECT details FROM {p}ews_audit_log WHERE action='approval_escalated'")[-1]['details'])
qs2 = decide(S['mona'], lid)
check('...Mona can no longer decide it', steps(lid)[0]['status'] == 'WAITING_FOR_LEVEL_1')
check('...a day later Big Boss has no manager: it moves up to the administrators', tick('2026-10-16 14:00:00') == '{"reminded":0,"moved":1}')
ar, st_ = steps(lid)
check('...who are told; and then it waits there (no more moves)', st_[0]['rerouted'] == '1' and st_[0]['approver_wp_user_id'] is None and st_[0]['escalations'] == '2'
      and q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='Approval needed'" % ids['admin_uid']) and tick('2026-10-20 14:00:00') == '{"reminded":0,"moved":0}', st_)
save('vacation', 'LEVEL_1', level_1_type='SUPERVISOR')

# ---------------------------------------------------------------- overtime: levels and hours
clear()
loc = save('overtime', 'LEVEL_3', level_1_type='SUPERVISOR', level_2_type='MANAGERS_MANAGER', level_2_when='above', level_2_min='2', level_3_type='HR', level_3_when='above', level_3_min='4')
n = emp.nonce('ews_overtime_request_create', 'overtime')
emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(2), start_time='18:00', end_time='21:00', reason='x')
r = q("SELECT id FROM {p}ews_overtime_requests ORDER BY id DESC LIMIT 1")
ar, st_ = steps(int(r[0]['id']) if r else 0, 'overtime_request')
check('Overtime, three levels: 3 hours → the manager\'s manager (above 2 h) decides, HR (above 4 h) is skipped',
      ar and ar['status'] == 'WAITING_FOR_LEVEL_1' and [x['status'] for x in st_] == ['PENDING', 'WAITING', 'SKIPPED'], (loc, r, ar, st_))
save('overtime', 'NONE')
php("update_option('ews_feature_overtime',0,false); $wpdb->query(\"UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0\"); foreach(['ews_approval_self_rule','ews_approval_deadlines','ews_approval_settings','ews_approval_allow_self'] as $o) delete_option($o);")
clear()
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

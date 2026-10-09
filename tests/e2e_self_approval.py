"""Behaviour tests for self-approval in approval workflows (3.31.75): a requester who is their own
approver decides (allowed, the default) and is marked; with the switch off the level goes to the
site's administrators, the requester cannot decide it, and other people's requests are unaffected.
Driven through Leave requests (workflow "vacation").

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_self_approval.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-approvals'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_audit_log','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
        delete_option('ews_approval_allow_self');
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        $uid=username_exists('boss')?:wp_create_user('boss','bosspass','boss@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>'Boss Person','domain_name'=>'boss','email'=>'boss@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $boss=(int)$wpdb->insert_id;
        $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
        foreach([$eid,$boss] as $e) $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$e,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);
        echo wp_json_encode(['emp'=>$eid,'boss'=>$boss,'boss_uid'=>(int)$uid,'admin_uid'=>(int)get_user_by('login','admin')->ID,'type'=>$type]);
    """)
    return json.loads(out.splitlines()[-1])


def card(page, key):
    m = re.search(r'data-approval-card="ews-approval-workflow-%s".*?(?=data-approval-card=|<div id="ews-self-decisions")' % key, page, re.S)
    return m.group(0) if m else ''


def save(sess, key, allow_self, **levels):
    st, page, _ = sess.req(PAGE)
    m = re.search(r'name="_wpnonce" value="([^"]+)"', card(page, key))
    data = {'action': 'ews_approval_workflow_save', '_wpnonce': m.group(1) if m else 'x', 'workflow_key': key, 'approval_mode': 'LEVEL_1',
            'level_1_type': 'SPECIFIC_EMPLOYEE', 'level_2_type': 'SUPERVISOR'}
    if allow_self:
        data['allow_self'] = '1'
    data.update({k: str(v) for k, v in levels.items()})
    st, body, h = sess.req('/wp-admin/admin-post.php', data)
    return urllib.parse.unquote(html.unescape(h.get('Location', '')))


def submit(sess, start):
    n = sess.nonce('ews_vacation_request_create')
    st, qs, _ = sess.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=start, reason='x')
    lid = q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s' ORDER BY id DESC LIMIT 1" % start)
    return qs, int(lid[0]['id']) if lid else 0


def steps(lid):
    ar = q("SELECT id,status FROM {p}ews_approval_requests WHERE entity_type='leave' AND entity_id=%d" % lid)
    if not ar:
        return None, []
    return ar[0], q("SELECT step_order,status,approver_wp_user_id,acted_by_wp_user_id,self_decision,rerouted FROM {p}ews_approval_steps WHERE approval_request_id=%s ORDER BY step_order" % ar[0]['id'])


def leave_status(lid):
    r = q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lid)
    return r[0]['status'] if r else None


def decide(sess, lid, decision):
    n = sess.nonce('ews_vacation_request_respond')
    return sess.post('ews_vacation_request_respond', _wpnonce=n, request_id=lid, decision=decision)


ids = seed()
adm, boss, emp = Session('admin', 'admin'), Session('boss', 'bosspass'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- default: allowed
st, page, _ = adm.req(PAGE)
check('the switch is shown on every workflow card, on by default',
      all(re.search(r'name="allow_self" value="1" checked', card(page, k)) for k in ('vacation', 'overtime', 'face_reset')), [k for k in ('vacation', 'overtime', 'face_reset') if 'name="allow_self"' not in card(page, k)])
check('the Self-decisions list is on the page, empty', 'id="ews-self-decisions"' in page and 'No self-decisions yet.' in page)
loc = save(adm, 'vacation', True, level_1_employee_ref='Boss Person · #%d' % ids['boss'])
check('Level 1 = the boss is saved (allowed)', 'approval_saved=1' in loc and php("echo (int)!empty(get_option('ews_approval_allow_self')['vacation']);").endswith('1'), loc)

qs, lid = submit(boss, d(3))
ar, st_ = steps(lid)
check('the boss asks for leave: level 1 is the boss himself', ar and ar['status'] == 'WAITING_FOR_LEVEL_1' and st_[0]['approver_wp_user_id'] == str(ids['boss_uid']) and st_[0]['rerouted'] == '0', (qs, ar, st_))
decide(boss, lid, 'approve')
ar, st_ = steps(lid)
check('allowed: the boss approves his own leave', ar['status'] == 'APPROVED' and leave_status(lid) == 'Approved', (ar, leave_status(lid)))
check('...the step is marked a self-decision, decided by the boss', st_[0]['self_decision'] == '1' and st_[0]['acted_by_wp_user_id'] == str(ids['boss_uid']), st_)
audit = q("SELECT action,entity_id,details FROM {p}ews_audit_log WHERE action='approval_self_decision'")
check('...and is in the Audit Log', len(audit) == 1 and audit[0]['entity_id'] == ar['id'] and audit[0]['details'] == 'vacation · level 1 · approve', audit)
st, page, _ = adm.req(PAGE)
check('...and listed under Self-decisions as Self-approved', re.search(r'data-self-decision="%s".*?Boss Person.*?Self-approved' % ar['id'], page, re.S) is not None)

qs, lid_e = submit(emp, d(4))
decide(boss, lid_e, 'approve')
ar, st_ = steps(lid_e)
check('someone else\'s request decided by the boss is not a self-decision', ar['status'] == 'APPROVED' and st_[0]['self_decision'] == '0' and st_[0]['acted_by_wp_user_id'] == str(ids['boss_uid']), st_)

# ---------------------------------------------------------------- switched off
loc = save(adm, 'vacation', False, level_1_employee_ref='Boss Person · #%d' % ids['boss'])
check('switching it off is saved and in the Audit Log', 'approval_saved=1' in loc and php("echo (int)!empty(get_option('ews_approval_allow_self')['vacation']);").endswith('0')
      and q("SELECT details FROM {p}ews_audit_log WHERE action='approval_self_setting'")[-1]['details'] == 'vacation=not allowed')
st, page, _ = adm.req(PAGE)
check('...and shows unchecked', 'name="allow_self" value="1">' in card(page, 'vacation') and 'name="allow_self" value="1" checked' not in card(page, 'vacation'))

php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\");")
qs, lid = submit(boss, d(6))
ar, st_ = steps(lid)
check('off: the boss\'s own leave still starts (no error)', not qs.get('leave_error') and ar and ar['status'] == 'WAITING_FOR_LEVEL_1', (qs, ar))
check('...its level goes to administrators: no approver, marked rerouted, pending', st_[0]['approver_wp_user_id'] is None and st_[0]['rerouted'] == '1' and st_[0]['status'] == 'PENDING', st_)
notes = q("SELECT user_id,title FROM {p}ews_notifications WHERE title='Approval needed'")
check('...administrators are told, the boss is not', any(n['user_id'] == str(ids['admin_uid']) for n in notes) and all(n['user_id'] != str(ids['boss_uid']) for n in notes), notes)
decide(boss, lid, 'approve')
ar, st_ = steps(lid)
check('...the boss cannot approve it', ar['status'] == 'WAITING_FOR_LEVEL_1' and leave_status(lid) == 'Pending', (ar, leave_status(lid)))
st, hub, _ = adm.req('/wp-admin/admin.php?page=ews31-requests')
check('...the Requests Hub shows it waits for an administrator', 'For an administrator (the approver made the request)' in hub)
n = adm.admin_request_nonce('leave', lid)
adm.post('ews31_requests_decision', _wpnonce=n or 'missing', request_type='leave', request_id=lid, decision='approve')
ar, st_ = steps(lid)
check('...an administrator approves it on the Requests Hub', ar['status'] == 'APPROVED' and leave_status(lid) == 'Approved' and st_[0]['acted_by_wp_user_id'] == str(ids['admin_uid']) and st_[0]['self_decision'] == '0', (ar, st_))

qs, lid_e = submit(emp, d(7))
ar, st_ = steps(lid_e)
check('off: other people\'s requests still go to the boss as before', st_ and st_[0]['approver_wp_user_id'] == str(ids['boss_uid']) and st_[0]['rerouted'] == '0', st_)
decide(boss, lid_e, 'approve')
check('...and he decides them', steps(lid_e)[0]['status'] == 'APPROVED')

# an administrator who asks for their own leave cannot push it through the Requests Hub either
php("$e=(int)$wpdb->get_var(\"SELECT id FROM {$p}ews_employees WHERE domain_name='boss'\"); $wpdb->update($p.'ews_employees',['wp_user_id'=>%d],['id'=>$e]);" % ids['admin_uid'])
qs, lid = submit(adm, d(9))
n = adm.admin_request_nonce('leave', lid)
adm.post('ews31_requests_decision', _wpnonce=n or 'missing', request_type='leave', request_id=lid, decision='approve')
ar, st_ = steps(lid)
check('off: an administrator cannot approve their own request through the Requests Hub', ar and ar['status'] == 'WAITING_FOR_LEVEL_1' and leave_status(lid) == 'Pending', (qs, ar, leave_status(lid)))
php("$wpdb->update($p.'ews_employees',['wp_user_id'=>%d],['domain_name'=>'boss']);" % ids['boss_uid'])

print('\n%d/%d passed' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

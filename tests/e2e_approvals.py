"""Behaviour tests for wp-admin → Approval Workflows (the approval engine's settings) and the engine's
multi-level flow, driven through Leave requests.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_approvals.py <state-dir>
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
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        $user=function($login){$u=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');return (int)$u;};
        $emp=function($name,$uid)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','wp_user_id'=>$uid?:null,'active'=>1,'attendance_enabled'=>1]);return (int)$wpdb->insert_id;};
        $ids=['emp'=>$eid,'boss'=>$emp('Boss Person',$user('boss')),'tina'=>$emp('Tina Lead',$user('tina')),'nouser'=>$emp('Nora Nouser',0)];
        $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
        $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);
        $ids['type']=$type;
        echo wp_json_encode($ids);
    """)
    return json.loads(out.splitlines()[-1])


def forms(page, action):
    out = []
    for f in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in f:
            out.append(f)
    return out


def field(form, name):
    m = re.search(r'name="%s" value="([^"]*)"' % re.escape(name), form)
    return html.unescape(m.group(1)) if m else ''


def card(page, key):
    """The settings card of one workflow."""
    m = re.search(r'data-approval-card="ews-approval-workflow-%s".*?(?=data-approval-card=|<h2[^>]*>Supervisor Relationships)' % key, page, re.S)
    return m.group(0) if m else ''


def modes(page, key):
    c = card(page, key)
    m = re.search(r'<select[^>]*name="approval_mode".*?</select>', c, re.S)
    return re.findall(r'<option value="([A-Z_0-9]+)"', m.group(0)) if m else []


def workflow(key):
    w = q("SELECT id,approval_mode,active FROM {p}ews_approval_workflows WHERE workflow_key='%s'" % key)[0]
    steps = q("SELECT step_order,step_type,resolver_type,resolver_value FROM {p}ews_approval_workflow_steps WHERE workflow_id=%s ORDER BY step_order" % w['id'])
    return w['approval_mode'], str(w['active']), [(str(s['step_order']), s['resolver_type'], s['resolver_value']) for s in steps]


def save(sess, nonce, key, mode, **levels):
    data = {'action': 'ews_approval_workflow_save', '_wpnonce': nonce, 'workflow_key': key, 'approval_mode': mode,
            'level_1_type': 'SUPERVISOR', 'level_1_employee': '0', 'level_1_user': '0', 'level_2_type': 'SUPERVISOR', 'level_2_employee': '0', 'level_2_user': '0'}
    data.update({k: str(v) for k, v in levels.items()})
    st, body, h = sess.req('/wp-admin/admin-post.php', data)
    return st, urllib.parse.unquote(html.unescape(h.get('Location', ''))), body


ids = seed()
adm = Session('admin', 'admin')

# ---------------------------------------------------------------- the settings page
st, page, _ = adm.req(PAGE)
check('the page shows a card for each workflow in use (Vacation, Overtime, Face Reset)', all(card(page, k) for k in ('vacation', 'overtime', 'face_reset')), [k for k in ('vacation', 'overtime', 'face_reset') if not card(page, k)])
check('...and no settings for Early Leave or Shift Swap, which do not use approval workflows', not forms(card(page, 'early_leave'), 'ews_approval_workflow_save') and not forms(card(page, 'shift_swap'), 'ews_approval_workflow_save')
      and 'Early Leave' in page and 'not use approval workflows' in page)
check('Vacation and Overtime offer only the modes they support (none, 1 level, 2 levels)', modes(page, 'vacation') == ['NONE', 'LEVEL_1', 'LEVEL_2'] and modes(page, 'overtime') == ['NONE', 'LEVEL_1', 'LEVEL_2'], (modes(page, 'vacation'), modes(page, 'overtime')))
check('"No approval" says what it does: managers decide a leave, overtime is approved automatically',
      'No approval chain: managers decide' in card(page, 'vacation') and 'approved automatically' in card(page, 'overtime'))
check('Face Reset also offers Sequential', modes(page, 'face_reset') == ['NONE', 'LEVEL_1', 'LEVEL_2', 'SEQUENTIAL'], modes(page, 'face_reset'))
check('the page script is a file, not inline', 'function syncCard' not in page and 'admin-approvals.js' in page)
nonce = field(forms(page, 'ews_approval_workflow_save')[0], '_wpnonce') if forms(page, 'ews_approval_workflow_save') else ''

st, loc, _ = save(adm, nonce, 'vacation', 'LEVEL_2', level_1_type='SUPERVISOR', level_2_type='SPECIFIC_EMPLOYEE', level_2_employee=ids['tina'])
check('saving Level 1 + Level 2 stores both steps', 'approval_saved=1' in loc and workflow('vacation') == ('LEVEL_2', '1', [('1', 'SUPERVISOR', None), ('2', 'SPECIFIC_EMPLOYEE', str(ids['tina']))]), (loc, workflow('vacation')))
check('...and is in the Audit Log', q("SELECT details FROM {p}ews_audit_log WHERE action='approval_workflow_update'")[-1]['details'] == 'vacation=LEVEL_2')
st, page, _ = adm.req(PAGE)
check('...and shows as saved', re.search(r'<option value="LEVEL_2" selected', card(page, 'vacation')) is not None)

# ---------------------------------------------------------------- supervisors
st, page, _ = adm.req(PAGE)
rel = next((f for f in forms(page, 'ews_approval_relationship_save') if 'name="employee_id" value="%d"' % ids['emp'] in f), '')
rnonce = field(rel, '_wpnonce')
adm.req('/wp-admin/admin-post.php', {'action': 'ews_approval_relationship_save', '_wpnonce': rnonce, 'employee_id': ids['emp'], 'supervisor_employee_id': ids['tina']})
adm.req('/wp-admin/admin-post.php', {'action': 'ews_approval_relationship_save', '_wpnonce': rnonce, 'employee_id': ids['emp'], 'supervisor_employee_id': ids['boss']})
sups = q("SELECT related_employee_id FROM {p}ews_employee_relationships WHERE employee_id=%d AND relationship_type='supervisor' AND active=1" % ids['emp'])
check('a supervisor is saved, and replacing them keeps one active supervisor', [s['related_employee_id'] for s in sups] == [str(ids['boss'])], sups)
st, page, _ = adm.req(PAGE)
rel = next((f for f in forms(page, 'ews_approval_relationship_save') if 'name="employee_id" value="%d"' % ids['emp'] in f), '')
check('...shown as selected', re.search(r'<option value="%d" selected' % ids['boss'], rel) is not None)

# ---------------------------------------------------------------- the two-level flow (Leave)
boss, tina, emp = Session('boss', 'bosspass'), Session('tina', 'tinapass'), Session('emp1', 'emp1pass')


def submit(start, end):
    n = emp.nonce('ews_vacation_request_create')
    st, qs, _ = emp.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=end, reason='x')
    lid = q("SELECT id FROM {p}ews_leave_requests ORDER BY id DESC LIMIT 1")
    return qs, int(lid[0]['id']) if lid else 0


def state(lid):
    ar = q("SELECT id,status FROM {p}ews_approval_requests WHERE entity_type='leave' AND entity_id=%d" % lid)
    steps = q("SELECT step_order,status,approver_wp_user_id FROM {p}ews_approval_steps WHERE approval_request_id=%s ORDER BY step_order" % ar[0]['id']) if ar else []
    leave = q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lid)
    return (ar[0]['status'] if ar else None), [s['status'] for s in steps], leave[0]['status'] if leave else None


def decide(sess, lid, decision):
    n = sess.nonce('ews_vacation_request_respond')
    return sess.post('ews_vacation_request_respond', _wpnonce=n, request_id=lid, decision=decision)


qs, lid = submit(d(3), d(3))
uid = {k: q("SELECT wp_user_id FROM {p}ews_employees WHERE id=%d" % ids[k])[0]['wp_user_id'] for k in ('boss', 'tina')}
check('a leave starts at level 1: the supervisor decides first, level 2 waits', state(lid) == ('WAITING_FOR_LEVEL_1', ['PENDING', 'WAITING'], 'Pending'), (qs, state(lid)))
check('...approvers are fixed when the request starts', [s['approver_wp_user_id'] for s in q("SELECT approver_wp_user_id FROM {p}ews_approval_steps ORDER BY step_order")] == [uid['boss'], uid['tina']])
decide(tina, lid, 'approve')
check('level 2 cannot act before level 1', state(lid) == ('WAITING_FOR_LEVEL_1', ['PENDING', 'WAITING'], 'Pending'), state(lid))
decide(boss, lid, 'approve')
check('level 1 approves: the request moves to level 2, the leave stays pending', state(lid) == ('WAITING_FOR_LEVEL_2', ['APPROVED', 'PENDING'], 'Pending'), state(lid))
decide(boss, lid, 'approve')
check('level 1 cannot act twice', state(lid) == ('WAITING_FOR_LEVEL_2', ['APPROVED', 'PENDING'], 'Pending'), state(lid))
decide(tina, lid, 'approve')
check('level 2 approves: the leave is approved', state(lid) == ('APPROVED', ['APPROVED', 'APPROVED'], 'Approved'), state(lid))

qs, lid = submit(d(6), d(6))
decide(boss, lid, 'reject')
check('a rejection at level 1 ends it (level 2 is never asked)', state(lid) == ('REJECTED', ['REJECTED', 'WAITING'], 'Rejected'), state(lid))

php("$wpdb->query(\"UPDATE {$p}ews_employee_relationships SET active=0\");")
qs, lid = submit(d(9), d(9))
check('without a supervisor the leave is not submitted (no approver for level 1)', qs.get('leave_error') == 'approval' and not q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s'" % d(9)), (qs, lid))
php("$wpdb->insert($p.'ews_employee_relationships',['employee_id'=>%d,'relationship_type'=>'supervisor','related_employee_id'=>%d,'active'=>1]);" % (ids['emp'], ids['nouser']))
qs, lid = submit(d(9), d(9))
check('a supervisor without a WordPress user cannot approve: not submitted', qs.get('leave_error') == 'approval', qs)

# ---------------------------------------------------------------- refused settings
st, loc, _ = save(adm, nonce, 'overtime', 'LEVEL_1', level_1_type='SPECIFIC_EMPLOYEE')
check('a specific approver with nobody chosen is refused, with the reason', 'approval_error=1' in loc and 'A specific approver is required for step 1.' in loc and workflow('overtime')[0] == 'NONE', (loc, workflow('overtime')))
st, loc, _ = save(adm, nonce, 'vacation', 'SEQUENTIAL')
check('a mode the workflow does not support is refused', 'approval_error=1' in loc and workflow('vacation')[0] == 'LEVEL_2', (loc, workflow('vacation')))
st, loc, body = save(adm, nonce, 'shift_swap', 'PEER')
check('Shift Swap (not using workflows) cannot be saved', workflow('shift_swap')[1] == '0' and 'approval_saved' not in loc, (st, loc, workflow('shift_swap')))
st, loc, body = save(adm, nonce, 'payroll', 'NONE')
check('an unknown workflow is refused', 'approval_saved' not in loc and 'Invalid workflow.' in body, (st, loc))
st, loc, _ = save(emp, nonce, 'vacation', 'NONE')
check('an employee cannot change approvals', workflow('vacation')[0] == 'LEVEL_2', workflow('vacation'))

php("$wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'SEQUENTIAL','active'=>1],['workflow_key'=>'vacation']);")
st, page, _ = adm.req(PAGE)
check('a mode saved by an older version that Leave ignores is pointed out', 'is not supported by Vacation requests' in card(page, 'vacation'), card(page, 'vacation')[:300])

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

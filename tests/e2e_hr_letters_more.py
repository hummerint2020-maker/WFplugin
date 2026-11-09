"""HR letters (HL3, docs/tasks/hr-letter-hl3.md §5-6), the cases tests/e2e_hr_letters.py does not reach:
- requests at the same moment: two asks for the same open letter (the UNIQUE key refuses the second), a decision and a withdrawal, two
  deciders, a file attached by two requests;
- faults injected with tests/e2e_hr_letters_mu.php: the approval cannot be written, the letter decided meanwhile, the approval decided
  while the employee withdraws, the file claimed by another request (no file left behind);
- the upgrade from 3.31.144 (HL2): the table, the inactive "HR Letter" workflow row and the capability arrive, letters stay off.
The test web server answers one request at a time, so "the same moment" is reproduced by sending a form the page showed before the other
request was saved, or by the fault file.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_hr_letters_more.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
SETUP = """
    foreach(['ews_hr_letters','ews_pay_rates','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_notifications','ews_audit_log'] as $t) if($wpdb->get_var("SHOW TABLES LIKE '{$p}$t'")) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_hr_letters','ews_hr_letters','ews_hr_letter_types','ews_hr_letter_letterhead','ews_hr_letters_ever','ews_approval_settings','ews_approval_self_rule','ews_approval_reject_note'] as $o) delete_option($o);
    update_option('ews_feature_my_approvals',1,false);
"""
ids = json.loads(php(SETUP + """
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='hrl'");
    $uid=username_exists('hrl')?:wp_create_user('hrl','hrlpass','hrl@example.com');
    (new WP_User($uid))->set_role('ews_administrator');
    $wpdb->insert($p.'ews_employees',['name'=>'Heba HR','domain_name'=>'hrl','email'=>'hrl@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
    update_option('ews_approval_settings',['hr_user'=>(int)$uid],false);
    update_option('ews_feature_hr_letters',1,false);
    echo wp_json_encode(['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID,'hrl'=>(int)$wpdb->insert_id,'hrl_uid'=>(int)$uid]);
""").splitlines()[-1])
adm, emp, hr = Session('admin', 'admin'), Session('emp1', 'emp1pass'), Session('hrl', 'hrlpass')


def fault(mode=''):
    """Turn a fault of tests/e2e_hr_letters_mu.php on ('' = off)."""
    if mode:
        php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); copy('%s',$d.'/ews-e2e-hr-letters.php'); file_put_contents($d.'/ews-e2e-hr-letters.txt','%s');" % (os.path.join(HERE, 'e2e_hr_letters_mu.php'), mode))
    else:
        php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-hr-letters.php'); @unlink(WPMU_PLUGIN_DIR.'/ews-e2e-hr-letters.txt');")


def letters():
    return q("SELECT * FROM {p}ews_hr_letters ORDER BY id")


def letter(i):
    r = q("SELECT * FROM {p}ews_hr_letters WHERE id=%d" % int(i))
    return r[0] if r else None


def approval(i):
    r = q("SELECT r.id,r.status FROM {p}ews_approval_requests r JOIN {p}ews_approval_workflows w ON w.id=r.workflow_id WHERE w.workflow_key='hr_letter' AND r.entity_type='hr_letter' AND r.entity_id=%d" % int(i))
    return r[0] if r else None


def approvals_count():
    return int(q("SELECT COUNT(*) c FROM {p}ews_approval_requests r JOIN {p}ews_approval_workflows w ON w.id=r.workflow_id WHERE w.workflow_key='hr_letter'")[0]['c'])


def files_count():
    return int(php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'private_files_dir'); $r->setAccessible(true); echo count((array)glob($r->invoke($m,'workforce-one-letters').'/*.pdf'));").splitlines()[-1])


def audits(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


def form_nonce(page, action):
    for f in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in f:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', f)
            if m:
                return m.group(1)
    return ''


def ask(**data):
    page = emp.req('/app/?ews_view=requests&new=1')[1]
    return emp.post('ews_hr_letter_request', _wpnonce=form_nonce(page, 'ews_hr_letter_request') or 'none', ews_return='requests', **data)[1]


def decision_form(sess, letter_id):
    """The hidden fields of a letter's decision form as the page shows it now (to be sent later)."""
    page = sess.req('/app/?ews_view=approvals')[1]
    for body in re.findall(r'<article class="wfo-apv-item tone-letter[^"]*"[^>]*>(.*?)</article>', page, re.S):
        if 'name="letter_id" value="%d"' % letter_id in body:
            f = re.search(r'<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>', body, re.S)
            return {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f.group(1))}
    return None


def send_decision(sess, form, decision, note=''):
    data = dict(form, decision=decision, note=note)
    return sess.post(data.pop('action'), **data)[1]


def withdraw_form(letter_id):
    page = emp.req('/app/?ews_view=requests')[1]
    m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="ews_request_withdraw"><input type="hidden" name="kind" value="letter"><input type="hidden" name="id" value="%d">' % letter_id, page)
    return m.group(1) if m else None


def send_withdraw(letter_id, nonce):
    return emp.post('ews_request_withdraw', _wpnonce=nonce or 'none', kind='letter', id=letter_id)[1]


def hr_action_nonce(letter_id):
    page = hr.req('/wp-admin/admin.php?page=ews31-hr-letters&tab=list')[1]
    m = re.search(r'name="_wpnonce" value="([^"]+)"><input type="hidden" name="letter_id" value="%d">' % letter_id, page)
    return m.group(1) if m else 'none'


try:
    # ---- the approval cannot be written: nothing is left half-saved
    fault('start-fails')
    qs = ask(type='employment', language='ar')
    fault()
    check('the approval cannot be written: refused, the letter row rolled back with it', qs.get('hl_error') == 'save' and not letters() and approvals_count() == 0, (qs, letters()))
    check('...nothing in the Audit Log', not audits('hr_letter_request'))
    qs = ask(type='employment', language='ar')
    check('...the next request goes through', qs.get('hl_sent') == 'pending' and len(letters()) == 1, qs)
    A = letters()[-1]

    # ---- two requests for the same letter at the same moment (both pass the "one open" check)
    fault('open-check-blind')
    qs = ask(type='employment', language='ar')
    fault()
    check('two at the same moment: the second is refused by the UNIQUE key ("already open")', qs.get('hl_error') == 'open' and len(letters()) == 1, (qs, letters()))
    check('...no approval left without its letter', approvals_count() == 1)
    check('...another language is a different letter', ask(type='employment', language='en').get('hl_sent') == 'pending' and len(letters()) == 2)
    B = letters()[-1]

    # ---- a decision and a withdrawal at the same moment
    # (1) the employee's page was open, HR approves first: the withdrawal finds nothing waiting
    wn = withdraw_form(int(A['id']))
    form = decision_form(hr, int(A['id']))
    qs = send_decision(hr, form, 'approve')
    check('HR approves first: issued', qs.get('hl_done') == '1' and letter(A['id'])['status'] == 'issued', (qs, letter(A['id'])))
    qs = send_withdraw(int(A['id']), wn)
    check('...the withdrawal sent from the page opened before is refused, the letter stays issued', qs.get('req_error') == 'done' and letter(A['id'])['status'] == 'issued', (qs, wn is not None))
    # (2) HR's page was open, the employee withdraws first: the decision finds nothing waiting
    form = decision_form(hr, int(B['id']))
    qs = send_withdraw(int(B['id']), withdraw_form(int(B['id'])))
    check('the employee withdraws first', qs.get('req_done') == 'letter' and letter(B['id'])['status'] == 'withdrawn', qs)
    qs = send_decision(hr, form, 'approve')
    check('...HR\'s decision from the page opened before is refused, nothing is made', qs.get('hl_error') == 'done' and letter(B['id'])['status'] == 'withdrawn' and letter(B['id'])['file_key'] is None, (qs, letter(B['id'])))
    check('...the approval stays cancelled', approval(B['id'])['status'] == 'CANCELLED', approval(B['id']))
    # (3) inside the same moment: the engine saved the step, the letter was decided meanwhile → all rolled back
    ask(type='bank', language='ar')
    C = letters()[-1]
    form = decision_form(hr, int(C['id']))
    decided = len(audits('hr_letter_approve'))
    fault('letter-decided')
    qs = send_decision(hr, form, 'approve')
    fault()
    check('the letter changed while the decision was saved: refused, the approval step rolled back', qs.get('hl_error') == 'done' and letter(C['id'])['status'] == 'pending' and approval(C['id'])['status'] == 'PENDING', (qs, letter(C['id']), approval(C['id'])))
    check('...no decision in the Audit Log', len(audits('hr_letter_approve')) == decided)
    # (4) the employee withdraws while the approval is being decided → the withdrawal rolls back
    fault('engine-moved')
    qs = send_withdraw(int(C['id']), withdraw_form(int(C['id'])))
    fault()
    check('the approval was decided while the employee withdrew: refused, the letter still waits', qs.get('req_error') == 'done' and letter(C['id'])['status'] == 'pending' and approval(C['id'])['status'] == 'PENDING', (qs, letter(C['id']), approval(C['id'])))

    # ---- two deciders at the same moment: HR in My approvals, an administrator on the Requests Hub
    f_hr = decision_form(hr, int(C['id']))
    page = adm.req('/wp-admin/admin.php?page=ews31-requests')[1]
    hub = [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="request_type" value="hr_letter"' in f and 'name="request_id" value="%s"' % C['id'] in f and 'value="approve"' in f]
    f_hub = {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', hub[0])} if hub else {}
    check('both see it', f_hr is not None and bool(f_hub), (f_hr, bool(f_hub)))
    qs1 = send_decision(hr, f_hr, 'approve')
    st, qs2, _ = adm.post(f_hub.pop('action', 'ews31_requests_decision'), **f_hub)
    L = letter(C['id'])
    check('the first decision wins (request only: approved, waits to be collected)', qs1.get('hl_done') == '1' and L['status'] == 'approved', (qs1, L))
    check('...the second is refused, one decision in the Audit Log', qs2.get('request_notice') in ('error', 'moved') and len(audits('hr_letter_approve')) == decided + 1, (qs2, audits('hr_letter_approve')))
    n = hr_action_nonce(int(C['id']))
    st, qs, _ = hr.post('ews31_hr_letter_action', _wpnonce=n, letter_id=C['id'], do='ready', where='Desk 3')
    st, qs2, _ = hr.post('ews31_hr_letter_action', _wpnonce=n, letter_id=C['id'], do='cancel', reason='Too late')
    check('ready and cancel at the same moment: one wins', qs.get('hl_done') == 'ready' and qs2.get('hl_error') == 'done' and letter(C['id'])['status'] == 'ready', (qs, qs2, letter(C['id'])))

    # ---- the file claimed by another request: the second file is not kept
    ask(type='employment', language='en')
    D = letters()[-1]
    form = decision_form(hr, int(D['id']))
    before = files_count()
    fault('claim-lost')
    qs = send_decision(hr, form, 'approve')
    fault()
    L = letter(D['id'])
    check('the decision stands (approved)', qs.get('hl_done') == '1' and L['status'] == 'approved', (qs, L))
    check('...the file made for it was not kept (another request attached its own)', L['file_key'] is None and files_count() == before, (L, before, files_count()))
    n = hr_action_nonce(int(D['id']))
    st, qs, _ = hr.post('ews31_hr_letter_action', _wpnonce=n, letter_id=D['id'], do='regenerate')
    check('...HR makes it from the HR letters page: issued, one file', qs.get('hl_done') == 'regenerated' and letter(D['id'])['status'] == 'issued' and files_count() == before + 1, (qs, letter(D['id'])))
    st, qs, _ = hr.post('ews31_hr_letter_action', _wpnonce=n, letter_id=D['id'], do='regenerate')
    check('made again: the old file is removed', qs.get('hl_done') == 'regenerated' and files_count() == before + 1, (qs, files_count()))
finally:
    fault()

# ---- the upgrade from 3.31.144 (HL2): no table, no workflow row, no capability
php("""
    $wpdb->query("DROP TABLE IF EXISTS {$p}ews_hr_letters");
    $wpdb->query("DELETE FROM {$p}ews_approval_workflows WHERE workflow_key='hr_letter'");
    foreach(['administrator','ews_administrator'] as $r) if($role=get_role($r)) $role->remove_cap('ews_manage_hr_letters');
    $saved=get_option('ews_role_permissions'); if(is_array($saved)){ foreach($saved as $k=>$caps) if(is_array($caps)) unset($saved[$k]['ews_manage_hr_letters']); update_option('ews_role_permissions',$saved); }
    foreach(['ews_feature_hr_letters','ews_hr_letters','ews_hr_letter_types','ews_hr_letter_letterhead','ews_hr_letters_ever'] as $o) delete_option($o);
    update_option('ews_schema_version','3.31.144',false); delete_transient('ews_schema_retry');
""")
r = json.loads(php("new EWS_Manager_V31_1(); echo wp_json_encode(['v'=>get_option('ews_schema_version'),'f'=>get_option('ews_schema_failed'),'t'=>(bool)$wpdb->get_var(\"SHOW TABLES LIKE '{$p}ews_hr_letters'\"),"
                   "'w'=>$wpdb->get_row(\"SELECT approval_mode,active FROM {$p}ews_approval_workflows WHERE workflow_key='hr_letter'\",ARRAY_A),'cap'=>get_role('administrator')->has_cap('ews_manage_hr_letters'),'cap2'=>get_role('ews_administrator')&&get_role('ews_administrator')->has_cap('ews_manage_hr_letters'),"
                   "'on'=>get_option('ews_feature_hr_letters',null)]);").splitlines()[-1])
target = php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'ews_schema_target'); $r->setAccessible(true); echo $r->invoke($m);").splitlines()[-1]
check('upgrade from 3.31.144: the schema reaches the target, nothing failed', r['v'] == target and not r['f'], r)
check('...the letters table is created', r['t'], r)
check('...the "HR Letter" workflow row is there, inactive', r['w'] and r['w']['active'] == '0', r['w'])
check('...the capability Manage HR letters reaches the administrator and EWS Administrator', r['cap'] and r['cap2'], r)
check('...letters stay off', not r['on'], r['on'])
page = emp.req('/app/?ews_view=requests&new=1')[1]
check('...no HR letter in New request', 'data-new-kind="letter"' not in page)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-hr-letters')
check('...no HR letters page (never used)', 'ews31_hr_letter_action' not in page)
r2 = json.loads(php("delete_option('ews_schema_version'); delete_transient('ews_schema_retry'); new EWS_Manager_V31_1(); echo wp_json_encode(['v'=>get_option('ews_schema_version'),'f'=>get_option('ews_schema_failed'),'n'=>(int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$p}ews_approval_workflows WHERE workflow_key='hr_letter'\")]);").splitlines()[-1])
check('...run again: nothing changes, one workflow row', r2['v'] == target and not r2['f'] and r2['n'] == 1, r2)

php(SETUP)
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

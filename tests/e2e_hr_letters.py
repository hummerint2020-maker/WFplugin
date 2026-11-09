"""HR letters (HL3, docs/tasks/hr-letter-hl3.md; owner decisions in docs/tasks/hr-letter.md): off by default; the employee asks from
My requests → New request → HR letter; it goes through the "HR Letter" workflow (HR at one level by default); a PDF type is made at once
on approval, an upload type waits for HR's file, a request-only type is marked ready to collect; a salary letter needs a pay rate; one
open request per type and language; reject with a reason, withdraw, cancel; downloads only for the employee and HR; the Requests Hub;
HR's letter for an employee; "No approval needed" issues at once.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_hr_letters.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, io, json, os, re, sys, urllib.error, urllib.parse, urllib.request, uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_hr_letters','ews_pay_rates','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_notifications','ews_audit_log'] as $t) if($wpdb->get_var("SHOW TABLES LIKE '{$p}$t'")) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_hr_letters','ews_hr_letters','ews_hr_letter_types','ews_hr_letter_letterhead','ews_hr_letters_ever','ews_approval_settings','ews_approval_self_rule','ews_approval_reject_note'] as $o) delete_option($o);
    update_option('ews_feature_my_approvals',1,false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('hrl','other1','gone1')");
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID];
    foreach(['hrl'=>['Heba HR','ews_administrator',1],'other1'=>['Omar Other','subscriber',1],'gone1'=>['Gone Former','subscriber',0]] as $login=>[$name,$role,$active]){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        (new WP_User($uid))->set_role($role);
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>$active,'attendance_enabled'=>1]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    $wpdb->update($p.'ews_employees',['name_ar'=>'إيمب وان','gender'=>'male','hire_date'=>'2021-03-01','job_title'=>'Accountant'],['id'=>$eid]);
    update_option('ews_approval_settings',['hr_user'=>$out['hrl_uid']],false);
    echo wp_json_encode($out);
""").splitlines()[-1])
adm, emp, hr, other = Session('admin', 'admin'), Session('emp1', 'emp1pass'), Session('hrl', 'hrlpass'), Session('other1', 'other1pass')
ADMIN = '/wp-admin/admin.php?page=ews31-hr-letters'


def letters():
    return q("SELECT * FROM {p}ews_hr_letters ORDER BY id")


def letter(i):
    r = q("SELECT * FROM {p}ews_hr_letters WHERE id=%d" % int(i))
    return r[0] if r else None


def form_nonce(page, action):
    for f in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in f:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', f)
            if m:
                return m.group(1)
    return ''


def ask(sess=emp, **data):
    page = sess.req('/app/?ews_view=requests&new=1')[1]
    st, qs, _ = sess.post('ews_hr_letter_request', _wpnonce=form_nonce(page, 'ews_hr_letter_request') or 'none', ews_return='requests', **data)
    return qs


def cards(sess):
    page = sess.req('/app/?ews_view=approvals')[1]
    return re.findall(r'<article class="wfo-apv-item tone-letter[^"]*"[^>]*>(.*?)</article>', page, re.S)


def decide(sess, letter_id, decision, note=''):
    for body in cards(sess):
        if 'name="letter_id" value="%d"' % letter_id in body:
            f = re.search(r'<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>', body, re.S)
            data = {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f.group(1))}
            data.update(decision=decision, note=note)
            return sess.post(data.pop('action'), **data)[1]
    return None


def admin_action(letter_id, do, files=None, **data):
    page = hr.req(ADMIN + '&tab=list')[1]
    m = re.search(r'name="_wpnonce" value="([^"]+)"><input type="hidden" name="letter_id" value="%d">' % letter_id, page)
    fields = {'action': 'ews31_hr_letter_action', '_wpnonce': m.group(1) if m else 'none', 'letter_id': letter_id, 'do': do}
    fields.update(data)
    if not files:
        return hr.post(fields.pop('action'), **fields)[1]
    boundary = uuid.uuid4().hex
    body = io.BytesIO()
    for k, v in fields.items():
        body.write(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode())
    for name, (filename, content) in files.items():
        body.write(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, name, filename)).encode())
        body.write(content)
        body.write(b'\r\n')
    body.write(('--%s--\r\n' % boundary).encode())
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=body.getvalue(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        h = dict(hr.op.open(req).headers)
    except urllib.error.HTTPError as e:
        h = dict(e.headers)
    qs = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    return {k: v[0] for k, v in qs.items()}


def fetch(sess, url):
    try:
        r = sess.op.open(urllib.request.Request(url if url.startswith('http') else B + url))
        return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)


def file_url(sess, letter_id):
    page = sess.req('/app/?ews_view=requests')[1]
    m = re.search(r'href="([^"]*action=ews_hr_letter_file&(?:amp;|#038;)id=%d&[^"]*)"' % letter_id, page)
    return html.unescape(m.group(1)) if m else ''


def audits(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


# ---- off by default
page = emp.req('/app/?ews_view=requests&new=1')[1]
check('off: no HR letter tile', 'wfo-new-letter' not in page and 'data-new-kind="letter"' not in page)
check('off: a request is refused', ask(type='employment', language='ar').get('hl_error') == 'access' and not letters())
st, page, _ = hr.req(ADMIN)
check('off: no HR letters page before letters exist', 'ews31_hr_letter_action' not in page, st)
check('the table and the inactive workflow row are there while off', q("SELECT COUNT(*) c FROM {p}ews_approval_workflows WHERE workflow_key='hr_letter'")[0]['c'] == '1')
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-approvals')
check('off: no HR Letter card on Approval Workflows', 'hr_letter' not in page)

# ---- on
php("update_option('ews_feature_hr_letters',1,false);")
page = emp.req('/app/?ews_view=requests&new=1')[1]
check('on: the HR letter tile and its form', 'data-new-kind="letter"' in page and 'id="wfo-new-letter"' in page and 'name="type"' in page and 'value="other"' in page)
check('...the salary certificate offers Arabic and English', re.search(r'<option value="salary" data-languages="ar,en" data-delivery="pdf" data-salary="1">', page) is not None)

qs = ask(type='employment', language='ar', addressee='بنك مصر', note='قبل الخميس')
L1 = letters()[-1]
check('a request waits for HR', qs.get('hl_sent') == 'pending' and L1['status'] == 'pending' and L1['open_key'] == '%d:employment:ar' % ids['emp'] and L1['delivery'] == 'pdf', (qs, L1))
check('...the workflow was set to one level, HR', q("SELECT approval_mode,active FROM {p}ews_approval_workflows WHERE workflow_key='hr_letter'")[0] == {'approval_mode': 'LEVEL_1', 'active': '1'})
check('...HR is told', q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE user_id=%d AND title LIKE '%%HR letter%%'" % ids['hrl_uid'])[0]['c'] != '0')
check('one open request per type and language', ask(type='employment', language='ar').get('hl_error') == 'open' and len(letters()) == 1)
c = [b for b in cards(hr) if 'name="letter_id" value="%s"' % L1['id'] in b]
check('HR\'s card: type, addressee, the employee data, "Approve and issue the letter"', c and 'Approve and issue the letter' in c[0] and 'بنك مصر' in c[0] and '✓' in c[0] and 'Shows the salary' not in c[0], c[:1])
check('another employee has no card', not cards(other))
qs = decide(hr, int(L1['id']), 'approve')
L1 = letter(L1['id'])
check('approved: the PDF is made at once', qs.get('hl_done') == '1' and L1['status'] == 'issued' and L1['file_source'] == 'generated' and L1['open_key'] is None, (qs, L1))
url = file_url(emp, int(L1['id']))
st, body, h = fetch(emp, url)
check('the employee downloads it (inline, not stored)', st == 200 and body[:5] == b'%PDF-' and 'no-store' in h.get('Cache-Control', '') and 'inline' in h.get('Content-Disposition', ''), (st, h))
check('...the first download is audited once', audits('hr_letter_downloaded') == ['First download by the employee'])
fetch(emp, url)
check('...not again', len(audits('hr_letter_downloaded')) == 1)
st, body, h = fetch(other, url)
check('another employee cannot open it', st == 403 and body[:5] != b'%PDF-', st)
st, body, h = fetch(hr, url)
check('HR can, and it is audited', st == 200 and body[:5] == b'%PDF-' and len(audits('hr_letter_downloaded')) == 2)

# ---- the salary certificate
qs = ask(type='salary', language='ar')
check('a salary letter needs a pay rate on file', qs.get('hl_error') == 'no_salary', qs)
php("$wpdb->insert($p.'ews_pay_rates',['employee_id'=>%d,'effective_from'=>'2020-01-01','basic'=>15000,'allowances'=>'%s','created_at'=>current_time('mysql')]);" % (ids['emp'], json.dumps([{'name': 'x', 'amount': 3500}])))
qs = ask(type='salary', language='ar')
L2 = letters()[-1]
check('...with one, it waits', qs.get('hl_sent') == 'pending' and L2['with_salary'] == '1')
c = [b for b in cards(hr) if 'name="letter_id" value="%s"' % L2['id'] in b]
check('...HR\'s card says it shows the salary (no amount)', c and 'Shows the salary' in c[0] and '18,500' not in c[0] and '18500' not in c[0], c[:1])
qs = decide(hr, int(L2['id']), 'reject')
check('rejecting needs a reason', qs.get('hl_error') == 'note' and letter(L2['id'])['status'] == 'pending', qs)
decide(hr, int(L2['id']), 'reject', 'Not for loans')
L2 = letter(L2['id'])
check('rejected with the reason, closed', L2['status'] == 'rejected' and L2['decision_note'] == 'Not for loans' and L2['open_key'] is None, L2)
page = emp.req('/app/?ews_view=requests')[1]
check('My requests shows the reason', 'Reason: Not for loans' in html.unescape(page))
ask(type='salary', language='ar')
L3 = letters()[-1]
decide(hr, int(L3['id']), 'approve')
check('a salary certificate is made with the pay rate', letter(L3['id'])['status'] == 'issued')
check('no amount in the Audit Log', not [d for d in q("SELECT details FROM {p}ews_audit_log") if '18500' in d['details'] or '18,500' in d['details'] or '١٨' in d['details']])

# ---- upload, request only, cancel, withdraw, Other
ask(type='embassy', language='en', addressee='German Embassy')
L4 = letters()[-1]
decide(hr, int(L4['id']), 'approve')
check('an upload type waits for HR\'s file', letter(L4['id'])['status'] == 'approved' and letter(L4['id'])['file_key'] is None)
st, page, _ = hr.req(ADMIN)
check('the HR letters page lists it with Upload file', st == 200 and 'HR-' in page and 'Upload file' in page)
qs = admin_action(int(L4['id']), 'upload', files={'letter_file': ('x.txt', b'hello')})
check('not a PDF is refused', qs.get('hl_error') == 'not_pdf' and letter(L4['id'])['status'] == 'approved', qs)
qs = admin_action(int(L4['id']), 'upload', files={'letter_file': ('letter.pdf', b'%PDF-1.4\n%fake\n')})
check('a PDF is attached: issued (uploaded)', qs.get('hl_done') == 'uploaded' and letter(L4['id'])['status'] == 'issued' and letter(L4['id'])['file_source'] == 'uploaded', qs)
check('...the employee gets that file', fetch(emp, file_url(emp, int(L4['id'])))[1] == b'%PDF-1.4\n%fake\n')
ask(type='bank', language='ar')
L5 = letters()[-1]
decide(hr, int(L5['id']), 'approve')
qs = admin_action(int(L5['id']), 'ready', where='Office 204')
check('request only: ready to collect, with where', qs.get('hl_done') == 'ready' and letter(L5['id'])['status'] == 'ready' and letter(L5['id'])['decision_note'] == 'Office 204', qs)
check('...My requests says where', 'Collect it from HR · Office 204' in html.unescape(emp.req('/app/?ews_view=requests')[1]))
ask(type='experience', language='ar')
L6 = letters()[-1]
decide(hr, int(L6['id']), 'approve')
check('cancel needs a reason', admin_action(int(L6['id']), 'cancel', reason='').get('hl_error') == 'note' and letter(L6['id'])['status'] == 'approved')
qs = admin_action(int(L6['id']), 'cancel', reason='Issued on leaving only')
check('HR cancels a decided letter with no file', qs.get('hl_done') == 'cancelled' and letter(L6['id'])['status'] == 'cancelled', qs)
ask(type='bank', language='en')
L7 = letters()[-1]
page = emp.req('/app/?ews_view=requests')[1]
n = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="ews_request_withdraw"><input type="hidden" name="kind" value="letter"><input type="hidden" name="id" value="%s">' % L7['id'], page)
st, qs, _ = emp.post('ews_request_withdraw', _wpnonce=n.group(1) if n else 'none', kind='letter', id=L7['id'])
check('the employee withdraws a waiting letter', qs.get('req_done') == 'letter' and letter(L7['id'])['status'] == 'withdrawn' and letter(L7['id'])['open_key'] is None, (qs, n is not None))
check('...its approval is closed', q("SELECT status FROM {p}ews_approval_requests WHERE workflow_key='hr_letter' AND entity_id=%s" % L7['id'])[0]['status'] == 'CANCELLED')
ask(type='other', other_text='Club membership letter', language='ar')
L8 = letters()[-1]
check('"Other": HR uploads it, any number open', L8['delivery'] == 'upload' and L8['open_key'] is None and L8['other_text'] == 'Club membership letter')
check('...a second "Other" is fine', ask(type='other', other_text='Another', language='ar').get('hl_sent') == 'pending')

# ---- the Requests Hub (owner decision 1)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-requests')
check('the Requests Hub lists waiting letters', 'HR Letter' in page and 'Club membership letter' in page)
forms = [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="request_type" value="hr_letter"' in f and 'name="request_id" value="%s"' % L8['id'] in f and 'value="approve"' in f]
data = {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', forms[0])} if forms else {}
st, qs, _ = adm.post(data.pop('action', 'ews31_requests_decision'), **data)
check('...an administrator approves it there', qs.get('request_notice') == 'approved' and letter(L8['id'])['status'] == 'approved', (qs, letter(L8['id'])))

# ---- "No approval needed" issues at once; HR's letter for an employee
php("$wpdb->query(\"UPDATE {$p}ews_approval_workflows SET approval_mode='NONE' WHERE workflow_key='hr_letter'\");")
qs = ask(type='employment', language='en')
check('"No approval needed": issued at once', qs.get('hl_sent') == 'issued' and letters()[-1]['status'] == 'issued', qs)
page = hr.req(ADMIN + '&tab=new')[1]
check('Letter for an employee: former employees listed, not oneself', 'Gone Former (former)' in page and '>Heba HR<' not in page)
n = form_nonce(page, 'ews31_hr_letter_create')
st, qs, _ = hr.post('ews31_hr_letter_create', _wpnonce=n, employee_id=ids['gone1'], type='employment', language='en', make='template')
G = letters()[-1]
check('HR\'s letter for a former employee is issued directly', qs.get('hl_done') == 'created' and G['source'] == 'hr' and G['status'] == 'issued' and int(G['employee_id']) == ids['gone1'], (qs, G))
check('...audited', audits('hr_letter_direct') and 'Gone Former' in audits('hr_letter_direct')[-1])
st, qs, _ = hr.post('ews31_hr_letter_create', _wpnonce=n, employee_id=ids['hrl'], type='employment', language='en', make='template')
check('...not for one\'s own record', qs.get('hl_error') == 'self', qs)
st, qs, _ = hr.post('ews31_hr_letter_create', _wpnonce=n, employee_id=ids['emp'], type='salary', language='ar', make='template')
check('...a salary letter needs Manage payroll (HR without it)', qs.get('hl_error') == 'payroll', qs)
st, body, _ = emp.req(ADMIN)
check('an employee cannot open the HR letters page', 'ews31_hr_letter_action' not in body)
st, qs, body = emp.post('ews31_hr_letter_action', _wpnonce='x', letter_id=L4['id'], do='regenerate')
check('...nor act on a letter', st in (403, 500) or 'Access denied' in body, st)

# ---- templates
page = hr.req(ADMIN + '&tab=types&type=employment')[1]
n = form_nonce(page, 'ews31_hr_letter_types_save')
st, qs, _ = hr.post('ews31_hr_letter_types_save', _wpnonce=n, key='employment', name_ar='شهادة عمل', name_en='Employment certificate', delivery='pdf', active=1, tpl_ar='{employee_name} {salary_net}', tpl_en='Hi')
check('a template with an unknown placeholder is refused', qs.get('hl_error') == 'template_tokens' and '{salary_net}' in qs.get('hl_unknown', ''), qs)
st, qs, _ = hr.post('ews31_hr_letter_types_save', _wpnonce=n, key='employment', name_ar='شهادة عمل', name_en='Employment certificate', delivery='pdf', active=1, tpl_ar='{employee_name} {salary_total}', tpl_en='Hi')
check('a template with the salary needs Manage payroll', qs.get('hl_error') == 'payroll', qs)
st, qs, _ = hr.post('ews31_hr_letter_types_save', _wpnonce=n, key='employment', name_ar='شهادة عمل', name_en='Employment certificate', delivery='pdf', active=1, tpl_ar='تشهد {company_name} بأن {employee_name} {g:يعمل|تعمل} لديها.', tpl_en='{employee_name} works here.')
check('...a template without it is saved', qs.get('hl_done') == 'saved' and 'g:يعمل' in json.dumps(json.loads(php("echo wp_json_encode(get_option('ews_hr_letter_types'));").splitlines()[-1]), ensure_ascii=False), qs)

# ---- off again: the employee loses access, HR keeps the page and the files
php("update_option('ews_feature_hr_letters',0,false);")
check('off: the employee cannot download', fetch(emp, url)[0] == 403)
st, body, _ = fetch(hr, url)
check('off: HR still can', st == 200 and body[:5] == b'%PDF-')
st, page, _ = hr.req(ADMIN)
check('off: the HR letters page stays (letters exist) with the warning', 'HR letters are off' in page)
check('off: no letter on the Requests Hub', 'HR Letter' not in adm.req('/wp-admin/admin.php?page=ews31-requests')[1])

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

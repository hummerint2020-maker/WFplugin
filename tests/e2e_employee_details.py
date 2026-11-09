"""HR letters HL2 (docs/tasks/employee-details.md): an employee's name in Arabic, gender and hiring date. HR edits them on the
employee's profile in wp-admin, or for many employees from a file (download, fill, upload, preview, apply); the employee sees them
read only on My Profile; nobody else sees them.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_employee_details.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, io, os, re, sys, urllib.error, urllib.parse, urllib.request, uuid, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

EMPLOYEES = '/wp-admin/admin.php?page=ews31-employees'
IMPORT = EMPLOYEES + '&view=details-import'
TODAY = php("echo current_time('Y-m-d');").splitlines()[-1]
TOMORROW = (datetime.date.fromisoformat(TODAY) + datetime.timedelta(days=1)).isoformat()


def profile_url(eid, extra=''):
    return '/wp-admin/admin.php?page=ews31-employee-profile&employee_id=%d%s' % (eid, extra)


def form_nonce(page, action):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    return ''


def row(eid):
    r = q("SELECT name,name_ar,gender,hire_date,created_at,updated_at FROM {p}ews_employees WHERE id=%d" % eid)
    return r[0] if r else None


def audits(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


def upload(sess, data, filename):
    st, page, _ = sess.req(IMPORT)
    fields = {'_wpnonce': form_nonce(page, 'ews31_employee_details_import'), 'action': 'ews31_employee_details_import'}
    boundary = uuid.uuid4().hex
    body = io.BytesIO()
    for k, v in fields.items():
        body.write(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode())
    body.write(('--%s\r\nContent-Disposition: form-data; name="details_file"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, filename)).encode())
    body.write(data)
    body.write(('\r\n--%s--\r\n' % boundary).encode())
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=body.getvalue(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        h = dict(sess.op.open(req).headers)
    except urllib.error.HTTPError as e:
        h = dict(e.headers)
    qs = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    return {k: v[0] for k, v in qs.items()}


def xlsx(rows):
    """A small workbook with shared strings, numbers as numbers (as Excel saves them)."""
    strings, sheet = [], []
    for r, cells_in in enumerate(rows):
        cells = []
        for c, v in enumerate(cells_in):
            ref = chr(65 + c) + str(r + 1)
            if v == '':
                continue
            if isinstance(v, int):
                cells.append('<c r="%s"><v>%d</v></c>' % (ref, v))
            else:
                strings.append(v)
                cells.append('<c r="%s" t="s"><v>%d</v></c>' % (ref, len(strings) - 1))
        sheet.append('<row r="%d">%s</row>' % (r + 1, ''.join(cells)))
    b = io.BytesIO()
    with zipfile.ZipFile(b, 'w') as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
        z.writestr('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Employees" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        z.writestr('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">%s</sst>' % ''.join('<si><t>%s</t></si>' % html.escape(s) for s in strings))
        z.writestr('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>%s</sheetData></worksheet>' % ''.join(sheet))
    return b.getvalue()


# ---- seed: Emp One (emp1, a user), Ahmed and Sara Old (archived), no details anywhere
wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
EID = ids()['eid']
out = php("""$wpdb->query("DELETE FROM {$p}ews_audit_log");
$wpdb->update($p.'ews_employees',['created_at'=>'2023-01-12 08:00:00'],['id'=>%d]);
$wpdb->insert($p.'ews_employees',['name'=>'Ahmed Samir','domain_name'=>'asamir','email'=>'asamir@example.com','active'=>1,'attendance_enabled'=>1,'created_at'=>'2024-05-02 09:00:00']); $a=$wpdb->insert_id;
$wpdb->insert($p.'ews_employees',['name'=>'Sara Old','domain_name'=>'sold','active'=>0,'attendance_enabled'=>1,'created_at'=>'2020-01-01 09:00:00']); $s=$wpdb->insert_id;
echo $a.' '.$s;""" % EID)
AHMED, SARA = map(int, out.splitlines()[-1].split())
adm, emp1 = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---- the schema
cols = {r['Field']: r['Type'] for r in q("SHOW COLUMNS FROM {p}ews_employees")}
check('the employees table has name_ar, gender and hire_date', cols.get('name_ar', '').startswith('varchar(190)') and cols.get('gender', '').startswith('varchar(10)') and cols.get('hire_date') == 'date', cols)
check('...empty for everyone after the upgrade', all(r['name_ar'] is None and r['gender'] is None and r['hire_date'] is None for r in q("SELECT name_ar,gender,hire_date FROM {p}ews_employees")))

# ---- nothing filled: Not set, and Employee Since is the day the employee was added
st, page, _ = adm.req(profile_url(EID))
check('the profile opens', st == 200 and 'Emp One' in page, st)
check('Employee Since is the day the employee was added, with the note', 'January 12, 2023' in page and 'The day the employee was added: hiring date not set' in page, re.findall(r'Employee Since</div><div class="v">(.*?)</div>', page))
check('Gender: Not set', re.search(r'Gender</div><div class="v ews-ef-empty">Not set<', page) is not None)
check('one Edit button, and no form until it is pressed', 'Edit name in Arabic, gender, hiring date' in page and 'ews31_employee_details_save' not in page)
st, page, _ = emp1.req('/app/?ews_view=profile')
check('My Profile: Gender Not set, Employee since the day added, the note', re.search(r'<dt>Gender</dt><dd class="ews-ef-empty">Not set</dd>', page) is not None
      and re.search(r'<dt>Employee since</dt><dd>12 Jan 2023</dd>', page) is not None and 'This is what appears on your HR letters.' in page, re.findall(r'<dt>(?:Gender|Employee since)</dt><dd[^>]*>[^<]*', page))

# ---- the profile's Edit
st, page, _ = adm.req(profile_url(EID, '&edit_details=1'))
n_save = form_nonce(page, 'ews31_employee_details_save')
check('Edit shows the form with the three fields', n_save and 'name="name_ar"' in page and 'name="gender"' in page and 'name="hire_date"' in page and 'max="%s"' % TODAY in page)


def edit_nonce(eid):
    return form_nonce(adm.req(profile_url(eid, '&edit_details=1'))[1], 'ews31_employee_details_save')


def save(eid, nonce=None, sess=None, **f):
    """The profile's form; its nonce is per employee."""
    data = {'_wpnonce': (n_save if eid == EID else edit_nonce(eid)) if nonce is None else nonce, 'employee_id': eid, 'name_ar': '', 'gender': '', 'hire_date': ''}
    data.update(f)
    return (sess or adm).post('ews31_employee_details_save', **data)


st, qs, _ = save(EID, name_ar='  منى   أحمد ', gender='female', hire_date=TOMORROW)
check('a hiring date in the future is refused, and the form stays open with the reason', qs.get('details_error') == 'future_date' and qs.get('edit_details') == '1' and row(EID)['hire_date'] is None, qs)
st, page, _ = adm.req(profile_url(EID, '&details_error=future_date&edit_details=1'))
check('...the reason is shown', 'The hiring date is in the future' in page)
st, qs, _ = save(EID, name_ar='  منى   أحمد ', gender='female', hire_date='2021-03-01')
r = row(EID)
check('saved: the Arabic name tidied, gender and hiring date', qs.get('details_notice') == 'saved' and r['name_ar'] == 'منى أحمد' and r['gender'] == 'female' and r['hire_date'] == '2021-03-01', (qs, r))
check('...in the Audit Log, from and to', audits('employee_details') == ['Emp One: name_ar: — → منى أحمد · gender: — → female · hire_date: — → 2021-03-01'], audits('employee_details'))
st, qs, _ = save(EID, name_ar='منى أحمد', gender='female', hire_date='2021-03-01')
check('saving the same again changes nothing and adds no audit', qs.get('details_notice') == 'saved' and len(audits('employee_details')) == 1)
st, page, _ = adm.req(profile_url(EID))
check('the profile shows the Arabic name under the name, Female, and the hiring date as Employee Since (no note)',
      '<span class="ews-ef-sub" dir="rtl" lang="ar">منى أحمد</span>' in page and '>Female<' in page and 'March 1, 2021' in page and 'hiring date not set' not in page)
st, page, _ = emp1.req('/app/?ews_view=profile')
check('My Profile shows them, read only', 'منى أحمد</span>' in page and '<dt>Gender</dt><dd>Female</dd>' in page and '<dt>Employee since</dt><dd>1 Mar 2021</dd>' in page
      and 'ews31_employee_details_save' not in page)

st, qs, body = save(EID, nonce='bad', gender='male')
check('a wrong nonce is refused', st in (403, 200) and 'ews31_employee_details' not in str(qs) and row(EID)['gender'] == 'female', (st, qs))
st, qs, body = save(EID, sess=emp1, gender='male')
check('an employee cannot save details', 'Access denied' in body and row(EID)['gender'] == 'female', (st, body[:200]))
st, qs, _ = save(EID, gender='m')
check('a wrong gender is refused', qs.get('details_error') == 'bad_gender' and row(EID)['gender'] == 'female', qs)
st, qs, _ = save(EID, name_ar='', gender='', hire_date='')
r = row(EID)
check('emptying the form clears the three', qs.get('details_notice') == 'saved' and r['name_ar'] is None and r['gender'] is None and r['hire_date'] is None, r)
save(EID, name_ar='منى أحمد', gender='female', hire_date='2021-03-01')

# ---- nobody else sees them (owner decision Q5)
php("update_option('ews_employee_profile_settings',['enabled'=>1,'show_photo'=>1,'show_name'=>1,'show_title'=>1,'show_department'=>1,'show_team'=>1,'show_manager'=>1,'show_email'=>1],false);")
for path in ['/app/?ews_view=employees', '/app/?ews_view=employee&employee_id=%d' % EID]:
    st, page, _ = adm.req(path)
    check('not on %s' % path, 'منى أحمد' not in page and '>Female<' not in page and 'hire_date' not in page, st)
php("delete_option('ews_employee_profile_settings');")

# ---- Add Employee, with and without the details; the update form keeps them
st, page, _ = adm.req(EMPLOYEES)
check('the Employees page has the "Update details from a file" button and the optional section', 'view=details-import' in page and 'Update details from a file' in page and 'name="name_ar"' in page)
n_add = form_nonce(page, 'ews31_employee_save')
base = dict(_wpnonce=n_add, wp_user_id=0, default_shift_id=0, department_id=0, attendance_enabled=1)
st, qs, _ = adm.post('ews31_employee_save', **dict(base, name='Nour Plain', domain_name='nplain', email='nplain@example.com'))
nour = q("SELECT id,name_ar,gender,hire_date FROM {p}ews_employees WHERE domain_name='nplain'")
check('Add Employee without the details', qs.get('employee_notice') == 'added' and nour and nour[0]['gender'] is None and nour[0]['hire_date'] is None, qs)
st, qs, _ = adm.post('ews31_employee_save', **dict(base, name='Hana Lotfy', domain_name='hlotfy', email='hlotfy@example.com', name_ar='هنا لطفي', gender='أنثى', hire_date='2019-07-15'))
hana = q("SELECT id,name_ar,gender,hire_date FROM {p}ews_employees WHERE domain_name='hlotfy'")
check('Add Employee with the details (the Arabic word for the gender too)', qs.get('employee_notice') == 'added' and hana and hana[0]['name_ar'] == 'هنا لطفي' and hana[0]['gender'] == 'female' and hana[0]['hire_date'] == '2019-07-15', (qs, hana))
check('...the add is audited with the details', any('gender: female' in d for d in audits('employee_create')), audits('employee_create'))
st, qs, _ = adm.post('ews31_employee_save', **dict(base, name='Bad Date', domain_name='bdate', email='bdate@example.com', hire_date=TOMORROW))
check('Add Employee with a future hiring date is refused, nothing added', qs.get('employee_error') == 'future_date' and not q("SELECT id FROM {p}ews_employees WHERE domain_name='bdate'"), qs)
n_upd = form_nonce(page, 'ews31_employee_update')
st, qs, _ = adm.post('ews31_employee_update', _wpnonce=n_upd, id=EID, name='Emp One', domain_name='emp1', email='emp1@example.com', wp_user_id=ids()['uid'], default_shift_id=0, department_id=0, attendance_enabled=1, active=1)
r = row(EID)
check('the Employees update form keeps the details', qs.get('employee_notice') == 'updated' and r['name_ar'] == 'منى أحمد' and r['gender'] == 'female' and r['hire_date'] == '2021-03-01', (qs, r))

# ---- the file: export
st, page, _ = adm.req(IMPORT)
check('the import page: the steps, the download and the upload', st == 200 and 'Download the employees file (Excel)' in page and 'name="details_file"' in page)
m = re.search(r'href="([^"]*action=ews31_employee_details_export[^"]*)"', page)
resp = adm.op.open(urllib.request.Request(B + html.unescape(m.group(1)).replace(B, ''))) if m else None
raw, h = (resp.read(), dict(resp.headers)) if resp else (b'', {})
check('the export is a workbook', raw[:2] == b'PK' and 'spreadsheetml' in h.get('Content-Type', ''), h.get('Content-Type'))
sheet = zipfile.ZipFile(io.BytesIO(raw)).read('xl/worksheets/sheet1.xml').decode() if raw[:2] == b'PK' else ''
names = zipfile.ZipFile(io.BytesIO(raw)).namelist() if raw[:2] == b'PK' else []
check('...every employee, archived ones as Former, with their details', 'Sara Old' in sheet and 'Former' in sheet and 'منى أحمد' in sheet and '2021-03-01' in sheet and 'hire_date' in sheet, sheet[:400])
check('...and a "How to import" sheet', any('sheet2' in n for n in names), names)
check('...the download is in the Audit Log', len(audits('employee_details_exported')) == 1)
st, body, _ = emp1.req('/wp-admin/admin-post.php?action=ews31_employee_details_export&_wpnonce=x')
check('an employee cannot download the file', 'PK' != body[:2] and 'Sara Old' not in body)

# ---- the file: import (CSV with a byte order mark and Arabic), a preview, Apply
csv = ('﻿Employee ID,Domain name,Name,name_ar,gender,hire_date\r\n'
       '%d,,Ahmed Samir,أحمد سمير,ذكر,2022-02-01\r\n'
       ',SOLD,Sara Old,سارة,female,\r\n'
       ',nobody,,,,\r\n'
       '%d,,,,m,\r\n'
       '%d,,,x,,\r\n'
       '%d,,,,,%s\r\n' % (AHMED, EID, AHMED, hana[0]['id'], TOMORROW)).encode('utf-8')
qs = upload(adm, csv, 'details.csv')
check('the upload goes to the preview', qs.get('view') == 'details-import' and 'details_error' not in qs, qs)
st, page, _ = adm.req(IMPORT)
check('the preview: the changes, the refused rows in red with their reason', 'Preview the update' in page and page.count('Will change') == 5 and page.count('ews-ef-refused') == 4
      and 'No employee with this ID or domain name' in page and 'Gender must be male or female' in page and 'The employee is in the file twice' in page and 'The hiring date is in the future' in page, re.findall(r'<td>([^<]*)</td></tr>', page))
check('...archived employees are importable, marked Former', 'Sara Old · Former' in page)
check('...nothing saved yet', row(AHMED)['name_ar'] is None)
apply_nonce = form_nonce(page, 'ews31_employee_details_import_apply')
# A stale preview: HR changes Ahmed's gender on the profile meanwhile.
save(AHMED, gender='female')
st, qs, _ = adm.post('ews31_employee_details_import_apply', _wpnonce=apply_nonce)
a, s = row(AHMED), row(SARA)
check('Apply saves what still holds the previewed value', qs.get('details_notice') == 'imported' and a['name_ar'] == 'أحمد سمير' and a['hire_date'] == '2022-02-01' and s['name_ar'] == 'سارة' and s['gender'] == 'female', (qs, a, s))
check('...a field changed since the preview is kept, and counted as skipped', a['gender'] == 'female' and qs.get('skipped') == '1' and qs.get('changed') == '4' and qs.get('employees') == '2', qs)
imp = [d for d in audits('employee_details') if d.endswith(' · import')]
check('...one Audit Log row per employee, and one for the file', len(imp) == 2 and len(audits('employee_details_imported')) == 1 and 'details.csv: 4 change(s) for 2 employee(s), 1 skipped' in audits('employee_details_imported')[0], (imp, audits('employee_details_imported')))
st, qs, _ = adm.post('ews31_employee_details_import_apply', _wpnonce=apply_nonce)
check('applying twice: the preview is gone', qs.get('details_error') == 'expired', qs)

# ---- an Excel file (numbers as numbers, a date as Excel stores it), then cancel
qs = upload(adm, xlsx([['employee_id', 'hire_date', 'gender'], [AHMED, 44256, 'male']]), 'details.xlsx')
st, page, _ = adm.req(IMPORT)
check('an Excel file: the ID as a number and the date as a serial are read', 'details.xlsx' in page and '2021-03-01' in page and page.count('Will change') == 2, re.findall(r'<td dir="auto">([^<]*)</td>', page))
st, qs, _ = adm.post('ews31_employee_details_import_apply', _wpnonce=form_nonce(page, 'ews31_employee_details_import_apply'), cancel='1')
check('Cancel saves nothing', row(AHMED)['hire_date'] == '2022-02-01' and 'details_error' not in qs, qs)
st, page, _ = adm.req(IMPORT)
check('...and the upload is shown again', 'name="details_file"' in page and 'Preview the update' not in page)

# ---- wrong files
qs = upload(adm, b'just text', 'notes.txt')
check('a file that is not Excel or CSV is refused', qs.get('details_error') == 'file', qs)
qs = upload(adm, 'Name,Title\r\nx,y\r\n'.encode(), 'wrong.csv')
check('a file without the columns is refused', qs.get('details_error') == 'columns', qs)
st, page, _ = adm.req(IMPORT + '&details_error=columns')
check('...with the reason', 'The file needs an "Employee ID" or "Domain name" column' in page)
st, page, _ = emp1.req(IMPORT)
check('an employee cannot open the import', 'name="details_file"' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

"""Organization chart, stage 3 (3.31.98): moving people (a new manager from a date, with a reason; the
people under them move with them; approvals waiting for the old manager go to the new one; a later date
is scheduled and done on its day; a scheduled move can be cancelled, and one that can no longer be done
is shown under Needs attention), the history, the Employees page while the chart is on, and the
managers file in Excel (export, a preview of every change and every row that cannot be used, then save).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_org_moves.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, io, json, os, re, sys, urllib.parse, urllib.request, uuid, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

CHART = '/wp-admin/admin.php?page=ews31-org-chart'
TODAY = php("echo current_time('Y-m-d');").splitlines()[-1]
TOMORROW = (datetime.date.fromisoformat(TODAY) + datetime.timedelta(days=1)).isoformat()


def nonce(page, action):
    m = re.search(r'name="action" value="%s">' % action, page)
    if not m:
        m2 = re.search(r'id="_wpnonce" name="_wpnonce" value="([^"]+)"[^>]*>(?:<input[^>]*>)*\s*<input type="hidden" name="action" value="%s"' % action, page)
        return m2.group(1) if m2 else ''
    before = page[:m.start()]
    return re.findall(r'name="_wpnonce" value="([^"]+)"', before)[-1]


def chart(sess):
    st, page, _ = sess.req(CHART)
    m = re.search(r'<script type="application/json" id="wfo-org-data">(.*?)</script>', page, re.S)
    return page, (json.loads(m.group(1)) if m else {})


def move(sess, eid, ref='', none=False, date=TODAY, reason='Team change'):
    page, _ = chart(sess)
    data = {'_wpnonce': nonce(page, 'ews_org_move'), 'employee_id': eid, 'manager_ref': ref, 'effective_date': date, 'reason': reason}
    if none:
        data['no_manager'] = '1'
    return sess.post('ews_org_move', **data)[1]


def ref(name, eid):
    return '%s · #%d' % (name, eid)


def manager(eid):
    r = q("SELECT related_employee_id m FROM {p}ews_employee_relationships WHERE employee_id=%d AND relationship_type='supervisor' AND active=1 ORDER BY id DESC LIMIT 1" % eid)
    return int(r[0]['m']) if r and r[0]['m'] else 0


def upload(sess, path_or_bytes, filename, date=TODAY, reason=''):
    page, _ = chart(sess)
    fields = {'_wpnonce': nonce(page, 'ews_org_import'), 'action': 'ews_org_import', 'effective_date': date, 'reason': reason}
    boundary = uuid.uuid4().hex
    body = io.BytesIO()
    for k, v in fields.items():
        body.write(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode())
    body.write(('--%s\r\nContent-Disposition: form-data; name="managers_file"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, filename)).encode())
    body.write(path_or_bytes)
    body.write(('\r\n--%s--\r\n' % boundary).encode())
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=body.getvalue(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = sess.op.open(req)
        h = dict(r.headers)
    except urllib.error.HTTPError as e:
        h = dict(e.headers)
    qs = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    return {k: v[0] for k, v in qs.items()}


def xlsx(rows, shared=True):
    """A small workbook as Excel writes it (shared strings), or with inline strings."""
    strings, sheet = [], []
    for r, row in enumerate(rows):
        cells = []
        for c, v in enumerate(row):
            refc = chr(65 + c) + str(r + 1)
            if v == '':
                continue
            if isinstance(v, int):
                cells.append('<c r="%s"><v>%d</v></c>' % (refc, v))
            elif shared:
                strings.append(v)
                cells.append('<c r="%s" t="s"><v>%d</v></c>' % (refc, len(strings) - 1))
            else:
                cells.append('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>' % (refc, html.escape(v)))
        sheet.append('<row r="%d">%s</row>' % (r + 1, ''.join(cells)))
    b = io.BytesIO()
    with zipfile.ZipFile(b, 'w') as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
        z.writestr('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Managers" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        z.writestr('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">%s</sst>' % ''.join('<si><t>%s</t></si>' % html.escape(s) for s in strings))
        z.writestr('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>%s</sheetData></worksheet>' % ''.join(sheet))
    return b.getvalue()


wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
EMP1 = ids()['eid']
# Ahmed (head) → Mona → Nour → Hana; Ahmed → Karim → Hassan; Khaled alone. Mona and Karim have users.
out = php("""foreach(['ews_employee_relationships','ews_audit_log','ews_departments','ews_org_moves','ews_approval_requests','ews_approval_steps','ews_notifications'] as $t)$wpdb->query("DELETE FROM {$p}$t");
update_option('ews_org_chart',['enabled'=>1,'audience'=>'everyone','employee_view'=>'line','wide_span'=>8,'title_required'=>0],false); delete_transient('ews_org_moves_checked');
foreach(['orgmona','orgkarim'] as $l){$u=get_user_by('login',$l); if($u) wp_delete_user($u->ID);}
$mk=function($n,$t,$u=0)use($wpdb,$p){$d=strtolower(str_replace(' ','.',$n));$wpdb->insert("{$p}ews_employees",['name'=>$n,'domain_name'=>$d,'email'=>$d.'@example.com','wp_user_id'=>$u,'job_title'=>$t,'active'=>1,'attendance_enabled'=>1]);return $wpdb->insert_id;};
$um=wp_create_user('orgmona','orgmonapass','orgmona@example.com'); $uk=wp_create_user('orgkarim','orgkarimpass','orgkarim@example.com');
$e=['ahmed'=>$mk('Ahmed Samir','CEO'),'mona'=>$mk('Mona Adel','Sales Director',$um),'nour'=>$mk('Nour Saeed','Team Lead'),'hana'=>$mk('Hana Lotfy','Sales Rep'),
 'karim'=>$mk('Karim Fathy','Operations Director',$uk),'hassan'=>$mk('Hassan Hamdy','Supervisor'),'khaled'=>$mk('Khaled Hamdy','Driver')];
$e['um']=$um; $e['uk']=$uk; $now=current_time('mysql');
foreach([['mona','ahmed'],['nour','mona'],['hana','nour'],['karim','ahmed'],['hassan','karim']] as [$a,$b])
  $wpdb->insert("{$p}ews_employee_relationships",['employee_id'=>$e[$a],'related_employee_id'=>$e[$b],'relationship_type'=>'supervisor','active'=>1,'created_at'=>$now,'updated_at'=>$now]);
// Nour's leave waits for Mona at level 1 and level 2; an older one, already approved, stays as it was.
foreach([['WAITING_FOR_LEVEL_1',[['PENDING',1],['WAITING',2]]],['APPROVED',[['APPROVED',1]]]] as $i=>[$st,$steps]){
  $wpdb->insert("{$p}ews_approval_requests",['workflow_id'=>1,'entity_type'=>'leave','entity_id'=>900+$i,'requester_employee_id'=>$e['nour'],'status'=>$st,'current_step'=>1,'created_at'=>$now]);
  $rid=$wpdb->insert_id;
  foreach($steps as [$s,$o])$wpdb->insert("{$p}ews_approval_steps",['approval_request_id'=>$rid,'step_order'=>$o,'approver_employee_id'=>$e['mona'],'approver_wp_user_id'=>$um,'resolver_type'=>'SUPERVISOR','status'=>$s,'created_at'=>$now]);
}
echo wp_json_encode($e);""")
E = json.loads(out.splitlines()[-1])
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- the chart: the dialog's facts
page, d = chart(adm)
by = {p['id']: p for p in d.get('people', [])}
check('the page has the Move dialog, the managers pick list, Export and Import', 'id="wfo-org-dlg"' in page and 'id="wfo-org-pick"' in page and 'data-org-export' in page and 'id="wfo-org-import"' in page)
check('each person carries the approvals waiting for their manager (Nour: 2 steps waiting for Mona)', by[E['nour']]['w'] == 2 and by[E['hana']]['w'] == 0, by.get(E['nour']))

# ---------------------------------------------------------------- refused moves
check('a move needs a reason', move(adm, E['nour'], ref('Karim Fathy', E['karim']), reason='  ').get('org_error') == 'reason')
check('nobody can be put under someone who reports to them (Mona under Hana, two levels down)', move(adm, E['mona'], ref('Hana Lotfy', E['hana'])).get('org_error') == 'loop')
check('...or under themselves', move(adm, E['nour'], ref('Nour Saeed', E['nour'])).get('org_error') == 'self')
check('...or under the manager they already have', move(adm, E['nour'], ref('Mona Adel', E['mona'])).get('org_error') == 'same')
check('...a manager not picked from the list is refused', move(adm, E['nour'], 'Somebody').get('org_error') == 'manager')
check('...a date more than a year away is refused', move(adm, E['nour'], ref('Karim Fathy', E['karim']), date='2099-01-01').get('org_error') == 'date')
st, r, _ = emp.post('ews_org_move', employee_id=E['nour'], manager_ref=ref('Karim Fathy', E['karim']), effective_date=TODAY, reason='x')
check('an employee without the permission cannot move anyone', manager(E['nour']) == E['mona'] and st in (403, 500, 200) and 'org_notice' not in r)
check('nothing was moved by the refused attempts', manager(E['nour']) == E['mona'] and not q("SELECT id FROM {p}ews_org_moves"))

# ---------------------------------------------------------------- a move now
r = move(adm, E['nour'], ref('Karim Fathy', E['karim']), reason='New team in Alexandria')
check('Nour moved under Karim, with the waiting approvals', r.get('org_notice') == 'moved' and r.get('requests') == '2' and manager(E['nour']) == E['karim'], r)
check('...the people under her stay under her (Hana)', manager(E['hana']) == E['nour'])
steps = q("SELECT s.status,s.approver_employee_id a,s.approver_wp_user_id u FROM {p}ews_approval_steps s JOIN {p}ews_approval_requests r ON r.id=s.approval_request_id WHERE r.entity_id=900 ORDER BY s.step_order")
check('...her open request now waits for Karim, at both levels', [(int(x['a']), int(x['u'])) for x in steps] == [(E['karim'], E['uk'])] * 2, steps)
old = q("SELECT s.approver_employee_id a FROM {p}ews_approval_steps s JOIN {p}ews_approval_requests r ON r.id=s.approval_request_id WHERE r.entity_id=901")
check('...a request already decided keeps who decided it', [int(x['a']) for x in old] == [E['mona']])
n = q("SELECT title,message FROM {p}ews_notifications WHERE user_id=%d" % E['uk'])
check('...Karim is told', len(n) == 1 and 'Nour Saeed' in n[0]['message'], n)
mv = q("SELECT * FROM {p}ews_org_moves")
check('...kept in the history: from Mona to Karim, the date, the reason, 2 approvals', len(mv) == 1 and int(mv[0]['from_manager_id']) == E['mona'] and int(mv[0]['to_manager_id']) == E['karim']
      and mv[0]['effective_date'] == TODAY and mv[0]['reason'] == 'New team in Alexandria' and mv[0]['status'] == 'done' and int(mv[0]['requests_moved']) == 2 and mv[0]['source'] == 'chart', mv)
a = q("SELECT details FROM {p}ews_audit_log WHERE action='org_move'")
check('...and in the Audit Log with the reason', len(a) == 1 and 'Nour Saeed: Mona Adel → Karim Fathy' in a[0]['details'] and 'New team in Alexandria' in a[0]['details'], a)
page, d = chart(adm)
h = d.get('history', {}).get(str(E['nour']), [])
check('the chart shows it in Nour\'s history, and opens on her', len(h) == 1 and h[0]['r'] == 'New team in Alexandria' and h[0]['f'] == E['mona'] and d.get('focus') == 0, h)
st, page, _ = adm.req(CHART + '&org_notice=moved&requests=2&focus=%d' % E['nour'])
check('...the message says the approvals went to the new manager', 'Moved. 2 waiting approvals went to the new manager.' in page)
r = move(adm, E['khaled'], none=True)
check('no manager for someone who has none is "the same"', r.get('org_error') == 'same')
r = move(adm, E['hassan'], none=True, reason='Reports to the board')
check('a move to no manager', r.get('org_notice') == 'moved' and manager(E['hassan']) == 0)

# ---------------------------------------------------------------- a later date
r = move(adm, E['hana'], ref('Mona Adel', E['mona']), date=TOMORROW, reason='From tomorrow')
check('a date after today is scheduled, nothing changes yet', r.get('org_notice') == 'scheduled' and manager(E['hana']) == E['nour'], r)
check('...a second move for her is refused while one is scheduled', move(adm, E['hana'], ref('Karim Fathy', E['karim'])).get('org_error') == 'scheduled')
page, d = chart(adm)
check('...the page lists the scheduled move', 'data-scheduled="' in page and len(d.get('scheduled', [])) == 1 and d['scheduled'][0]['d'] == TOMORROW)
sid = d['scheduled'][0]['id']
st, r, _ = adm.post('ews_org_move_cancel', move_id=sid, _wpnonce=d['scheduled'][0]['nonce'])
check('...it can be cancelled', r.get('org_notice') == 'cancelled' and q("SELECT status FROM {p}ews_org_moves WHERE id=%d" % sid)[0]['status'] == 'cancelled')
r = move(adm, E['hana'], ref('Mona Adel', E['mona']), date=TOMORROW, reason='From tomorrow')
php("$wpdb->query(\"UPDATE {$p}ews_org_moves SET effective_date='%s' WHERE status='scheduled'\");" % TODAY)
php("do_action('ews_privacy_cleanup');")
check('on its day the daily job does it', manager(E['hana']) == E['mona'] and q("SELECT status FROM {p}ews_org_moves WHERE employee_id=%d ORDER BY id DESC LIMIT 1" % E['hana'])[0]['status'] == 'done')
# A move that can no longer be done: its new manager archived before the day.
r = move(adm, E['khaled'], ref('Karim Fathy', E['karim']), date=TOMORROW, reason='Fleet')
php("$wpdb->query(\"UPDATE {$p}ews_org_moves SET effective_date='%s' WHERE status='scheduled'\"); $wpdb->update(\"{$p}ews_employees\",['active'=>0],['id'=>%d]); delete_transient('ews_org_moves_checked');" % (TODAY, E['karim']))
page, d = chart(adm)
fm = q("SELECT id,status,note FROM {p}ews_org_moves WHERE employee_id=%d ORDER BY id DESC LIMIT 1" % E['khaled'])
check('a scheduled move whose new manager was archived is not done, and says why', fm[0]['status'] == 'failed' and fm[0]['note'] == 'manager' and manager(E['khaled']) == 0, fm)
check('...it is under Needs attention, with Dismiss and Move', 'data-failed="%s"' % fm[0]['id'] in page and 'the new manager was archived' in page)
check('...and the people under the archived manager are listed (Nour), with who it was', re.findall(r'data-archived="(\d+)"', page) == [str(E['nour'])] and 'was under Karim Fathy' in page)
php("$wpdb->update(\"{$p}ews_employees\",['active'=>1],['id'=>%d]);" % E['karim'])

# ---------------------------------------------------------------- the Employees page while the chart is on
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employees')
un = re.search(r'name="action" value="ews31_employee_update">.*?name="_wpnonce" value="([^"]+)"', page, re.S).group(1)
st, r, _ = adm.post('ews31_employee_update', _wpnonce=un, id=E['khaled'], name='Khaled Hamdy', domain_name='khaled.hamdy', email='khaled.hamdy@example.com',
                    department_id=0, supervisor_employee_ref=ref('Ahmed Samir', E['ahmed']), attendance_enabled=1, active=1)
km = q("SELECT source,reason,to_manager_id FROM {p}ews_org_moves WHERE employee_id=%d AND status='done'" % E['khaled'])
check('a supervisor set on the Employees page is kept as a move', r.get('employee_notice') == 'updated' and km == [{'source': 'employees', 'reason': '', 'to_manager_id': str(E['ahmed'])}], (r, km))
st, r, _ = adm.post('ews31_employee_update', _wpnonce=un, id=E['khaled'], name='Khaled Hamdy', domain_name='khaled.hamdy', email='khaled.hamdy@example.com',
                    department_id=0, supervisor_employee_ref=ref('Ahmed Samir', E['ahmed']), attendance_enabled=1, active=1)
check('...saving again without a change adds nothing', len(q("SELECT id FROM {p}ews_org_moves WHERE employee_id=%d AND status='done'" % E['khaled'])) == 1)
php("update_option('ews_org_chart',['enabled'=>0],false);")
st, r, _ = adm.post('ews31_employee_update', _wpnonce=un, id=E['khaled'], name='Khaled Hamdy', domain_name='khaled.hamdy', email='khaled.hamdy@example.com',
                    department_id=0, supervisor_employee_ref='', attendance_enabled=1, active=1)
check('...with the chart off, the Employees page works as before (no history)', manager(E['khaled']) == 0 and len(q("SELECT id FROM {p}ews_org_moves WHERE employee_id=%d" % E['khaled'])) == 2)
php("update_option('ews_org_chart',['enabled'=>1,'audience'=>'everyone'],false);")

# ---------------------------------------------------------------- Excel: export
page, d = chart(adm)
url = html.unescape(re.search(r'href="([^"]+)" data-org-export', page).group(1))
r = adm.op.open(urllib.request.Request(url))
body = r.read()
z = zipfile.ZipFile(io.BytesIO(body))
sheet = z.read('xl/worksheets/sheet1.xml').decode()
check('Export: an Excel file with every active employee and their manager', 'spreadsheetml' in r.headers.get('Content-Type', '') and 'Manager domain name' in sheet and 'Nour Saeed' in sheet and 'karim.fathy' in sheet
      and 'How to import' in z.read('xl/workbook.xml').decode(), r.headers.get('Content-Type'))
check('...written to the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='org_exported'")) == 1)

# ---------------------------------------------------------------- Excel: import
r = upload(adm, body, 'org-chart-managers.xlsx')
st, page, _ = adm.req(CHART + '&import=preview')
check('the exported file, unchanged: read, nothing to change', r.get('import') == 'preview' and re.search(r'(\d+) rows read: 0 new managers, 0 job titles, 0 rows cannot be used', page), r)
rows = [['Employee ID', 'Name', 'Job title', 'Manager ID', 'Manager domain name'],
        [E['hana'], 'Hana Lotfy', 'Senior Sales Rep', '', 'karim.fathy'],          # a new manager by domain name, and a title
        [E['mona'], 'Mona Adel', '', E['hassan'], ''],                             # by id
        [99999, 'Nobody', '', E['ahmed'], ''],                                     # unknown employee
        [E['nour'], 'Nour Saeed', '', '', 'nobody.here'],                          # unknown manager
        [E['khaled'], 'Khaled Hamdy', '', E['khaled'], ''],                        # self
        [E['ahmed'], 'Ahmed Samir', '', E['nour'], ''],                            # Ahmed under Nour, who is under Karim, under Ahmed: a loop
        [E['hana'], 'Hana again', '', E['ahmed'], ''],                             # twice
        ['', '', '', '', '']]
r = upload(adm, xlsx(rows), 'changes.xlsx', reason='Restructure')
st, page, _ = adm.req(CHART + '&import=preview')
check('preview: 7 rows, 2 new managers, 1 job title, 5 cannot be used', r.get('import') == 'preview' and '7 rows read: 2 new managers, 1 job titles, 5 rows cannot be used' in page, r)
prob = re.search(r'data-preview="problems">(.*?)</table>', page, re.S)
prob = html.unescape(re.sub(r'<[^>]+>', ' ', prob.group(1))) if prob else ''
check('...each refused row says why', all(x in prob for x in ['No employee with this ID or domain name', 'No employee with this manager ID or domain name', 'Nobody can be their own manager',
      'Would put them under someone who reports to them', 'The employee is in the file twice']), prob[:400])
check('...nothing is saved before the preview is accepted', manager(E['hana']) == E['mona'] and q("SELECT job_title FROM {p}ews_employees WHERE id=%d" % E['hana'])[0]['job_title'] == 'Sales Rep')
an = nonce(page, 'ews_org_import_apply')
st, r, _ = adm.post('ews_org_import_apply', _wpnonce=an, apply='1')
check('saved: 2 moved, 1 job title', r.get('org_notice') == 'imported' and r.get('moved') == '2' and r.get('titles') == '1', r)
check('...Hana under Karim with the new title, Mona under Hassan', manager(E['hana']) == E['karim'] and manager(E['mona']) == E['hassan']
      and q("SELECT job_title FROM {p}ews_employees WHERE id=%d" % E['hana'])[0]['job_title'] == 'Senior Sales Rep')
check('...the moves are in the history with the reason and "import"', len(q("SELECT id FROM {p}ews_org_moves WHERE source='import' AND reason='Restructure' AND status='done'")) == 2)
check('...and the import in the Audit Log', 'changes.xlsx: 2 moved' in (q("SELECT details FROM {p}ews_audit_log WHERE action='org_imported'") or [{'details': ''}])[0]['details'])
st, r, _ = adm.post('ews_org_import_apply', _wpnonce=an, apply='1')
check('the same preview cannot be saved twice', r.get('org_error') == 'expired', r)

# Order in the file does not matter: Hassan under Mona and Mona under Ahmed, though Mona is under Hassan now.
csv = 'Domain name;Manager domain name\r\nhassan.hamdy;mona.adel\r\nmona.adel;ahmed.samir\r\n'.encode('utf-8-sig')
r = upload(adm, csv, 'swap.csv')
st, page, _ = adm.req(CHART + '&import=preview')
check('a .csv file (semicolons) is read too: 2 new managers, no loop in the end result', '2 rows read: 2 new managers, 0 job titles, 0 rows cannot be used' in page, r)
st, r, _ = adm.post('ews_org_import_apply', _wpnonce=nonce(page, 'ews_org_import_apply'), apply='1')
check('...saved in the order that works', r.get('moved') == '2' and manager(E['hassan']) == E['mona'] and manager(E['mona']) == E['ahmed'], r)

r = upload(adm, xlsx([['Employee ID', 'Manager ID'], [E['nour'], E['mona']]], shared=False), 'later.xlsx', date=TOMORROW)
st, page, _ = adm.req(CHART + '&import=preview')
check('an import with a later date says the moves are scheduled', 'The moves are scheduled for %s.' % TOMORROW in page)
st, r, _ = adm.post('ews_org_import_apply', _wpnonce=nonce(page, 'ews_org_import_apply'), apply='1')
check('...and schedules them', r.get('planned') == '1' and manager(E['nour']) == E['karim'] and len(q("SELECT id FROM {p}ews_org_moves WHERE status='scheduled' AND source='import'")) == 1, r)
r = upload(adm, b'just some text', 'notes.txt')
check('a file that is not .xlsx or .csv is refused', r.get('org_error') == 'file', r)
r = upload(adm, xlsx([['Name', 'Title'], ['x', 'y']]), 'wrong.xlsx')
check('a file without the columns is refused, saying which are needed', r.get('org_error') == 'columns', r)
st, page, _ = adm.req(CHART + '&org_error=columns')
check('...with the message', 'The file needs an &quot;Employee ID&quot; or &quot;Domain name&quot; column' in page or 'The file needs an "Employee ID"' in page)

page, _ = chart(adm)
mn = nonce(page, 'ews_org_move')
php("update_option('ews_org_chart',['enabled'=>0],false);")
st, r, _ = adm.post('ews_org_move', _wpnonce=mn, employee_id=E['nour'], manager_ref=ref('Ahmed Samir', E['ahmed']), effective_date=TODAY, reason='x')
check('with the chart off, moves are refused', r.get('org_error') == 'off' and manager(E['nour']) == E['karim'], r)
php("""delete_option('ews_org_chart'); foreach(['orgmona','orgkarim'] as $l){$u=get_user_by('login',$l); if($u) wp_delete_user($u->ID);}
foreach(['ews_employee_relationships','ews_org_moves','ews_approval_requests','ews_approval_steps'] as $t)$wpdb->query("DELETE FROM {$p}$t");
$wpdb->query("DELETE FROM {$p}ews_employees WHERE id IN (%s)");""" % ','.join(str(v) for k, v in E.items() if k not in ('um', 'uk')))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

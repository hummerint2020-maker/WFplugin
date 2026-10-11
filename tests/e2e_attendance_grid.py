"""Behaviour tests for the employee app's manager Attendance grid (?ews_view=attendance):
results per day, saving the planned schedule (with its concurrency guard), department scope
and the CSV import.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_attendance_grid.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys, urllib.parse, uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, d, php, q, results, wp, HERE  # noqa: E402

DAY = d(-1)  # a past day, so a missing Sign In is Absent rather than Pending
PAGE = '/app/?ews_view=attendance&week=' + DAY


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_teams','ews_team_members','ews_departments','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false); delete_option('ews_shifts');
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        delete_option('ews_schedule_types_config');
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $mk=function($name,$dep,$tracking=1)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>$tracking,'department_id'=>$dep]);return $wpdb->insert_id;};
        $ids=['mona'=>$eid,'omar'=>$mk('Omar Ontime',$ops),'lina'=>$mk('Lina Late',$ops),'ali'=>$mk('Ali Absent',$ops),'tarek'=>$mk('Tarek Trainee',$ops),'uma'=>$mk('Uma Untracked',$ops,0),'sam'=>$mk('Sam Sales',$sales)];
        $day='%(day)s';
        foreach(['omar'=>'Office','lina'=>'WFH','ali'=>'Office','tarek'=>'Training Course','sam'=>'Office'] as $k=>$st) $wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$day,'status'=>$st]);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['omar'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_in','event_at'=>$day.' 08:05:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['lina'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_in','event_at'=>$day.' 09:30:00','scheduled_status'=>'WFH']);
        $m=new ReflectionMethod('EWS_Manager_V31_1','week_dates_configured'); $m->setAccessible(true);
        $ids['dates']=$m->invoke(new EWS_Manager_V31_1(),$day)[0];
        echo wp_json_encode($ids);
    """ % {'day': DAY})
    return json.loads(out.splitlines()[-1])


def cell(page, eid, idx):
    """The grid cell of one employee/day: (planned, result class, result text)."""
    m = re.search(r'<td class="ews-att-day[^"]*" data-employee="%d" data-day="%d" data-status="[^"]*" data-planned="([^"]*)">.*?<div class="ews-att-result ([a-z-]+)">(.*?)</div>' % (eid, idx), page, re.S)
    return (m.group(1), m.group(2), re.sub(r'<[^>]+>', ' ', m.group(3)).split()) if m else None


def stat(page, label):
    m = re.search(r'<b>(\d+)%?</b><small>' + re.escape(label) + '</small>', page)
    return int(m.group(1)) if m else None


def form_nonce(page, action):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            return m.group(1) if m else None
    return None


def grid_save(sess, changes=None, legacy=None, referer=PAGE):
    st, page, _ = sess.req(PAGE)
    data = {'action': 'ews31_att_grid_save', '_wpnonce': form_nonce(page, 'ews31_att_grid_save') or 'x', 'week': DAY, 'attendance_client': 'desktop', '_wp_http_referer': referer}
    if changes is not None:
        data['att_changes_json'] = json.dumps(changes)
    data.update(legacy or {})
    st, body, h = sess.req('/wp-admin/admin-post.php', data)
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def upload(sess, fields, filename, content):
    boundary = uuid.uuid4().hex
    parts = []
    for k, v in fields.items():
        parts.append('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v))
    parts.append('--%s\r\nContent-Disposition: form-data; name="attendance_csv"; filename="%s"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n' % (boundary, filename, content, boundary))
    import urllib.request
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=''.join(parts).encode(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = sess.op.open(req)
        return r.status, r.read().decode(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), dict(e.headers)


ctx = seed()
idx = ctx['dates'].index(DAY)
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- page and results
st, page, _ = adm.req(PAGE)
check('the Attendance page opens for an administrator', st == 200 and 'MANAGER CONTROL CENTER' in page, st)
check('an employee without attendance tracking is left out', 'Uma Untracked' not in page)
check('Present / Late / Absent per day', [cell(page, ctx[k], idx)[1] if cell(page, ctx[k], idx) else None for k in ('omar', 'lina', 'ali')] == ['present', 'late', 'absent'],
      [cell(page, ctx[k], idx) for k in ('omar', 'lina', 'ali')])
check('the Sign In time is shown', '08:05' in ' '.join(cell(page, ctx['omar'], idx)[2]) and '09:30' in ' '.join(cell(page, ctx['lina'], idx)[2]))
check('a business-trip type (Training Course) shows as such, not "Not scheduled"', cell(page, ctx['tarek'], idx)[1] == 'trip' and 'Training' in cell(page, ctx['tarek'], idx)[2], cell(page, ctx['tarek'], idx))
check('summary: 1 present, 1 late, 2 absent (Ali and Sam), 50%', (stat(page, 'Present days'), stat(page, 'Late days'), stat(page, 'Absent days'), stat(page, 'Attendance rate')) == (1, 1, 2, 50),
      (stat(page, 'Present days'), stat(page, 'Late days'), stat(page, 'Absent days'), stat(page, 'Attendance rate')))
check('the page script is a file (no inline handlers)', 'attendance-grid.js' in page and 'function ewsFillDay' not in page and 'onchange="this.form.submit()"' not in page)
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>'%s','title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);" % DAY)
st, page, _ = adm.req(PAGE)
check('a company holiday shows as Leave with its name', cell(page, ctx['ali'], idx)[1] == 'leave' and 'National' in cell(page, ctx['ali'], idx)[2], cell(page, ctx['ali'], idx))
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")

# ---------------------------------------------------------------- saving
qs = grid_save(adm, [{'employee': ctx['ali'], 'day': idx, 'status': 'WFH', 'original': 'Office'}])
check('a changed cell is saved', qs.get('grid_saved') == '1' and q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['ali'], DAY)) == [{'status': 'WFH'}], qs)
check('...and audited', q("SELECT details FROM {p}ews_audit_log WHERE action='attendance_grid'") == [{'details': DAY + ' | Old: Office | New: WFH'}])
qs = grid_save(adm, [{'employee': ctx['ali'], 'day': idx, 'status': 'Vacation', 'original': 'Office'}])
check('a stale change (someone saved in between) is not applied', qs.get('grid_conflict') == '1' and q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['ali'], DAY)) == [{'status': 'WFH'}], qs)
st, page, _ = adm.req(PAGE + '&' + urllib.parse.urlencode(qs))
check('...and the manager is told so (the layout\'s Schedule Conflict pop-up)', 'Schedule Conflict' in page and 'another manager changed the same cell' in page, re.findall(r'class="ews-notice[^"]*">([^<]*)', page))
conflict_url = PAGE + '&' + urllib.parse.urlencode(qs)
qs = grid_save(adm, [{'employee': ctx['ali'], 'day': idx, 'status': 'Vacation', 'original': 'WFH'}], referer=conflict_url)
check('the next save does not repeat the old conflict message', qs.get('grid_saved') == '1' and 'grid_conflict' not in qs, qs)
qs = grid_save(adm, [{'employee': ctx['ali'], 'day': idx, 'status': 'Beach', 'original': 'Vacation'}])
st, page, _ = adm.req(PAGE + '&' + urllib.parse.urlencode(qs))
check('an unknown status is refused and reported', qs.get('grid_invalid') == '1' and 'could not be saved' in page, (qs, re.findall(r'class="ews-notice[^"]*">([^<]*)', page)))

# ---------------------------------------------------------------- department scope
php("$u=new WP_User(%d); $u->add_cap('ews_manage_attendance');" % int(q("SELECT wp_user_id FROM {p}ews_employees WHERE id=%d" % ctx['mona'])[0]['wp_user_id']))
st, page, _ = emp.req(PAGE)
check('a department manager sees their department only', 'Omar Ontime' in page and 'Sam Sales' not in page)
qs = grid_save(emp, [{'employee': ctx['sam'], 'day': idx, 'status': 'WFH', 'original': 'Office'}])
check('...and cannot change another department', qs.get('grid_invalid') == '1' and q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['sam'], DAY)) == [{'status': 'Office'}], qs)
qs = grid_save(emp, legacy={'att[%d][%d]' % (ctx['sam'], idx): 'WFH'})
check('...not even through the no-JavaScript form', q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['sam'], DAY)) == [{'status': 'Office'}], qs)
qs = grid_save(emp, legacy={'att[%d][%d]' % (ctx['omar'], idx): 'WFH'})
check('the no-JavaScript form still saves their own department', qs.get('grid_saved') == '1' and q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['omar'], DAY)) == [{'status': 'WFH'}], qs)
php("$u=new WP_User(%d); $u->remove_cap('ews_manage_attendance');" % int(q("SELECT wp_user_id FROM {p}ews_employees WHERE id=%d" % ctx['mona'])[0]['wp_user_id']))
st, page, _ = emp.req(PAGE)
check('an employee without the permission cannot open it', 'MANAGER CONTROL CENTER' not in page and 'Ali Absent' not in page)

# ---------------------------------------------------------------- CSV import
NEXT = d(1)
st, page, _ = adm.req(PAGE)
csv = 'domain_name,work_date,status,note\nomar,%s,office,From CSV\nnobody,%s,Office,\nlina,%s,Beach,\n' % (NEXT, NEXT, NEXT)
st, body, h = upload(adm, {'action': 'ews31_att_preview', '_wpnonce': form_nonce(page, 'ews31_att_preview') or 'x'}, 'week.csv', csv)
preview = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).get('preview', [''])[0]
st, page, _ = adm.req(PAGE + '&preview=' + preview)
check('the CSV preview counts ready and skipped rows', re.search(r'<strong>1</strong> row\(s\) ready to import.*?<strong[^>]*>2</strong> row\(s\) will be skipped', page, re.S) is not None and 'Unknown or inactive employee' in page and 'Unknown status' in page)
token = re.search(r'name="token" value="([^"]+)"', page)
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_import', '_wpnonce': form_nonce(page, 'ews31_att_import') or 'x', 'token': token.group(1) if token else ''})
qs = {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}
check('import saves the valid rows and reports the rest', (qs.get('imported'), qs.get('rejected')) == ('1', '2') and q("SELECT status,note FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['omar'], NEXT)) == [{'status': 'Office', 'note': 'From CSV'}], qs)
st, page, _ = adm.req(PAGE + '&imported=1&rejected=2')
check('...with a message', 'Imported 1 row(s); rejected 2.' in page)
st, page, _ = adm.req(PAGE + '&preview=nope')
check('an expired preview says so', 'The CSV preview has expired' in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

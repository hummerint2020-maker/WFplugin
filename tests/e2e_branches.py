"""Behaviour tests for Branches (3.31.72): the three modes (one branch, any of their branches, by the
schedule), an employee's other branches, the branch planned for a day on the Attendance grid, Sign In
at the right / another / a foreign branch, Home and Sign In showing today's branch, the reports
(At Another Branch, Location Capacity actual), the "Manage Employee Branches" permission and the audit.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_branches.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys, time, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, today, wp, HERE  # noqa: E402

TODAY = today.isoformat()
AT = {'maadi': ('29.9600', '31.2500'), 'cairo': ('30.0300', '31.4700'), 'nasr': ('30.0600', '31.3300'), 'zamalek': ('30.0600', '31.2200')}


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_locations','ews_employee_locations_v321','ews_employee_branches','ews_departments','ews_audit_log','ews_company_calendar'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_branch_settings');
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        $loc=function($name,$lat,$lng,$default=0)use($wpdb,$p){$wpdb->insert($p.'ews_locations',['name'=>$name,'latitude'=>$lat,'longitude'=>$lng,'radius'=>300,'enforcement'=>1,'is_default'=>$default,'active'=>1]);return (int)$wpdb->insert_id;};
        $L=['maadi'=>$loc('Maadi',29.96,31.25,1),'cairo'=>$loc('New Cairo',30.03,31.47),'nasr'=>$loc('Nasr City',30.06,31.33),'zamalek'=>$loc('Zamalek',30.06,31.22)];
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=(int)$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=(int)$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Sara Ali','department_id'=>$ops],['id'=>$eid]);
        $wpdb->insert($p.'ews_employee_locations_v321',['employee_id'=>$eid,'location_id'=>$L['maadi']]);
        $wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>'%(today)s','status'=>'Office']);
        // A manager in Ops with "Manage Employee Branches" only, and an employee in Sales.
        $mu=username_exists('brmgr')?:wp_create_user('brmgr','brmgrpass','brmgr@example.com'); $u=new WP_User($mu); $u->set_role('subscriber'); $u->add_cap('ews_manage_employee_branches');
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_employees WHERE domain_name IN ('brmgr','salesguy')"));
        $wpdb->insert($p.'ews_employees',['name'=>'Branch Manager','domain_name'=>'brmgr','email'=>'brmgr@example.com','wp_user_id'=>$mu,'active'=>1,'attendance_enabled'=>1,'department_id'=>$ops]); $mgr=(int)$wpdb->insert_id;
        $wpdb->insert($p.'ews_employees',['name'=>'Sales Guy','domain_name'=>'salesguy','email'=>'salesguy@example.com','active'=>1,'attendance_enabled'=>1,'department_id'=>$sales]); $sg=(int)$wpdb->insert_id;
        echo wp_json_encode(['L'=>$L,'emp'=>$eid,'mgr'=>$mgr,'sales'=>$sg]);
    """ % {'today': TODAY})
    return json.loads(out.splitlines()[-1])


def forms(page, action):
    return [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="action" value="%s"' % action in f]


def nonce_of(form):
    m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
    return m.group(1) if m else ''


def settings(mode, allow_others=1, kiosk_any=1, show_branch=1, manager_scope=0):
    st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
    f = forms(page, 'ews31_features_save')
    data = {'action': 'ews31_features_save', '_wpnonce': nonce_of(f[0]) if f else 'x', 'branch_mode': mode, 'early_leave_max': 120, 'early_leave_monthly': 240,
            'breaks_per_day': 3, 'break_duration': 30, 'break_escalation': 45, 'recognition_weekly_limit_mode': 'limited', 'recognition_weekly_limit': 5}
    for k, v in (('allow_others', allow_others), ('kiosk_any', kiosk_any), ('show_branch', show_branch), ('manager_scope', manager_scope)):
        if v:
            data['branch_' + k] = 1
    adm.req('/wp-admin/admin-post.php', data)
    return json.loads(php("echo wp_json_encode(get_option('ews_branch_settings'));") or 'null')


def reset_day():
    php("$wpdb->query($wpdb->prepare(\"DELETE FROM {$p}ews_time_logs WHERE employee_id=%%d\",%d));" % ids['emp'])


def sign_in(where):
    st, page, _ = emp.req('/app/?ews_view=time')
    f = next((x for x in forms(page, 'ews31_time_event') if 'name="event_type" value="sign_in"' in x), '')
    lat, lng = AT[where]
    st, body, h = emp.req('/wp-admin/admin-post.php', {'action': 'ews31_time_event', 'event_type': 'sign_in', '_wpnonce': nonce_of(f), 'latitude': lat, 'longitude': lng,
                                                         'accuracy': '20', 'location_timestamp': str(int(time.time() * 1000))})
    qs = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    row = q("SELECT location_id,branch_flag,location_status FROM {p}ews_time_logs WHERE employee_id=%d AND event_type IN ('sign_in','late_sign_in') ORDER BY id DESC LIMIT 1" % ids['emp'])
    return (qs.get('time_success') or [''])[0], (qs.get('time_error') or [''])[0], (row[0] if row else None)


def lid(k):
    return str(ids['L'][k])


ids = seed()
adm = Session('admin', 'admin')
emp = Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- settings
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
check('Feature Configuration has a Branches section with three modes', 'id="ews-branches"' in page and all('name="branch_mode" value="%s"' % m in page for m in ('single', 'any', 'schedule')))
check('..."One branch" is chosen at first', re.search(r'name="branch_mode" value="single"\s+checked', page) is not None)
cfg = settings('any', allow_others=0, kiosk_any=1, show_branch=1, manager_scope=1)
check('the mode and its options are saved', cfg and cfg['mode'] == 'any' and cfg['allow_others'] == 0 and cfg['kiosk_any'] == 1 and cfg['manager_scope'] == 1, cfg)
check('...and audited', q("SELECT id FROM {p}ews_audit_log WHERE action='branch_settings_update'") != [])

# ---------------------------------------------------------------- employee branches (admin)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-multi-locations')
row = next((r for r in re.findall(r'<tr>.*?</tr>', page, re.S) if 'ews-branches-%d"' % ids['emp'] in r), '')
check('Work Locations lists each employee with a main branch and other branches', 'Employee Branches' in page and 'name="other_location_ids[]"' in row and re.search(r'<option value="%s"\s+selected' % lid('maadi'), row) is not None)
f = next((x for x in forms(page, 'ews_employee_location_save_v321') if 'name="employee_id" value="%d"' % ids['emp'] in x), '')
adm.req('/wp-admin/admin-post.php', {'action': 'ews_employee_location_save_v321', '_wpnonce': nonce_of(f), 'employee_id': ids['emp'], 'location_id': lid('maadi'), 'branches_form': 1, 'other_location_ids[]': [lid('nasr'), lid('cairo'), lid('maadi')]})
others = sorted(int(r['location_id']) for r in q("SELECT location_id FROM {p}ews_employee_branches WHERE employee_id=%d" % ids['emp']))
check('other branches are saved (the main branch is not repeated there)', others == sorted([ids['L']['nasr'], ids['L']['cairo']]), others)
check('...and audited with the names', any('Nasr City' in (r['details'] or '') for r in q("SELECT details FROM {p}ews_audit_log WHERE action='employee_branches_update'")))

# ---------------------------------------------------------------- mode: any of their branches
settings('any')
reset_day()
ok, err, row = sign_in('nasr')
check('"Any of their branches": signing in at another of their branches is accepted', ok and not err and row and row['location_id'] == lid('nasr') and row['location_status'] == 'inside' and not row['branch_flag'], (ok, err, row))
reset_day()
ok, err, row = sign_in('zamalek')
check('...a branch that is not theirs is refused (their main branch requires the location)', err and not ok and 'outside' in urllib.parse.unquote(err).lower(), (ok, err))

# ---------------------------------------------------------------- mode: one branch
settings('single')
reset_day()
ok, err, row = sign_in('nasr')
check('"One branch": another of their branches is refused, as before', err and not ok, (ok, err, row))
reset_day()
ok, err, row = sign_in('maadi')
check('...their main branch is accepted and recorded', ok and row and row['location_id'] == lid('maadi'), (ok, err, row))

# ---------------------------------------------------------------- mode: by the schedule
settings('schedule', allow_others=1)
reset_day()
st, page, _ = adm.req('/app/?ews_view=attendance&week=' + TODAY)
cells = re.findall(r'<td class="ews-att-day[^"]*" data-employee="%d" data-day="(\d+)"[^>]*data-branch="([^"]*)" data-branches="([^"]*)"' % ids['emp'], page)
check('the Attendance grid shows each day\'s branch and the employee\'s branches', cells and set(cells[0][2].split(',')) == {lid('maadi'), lid('cairo'), lid('nasr')} and 'data-ews-branches' in page and 'data-ews-fill-branch' in page, cells[:1])
day = next((m for m in re.findall(r'<td class="ews-att-day is-today[^"]*" data-employee="%d" data-day="(\d+)"' % ids['emp'], page)), None)
f = forms(page, 'ews31_att_grid_save')
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_grid_save', '_wpnonce': nonce_of(f[0]) if f else 'x', 'week': TODAY, 'attendance_client': 'desktop',
                                                     'att_changes_json': json.dumps([{'employee': ids['emp'], 'day': int(day or 0), 'status': 'Office', 'original': 'Office', 'branch': lid('cairo')}])})
planned = q("SELECT location_id FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ids['emp'], TODAY))
check('a manager plans today at New Cairo from the grid', planned and planned[0]['location_id'] == lid('cairo'), (planned, h.get('Location')))
st, page, _ = adm.req('/app/?ews_view=attendance&week=' + TODAY)
check('...shown under the day', re.search(r'data-employee="%d" data-day="%s"[^>]*data-branch="%s"' % (ids['emp'], day, lid('cairo')), page) is not None and '>New Cairo</small>' in page)
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_grid_save', '_wpnonce': nonce_of(forms(page, 'ews31_att_grid_save')[0]), 'week': TODAY, 'attendance_client': 'desktop',
                                                     'att_changes_json': json.dumps([{'employee': ids['emp'], 'day': int(day or 0), 'status': 'Office', 'original': 'Office', 'branch': lid('zamalek')}])})
planned = q("SELECT location_id FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ids['emp'], TODAY))
check('...a branch that is not hers cannot be planned', planned and planned[0]['location_id'] == lid('cairo'), planned)

st, home, _ = emp.req('/app/?ews_view=dashboard')
check('Home shows today\'s branch, planned', 'New Cairo' in home and 'Planned for today' in home)
st, tpage, _ = emp.req('/app/?ews_view=time')
check('the Sign In page says where she is expected', 'At New Cairo, planned for today' in tpage)
ok, err, row = sign_in('cairo')
check('"By the schedule": the planned branch is accepted, nothing flagged', ok and row and row['location_id'] == lid('cairo') and not row['branch_flag'], (ok, err, row))
reset_day()
ok, err, row = sign_in('nasr')
check('...another of her branches is accepted and flagged', ok and row and row['location_id'] == lid('nasr') and row['branch_flag'] == 'other_branch', (ok, err, row))
check('...and she is told her manager will see it', 'not the branch planned for today' in urllib.parse.unquote(ok))
st, rpage, _ = adm.req('/app/?ews_view=reports&report_type=attendance&start=%s&end=%s&status=%s' % (TODAY, TODAY, urllib.parse.quote('At Another Branch')))
check('the daily log can show the days at another branch', 'At Another Branch' in rpage and 'Sara Ali' in rpage)
st, cpage, _ = adm.req('/app/?ews_view=reports&report_type=capacity&start=%s&end=%s' % (TODAY, TODAY))
cap = dict(re.findall(r'data-location="([^"]+)" data-date="%s"[^>]*data-actual="(\d+)"' % TODAY, cpage))
plan = dict(re.findall(r'data-location="([^"]+)" data-date="%s"[^>]*data-planned="(\d+)"' % TODAY, cpage))
check('Location Capacity: planned at New Cairo, actual at Nasr City', cap.get('Nasr City') == '1' and cap.get('New Cairo', '0') == '0' and plan.get('New Cairo') == '1', (plan, cap))
settings('schedule', allow_others=0)
reset_day()
ok, err, row = sign_in('nasr')
check('without "Also allow their other branches", only the planned branch is accepted', err and not ok, (ok, err, row))

# ---------------------------------------------------------------- permission: a manager with "Manage Employee Branches"
mgr = Session('brmgr', 'brmgrpass')
st, page, _ = mgr.req('/wp-admin/admin.php?page=ews31-employee-branches')
check('a manager with the permission has an Employee Branches page with their department only', st == 200 and 'ews-branches-%d"' % ids['emp'] in page and 'ews-branches-%d"' % ids['sales'] not in page, st)
f = next((x for x in forms(page, 'ews_employee_location_save_v321') if 'name="employee_id" value="%d"' % ids['emp'] in x), '')
mgr.req('/wp-admin/admin-post.php', {'action': 'ews_employee_location_save_v321', '_wpnonce': nonce_of(f), 'employee_id': ids['emp'], 'location_id': lid('maadi'), 'branches_form': 1, 'other_location_ids[]': [lid('nasr')]})
others = sorted(int(r['location_id']) for r in q("SELECT location_id FROM {p}ews_employee_branches WHERE employee_id=%d" % ids['emp']))
check('...can change their people\'s branches', others == [ids['L']['nasr']], others)
settings('schedule', manager_scope=1)
st, page, _ = mgr.req('/wp-admin/admin.php?page=ews31-employee-branches')
row = next((r for r in re.findall(r'<tr>.*?</tr>', page, re.S) if 'ews-branches-%d"' % ids['emp'] in r), '')
offered = re.findall(r'<option value="(\d+)"', re.search(r'name="other_location_ids\[\]".*?</select>', row, re.S).group(0)) if row else []
check('with "their department\'s branches only", a manager is offered only the branches their people use', sorted(offered) == sorted([lid('maadi'), lid('nasr')]), offered)
f = next((x for x in forms(page, 'ews_employee_location_save_v321') if 'name="employee_id" value="%d"' % ids['emp'] in x), '')
st, body, h = mgr.req('/wp-admin/admin-post.php', {'action': 'ews_employee_location_save_v321', '_wpnonce': nonce_of(f), 'employee_id': ids['emp'], 'location_id': lid('zamalek')})
main = q("SELECT location_id FROM {p}ews_employee_locations_v321 WHERE employee_id=%d" % ids['emp'])
check('...and cannot give another one', 'not available' in body and main and main[0]['location_id'] == lid('maadi'), (st, main))
st, body, h = mgr.req('/wp-admin/admin-post.php', {'action': 'ews_employee_location_save_v321', '_wpnonce': nonce_of(f), 'employee_id': ids['sales'], 'location_id': lid('zamalek')})
check('...but not someone in another department', st >= 400 or 'Access denied' in body, st)
check('...and every change names who made it', q("SELECT user_id FROM {p}ews_audit_log WHERE action='employee_branches_update' ORDER BY id DESC LIMIT 1")[0]['user_id'] == str(php("echo username_exists('brmgr');")))
plain = Session('emp1', 'emp1pass')
st, page, _ = plain.req('/wp-admin/admin.php?page=ews31-employee-branches')
check('an employee without the permission cannot open it', st >= 400 or 'ews-branches-' not in page, st)

php("delete_option('ews_branch_settings'); $wpdb->query(\"DELETE FROM {$p}ews_employee_branches\"); $wpdb->query(\"DELETE FROM {$p}ews_employees WHERE domain_name IN ('brmgr','salesguy')\");")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

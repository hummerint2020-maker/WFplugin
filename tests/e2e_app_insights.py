"""Behaviour tests for the employee app's Attendance Insights page (?ews_view=attendance-insights).
It must agree with wp-admin → Attendance Insights (tests/e2e_insights.py).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_app_insights.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

DAY = d(-1)  # a past day, so a missing Sign In is Absent rather than Pending
PAGE = '/app/?ews_view=attendance-insights&focus_date=' + DAY


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_teams','ews_team_members','ews_departments'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false); delete_option('ews_shifts');
        $types=get_option('ews_schedule_types_config'); if(!is_array($types)||!$types){$m=new ReflectionMethod('EWS_Manager_V31_1','default_schedule_types_config');$m->setAccessible(true);$types=$m->invoke(new EWS_Manager_V31_1());}
        $types=array_values(array_filter($types,function($t){return $t['name']!=='Field Visit';}));
        $types[]=['name'=>'Field Visit','requires_sign_in'=>1,'requires_location'=>0,'attendance_rule'=>'attendance','active'=>1,'icon'=>'•','bg_color'=>'#f2f4f7','text_color'=>'#667085','border_color'=>'#e5e7eb'];
        update_option('ews_schedule_types_config',$types,false);
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=$wpdb->insert_id;
        $mk=function($name,$dep,$tracking=1)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>$tracking,'department_id'=>$dep]);return $wpdb->insert_id;};
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $ids=['Mona Manager'=>$eid,'Omar Ontime'=>$mk('Omar Ontime',$ops),'Lina Late'=>$mk('Lina Late',$ops),'Vera Vacation'=>$mk('Vera Vacation',$ops),'Uma Untracked'=>$mk('Uma Untracked',$ops,0),
              'Fadi Field'=>$mk('Fadi Field',$ops),'Tarek Trainee'=>$mk('Tarek Trainee',$ops),'Sam Sales'=>$mk('Sam Sales',$sales)];
        $plan=['Omar Ontime'=>'Office','Lina Late'=>'WFH','Vera Vacation'=>'Vacation','Uma Untracked'=>'Office','Fadi Field'=>'Field Visit','Tarek Trainee'=>'Training Course','Sam Sales'=>'Office'];
        $day='%(day)s';
        foreach($plan as $n=>$st) $wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$n],'work_date'=>$day,'status'=>$st]);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['Omar Ontime'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_in','event_at'=>$day.' 08:05:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['Omar Ontime'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_out','event_at'=>$day.' 17:00:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['Lina Late'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_in','event_at'=>$day.' 09:30:00','scheduled_status'=>'WFH']);
        $wpdb->insert($p.'ews_teams',['name'=>'Blue Team','manager_employee_id'=>$eid,'department_id'=>$ops,'active'=>1]); $blue=$wpdb->insert_id;
        $wpdb->insert($p.'ews_teams',['name'=>'Red Team','manager_employee_id'=>$ids['Sam Sales'],'department_id'=>$sales,'active'=>1]); $red=$wpdb->insert_id;
        foreach(['Mona Manager','Omar Ontime','Lina Late'] as $n) $wpdb->insert($p.'ews_team_members',['team_id'=>$blue,'employee_id'=>$ids[$n],'active'=>1]);
        $wpdb->insert($p.'ews_team_members',['team_id'=>$red,'employee_id'=>$ids['Sam Sales'],'active'=>1]);
        echo wp_json_encode($ids);
    """ % {'day': DAY})
    return json.loads(out.splitlines()[-1])


def names(page, kind, label):
    """Names behind the first drill-down button of this kind and label."""
    m = re.search(r'<button[^>]*data-kind="%s" data-label="%s"[^>]*data-names="([^"]*)"' % (kind, re.escape(label)), page)
    return sorted(json.loads(html.unescape(m.group(1)))) if m else None


ids = seed()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
st, page, _ = adm.req(PAGE)
check('the page opens', st == 200 and 'Weekly Workforce Overview' in page, st)
actual = tuple(names(page, 'actual', k) for k in ('Present', 'Late', 'Absent'))
check('focus day: Present / Late / Absent', actual == (['Omar Ontime'], ['Lina Late'], ['Fadi Field', 'Sam Sales']), actual)
check('Vacation is planned Leave', names(page, 'planned', 'Leave') == ['Vera Vacation'], names(page, 'planned', 'Leave'))
check('Training Course is planned Business Trip (not Not Set)', names(page, 'planned', 'Business Trip') == ['Tarek Trainee'], (names(page, 'planned', 'Business Trip'), names(page, 'planned', 'Not Set')))
check('a custom schedule type is planned Other (not Not Set)', names(page, 'planned', 'Other') == ['Fadi Field'], names(page, 'planned', 'Other'))
check('an employee without attendance tracking is left out', 'Uma Untracked' not in page)
check('Missing Sign-out lists who signed in but not out', names(page, 'actual', 'Missing Sign-out') == ['Lina Late'], names(page, 'actual', 'Missing Sign-out'))
weekly = re.search(r'data-kind="planned" data-label="Office" data-date="[^"]*" data-names="([^"]*)" data-day="%s"' % DAY, page)
check('the weekly table counts the same people', weekly is not None and sorted(json.loads(html.unescape(weekly.group(1)))) == ['Omar Ontime', 'Sam Sales'], weekly and weekly.group(1))
check('the page script and styles are files', 'app-attendance-insights.js' in page and '.ews-fi{' not in page and 'onchange="this.form.submit()"' not in page)

st, page, _ = adm.req(PAGE + '&team=Blue+Team')
check('the team filter keeps the team only', names(page, 'actual', 'Present') == ['Omar Ontime'] and 'Sam Sales' not in page and 'Vera Vacation' not in page)
st, page, _ = adm.req('/app/?ews_view=attendance-insights&focus_date=2026-02-31')
check('an impossible date falls back to today', 'value="%s"' % today.isoformat() in page)

st, page, _ = emp.req(PAGE)
check('an employee without the permission cannot open it', 'Weekly Workforce Overview' not in page)
uid = q("SELECT wp_user_id FROM {p}ews_employees WHERE id=%d" % ids['Mona Manager'])[0]['wp_user_id']
php("$u=new WP_User(%s); $u->add_cap('ews_view_reports');" % uid)
st, page, _ = emp.req(PAGE)
check('a department manager sees their department only', 'Omar Ontime' in page and 'Sam Sales' not in page and 'Red Team' not in page and 'Blue Team' in page)
php("$u=new WP_User(%s); $u->remove_cap('ews_view_reports');" % uid)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

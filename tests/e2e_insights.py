"""Behaviour tests for the wp-admin "Attendance Insights" page.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_insights.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, results, wp, HERE  # noqa: E402

DAY = d(-1)  # a past day, so a missing Sign In is Absent rather than Pending
PAGE = '/wp-admin/admin.php?page=ews31-attendance-insights&week=%s&focus_date=%s' % (DAY, DAY)


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_teams','ews_team_members'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false); delete_option('ews_shifts');
        $types=get_option('ews_schedule_types_config'); if(!is_array($types)||!$types){$m=new ReflectionMethod('EWS_Manager_V31_1','default_schedule_types_config');$m->setAccessible(true);$types=$m->invoke(new EWS_Manager_V31_1());}
        $types=array_values(array_filter($types,function($t){return $t['name']!=='Field Visit';}));
        $types[]=['name'=>'Field Visit','requires_sign_in'=>1,'requires_location'=>0,'attendance_rule'=>'attendance','active'=>1,'icon'=>'•','bg_color'=>'#f2f4f7','text_color'=>'#667085','border_color'=>'#e5e7eb'];
        update_option('ews_schedule_types_config',$types,false);
        $mk=function($name,$tracking=1)use($wpdb,$p){$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>strtolower(str_replace(' ','',$name)),'email'=>strtolower(str_replace(' ','',$name)).'@example.com','active'=>1,'attendance_enabled'=>$tracking]);return $wpdb->insert_id;};
        $wpdb->update($p.'ews_employees',['name'=>'Ontime Omar'],['id'=>$eid]);
        $ids=['Ontime Omar'=>$eid,'Late Lina'=>$mk('Late Lina'),'Vacation Vera'=>$mk('Vacation Vera'),'Untracked Uma'=>$mk('Untracked Uma',0),'Field Fadi'=>$mk('Field Fadi'),'Trip Tarek'=>$mk('Trip Tarek')];
        $plan=['Ontime Omar'=>'Office','Late Lina'=>'WFH','Vacation Vera'=>'Vacation','Untracked Uma'=>'Office','Field Fadi'=>'Field Visit','Trip Tarek'=>'Business Trip'];
        $day='%s';
        foreach($ids as $n=>$id) $wpdb->insert($p.'ews_schedule',['employee_id'=>$id,'work_date'=>$day,'status'=>$plan[$n]]);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['Ontime Omar'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_in','event_at'=>$day.' 08:05:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['Ontime Omar'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_out','event_at'=>$day.' 17:00:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['Late Lina'],'user_id'=>1,'work_date'=>$day,'event_type'=>'sign_in','event_at'=>$day.' 09:30:00','scheduled_status'=>'WFH']);
        echo wp_json_encode($ids);
    """ % DAY)
    return json.loads(out.splitlines()[-1])


def names(page, kind, label, cls=None):
    """Names behind the first drill-down button of this kind and label."""
    for m in re.finditer(r'<button[^>]*data-kind="%s" data-label="%s"[^>]*data-names="([^"]*)"' % (kind, re.escape(label)), page):
        return sorted(json.loads(html.unescape(m.group(1))))
    return None


ids_by_name = seed()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
st, page, _ = adm.req(PAGE)
check('the page opens', st == 200 and 'Attendance Insights' in page, st)
check('focus day: Present / Late / Absent', (names(page, 'actual', 'Present'), names(page, 'actual', 'Late'), names(page, 'actual', 'Absent')) == (['Ontime Omar'], ['Late Lina'], ['Field Fadi']),
      (names(page, 'actual', 'Present'), names(page, 'actual', 'Late'), names(page, 'actual', 'Absent')))
check('a Vacation day is planned Leave (not Not Set)', names(page, 'planned', 'Leave') == ['Vacation Vera'] and 'Vacation Vera' not in (names(page, 'planned', 'Not Set') or []), (names(page, 'planned', 'Leave'), names(page, 'planned', 'Not Set')))
check('a custom schedule type is planned under Other', names(page, 'planned', 'Other') == ['Field Fadi'], names(page, 'planned', 'Other'))
check('an employee without attendance tracking is left out', 'Untracked Uma' not in page)
check('the employee count excludes untracked employees', re.search(r'<b>5</b><small>Employees</small>', page) is not None, re.findall(r'<b>(\d+)</b><small>Employees</small>', page))
check('"scheduled" counts every type that requires Sign In', '3 employees are expected to sign in' in page, re.findall(r'(\d+) employees are', page))
check('Missing Sign-out lists who signed in but not out', names(page, 'actual', 'Missing Sign-out') == ['Late Lina'])
check('"Review" opens the employee profile', 'page=ews31-employee-profile&amp;employee_id=%d' % ids_by_name['Field Fadi'] in page or 'page=ews31-employee-profile&#038;employee_id=%d' % ids_by_name['Field Fadi'] in page)
check('the drill-down script is a file, not inline', 'admin-attendance-insights.js' in page and 'function openDrawer' not in page)
st, page2, _ = adm.req(PAGE.replace('focus_date=%s' % DAY, 'focus_date=2026-02-31'))
check('an impossible focus date falls back to today', st == 200 and 'Attendance Insights' in page2)
st, page, _ = emp.req(PAGE)
check('an employee cannot open the page', 'Attendance Insights' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

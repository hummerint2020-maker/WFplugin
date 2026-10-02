"""Behaviour tests for the employee app's My Profile page (?ews_view=profile).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_my_profile.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, results, today, wp, HERE  # noqa: E402

PAGE = '/app/?ews_view=profile'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_company_calendar','ews_leave_balances'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false); delete_option('ews_shifts');
        $wpdb->update($p.'ews_employees',['name'=>'Mona Ahmed Monier'],['id'=>$eid]);
        $log=function($day,$type,$time)use($wpdb,$p,$eid){$wpdb->insert($p.'ews_time_logs',['employee_id'=>$eid,'user_id'=>1,'work_date'=>$day,'event_type'=>$type,'event_at'=>$day.' '.$time,'scheduled_status'=>'Office']);};
        $log('%(d1)s','sign_in','09:30:00'); $log('%(d1)s','sign_out','17:00:00');
        $log('%(d2)s','late_sign_in','09:40:00'); $log('%(d2)s','sign_out','17:00:00');
        $log('%(d3)s','sign_in','08:00:00'); $log('%(d3)s','sign_out','16:30:00');
        $lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
        $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$lt,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>1.5,'pending'=>0]);
    """ % {'d1': d(-1), 'd2': d(-2), 'd3': d(-3)})


def recent(page, date):
    """Sign In and Status of the Recent Attendance row of a date."""
    label = date.strftime('%B %-d, %Y')
    m = re.search(r'<tr><td>%s</td><td>([^<]*)</td><td>[^<]*</td><td>([^<]*)</td><td><span class="ews-att-status [^"]*">([^<]*)</span>' % re.escape(label), page)
    return m.groups() if m else None


seed()
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req(PAGE)
check('My Profile opens', st == 200 and 'My Information' in page and 'Mona Ahmed Monier' in page, st)
check('initials: first and last name (as on the admin profile)', re.search(r'class="ews-my-profile-avatar"[^>]*>\s*MM\s*<', page) is not None, re.findall(r'class="ews-my-profile-avatar"[^>]*>([^<]{0,20})', page))
import datetime  # noqa: E402
day = lambda n: today + datetime.timedelta(days=n)  # noqa: E731
check('a late Sign In is Late, not Present', recent(page, day(-1)) == ('9:30 AM', '7h 30m', 'Late'), recent(page, day(-1)))
check('an older-style late Sign In is shown (not "No Sign In")', recent(page, day(-2)) == ('9:40 AM', '7h 20m', 'Late'), recent(page, day(-2)))
check('an on-time day is Present with its total', recent(page, day(-3)) == ('8:00 AM', '8h 30m', 'Present'), recent(page, day(-3)))
check('"View Leave" opens the Leave page', 'ews_view=vacation' in page and 'ews_view=leave' not in page)
check('the leave balance shows 19.5 available', '<strong>19.5</strong> available' in page, re.findall(r'<strong>([^<]*)</strong> available', page))
check('the page script and styles are files', 'my-profile.js' in page and '.ews-my-profile{' not in page and "modal('ews-password-modal'" not in page)
st, page, _ = emp.req(PAGE + '&profile_error=size')
check('an upload error is explained', 'Image must be 2 MB or smaller.' in page)

php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>current_time('Y-m-d'),'title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);")
st, page, _ = emp.req(PAGE)
planned = re.search(r'Today · Planned</div><div class="value">([^<]*)<', page)
result = re.search(r'Today · Attendance</div><div class="value">([^<]*)<', page)
check('on a company holiday today is General Leave, not "Not Signed In"', planned and planned.group(1) == 'General Leave' and result and result.group(1) == 'General Leave', (planned and planned.group(1), result and result.group(1)))
check('...also in My Week', re.search(r'current-day"><div class="d">[^<]*<br>[^<]*</div><div class="s">General Leave</div>', page) is not None)
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")

php("$id=username_exists('loner')?:wp_create_user('loner','lonerpass','loner@example.com'); (new WP_User($id))->set_role('subscriber');")
st, page, _ = Session('loner', 'lonerpass').req(PAGE)
check('a login without an employee record is told so', 'Profile unavailable' in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

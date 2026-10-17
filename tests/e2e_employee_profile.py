"""Behaviour tests for the wp-admin employee profile page, and for judging On Time / Late Arrival
by the employee's own shift everywhere it is shown.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_employee_profile.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, today, wp, HERE  # noqa: E402

DAY = today.isoformat()


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_teams','ews_team_members','ews_employee_relationships','ews_leave_balances'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false);
        update_option('ews_grace_period',10,false);
        update_option('ews_shifts',[['id'=>1,'name'=>'Day','start'=>'08:00','end'=>'17:00','grace'=>10,'sign_in_cutoff_minutes'=>240,'overnight'=>0,'active'=>1],['id'=>9,'name'=>'Late Shift','start'=>'10:00','end'=>'18:00','grace'=>10,'sign_in_cutoff_minutes'=>240,'overnight'=>0,'active'=>1]],false);
        $wpdb->update($p.'ews_employees',['name'=>'أحمد منير','default_shift_id'=>9],['id'=>$eid]);
        $wpdb->insert($p.'ews_employees',['name'=>'Boss Person','domain_name'=>'boss','email'=>'boss@example.com','active'=>1]); $boss=$wpdb->insert_id;
        $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>$eid,'relationship_type'=>'supervisor','related_employee_id'=>$boss,'active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
        $wpdb->insert($p.'ews_teams',['name'=>'Ops Team','active'=>1]); $t=$wpdb->insert_id;
        $wpdb->insert($p.'ews_team_members',['team_id'=>$t,'employee_id'=>$eid,'active'=>1]);
        $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
        $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>3,'pending'=>1]);
    """)


def sign_in_at(hhmm):
    php("$wpdb->query(\"DELETE FROM {$p}ews_time_logs\"); $wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>%d,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s %s:00','scheduled_status'=>'Office']);" % (ids()['eid'], ids()['uid'], DAY, DAY, hhmm))


seed()
eid = ids()['eid']
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
PROFILE = '/wp-admin/admin.php?page=ews31-employee-profile&employee_id=%d' % eid

st, page, _ = adm.req(PROFILE)
check('the profile opens for the administrator', st == 200 and 'أحمد منير' in page, st)
check('Arabic initials are whole letters', re.search(r'class="ews-profile-avatar">\s*أم\s*<', page) is not None, re.findall(r'class="ews-profile-avatar">(.{0,20})', page))
check('it shows supervisor, team and default shift', 'Boss Person' in page and 'Ops Team' in page and 'Late Shift' in page)
check("it shows this year's leave balance", 'Annual Leave' in page and '17.00 available' in page)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employee-profile&employee_id=999999')
check('an unknown employee says so', 'Employee not found' in page)
st, page, _ = emp.req(PROFILE)
check('employee cannot open an admin profile', 'أحمد منير' not in page, st)

# 10:05 is on time for the 10:00 Late Shift, though late for company hours (08:00).
sign_in_at('10:05')
st, page, _ = adm.req(PROFILE)
m = re.search(r'Today · Attendance</div><div class="value"[^>]*>([^<]+)<', page)
check("today's card judges the sign-in by the employee's shift (Present)", m and m.group(1) == 'Present', m and m.group(1))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-time-report&start=%s&end=%s' % (DAY, DAY))
check('the Sign In / Out report says On Time', '<strong>On Time</strong>' in page and '<strong>Late Arrival</strong>' not in page)
st, page, _ = emp.req('/app/?ews_view=time')
check("the employee's Sign In page says On Time", 'On Time' in page and 'Late Arrival' not in page)

sign_in_at('10:30')
st, page, _ = adm.req(PROFILE)
m = re.search(r'Today · Attendance</div><div class="value"[^>]*>([^<]+)<', page)
check("...and a sign-in after the shift's grace period is Late", m and m.group(1) == 'Late', m and m.group(1))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-time-report&start=%s&end=%s' % (DAY, DAY))
check('...also in the report', '<strong>Late Arrival</strong>' in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

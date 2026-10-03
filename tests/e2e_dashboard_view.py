"""Behaviour tests for the employee app's Dashboard (?ews_view=dashboard): the managers' workforce
snapshot and the employee's personal dashboard.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_dashboard_view.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

PAGE = '/app/?ews_view=dashboard'
TODAY = today.isoformat()


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_schedule','ews_company_calendar','ews_departments','ews_time_logs'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_schedule_types_config'); update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_employee_moments_enabled',0,false);
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $mk=function($name,$dep,$active=1)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>$active,'attendance_enabled'=>1,'department_id'=>$dep]);return $wpdb->insert_id;};
        $ids=['mona'=>$eid,'omar'=>$mk('Omar Office',$ops),'wendy'=>$mk('Wendy Home',$ops),'tarek'=>$mk('Tarek Trainee',$ops),'vera'=>$mk('Vera Vacation',$ops),'arch'=>$mk('Archie Archived',$ops,0),'sam'=>$mk('Sam Sales',$sales)];
        $today=current_time('Y-m-d');
        $plan=['omar'=>'Office','wendy'=>'WFH','tarek'=>'Training Course','vera'=>'Vacation','arch'=>'Office','sam'=>'Office'];
        foreach($plan as $k=>$st) $wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$today,'status'=>$st]);
        foreach([0=>'WFH',1=>'Office',2=>'Office'] as $i=>$st) $wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>date('Y-m-d',strtotime($today.' +'.$i.' day')),'status'=>$st]);
        echo wp_json_encode($ids);
    """)
    return json.loads(out.splitlines()[-1])


def stat(page, label):
    m = re.search(r'<div class="n">(\d+)</div><div class="l">' + re.escape(label) + '</div>', page)
    return int(m.group(1)) if m else None


def snapshot(page):
    return tuple(stat(page, k) for k in ('Active Employees', 'Office Today', 'WFH Today', 'Leave / Mission'))


def balanced(page):
    """The dashboard markup closes exactly the elements it opens (it sits inside <main>)."""
    m = re.search(r'<main class="ews-main">(.*?)</main>', page, re.S)
    body = re.sub(r'<div[^>]*class="ews-ux-modal.*', '', m.group(1), flags=re.S) if m else ''
    return body.count('<div') == body.count('</div>'), (body.count('<div'), body.count('</div>'))


ids = seed()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- manager snapshot
st, page, _ = adm.req(PAGE)
check('the manager dashboard opens', st == 200 and 'Active Employees' in page, st)
check('snapshot: 6 active, 2 Office, 2 WFH, 2 Leave/Mission (archived left out, Training Course is a mission)', snapshot(page) == (6, 2, 2, 2), snapshot(page))
ok, counts = balanced(page)
check('the dashboard closes every element it opens', ok, counts)
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>current_time('Y-m-d'),'title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);")
st, page, _ = adm.req(PAGE)
check('on a company holiday nobody is counted in the Office or at home, and the holiday is named', snapshot(page)[1:3] == (0, 0) and 'National Day' in page, snapshot(page))

# ---------------------------------------------------------------- employee dashboard
st, page, _ = emp.req(PAGE)
check('the employee dashboard greets by first name', 'Good to see you, Mona.' in page)
check('on a company holiday today shows General Leave, not a working day', re.search(r"TODAY(?:'|&#039;)S SCHEDULE</span><h3>General Leave</h3>", page) is not None and 'No work scheduled' in page,
      re.findall(r"TODAY(?:'|&#039;)S SCHEDULE</span><h3>([^<]*)</h3>", page))
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\"); $wpdb->insert($p.'ews_company_calendar',['event_date'=>'%s','title'=>'Bank Holiday','event_type'=>'general_leave','active'=>1,'created_by'=>1]);" % d(1))
st, page, _ = emp.req(PAGE)
week = re.findall(r'<div><span>([^<]+)</span><b>([^<]+)</b></div>', page.split('ews-my-week-list')[-1].split('ews-dash-inline-link')[0])
check('My Week shows a company holiday as General Leave', len(week) >= 2 and week[0][1] == 'General Leave' and week[1][1] == 'Office', week)
check('today: WFH, a working day', re.search(r"TODAY(?:'|&#039;)S SCHEDULE</span><h3>WFH</h3>", page) is not None and 'Working day' in page)
ok, counts = balanced(page)
check('the employee dashboard closes every element it opens', ok, counts)
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")

# ---------------------------------------------------------------- department manager
uid = q("SELECT wp_user_id FROM {p}ews_employees WHERE id=%d" % ids['mona'])[0]['wp_user_id']
php("$u=new WP_User(%s); $u->add_cap('ews_view_dashboard');" % uid)
st, page, _ = emp.req(PAGE)
check('a department manager sees their department only (5 active, 1 Office)', snapshot(page) == (5, 1, 2, 2), snapshot(page))
php("$u=new WP_User(%s); $u->remove_cap('ews_view_dashboard');" % uid)

php("$id=username_exists('loner')?:wp_create_user('loner','lonerpass','loner@example.com'); (new WP_User($id))->set_role('subscriber');")
st, page, _ = Session('loner', 'lonerpass').req(PAGE)
check('a user without an employee record is told so', 'Employee account not linked' in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

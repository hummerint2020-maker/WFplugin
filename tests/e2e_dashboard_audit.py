"""Behaviour tests for the wp-admin home (Employee Schedule dashboard) and the Audit Log.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_dashboard_audit.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, today, wp, HERE  # noqa: E402

HOME = '/wp-admin/admin.php?page=ews31'
AUDIT = '/wp-admin/admin.php?page=ews31-audit'
DAY = today.isoformat()


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false);
        delete_option('ews_shifts');
        $types=get_option('ews_schedule_types_config'); if(!is_array($types)||!$types){$m=new ReflectionMethod('EWS_Manager_V31_1','default_schedule_types_config');$m->setAccessible(true);$types=$m->invoke(new EWS_Manager_V31_1());}
        $types=array_values(array_filter($types,function($t){return $t['name']!=='Field Visit';}));
        $types[]=['name'=>'Field Visit','requires_sign_in'=>1,'requires_location'=>0,'attendance_rule'=>'attendance','active'=>1,'icon'=>'•','bg_color'=>'#f2f4f7','text_color'=>'#667085','border_color'=>'#e5e7eb'];
        update_option('ews_schedule_types_config',$types,false);
        $mk=function($name,$tracking=1)use($wpdb,$p){$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>strtolower(str_replace(' ','',$name)),'email'=>strtolower(str_replace(' ','',$name)).'@example.com','active'=>1,'attendance_enabled'=>$tracking]);return $wpdb->insert_id;};
        $ids=['on_time'=>$eid,'late'=>$mk('Late Lina'),'untracked'=>$mk('Untracked Uma',0),'field'=>$mk('Field Fadi'),'vacation'=>$mk('Vacation Vera')];
        $wpdb->update($p.'ews_employees',['name'=>'Ontime Omar'],['id'=>$eid]);
        $st=['on_time'=>'Office','late'=>'WFH','untracked'=>'Office','field'=>'Field Visit','vacation'=>'Vacation'];
        foreach($ids as $k=>$id) $wpdb->insert($p.'ews_schedule',['employee_id'=>$id,'work_date'=>current_time('Y-m-d'),'status'=>$st[$k]]);
        $d=current_time('Y-m-d');
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['on_time'],'user_id'=>1,'work_date'=>$d,'event_type'=>'sign_in','event_at'=>$d.' 08:05:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['late'],'user_id'=>1,'work_date'=>$d,'event_type'=>'sign_in','event_at'=>$d.' 09:30:00','scheduled_status'=>'WFH']);
        echo wp_json_encode($ids);
    """)
    return json.loads(out.splitlines()[-1])


def card(page, title):
    m = re.search(r'font-size:32px;font-weight:700;line-height:1.1">(\d+)</div><div[^>]*>%s<' % re.escape(title), page)
    return int(m.group(1)) if m else None


def row_status(page, name):
    m = re.search(r'<td>%s</td>.*?<strong>([^<]+)</strong>' % re.escape(name), page, re.S)
    return m.group(1) if m else None


ctx = seed()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- dashboard
st, page, _ = adm.req(HOME)
check('the dashboard opens', st == 200 and 'Employee Schedule Dashboard' in page, st)
check('counts: 1 on time, 1 late, 1 no show, 3 expected', (card(page, 'Sign In'), card(page, 'Late Arrival'), card(page, 'No Show'), card(page, 'Scheduled Today')) == (1, 1, 1, 3), (card(page, 'Sign In'), card(page, 'Late Arrival'), card(page, 'No Show'), card(page, 'Scheduled Today')))
check('a custom schedule type that requires Sign In is expected', row_status(page, 'Field Fadi') == 'No Show', row_status(page, 'Field Fadi'))
check('an employee without attendance tracking is not a No Show', 'Untracked Uma' not in page)
check('leave days are not expected', 'Vacation Vera' not in page)
check('on time and late are shown per employee', row_status(page, 'Ontime Omar') == 'On Time' and row_status(page, 'Late Lina') == 'Late Arrival')
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>current_time('Y-m-d'),'title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);")
st, page, _ = adm.req(HOME)
check('on a company holiday nobody is a No Show', card(page, 'No Show') == 0 and 'National Day' in page, card(page, 'No Show'))
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")
st, page, _ = emp.req(HOME)
check('an employee without EWS access cannot open it', 'Employee Schedule Dashboard' not in page)
php("$id=username_exists('auditor')?:wp_create_user('auditor','auditorpass','auditor@example.com'); $u=new WP_User($id); $u->set_role('subscriber'); $u->add_cap('ews_view_audit_log');")
st, page, _ = Session('auditor', 'auditorpass').req(HOME)
check('a user who may only read the audit log lands on the Audit Log', '<h1>Audit Log</h1>' in page)

# ---------------------------------------------------------------- audit log
php("""
    $wpdb->query("DELETE FROM {$p}ews_audit_log");
    $wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%(on)d,'target_employee_id'=>%(late)d,'work_date'=>current_time('Y-m-d'),'requester_status'=>'Office','target_status'=>'WFH','status'=>'Pending','requested_by'=>1,'created_at'=>current_time('mysql')]); $sw=$wpdb->insert_id;
    $lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->insert($p.'ews_company_calendar',['event_date'=>'2030-01-01','title'=>'New Year','event_type'=>'general_leave','active'=>1,'created_by'=>1]); $cal=$wpdb->insert_id;
    $rows=[['SCHEDULE_SWAP_REQUESTED','schedule_swap',$sw],['leave_type_update','leave_type',$lt],['general_leave_add','company_calendar',$cal],['employee_update','employee',%(on)d]];
    foreach($rows as $r) $wpdb->insert($p.'ews_audit_log',['user_id'=>1,'action'=>$r[0],'entity'=>$r[1],'entity_id'=>$r[2],'details'=>'x','created_at'=>'2026-09-15 10:00:00']);
    for($i=0;$i<55;$i++) $wpdb->insert($p.'ews_audit_log',['user_id'=>1,'action'=>'bulk_test','entity'=>'settings','entity_id'=>0,'details'=>'row '.$i,'created_at'=>'2026-09-20 10:00:00']);
""" % {'on': ctx['on_time'], 'late': ctx['late']})
st, page, _ = adm.req(AUDIT + '&audit_action=SCHEDULE_SWAP_REQUESTED')
check('filter by action', page.count('<td>SCHEDULE_SWAP_REQUESTED</td>') == 1 and 'bulk_test' not in page.split('<tbody>')[-1])
check('a swap is named by its employees and date', 'Ontime Omar ↔ Late Lina' in page)
st, page, _ = adm.req(AUDIT + '&audit_action=leave_type_update')
check('a leave type is named', 'Annual Leave' in page.split('<tbody>')[-1])
st, page, _ = adm.req(AUDIT + '&audit_action=general_leave_add')
check('a holiday is named by date and title', '2030-01-01 — New Year' in page)
st, page, _ = adm.req(AUDIT + '&audit_from=2026-09-16&audit_to=2026-09-14')
check('a reversed date range is swapped', '4</strong> log(s) found' in page, re.findall(r'<strong>([\d,]+)</strong> log', page))
st, page, _ = adm.req(AUDIT + '&audit_from=2026-13-45')
check('an impossible date is ignored', '59</strong> log(s) found' in page, re.findall(r'<strong>([\d,]+)</strong> log', page))
st, page, _ = adm.req(AUDIT + '&audit_page=2')
check('pagination: page 2 shows the remaining 9', page.split('<tbody>')[-1].count('<tr>') == 9)
check('the action filter lists the actions that exist', 'value="general_leave_add"' in page)
st, page, _ = emp.req(AUDIT)
check('an employee cannot read the audit log', '<h1>Audit Log</h1>' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

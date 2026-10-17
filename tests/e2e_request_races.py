"""Two requests of the same employee sent at the same moment (3.31.91, second review items 6 and 14):
leave and overtime are saved under a per-employee lock and checked again inside the transaction, so the
second one is refused instead of overlapping the first or using the same balance twice.

The test web server answers one request at a time, so "the same moment" is reproduced by calling the
save step with the checks the second request made before the first one was saved: an overlapping
request already there, or a balance already reserved. Then the normal HTTP flow is checked once.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_request_races.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""
    foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_overtime_requests','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=3 WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET active=0,approval_mode='NONE' WHERE workflow_key IN ('vacation','overtime')");
    update_option('ews_working_days',[0,1,2,3,4,5,6],false); update_option('ews_feature_overtime',1,false);
""")
EID = ids()['eid']
TYPE = int(q("SELECT id FROM {p}ews_leave_types WHERE name='Annual Leave'")[0]['id'])


def leave_insert(start, end, days):
    """leave_insert_request() in its own transaction, as leave_request_create() runs it."""
    out = php("wp_set_current_user((int)get_user_by('login','emp1')->ID); $m=new EWS_Manager_V31_1();"
              "$emp=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM {$p}ews_employees WHERE id=%%d\",%d)); $type=$wpdb->get_row(\"SELECT * FROM {$p}ews_leave_types WHERE id=%d\");"
              "$e=new ReflectionMethod($m,'ensure_leave_balance'); $e->setAccessible(true); $bal=$e->invoke($m,%d,%d,%d);"
              "$r=new ReflectionMethod($m,'leave_insert_request'); $r->setAccessible(true);"
              "$wpdb->query('START TRANSACTION'); $x=$r->invoke($m,$emp,$type,'%s','%s',%d,'race',$bal); $wpdb->query(is_int($x)?'COMMIT':'ROLLBACK'); echo wp_json_encode($x);"
              % (EID, TYPE, EID, TYPE, int(start[:4]), start, end, days))
    return json.loads(out.splitlines()[-1])


def balance():
    r = q("SELECT entitlement,used,pending FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d" % (EID, TYPE))
    return {k: float(v) for k, v in r[0].items()} if r else {}


def requests(where="1=1"):
    return q("SELECT id,status,requested_days FROM {p}ews_leave_requests WHERE employee_id=%d AND %s" % (EID, where))


# ---------------------------------------------------------------- leave
A1, A2 = d(10), d(11)
first = leave_insert(A1, A2, 2)
check('leave: the first request is saved and reserves its 2 days', isinstance(first, int) and balance().get('pending') == 2, (first, balance()))
second = leave_insert(A1, A1, 1)
check('leave: a second request for the same days, sent at the same moment, is refused (overlap)', second == 'overlap' and len(requests()) == 1 and balance().get('pending') == 2, (second, requests(), balance()))
third = leave_insert(d(20), d(21), 2)
check('leave: a request that would use days already reserved by the first is refused (balance, 3 days a year)', third == 'balance' and len(requests()) == 1 and balance().get('pending') == 2, (third, requests(), balance()))
fourth = leave_insert(d(20), d(20), 1)
check('leave: the one day left can still be requested', isinstance(fourth, int) and balance().get('pending') == 3, (fourth, balance()))

emp = Session('emp1', 'emp1pass')
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_requests\"); $wpdb->query(\"UPDATE {$p}ews_leave_balances SET pending=0,used=0\");")
n = emp.nonce('ews_vacation_request_create')
_, qs, _ = emp.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=TYPE, start_date=A1, end_date=A2, reason='trip')
check('leave: a normal request in the app still works', 'leave_sent' in qs and len(requests()) == 1, qs)
_, qs, _ = emp.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=TYPE, start_date=A2, end_date=A2, reason='again')
check('...and an overlapping one is refused as before', qs.get('leave_error') == 'overlap', qs)

# ---------------------------------------------------------------- overtime
O = d(5)


def ot_create(start, end):
    out = php("wp_set_current_user((int)get_user_by('login','emp1')->ID); $m=new EWS_Manager_V31_1();"
              "$emp=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM {$p}ews_employees WHERE id=%%d\",%d));"
              "$r=new ReflectionMethod($m,'overtime_create_locked'); $r->setAccessible(true); $x=$r->invoke($m,$emp,'%s','%s','%s','race'); echo wp_json_encode(isset($x['error'])?$x['error']:(int)$x['id']);" % (EID, O, start, end))
    return json.loads(out.splitlines()[-1])


def ots():
    return q("SELECT id,start_time,end_time FROM {p}ews_overtime_requests WHERE employee_id=%d" % EID)


first = ot_create('17:00', '19:00')
check('overtime: the first request is saved', isinstance(first, int) and len(ots()) == 1, (first, ots()))
# The second request checked for an overlap before the first was saved: the save step checks again.
second = php("wp_set_current_user((int)get_user_by('login','emp1')->ID); $m=new EWS_Manager_V31_1();"
             "$emp=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM {$p}ews_employees WHERE id=%%d\",%d)); $wpdb->query('START TRANSACTION');"
             "$wpdb->insert($p.'ews_overtime_requests',['employee_id'=>%d,'overtime_date'=>'%s','start_time'=>'18:00','end_time'=>'20:00','requested_minutes'=>120,'reason'=>'race','status'=>'Pending','requested_by'=>0,'requested_at'=>current_time('mysql')]);"
             "$id=(int)$wpdb->insert_id; $o=new ReflectionMethod($m,'overtime_overlap'); $o->setAccessible(true); $x=$o->invoke($m,%d,'%s','18:00','20:00',$id);"
             "$wpdb->query('ROLLBACK'); echo $x?'overlap':'saved';" % (EID, EID, O, EID, O)).strip().splitlines()[-1]
check('overtime: a second request saved at the same moment finds the first after its own insert and is refused', second == 'overlap' and len(ots()) == 1, (second, ots()))
check('overtime: an overlapping request made after it is refused', ot_create('18:30', '19:30') == 'overlap' and len(ots()) == 1)
check('overtime: one that does not overlap is saved', isinstance(ot_create('19:00', '20:00'), int) and len(ots()) == 2)
n = emp.nonce('ews_overtime_request_create', 'overtime')
_, qs, _ = emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(6), start_time='17:00', end_time='18:00', reason='stock count')
check('overtime: a normal request in the app still works', 'overtime_sent' in qs, qs)
_, qs, _ = emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(6), start_time='17:30', end_time='18:30', reason='again')
check('...and an overlapping one is refused as before', qs.get('overtime_error') == 'overlap', qs)

php("""foreach(['ews_leave_requests','ews_leave_balances','ews_overtime_requests'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_leave_types SET annual_entitlement=21 WHERE name='Annual Leave'");""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

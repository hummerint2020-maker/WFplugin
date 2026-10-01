"""Behaviour tests for Overtime requests and Early Leave requests (HTTP, real site).

Pins validation, overlap rules, who may decide, double decisions, the admin Requests page,
and - for overtime - the same flows with the one-level approval workflow switched on.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_overtime.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, today, wp, HERE  # noqa: E402


def seed(approval=False):
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_overtime_requests','ews_early_leave_requests','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_feature_overtime',1,false);
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_early_leave_max_minutes',120,false); update_option('ews_early_leave_monthly_minutes',240,false); update_option('ews_early_leave_office_only',1,false);
        $wf=$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='overtime'");
        $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".(int)$wf);
        $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'%s','active'=>%d],['id'=>$wf]);
        if(%d) $wpdb->insert($p.'ews_approval_workflow_steps',['workflow_id'=>$wf,'step_order'=>1,'step_type'=>'APPROVAL','resolver_type'=>'SPECIFIC_USER','resolver_value'=>(string)get_user_by('login','admin')->ID,'required'=>1,'active'=>1]);
    """ % ('LEVEL_1' if approval else 'NONE', 1 if approval else 0, 1 if approval else 0))


def ot(oid):
    rows = q("SELECT status,requested_minutes FROM {p}ews_overtime_requests WHERE id=%d" % oid)
    return rows[0] if rows else None


def el(eid):
    rows = q("SELECT status,leave_minutes FROM {p}ews_early_leave_requests WHERE id=%d" % eid)
    return rows[0] if rows else None


def last(table):
    return int(q("SELECT MAX(id) id FROM {p}%s" % table)[0]['id'] or 0)


def set_schedule(date, status):
    php("$wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (ids()['eid'], date, ids()['eid'], date, status))


def run_overtime(approval):
    tag = '[overtime approval] ' if approval else '[overtime] '
    seed(approval)
    emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
    n_create = emp.nonce('ews_overtime_request_create', view='overtime')
    check(tag + 'overtime form is shown', bool(n_create))

    def create(date, start, end, reason='deadline'):
        return emp.post('ews_overtime_request_create', _wpnonce=n_create, overtime_date=date, start_time=start, end_time=end, reason=reason)

    _, qs, _ = create(d(-1), '18:00', '20:00')
    check(tag + 'past date is rejected', qs.get('overtime_error') == 'date', qs)
    _, qs, _ = create(d(1), '6pm', '20:00')
    check(tag + 'bad time format is rejected', qs.get('overtime_error') == 'time', qs)
    _, qs, _ = create(d(1), '20:00', '18:00')
    check(tag + 'end before start is rejected', qs.get('overtime_error') == 'time', qs)
    _, qs, _ = create(d(1), '18:00', '20:00', reason='')
    check(tag + 'missing reason is rejected', qs.get('overtime_error') == 'reason', qs)
    _, qs, _ = create(today.isoformat(), '18:00', '20:30')
    o1 = last('ews_overtime_requests')
    check(tag + 'today is allowed; 2.5 h submitted as Pending', qs.get('overtime_sent') == '2.5' and ot(o1) == {'status': 'Pending', 'requested_minutes': '150'}, (qs, ot(o1)))
    _, qs, _ = create(today.isoformat(), '19:00', '21:00')
    check(tag + 'overlapping overtime is rejected', qs.get('overtime_error') == 'overlap', qs)
    _, qs, _ = create(today.isoformat(), '20:30', '21:00')
    o2 = last('ews_overtime_requests')
    check(tag + 'back-to-back overtime is allowed', 'overtime_sent' in qs and o2 != o1, qs)

    n_resp_emp = emp.nonce('ews_overtime_request_respond', view='overtime') or adm.nonce('ews_overtime_request_respond', view='overtime')
    _, qs, _ = emp.post('ews_overtime_request_respond', _wpnonce=n_resp_emp, request_id=o1, decision='approve')
    # The employee has no decision form (no nonce of their own); the request must stay untouched.
    check(tag + 'employee cannot approve overtime', ot(o1)['status'] == 'Pending', (qs, ot(o1)))

    n_resp = adm.nonce('ews_overtime_request_respond', view='overtime')
    check(tag + 'manager sees the approval form', bool(n_resp))
    _, qs, _ = adm.post('ews_overtime_request_respond', _wpnonce=n_resp, request_id=o1, decision='approve')
    check(tag + 'manager approves', ot(o1)['status'] == 'Approved' and qs.get('overtime_done') == '1', (qs, ot(o1)))
    _, qs, _ = adm.post('ews_overtime_request_respond', _wpnonce=n_resp, request_id=o1, decision='reject')
    check(tag + 'deciding twice changes nothing', ot(o1)['status'] == 'Approved', ot(o1))
    _, qs, _ = adm.post('ews_overtime_request_respond', _wpnonce=n_resp, request_id=o2, decision='reject')
    expected_reject = {'overtime_rejected': '1'}
    check(tag + 'manager rejects', ot(o2)['status'] == 'Rejected' and all(qs.get(k) == v for k, v in expected_reject.items()), (qs, ot(o2)))

    _, qs, _ = create(d(2), '18:00', '19:00')
    o3 = last('ews_overtime_requests')
    n = adm.admin_request_nonce('overtime', o3)
    adm.post('ews31_requests_decision', _wpnonce=n, request_type='overtime', request_id=o3, decision='approve')
    check(tag + 'admin Requests page approves overtime', ot(o3)['status'] == 'Approved', ot(o3))


def run_early_leave():
    tag = '[early leave] '
    seed(False)
    emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
    n_create = emp.nonce('ews_early_leave_create')
    check(tag + 'early leave form is shown', bool(n_create))

    def create(date, minutes):
        return emp.post('ews_early_leave_create', _wpnonce=n_create, work_date=date, leave_minutes=minutes, reason='doctor')

    _, qs, _ = create(today.isoformat(), 60)
    check(tag + 'today is rejected (future dates only)', qs.get('early_error') == 'future_date', qs)
    php("update_option('ews_working_days',[0,1,2,3,4],false);")
    fri = today + datetime.timedelta(days=1)
    while fri.isoweekday() != 5:
        fri += datetime.timedelta(days=1)
    _, qs, _ = create(fri.isoformat(), 60)
    check(tag + 'non-working day is rejected', qs.get('early_error') == 'working_day', qs)
    php("update_option('ews_working_days',[0,1,2,3,4,5,6],false);")
    set_schedule(d(3), 'WFH')
    _, qs, _ = create(d(3), 60)
    check(tag + 'WFH day is rejected when Office-only is on', qs.get('early_error') == 'office_only', qs)
    set_schedule(d(3), 'Office')
    _, qs, _ = create(d(3), 121)
    check(tag + 'longer than the per-request maximum is rejected', qs.get('early_error') == 'max_duration', qs)
    _, qs, _ = create(d(3), 120)
    e1 = last('ews_early_leave_requests')
    check(tag + 'valid request is submitted as Pending', qs.get('leave_sent') == '1' and el(e1) == {'status': 'Pending', 'leave_minutes': '120'}, (qs, el(e1)))
    month_day = d(3)
    set_schedule(d(4), 'Office')
    same_month = d(4)[:7] == month_day[:7]
    _, qs, _ = create(d(4), 120)
    e2 = last('ews_early_leave_requests')
    if same_month:
        _, qs, _ = create(d(4), 1)
        check(tag + 'monthly allowance is enforced (240 min used)', qs.get('early_error') == 'monthly_limit', qs)

    n_resp = adm.nonce('ews_early_leave_respond')
    check(tag + 'manager sees the decision form', bool(n_resp))
    st, qs, body = emp.post('ews_early_leave_respond', _wpnonce=n_resp, request_id=e1, decision='approve')
    check(tag + 'employee cannot decide', el(e1)['status'] == 'Pending', (st, body[:120]))
    set_schedule(d(3), 'WFH')
    _, qs, _ = adm.post('ews_early_leave_respond', _wpnonce=n_resp, request_id=e1, decision='approve')
    check(tag + 'approval is refused once the day is no longer Office', el(e1)['status'] == 'Pending' and qs.get('early_error') == 'approval_conflict', (qs, el(e1)))
    set_schedule(d(3), 'Office')
    _, qs, _ = adm.post('ews_early_leave_respond', _wpnonce=n_resp, request_id=e1, decision='approve')
    check(tag + 'manager approves', el(e1)['status'] == 'Approved' and qs.get('leave_done') == '1', (qs, el(e1)))
    check(tag + '...the schedule stays Office (early leave is an exception, not a schedule type)', q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ids()['eid'], d(3)))[0]['status'] == 'Office')
    n = adm.admin_request_nonce('early_leave', e2)
    adm.post('ews31_requests_decision', _wpnonce=n, request_type='early_leave', request_id=e2, decision='reject')
    check(tag + 'admin Requests page rejects early leave', el(e2)['status'] == 'Rejected', el(e2))


run_overtime(False)
run_overtime(True)
run_early_leave()
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

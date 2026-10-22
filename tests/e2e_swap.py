"""Behaviour tests for Schedule Swap requests (HTTP, real site).

Pins request validation, department scope, the peer accept / reject / cancel flows, the
"schedule changed since the request" guard, and the admin Requests page decisions.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_swap.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import atexit, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, break_writes, check, ids, php, q, results, today, wp, HERE  # noqa: E402

DAY = today.isoformat()  # always inside the week the schedule page shows


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $wpdb->query("DELETE FROM {$p}ews_schedule_swaps");
        $wpdb->query("UPDATE {$p}ews_approval_workflows SET active=0 WHERE workflow_key='shift_swap'");   // the colleague alone decides until a test sets levels
        $wpdb->query("DELETE FROM {$p}ews_departments");
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $d1=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $d2=$wpdb->insert_id;
        $u2=username_exists('emp2')?:wp_create_user('emp2','emp2pass','emp2@example.com');
        $u3=username_exists('emp3')?:wp_create_user('emp3','emp3pass','emp3@example.com');
        $e1=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        $wpdb->update($p.'ews_employees',['department_id'=>$d1],['id'=>$e1]);
        $wpdb->insert($p.'ews_employees',['name'=>'Emp Two','domain_name'=>'emp2','email'=>'emp2@example.com','wp_user_id'=>$u2,'active'=>1,'attendance_enabled'=>1,'department_id'=>$d1]); $e2=$wpdb->insert_id;
        $wpdb->insert($p.'ews_employees',['name'=>'Emp Three','domain_name'=>'emp3','email'=>'emp3@example.com','wp_user_id'=>$u3,'active'=>1,'attendance_enabled'=>1,'department_id'=>$d2]); $e3=$wpdb->insert_id;
        echo wp_json_encode(['e2'=>$e2,'e3'=>$e3]);
    """)
    return {k: int(v) for k, v in __import__('json').loads(out.splitlines()[-1]).items()}


def set_schedule(eid, status, date=DAY):
    php("$wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (eid, date, eid, date, status))


def sched(eid, date=DAY):
    rows = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (eid, date))
    return rows[0]['status'] if rows else None


def swap(sid):
    rows = q("SELECT status,requester_status,target_status FROM {p}ews_schedule_swaps WHERE id=%d" % sid)
    return rows[0] if rows else None


def last():
    return int(q("SELECT MAX(id) id FROM {p}ews_schedule_swaps")[0]['id'] or 0)


def swap_form_nonce(sess, action, sid):
    st, page, _ = sess.req('/app/?ews_view=schedule')
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form and 'name="swap_id" value="%d"' % sid in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    return None


ex = seed()
e1, e2, e3 = ids()['eid'], ex['e2'], ex['e3']
emp1, emp2, adm = Session('emp1', 'emp1pass'), Session('emp2', 'emp2pass'), Session('admin', 'admin')
set_schedule(e1, 'Office')
set_schedule(e2, 'WFH')
set_schedule(e3, 'WFH')

n_create = emp1.nonce('ews_swap_create', view='schedule')
check('swap form is shown on the schedule page', bool(n_create))


def create(target, date=DAY):
    return emp1.post('ews_swap_create', _wpnonce=n_create, target_employee_id=target, work_date=date)


_, qs, _ = create(e1)
check('swapping with yourself is rejected', qs.get('swap_error') == 'invalid_request', qs)
_, qs, _ = create(e2, 'tomorrow')
check('bad date is rejected', qs.get('swap_error') == 'invalid_request', qs)
_, qs, _ = create(e3)
check('colleague from another department is rejected', qs.get('swap_error') == 'employee', qs)
set_schedule(e2, 'Office')
_, qs, _ = create(e2)
check('same status on both sides is not swappable', qs.get('swap_error') == 'not_swappable', qs)
set_schedule(e2, 'Vacation')
_, qs, _ = create(e2)
check('a non Office/WFH day is not swappable', qs.get('swap_error') == 'not_swappable', qs)
set_schedule(e2, 'WFH')
_, qs, _ = create(e2)
s1 = last()
check('valid swap is submitted as Pending', qs.get('swap_sent') == '1' and swap(s1) == {'status': 'Pending', 'requester_status': 'Office', 'target_status': 'WFH'}, (qs, swap(s1)))
check('...and redirects back to the schedule page', qs.get('ews_view') == 'schedule', qs)
_, qs, _ = create(e2)
check('a second pending swap for the same pair and day is rejected', qs.get('swap_error') == 'pending', qs)
check('the target is notified', int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE user_id=(SELECT wp_user_id FROM {p}ews_employees WHERE id=%d) AND entity='swap' AND entity_id=%d" % (e2, s1))[0]['c']) >= 1)

check('the requester has no accept form', swap_form_nonce(emp1, 'ews_swap_respond', s1) is None)
n_acc = swap_form_nonce(emp2, 'ews_swap_respond', s1)
check('the target sees the accept form', bool(n_acc))
set_schedule(e2, 'Office')
_, qs, _ = emp2.post('ews_swap_respond', _wpnonce=n_acc, swap_id=s1, decision='accept')
check('accepting after the schedule changed is refused', qs.get('swap_error') == 'changed' and swap(s1)['status'] == 'Pending', (qs, swap(s1)))
set_schedule(e2, 'WFH')
_, qs, _ = emp2.post('ews_swap_respond', _wpnonce=n_acc, swap_id=s1, decision='accept')
check('target accepts', qs.get('swap_done') == 'accepted' and swap(s1)['status'] == 'Accepted', (qs, swap(s1)))
check('...both schedules are swapped', sched(e1) == 'WFH' and sched(e2) == 'Office', (sched(e1), sched(e2)))
_, qs, _ = emp2.post('ews_swap_respond', _wpnonce=n_acc, swap_id=s1, decision='reject')
check('deciding twice changes nothing', qs.get('swap_error') == 'not_found' and swap(s1)['status'] == 'Accepted', (qs, swap(s1)))

# Reject
_, qs, _ = create(e2)
s2 = last()
check('a new swap can be requested once the previous one is closed', qs.get('swap_sent') == '1' and s2 != s1, qs)
n_rej = swap_form_nonce(emp2, 'ews_swap_respond', s2)
_, qs, _ = emp2.post('ews_swap_respond', _wpnonce=n_rej, swap_id=s2, decision='reject')
check('target rejects', qs.get('swap_done') == 'rejected' and swap(s2)['status'] == 'Rejected', (qs, swap(s2)))
check('...schedules are unchanged', sched(e1) == 'WFH' and sched(e2) == 'Office')

# Cancel
_, qs, _ = create(e2)
s3 = last()
check('the target has no cancel form', swap_form_nonce(emp2, 'ews_swap_cancel', s3) is None)
n_can = swap_form_nonce(emp1, 'ews_swap_cancel', s3)
check('the requester sees the cancel form', bool(n_can))
_, qs, _ = emp1.post('ews_swap_cancel', _wpnonce=n_can, swap_id=s3)
check('requester cancels', qs.get('swap_done') == 'cancelled' and swap(s3)['status'] == 'Cancelled', (qs, swap(s3)))

# Admin Requests page
_, qs, _ = create(e2)
s4 = last()
n = adm.admin_request_nonce('shift_swap', s4)
check('admin Requests page lists the pending swap', bool(n))
adm.post('ews31_requests_decision', _wpnonce=n, request_type='shift_swap', request_id=s4, decision='approve')
check('admin Requests page approves the swap', swap(s4)['status'] == 'Accepted' and sched(e1) == 'Office' and sched(e2) == 'WFH', (swap(s4), sched(e1), sched(e2)))
_, qs, _ = create(e2)
s5 = last()
set_schedule(e2, 'Office')
n = adm.admin_request_nonce('shift_swap', s5)
_, qs, _ = adm.post('ews31_requests_decision', _wpnonce=n, request_type='shift_swap', request_id=s5, decision='approve')
check('admin approval is refused once the schedule changed', swap(s5)['status'] == 'Pending' and sched(e1) == 'Office' and sched(e2) == 'Office', (qs, swap(s5)))
set_schedule(e2, 'WFH')
adm.post('ews31_requests_decision', _wpnonce=n, request_type='shift_swap', request_id=s5, decision='reject')
check('admin Requests page rejects the swap', swap(s5)['status'] == 'Rejected' and sched(e1) == 'Office', (swap(s5), sched(e1)))

# 3.31.89: the Shift Swap workflow can add approvers after the colleague (here Level 1 = Emp Three).
def configure_swap(mode, approver=0, approver2=0):
    if mode == 'PEER':
        steps = "[1=>['resolver_type'=>'TARGET_EMPLOYEE','resolver_value'=>0]]"
    else:
        steps = "[1=>['resolver_type'=>'SPECIFIC_EMPLOYEE','resolver_value'=>%d]%s]" % (approver, ",2=>['resolver_type'=>'SPECIFIC_EMPLOYEE','resolver_value'=>%d]" % approver2 if approver2 else '')
    out = php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod('EWS_Manager_V31_1','approval_configure_workflow'); $m->setAccessible(true);
    $r=$m->invoke($o,'shift_swap','%s',%s,\\WorkforceOne\\Approvals\\Workflows::IN_USE['shift_swap'][1]); echo is_wp_error($r)?$r->get_error_message():'ok';""" % (mode, steps))
    assert out.strip().endswith('ok'), out


configure_swap('LEVEL_1', e3)
emp3 = Session('emp3', 'emp3pass')
set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
_, qs, _ = create(e2)
s6 = last()
_, qs, _ = emp2.post('ews_swap_respond', _wpnonce=swap_form_nonce(emp2, 'ews_swap_respond', s6), swap_id=s6, decision='accept')
check('with an approver: the colleague accepts, the swap waits for approval, the days are not swapped yet', qs.get('swap_done') == 'awaiting' and swap(s6)['status'] == 'Awaiting' and sched(e1) == 'Office' and sched(e2) == 'WFH', (qs, swap(s6)))
check('...the approver is notified', int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE user_id=(SELECT wp_user_id FROM {p}ews_employees WHERE id=%d) AND entity='swap' AND entity_id=%d" % (e3, s6))[0]['c']) >= 1)
_, qs, _ = create(e2)
check('...and no second request for the same day while it waits', qs.get('swap_error') == 'pending', qs)
check('only the approver sees "To approve" with the decision form', swap_form_nonce(emp3, 'ews_swap_decide', s6) is not None and swap_form_nonce(emp1, 'ews_swap_decide', s6) is None and swap_form_nonce(emp2, 'ews_swap_decide', s6) is None)
st, page, _ = emp1.req('/app/?ews_view=schedule')
check('the requester sees it in its own "Waiting for approval" group, not under Decided', 'data-swap-awaiting' in page and re.search(r'data-swap-awaiting.*?Waiting for approval', page, re.S) is not None)
_, qs, _ = emp1.post('ews_swap_decide', _wpnonce=emp1.nonce('ews_swap_create', view='schedule'), swap_id=s6, decision='approve')
check('someone else cannot approve it', swap(s6)['status'] == 'Awaiting', (qs, swap(s6)))
_, qs, _ = emp3.post('ews_swap_decide', _wpnonce=swap_form_nonce(emp3, 'ews_swap_decide', s6), swap_id=s6, decision='approve')
check('the approver approves: the days are swapped', qs.get('swap_done') == 'approved' and swap(s6)['status'] == 'Accepted' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(s6), sched(e1), sched(e2)))

_, qs, _ = create(e2)
s7 = last()
emp2.post('ews_swap_respond', _wpnonce=swap_form_nonce(emp2, 'ews_swap_respond', s7), swap_id=s7, decision='accept')
_, qs, _ = emp3.post('ews_swap_decide', _wpnonce=swap_form_nonce(emp3, 'ews_swap_decide', s7), swap_id=s7, decision='reject')
check('the approver rejects: closed, the days unchanged', qs.get('swap_done') == 'rejected' and swap(s7)['status'] == 'Rejected' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(s7)))

_, qs, _ = create(e2)
s8 = last()
emp2.post('ews_swap_respond', _wpnonce=swap_form_nonce(emp2, 'ews_swap_respond', s8), swap_id=s8, decision='accept')
n = adm.admin_request_nonce('shift_swap', s8)
check('the Requests Hub lists a swap waiting for approval', bool(n))
adm.post('ews31_requests_decision', _wpnonce=n, request_type='shift_swap', request_id=s8, decision='approve')
check('...an administrator approves it there: swapped, and the approval request is closed', swap(s8)['status'] == 'Accepted' and sched(e1) == 'Office' and sched(e2) == 'WFH'
      and q("SELECT status FROM {p}ews_approval_requests WHERE entity_type='schedule_swap' AND entity_id=%d" % s8)[0]['status'] == 'APPROVED', (swap(s8), sched(e1)))

configure_swap('PEER')
_, qs, _ = create(e2)
s9 = last()
_, qs, _ = emp2.post('ews_swap_respond', _wpnonce=swap_form_nonce(emp2, 'ews_swap_respond', s9), swap_id=s9, decision='accept')
check('back to "Colleague only": accepting swaps at once, as before', qs.get('swap_done') == 'accepted' and swap(s9)['status'] == 'Accepted', (qs, swap(s9)))

# 3.31.117: the days are written only if they are still as read; a schedule saved at the same moment is never overwritten.
php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); copy('%s',$d.'/ews-e2e-swap-mu.php');" % os.path.join(HERE, 'e2e_swap_mu.php'))
atexit.register(lambda: php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-swap-mu.php'); @unlink(WPMU_PLUGIN_DIR.'/ews-e2e-swap.txt');"))


def stale(eid=0, status=''):
    php("file_put_contents(WPMU_PLUGIN_DIR.'/ews-e2e-swap.txt','%s');" % ('stale %d %s %s' % (eid, DAY, status) if eid else ''))


def accept(sid):
    return emp2.post('ews_swap_respond', _wpnonce=swap_form_nonce(emp2, 'ews_swap_respond', sid), swap_id=sid, decision='accept')[1]


notes = lambda: int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE title='Schedule Swap Completed'")[0]['c'])
set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
for who, mine, theirs in ((e1, 'Office', 'WFH'), (e2, 'WFH', 'Office')):
    _, qs, _ = create(e2)
    sid = last()
    set_schedule(who, 'Vacation')   # a leave approved for that day a moment ago
    stale(who, mine)                # the swap read the day just before
    before = notes()
    qs = accept(sid)
    stale()
    check('a leave saved at the same moment on the %s\'s day: the swap is refused (changed) and the leave stays' % ('requester' if who == e1 else 'colleague'),
          qs.get('swap_error') == 'changed' and swap(sid)['status'] == 'Pending' and sched(who) == 'Vacation' and sched(e2 if who == e1 else e1) == ('WFH' if who == e1 else 'Office') and notes() == before,
          (qs, swap(sid), sched(e1), sched(e2)))
    php("$wpdb->update($p.'ews_schedule_swaps',['status'=>'Cancelled'],['id'=>%d]);" % sid)
    set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
for table in ('ews_schedule', 'ews_schedule_swaps'):
    _, qs, _ = create(e2)
    sid = last()
    n = swap_form_nonce(emp2, 'ews_swap_respond', sid)
    break_writes(table)
    qs = emp2.post('ews_swap_respond', _wpnonce=n, swap_id=sid, decision='accept')[1]
    break_writes('')
    check('a database error on %s while accepting: nothing changes (the swap still waits, both days as they were)' % table,
          qs.get('swap_error') == 'save' and swap(sid)['status'] == 'Pending' and sched(e1) == 'Office' and sched(e2) == 'WFH', (qs, swap(sid), sched(e1), sched(e2)))
    php("$wpdb->update($p.'ews_schedule_swaps',['status'=>'Cancelled'],['id'=>%d]);" % sid)
_, qs, _ = create(e2)
sid = last()
qs = accept(sid)
check('...and with nothing in the way the swap goes through as before', qs.get('swap_done') == 'accepted' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, sched(e1), sched(e2)))

# 3.31.120 (owner decision): with two levels the administrator decides the current level only, as for leave and overtime.
e4 = int(php("""$u=username_exists('emp4')?:wp_create_user('emp4','emp4pass','emp4@example.com');
    $e=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d",$u));
    if(!$e){$wpdb->insert($p.'ews_employees',['name'=>'Emp Four','domain_name'=>'emp4','email'=>'emp4@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>1]);$e=$wpdb->insert_id;}
    echo $e;""").splitlines()[-1])
emp4 = Session('emp4', 'emp4pass')
configure_swap('LEVEL_2', e3, e4)


def engine(sid):
    ar = q("SELECT id,status FROM {p}ews_approval_requests WHERE entity_type='schedule_swap' AND entity_id=%d" % sid)
    if not ar:
        return None, []
    return ar[0]['status'], [(int(r['step_order']), r['status']) for r in q("SELECT step_order,status FROM {p}ews_approval_steps WHERE approval_request_id=%s ORDER BY step_order" % ar[0]['id'])]


def notified(eid, sid):
    return int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE user_id=(SELECT wp_user_id FROM {p}ews_employees WHERE id=%d) AND entity='swap' AND entity_id=%d" % (eid, sid))[0]['c'])


def audits(sid):
    return int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='SCHEDULE_SWAP_ACCEPTED' AND entity_id=%d" % sid)[0]['c'])


def awaiting():
    set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
    create(e2)
    sid = last()
    qs = accept(sid)
    assert qs.get('swap_done') == 'awaiting' and swap(sid)['status'] == 'Awaiting', (qs, swap(sid))
    return sid


def admin(sid, decision):
    return adm.post('ews31_requests_decision', _wpnonce=adm.admin_request_nonce('shift_swap', sid), request_type='shift_swap', request_id=sid, decision=decision)[1]


a1 = awaiting()
old_form = swap_form_nonce(emp3, 'ews_swap_decide', a1)
before_notes, before_audits = notes(), audits(a1)
qs = admin(a1, 'approve')
check('two levels, the administrator approves level 1: moved to level 2, the days are not exchanged yet',
      qs.get('request_notice') == 'advanced' and swap(a1)['status'] == 'Awaiting' and sched(e1) == 'Office' and sched(e2) == 'WFH'
      and engine(a1) == ('WAITING_FOR_LEVEL_2', [(1, 'APPROVED'), (2, 'PENDING')]), (qs, swap(a1), sched(e1), sched(e2), engine(a1)))
check('...the level 2 approver is told; nobody is told it is completed and no "accepted" audit row', notified(e4, a1) >= 1 and notes() == before_notes and audits(a1) == before_audits, (notified(e4, a1), notes(), audits(a1)))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-requests&request_notice=advanced')
check('...the Requests page shows the moved-on notice and the swap at level 2', 'moved to the next level' in page and re.search(r'Approval #\d+ · Level 2', page) is not None)
_, qs, _ = emp3.post('ews_swap_decide', _wpnonce=old_form, swap_id=a1, decision='reject')
check('...the level 1 approver\'s form from before is refused', qs.get('swap_error') == 'access' and swap(a1)['status'] == 'Awaiting', (qs, swap(a1)))
_, qs, _ = emp4.post('ews_swap_decide', _wpnonce=swap_form_nonce(emp4, 'ews_swap_decide', a1), swap_id=a1, decision='approve')
check('...the level 2 approver approves in the app: the days are exchanged', qs.get('swap_done') == 'approved' and swap(a1)['status'] == 'Accepted' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(a1)))

a2 = awaiting()
admin(a2, 'approve')
before_notes = notes()
qs = admin(a2, 'approve')
check('the administrator approves both levels: exchanged, the approval closed, both told',
      qs.get('request_notice') == 'approved' and swap(a2)['status'] == 'Accepted' and sched(e1) == 'WFH' and sched(e2) == 'Office'
      and engine(a2) == ('APPROVED', [(1, 'APPROVED'), (2, 'APPROVED')]) and notes() == before_notes + 2, (qs, swap(a2), engine(a2), notes() - before_notes))

a3 = awaiting()
qs = admin(a3, 'reject')
check('the administrator rejects at level 1: closed with its approval, the days unchanged',
      qs.get('request_notice') == 'rejected' and swap(a3)['status'] == 'Rejected' and engine(a3)[0] == 'REJECTED' and sched(e1) == 'Office' and sched(e2) == 'WFH', (qs, swap(a3), engine(a3)))
a4 = awaiting()
admin(a4, 'approve')
qs = admin(a4, 'reject')
check('...and at level 2', qs.get('request_notice') == 'rejected' and swap(a4)['status'] == 'Rejected' and engine(a4)[0] == 'REJECTED' and sched(e1) == 'Office', (qs, swap(a4), engine(a4)))

a5 = awaiting()
admin(a5, 'approve')
set_schedule(e2, 'Vacation')   # a leave approved for that day a moment ago
stale(e2, 'WFH')                # the swap read the day just before
before_notes = notes()
qs = admin(a5, 'approve')
stale()
check('the last level with a schedule changed since the request: nothing saved, the level stays open, the leave stays',
      qs.get('request_notice') == 'changed' and swap(a5)['status'] == 'Awaiting' and engine(a5) == ('WAITING_FOR_LEVEL_2', [(1, 'APPROVED'), (2, 'PENDING')])
      and sched(e2) == 'Vacation' and sched(e1) == 'Office' and notes() == before_notes, (qs, swap(a5), engine(a5), sched(e1), sched(e2)))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-requests&request_notice=changed')
check('...the Requests page says the schedule changed, as an error', 'changed since the request' in page and 'notice-error' in page)
qs = admin(a5, 'reject')
check('...and it can then be rejected cleanly', qs.get('request_notice') == 'rejected' and swap(a5)['status'] == 'Rejected' and engine(a5)[0] == 'REJECTED' and sched(e2) == 'Vacation', (qs, swap(a5), engine(a5)))

for table, verb, decision, level2 in (('ews_schedule', '', 'approve', True), ('ews_approval_requests', '', 'approve', False), ('ews_schedule_swaps', '', 'reject', True)):
    sid = awaiting()
    if level2:
        admin(sid, 'approve')
    before = engine(sid)
    n = adm.admin_request_nonce('shift_swap', sid)
    break_writes(table, verb)
    qs = adm.post('ews31_requests_decision', _wpnonce=n, request_type='shift_swap', request_id=sid, decision=decision)[1]
    break_writes('')
    check('a database error on %s while the administrator decides level %d: nothing changes' % (table, 2 if level2 else 1),
          qs.get('request_notice') == 'error' and swap(sid)['status'] == 'Awaiting' and engine(sid) == before and sched(e1) == 'Office' and sched(e2) == 'WFH',
          (qs, swap(sid), engine(sid), before, sched(e1), sched(e2)))
    admin(sid, 'reject')

a6 = awaiting()
php("$wpdb->update($p.'ews_approval_requests',['status'=>'APPROVED'],['entity_type'=>'schedule_swap','entity_id'=>%d]);" % a6)
qs = admin(a6, 'approve')
check('an approval already decided while the swap still waits (an earlier failed exchange): the administrator\'s approval exchanges the days',
      qs.get('request_notice') == 'approved' and swap(a6)['status'] == 'Accepted' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(a6)))

set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
create(e2)
a7 = last()
qs = admin(a7, 'approve')
check('a swap the colleague has not answered yet: the administrator decides it directly, with no approval request',
      qs.get('request_notice') == 'approved' and swap(a7)['status'] == 'Accepted' and engine(a7) == (None, []) and sched(e1) == 'WFH', (qs, swap(a7), engine(a7)))
configure_swap('PEER')

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

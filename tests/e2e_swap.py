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
check('colleague from another department is rejected', qs.get('swap_error') == 'department', qs)   # 3.31.156: its own message
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
check('admin Requests page approves the swap', swap(s4)['status'] == 'Approved' and sched(e1) == 'Office' and sched(e2) == 'WFH', (swap(s4), sched(e1), sched(e2)))
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
check('the approver approves: the days are swapped', qs.get('swap_done') == 'approved' and swap(s6)['status'] == 'Approved' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(s6), sched(e1), sched(e2)))

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
check('...an administrator approves it there: swapped, and the approval request is closed', swap(s8)['status'] == 'Approved' and sched(e1) == 'Office' and sched(e2) == 'WFH'
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
check('...the level 2 approver approves in the app: the days are exchanged', qs.get('swap_done') == 'approved' and swap(a1)['status'] == 'Approved' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(a1)))

a2 = awaiting()
admin(a2, 'approve')
before_notes = notes()
qs = admin(a2, 'approve')
check('the administrator approves both levels: exchanged, the approval closed, both told',
      qs.get('request_notice') == 'approved' and swap(a2)['status'] == 'Approved' and sched(e1) == 'WFH' and sched(e2) == 'Office'
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
check('...the Requests page says the schedule changed, as an error', re.search(r'notice-error[^>]*><p>Nothing was changed: a schedule day of this swap changed since the request', page) is not None)
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
      qs.get('request_notice') == 'approved' and swap(a6)['status'] == 'Approved' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(a6)))

a8 = awaiting()
php("$wpdb->update($p.'ews_approval_requests',['status'=>'REJECTED'],['entity_type'=>'schedule_swap','entity_id'=>%d]);" % a8)
qs = admin(a8, 'approve')
check('an approval rejected while the swap still waits: the administrator may still approve it (owner decision)',
      qs.get('request_notice') == 'approved' and swap(a8)['status'] == 'Approved' and sched(e1) == 'WFH' and sched(e2) == 'Office', (qs, swap(a8)))

configure_swap('LEVEL_1', e3)
a9 = awaiting()
set_schedule(e1, 'Vacation')
stale(e1, 'Office')
qs = admin(a9, 'approve')
stale()
check('one level, the schedule changed since the request: nothing saved, the level stays open (before, the approval was kept)',
      qs.get('request_notice') == 'changed' and swap(a9)['status'] == 'Awaiting' and engine(a9) == ('WAITING_FOR_LEVEL_1', [(1, 'PENDING')]) and sched(e1) == 'Vacation' and sched(e2) == 'WFH',
      (qs, swap(a9), engine(a9), sched(e1), sched(e2)))
admin(a9, 'reject')
configure_swap('LEVEL_2', e3, e4)

set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
create(e2)
a7 = last()
qs = admin(a7, 'approve')
check('a swap the colleague has not answered yet: the administrator decides it directly, with no approval request',
      qs.get('request_notice') == 'approved' and swap(a7)['status'] == 'Approved' and engine(a7) == (None, []) and sched(e1) == 'WFH', (qs, swap(a7), engine(a7)))
configure_swap('PEER')

# 3.31.130: an app approver's decision and the exchange are one transaction. Whatever fails after the level was decided (a day changed
# since the request, a database error on the schedule or on the swap), nothing is saved, the level stays open, nobody is told.
configure_swap('LEVEL_1', e3)
set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
audit_n = lambda: int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action IN ('SCHEDULE_SWAP_APPROVED','SCHEDULE_SWAP_REJECTED')")[0]['c'])
def app_decide(sid, decision):
    return emp3.post('ews_swap_decide', _wpnonce=swap_form_nonce(emp3, 'ews_swap_decide', sid), swap_id=sid, decision=decision)[1]
def awaiting_app():
    create(e2)
    sid = last()
    accept(sid)
    return sid
untouched = ('WAITING_FOR_LEVEL_1', [(1, 'PENDING')])
for how in ('a day changed (after the swap was claimed)', 'a database error on ews_schedule', 'a database error on ews_schedule_swaps (reject)'):
    sid = awaiting_app()
    before_notes, before_audit = notes(), audit_n()
    day1 = 'Office'
    if how.startswith('a day'):
        set_schedule(e1, 'Vacation'); day1 = 'Vacation'   # saved a moment ago, the approver's read still sees Office
        stale(e1, 'Office')
    decision = 'reject' if 'reject' in how else 'approve'
    if 'ews_schedule_swaps' in how:
        break_writes('ews_schedule_swaps')
    elif 'ews_schedule' in how:
        break_writes('ews_schedule')
    qs = app_decide(sid, decision)
    break_writes('')
    stale()
    err = 'changed' if how.startswith('a day') else 'save'
    check('the approver decides in the app with %s: refused (%s), the swap Awaiting, the level open, both days as they were, nobody told' % (how, err),
          qs.get('swap_error') == err and swap(sid)['status'] == 'Awaiting' and engine(sid) == untouched and sched(e1) == day1 and sched(e2) == 'WFH' and notes() == before_notes and audit_n() == before_audit,
          (qs, swap(sid), engine(sid), sched(e1), sched(e2)))
    set_schedule(e1, 'Office')
    qs = app_decide(sid, 'reject')
    check('...and the same approver can still reject it afterwards', qs.get('swap_done') == 'rejected' and swap(sid)['status'] == 'Rejected' and engine(sid)[0] == 'REJECTED', (qs, swap(sid), engine(sid)))
sid = awaiting_app()
set_schedule(e1, 'Vacation')
qs = app_decide(sid, 'approve')
check('a schedule changed since the request (seen when the approver decides): refused, the level stays open, the leave stays',
      qs.get('swap_error') == 'changed' and swap(sid)['status'] == 'Awaiting' and engine(sid) == untouched and sched(e1) == 'Vacation', (qs, swap(sid), engine(sid)))
app_decide(sid, 'reject')
set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
configure_swap('PEER')

# 3.31.156 (owner, 2026-11-20): two races pinned on the code before the swap fixes.
def stale_many(pairs):
    php("file_put_contents(WPMU_PLUGIN_DIR.'/ews-e2e-swap.txt','%s');" % '|'.join('stale %d %s %s' % (eid, DAY, st) for eid, st in pairs))


# (a) Two accepts of the same swap: the second sees the days as they were (a request that read them a moment before) and is refused.
set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')
create(e2)
ra = last()
row = php("echo wp_json_encode($wpdb->get_row(\"SELECT * FROM {$p}ews_schedule_swaps WHERE id=%d\"));" % ra).splitlines()[-1]
before_notes = notes()
qs = accept(ra)
stale_many([(e1, 'Office'), (e2, 'WFH')])
second = php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'swap_apply'); $m->setAccessible(true);
    echo wp_json_encode($m->invoke($o,(object)json_decode('%s',true)));""" % row.replace("'", "\\'")).splitlines()[-1]
stale()
check('race: two accepts of the same swap at once: the second is refused (expired), the days are exchanged once, told once',
      qs.get('swap_done') == 'accepted' and second == '"expired"' and swap(ra)['status'] == 'Accepted' and sched(e1) == 'WFH' and sched(e2) == 'Office'
      and audits(ra) == 1 and notes() == before_notes + 2, (qs, second, swap(ra), sched(e1), sched(e2), audits(ra), notes() - before_notes))

# (b) Two Pending swaps on one day sharing the requester (seeded directly: the rules may refuse to create the second). The colleague's
# row comes first in the write order; the requester's day was exchanged by the first swap, so the second must change nothing at all.
set_schedule(e4, 'WFH'); set_schedule(e1, 'Office'); set_schedule(e2, 'WFH')   # e4's row id is lower than e1's
pair = php("""foreach([%d,%d] as $t){ $wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%d,'target_employee_id'=>$t,'work_date'=>'%s','requester_status'=>'Office','target_status'=>'WFH','status'=>'Pending','requested_by'=>1,'created_at'=>current_time('mysql')]); $ids[]=$wpdb->insert_id; } echo wp_json_encode($ids);""" % (e2, e4, e1, DAY)).splitlines()[-1]
rb, rc = __import__('json').loads(pair)
qs = accept(rb)
stale_many([(e1, 'Office')])   # the second accept read the requester's day before the first was saved
n4 = swap_form_nonce(emp4, 'ews_swap_respond', rc)
qs2 = emp4.post('ews_swap_respond', _wpnonce=n4, swap_id=rc, decision='accept')[1]
stale()
check('race: two swaps on one day sharing the requester, both accepted: the first exchanges, the second is refused (changed) with no partial write',
      qs.get('swap_done') == 'accepted' and qs2.get('swap_error') == 'changed' and swap(rb)['status'] == 'Accepted' and swap(rc)['status'] == 'Pending'
      and sched(e1) == 'WFH' and sched(e2) == 'Office' and sched(e4) == 'WFH', (qs, qs2, swap(rb), swap(rc), sched(e1), sched(e2), sched(e4)))
php("$wpdb->update($p.'ews_schedule_swaps',['status'=>'Cancelled'],['id'=>%d]);" % rc)

# ---------------------------------------------------------------- 3.31.156 swap fixes (docs/tasks/swap-fixes.md; owner 2026-11-20)
configure_swap('PEER')
D2 = (today + __import__('datetime').timedelta(days=1)).isoformat()   # tomorrow: shown on the page's week except on its last day
dept_ops = int(q("SELECT department_id d FROM {p}ews_employees WHERE id=%d" % e1)[0]['d'])
e5 = int(php("""$u=username_exists('emp5')?:wp_create_user('emp5','emp5pass','emp5@example.com');
    $e=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%%d",$u));
    if(!$e){$wpdb->insert($p.'ews_employees',['name'=>'Emp Five','domain_name'=>'emp5','email'=>'emp5@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>1,'department_id'=>%d]);$e=$wpdb->insert_id;}
    echo $e;""" % dept_ops).splitlines()[-1])
emp5 = Session('emp5', 'emp5pass')
for who, st in ((e1, 'Office'), (e2, 'WFH'), (e5, 'WFH')):
    set_schedule(who, st, D2)
cancel_all = lambda: php("$wpdb->query(\"UPDATE {$p}ews_schedule_swaps SET status='Cancelled' WHERE status IN ('Pending','Awaiting')\");")
cancel_all()

# SW-b: one commitment per person and day.
_, qs, _ = create(e2, D2)
first = last()
check('SW-b: the first swap of the day is sent', qs.get('swap_sent') == '1', qs)
_, qs, _ = create(e5, D2)
check('SW-b: a second swap for the same day with another colleague is refused (one_per_day), nothing saved', qs.get('swap_error') == 'one_per_day' and last() == first, (qs, last(), first))
check('...the New request sheet no longer offers that day', D2 not in php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'swap_new_choices'); $m->setAccessible(true); wp_set_current_user((int)get_user_by('login','emp1')->ID);
    $e=(new ReflectionMethod($o,'people_employee')); $e->setAccessible(true); echo wp_json_encode(array_keys($m->invoke($o,$e->invoke($o,%d))['days']));""" % e1).splitlines()[-1])
_, qs, _ = emp2.post('ews_swap_create', _wpnonce=emp2.nonce('ews_swap_create', view='schedule'), target_employee_id=e1, work_date=D2)
check('SW-b: the colleague asked cannot ask the same day back (pending, as before)', qs.get('swap_error') == 'pending', qs)
set_schedule(e5, 'Office', D2)   # e5 asks e2 (WFH) for that day
_, qs, _ = emp5.post('ews_swap_create', _wpnonce=emp5.nonce('ews_swap_create', view='schedule'), target_employee_id=e2, work_date=D2)
check('SW-b: a request asked of someone and not answered is not their commitment: they may ask another colleague', qs.get('swap_sent') == '1', qs)
set_schedule(e5, 'WFH', D2)
# Accepting while one's own request for that day is open is refused: e5 asked e2, so e5 cannot accept a swap asked of e5 that day.
php("$wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%d,'target_employee_id'=>%d,'work_date'=>'%s','requester_status'=>'Office','target_status'=>'WFH','status'=>'Pending','requested_by'=>1,'created_at'=>current_time('mysql')]);" % (e1, e5, D2))   # e1 asks e5 (seeded: e1 already asked e2)
dup = last()
n5 = swap_form_nonce(emp5, 'ews_swap_respond', dup)   # None when tomorrow is not on this week's page
if n5:
    _, qs, _ = emp5.post('ews_swap_respond', _wpnonce=n5, swap_id=dup, decision='accept')
    check('SW-b: accepting is refused while one\'s own swap for that day is open (one_per_day_accept); nothing changes',
          qs.get('swap_error') == 'one_per_day_accept' and swap(dup)['status'] == 'Pending' and sched(e5, D2) == 'WFH', (qs, swap(dup), sched(e5, D2)))
    _, qs, _ = emp5.post('ews_swap_respond', _wpnonce=n5, swap_id=dup, decision='reject')
    check('...declining it is always allowed', qs.get('swap_done') == 'rejected' and swap(dup)['status'] == 'Rejected', (qs, swap(dup)))
cancel_all()
_, qs, _ = create(e5, D2)
check('SW-b: once the first is closed, another colleague can be asked for that day', qs.get('swap_sent') == '1', qs)
cancel_all()
busy = php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'swap_create_locked'); $m->setAccessible(true); $pe=new ReflectionMethod($o,'people_employee'); $pe->setAccessible(true);
    $sd=new ReflectionMethod($o,'schedule_for_employee_date'); $sd->setAccessible(true); $a=$sd->invoke($o,%d,'%s'); $b=$sd->invoke($o,%d,'%s'); $r=$pe->invoke($o,%d);
    echo wp_json_encode([$m->invoke($o,$r,%d,'%s',$a,$b),$m->invoke($o,$r,%d,'%s',$a,$b)]);""" % (e1, D2, e2, D2, e1, e2, D2, e2, D2)).splitlines()[-1]
two = __import__('json').loads(busy)
check('SW-b: the same swap saved twice (a double click) makes one row: the second is refused', 'id' in two[0] and two[1].get('error') == 'pending', two)
cancel_all()

# SW-a: an administrator who is also an employee swaps within their own department, like anyone.
adm_eid = int(php("""$u=(int)get_user_by('login','admin')->ID; $e=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%%d",$u));
    if(!$e){$wpdb->insert($p.'ews_employees',['name'=>'Admin Employee','domain_name'=>'adminemp','email'=>'adminemp@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>1,'department_id'=>%d]);$e=$wpdb->insert_id;}
    else $wpdb->update($p.'ews_employees',['department_id'=>%d],['id'=>$e]); echo $e;""" % (dept_ops, dept_ops)).splitlines()[-1])
set_schedule(adm_eid, 'Office', D2); set_schedule(e3, 'WFH', D2)
st, page, _ = adm.req('/app/?ews_view=schedule')
check('SW-a: the swap form lists only their department', 'value="%d">Emp Three<' % e3 not in page and 'value="%d">Emp Two<' % e2 in page)
n_adm = adm.nonce('ews_swap_create', view='schedule')
_, qs, _ = adm.post('ews_swap_create', _wpnonce=n_adm, target_employee_id=e3, work_date=D2)
check('SW-a: an administrator-employee cannot swap with another department', qs.get('swap_error') == 'department', qs)
_, qs, _ = adm.post('ews_swap_create', _wpnonce=n_adm, target_employee_id=e2, work_date=D2)
check('SW-a: ...but can with their own department', qs.get('swap_sent') == '1', qs)
cancel_all()
php("$wpdb->delete($p.'ews_employees',['id'=>%d]);" % adm_eid)

# SW-e: the colleague's answer and the approver's decision are both kept; approved by the approver shows as Approved.
configure_swap('LEVEL_1', e3)
create(e2, D2)
se = last()
accept_qs = emp2.post('ews_swap_respond', _wpnonce=swap_form_nonce(emp2, 'ews_swap_respond', se), swap_id=se, decision='accept')[1] if swap_form_nonce(emp2, 'ews_swap_respond', se) else {}
if accept_qs.get('swap_done') == 'awaiting':
    emp3.post('ews_swap_decide', _wpnonce=swap_form_nonce(emp3, 'ews_swap_decide', se), swap_id=se, decision='approve')
    who = q("SELECT status,responded_by,decided_by,responded_at IS NOT NULL ra,decided_at IS NOT NULL da FROM {p}ews_schedule_swaps WHERE id=%d" % se)[0]
    uid = lambda login: int(q("SELECT ID FROM {p}users WHERE user_login='%s'" % login)[0]['ID'])
    check('SW-e: after the approver approves: Approved, the colleague\'s answer and the approver\'s decision both kept',
          who['status'] == 'Approved' and int(who['responded_by']) == uid('emp2') and int(who['decided_by']) == uid('emp3') and str(who['ra']) == '1' and str(who['da']) == '1', who)
cancel_all()
configure_swap('PEER')

# SW-h: a manager away who may still decide sees, on the Schedule page, the swaps waiting for their substitute.
php("""$wpdb->query("DELETE FROM {$p}ews_employee_relationships WHERE employee_id=%d");
    $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>%d,'relationship_type'=>'supervisor','related_employee_id'=>%d,'active'=>1]);
    $wpdb->query("DELETE FROM {$p}ews_approval_substitutes");
    $wpdb->insert($p.'ews_approval_substitutes',['manager_employee_id'=>%d,'substitute_employee_id'=>%d,'starts_at'=>gmdate('Y-m-d H:i:s',time()-3600),'ends_at'=>gmdate('Y-m-d H:i:s',time()+7*86400),'status'=>'active','source'=>'hr','reason'=>'','decided'=>0,'created_by'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'started_at'=>gmdate('Y-m-d H:i:s',time()-3600)]);
    update_option('ews_feature_substitutes',1,false); update_option('ews_substitute_settings',['manager_decides'=>1],false);""" % (e1, e1, e4, e4, e3))
php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'approval_configure_workflow'); $m->setAccessible(true);
    $m->invoke($o,'shift_swap','LEVEL_1',[1=>['resolver_type'=>'SUPERVISOR','resolver_value'=>0]],\\WorkforceOne\\Approvals\\Workflows::IN_USE['shift_swap'][1]);""")
create(e2, D2)
sh = last()
n_acc = swap_form_nonce(emp2, 'ews_swap_respond', sh)
if n_acc:
    emp2.post('ews_swap_respond', _wpnonce=n_acc, swap_id=sh, decision='accept')
    check('SW-h: the substitute sees the swap to approve (as before)', swap_form_nonce(emp3, 'ews_swap_decide', sh) is not None)
    n_mgr = swap_form_nonce(emp4, 'ews_swap_decide', sh)
    check('SW-h: the manager away, allowed to decide, sees it too on the Schedule page', n_mgr is not None)
    php("update_option('ews_substitute_settings',['manager_decides'=>0],false);")
    check('SW-h: ...not when managers away may not decide', swap_form_nonce(emp4, 'ews_swap_decide', sh) is None)
    php("update_option('ews_substitute_settings',['manager_decides'=>1],false);")
    if n_mgr:
        _, qs, _ = emp4.post('ews_swap_decide', _wpnonce=n_mgr, swap_id=sh, decision='approve')
        check('SW-h: ...and approves it there: the days are exchanged', qs.get('swap_done') == 'approved' and swap(sh)['status'] == 'Approved', (qs, swap(sh)))
php("delete_option('ews_feature_substitutes'); delete_option('ews_substitute_settings'); $wpdb->query(\"DELETE FROM {$p}ews_approval_substitutes\"); $wpdb->query(\"DELETE FROM {$p}ews_employee_relationships WHERE employee_id=%d\");" % e1)
cancel_all()
configure_swap('PEER')

# Upgrade: a site on the schema before 3.31.156 gets the two columns; its rows stay as they were.
out = php("""$t=$p.'ews_schedule_swaps'; $wpdb->query("ALTER TABLE {$t} DROP COLUMN decided_by, DROP COLUMN decided_at"); update_option('ews_schema_version','3.31.148.1');
    $wpdb->insert($t,['requester_employee_id'=>%d,'target_employee_id'=>%d,'work_date'=>'2020-01-01','requester_status'=>'Office','target_status'=>'WFH','status'=>'Accepted','requested_by'=>1,'responded_by'=>7,'responded_at'=>'2020-01-01 10:00:00','created_at'=>'2020-01-01 09:00:00']); $old=$wpdb->insert_id;
    $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'maybe_upgrade_schema'); $m->setAccessible(true); delete_transient('ews_schema_retry'); $m->invoke($o);
    $cols=$wpdb->get_col("SHOW COLUMNS FROM {$t}"); $r=$wpdb->get_row("SELECT status,responded_by,decided_by FROM {$t} WHERE id=".$old,ARRAY_A);
    echo wp_json_encode(['cols'=>array_values(array_intersect(['decided_by','decided_at'],$cols)),'version'=>get_option('ews_schema_version'),'failed'=>get_option('ews_schema_failed'),'row'=>$r]);""" % (e1, e2)).splitlines()[-1]
up = __import__('json').loads(out)
check('upgrade: the two columns are added, the schema is 3.31.156, an old row is unchanged',
      up['cols'] == ['decided_by', 'decided_at'] and up['version'] == '3.31.156' and not up['failed'] and up['row'] == {'status': 'Accepted', 'responded_by': '7', 'decided_by': None}, up)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

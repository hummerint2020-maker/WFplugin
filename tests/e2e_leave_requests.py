"""Leave in My requests (3.31.158, docs/tasks/leave-in-requests.md, mockups docs/mockups/leave-in-requests/):
off by default (My requests and the Leave page as before); on: every leave balance on top of My requests, "Request
cancellation" on an approved leave that has not started (the Leave page's handler and rules, back to My requests with a
message), the cancellation's states, administrators told when leave approvals are off, the Leave filter (leave and early
leave, pages of 50); the Leave page switch (only while it is on): menu item gone, its address and links go to My requests,
"View all" to the Leave filter, a notification link still marked read; turned off again, all as before.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_requests.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080. Wipes Workforce One leave data.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_leave_requests','ews_leave_balances','ews_early_leave_requests','ews_approval_requests','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_my_requests_leave','ews_feature_leave_page','ews_approval_settings'] as $o) delete_option($o);
    $wpdb->query("DELETE FROM {$p}ews_audit_log WHERE action IN ('my_requests_leave_feature_update','leave_page_feature_update')");
    update_option('ews_feature_my_approvals',1,false); update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    $annual=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0 WHERE id=$annual");
    $casual=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Casual Leave'");
    if(!$casual){$wpdb->insert($p.'ews_leave_types',['name'=>'Casual Leave','annual_entitlement'=>0,'deduct_balance'=>0,'active'=>1]);$casual=(int)$wpdb->insert_id;}
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=0 WHERE id=$casual");
    $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$annual,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>3,'pending'=>0]);
    $bal=(int)$wpdb->insert_id;
    echo wp_json_encode(['emp'=>$eid,'annual'=>$annual,'casual'=>$casual,'bal'=>$bal,'emp_uid'=>(int)get_user_by('login','emp1')->ID,'admin_uid'=>(int)get_user_by('login','admin')->ID]);
""").splitlines()[-1])
E = ids['emp']
NOW = php("echo current_time('mysql');").splitlines()[-1]


def leave(start, end, status='Approved', cancel='Not Requested', at=None, days=1):
    return int(php("""$wpdb->insert($p.'ews_leave_requests',['employee_id'=>%d,'leave_type_id'=>%d,'balance_id'=>%d,'start_date'=>'%s','end_date'=>'%s','requested_days'=>%d,
        'status'=>'%s','cancellation_status'=>'%s','requested_at'=>'%s']); echo $wpdb->insert_id;""" % (E, ids['annual'], ids['bal'], start, end, days, status, cancel, at or NOW)).splitlines()[-1])


A = leave(d(10), d(12), days=3)                                        # approved, not started: may be cancelled
B = leave(d(-1), d(1), days=3)                                         # approved, started
C = leave(d(20), d(20), cancel='Rejected')                             # cancelling it was refused
D = leave(d(30), d(31), status='Cancelled', cancel='Approved', days=2)  # cancelled at the employee's request
P = leave(d(40), d(40), status='Pending')                              # waiting
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')


def page(path='/app/?ews_view=requests'):
    return emp.req(path)[1]


def item(html, rid):
    m = re.search(r'<article class="wfo-apv-item[^"]*" data-req="leave-%d">.*?</article>' % rid, html, re.S)
    return m.group(0) if m else ''


# ---------------------------------------------------------------- off (the default): as before
pg = page()
check('off: no balances strip, no cancel button, no Leave filter on My requests', 'wfo-lr-bals' not in pg and 'value="ews_leave_cancel"' not in pg and 'req_kind=leave' not in pg)
check('off: a cancelled leave reads as before (no "Cancelled at your request")', 'Cancelled at your request' not in pg and item(pg, D) != '')
st, lp, _ = emp.req('/app/?ews_view=vacation')
check('off: the Leave page opens as before, with its menu item', st == 200 and 'ews-vac-' in lp and 'ews_view=vacation' in lp)
n = re.search(r'name="_wpnonce" value="([^"]+)"', adm.req('/wp-admin/admin.php?page=ews31-features')[1])
features_nonce = n.group(1) if n else ''


def features(**on):
    data = {'_wpnonce': features_nonce, 'my_requests_leave_present': '1', 'my_approvals_present': '1', 'my_approvals_enabled': '1'}
    data.update({k: '1' for k, v in on.items() if v})
    return adm.post('ews31_features_save', **data)


features(leave_page_enabled=False)
check('the Leave page cannot be switched off while Leave in My requests is off', php("echo (int)get_option('ews_feature_leave_page',1);").splitlines()[-1] == '1')

# ---------------------------------------------------------------- on
features(my_requests_leave_enabled=True, leave_page_enabled=True)
check('the switch is saved and audited', php("echo (int)get_option('ews_feature_my_requests_leave');").splitlines()[-1] == '1'
      and q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='my_requests_leave_feature_update'")[0]['c'] in ('1', 1))
pg = page()
strip = re.search(r'<section class="wfo-lr-bals".*?</section>', pg, re.S)
strip = strip.group(0) if strip else ''
check('on: the balances strip lists every leave type', 'Annual Leave' in strip and 'Casual Leave' in strip, strip[:400])
check('...Annual Leave: 21 - 3 used = 18 of 21 days', re.search(r'Annual Leave</span><b>18</b><small>of 21 days', strip) is not None, strip[:600])
check('...a type without deduction says so', re.search(r'Casual Leave</span><b>—</b><small>No balance deduction', strip) is not None)
check('the cancel button only on the approved leave that has not started', 'Request cancellation' in item(pg, A)
      and all('Request cancellation' not in item(pg, x) for x in (B, C, D, P)) and pg.count('Request cancellation') == 1)
check('...it posts back to My requests', 'name="ews_return" value="requests"' in item(pg, A))
check('a started leave with days left offers "I\'m back early" instead', 'name="back_early" value="1"' in item(pg, B) and 'Request cancellation' not in item(pg, B))
check('a refused cancellation says it cannot be asked again', 'Cancelling it was refused, so it cannot be asked again.' in item(pg, C))
check('a cancelled leave says it was at the employee\'s request, and the days came back', 'Cancelled at your request' in item(pg, D) and '2 days went back to your balance.' in item(pg, D))
check('a waiting leave can still be withdrawn', 'value="ews_request_withdraw"' in item(pg, P))

nonce = re.search(r'name="_wpnonce" value="([^"]+)"', item(pg, A)).group(1)
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nonce, request_id=A, ews_return='requests')
check('asking to cancel comes back to My requests with "submitted"', qs.get('ews_view') == 'requests' and qs.get('leave_cancel_sent') == '1', (st, qs))
row = q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % A)[0]
check('...the leave stays Approved with its cancellation waiting', row == {'status': 'Approved', 'cancellation_status': 'Pending'}, row)
told = q("SELECT user_id,title FROM {p}ews_notifications WHERE title='Leave Cancellation Request'")
check('...leave approvals off: the administrators are told', any(int(r['user_id']) == ids['admin_uid'] for r in told), told)
pg = page()
check('...My requests shows "Cancellation waiting" and no button', 'Cancellation waiting' in item(pg, A) and 'value="ews_leave_cancel"' not in item(pg, A)
      and 'The leave stands until the cancellation is approved.' in item(pg, A))
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nonce, request_id=A, ews_return='requests')
check('asking again: back to My requests with "already waiting" (not a bare error page)', qs.get('ews_view') == 'requests' and qs.get('leave_cancel_error') == 'already_pending', (st, qs))
st, body, _ = emp.req('/app/?ews_view=requests&leave_cancel_error=already_pending')
check('...which explains it', 'You already asked to cancel this leave.' in body)
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nonce, request_id=B, ews_return='requests')
check('a started leave: "started", back to My requests', qs.get('leave_cancel_error') == 'started', (st, qs))
st, qs, body = emp.post('ews_leave_cancel', _wpnonce=nonce, request_id=C)
check('from the Leave page (no ews_return) a refusal keeps the Leave page\'s own message', 'already rejected' in body, (st, body[:200]))

# The administrator decides it (leave approvals off): the leave is cancelled, the days come back.
apv = adm.req('/app/?ews_view=approvals')[1]
m = re.search(r'value="ews_leave_cancel_respond".*?name="request_id" value="%d"' % A, apv, re.S) or re.search(r'name="request_id" value="%d".*?value="ews_leave_cancel_respond"' % A, apv, re.S)
form = re.search(r'<form[^>]*>(?:(?!</form>).)*name="request_id" value="%d"(?:(?!</form>).)*ews_leave_cancel_respond(?:(?!</form>).)*</form>|<form[^>]*>(?:(?!</form>).)*ews_leave_cancel_respond(?:(?!</form>).)*name="request_id" value="%d"(?:(?!</form>).)*</form>' % (A, A), apv, re.S)
if form:
    n2 = re.search(r'name="_wpnonce" value="([^"]+)"', form.group(0)).group(1)
    adm.post('ews_leave_cancel_respond', _wpnonce=n2, request_id=A, decision='approve', ews_return='approvals')
row = q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % A)[0]
check('the administrator approves it from My approvals: Cancelled', form is not None and row == {'status': 'Cancelled', 'cancellation_status': 'Approved'}, row)
pg = page()
check('...My requests: "Cancelled at your request", 3 days back', 'Cancelled at your request' in item(pg, A) and '3 days went back to your balance.' in item(pg, A))

# ---------------------------------------------------------------- the Leave filter: leave and early leave, pages of 50
for i in range(56):
    leave(d(-200 - i), d(-200 - i), status='Rejected', at='2025-01-%02d 10:00:00' % (1 + i % 28))
php("""$wpdb->insert($p.'ews_early_leave_requests',['employee_id'=>%d,'work_date'=>'%s','leave_minutes'=>60,'reason'=>'x','status'=>'Pending','requested_at'=>'%s']);""" % (E, d(3), NOW))
pg = page()
check('My requests (All) as before: each kind\'s last 20 (20 leave, 1 early leave), with an All and a Leave chip', pg.count('<article class="wfo-apv-item') == 21 and re.search(r'<a href="[^"]*req_kind=leave[^"]*">Leave</a>', pg) is not None, (pg.count('<article class="wfo-apv-item'), re.findall(r'tone-[a-z]+', pg)[:30]))
p1 = page('/app/?ews_view=requests&req_kind=leave')
check('the Leave filter: 20 on a page, leave and early leave only, an "Older" link', p1.count('<article class="wfo-apv-item') == 20 and 'tone-early' in p1 and 'req_page=2' in p1, p1.count('<article class="wfo-apv-item'))
keys = []
for n in (1, 2, 3, 4):
    pn = page('/app/?ews_view=requests&req_kind=leave&req_page=%d' % n)
    keys += re.findall(r'data-req="([^"]+)"', pn)
check('...pages 2-4 have the rest (5 + 56 + 1 = 62 in all, 20 + 20 + 20 + 2), each once', len(keys) == 62 and len(set(keys)) == 62 and 'Newer' in pn and 'Older' not in pn, (len(keys), len(set(keys))))
p2 = page('/app/?ews_view=requests&req_kind=leave&req_page=2&req_state=rejected')
check('...the page links keep the state chip', re.search(r'href="[^"]*req_page=(1|3)[^"]*req_state=rejected|href="[^"]*req_state=rejected[^"]*req_page=(1|3)', p2) is not None)
check('...the state chips keep the Leave filter', re.search(r'href="[^"]*req_kind=leave[^"]*req_state=rejected|href="[^"]*req_state=rejected[^"]*req_kind=leave', p1) is not None)

# ---------------------------------------------------------------- the cancellation's edge cases (owner 2026-11-21)
def decide(rid, decision='approve'):
    apv = adm.req('/app/?ews_view=approvals')[1]
    f = re.search(r'<form[^>]*>(?:(?!</form>).)*ews_leave_cancel_respond(?:(?!</form>).)*name="request_id" value="%d"(?:(?!</form>).)*</form>' % rid, apv, re.S) \
        or re.search(r'<form[^>]*>(?:(?!</form>).)*name="request_id" value="%d"(?:(?!</form>).)*ews_leave_cancel_respond(?:(?!</form>).)*</form>' % rid, apv, re.S)
    if not f:
        return None
    n2 = re.search(r'name="_wpnonce" value="([^"]+)"', f.group(0)).group(1)
    return adm.post('ews_leave_cancel_respond', _wpnonce=n2, request_id=rid, decision=decision, ews_return='approvals')


def with_days(start_off, end_off, cancel='Pending'):
    rid = leave(d(start_off), d(end_off), cancel=cancel, days=end_off - start_off + 1)
    for o in range(start_off, end_off + 1):
        php("$wpdb->insert($p.'ews_leave_schedule_snapshots',['leave_request_id'=>%d,'employee_id'=>%d,'work_date'=>'%s','had_schedule'=>0]);"
            " $wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Vacation']);" % (rid, E, d(o), E, d(o), E, d(o)))
    return rid


php("$wpdb->update($p.'ews_leave_balances',['used'=>10],['id'=>%d]);" % ids['bal'])   # the seeded leaves were never charged
used0 = 10.0
L1 = with_days(-2, 2)   # asked before it started, decided on its third day
decide(L1)
row = q("SELECT status,cancellation_status,end_date,requested_days FROM {p}ews_leave_requests WHERE id=%d" % L1)[0]
check('approved after the leave started: only the days from today on are cancelled; it now ends yesterday', row['status'] == 'Approved' and row['cancellation_status'] == 'Approved'
      and row['end_date'] == d(-1) and float(row['requested_days']) == 2.0, row)
sched = {r['work_date']: r['status'] for r in q("SELECT work_date,status FROM {p}ews_schedule WHERE employee_id=%d AND work_date BETWEEN '%s' AND '%s'" % (E, d(-2), d(2)))}
check('...the days taken stay leave, today and after are back as before', sched.get(d(-2)) == 'Vacation' and sched.get(d(-1)) == 'Vacation' and all(d(o) not in sched for o in (0, 1, 2)), sched)
check('...3 days back to the balance', float(q("SELECT used FROM {p}ews_leave_balances WHERE id=%d" % ids['bal'])[0]['used']) == used0 - 3)
check('...My requests says it was shortened', 'Shortened at your request' in item(page(), L1))
L2 = with_days(-6, -5)   # decided once it had ended
decide(L2)
row = q("SELECT status,cancellation_status,end_date FROM {p}ews_leave_requests WHERE id=%d" % L2)[0]
check('decided after the leave ended: nothing cancelled, the cancellation closed as Ended', row == {'status': 'Approved', 'cancellation_status': 'Ended', 'end_date': d(-5)}, row)
check('...My requests says so', 'nothing was cancelled' in item(page(), L2))

W = leave(d(15), d(16), days=2)
pg = page()
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', item(pg, W)).group(1)
emp.post('ews_leave_cancel', _wpnonce=nonce, request_id=W, ews_return='requests')
it = item(page(), W)
m = re.search(r'name="_wpnonce" value="([^"]+)"(?:(?!</form>).)*name="kind" value="leave_cancel"', it, re.S)
check('a cancellation waiting can be withdrawn', m is not None, it[-600:])
if m:
    st, qs, _ = emp.post('ews_request_withdraw', _wpnonce=m.group(1), kind='leave_cancel', id=W)
row = q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % W)[0]
check('...withdrawn: the leave stands', row == {'status': 'Approved', 'cancellation_status': 'Withdrawn'}, row)
it = item(page(), W)
check('...no cancel button any more, and it says why', 'value="ews_leave_cancel"' not in it and 'You withdrew the cancellation' in it)
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nonce, request_id=W, ews_return='requests')
check('...asking again: "closed" (a leave has one cancellation)', qs.get('leave_cancel_error') == 'closed', qs)

# "I'm back early": a started leave's days from tomorrow on, the same approval as a cancellation.
BK = with_days(-1, 3, cancel='Not Requested')
it = item(page(), BK)
check('"I\'m back early" on a started leave with days left (no cancel button)', 'name="back_early" value="1"' in it and 'Request cancellation' not in it, it[-500:])
check('...it says which days and how many', '(3 days)' in it)
nb = re.search(r'name="_wpnonce" value="([^"]+)"', it).group(1)
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nb, request_id=BK, ews_return='requests', back_early='1')
row = q("SELECT status,cancellation_status,cancel_from FROM {p}ews_leave_requests WHERE id=%d" % BK)[0]
check('...asked: the cancellation waits, from tomorrow', qs.get('leave_cancel_sent') == '1' and row == {'status': 'Approved', 'cancellation_status': 'Pending', 'cancel_from': d(1)}, (qs, row))
check('...My requests says from when, and it can be withdrawn', 'Back early: the days from' in item(page(), BK) and 'name="kind" value="leave_cancel"' in item(page(), BK))
card = adm.req('/app/?ews_view=approvals')[1]
check('...the approver sees from when and how many days', 'Back early: cancel from' in card and '3 days' in card)
php("$wpdb->update($p.'ews_leave_balances',['used'=>10],['id'=>%d]);" % ids['bal'])
decide(BK)
row = q("SELECT status,cancellation_status,end_date,requested_days FROM {p}ews_leave_requests WHERE id=%d" % BK)[0]
sched = {r['work_date']: r['status'] for r in q("SELECT work_date,status FROM {p}ews_schedule WHERE employee_id=%d AND work_date BETWEEN '%s' AND '%s'" % (E, d(-1), d(3)))}
check('...approved today: the leave ends today, the 3 days after go back', row['status'] == 'Approved' and row['cancellation_status'] == 'Approved' and row['end_date'] == d(0)
      and float(row['requested_days']) == 2.0 and float(q("SELECT used FROM {p}ews_leave_balances WHERE id=%d" % ids['bal'])[0]['used']) == 7.0, row)
check('...today and yesterday stay leave, tomorrow on as before', sched.get(d(-1)) == 'Vacation' and sched.get(d(0)) == 'Vacation' and all(d(o) not in sched for o in (1, 2, 3)), sched)
LE = with_days(-2, 0, cancel='Not Requested')
check('a leave ending today: no "back early"', 'name="back_early"' not in item(page(), LE))
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nb, request_id=LE, ews_return='requests', back_early='1')
check('...asked anyway: "ends today"', qs.get('leave_cancel_error') == 'ending', qs)
st, qs, _ = emp.post('ews_leave_cancel', _wpnonce=nb, request_id=W, ews_return='requests', back_early='1')
check('...on a leave already cancelled once (withdrawn): "closed"', qs.get('leave_cancel_error') == 'closed', qs)

# Asked while leave approvals were off, then approvals turned on: it stays with the administrators.
O = leave(d(25), d(25), cancel='Pending')
php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'approval_configure_workflow'); $m->setAccessible(true);
    $m->invoke($o,'vacation','LEVEL_1',[1=>['resolver_type'=>'SUPERVISOR','resolver_value'=>0]],\\WorkforceOne\\Approvals\\Workflows::IN_USE['vacation'][1]);""")
active = php("$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'leave_approval_active'); $m->setAccessible(true); echo $m->invoke($o)?1:0;").splitlines()[-1]
r = decide(O)
row = q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % O)[0]
check('approvals turned on after a cancellation was asked: it stays with the administrators, who decide it', active == '1' and r is not None and row == {'status': 'Cancelled', 'cancellation_status': 'Approved'}, (active, r, row))
php("$wpdb->query(\"UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0\");")

# ---------------------------------------------------------------- the Leave page switched off
features(my_requests_leave_enabled=True, leave_page_enabled=False)
check('the Leave page is switched off and audited', php("echo (int)get_option('ews_feature_leave_page',1);").splitlines()[-1] == '0'
      and q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='leave_page_feature_update'")[0]['c'] in ('1', 1))
pg = page()
check('...its menu item is gone', 'ews_view=vacation' not in pg)
st, _, h = emp.req('/app/?ews_view=vacation&leave_sent=1')
loc = h.get('Location', '')
check('...its address goes to My requests, keeping the message', st in (301, 302) and 'ews_view=requests' in loc and 'leave_sent=1' in loc, (st, loc))
st, _, h = emp.req('/app/?ews_view=vacation&leave_history=all')
check('..."View all leave requests" goes to the Leave filter', 'req_kind=leave' in h.get('Location', '') and 'leave_history' not in h.get('Location', ''), h.get('Location', ''))
nid = int(php("""$wpdb->insert($p.'ews_notifications',['user_id'=>%d,'title'=>'Leave Approved','message'=>'x','type'=>'leave','is_read'=>0,'created_at'=>current_time('mysql'),'link'=>'/app/?ews_view=vacation']); echo $wpdb->insert_id;""" % ids['emp_uid']).splitlines()[-1])
st, _, h = emp.req('/app/?ews_view=vacation&ews_nid=%d' % nid)
check('...a notification link saved before is still marked read, and lands on My requests', 'ews_view=requests' in h.get('Location', '') and 'ews_nid' not in h.get('Location', '')
      and q("SELECT is_read FROM {p}ews_notifications WHERE id=%d" % nid)[0]['is_read'] in ('1', 1), h.get('Location', ''))
check('...the profile\'s leave link opens My requests', 'ews_view=vacation' not in page('/app/?ews_view=profile'))
st, _, h = adm.req('/app/?ews_view=vacation')
check('...an administrator without an employee record goes to My approvals', 'ews_view=approvals' in h.get('Location', ''), h.get('Location', ''))

# Leave in My requests switched off again: the Leave page is back whatever its own switch says.
features(my_requests_leave_enabled=False)
st, lp, _ = emp.req('/app/?ews_view=vacation')
check('Leave in My requests off: the Leave page is back, My requests as before', st == 200 and 'ews-vac-' in lp and 'wfo-lr-bals' not in page()
      and php("echo (int)get_option('ews_feature_leave_page',1);").splitlines()[-1] == '1')
php("update_option('ews_feature_my_approvals',1,false);")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

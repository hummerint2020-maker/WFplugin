"""My approvals and My requests (3.31.103, owner decisions 2026-10-16, docs/tasks/approvals.md, mockup
docs/mockups/my-approvals/): off by default; one inbox in the app for every request waiting for the
signed-in person (leave, overtime, corrections, swaps), with its level, wait in working hours, overdue
first, "moved up from", the path; a decision taken there goes through the request's own handler and
comes back; a reason to reject per type; administrators see the levels waiting for them; approving
several at once (the same forms, one after the other); the menu count, the home card and the link on
the pages with their own lists; My requests with the path and withdrawing a waiting leave, overtime
or correction.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_my_approvals.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-approvals'
wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_overtime_requests','ews_attendance_corrections','ews_schedule_swaps','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_audit_log','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_approval_allow_self','ews_approval_self_rule','ews_approval_deadlines','ews_approval_settings','ews_approval_reject_note','ews_approval_overdue','ews_feature_my_approvals'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_feature_overtime',1,false); update_option('ews_feature_corrections',1,false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','big','sami')");
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID,'admin_uid'=>(int)get_user_by('login','admin')->ID];
    foreach(['mona'=>'Mona Manager','big'=>'Big Boss','sami'=>'Sami Colleague'] as $login=>$name){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    foreach([[$eid,$out['mona']],[$out['mona'],$out['big']],[$out['sami'],$out['mona']]] as [$e,$s]) $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>$e,'relationship_type'=>'supervisor','related_employee_id'=>$s,'active'=>1]);
    $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0 WHERE id=$type");
    foreach([$eid,$out['big']] as $e) $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$e,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);
    $out['type']=$type;
    echo wp_json_encode($out);
""").splitlines()[-1])
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
S = {k: Session(k, k + 'pass') for k in ('mona', 'big', 'sami')}


def card(page, key):
    m = re.search(r'data-approval-card="ews-approval-workflow-%s".*?(?=data-approval-card=|<div id="ews-self-decisions")' % key, page, re.S)
    return m.group(0) if m else ''


def save(key, mode, **levels):
    st, page, _ = adm.req(PAGE)
    m = re.search(r'name="_wpnonce" value="([^"]+)"', card(page, key))
    data = {'action': 'ews_approval_workflow_save', '_wpnonce': m.group(1) if m else 'x', 'workflow_key': key, 'approval_mode': mode, 'self_rule': 'allow', 'remind_hours': 0, 'escalate_hours': 0, 'overdue_hours': 0}
    data.update({k: str(v) for k, v in levels.items()})
    st, body, h = adm.req('/wp-admin/admin-post.php', data)
    return urllib.parse.unquote(html.unescape(h.get('Location', '')))


def inbox(sess, extra=''):
    return sess.req('/app/?ews_view=approvals' + extra)[1]


def items(page):
    return re.findall(r'<article class="wfo-apv-item[^"]*" data-apv-item="(\d+)">(.*?)</article>', page, re.S)


def form_of(page, approval_id):
    for i, body in items(page):
        if int(i) == approval_id:
            f = re.search(r'<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>', body, re.S)
            return {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f.group(1))} if f else {}
    return {}


def ar_id(entity, entity_id):
    r = q("SELECT id,status FROM {p}ews_approval_requests WHERE entity_type='%s' AND entity_id=%d" % (entity, entity_id))
    return (int(r[0]['id']), r[0]['status']) if r else (0, '')


def leave(sess, start, end=None):
    n = sess.nonce('ews_vacation_request_create')
    st, qs, _ = sess.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=end or start, reason='family trip')
    r = q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s' ORDER BY id DESC LIMIT 1" % start)
    return int(r[0]['id']) if r else 0


def start_approval(key, entity, entity_id, emp_id):
    return php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'approval_start');$m->setAccessible(true);$r=$m->invoke($o,'%s','%s',%d,%d);echo is_wp_error($r)?'ERR '.$r->get_error_code():$r;""" % (key, entity, entity_id, emp_id)).splitlines()[-1]


# ---------------------------------------------------------------- off by default
save('vacation', 'LEVEL_2', level_1_type='SUPERVISOR', level_2_type='MANAGERS_MANAGER')
lid = leave(emp, d(5), d(7))
pg = inbox(S['mona'])
check('off by default: no My approvals page and no menu item; the Leave page as before', 'data-apv ' not in pg and 'ews_view=approvals' not in pg and 'data-apv-link' not in S['mona'].req('/app/?ews_view=vacation')[1])
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
check('...the switch is on the Features page, off', 'name="my_approvals_enabled"' in page and 'My approvals and My requests' in page)
php("update_option('ews_feature_my_approvals',1,false);")

# ---------------------------------------------------------------- the inbox
save('overtime', 'LEVEL_1', level_1_type='SUPERVISOR')
save('attendance_correction', 'LEVEL_1', level_1_type='SUPERVISOR')
n = emp.nonce('ews_overtime_request_create', 'overtime')
emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(2), start_time='18:00', end_time='21:00', reason='month end')
oid = int(q("SELECT id FROM {p}ews_overtime_requests ORDER BY id DESC LIMIT 1")[0]['id'])
cid = int(php("""$wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>%d,'work_date'=>'%s','type'=>'out','sign_out_at'=>'%s 17:30:00','reason'=>'forgot','status'=>'pending','source'=>'employee','requested_by'=>%d,'requested_at'=>current_time('mysql')]);echo $wpdb->insert_id;""" % (ids['emp'], d(-1), d(-1), ids['emp_uid'])).splitlines()[-1])
check('a correction started on the engine', not start_approval('attendance_correction', 'attendance_correction', cid, ids['emp']).startswith('ERR'))
save('shift_swap', 'LEVEL_1', level_1_type='SUPERVISOR')
sid = int(php("""$wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%d,'target_employee_id'=>%d,'work_date'=>'%s','requester_status'=>'Office','target_status'=>'WFH','status'=>'Awaiting']);echo $wpdb->insert_id;""" % (ids['sami'], ids['emp'], d(4))).splitlines()[-1])
check('a swap waiting for its approver (the colleague accepted)', not start_approval('shift_swap', 'schedule_swap', sid, ids['sami']).startswith('ERR'))

pg = inbox(S['mona'])
its = items(pg)
la, _ = ar_id('leave', lid)
oa, _ = ar_id('overtime_request', oid)
ca, _ = ar_id('attendance_correction', cid)
sa, _ = ar_id('schedule_swap', sid)
check('Mona\'s inbox has the four requests waiting for her: leave, overtime, correction, swap', sorted(int(i) for i, _ in its) == sorted([la, oa, ca, sa]), [i for i, _ in its])
lv = dict(its).get(str(la), '')
check('...the leave: who, what, "Level 1 of 2", waiting in working hours', 'tone-leave' in pg and 'Level 1 of 2' in lv and 'Waiting 0 working h' in lv and '3 days' in lv and 'Annual Leave' in lv, lv[:600])
check('...its details: the balance after, the reason and the path (Mona now, Big Boss next)', 'Balance after' in lv and 'family trip' in lv and re.search(r'is-now.*?Direct manager · Mona Manager.*?is-next.*?Manager&#039;s manager · Big Boss', lv, re.S))
check('...the KPIs and the filters (only the kinds present)', '<b>4</b><span>Waiting for you</span>' in pg and 'apv_kind=leave' in pg and 'apv_kind=swap' in pg and 'apv_kind=face' not in pg)
check('...a filter shows one kind', [int(i) for i, _ in items(inbox(S['mona'], '&apv_kind=overtime'))] == [oa])
check('emp1 has nothing to decide: no My approvals in the menu, but My requests', 'ews_view=approvals' not in emp.req('/app/')[1] and 'ews_view=requests' in emp.req('/app/')[1])
home = S['mona'].req('/app/')[1]
check('Mona\'s menu shows the count, and the home card says 4 wait', re.search(r'ews_view=approvals.*?wfo-nav-count[^>]*>4<', home, re.S) and '4 requests wait for your decision' in home)
check('...the Leave page links to the inbox above its own list', 'data-apv-link' in S['mona'].req('/app/?ews_view=vacation')[1])

# ---------------------------------------------------------------- deciding here, through the request's own handler
f = form_of(pg, la)
f['decision'] = 'approve'
st, body, h = S['mona'].req('/wp-admin/admin-post.php', f)
loc = h.get('Location', '')
check('approving the leave in the inbox comes back to the inbox', 'ews_view=approvals' in loc and 'leave_done=1' in loc, loc)
pg = inbox(S['mona'])
check('...it moved to level 2 (Big Boss) and left Mona\'s inbox', ar_id('leave', lid)[1] == 'WAITING_FOR_LEVEL_2' and la not in [int(i) for i, _ in items(pg)])
bpg = inbox(S['big'])
check('...Big Boss sees it, "Level 2 of 2", with Mona\'s approval in the path', la in [int(i) for i, _ in items(bpg)] and 'Level 2 of 2' in dict(items(bpg))[str(la)] and 'Mona Manager approved' in dict(items(bpg))[str(la)])

save('overtime', 'LEVEL_1', level_1_type='SUPERVISOR', reject_note=1)
f = form_of(pg, oa); f['decision'] = 'reject'
st, body, h = S['mona'].req('/wp-admin/admin-post.php', f)
check('a reason is needed to reject overtime (the new setting): refused without one', 'overtime_error=note' in h.get('Location', '') and ar_id('overtime_request', oid)[1] == 'WAITING_FOR_LEVEL_1', h.get('Location'))
check('...the inbox marks the reason as needed', 'data-note-required' in re.search(r'data-apv-item="%d">.*?</form>' % oa, inbox(S['mona']), re.S).group(0))
f['note'] = 'Not this month'
st, body, h = S['mona'].req('/wp-admin/admin-post.php', f)
check('...with a reason it is rejected, the reason kept', ar_id('overtime_request', oid)[1] == 'REJECTED' and q("SELECT status FROM {p}ews_overtime_requests WHERE id=%d" % oid)[0]['status'] == 'Rejected'
      and q("SELECT comment FROM {p}ews_approval_steps WHERE approval_request_id=%d" % oa)[0]['comment'] == 'Not this month')
php("$o=get_option('ews_approval_reject_note',[]); unset($o['attendance_correction']); update_option('ews_approval_reject_note',$o,false);")  # never saved: the default
st, page, _ = adm.req(PAGE)
check('...the setting shows on the card, Corrections need one by default, Leave does not', 'name="reject_note" value="1" checked' in card(page, 'overtime') and 'name="reject_note" value="1" checked' in card(page, 'attendance_correction') and 'name="reject_note" value="1" checked' not in card(page, 'vacation'))

f = form_of(inbox(S['mona']), ca); f['decision'] = 'reject'; f['note'] = 'No evidence'
S['mona'].req('/wp-admin/admin-post.php', f)
check('the correction is rejected from the inbox (its own handler, its own note rule)', q("SELECT status,decision_note FROM {p}ews_attendance_corrections WHERE id=%d" % cid)[0] == {'status': 'rejected', 'decision_note': 'No evidence'})
f = form_of(inbox(S['mona']), sa); f['decision'] = 'reject'
st, body, h = S['mona'].req('/wp-admin/admin-post.php', f)
check('the swap is rejected from the inbox and comes back to it', q("SELECT status FROM {p}ews_schedule_swaps WHERE id=%d" % sid)[0]['status'] == 'Rejected' and 'ews_view=approvals' in h.get('Location', ''), h.get('Location'))

# ---------------------------------------------------------------- overdue, moved up, administrators
php("$wpdb->query($wpdb->prepare(\"UPDATE {$p}ews_approval_steps SET pending_since=%%s,escalations=1,escalated_from_wp_user_id=%d WHERE approval_request_id=%d AND status='PENDING'\",gmdate('Y-m-d H:i:s',current_time('timestamp')-3*DAY_IN_SECONDS)));" % (ids['mona_uid'], la))
php("update_option('ews_approval_overdue',['vacation'=>4],false);")
bpg = inbox(S['big'])
lv = dict(items(bpg)).get(str(la), '')
check('waiting more than the overdue time (4 working h): marked overdue, counted, first', 'is-late' in lv and 'Overdue · Waiting' in lv and '<b>1</b><span>Overdue</span>' in bpg and 'apv_kind=late' in bpg, lv[:500])
check('...and "Moved up from Mona Manager"', 'Moved up from Mona Manager' in lv)
check('...the home card says 1 overdue', '1 overdue' in S['big'].req('/app/')[1])

save('vacation', 'LEVEL_1', level_1_type='SUPERVISOR')
blid = leave(S['big'], d(9))
ba, _ = ar_id('leave', blid)
apg = inbox(adm)
check('Big Boss has no manager: his leave waits for administrators, in their inbox, marked', ba in [int(i) for i, _ in items(apg)] and 'For administrators' in dict(items(apg))[str(ba)])
check('...not in Mona\'s', ba not in [int(i) for i, _ in items(inbox(S['mona']))])
f = form_of(apg, ba); f['decision'] = 'approve'
adm.req('/wp-admin/admin-post.php', f)
check('...an administrator approves it there', ar_id('leave', blid)[1] == 'APPROVED' and q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % blid)[0]['status'] == 'Approved')

# ---------------------------------------------------------------- several at once (the same forms, one after the other)
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_requests WHERE employee_id=%d\"); $wpdb->query(\"UPDATE {$p}ews_leave_balances SET pending=0,used=0\");" % ids['emp'])
three = [leave(emp, d(20 + i)) for i in range(3)]
pg = inbox(S['mona'])
check('three leave requests wait; the page has the Select bar and the bulk script', all(ar_id('leave', x)[0] in [int(i) for i, _ in items(pg)] for x in three) and 'data-apv-select' in pg and 'approvals.js' in pg)
for x in three:
    f = form_of(inbox(S['mona']), ar_id('leave', x)[0]); f['decision'] = 'approve'
    S['mona'].req('/wp-admin/admin-post.php', f)
check('...approving them one after the other works (what "Approve the selected" sends)', all(q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % x)[0]['status'] == 'Approved' for x in three))
check('...the result message', '3 requests approved.' in inbox(S['mona'], '&apv_bulk=3&apv_asked=3') and '1 could not be approved' in inbox(S['mona'], '&apv_bulk=2&apv_asked=3'))

# ---------------------------------------------------------------- My requests
save('vacation', 'LEVEL_2', level_1_type='SUPERVISOR', level_2_type='MANAGERS_MANAGER')
wl = leave(emp, d(30), d(31))
n = emp.nonce('ews_overtime_request_create', 'overtime')
emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(3), start_time='18:00', end_time='19:00', reason='x')
wo = int(q("SELECT id FROM {p}ews_overtime_requests ORDER BY id DESC LIMIT 1")[0]['id'])
mine = emp.req('/app/?ews_view=requests')[1]
check('My requests lists the leave, overtime, correction and their states', 'data-req="leave-%d"' % wl in mine and 'data-req="overtime-%d"' % wo in mine and 'data-req="overtime-%d"' % oid in mine and 'data-req="correction-%d"' % cid in mine)
blk = re.search(r'data-req="leave-%d".*?</article>' % wl, mine, re.S).group(0)
check('...the waiting leave: "Waiting · level 1 of 2", Mona deciding now, Big Boss next, Withdraw', 'Waiting · level 1 of 2' in blk and 'Mona Manager' in blk and 'Big Boss' in blk and 'value="ews_request_withdraw"' in blk)
blk = re.search(r'data-req="overtime-%d".*?</article>' % oid, mine, re.S).group(0)
check('...the rejected overtime shows why, and cannot be withdrawn', 'Rejected' in blk and 'Not this month' in blk and 'ews_request_withdraw' not in blk)
pend = float(q("SELECT pending FROM {p}ews_leave_balances WHERE employee_id=%d" % ids['emp'])[0]['pending'])
m = re.search(r'data-req="leave-%d".*?name="_wpnonce" value="([^"]+)"' % wl, mine, re.S)
st, qs, _ = emp.post('ews_request_withdraw', _wpnonce=m.group(1), kind='leave', id=wl)
check('withdrawing the waiting leave: cancelled, its 2 days no longer reserved, its approval closed', qs.get('req_done') == 'leave' and q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % wl)[0]['status'] == 'Cancelled'
      and float(q("SELECT pending FROM {p}ews_leave_balances WHERE employee_id=%d" % ids['emp'])[0]['pending']) == pend - 2 and ar_id('leave', wl)[1] == 'CANCELLED', (qs, pend))
check('...and gone from Mona\'s inbox', ar_id('leave', wl)[0] not in [int(i) for i, _ in items(inbox(S['mona']))])
check('...the Audit Log has it', q("SELECT id FROM {p}ews_audit_log WHERE action='leave_withdrawn' AND entity_id=%d" % wl))
st, qs, _ = emp.post('ews_request_withdraw', _wpnonce=m.group(1), kind='leave', id=wl)
check('...twice: "already decided"', qs.get('req_error') == 'done', qs)
mine = emp.req('/app/?ews_view=requests')[1]
m = re.search(r'data-req="overtime-%d".*?name="_wpnonce" value="([^"]+)"' % wo, mine, re.S)
st, qs, _ = S['mona'].post('ews_request_withdraw', _wpnonce=m.group(1), kind='overtime', id=wo)
check('someone else cannot withdraw it', q("SELECT status FROM {p}ews_overtime_requests WHERE id=%d" % wo)[0]['status'] == 'Pending')
st, qs, _ = emp.post('ews_request_withdraw', _wpnonce=m.group(1), kind='overtime', id=wo)
check('withdrawing the waiting overtime', qs.get('req_done') == 'overtime' and q("SELECT status FROM {p}ews_overtime_requests WHERE id=%d" % wo)[0]['status'] == 'Cancelled' and ar_id('overtime_request', wo)[1] == 'CANCELLED')
check('...shown as Withdrawn', 'Withdrawn' in re.search(r'data-req="overtime-%d".*?</article>' % wo, emp.req('/app/?ews_view=requests')[1], re.S).group(0))

php("""foreach(['ews_feature_my_approvals','ews_approval_reject_note','ews_approval_overdue'] as $o) delete_option($o); update_option('ews_feature_overtime',0,false); update_option('ews_feature_corrections',0,false);
$wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
foreach(['ews_leave_requests','ews_overtime_requests','ews_attendance_corrections','ews_schedule_swaps','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

"""Shift swaps in My approvals (3.31.124, docs/tasks/swap-inbox.md, mockups docs/mockups/swap-inbox/).

With My approvals on, a swap a colleague asked for waits in My approvals (accept / decline), whatever week its day is in; the
notification opens it there; the Schedule page lists no requests (one line to My approvals, one to My requests); My requests shows
the swap with where it stands and withdraws it while the colleague has not answered (the colleague is told). With My approvals off
nothing changes (tests/e2e_swap.py, tests/e2e_schedule_view.py).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_swap_inbox.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, today, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))


def day(n):
    return (today + datetime.timedelta(days=n)).isoformat()


NEXT, LATER, PAST = day(8), day(9), day(-2)
ids = json.loads(php("""
    foreach(['ews_schedule_swaps','ews_approval_requests','ews_approval_steps','ews_notifications','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    update_option('ews_feature_my_approvals',1,false); update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET active=0 WHERE workflow_key='shift_swap'");
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE code='si-e2e'");
    $wpdb->insert($p.'ews_departments',['name'=>'SI','code'=>'si-e2e','active'=>1]); $d=(int)$wpdb->insert_id;
    $mk=function($l,$n)use($wpdb,$p,$d){$u=username_exists($l)?:wp_create_user($l,$l.'pass',$l.'@example.com');(new WP_User($u))->set_role('subscriber');
        $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='$l'");
        $wpdb->insert($p.'ews_employees',['name'=>$n,'domain_name'=>$l,'email'=>$l.'@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>1,'department_id'=>$d]);return [(int)$wpdb->insert_id,(int)$u];};
    [$peer,$peer_u]=$mk('sipeer','Mona Adel'); [$appr,$appr_u]=$mk('siappr','Heba Farouk'); [$other,$other_u]=$mk('siother','Sara Ali');
    $e1=(int)$wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE wp_user_id=".(int)get_user_by('login','emp1')->ID); $wpdb->update($p.'ews_employees',['department_id'=>$d],['id'=>$e1]);
    echo wp_json_encode(['e1'=>$e1,'peer'=>$peer,'peer_u'=>$peer_u,'appr'=>$appr,'other'=>$other,'e1_u'=>(int)get_user_by('login','emp1')->ID]);
""").splitlines()[-1])
E1, PEER = ids['e1'], ids['peer']
emp, peer, appr, other = Session('emp1', 'emp1pass'), Session('sipeer', 'sipeerpass'), Session('siappr', 'siapprpass'), Session('siother', 'siotherpass')


def set_day(eid, d, status):
    php("$wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (eid, d, eid, d, status))


def sched(eid, d):
    r = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (eid, d))
    return r[0]['status'] if r else None


def swap(sid):
    r = q("SELECT status FROM {p}ews_schedule_swaps WHERE id=%d" % sid)
    return r[0]['status'] if r else None


WD = """$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'swap_withdraw'); $m->setAccessible(true); $r=$m->invoke($o,%d,%d); echo $r===true?'true':$r;"""
NONCE = []


def ask(d):
    """emp1 (Office) asks Mona (WFH) to swap day d, as the New request sheet does."""
    set_day(E1, d, 'Office'); set_day(PEER, d, 'WFH')
    if not NONCE:   # read once from the New request sheet; it stays valid with My approvals off, where that page is gone
        n = re.search(r'id="wfo-new-swap".*?name="_wpnonce" value="([^"]+)"', emp.req('/app/?ews_view=requests')[1], re.S)
        NONCE.append(n.group(1) if n else 'x')
    emp.post('ews_swap_create', _wpnonce=NONCE[0], ews_return='requests', target_employee_id=PEER, work_date=d)
    return int(q("SELECT MAX(id) id FROM {p}ews_schedule_swaps")[0]['id'] or 0)


def items(sess):
    return re.findall(r'<article class="wfo-apv-item[^"]*"[^>]*data-apv-item="([^"]+)"', sess.req('/app/?ews_view=approvals')[1])


def card(sess, sid):
    pg = sess.req('/app/?ews_view=approvals')[1]
    m = re.search(r'<article[^>]*data-apv-item="swap-%d".*?</article>' % sid, pg, re.S)
    return m.group(0) if m else ''


def answer(sess, sid, decision):
    c = card(sess, sid)
    n = re.search(r'name="_wpnonce" value="([^"]+)"', c)
    return sess.post('ews_swap_respond', _wpnonce=n.group(1) if n else 'x', ews_return='approvals', swap_id=sid, decision=decision)[1]


# ---------------------------------------------------------------- the colleague's inbox
S1 = ask(NEXT)
check('a swap for a day of a later week is sent', swap(S1) == 'Pending', swap(S1))
c = card(peer, S1)
check('the colleague finds it in My approvals, whatever its week', c != '', items(peer))
check('...a card to accept or decline, with what each one works that day', 'value="accept"' in c and 'value="reject"' in c and 'wfo-si-trade' in c and 'name="action" value="ews_swap_respond"' in c, c[:400])
check('...never in "Approve the selected", and no approval level', 'data-apv-nobulk' in c and 'data-apv-pick' not in c and 'approval_step' not in c)
check('...no "goes to the approver" hint while the colleague alone decides', 'After you accept' not in c)
check('somebody else does not see it', 'swap-%d' % S1 not in items(other) and card(emp, S1) == '')
home = peer.req('/app/?ews_view=dashboard')[1]
check('the home card counts it', re.search(r'data-home-approvals><span>1 request', home) is not None)
note = q("SELECT link link_url,title FROM {p}ews_notifications WHERE user_id=%d ORDER BY id DESC LIMIT 1" % ids['peer_u'])
check('the colleague\'s notification opens My approvals on it', note and 'ews_view=approvals' in note[0]['link_url'] and 'apv_swap=%d' % S1 in note[0]['link_url'] and note[0]['title'] == 'Swap request', note)

# ---------------------------------------------------------------- the Schedule page
pg = peer.req('/app/?ews_view=schedule')[1]
check('the colleague\'s Schedule page: one line to My approvals, no request list', 'data-swap-inbox-answer' in pg and 'ews_swap_respond' not in pg and 'ews-swap-panel' not in pg)
check('...the line counts it and opens My approvals on swaps', re.search(r'1 swap request waits for your answer', pg) is not None and 'apv_kind=swap' in pg)
pg = emp.req('/app/?ews_view=schedule')[1]
check('the requester\'s Schedule page: one line to My requests', 'data-swap-inbox-mine' in pg and '1 swap you asked for is waiting' in pg and 'req_state=waiting' in pg and 'ews_swap_cancel' not in pg)
check('...and no general "All your approvals" link there any more', 'data-apv-link' not in pg)

# ---------------------------------------------------------------- My requests
pg = emp.req('/app/?ews_view=requests')[1]
m = re.search(r'<article[^>]*data-req="swap-%d".*?</article>' % S1, pg, re.S)
r = m.group(0) if m else ''
check('My requests: waiting for the colleague\'s answer, with withdraw', 'waiting for their answer' in r and 'name="kind" value="swap"' in r, r[:500])

# ---------------------------------------------------------------- accept (colleague alone)
n1 = re.search(r'name="_wpnonce" value="([^"]+)"', card(peer, S1)).group(1)
qs = answer(peer, S1, 'accept')
check('accepting in My approvals: back there, the days exchanged', qs.get('ews_view') == 'approvals' and qs.get('swap_done') == 'accepted' and swap(S1) == 'Accepted' and sched(E1, NEXT) == 'WFH' and sched(PEER, NEXT) == 'Office', (qs, swap(S1)))
check('...the card is gone', card(peer, S1) == '')
qs = peer.post('ews_swap_respond', _wpnonce=n1, ews_return='approvals', swap_id=S1, decision='accept')[1]
check('...the same answer posted again changes nothing', qs.get('swap_error') == 'not_found' and sched(E1, NEXT) == 'WFH', qs)
pg = peer.req('/app/?ews_view=approvals&swap_done=accepted')[1]
check('...and the page says what happened', 'You accepted: both schedules are updated.' in pg)
note = q("SELECT link link_url FROM {p}ews_notifications WHERE user_id=%d ORDER BY id DESC LIMIT 1" % ids['e1_u'])
check('the requester\'s note opens My requests', note and 'ews_view=requests' in note[0]['link_url'], note)

# ---------------------------------------------------------------- decline, and a page from before
S2 = ask(LATER)
qs = answer(peer, S2, 'reject')
check('declining: rejected, back on My approvals', qs.get('swap_done') == 'rejected' and swap(S2) == 'Rejected', qs)
pg = emp.req('/app/?ews_view=requests')[1]
check('My requests: "they declined"', 'they declined' in pg)

# ---------------------------------------------------------------- withdraw (owner decision: only while the colleague has not answered)
S3 = ask(day(10))
n = re.search(r'name="_wpnonce" value="([^"]+)"(?:(?!</form>).)*?name="kind" value="swap"><input type="hidden" name="id" value="%d"' % S3, emp.req('/app/?ews_view=requests')[1], re.S)
_, qs, _ = emp.post('ews_request_withdraw', _wpnonce=n.group(1) if n else 'x', kind='swap', id=S3)
check('withdrawn from My requests: cancelled and audited', qs.get('req_done') == 'swap' and swap(S3) == 'Cancelled' and q("SELECT id FROM {p}ews_audit_log WHERE action='SCHEDULE_SWAP_CANCELLED' AND entity_id=%d" % S3), qs)
told = q("SELECT title FROM {p}ews_notifications WHERE user_id=%d ORDER BY id DESC LIMIT 1" % ids['peer_u'])
check('...the colleague is told', told and told[0]['title'] == 'Swap request withdrawn', told)
pg = peer.req('/app/?ews_view=approvals&apv_swap=%d' % S3)[1]
check('...their notification opened afterwards says it was withdrawn', 'it was withdrawn' in pg)
qs = answer(peer, S3, 'accept')
check('...and a card from before cannot accept it', swap(S3) == 'Cancelled', qs)

# ---------------------------------------------------------------- with an approver after the colleague
out = php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod('EWS_Manager_V31_1','approval_configure_workflow'); $m->setAccessible(true);
    $r=$m->invoke($o,'shift_swap','LEVEL_1',[1=>['resolver_type'=>'SPECIFIC_EMPLOYEE','resolver_value'=>%d]],\\WorkforceOne\\Approvals\\Workflows::IN_USE['shift_swap'][1]); echo is_wp_error($r)?$r->get_error_message():'ok';""" % ids['appr'])
check('the approver is set on the Shift Swap type', out.strip().endswith('ok'), out)
S4 = ask(day(11))
c = card(peer, S4)
check('with an approver: the card says it goes to the approver after', 'After you accept, it goes to the approver.' in c)
qs = answer(peer, S4, 'accept')
check('...accepted: it waits for the approver', qs.get('swap_done') == 'awaiting' and swap(S4) == 'Awaiting', qs)
check('...and the approver finds it in My approvals', 'swap-%d' % S4 not in items(appr) and re.search(r'name="swap_id" value="%d"' % S4, appr.req('/app/?ews_view=approvals')[1]) is not None)
pg = emp.req('/app/?ews_view=requests')[1]
m = re.search(r'<article[^>]*data-req="swap-%d".*?</article>' % S4, pg, re.S)
check('...My requests: they accepted, no withdraw any more (owner decision)', m and 'they accepted' in m.group(0) and 'name="kind" value="swap"' not in m.group(0), m.group(0)[:400] if m else pg[:200])
note = q("SELECT link link_url FROM {p}ews_notifications WHERE user_id=(SELECT wp_user_id FROM {p}ews_employees WHERE id=%d) ORDER BY id DESC LIMIT 1" % ids['appr'])
check('...the approver\'s note opens My approvals', note and 'ews_view=approvals' in note[0]['link_url'], note)
pg = appr.req('/app/?ews_view=approvals&apv_swap=%d' % S4)[1]
check('...opened from it, the approver\'s card is marked and nothing says it no longer waits', re.search(r'<article class="wfo-apv-item[^"]*is-focus[^"]*"[^>]*id="apv-swap-%d"' % S4, pg) is not None and 'no longer waiting' not in pg)
check('...an accepted swap can no longer be withdrawn (owner decision)', php(WD % (S4, E1)).splitlines()[-1] == 'done' and swap(S4) == 'Awaiting')
S8 = ask(day(14))
set_day(PEER, day(14), 'Office')   # Mona's day changed since: a page from before posts Accept anyway
qs = answer(peer, S8, 'accept')
check('...a day changed since: Accept posted anyway is refused before it reaches the approver', qs.get('swap_error') == 'changed' and swap(S8) == 'Pending', (qs, swap(S8)))
php("$wpdb->query(\"UPDATE {$p}ews_approval_workflows SET active=0 WHERE workflow_key='shift_swap'\");")

# ---------------------------------------------------------------- a changed day, a past day
S5 = ask(day(12))
set_day(PEER, day(12), 'Office')   # Mona's day changed since
c = card(peer, S5)
check('a day changed since the request: only Decline, and it says why', c and 'value="accept"' not in c and 'value="reject"' in c and 'you can only decline it' in c, c[:400])
S6 = php("$wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%d,'target_employee_id'=>%d,'work_date'=>'%s','requester_status'=>'Office','target_status'=>'WFH','status'=>'Pending','requested_by'=>1,'created_at'=>current_time('mysql')]); echo $wpdb->insert_id;" % (E1, PEER, PAST)).splitlines()[-1]
check('a swap whose day passed is not in My approvals', card(peer, int(S6)) == '')
S9 = ask(day(15))
check('somebody else cannot withdraw a swap', php(WD % (S9, ids['other'])).splitlines()[-1] == 'done' and swap(S9) == 'Pending')
php("$wpdb->update($p.'ews_employees',['active'=>0],['id'=>%d]);" % E1)
check('a swap whose requester was deactivated is left out of My approvals', card(peer, S9) == '')
php("$wpdb->update($p.'ews_employees',['active'=>1],['id'=>%d]);" % E1)
# Queries: My approvals' list costs the same with 1 or 5 swaps waiting (no query per swap).
cost = php("""$p=$wpdb->prefix; $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule_swaps WHERE status='Pending' AND work_date>=%%s",gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +15 days')))); wp_set_current_user(%d); $n=[];
    foreach([1,5] as $k){ for($i=count($n)?1:0;$i<$k;$i++) $wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%d,'target_employee_id'=>%d,'work_date'=>gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +'.(30+$i).' days')),'requester_status'=>'Office','target_status'=>'WFH','status'=>'Pending','requested_by'=>1,'created_at'=>current_time('mysql')]);
      $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'my_approvals_items'); $m->setAccessible(true); $m->invoke($o); $o=new EWS_Manager_V31_1();
      $q=$wpdb->num_queries; $c=count($m->invoke($o)); $n[]=($wpdb->num_queries-$q).':'.$c; }
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule_swaps WHERE status='Pending' AND work_date>=%%s",gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +15 days')))); echo implode(',',$n);""" % (ids['peer_u'], E1, PEER)).splitlines()[-1]
a, b = [x.split(':') for x in cost.split(',')]
check('My approvals\' list: as many queries with 5 swaps waiting as with 1', int(b[1]) - int(a[1]) == 4 and a[0] == b[0], cost)
check('...and shows as Expired in My requests, without withdraw', re.search(r'data-req="swap-%s".*?is-expired' % S6, emp.req('/app/?ews_view=requests')[1], re.S) is not None)

# ---------------------------------------------------------------- off
php("delete_option('ews_feature_my_approvals');")
S7 = ask(day(13))
check('My approvals off: the swap is still sent', swap(S7) == 'Pending', S7)
note = q("SELECT link link_url,title FROM {p}ews_notifications WHERE user_id=%d ORDER BY id DESC LIMIT 1" % ids['peer_u'])
check('My approvals off: the notification opens the Schedule page, with its text as before', note and 'ews_view=schedule' in note[0]['link_url'] and note[0]['title'] == 'Schedule Swap Request', note)
pg = peer.req('/app/?ews_view=schedule&week=' + day(13))[1]
check('...and the Schedule page keeps its swap panel', 'data-swap-inbox-answer' not in pg and re.search(r'name="swap_id" value="%d"' % S7, pg) is not None)

php("$wpdb->query(\"DELETE FROM {$p}ews_schedule_swaps\"); $dep=(int)$wpdb->get_var(\"SELECT id FROM {$p}ews_departments WHERE code='si-e2e'\"); $wpdb->query(\"UPDATE {$p}ews_employees SET department_id=NULL WHERE department_id=$dep\"); $wpdb->query(\"DELETE FROM {$p}ews_departments WHERE id=$dep\");")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

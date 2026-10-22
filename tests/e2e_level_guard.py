"""A decision applies only to the approval level the person saw (3.31.122, docs/tasks/approval-level-guard.md).

Every form scraped here is the real page's form, so a page that forgets to post `approval_step` fails the test. On a two-level
workflow: a repeat of the same decision (double click, a resubmitted page, two administrators, a page opened before another
approver acted) is refused with "moved", level 2 stays open and the request itself is untouched; a fresh page decides level 2;
a form without the field decides the current level, as before (owner decision). Leave, leave cancellation, overtime, swaps,
attendance corrections (engine levels and HR's stage, the app API), face resets, My approvals (one card and the bulk count), and
two decisions at the same moment (forced in the database).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_level_guard.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import atexit, datetime, html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

HUB = '/wp-admin/admin.php?page=ews31-requests'

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
D1 = (today - datetime.timedelta(days=1)).isoformat()
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_overtime_requests','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps',
             'ews_employee_relationships','ews_audit_log','ews_notifications','ews_schedule_swaps','ews_attendance_corrections','ews_time_logs','ews_schedule','ews_substitutes'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_approval_allow_self','ews_approval_self_rule','ews_approval_deadlines','ews_approval_settings','ews_face_reset_requests','ews_correction_settings'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'10:00','end'=>'16:00'],false);
    foreach(['ews_feature_overtime','ews_feature_corrections','ews_feature_my_approvals'] as $o) update_option($o,1,false);
    update_option('ews_correction_settings',['monthly_limit'=>20],false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('lgmona','lgbig','lgpeer','lghr')");
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID];
    foreach(['lgmona'=>['Mona Manager','ews_manage_time'],'lgbig'=>['Big Boss',''],'lgpeer'=>['Peer Colleague',''],'lghr'=>['Hana HR','ews_manage_corrections']] as $login=>[$name,$cap]){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        $u=new WP_User($uid);$u->set_role('subscriber');if($cap)$u->add_cap($cap);
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE code='lg-e2e'");
    $wpdb->insert($p.'ews_departments',['name'=>'Level Guard E2E','code'=>'lg-e2e','active'=>1]); $dep=(int)$wpdb->insert_id;
    $wpdb->query("UPDATE {$p}ews_employees SET department_id=$dep WHERE id=$eid OR domain_name='lgpeer'");
    $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0 WHERE id=$type");
    $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>0]);
    $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y')+1,'entitlement'=>21,'used'=>0,'pending'=>0]);
    $out['type']=$type;
    echo wp_json_encode($out);
""").splitlines()[-1])
E = ids['emp']
for who, sup in ((E, ids['lgmona']), (ids['lgmona'], ids['lgbig'])):
    php("$wpdb->insert($p.'ews_employee_relationships',['employee_id'=>%d,'relationship_type'=>'supervisor','related_employee_id'=>%d,'active'=>1]);" % (who, sup))

adm, emp, mona, big, peer, hr = (Session('admin', 'admin'), Session('emp1', 'emp1pass'), Session('lgmona', 'lgmonapass'), Session('lgbig', 'lgbigpass'),
                                 Session('lgpeer', 'lgpeerpass'), Session('lghr', 'lghrpass'))


def configure(key, mode, *levels):
    """levels: (resolver_type, resolver_value) per level."""
    steps = '[' + ','.join("%d=>['resolver_type'=>'%s','resolver_value'=>%d]" % (i + 1, t, v) for i, (t, v) in enumerate(levels)) + ']'
    out = php("""$o=new EWS_Manager_V31_1(); $m=new ReflectionMethod('EWS_Manager_V31_1','approval_configure_workflow'); $m->setAccessible(true);
    $r=$m->invoke($o,'%s','%s',%s,\\WorkforceOne\\Approvals\\Workflows::IN_USE['%s'][1]); echo is_wp_error($r)?$r->get_error_message():'ok';""" % (key, mode, steps, key))
    assert out.strip().endswith('ok'), out


TWO = (('SUPERVISOR', 0), ('MANAGERS_MANAGER', 0))
for key in ('vacation', 'overtime', 'attendance_correction', 'face_reset'):
    configure(key, 'LEVEL_2', *TWO)
configure('shift_swap', 'LEVEL_2', ('SPECIFIC_EMPLOYEE', ids['lgmona']), ('SPECIFIC_EMPLOYEE', ids['lgbig']))


def forms(page):
    """Every form of a page: {field: value} of its hidden inputs (and the decision button values under '_buttons')."""
    out = []
    for f in re.findall(r'<form.*?</form>', page, re.S):
        fields = {}
        for tag in re.findall(r'<input\b[^>]*>', f):
            if 'type="hidden"' not in tag:
                continue
            n, v = re.search(r'name="([^"]+)"', tag), re.search(r'value="([^"]*)"', tag)
            if n:
                fields[html.unescape(n.group(1))] = html.unescape(v.group(1)) if v else ''
        fields['_buttons'] = re.findall(r'name="decision" value="([^"]+)"', f)
        out.append(fields)
    return out


def form(sess, path, **match):
    st, page, _ = sess.req(path)
    for f in forms(page):
        if all(f.get(k) == str(v) for k, v in match.items()):
            return f
    return None


def send(sess, f, decision, drop=(), **extra):
    data = {k: v for k, v in f.items() if k != '_buttons' and k not in drop}
    if 'decision' not in data or decision:
        data['decision'] = decision
    data.update(extra)
    return sess.post(data.pop('action'), **data)[1]


def engine(entity, entity_id):
    ar = q("SELECT id,status FROM {p}ews_approval_requests WHERE entity_type='%s' AND entity_id=%d ORDER BY id DESC LIMIT 1" % (entity, entity_id))
    if not ar:
        return None, []
    return ar[0]['status'], [(int(r['step_order']), r['status']) for r in q("SELECT step_order,status FROM {p}ews_approval_steps WHERE approval_request_id=%s ORDER BY step_order" % ar[0]['id'])]


def hub(rtype, rid):
    return form(adm, HUB, action='ews31_requests_decision', request_type=rtype, request_id=rid, decision='approve')


AT2 = ('WAITING_FOR_LEVEL_2', [(1, 'APPROVED'), (2, 'PENDING')])


# ---------------------------------------------------------------- leave on the Requests page
def new_leave(start):
    n = emp.nonce('ews_vacation_request_create')
    emp.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=start, reason='x')
    return int(q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s' ORDER BY id DESC LIMIT 1" % start)[0]['id'])


def leave_status(lid):
    return q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lid)[0]['status']


L1 = new_leave(d(30))
f = hub('leave', L1)
check('Requests page: each decision form sends the level it shows', f is not None and f.get('approval_step', '').isdigit() and int(f['approval_step']) > 0, f)
notes_before = len(q("SELECT id FROM {p}ews_notifications"))
qs = send(adm, f, 'approve')
check('...approving level 1 moves it to level 2', qs.get('request_notice') == 'advanced' and engine('leave', L1) == AT2, (qs, engine('leave', L1)))
notes_after = len(q("SELECT id FROM {p}ews_notifications"))
qs = send(adm, f, 'approve')
check('...the same form sent again (double click, resubmit): "moved", level 2 still waits, the leave still pending',
      qs.get('request_notice') == 'moved' and engine('leave', L1) == AT2 and leave_status(L1) == 'Pending', (qs, engine('leave', L1), leave_status(L1)))
check('...and nobody is told anything more', len(q("SELECT id FROM {p}ews_notifications")) == notes_after, (notes_before, notes_after))
st, page, _ = adm.req(HUB + '&request_notice=moved')
check('...the page says it moved and that nothing was changed, as an error', 'moved to another level since you opened the page; nothing was changed' in page and 'notice-error' in page)
qs = send(adm, f, 'reject')
check('...a stale Reject is refused the same way', qs.get('request_notice') == 'moved' and engine('leave', L1) == AT2)
qs = send(adm, hub('leave', L1), 'approve')
check('...a fresh page decides level 2: approved', qs.get('request_notice') == 'approved' and leave_status(L1) == 'Approved' and engine('leave', L1)[0] == 'APPROVED', (qs, leave_status(L1)))

L2 = new_leave(d(31))
f = hub('leave', L2)
send(adm, f, 'approve')
qs = send(adm, f, 'approve', drop=('approval_step',))
check('a form without the level (a page opened before the update) decides the current level, as before (owner decision)',
      qs.get('request_notice') == 'approved' and leave_status(L2) == 'Approved', (qs, leave_status(L2)))

# ---------------------------------------------------------------- leave in the app
L3 = new_leave(d(32))
f_hub = hub('leave', L3)
f_mona = form(mona, '/app/?ews_view=vacation', action='ews_vacation_request_respond', request_id=L3)
check('Leave page: the approver\'s form sends its level too', f_mona is not None and f_mona.get('approval_step') == f_hub.get('approval_step'), (f_mona, f_hub))
qs = send(mona, f_mona, 'approve')
check('...level 1 approves in the app: level 2 waits for the manager\'s manager', engine('leave', L3) == AT2, (qs, engine('leave', L3)))
qs = send(mona, f_mona, 'approve')
check('...the level 1 approver sends it again: "moved" (it was "Access denied")', qs.get('leave_error') == 'moved' and engine('leave', L3) == AT2, qs)
qs = send(adm, f_hub, 'approve')
check('...an administrator\'s page opened before that approval: "moved", level 2 not decided', qs.get('request_notice') == 'moved' and engine('leave', L3) == AT2 and leave_status(L3) == 'Pending', qs)
f_big = form(big, '/app/?ews_view=vacation', action='ews_vacation_request_respond', request_id=L3)
qs = send(big, f_big, 'approve')
check('...the level 2 approver decides it from their page', leave_status(L3) == 'Approved', (qs, leave_status(L3)))
st, page, _ = mona.req('/app/?ews_view=vacation&leave_error=moved')
check('...the app explains it (translated message)', 'moved to another approval level since you opened the page' in page)

# The same person at both levels: their second post must not approve level 2 with a page that showed level 1.
configure('vacation', 'LEVEL_2', ('SUPERVISOR', 0), ('SPECIFIC_USER', ids['lgmona_uid']))
L4 = new_leave(d(33))
f = form(mona, '/app/?ews_view=vacation', action='ews_vacation_request_respond', request_id=L4)
send(mona, f, 'approve')
qs = send(mona, f, 'approve')
check('the same approver at both levels, the form sent twice: only level 1 is decided', qs.get('leave_error') == 'moved' and engine('leave', L4) == AT2 and leave_status(L4) == 'Pending', (qs, engine('leave', L4)))
qs = send(mona, form(mona, '/app/?ews_view=vacation', action='ews_vacation_request_respond', request_id=L4), 'approve')
check('...their page opened again shows level 2, which they approve', leave_status(L4) == 'Approved', (qs, leave_status(L4)))

# My approvals: the card sends its level; the same card sent twice is refused.
L5 = new_leave(d(34))
f = form(mona, '/app/?ews_view=approvals', action='ews_vacation_request_respond', request_id=L5)
check('My approvals: the card sends the level it shows', f is not None and f.get('approval_step', '0') != '0', f)
send(mona, f, 'approve')
qs = send(mona, f, 'approve')
check('...sent twice: the second is "moved"', qs.get('leave_error') == 'moved' and engine('leave', L5) == AT2 and leave_status(L5) == 'Pending', (qs, engine('leave', L5)))
configure('vacation', 'LEVEL_2', *TWO)

# Leave cancellation.
L6 = new_leave(d(35))
for who in (mona, big):
    send(who, form(who, '/app/?ews_view=vacation', action='ews_vacation_request_respond', request_id=L6), 'approve')
emp.post('ews_leave_cancel', _wpnonce=emp.nonce('ews_leave_cancel'), request_id=L6)
f = hub('leave_cancellation', L6)
send(adm, f, 'approve')
qs = send(adm, f, 'approve')
st_ = q("SELECT status,cancellation_status FROM {p}ews_leave_requests WHERE id=%d" % L6)[0]
check('leave cancellation, Requests page sent twice: "moved", the leave not cancelled', qs.get('request_notice') == 'moved' and engine('leave_cancellation', L6) == AT2
      and st_['status'] == 'Approved' and st_['cancellation_status'] == 'Pending', (qs, engine('leave_cancellation', L6), st_))
f = form(big, '/app/?ews_view=vacation', action='ews_leave_cancel_respond', request_id=L6)
check('...the level 2 approver\'s cancellation form sends its level', f is not None and f.get('approval_step', '0') != '0', f)

# ---------------------------------------------------------------- overtime
def new_ot(day):
    out = php("wp_set_current_user(%d); $m=new EWS_Manager_V31_1(); $emp=$wpdb->get_row($wpdb->prepare(\"SELECT * FROM {$p}ews_employees WHERE id=%%d\",%d));"
              "$r=new ReflectionMethod($m,'overtime_create_locked'); $r->setAccessible(true); $x=$r->invoke($m,$emp,'%s','18:00','20:00','late'); echo wp_json_encode($x);" % (ids['emp_uid'], E, day))
    return int(json.loads(out.splitlines()[-1])['id'])


def ot_status(oid):
    return q("SELECT status FROM {p}ews_overtime_requests WHERE id=%d" % oid)[0]['status']


O1 = new_ot(d(5))
f = hub('overtime', O1)
send(adm, f, 'approve')
qs = send(adm, f, 'approve')
check('overtime, Requests page sent twice: "moved", level 2 still waits', qs.get('request_notice') == 'moved' and engine('overtime_request', O1) == AT2 and ot_status(O1) == 'Pending', (qs, engine('overtime_request', O1)))
f = form(big, '/app/?ews_view=overtime', action='ews_overtime_request_respond', request_id=O1, decision='approve')
check('...the Overtime page form sends its level', f is not None and f.get('approval_step') == hub('overtime', O1).get('approval_step'), f)
O2 = new_ot(d(6))
f = form(mona, '/app/?ews_view=overtime', action='ews_overtime_request_respond', request_id=O2, decision='approve')
send(mona, f, '')
qs = send(mona, f, '')
check('...the level 1 approver sends it again in the app: "moved"', qs.get('overtime_error') == 'moved' and engine('overtime_request', O2) == AT2, (qs, engine('overtime_request', O2)))
qs = send(big, form(big, '/app/?ews_view=overtime', action='ews_overtime_request_respond', request_id=O2, decision='approve'), '')
check('...level 2 approves from a fresh page', ot_status(O2) == 'Approved', (qs, ot_status(O2)))

# ---------------------------------------------------------------- shift swaps
def set_schedule(eid, status):
    php("$wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (eid, today, eid, today, status))


def new_swap():
    set_schedule(E, 'Office'); set_schedule(ids['lgpeer'], 'WFH')
    emp.post('ews_swap_create', _wpnonce=emp.nonce('ews_swap_create', view='schedule'), target_employee_id=ids['lgpeer'], work_date=today.isoformat())
    return int(q("SELECT MAX(id) id FROM {p}ews_schedule_swaps")[0]['id'])


def swap_status(sid):
    return q("SELECT status FROM {p}ews_schedule_swaps WHERE id=%d" % sid)[0]['status']


W1 = new_swap()
f_peer = hub('shift_swap', W1)
check('a swap the colleague has not answered: the Requests page shows no level (0)', f_peer is not None and f_peer.get('approval_step') == '0', f_peer)
peer.post('ews_swap_respond', _wpnonce=form(peer, '/app/?ews_view=schedule', action='ews_swap_respond', swap_id=W1)['_wpnonce'], swap_id=W1, decision='accept')
check('...the colleague accepts: it waits for level 1', swap_status(W1) == 'Awaiting' and engine('schedule_swap', W1)[0] == 'WAITING_FOR_LEVEL_1', engine('schedule_swap', W1))
qs = send(adm, f_peer, 'approve')
check('...the administrator\'s page from before (no level shown) is refused: "moved", not a direct exchange',
      qs.get('request_notice') == 'moved' and swap_status(W1) == 'Awaiting' and engine('schedule_swap', W1)[0] == 'WAITING_FOR_LEVEL_1', (qs, swap_status(W1)))
f = form(mona, '/app/?ews_view=schedule', action='ews_swap_decide', swap_id=W1)
check('...the approver\'s Schedule form sends its level', f is not None and f.get('approval_step', '0') != '0', f)
send(mona, f, 'approve')
qs = send(mona, f, 'approve')
check('...sent twice: "moved", the days not exchanged', qs.get('swap_error') == 'moved' and engine('schedule_swap', W1) == AT2 and swap_status(W1) == 'Awaiting', (qs, engine('schedule_swap', W1)))
f = hub('shift_swap', W1)
send(adm, f, 'approve')
qs = send(adm, f, 'approve')
check('...the Requests page decides level 2 once; sent again it is refused (the swap is done)', swap_status(W1) == 'Accepted' and qs.get('request_notice') in ('moved', 'error'), (qs, swap_status(W1)))

# ---------------------------------------------------------------- attendance corrections
def new_cx():
    php("""$wpdb->query("DELETE FROM {$p}ews_attendance_corrections"); $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date='%(d)s'"); $wpdb->delete($p.'ews_schedule',['employee_id'=>%(e)d,'work_date'=>'%(d)s']);
        $wpdb->insert($p.'ews_schedule',['employee_id'=>%(e)d,'work_date'=>'%(d)s','status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>%(e)d,'user_id'=>0,'work_date'=>'%(d)s','event_type'=>'sign_in','event_at'=>'%(d)s 08:52:00','scheduled_status'=>'Office','location_status'=>'inside','distance_meters'=>35,'integrity_status'=>'verified','source'=>'app']);""" % {'d': D1, 'e': E})
    out = php("""wp_set_current_user(%d); $m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'cx_create'); $r->setAccessible(true); $e=$wpdb->get_row("SELECT * FROM {$p}ews_employees WHERE id=%d");
        echo wp_json_encode($r->invoke($m,$e,['date'=>'%s','type'=>'out','target'=>'','time_in'=>'','time_out'=>'17:00','reason'=>'I forgot'],'employee'));""" % (ids['emp_uid'], E, D1))
    return int(json.loads(out.splitlines()[-1])['id'])


def cx_status(cid):
    return q("SELECT status FROM {p}ews_attendance_corrections WHERE id=%d" % cid)[0]['status']


C1 = new_cx()
f = form(mona, '/app/?ews_view=corrections', action='ews_correction_decide', correction_id=C1)
check('Corrections page: the form sends its level and its stage', f is not None and f.get('approval_step', '0') != '0' and f.get('request_stage') == 'pending', f)
send(mona, f, 'approve')
qs = send(mona, f, 'approve')
check('...sent twice: "moved", level 2 still waits', qs.get('cx_error') == 'moved' and engine('attendance_correction', C1) == AT2 and cx_status(C1) == 'pending', (qs, engine('attendance_correction', C1)))
f = form(adm, '/wp-admin/admin.php?page=ews31-corrections&status=pending', action='ews_correction_decide', correction_id=C1)
check('...the wp-admin Corrections form sends the level too', f is not None and f.get('approval_step') == hub('correction', C1).get('approval_step'), f)

# The HR stage: a manager-level page sent again after the last level must not become HR's approval.
php("$wpdb->update($p.'ews_attendance_corrections',['needs_hr'=>1],['id'=>%d]);" % C1)
f_big = form(big, '/app/?ews_view=corrections', action='ews_correction_decide', correction_id=C1)
f_hub = hub('correction', C1)
send(big, f_big, 'approve')
check('...level 2 approves a request that needs HR: it waits for HR', cx_status(C1) == 'pending_hr', cx_status(C1))
qs = send(adm, f_hub, 'approve')
check('...the Requests page from before (level 2, stage "pending") is refused: not applied as HR\'s approval', qs.get('request_notice') == 'moved' and cx_status(C1) == 'pending_hr', (qs, cx_status(C1)))
f = form(hr, '/app/?ews_view=corrections', action='ews_correction_decide', correction_id=C1)
check('...HR\'s form shows the HR stage and no level', f is not None and f.get('request_stage') == 'pending_hr' and f.get('approval_step') == '0', f)
qs = send(hr, f, 'approve')
check('...HR approves: the day is corrected', cx_status(C1) == 'approved', (qs, cx_status(C1)))

# The engine off, a manager who is also HR: the stage alone keeps the second post from being HR's approval.
php("$wpdb->query(\"UPDATE {$p}ews_approval_workflows SET active=0,approval_mode='NONE' WHERE workflow_key='attendance_correction'\"); (new WP_User(%d))->add_cap('ews_manage_corrections');" % ids['lgmona_uid'])
C2 = new_cx()
php("$wpdb->update($p.'ews_attendance_corrections',['needs_hr'=>1],['id'=>%d]);" % C2)
f = form(mona, '/app/?ews_view=corrections', action='ews_correction_decide', correction_id=C2)
send(mona, f, 'approve')
check('engine off, needs HR: the manager approves, it waits for HR', cx_status(C2) == 'pending_hr', cx_status(C2))
qs = send(mona, f, 'approve')
check('...the same manager (also HR) sends the form again: "moved", HR\'s stage not decided', qs.get('cx_error') == 'moved' and cx_status(C2) == 'pending_hr', (qs, cx_status(C2)))
php("(new WP_User(%d))->remove_cap('ews_manage_corrections');" % ids['lgmona_uid'])
configure('attendance_correction', 'LEVEL_2', *TWO)


# The app API (cx_decide with the step and stage the API passes; the route maps "moved" to VALIDATION_FAILED).
def cx_decide(uid, cid, step, stage):
    return php("""wp_set_current_user(%d); $m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'cx_decide'); $r->setAccessible(true);
        echo wp_json_encode($r->invoke($m,%d,'approve','',false,\\WorkforceOne\\Approvals\\LevelGuard::posted(%s),%s));""" % (
        uid, cid, 'null' if step is None else "'%s'" % step, 'null' if stage is None else "'%s'" % stage)).strip().splitlines()[-1]


C3 = new_cx()
step1 = q("SELECT s.id FROM {p}ews_approval_steps s JOIN {p}ews_approval_requests r ON r.id=s.approval_request_id WHERE r.entity_type='attendance_correction' AND r.entity_id=%d AND s.step_order=1" % C3)[0]['id']
pending = json.loads(php("""wp_set_current_user(%d); $m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'cx_to_decide'); $r->setAccessible(true);
    echo wp_json_encode(array_map(function($c){return [(int)$c->id,(int)$c->approval_step,$c->status];},$r->invoke($m)));""" % ids['lgmona_uid']).splitlines()[-1])
check('API: /corrections/pending gives each request its level', [C3, int(step1), 'pending'] in pending, pending)
check('...a decision with that level: advanced', cx_decide(ids['lgmona_uid'], C3, step1, 'pending') == '"advanced"')
check('...the same level sent again: moved', cx_decide(ids['lgmona_uid'], C3, step1, 'pending') == '"moved"' and engine('attendance_correction', C3) == AT2)
check('...without a level, the current one is decided as before (here not theirs: access)', cx_decide(ids['lgmona_uid'], C3, None, None) == '"access"')

# ---------------------------------------------------------------- face reset
php("""$r=get_option('ews_face_reset_requests',[]); $r[%(e)d]=['status'=>'pending','requested_by'=>%(u)d,'requested_at'=>current_time('mysql')]; update_option('ews_face_reset_requests',$r,false);
    $m=new EWS_Manager_V31_1(); $x=new ReflectionMethod($m,'approval_start'); $x->setAccessible(true); $x->invoke($m,'face_reset','face_reset_request',%(e)d,%(e)d,[],true);""" % {'e': E, 'u': ids['emp_uid']})
f = hub('face_reset', E)
send(adm, f, 'approve')
qs = send(adm, f, 'approve')
check('face reset, Requests page sent twice: "moved", level 2 still waits', qs.get('request_notice') == 'moved' and engine('face_reset_request', E) == AT2, (qs, engine('face_reset_request', E)))
php("(new WP_User(%d))->add_cap('ews_manage_employees');" % ids['lgbig_uid'])
f = form(big, '/wp-admin/admin.php?page=ews31-face-reset-requests', action='ews31_face_reset_reject', employee_id=E)
check('...the Face Reset page form sends its level', f is not None and f.get('approval_step', '0') != '0', f)
php("$wpdb->query(\"UPDATE {$p}ews_approval_steps SET status='APPROVED' WHERE status='PENDING'\");")   # decided elsewhere meanwhile
qs = big.post('ews31_face_reset_reject', **{k: v for k, v in f.items() if k not in ('_buttons', 'action')})[1]
check('...a level decided since the page was opened: "moved" on the Face Reset page', qs.get('face_reset_notice') == 'moved', qs)
php("(new WP_User(%d))->remove_cap('ews_manage_employees');" % ids['lgbig_uid'])

# ---------------------------------------------------------------- two decisions at the same moment
L7 = new_leave(d(36))
f = hub('leave', L7)
mu = os.path.join(HERE, 'e2e_level_guard_mu.php')
open(mu, 'w').write("""<?php
// Test helper (tests/e2e_level_guard.py): another decision lands just before this one's UPDATE of the level.
add_filter('query',function($sql){
    global $wpdb;static $done=false;
    if(!$done && strpos($sql,'UPDATE `'.$wpdb->prefix.'ews_approval_steps`')===0 && strpos($sql,"`decision`")!==false){
        $done=true;$wpdb->query("UPDATE {$wpdb->prefix}ews_approval_steps SET status='APPROVED',decision='approve' WHERE status='PENDING' ORDER BY step_order LIMIT 1");
        $wpdb->query("UPDATE {$wpdb->prefix}ews_approval_steps SET status='PENDING' WHERE status='WAITING' ORDER BY step_order LIMIT 1");
    }
    return $sql;
});
""")
php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); copy('%s',$d.'/ews-e2e-level-guard.php');" % mu)
atexit.register(lambda: (php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-level-guard.php');"), os.path.exists(mu) and os.unlink(mu)))
qs = send(adm, f, 'approve')
php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-level-guard.php');")
# The other decision ran on the same database connection, so the refused one's rollback undoes it too: what counts is that this
# decision did not take level 2 instead, and that nothing of it was saved.
check('two decisions at the same moment: the second finds its level taken and changes nothing ("moved")',
      qs.get('request_notice') == 'moved' and engine('leave', L7) == ('WAITING_FOR_LEVEL_1', [(1, 'PENDING'), (2, 'WAITING')]) and leave_status(L7) == 'Pending', (qs, engine('leave', L7)))

# ---------------------------------------------------------------- unchanged: single level, early leave
configure('vacation', 'LEVEL_1', ('SUPERVISOR', 0))
L8 = new_leave(d(37))
f = form(mona, '/app/?ews_view=vacation', action='ews_vacation_request_respond', request_id=L8)
qs = send(mona, f, 'approve')
check('one level: the decision is as before', leave_status(L8) == 'Approved', (qs, leave_status(L8)))
qs = send(mona, f, 'approve')
check('...sent again: already decided, as before', qs.get('leave_done') == '1' and leave_status(L8) == 'Approved', qs)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

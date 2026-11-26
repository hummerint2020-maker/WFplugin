"""Behaviour tests for schedule change requests (3.31.165, docs/tasks/schedule-change-request.md): while the week lock, My
approvals and its own switch are on, a manager changes days of a final week on the Attendance grid and sends them to HR with a
reason; HR approves or rejects the whole request (My approvals, the Requests page); the days still as they were asked are applied
and each employee is told; the manager can withdraw it from My requests; a waiting request closes after the set days, or when the
switch goes off. The final-week banner text is configurable. Off by default; off changes nothing.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_schedule_change.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import atexit, html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, break_writes, check, php, q, results, wp, HERE  # noqa: E402

FEAT = '/wp-admin/admin.php?page=ews31-features'
OPTS = "foreach(['ews_feature_week_lock','ews_week_lock','ews_week_lock_notified','ews_feature_schedule_change','ews_schedule_change','ews_feature_my_approvals','ews_approval_settings','ews_approval_self_rule','ews_approval_reject_note'] as $o) delete_option($o);"


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_schedule','ews_audit_log','ews_notifications','ews_departments','ews_schedule_change_requests','ews_schedule_change_days','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        %s
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $u2=username_exists('emp2')?:wp_create_user('emp2','emp2pass','emp2@example.com');
        $wpdb->delete($p.'ews_employees',['wp_user_id'=>$u2]);
        $wpdb->insert($p.'ews_employees',['name'=>'Omar Ops','domain_name'=>'omar','email'=>'emp2@example.com','wp_user_id'=>$u2,'active'=>1,'attendance_enabled'=>1,'department_id'=>$ops]); $omar=$wpdb->insert_id;
        $m=new ReflectionMethod('EWS_Manager_V31_1','week_dates_configured'); $m->setAccessible(true); $o=new EWS_Manager_V31_1();
        $w0=$m->invoke($o,current_time('Y-m-d'))[0]; $w1=$m->invoke($o,date('Y-m-d',strtotime($w0[0].' +7 days')))[0]; $w2=$m->invoke($o,date('Y-m-d',strtotime($w1[0].' +7 days')))[0];
        foreach(array_merge($w1,[$w2[0]]) as $d) foreach([$eid,$omar] as $e) $wpdb->insert($p.'ews_schedule',['employee_id'=>$e,'work_date'=>$d,'status'=>'Office']);
        update_option('ews_approval_settings',['hr_user'=>(int)get_user_by('login','admin')->ID],false);
        echo wp_json_encode(['mona'=>$eid,'omar'=>$omar,'mona_uid'=>(int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$p}ews_employees WHERE id=%%d",$eid)),'omar_uid'=>$u2,
            'admin_uid'=>(int)get_user_by('login','admin')->ID,'w0'=>$w0,'w1'=>$w1,'w2'=>$w2,'today_w'=>(int)current_time('w')]);
    """ % OPTS)
    return json.loads(out.splitlines()[-1])


def switches(lock=True, apv=True, sc=True, settings=None):
    php("update_option('ews_feature_week_lock',%d,false); update_option('ews_week_lock',['cutoff_day'=>%d,'cutoff_time'=>'00:00','notify'=>0],false);"
        "update_option('ews_feature_my_approvals',%d,false); update_option('ews_feature_schedule_change',%d,false); update_option('ews_schedule_change',json_decode('%s',true),false);"
        % (1 if lock else 0, ctx['today_w'], 1 if apv else 0, 1 if sc else 0, json.dumps(settings or {})))


def sched(eid, date):
    r = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (eid, date))
    return r[0]['status'] if r else None


def set_day(eid, date, status):
    php("$wpdb->update($p.'ews_schedule',['status'=>'%s'],['employee_id'=>%d,'work_date'=>'%s']);" % (status, eid, date))


def audits(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


def notes(uid):
    return q("SELECT title,message FROM {p}ews_notifications WHERE user_id=%d ORDER BY id" % uid)


def requests():
    return q("SELECT id,status,days,applied,decision_note FROM {p}ews_schedule_change_requests ORDER BY id")


def last_request():
    r = requests()
    return r[-1] if r else {}


def form_nonce(page, action):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            return m.group(1) if m else None
    return None


def session_nonce(s, uid, action):
    jar = [h for h in s.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
    c = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
    return php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(%d); echo wp_create_nonce('%s');" % (json.dumps(urllib.parse.unquote(c.value)), uid, action)).strip().splitlines()[-1]


def page(sess, week, extra=''):
    return sess.req('/app/?ews_view=attendance&week=' + week + extra)[1]


def qs_of(h):
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def change(eid, date, status):
    return {'employee': eid, 'day': W1.index(date), 'status': status, 'original': sched(eid, date) or ''}


def ask(changes, reason='The client moved the visit to this week.', nonce=None):
    """Sends the grid's changes of the final week W1 to HR as the manager."""
    pg = page(mgr, W1[0])
    data = {'action': 'ews_schedule_change_request', '_wpnonce': nonce or form_nonce(pg, 'ews_schedule_change_request') or 'x', 'week': W1[0],
            'att_changes_json': json.dumps(changes), 'sc_reason': reason, '_wp_http_referer': '/app/?ews_view=attendance&week=' + W1[0]}
    st, body, h = mgr.req('/wp-admin/admin-post.php', data)
    return qs_of(h)


def decide_form(sess, rid):
    """The hidden fields of the schedule change card for request rid on My approvals."""
    pg = sess.req('/app/?ews_view=approvals')[1]
    for f in re.findall(r'<form[^>]*data-apv-form[^>]*>(.*?)</form>', pg, re.S):
        fields = {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f)}
        if fields.get('action') == 'ews_schedule_change_decide' and fields.get('request_id') == str(rid):
            return fields, f
    return {}, ''


def decide(sess, rid, decision, note=''):
    fields, _ = decide_form(sess, rid)
    if not fields:
        return {'missing': '1'}
    fields.update({'decision': decision, 'note': note})
    st, body, h = sess.req('/wp-admin/admin-post.php', fields)
    return qs_of(h)


def form_fields(pg, action):
    form = [f for f in re.findall(r'<form.*?</form>', pg, re.S) if 'value="%s"' % action in f][0]
    data = []
    for tag in re.findall(r'<(?:input|select|textarea)[^>]*>', form):
        name = re.search(r'name="([^"]+)"', tag)
        if not name:
            continue
        typ = (re.search(r'type="([^"]+)"', tag) or [None, 'text'])[1]
        if typ in ('checkbox', 'radio') and 'checked' not in tag:
            continue
        if tag.startswith('<select'):
            sel = re.search(re.escape(tag) + r'(.*?)</select>', form, re.S).group(1)
            opt = re.search(r'<option value="([^"]*)"[^>]*selected', sel) or re.search(r'<option value="([^"]*)"', sel)
            val = opt.group(1) if opt else ''
        elif tag.startswith('<textarea'):
            m = re.search(re.escape(tag) + r'(.*?)</textarea>', form, re.S)
            val = html.unescape(m.group(1)) if m else ''
        else:
            v = re.search(r'value="([^"]*)"', tag)
            val = html.unescape(v.group(1)) if v else ''
        data.append((name.group(1), val))
    return data


def save_features(over=None, drop=()):
    fields = [(k, v) for k, v in form_fields(adm.req(FEAT)[1], 'ews31_features_save') if k not in drop and k not in (over or {})]
    fields += list((over or {}).items())
    st, body, h = adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
    return qs_of(h)


ctx = seed()
W0, W1, W2 = ctx['w0'], ctx['w1'], ctx['w2']
adm, mgr, omar = Session('admin', 'admin'), Session('emp1', 'emp1pass'), Session('emp2', 'emp2pass')
php("$u=new WP_User(%d); $u->add_cap('ews_manage_attendance');" % ctx['mona_uid'])
atexit.register(lambda: (break_writes(), php("$u=new WP_User(%d); $u->remove_cap('ews_manage_attendance'); $wpdb->query(\"DELETE FROM {$p}ews_payroll_runs WHERE closed_by=424242\");" % ctx['mona_uid'] + OPTS)))

# ---------------------------------------------------------------- off: nothing changes
switches(sc=False)
pg = page(mgr, W1[0])
check('off: the final week is read-only as before, no request mode', 'wfo-wl-locked' in pg and 'wfo-sc-mode' not in pg and 'ews_schedule_change_request' not in pg
      and 'This week is final' in pg and 'data-ews-cell' not in pg)
qs = ask([change(ctx['omar'], W1[0], 'WFH')], nonce=session_nonce(mgr, ctx['mona_uid'], 'ews_schedule_change_request'))
check('off: a posted request is refused, nothing stored', qs.get('sc_error') == 'access' and requests() == [] and sched(ctx['omar'], W1[0]) == 'Office', qs)
check('off: no "Change requests to HR" on the Requests page or My approvals', 'Schedule change' not in adm.req('/wp-admin/admin.php?page=ews31-requests')[1]
      and 'ews_schedule_change_decide' not in adm.req('/app/?ews_view=approvals')[1])
php("$o=new EWS_Manager_V31_1(); $o->week_lock_tick();")
check('off: the tick closes nothing', requests() == [])
fp = adm.req(FEAT)[1]
check('Feature Configuration: the block is there, off', 'id="ews-schedule-change"' in fp and re.search(r'name="sc_enabled"[^>]*checked', fp) is None)
php("delete_option('ews_schedule_change'); delete_option('ews_feature_schedule_change');")
qs = save_features()
check('off: saving the page writes no setting of the feature and no Audit Log row', qs.get('features_saved') == '1' and audits('schedule_change_settings') == []
      and php("echo var_export(get_option('ews_schedule_change','none'),true).' '.var_export(get_option('ews_feature_schedule_change','none'),true);").strip() == "'none' 'none'", qs)
qs = save_features(drop=('sc_statuses[]',), over={'sc_reason_min': 'x'})
check('off: a field left wrong in the block does not stop the page', qs.get('features_saved') == '1' and php("echo var_export(get_option('ews_schedule_change','none'),true);").strip() == "'none'", qs)

# ---------------------------------------------------------------- the settings
switches(sc=False, apv=False)
php("delete_option('ews_feature_schedule_change'); delete_option('ews_schedule_change');")
qs = save_features({'sc_enabled': '1'})
check('validation: needs My approvals', qs.get('features_error') == 'sc_my_approvals' and php("echo (int)get_option('ews_feature_schedule_change');").strip() == '0', qs)
qs = save_features({'sc_enabled': '1', 'my_approvals_enabled': '1'}, drop=('sc_statuses[]',))
check('validation: at least one status', qs.get('features_error') == 'sc_statuses', qs)
qs = save_features({'sc_enabled': '1', 'my_approvals_enabled': '1', 'sc_reason_min': '12', 'wl_banner_title_en': 'Week closed', 'wl_banner_text_en': 'Closed at {since}. Write to HR.'})
check('saved: on, audited', qs.get('features_saved') == '1' and php("echo (int)get_option('ews_feature_schedule_change');").strip() == '1'
      and any(a.startswith('enabled;') and 'reason=12' in a for a in audits('schedule_change_settings')), (qs, audits('schedule_change_settings')))
check('...every status ticked is stored as "all"', php("echo wp_json_encode(get_option('ews_schedule_change')['statuses']);").strip() == '[]')
check('...the banner texts are saved', php("$w=get_option('ews_week_lock'); echo $w['banner_title_en'].'|'.$w['banner_text_en'];").strip() == 'Week closed|Closed at {since}. Write to HR.')
fp = adm.req(FEAT)[1]
check('...and shown again on the card', 'value="Week closed"' in fp and 'Closed at {since}. Write to HR.</textarea>' in fp)
qs = save_features()
check('saving the page again keeps them', qs.get('features_saved') == '1' and php("$w=get_option('ews_week_lock'); echo $w['banner_title_en'];").strip() == 'Week closed', qs)

# ---------------------------------------------------------------- the grid: request mode, the banner
pg = page(mgr, W1[0])
check('request mode: the custom banner, {since} replaced', 'wfo-sc-mode' in pg and '<h3>Week closed</h3>' in pg and 'Closed at ' in pg and '{since}' not in pg)
check('...the days can be changed, the form sends to HR, with the sheet', 'data-ews-cell' in pg and 'value="ews_schedule_change_request"' in pg and 'id="wfo-sc-sheet"' in pg
      and 'name="sc_reason"' in pg and 'data-ews-sc' in pg)
check('...the open week next to it is the normal grid', 'wfo-sc-mode' not in page(mgr, W2[0]) and 'ews31_att_grid_save' in page(mgr, W2[0]))
pg = page(adm, W1[0])
check('an administrator ("Edit a locked week") edits the final week directly, no request mode', 'wfo-sc-mode' not in pg and 'ews31_att_grid_save' in pg)
php("$w=get_option('ews_week_lock'); $w['banner_title_en']=''; $w['banner_text_en']=''; update_option('ews_week_lock',$w,false);")
check('empty banner fields: the default request-mode text', 'This week is final: changes go to HR as a request' in page(mgr, W1[0]))

# ---------------------------------------------------------------- sending
qs = ask([change(ctx['omar'], W1[0], 'WFH')], reason='short')
check('a reason shorter than the minimum is refused', qs.get('sc_error') == 'reason' and requests() == [], qs)
qs = ask([change(ctx['omar'], W1[0], 'WFH'), change(ctx['omar'], W1[1], 'WFH'), change(ctx['mona'], W1[2], 'Vacation'), change(ctx['mona'], W1[3], 'Office')])
r1 = last_request()
check('sent: 2 days, the leave and the unchanged day refused', qs.get('sc_sent') == '2' and r1.get('status') == 'pending' and int(r1.get('days', 0)) == 2, (qs, r1))
check('...the schedule is not changed yet', sched(ctx['omar'], W1[0]) == 'Office' and sched(ctx['omar'], W1[1]) == 'Office')
pg = page(mgr, W1[0], '&' + urllib.parse.urlencode(qs))
check('...the pop-up says what was sent and what was not', 'Sent to HR: 2 days' in pg and '2 days not sent' in pg and 'a leave goes through a leave request' in pg and 'the day is already so' in pg)
check('...the workflow was set to HR, one level', q("SELECT approval_mode m,active a FROM {p}ews_approval_workflows WHERE workflow_key='schedule_change'") == [{'m': 'LEVEL_1', 'a': '1'}])
check('...HR is told, once', [n['title'] for n in notes(ctx['admin_uid'])] == ['A schedule change waits for you'], notes(ctx['admin_uid']))
pg = page(mgr, W1[0])
check('the grid marks the days waiting for HR', pg.count('wfo-sc-pending') >= 2 and 'Requested' in pg and 'waiting for HR' in pg)
qs = ask([change(ctx['omar'], W1[0], 'Absent')])
check('a day already waiting cannot be asked again', qs.get('sc_error') == 'none' and len(requests()) == 1, qs)

# ---------------------------------------------------------------- HR approves on My approvals: one day changed since -> partly applied
fields, card = decide_form(adm, r1['id'])
check('My approvals shows the card with its days and reason', bool(fields) and 'Schedule change' in adm.req('/app/?ews_view=approvals')[1] and 'The client moved the visit' in adm.req('/app/?ews_view=approvals')[1])
set_day(ctx['omar'], W1[1], 'Absent')
check('...a day changed since is flagged', 'Changed since the request: now Absent.' in adm.req('/app/?ews_view=approvals')[1])
qs = decide(adm, r1['id'], 'approve')
check('approved: partly applied', qs.get('scd_done') == 'partial' and last_request()['status'] == 'partial' and int(last_request()['applied']) == 1, (qs, last_request()))
check('...the asked day is applied, the changed one left alone', sched(ctx['omar'], W1[0]) == 'WFH' and sched(ctx['omar'], W1[1]) == 'Absent')
check('...the employee is told once, with the day', [n['title'] for n in notes(ctx['omar_uid'])] == ['Your schedule changed'] and 'is now WFH' in notes(ctx['omar_uid'])[0]['message'], notes(ctx['omar_uid']))
check('...the manager is told it was partly applied', any(n['title'] == 'Your schedule change was partly applied' for n in notes(ctx['mona_uid'])))
check('...audited per day, and as a change of a final week', audits('schedule_change_applied') == ['Request #%s · Omar Ops %s: Office → WFH (final week)' % (r1['id'], W1[0])]
      and audits('schedule_change_skipped') == ['Request #%s · Omar Ops %s: not applied (changed, now Absent)' % (r1['id'], W1[1])]
      and audits('schedule_locked_by_schedule_change') == ['Schedule change #%s | %s (final week)' % (r1['id'], W1[0])], (audits('schedule_change_applied'), audits('schedule_locked_by_schedule_change')))
rp = mgr.req('/app/?ews_view=requests')[1]
check('My requests: partly applied, with the day not applied and why', 'Partly applied' in rp and '1 applied, 1 not' in rp and 'Not applied: the day changed before the decision (now Absent).' in rp)
set_day(ctx['omar'], W1[1], 'Office')

# ---------------------------------------------------------------- reject: a reason is needed
ask([change(ctx['omar'], W1[3], 'WFH')])
r2 = last_request()
qs = decide(adm, r2['id'], 'reject')
check('rejecting without a reason is refused', 'scd_done' not in qs and last_request()['status'] == 'pending', qs)
qs = decide(adm, r2['id'], 'reject', 'The client cannot move it.')
check('rejected with a reason, nothing applied', qs.get('scd_done') == 'rejected' and last_request()['status'] == 'rejected' and sched(ctx['omar'], W1[3]) == 'Office', (qs, last_request()))
check('...the manager is told the reason', any(n['title'] == 'Your schedule change was rejected' and 'The client cannot move it.' in n['message'] for n in notes(ctx['mona_uid'])))
check('...My requests shows it', 'Reason: The client cannot move it.' in mgr.req('/app/?ews_view=requests')[1])

# ---------------------------------------------------------------- withdraw from My requests
ask([change(ctx['omar'], W1[4], 'WFH')])
r3 = last_request()
rp = mgr.req('/app/?ews_view=requests')[1]
n = None
for f in re.findall(r'<form.*?</form>', rp, re.S):
    if 'value="ews_request_withdraw"' in f and 'name="kind" value="schedule"' in f and 'name="id" value="%s"' % r3['id'] in f:
        n = re.search(r'name="_wpnonce" value="([^"]+)"', f).group(1)
check('My requests offers Withdraw on the waiting request', n is not None)
st, body, h = mgr.req('/wp-admin/admin-post.php', {'action': 'ews_request_withdraw', 'kind': 'schedule', 'id': r3['id'], '_wpnonce': n or 'x', '_wp_http_referer': '/app/?ews_view=requests'})
check('...withdrawn', 'req_done' in h.get('Location', '') and last_request()['status'] == 'withdrawn', (h.get('Location'), last_request()))
check('...no longer waiting for HR', not decide_form(adm, r3['id'])[0])

# ---------------------------------------------------------------- the Requests page
ask([change(ctx['omar'], W1[5], 'WFH')])
r4 = last_request()
hub = adm.req('/wp-admin/admin.php?page=ews31-requests')[1]
check('the Requests page lists it', 'Omar Ops %s Office → WFH' % W1[5] in hub)
nn = adm.admin_request_nonce('schedule_change', int(r4['id']))
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_requests_decision', '_wpnonce': nn or 'x', 'request_type': 'schedule_change', 'request_id': r4['id'], 'decision': 'approve'})
check('...approved there: applied', last_request()['status'] == 'approved' and sched(ctx['omar'], W1[5]) == 'WFH', (h.get('Location'), last_request()))

# ---------------------------------------------------------------- a failed write rolls everything back
ask([change(ctx['omar'], W1[4], 'Absent')])
r7 = last_request()
break_writes('ews_schedule', 'UPDATE')
try:
    qs = decide(adm, r7['id'], 'approve')
finally:
    break_writes()
check('a failed schedule write: nothing applied, the request still waits for HR', 'scd_done' in qs or 'scd_error' in qs, qs)
check('...rolled back', qs.get('scd_error') == 'save' and last_request()['status'] == 'pending' and sched(ctx['omar'], W1[4]) == 'Office' and bool(decide_form(adm, r7['id'])[0]), (qs, last_request()))
qs = decide(adm, r7['id'], 'approve')
check('...and can be approved once the database is back', qs.get('scd_done') == 'approved' and sched(ctx['omar'], W1[4]) == 'Absent', qs)
set_day(ctx['omar'], W1[4], 'Office')

# ---------------------------------------------------------------- "changed since": refuse instead of skip
php("$s=get_option('ews_schedule_change'); $s['changed_day']='refuse'; update_option('ews_schedule_change',$s,false);")
ask([change(ctx['omar'], W1[2], 'WFH')])
r8 = last_request()
set_day(ctx['omar'], W1[2], 'Absent')
qs = decide(adm, r8['id'], 'approve')
check('refuse mode: a day changed since blocks the approval, nothing changes', qs.get('scd_error') == 'changed' and last_request()['status'] == 'pending' and sched(ctx['omar'], W1[2]) == 'Absent', (qs, last_request()))
set_day(ctx['omar'], W1[2], 'Office')
qs = decide(adm, r8['id'], 'approve')
check('...once the day is back as asked, it is approved', qs.get('scd_done') == 'approved' and sched(ctx['omar'], W1[2]) == 'WFH', qs)
php("$s=get_option('ews_schedule_change'); $s['changed_day']='skip'; update_option('ews_schedule_change',$s,false);")

# ---------------------------------------------------------------- the payroll month closes
ask([change(ctx['omar'], W1[3], 'WFH')])
r9 = last_request()
php("$wpdb->insert($p.'ews_payroll_runs',['month'=>'%s','closed_by'=>424242]);" % W1[3][:7])
php("$o=new EWS_Manager_V31_1(); $o->week_lock_tick();")
check('its payroll month closed: the request closes by itself', last_request()['status'] == 'closed' and last_request()['decision_note'] == 'closed_month' and sched(ctx['omar'], W1[3]) == 'Office', last_request())
qs = ask([change(ctx['omar'], W1[3], 'WFH')])
check('...and a day of a closed month cannot be asked', qs.get('sc_error') == 'none' and int(last_request()['id']) == int(r9['id']), qs)
php("$wpdb->query(\"DELETE FROM {$p}ews_payroll_runs WHERE closed_by=424242\");")

# ---------------------------------------------------------------- expiry, and the switch going off
ask([change(ctx['omar'], W1[6], 'WFH')])
r5 = last_request()
php("$wpdb->update($p.'ews_schedule_change_requests',['requested_at'=>date('Y-m-d H:i:s',strtotime(current_time('mysql').' -15 days'))],['id'=>%s]);" % r5['id'])
php("$o=new EWS_Manager_V31_1(); $o->week_lock_tick();")
check('a request older than 14 days closes by itself', last_request()['status'] == 'expired' and last_request()['decision_note'] == 'expired', last_request())
check('...the manager is told', any(n['title'] == 'Your schedule change was closed' and '14 days without a decision' in n['message'] for n in notes(ctx['mona_uid'])))
n_expired = len(audits('schedule_change_expired'))
php("$o=new EWS_Manager_V31_1(); $o->week_lock_tick();")
check('...a second tick does nothing', len(audits('schedule_change_expired')) == n_expired)
check('...My requests: closed automatically', 'Closed automatically: 14 days without a decision.' in mgr.req('/app/?ews_view=requests')[1])
check('...and the day can be asked again', ask([change(ctx['omar'], W1[6], 'WFH')]).get('sc_sent') == '1')
r6 = last_request()
qs = save_features(drop=('my_approvals_enabled',))
check('switching My approvals off closes the waiting requests', qs.get('features_saved') == '1' and last_request()['status'] == 'closed' and last_request()['decision_note'] == 'off', (qs, last_request()))
switches(settings={'reason_min': 12})
check('...on again, the day can be asked again', ask([change(ctx['omar'], W1[6], 'WFH')]).get('sc_sent') == '1')
qs = save_features(drop=('week_lock_enabled',))
check('switching the week lock off closes the waiting requests', qs.get('features_saved') == '1' and last_request()['status'] == 'closed' and last_request()['decision_note'] == 'off', (qs, last_request()))
check('...the manager is told', any(n['message'].endswith('change requests to HR were switched off.') for n in notes(ctx['mona_uid'])))
switches(settings={'reason_min': 12})
check('...on again, the day can be asked again', ask([change(ctx['omar'], W1[6], 'WFH')]).get('sc_sent') == '1')
r6 = last_request()
qs = save_features(drop=('sc_enabled',))
check('switching it off closes the waiting requests', qs.get('features_saved') == '1' and last_request()['status'] == 'closed' and last_request()['decision_note'] == 'off'
      and int(last_request()['id']) == int(r6['id']), (qs, last_request()))
check('...and the grid is read-only again', 'wfo-sc-mode' not in page(mgr, W1[0]) and 'wfo-wl-locked' in page(mgr, W1[0]))
check('...nothing else changed in the schedule', sched(ctx['omar'], W1[6]) == 'Office' and sched(ctx['omar'], W1[4]) == 'Office')

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

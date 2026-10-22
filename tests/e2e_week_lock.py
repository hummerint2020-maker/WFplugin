"""Behaviour tests for the week lock (3.31.121, docs/tasks/week-lock.md): from a weekly cutoff the coming week's schedule is
final and stays final. Managers can no longer change it (grid, no-JavaScript form, single day, CSV import); people with "Edit a
locked week" can, audited. Approved requests are applied as usual and recorded (here a leave decided on the Requests page;
the other request paths call the same recorder). Employees see "Final" and are told at the
cutoff. Off by default; off changes nothing.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_week_lock.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import atexit, html, json, os, re, sys, urllib.parse, uuid
import urllib.request, urllib.error

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, q, results, wp, HERE  # noqa: E402

FEAT = '/wp-admin/admin.php?page=ews31-features'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_schedule','ews_audit_log','ews_notifications','ews_departments'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        delete_option('ews_feature_week_lock'); delete_option('ews_week_lock'); delete_option('ews_week_lock_notified');
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $u2=username_exists('emp2')?:wp_create_user('emp2','emp2pass','emp2@example.com');
        $wpdb->delete($p.'ews_employees',['wp_user_id'=>$u2]);
        $wpdb->insert($p.'ews_employees',['name'=>'Omar Ops','domain_name'=>'omar','email'=>'emp2@example.com','wp_user_id'=>$u2,'active'=>1,'attendance_enabled'=>1,'department_id'=>$ops]); $omar=$wpdb->insert_id;
        $c=$wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}ews_vacation_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,requested_days INT UNSIGNED NOT NULL DEFAULT 0,reason TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'Pending',requested_by BIGINT UNSIGNED NOT NULL,reviewed_by BIGINT UNSIGNED NULL,requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at DATETIME NULL,PRIMARY KEY(id)) $c");
        $wpdb->query("DELETE FROM {$p}ews_vacation_requests");
        $m=new ReflectionMethod('EWS_Manager_V31_1','week_dates_configured'); $m->setAccessible(true); $o=new EWS_Manager_V31_1();
        $w0=$m->invoke($o,current_time('Y-m-d'))[0]; $w1=$m->invoke($o,date('Y-m-d',strtotime($w0[0].' +7 days')))[0]; $w2=$m->invoke($o,date('Y-m-d',strtotime($w1[0].' +7 days')))[0];
        foreach([$w0[1],$w1[0],$w1[1],$w2[0]] as $d) foreach([$eid,$omar] as $e) $wpdb->insert($p.'ews_schedule',['employee_id'=>$e,'work_date'=>$d,'status'=>'Office']);
        echo wp_json_encode(['mona'=>$eid,'omar'=>$omar,'mona_uid'=>(int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$p}ews_employees WHERE id=%d",$eid)),'omar_uid'=>$u2,'w0'=>$w0,'w1'=>$w1,'w2'=>$w2,'today_w'=>(int)current_time('w'),'now'=>current_time('H:i')]);
    """)
    return json.loads(out.splitlines()[-1])


def lock(on=True, time='00:00', day=None, notify=1):
    """The switch and a cutoff today (00:00: the coming week is final now; 23:59: final later today)."""
    php("update_option('ews_feature_week_lock',%d,false); update_option('ews_week_lock',['cutoff_day'=>%d,'cutoff_time'=>'%s','notify'=>%d],false);" % (
        1 if on else 0, ctx['today_w'] if day is None else day, time, notify))


def sched(eid, date):
    r = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (eid, date))
    return r[0]['status'] if r else None


def audits(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


def form_nonce(page, action):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            return m.group(1) if m else None
    return None


def page(sess, week):
    return sess.req('/app/?ews_view=attendance&week=' + week)[1]


def grid_save(sess, week, changes=None, legacy=None):
    pg = page(sess, week)
    data = {'action': 'ews31_att_grid_save', '_wpnonce': form_nonce(pg, 'ews31_att_grid_save') or form_nonce(page(adm, week), 'ews31_att_grid_save') or 'x',
            'week': week, 'attendance_client': 'desktop', '_wp_http_referer': '/app/?ews_view=attendance&week=' + week}
    if changes is not None:
        data['att_changes_json'] = json.dumps(changes)
    data.update(legacy or {})
    st, body, h = sess.req('/wp-admin/admin-post.php', data)
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def change(eid, week, date, status, original='Office'):
    return {'employee': eid, 'day': ctx[week].index(date), 'status': status, 'original': original}


def upload(sess, fields, content):
    boundary = uuid.uuid4().hex
    parts = ['--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v) for k, v in fields.items()]
    parts.append('--%s\r\nContent-Disposition: form-data; name="attendance_csv"; filename="w.csv"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n' % (boundary, content, boundary))
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=''.join(parts).encode(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = sess.op.open(req)
        return r.status, r.read().decode(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), dict(e.headers)


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
        else:
            v = re.search(r'value="([^"]*)"', tag)
            val = html.unescape(v.group(1)) if v else ''
        data.append((name.group(1), val))
    return data


def save_features(over=None, drop=()):
    fields = [(k, v) for k, v in form_fields(adm.req(FEAT)[1], 'ews31_features_save') if k not in drop and k not in (over or {})]
    fields += list((over or {}).items())
    st, body, h = adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


ctx = seed()
W0, W1, W2 = ctx['w0'], ctx['w1'], ctx['w2']
adm, mgr = Session('admin', 'admin'), Session('emp1', 'emp1pass')
php("$u=new WP_User(%d); $u->add_cap('ews_manage_attendance');" % ctx['mona_uid'])
# Whatever happens below, the next suites start without the switch and the manager's extra permissions.
atexit.register(lambda: php("$u=new WP_User(%d); foreach(['ews_manage_attendance','ews_week_lock_override','ews_manage_settings'] as $c) $u->remove_cap($c); delete_option('ews_feature_week_lock'); delete_option('ews_week_lock'); delete_option('ews_week_lock_notified');" % ctx['mona_uid']))
late_enough = ctx['now'] < '23:30'   # the "later today" cutoff needs time left today (the run takes minutes)

# ---------------------------------------------------------------- off: nothing changes
pg = page(mgr, W1[0])
check('off: no banner, no "Final", the days can be changed', 'wfo-wl-' not in pg and 'data-ews-cell' in pg and 'ews-save-all' in pg)
qs = grid_save(mgr, W1[0], [change(ctx['omar'], 'w1', W1[0], 'WFH')])
check('off: a manager saves next week as before', qs.get('grid_saved') == '1' and 'grid_locked' not in qs and sched(ctx['omar'], W1[0]) == 'WFH', qs)
php("$wpdb->update($p.'ews_schedule',['status'=>'Office'],['employee_id'=>%d,'work_date'=>'%s']);" % (ctx['omar'], W1[0]))
check('off: the schedule page shows no "Final"', 'wfo-wl-' not in mgr.req('/app/?ews_view=schedule&week=' + W1[0])[1])
php("$o=new EWS_Manager_V31_1(); $o->week_lock_tick();")
check('off: the cutoff sends nothing', q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE entity='week_lock'")[0]['c'] in (0, '0'))
check('off: nothing recorded', audits('schedule_locked_refused') == [] and audits('schedule_locked_edit') == [])

# ---------------------------------------------------------------- the settings
fp = adm.req(FEAT)[1]
check('Feature Configuration has the Week lock card, off, Thursday 17:00 by default', 'id="ews-week-lock"' in fp and 'name="wl_cutoff_time" value="17:00"' in fp
      and re.search(r'name="wl_cutoff_day".*?<option value="4"\s+selected', fp, re.S) is not None and re.search(r'name="week_lock_enabled"[^>]*checked', fp) is None)
check('validation: a bad time is refused and nothing is saved', save_features({'week_lock_enabled': '1', 'wl_cutoff_time': '25:00'}).get('features_error') == 'wl_time'
      and php("echo (int)get_option('ews_feature_week_lock');").strip() == '0')
qs = save_features({'week_lock_enabled': '1', 'wl_cutoff_day': str(ctx['today_w']), 'wl_cutoff_time': '00:00'})
check('saved: on, cutoff today 00:00, audited', qs.get('features_saved') == '1' and php("echo (int)get_option('ews_feature_week_lock');").strip() == '1'
      and any(a.startswith('enabled; cutoff=%d 00:00' % ctx['today_w']) for a in audits('week_lock_update')), (qs, audits('week_lock_update')))
check('...switching it on does not send the cutoff that already passed', php("echo get_option('ews_week_lock_notified');").strip() == W1[0])

# ---------------------------------------------------------------- the manager: a final week
lock(time='00:00')
pg = page(mgr, W1[0])
check('next week is final: banner, "Final" next to the week, read-only days, no Save button',
      'This week is final' in pg and 'wfo-wl-locked' in pg and 'data-ews-cell' not in pg and 'ews-save-all' not in pg and 'nothing to save' in pg, re.findall(r'wfo-wl-[a-z-]+', pg)[:8])
check('...the current week is final too', 'wfo-wl-locked' in page(mgr, W0[0]))
pg = page(mgr, W2[0])
check('...the week after is still open', 'wfo-wl-banner' not in pg and 'wfo-wl-locked' not in pg and 'data-ews-cell' in pg and 'ews-save-all' in pg)
qs = grid_save(mgr, W1[0], [change(ctx['omar'], 'w1', W1[0], 'WFH'), change(ctx['omar'], 'w1', W1[1], 'WFH')])
check('a manager\'s save into a final week is refused, nothing written', qs.get('grid_locked') == '2' and sched(ctx['omar'], W1[0]) == 'Office' and sched(ctx['omar'], W1[1]) == 'Office', qs)
check('...one Audit Log row for the request', audits('schedule_locked_refused') == ['2 day(s) not saved (grid): the week is final'], audits('schedule_locked_refused'))
st, pg2, _ = mgr.req('/app/?ews_view=attendance&week=%s&%s' % (W1[0], urllib.parse.urlencode(qs)))
check('...and told so', '2 changes were not saved' in pg2 and 'This week is final now.' in pg2 and 'ask HR' in pg2)
qs = grid_save(mgr, W2[0], [change(ctx['omar'], 'w2', W2[0], 'WFH')])
check('the open week saves as before', qs.get('grid_saved') == '1' and 'grid_locked' not in qs and sched(ctx['omar'], W2[0]) == 'WFH', qs)
day = W1.index(W1[0])
qs = grid_save(mgr, W1[0], legacy={'att[%d][%d]' % (ctx['omar'], day): 'Office', 'att[%d][%d]' % (ctx['mona'], day): 'WFH'})
check('no-JavaScript form: an unchanged day is fine, a changed one is refused', qs.get('grid_locked') == '1' and sched(ctx['mona'], W1[0]) == 'Office', qs)
php("$wpdb->update($p.'ews_schedule',['note'=>'keep me'],['employee_id'=>%d,'work_date'=>'%s']);" % (ctx['omar'], W1[0]))
grid_save(mgr, W1[0], legacy={'att[%d][%d]' % (ctx['omar'], day): 'Office', 'att[%d][note_%d]' % (ctx['omar'], day): ''})
check('...and an unchanged day of a final week is not rewritten (its note stays)', q("SELECT note FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ctx['omar'], W1[0])) == [{'note': 'keep me'}])
php("$u=new WP_User(%d); $u->add_cap('ews_manage_settings');" % ctx['mona_uid'])
fields = [(k, v) for k, v in form_fields(mgr.req(FEAT)[1], 'ews31_features_save') if not k.startswith('wl_') and k != 'week_lock_enabled'] + [('wl_cutoff_day', '6'), ('wl_cutoff_time', '23:00')]
st, body, h = mgr.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
check('someone without "Edit a locked week" cannot switch the lock off or move the cutoff', 'features_saved=1' in h.get('Location', '')
      and php("echo (int)get_option('ews_feature_week_lock').' '.get_option('ews_week_lock')['cutoff_time'];").strip() == '1 00:00', h.get('Location'))
check('...the card is read-only for them', 'Only people with "Edit a locked week"' in mgr.req(FEAT)[1])
php("$u=new WP_User(%d); $u->remove_cap('ews_manage_settings');" % ctx['mona_uid'])
# ---------------------------------------------------------------- HR / administrator: "Edit a locked week"
pg = page(adm, W1[0])
check('an administrator sees the week is final and can still edit it', 'you can still edit it' in pg and 'data-ews-cell' in pg and 'ews-save-all' in pg)
qs = grid_save(adm, W1[0], [change(ctx['omar'], 'w1', W1[0], 'WFH')])
check('...the change is saved and audited as an edit of a final week', qs.get('grid_saved') == '1' and sched(ctx['omar'], W1[0]) == 'WFH'
      and audits('schedule_locked_edit') == ['%s | grid | Office → WFH (final week)' % W1[0]], (qs, audits('schedule_locked_edit')))
php("$u=new WP_User(%d); $u->add_cap('ews_week_lock_override');" % ctx['mona_uid'])
qs = grid_save(mgr, W1[0], [change(ctx['omar'], 'w1', W1[1], 'WFH')])
check('the permission given to a manager lets them edit a final week', qs.get('grid_saved') == '1' and sched(ctx['omar'], W1[1]) == 'WFH', qs)
php("$u=new WP_User(%d); $u->remove_cap('ews_week_lock_override');" % ctx['mona_uid'])
check('the EWS Administrator role holds "Edit a locked week" after the upgrade', php("echo (int)get_role('ews_administrator')->has_cap('ews_week_lock_override');").strip() == '1')

# ---------------------------------------------------------------- CSV import
target = W1[3] if len(W1) > 3 else W1[1]
st, body, h = upload(adm, {'action': 'ews31_att_preview', '_wpnonce': form_nonce(page(adm, W2[0]), 'ews31_att_preview') or 'x'}, 'domain_name,work_date,status,note\nomar,%s,WFH,\n' % target)
token = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).get('preview', [''])[0]
pv = adm.req('/app/?ews_view=attendance&week=%s&preview=%s' % (W2[0], token))[1]
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_import', '_wpnonce': form_nonce(pv, 'ews31_att_import') or 'x', 'token': token})
check('an administrator\'s import into a final week is saved, each day audited', 'imported=1' in h.get('Location', '') and sched(ctx['omar'], target) == 'WFH'
      and '%s | import | %s → WFH (final week)' % (target, 'Office' if target in (W1[0], W1[1]) else 'Not Set') in audits('schedule_locked_edit'), (h.get('Location'), audits('schedule_locked_edit')))
pg = page(mgr, W2[0])
csv = 'domain_name,work_date,status,note\nomar,%s,WFH,\nomar,%s,WFH,\n' % (W1[2] if len(W1) > 2 else W1[0], W2[1])
st, body, h = upload(mgr, {'action': 'ews31_att_preview', '_wpnonce': form_nonce(pg, 'ews31_att_preview') or 'x'}, csv)
token = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).get('preview', [''])[0]
pv = mgr.req('/app/?ews_view=attendance&week=%s&preview=%s' % (W2[0], token))[1]
check('import preview: a row in a final week is an error, a row in the open week is valid', 'The week of this day is final' in pv and token, (st, token))
if late_enough:
    lock(time='23:59')                                  # next week open again for the preview
    csv2 = 'domain_name,work_date,status,note\nomar,%s,Vacation,\n' % W1[1]
    st, body, h = upload(mgr, {'action': 'ews31_att_preview', '_wpnonce': form_nonce(page(mgr, W2[0]), 'ews31_att_preview') or 'x'}, csv2)
    token = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).get('preview', [''])[0]
    pv = mgr.req('/app/?ews_view=attendance&week=%s&preview=%s' % (W2[0], token))[1]
    lock(time='00:00')                                  # the cutoff passes before the import
    st, body, h = mgr.req('/wp-admin/admin-post.php', {'action': 'ews31_att_import', '_wpnonce': form_nonce(pv, 'ews31_att_import') or 'x', 'token': token})
    qs = {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}
    check('import: the cutoff passed after the preview, the row is refused at import', qs.get('rejected') == '1' and qs.get('imported') == '0' and sched(ctx['omar'], W1[1]) == 'WFH', qs)
    # ---------------------------------------------------------------- "later today"
    lock(time='23:59')
    pg = page(mgr, W1[0])
    check('before the cutoff: "becomes final today", the days can still be changed', 'This week becomes final today' in pg and 'data-ews-cell' in pg and 'wfo-wl-locked' not in pg)
    lock(time='00:00')

# ---------------------------------------------------------------- approved requests are applied, and recorded
php("$wpdb->insert($p.'ews_vacation_requests',['employee_id'=>%d,'start_date'=>'%s','end_date'=>'%s','requested_days'=>1,'reason'=>'trip','status'=>'Pending','requested_by'=>%d,'requested_at'=>current_time('mysql')]);" % (ctx['mona'], W1[0], W1[0], ctx['mona_uid']))
lid = int(q("SELECT MAX(id) id FROM {p}ews_vacation_requests")[0]['id'])
n = adm.admin_request_nonce('legacy_vacation', lid)
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_requests_decision', '_wpnonce': n or 'x', 'request_type': 'legacy_vacation', 'request_id': lid, 'decision': 'approve'})
check('an approved leave is written into a final week as usual', sched(ctx['mona'], W1[0]) == 'Vacation' and 'request_notice=approved' in h.get('Location', ''), h.get('Location'))
check('...and recorded', audits('schedule_locked_by_leave') == ['legacy vacation #%d | %s (final week)' % (lid, W1[0])], audits('schedule_locked_by_leave'))

# ---------------------------------------------------------------- the employee
sp = Session('emp2', 'emp2pass').req('/app/?ews_view=schedule&week=' + W1[0])[1]
check('the employee sees "Final" on the week and the note', 'wfo-wl-final' in sp and 'is final since' in sp and 'Request a swap' in sp)
check('...and not on an open week', 'is final since' not in Session('emp2', 'emp2pass').req('/app/?ews_view=schedule&week=' + W2[0])[1])
php("delete_option('ews_week_lock_notified'); $o=new EWS_Manager_V31_1(); $o->week_lock_tick(); $o->week_lock_tick();")
told = q("SELECT user_id,title,message FROM {p}ews_notifications WHERE entity='week_lock' ORDER BY user_id")
check('at the cutoff everyone with a schedule that week is told once, with their days', len(told) == 2 and all(t['title'] == "Next week's schedule is final" for t in told)
      and any('Vacation' in t['message'] for t in told), told)
check('...the cutoff is remembered', php("echo get_option('ews_week_lock_notified');").strip() == W1[0])
php("delete_option('ews_week_lock_notified'); $wpdb->query(\"DELETE FROM {$p}ews_notifications WHERE entity='week_lock'\");")
lock(notify=0)
php("$o=new EWS_Manager_V31_1(); $o->week_lock_tick();")
check('notification off: nothing is sent', q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE entity='week_lock'")[0]['c'] in (0, '0'))

# ---------------------------------------------------------------- off again
lock(on=False)
qs = grid_save(mgr, W1[0], [change(ctx['omar'], 'w1', W1[0], 'Office', original='WFH')])
check('switched off: the week can be changed again', qs.get('grid_saved') == '1' and sched(ctx['omar'], W1[0]) == 'Office', qs)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

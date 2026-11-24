"""Approved leave days locked in the schedule (Leave return, 3.31.161, docs/tasks/leave-return.md §4.5): with the switch on, a
manager cannot change a day of an approved leave in the Attendance grid (JavaScript and no-JavaScript), a single day or a CSV
import (the preview marks it, the import reads again); the refusals are counted, shown and audited; the grid shows the day
locked. Off: as before.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_lock.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080. Wipes Workforce One test data.
"""
import json, os, re, sys, urllib.error, urllib.parse, urllib.request, uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ctx = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_schedule','ews_audit_log','ews_leave_requests','ews_leave_schedule_snapshots'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    foreach(['ews_feature_leave_return','ews_feature_week_lock'] as $o) delete_option($o);
    $m=new ReflectionMethod('EWS_Manager_V31_1','week_dates_configured'); $m->setAccessible(true); $o=new EWS_Manager_V31_1();
    $w=$m->invoke($o,date('Y-m-d',strtotime(current_time('Y-m-d').' +7 days')))[0];
    $annual=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $dom=(string)$wpdb->get_var($wpdb->prepare("SELECT domain_name FROM {$p}ews_employees WHERE id=%d",$eid));
    echo wp_json_encode(['eid'=>$eid,'w'=>$w,'annual'=>$annual,'dom'=>$dom]);
""").splitlines()[-1])
E, W = ctx['eid'], ctx['w']
adm = Session('admin', 'admin')


def leave(days, status='Approved'):
    """A leave whose days are held as approval holds them: a snapshot each, the schedule set to Vacation."""
    lid = int(php("""$wpdb->insert($p.'ews_leave_requests',['employee_id'=>%d,'leave_type_id'=>%d,'start_date'=>'%s','end_date'=>'%s','requested_days'=>%d,'status'=>'%s','cancellation_status'=>'Not Requested','requested_at'=>current_time('mysql')]); echo $wpdb->insert_id;"""
                  % (E, ctx['annual'], days[0], days[-1], len(days), status)).splitlines()[-1])
    for day in days:
        php("""$wpdb->insert($p.'ews_leave_schedule_snapshots',['leave_request_id'=>%d,'employee_id'=>%d,'work_date'=>'%s','had_schedule'=>0]);
            $wpdb->replace($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Vacation','note'=>'Approved leave #%d']);""" % (lid, E, day, E, day, lid))
    return lid


def sched(day):
    r = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (E, day))
    return r[0]['status'] if r else None


def switch(on):
    php("update_option('ews_feature_leave_return',%d,false);" % (1 if on else 0))


def page(extra=''):
    return adm.req('/app/?ews_view=attendance&week=%s%s' % (W[0], extra))[1]


def form_nonce(pg, action):
    for form in re.findall(r'<form.*?</form>', pg, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            return m.group(1) if m else None
    return None


def grid(changes=None, legacy=None):
    data = {'action': 'ews31_att_grid_save', '_wpnonce': form_nonce(page(), 'ews31_att_grid_save') or 'x', 'week': W[0], 'attendance_client': 'desktop'}
    if changes is not None:
        data['att_changes_json'] = json.dumps(changes)
    data.update(legacy or {})
    st, body, h = adm.req('/wp-admin/admin-post.php', data)
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def change(day, status, original):
    return {'employee': E, 'day': W.index(day), 'status': status, 'original': original}


def upload(content):
    fields = {'action': 'ews31_att_preview', '_wpnonce': form_nonce(page(), 'ews31_att_preview') or 'x'}
    boundary = uuid.uuid4().hex
    parts = ['--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v) for k, v in fields.items()]
    parts.append('--%s\r\nContent-Disposition: form-data; name="attendance_csv"; filename="w.csv"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n' % (boundary, content, boundary))
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=''.join(parts).encode(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        h = dict(adm.op.open(req).headers)
    except urllib.error.HTTPError as e:
        h = dict(e.headers)
    return urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).get('preview', [''])[0]


def import_(token):
    pv = page('&preview=' + token)
    st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_import', '_wpnonce': form_nonce(pv, 'ews31_att_import') or 'x', 'token': token})
    return pv, {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def refused():
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='schedule_leave_refused' ORDER BY id")]


def cell(pg, day):
    m = re.search(r'<td class="ews-att-day[^"]*"[^>]*data-employee="%d" data-day="%d".*?</td>' % (E, W.index(day)), pg, re.S)
    return m.group(0) if m else ''


A = leave([W[1], W[2]])
php("$wpdb->replace($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (E, W[3]))

# ---------------------------------------------------------------- off (the default): as before
qs = grid([change(W[1], 'Office', 'Vacation')])
check('off: a manager changes an approved leave day in the grid, as before', qs.get('grid_saved') == '1' and sched(W[1]) == 'Office' and 'grid_leave' not in qs, qs)
check('off: the grid does not lock it', 'data-held' not in page())
php("$wpdb->update($p.'ews_schedule',['status'=>'Vacation'],['employee_id'=>%d,'work_date'=>'%s']);" % (E, W[1]))

# ---------------------------------------------------------------- on
switch(True)
pg = page()
check('on: the leave days are locked in the grid (disabled, no picker), the other days are not',
      'data-held="1"' in cell(pg, W[1]) and 'disabled aria-disabled="true"' in cell(pg, W[2]) and 'data-ews-cell' not in cell(pg, W[1])
      and 'data-held' not in cell(pg, W[3]) and 'data-ews-cell' in cell(pg, W[3]), cell(pg, W[1])[:600])
qs = grid([change(W[1], 'Office', 'Vacation'), change(W[3], 'WFH', 'Office')])
check('the grid: the leave day is refused and counted, the other day saved', qs.get('grid_leave') == '1' and qs.get('grid_saved') == '1' and sched(W[1]) == 'Vacation' and sched(W[3]) == 'WFH', qs)
check('...the refusal is audited with the day', len(refused()) == 1 and refused()[0].startswith('grid: 1 refused') and '%d %s' % (E, W[1]) in refused()[0], refused())
pg = adm.req('/app/?ews_view=attendance&week=%s&grid_saved=1&grid_skipped=0&grid_invalid=0&grid_leave=1' % W[0])[1]
check('...and shown to the manager', 'change(s) of an approved leave day were refused: HR records a return from leave.' in pg)

legacy = {'att[%d][%d]' % (E, W.index(W[1])): 'Office', 'att[%d][note_%d]' % (E, W.index(W[1])): 'manager', 'att[%d][%d]' % (E, W.index(W[2])): 'Vacation'}
qs = grid(legacy=legacy)
check('without JavaScript: the changed leave day is refused (counted once), the unchanged one left alone', qs.get('grid_leave') == '1' and sched(W[1]) == 'Vacation' and sched(W[2]) == 'Vacation', qs)
check('...its note is not changed either', q("SELECT note FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (E, W[1]))[0]['note'] == 'Approved leave #%d' % A)

# The single-day handler (no page shows its form now): the nonce this session would get.
jar = [h for h in adm.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
ck = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
nn = php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(get_user_by('login','admin')->ID); echo wp_create_nonce('ews31_att_single');" % json.dumps(urllib.parse.unquote(ck.value))).strip()
st, body, _ = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_single', '_wpnonce': nn, 'employee_id': E, 'work_date': W[2], 'status': 'Office', 'note': ''})
check('a single day: refused with the reason, unchanged, audited', 'This day is an approved leave.' in body and sched(W[2]) == 'Vacation' and any(r.startswith('single:') for r in refused()), (st, body[:300]))

csv = 'domain_name,work_date,status,note\n%s,%s,Office,\n%s,%s,Office,\n' % (ctx['dom'], W[2], ctx['dom'], W[4])
token = upload(csv)
pv, qs = import_(token)
check('CSV preview marks the leave day', 'Approved leave (#%d): HR records a return from leave' % A in pv, pv[:200])
check('...the import skips it and imports the other row', qs.get('imported') == '1' and qs.get('rejected') == '1' and sched(W[2]) == 'Vacation' and sched(W[4]) == 'Office', qs)

# A leave approved after the preview: the import reads again.
token = upload('domain_name,work_date,status,note\n%s,%s,WFH,\n' % (ctx['dom'], W[5]))
B2 = leave([W[5]])
pv, qs = import_(token)
check('a leave approved after the preview: the import refuses its day, counted and audited', qs.get('imported') == '0' and qs.get('grid_leave') == '1' and sched(W[5]) == 'Vacation'
      and any(r.startswith('import:') for r in refused()), qs)

# A pending leave's days stay open; a cancelled leave's are not held.
P = leave([W[6]], status='Pending')
qs = grid([change(W[6], 'Office', 'Vacation')])
check('a pending leave\'s day can still be changed', qs.get('grid_saved') == '1' and sched(W[6]) == 'Office', qs)

switch(False)
qs = grid([change(W[2], 'Office', 'Vacation')])
check('switched off again: as before', qs.get('grid_saved') == '1' and sched(W[2]) == 'Office' and 'grid_leave' not in qs, qs)

php("""foreach(['ews_schedule','ews_leave_requests','ews_leave_schedule_snapshots'] as $t) $wpdb->query("DELETE FROM {$p}$t"); delete_option('ews_feature_leave_return');""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

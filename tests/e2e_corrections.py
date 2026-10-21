"""Behaviour tests for attendance corrections (3.31.74), HTTP on a real site.

Pins: the feature switch; each kind end to end (forgot Sign Out, forgot Sign In, wrong time, whole
day with a photo); the deadline, a pending request and the monthly limit (refused, or allowed and sent
to HR); the second level for a Sign In moved earlier; the original rows never change; every reader
(reports, payroll, insights, My Profile, overtime) sees the corrected day; a closed payroll month gets
an adjustment in the next open month; rejection needs a note and changes nothing; who may decide (not
one's own, a manager only for their department, HR for the second level); the Requests Hub; the
wp-admin page (list, settings, report, CSV, direct correction); the end-of-day reminder; API routes.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_corrections.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, json, os, re, sys, urllib.parse, urllib.request, uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, q, results, today, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))


def day(n):
    return (today - datetime.timedelta(days=n)).isoformat()


D1, D2, D3, D4, D10 = day(1), day(2), day(3), day(4), day(10)
setup = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_attendance_corrections','ews_time_logs','ews_schedule','ews_departments','ews_notifications','ews_audit_log','ews_pay_rates','ews_pay_adjustments','ews_payroll_runs','ews_payslips','ews_company_calendar','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    delete_option('ews_correction_settings'); delete_option('ews_corrections_reminded');
    update_option('ews_feature_corrections',0,false);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'10:00','end'=>'16:00'],false);
    $wf=(int)$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='attendance_correction'");
    if($wf){$wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]);$wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".$wf);}
    $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=(int)$wpdb->insert_id;
    $wpdb->update($p.'ews_employees',['name'=>'Mona Adel','department_id'=>$ops],['id'=>$eid]);
    $mk=function($login,$cap)use($wpdb,$p){$u=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');$o=new WP_User($u);$o->set_role('subscriber');if($cap)$o->add_cap($cap);return (int)$u;};
    $mgr=$mk('cxmgr','ews_manage_time'); $hr=$mk('cxhr','ews_manage_corrections'); $e2u=$mk('cxemp2','');
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('cxmgr','cxemp2')");
    $wpdb->insert($p.'ews_employees',['name'=>'Ahmed Samir','domain_name'=>'cxmgr','email'=>'cxmgr@example.com','wp_user_id'=>$mgr,'active'=>1,'attendance_enabled'=>1,'department_id'=>$ops]); $mgr_e=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_employees',['name'=>'Karim Youssef','domain_name'=>'cxemp2','email'=>'cxemp2@example.com','wp_user_id'=>$e2u,'active'=>1,'attendance_enabled'=>1,'department_id'=>$sales]); $e2=(int)$wpdb->insert_id;
    $log=function($e,$d,$type,$t)use($wpdb,$p){$wpdb->insert($p.'ews_time_logs',['employee_id'=>$e,'user_id'=>0,'work_date'=>$d,'event_type'=>$type,'event_at'=>$d.' '.$t.':00','scheduled_status'=>'Office','location_status'=>'inside','distance_meters'=>35,'integrity_status'=>'verified','source'=>'app']);};
    foreach(['%(d1)s','%(d2)s','%(d3)s','%(d4)s','%(d10)s'] as $d){$wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>$d,'status'=>'Office']);}
    $log($eid,'%(d1)s','sign_in','08:52');                                   // forgot Sign Out
    $log($eid,'%(d2)s','sign_in','09:31'); $log($eid,'%(d2)s','sign_out','17:05');   // wrong time
    $log($eid,'%(d4)s','sign_out','17:00');                                   // forgot Sign In
    $log($eid,'%(d10)s','sign_in','08:30');                                   // too old
    $log($e2,'%(d1)s','sign_in','08:40');                                     // Sales: not the manager's
    echo wp_json_encode(['eid'=>$eid,'e2'=>$e2,'mgr_e'=>$mgr_e,'ops'=>$ops,'sales'=>$sales]);
""" % {'d1': D1, 'd2': D2, 'd3': D3, 'd4': D4, 'd10': D10}).splitlines()[-1])
E, E2 = setup['eid'], setup['e2']
ORIGINALS = q("SELECT id,employee_id,work_date,event_type,event_at,location_status,distance_meters,integrity_status,source FROM {p}ews_time_logs ORDER BY id")

emp, mgr, hr, adm, emp2 = Session('emp1', 'emp1pass'), Session('cxmgr', 'cxmgrpass'), Session('cxhr', 'cxhrpass'), Session('admin', 'admin'), Session('cxemp2', 'cxemp2pass')


def page(s, view):
    return s.req('/app/?ews_view=' + view)[1]


def form_nonce(pg, action, extra=''):
    for f in re.findall(r'<form.*?</form>', pg, re.S):
        if 'name="action" value="%s"' % action in f and extra in f:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', f)
            if m:
                return m.group(1)
    return ''


def nonce_for(s, action):
    """The nonce this logged-in session would get for an action (to show the server refuses it anyway)."""
    jar = [h for h in s.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
    c = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
    return php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(%d); echo wp_create_nonce('%s');" % (json.dumps(urllib.parse.unquote(c.value)), int(php("echo username_exists('%s');" % s_login[id(s)])), action)).strip()


def request(s, date, typ, time_in='', time_out='', target='', reason='I forgot', photo=None):
    pg = page(s, 'corrections')
    n = form_nonce(pg, 'ews_correction_request') or nonce_for(s, 'ews_correction_request')
    fields = {'action': 'ews_correction_request', '_wpnonce': n, 'date': date, 'type': typ, 'target': target, 'time_in': time_in, 'time_out': time_out, 'reason': reason}
    if photo is None:
        _, qs, _ = s.post('ews_correction_request', **{k: v for k, v in fields.items() if k != 'action'})
        return qs
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in fields.items():
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    body += ('--%s\r\nContent-Disposition: form-data; name="photo"; filename="visit.png"\r\nContent-Type: image/png\r\n\r\n' % boundary).encode() + photo + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = s.op.open(req)
        loc = r.headers.get('Location', '')
    except urllib.error.HTTPError as e:
        loc = e.headers.get('Location', '')
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(loc).query).items()}


def decide(s, cid, decision, note='', in_admin=False):
    pg = s.req('/wp-admin/admin.php?page=ews31-corrections&month=%s' % D1[:7])[1] if in_admin else page(s, 'corrections')
    n = form_nonce(pg, 'ews_correction_decide', 'name="correction_id" value="%d"' % cid)
    if not n:
        # Not on the page: a valid nonce for this user anyway (the server must still refuse).
        n = nonce_for(s, 'ews_correction_decide_%d' % cid)
    data = {'_wpnonce': n, 'correction_id': cid, 'decision': decision, 'note': note}
    if in_admin:
        data['in_admin'] = 1
    _, qs, _ = s.post('ews_correction_decide', **data)
    return qs


s_login = {id(emp): 'emp1', id(mgr): 'cxmgr', id(hr): 'cxhr', id(adm): 'admin', id(emp2): 'cxemp2'}


def cx(cid):
    r = q("SELECT * FROM {p}ews_attendance_corrections WHERE id=%d" % cid)
    return r[0] if r else {}


def last_cx():
    return int(q("SELECT MAX(id) id FROM {p}ews_attendance_corrections")[0]['id'] or 0)


def events(eid, date):
    return q("SELECT id,event_type,event_at,source,corrects_id,correction_id FROM {p}ews_time_logs WHERE employee_id=%d AND work_date='%s' ORDER BY id" % (eid, date))


def call(method, *args):
    """A private plugin method on a fresh instance, as the given user (0 = none)."""
    return php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'%s'); $r->setAccessible(true); echo wp_json_encode($r->invoke($m,%s));" % (method, ','.join(args)))


# ---------------------------------------------------------------- switched off
pg = page(emp, 'time')
check('off: no "My recent days" on Sign In / Out', 'wfo-cx-days' not in pg)
check('off: the Corrections page is not available (Home instead)', 'wfo-cx' not in page(emp, 'corrections'))
r = q("SELECT COUNT(*) n FROM {p}ews_attendance_corrections")[0]['n']
php("update_option('ews_feature_corrections',1,false); update_option('ews_correction_settings',['monthly_limit'=>20],false);")  # the limit has its own test below

# ---------------------------------------------------------------- the employee's days
pg = page(emp, 'time')
check('on: "My recent days" is on Sign In / Out', 'wfo-cx-days' in pg and 'wfo-cx-sheet' in pg)
check('...the day without a Sign Out has the "Request correction" button', re.search(r'data-date="%s"[^>]*data-type="out"' % D1, pg) is not None, D1)
check('...the day without a Sign In offers "Forgot Sign In"', re.search(r'data-date="%s"[^>]*data-type="in"' % D4, pg) is not None)
check('...a day older than the deadline is not listed', 'data-date="%s"' % D10 not in pg)
check('...the menu has Corrections', 'ews_view=corrections' in pg)

# ---------------------------------------------------------------- validation
check('a day past the deadline is refused', request(emp, D10, 'out', time_out='17:00').get('cx_error') == 'deadline')
check('a reason is required', request(emp, D1, 'out', time_out='17:30', reason='  ').get('cx_error') == 'reason')
check('"Forgot Sign Out" on a day that has one is refused', request(emp, D2, 'out', time_out='18:00').get('cx_error') == 'has_out')
check('a Sign Out before the Sign In is refused', request(emp, D1, 'out', time_out='08:00').get('cx_error') == 'order')
check('a day that has not come is refused', request(emp, day(-1), 'out', time_out='17:00').get('cx_error') == 'future')
check('the whole day needs a photo (the default)', request(emp, D3, 'day', time_in='09:00', time_out='17:00').get('cx_error') == 'photo')

# ---------------------------------------------------------------- forgot Sign Out, manager approves
qs = request(emp, D1, 'out', time_out='17:30', reason='Left at 5:30 after the customer meeting')
C1 = last_cx()
check('forgot Sign Out: the request is sent and waits for the manager', qs.get('cx_sent') == 'pending' and cx(C1).get('status') == 'pending', qs)
check('...a second request for the same day is refused while it waits', request(emp, D1, 'out', time_out='17:45').get('cx_error') == 'pending')
check('...the manager is notified', len(q("SELECT n.id FROM {p}ews_notifications n JOIN {p}users u ON u.ID=n.user_id WHERE u.user_login='cxmgr' AND n.entity='correction'")) >= 1)
check('...nothing is added to the day yet', len(events(E, D1)) == 1)
mp = page(mgr, 'corrections')
check('the manager sees it with the evidence panel', 'Mona Adel' in mp and 'wfo-cx-evidence' in mp and '08:52' in mp, mp[:200])
check('...not the Sales employee\'s request (another department)', 'Karim Youssef' not in mp)
check('the employee cannot decide their own request', decide(emp, C1, 'approve').get('cx_error') in ('own', 'access') and cx(C1)['status'] == 'pending')
check('rejecting needs a note', decide(mgr, C1, 'reject').get('cx_error') == 'note' and cx(C1)['status'] == 'pending')
qs = decide(mgr, C1, 'approve')
ev = events(E, D1)
check('the manager approves: the day gets its Sign Out', qs.get('cx_done') == 'approved' and cx(C1)['status'] == 'approved', qs)
check('...as its own event (source correction, linked to the request)', len(ev) == 2 and ev[1]['event_type'] == 'sign_out' and ev[1]['event_at'].startswith(D1 + ' 17:30') and ev[1]['source'] == 'correction' and int(ev[1]['correction_id']) == C1, ev)
check('...the employee is notified', len(q("SELECT id FROM {p}ews_notifications WHERE user_id=(SELECT wp_user_id FROM {p}ews_employees WHERE id=%d) AND title LIKE 'Correction approved%%'" % E)) == 1)
check('...and it is in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='correction_approved'")) == 1)
check('a decided request cannot be decided again', decide(mgr, C1, 'reject', 'no').get('cx_error') in ('done', 'access'))

# ---------------------------------------------------------------- wrong time: an earlier Sign In needs HR
qs = request(emp, D2, 'time', time_in='08:55', target='sign_in', reason='The app hung')
C2 = last_cx()
check('wrong time (Sign In earlier): marked for the second level', qs.get('cx_sent') == 'pending' and int(cx(C2)['earlier']) == 1 and int(cx(C2)['needs_hr']) == 1, qs)
check('...the manager approves: it goes to HR, nothing applied yet', decide(mgr, C2, 'approve').get('cx_done') == 'pending_hr' and cx(C2)['status'] == 'pending_hr' and len(events(E, D2)) == 2)
check('...the manager cannot approve the HR level', 'Karim' not in page(mgr, 'corrections') and decide(mgr, C2, 'approve').get('cx_error') == 'access')
hp = page(hr, 'corrections')
check('...HR sees it', 'Mona Adel' in hp and 'HR approves it too' in hp)
qs = decide(hr, C2, 'approve')
ev = events(E, D2)
orig_in = [e for e in ev if e['event_type'] == 'sign_in' and e['source'] != 'correction'][0]
new_in = [e for e in ev if e['source'] == 'correction'][0]
check('...HR approves: a new Sign In replaces the original', qs.get('cx_done') == 'approved' and new_in['event_at'].startswith(D2 + ' 08:55') and int(new_in['corrects_id']) == int(orig_in['id']), ev)

# ---------------------------------------------------------------- forgot Sign In
qs = request(emp, D4, 'in', time_in='08:45', reason='Came in with the client')
C4 = last_cx()
decide(mgr, C4, 'approve')
check('forgot Sign In: approved and added', cx(C4)['status'] == 'approved' and any(e['event_type'] == 'sign_in' and e['source'] == 'correction' for e in events(E, D4)))

# ---------------------------------------------------------------- whole day with a photo
PNG = bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000'
                    '1f15c4890000000d49444154789c6360000002000100e221bc330000000049454e44ae426082')
qs = request(emp, D3, 'day', time_in='09:00', time_out='17:00', reason='Mission at a customer', photo=PNG)
C3 = last_cx()
check('whole day with a photo: sent', qs.get('cx_sent') == 'pending' and cx(C3).get('photo_key'), qs)
mp = page(mgr, 'corrections')
m = re.search(r'href="([^"]*action=ews_correction_photo[^"]*id=%d[^"]*)"' % C3, html.unescape(mp))
photo_url = html.unescape(m.group(1)) if m else ''
st, body, hd = mgr.req(photo_url.replace(B, '')) if photo_url else (0, '', {})
check('...the manager can open the photo', st == 200 and 'image/png' in hd.get('Content-Type', ''), (st, hd.get('Content-Type')))
st2, _, _ = emp2.req(photo_url.replace(B, '')) if photo_url else (0, '', {})
check('...another employee cannot', st2 == 403, st2)
check('...the photo is not in the public uploads URL space', '/uploads/workforce-one-corrections' not in mp)
decide(mgr, C3, 'approve')
check('...approved: Sign In and Sign Out added', [e['event_type'] for e in events(E, D3)] == ['sign_in', 'sign_out'])

# ---------------------------------------------------------------- rejection changes nothing
ev_before = len(q("SELECT id FROM {p}ews_time_logs"))
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 10:00:00','scheduled_status'=>'Office','source'=>'app']);" % (E, day(5), day(5)))
php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (E, day(5)))
request(emp, day(5), 'out', time_out='18:00', reason='forgot')
C5 = last_cx()
qs = decide(mgr, C5, 'reject', 'Your location was outside the office at 17:00.')
check('rejected with a note: the day is unchanged', qs.get('cx_done') == 'rejected' and cx(C5)['status'] == 'rejected' and len(events(E, day(5))) == 1 and cx(C5)['decision_note'].startswith('Your location'), qs)
check('...the employee is told why', len(q("SELECT id FROM {p}ews_notifications WHERE title LIKE 'Correction rejected%%' AND message LIKE '%%outside the office%%'")) == 1)

# ---------------------------------------------------------------- the originals never change
now_rows = {r['id']: r for r in q("SELECT id,employee_id,work_date,event_type,event_at,location_status,distance_meters,integrity_status,source FROM {p}ews_time_logs")}
check('no original event was changed or deleted', all(now_rows.get(r['id']) == r for r in ORIGINALS), [r for r in ORIGINALS if now_rows.get(r['id']) != r][:2])

# ---------------------------------------------------------------- every reader sees the corrected day
emp_row = "$wpdb->get_row($wpdb->prepare(\"SELECT * FROM {$wpdb->prefix}ews_employees WHERE id=%%d\",%d))" % E
rd = {r['date']: r for r in json.loads(call('report_days', '[' + emp_row + ']', "'%s'" % D4, "'%s'" % D1, 'true').splitlines()[-1])}
check('reports / payroll (report_days): Sign Out added', rd[D1]['sign_out'] == '17:30' and not rd[D1]['missing_sign_out'], rd.get(D1))
check('...Sign In replaced: 08:55 (late 55 min instead of 91)', rd[D2]['sign_in'] == '08:55' and rd[D2]['late_minutes'] == 55, rd.get(D2))
check('...the whole day is no longer absent', rd[D3]['sign_in'] == '09:00' and rd[D3]['result'] != 'Absent', rd.get(D3))
check('...forgot Sign In: 08:45', rd[D4]['sign_in'] == '08:45', rd.get(D4))
ins = json.loads(call('insights_load', '[%d]' % E, "'%s'" % D4, "'%s'" % D1).splitlines()[-1])
check('Attendance Insights read it', ins[1][str(E)][D2]['sign_in']['event_at'].startswith(D2 + ' 08:55') and ins[1][str(E)][D1]['sign_out']['event_at'].startswith(D1 + ' 17:30'))
mpr = json.loads(call('my_profile_recent', str(E), "'%s'" % today.isoformat()).splitlines()[-1])
check('My Profile reads it', any(r['out'] == '5:30 PM' for r in mpr) and any(r['in'] == '8:55 AM' for r in mpr), mpr[:3])
ot = json.loads(call('overtime_attendance_summary', str(E), "'%s'" % D2).splitlines()[-1])
check('the overtime summary reads it', (ot.get('sign_in') or '').startswith(D2 + ' 08:55') or not php("echo (int)get_option('ews_feature_overtime');").strip() == '1', ot)
tr = adm.req('/wp-admin/admin.php?page=ews31-time-report&start=%s&end=%s' % (D4, D1))[1]
check('the Sign In / Out report shows the source and the replaced original (kept)', 'Correction' in tr and 'Replaced by correction #%d (kept)' % C2 in tr)
tp = page(emp, 'time')
check('"My recent days" shows the corrected days', re.search(r'Corrected', tp) is not None)

# ---------------------------------------------------------------- managers only their department; HR any
php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (E2, D1))
request(emp2, D1, 'out', time_out='17:10', reason='forgot')
C6 = last_cx()
check('a manager cannot decide another department\'s request', decide(mgr, C6, 'approve').get('cx_error') == 'access' and cx(C6)['status'] == 'pending')

# ---------------------------------------------------------------- Requests Hub
hub = adm.req('/wp-admin/admin.php?page=ews31-requests')[1]
check('the Requests Hub lists it', 'Attendance Correction' in hub and 'Karim Youssef' in hub)
n = adm.admin_request_nonce('correction', C6)
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_requests_decision', 'request_type': 'correction', 'request_id': C6, 'decision': 'approve', '_wpnonce': n})
check('...and decides it', 'request_notice=approved' in h.get('Location', '') and cx(C6)['status'] == 'approved', h.get('Location'))

# ---------------------------------------------------------------- monthly limit
# 3.31.111: a month ends on its own last day (February has no 31st): a request on 28 Feb counts towards February's limit
php("""$e=(int)$wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE wp_user_id=".(int)get_user_by('login','emp1')->ID); $wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>$e,'work_date'=>'2027-02-28','type'=>'time','target'=>'sign_out','status'=>'pending','source'=>'employee','reason'=>'x','requested_by'=>1,'requested_at'=>'2027-02-28 10:00:00']);""")
cnt = php("""$e=(int)$wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE wp_user_id=".(int)get_user_by('login','emp1')->ID); $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'cx_month_count'); $m->setAccessible(true); echo $m->invoke($o,$e,'2027-02-10');""").splitlines()[-1]
check('the monthly limit counts a request on the last day of February', cnt == '1', cnt)
php("""$wpdb->delete($p.'ews_attendance_corrections',['work_date'=>'2027-02-28']);""")
month = D1[:7]
php("for($i=0;$i<3;$i++)$wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>%d,'work_date'=>'%s','type'=>'out','status'=>'approved','source'=>'employee','requested_by'=>0,'requested_at'=>current_time('mysql'),'reason'=>'filler']);" % (E, month + '-01'))
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 08:00:00','scheduled_status'=>'Office','source'=>'app']);" % (E, day(6), day(6)))
same_month = day(6)[:7] == month
if same_month:
    php("update_option('ews_correction_settings',['above_limit'=>'refuse','monthly_limit'=>3],false);")
    check('above the monthly limit: refused when set to refuse', request(emp, day(6), 'out', time_out='16:30').get('cx_error') == 'limit')
    php("update_option('ews_correction_settings',['above_limit'=>'hr','monthly_limit'=>3],false);")
    qs = request(emp, day(6), 'out', time_out='16:30')
    C7 = last_cx()
    check('...allowed and sent to HR when set to HR', qs.get('cx_sent') == 'pending' and int(cx(C7)['above_limit']) == 1 and int(cx(C7)['needs_hr']) == 1, qs)
    decide(mgr, C7, 'approve')
    check('...after the manager it waits for HR', cx(C7)['status'] == 'pending_hr')
php("update_option('ews_correction_settings',['monthly_limit'=>20],false);")

# ---------------------------------------------------------------- wp-admin: list, settings, report, CSV, direct
lp = hr.req('/wp-admin/admin.php?page=ews31-corrections&month=%s' % month)[1]
check('wp-admin: HR sees the requests list with filters', 'Attendance Corrections' in lp and 'Mona Adel' in lp and 'name="type"' in lp and 'name="department"' in lp)
check('...an employee cannot open it', emp.req('/wp-admin/admin.php?page=ews31-corrections')[0] in (302, 403, 500) or 'Attendance Corrections' not in emp.req('/wp-admin/admin.php?page=ews31-corrections')[1])
sp = hr.req('/wp-admin/admin.php?page=ews31-corrections&tab=settings')[1]
sn = form_nonce(sp, 'ews_corrections_settings_save')
_, qs, _ = hr.post('ews_corrections_settings_save', _wpnonce=sn, cx_type_out=1, cx_type_in=1, cx_type_time=1, cx_type_day=1, cx_photo_out='off', cx_photo_in='optional', cx_photo_time='optional', cx_photo_day='required',
                   cx_deadline_days='5', cx_monthly_limit='4', cx_above_limit='refuse', cx_earlier_needs_hr=1, cx_reminder=1, cx_reminder_time='18:30')
saved = json.loads(php("echo wp_json_encode(get_option('ews_correction_settings'));"))
check('settings are saved', qs.get('cx_saved') == '1' and saved['deadline_days'] == 5 and saved['monthly_limit'] == 4 and saved['reminder_time'] == '18:30' and saved['photo']['out'] == 'off', saved)
_, qs, _ = hr.post('ews_corrections_settings_save', _wpnonce=sn, cx_type_out=1, cx_deadline_days='0', cx_monthly_limit='3', cx_reminder_time='18:30')
check('...a bad deadline is refused', qs.get('cx_settings_error') == 'deadline')
check('...on Settings Overview', 'ews_correction_settings' in adm.req('/wp-admin/admin.php?page=ews31-settings-overview')[1])
php("update_option('ews_correction_settings',['monthly_limit'=>20],false);")
rp = hr.req('/wp-admin/admin.php?page=ews31-corrections&tab=report&month=%s' % month)[1]
check('the report counts by employee and type', 'By employee' in rp and 'Mona Adel' in rp and 'frequent' in rp)
m = re.search(r'href="([^"]*action=ews_corrections_export[^"]*)"', rp)
st, csv, hd = hr.req(html.unescape(m.group(1)).replace(B, '')) if m else (0, '', {})
check('...and downloads as CSV', st == 200 and 'Mona Adel' in csv and 'text/csv' in hd.get('Content-Type', ''), st)

# direct correction by HR: past the deadline, reason required
dp = hr.req('/wp-admin/admin.php?page=ews31-corrections&tab=direct')[1]
dn = form_nonce(dp, 'ews_correction_direct')
ref = php("echo \\WorkforceOne\\Support\\Picker::label('Mona Adel (emp1)',%d);" % E).strip()
_, qs, _ = hr.post('ews_correction_direct', _wpnonce=dn, employee_ref=ref, date=D10, type='out', time_out='17:00', reason='')
check('HR direct correction: the reason is required', qs.get('cx_error') == 'reason')
_, qs, _ = hr.post('ews_correction_direct', _wpnonce=dn, employee_ref=ref, date=D10, type='out', time_out='17:00', reason='Manager confirmed she stayed until 17:00')
check('...past the deadline it is applied at once', qs.get('cx_done') == 'direct' and any(e['source'] == 'correction' for e in events(E, D10)), qs)
check('...and written to the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='correction_direct'")) == 1)

# ---------------------------------------------------------------- closed payroll month: an adjustment next month
pm = json.loads(php("""
    $month=date('Y-m',strtotime('first day of last month',current_time('timestamp')));
    $day=$month.'-14';
    // Every other day of the month worked, so the one absence is the only deduction.
    for($t=strtotime($month.'-01');date('Y-m',$t)===$month;$t=strtotime('+1 day',$t)){$d=date('Y-m-d',$t);if($d===$day)continue;
        $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>$d,'status'=>'Office']);
        foreach([['sign_in','08:00'],['sign_out','16:00']] as $x)$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>$d,'event_type'=>$x[0],'event_at'=>$d.' '.$x[1].':00','scheduled_status'=>'Office','source'=>'app']);}
    $wpdb->insert($p.'ews_pay_rates',['employee_id'=>%d,'effective_from'=>$month.'-01','basic'=>9000,'allowances'=>'[]','created_by'=>1]);
    $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>$day,'status'=>'Office']);
    $wpdb->insert($p.'ews_payroll_runs',['month'=>$month,'employees'=>1,'total_net'=>1,'currency'=>'EGP','closed_by'=>1]);
    $wpdb->insert($p.'ews_payslips',['run_id'=>(int)$wpdb->insert_id,'employee_id'=>%d,'net'=>1,'data'=>'{}']);
    echo wp_json_encode(['month'=>$month,'day'=>$day,'current'=>current_time('Y-m')]);
""" % (E, E, E, E, E)).splitlines()[-1])
slips_before = q("SELECT net,data FROM {p}ews_payslips")
_, qs, _ = hr.post('ews_correction_direct', _wpnonce=dn, employee_ref=ref, date=pm['day'], type='day', time_in='08:00', time_out='16:00', reason='Was at the customer all day')
adj = q("SELECT month,kind,amount,reason FROM {p}ews_pay_adjustments WHERE reason LIKE 'Attendance correction%%'")
check('a correction in a closed month does not reopen it', q("SELECT net,data FROM {p}ews_payslips") == slips_before and len(q("SELECT id FROM {p}ews_payroll_runs")) == 1)
check('...the difference goes to the next open month as an adjustment with its reason', len(adj) == 1 and adj[0]['month'] == pm['current'] and adj[0]['kind'] == 'bonus' and float(adj[0]['amount']) > 0 and pm['day'] in adj[0]['reason'], adj)

# ---------------------------------------------------------------- end-of-day reminder
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>(int)get_user_by('login','emp1')->ID,'work_date'=>current_time('Y-m-d'),'event_type'=>'sign_in','event_at'=>current_time('Y-m-d').' 00:01:00','scheduled_status'=>'Office','source'=>'app']);" % E)
n_rem = call('corrections_remind', "'%s'" % today.isoformat()).splitlines()[-1]
rem = q("SELECT message FROM {p}ews_notifications WHERE title='You did not sign out today'")
check('the end-of-day reminder goes to whoever has no Sign Out today', n_rem.strip() == '1' and len(rem) == 1 and '00:01' in rem[0]['message'], (n_rem, rem))
tp = emp.req('/app/?ews_view=time&cx=out&cx_date=%s' % today.isoformat())[1]
check('...its link opens the form on today with "Forgot Sign Out"', 'data-open="out"' in tp and 'data-open-date="%s"' % today.isoformat() in tp)

# ---------------------------------------------------------------- approval workflow (Level 1, a specific approver)
php("""$wf=(int)$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='attendance_correction'");
    $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'LEVEL_1','active'=>1],['id'=>$wf]);
    $wpdb->insert($p.'ews_approval_workflow_steps',['workflow_id'=>$wf,'step_order'=>1,'step_type'=>'APPROVAL','resolver_type'=>'SPECIFIC_USER','resolver_value'=>(string)get_user_by('login','cxhr')->ID,'required'=>1,'active'=>1]);""")
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 08:10:00','scheduled_status'=>'Office','source'=>'app']);" % (E, day(7), day(7)))
qs = request(emp, day(7), 'out', time_out='16:00', reason='forgot')
C8 = last_cx()
check('with the workflow, the request waits for its approver', qs.get('cx_sent') == 'pending' and cx(C8)['status'] == 'pending', qs)
check('...the department manager cannot decide it', decide(mgr, C8, 'approve').get('cx_error') == 'access')
check('...the approver can', decide(hr, C8, 'approve').get('cx_done') == 'approved' and cx(C8)['status'] == 'approved')
php("""$wf=(int)$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='attendance_correction'"); $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]); $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".$wf);""")

# ---------------------------------------------------------------- a day nobody works (3.31.115, owner decision)
D5, D6 = day(5), day(6)
php("""$wpdb->query("DELETE FROM {$p}ews_attendance_corrections WHERE work_date IN ('%(a)s','%(b)s')"); $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date IN ('%(a)s','%(b)s')");
    foreach(['%(a)s','%(b)s'] as $d)$wpdb->insert($p.'ews_company_calendar',['event_date'=>$d,'title'=>'National Day','event_type'=>'general_leave','active'=>1]);
    $wpdb->insert($p.'ews_time_logs',['employee_id'=>%(e)d,'user_id'=>1,'work_date'=>'%(b)s','event_type'=>'sign_in','event_at'=>'%(b)s 09:05:00','scheduled_status'=>'Office']);""" % {'a': D5, 'b': D6, 'e': E})
tp = emp.req('/app/?ews_view=time')[1]
check('a holiday with nothing recorded: no Correct button in "My recent days"', 'data-date="%s"' % D5 not in tp and 'National Day' in tp, re.findall(r'data-date="[^"]+"', tp)[:8])
check('...and the request is refused: there is nothing to correct', request(emp, D5, 'day', time_in='09:00', time_out='17:00', photo=PNG).get('cx_error') == 'no_work' and request(emp, D5, 'in', time_in='09:00').get('cx_error') == 'no_work')
check('a holiday on which the employee came in: the day can still be corrected', 'data-date="%s"' % D6 in tp)
php("""$wpdb->query("DELETE FROM {$p}ews_company_calendar WHERE title='National Day'"); $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date='%s'");""" % D6)

# An approved leave (the schedule says Vacation) and a weekly day off, through the code path the app and the API share (cx_create).
D7 = day(7)
php("""$wpdb->query("DELETE FROM {$p}ews_attendance_corrections WHERE work_date IN ('%(a)s','%(b)s')"); $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date IN ('%(a)s','%(b)s')");
    $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=%(e)d AND work_date='%(a)s'"); $wpdb->insert($p.'ews_schedule',['employee_id'=>%(e)d,'work_date'=>'%(a)s','status'=>'Vacation']);""" % {'a': D7, 'b': D5, 'e': E})
tp = emp.req('/app/?ews_view=time')[1]
check('an approved leave day ("On leave", nothing recorded): no Correct button', 'data-date="%s"' % D7 not in tp and 'On leave' in tp)
def mk(d, src, typ='day', eid=None, login='emp1'):
    return json.loads(php("""$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'cx_create'); $r->setAccessible(true); $e=$wpdb->get_row("SELECT * FROM {$p}ews_employees WHERE id=%d");
    wp_set_current_user(%d); echo wp_json_encode($r->invoke($m,$e,['date'=>'%s','type'=>'%s','target'=>'','time_in'=>'09:00','time_out'=>'%s','reason'=>'I was there'],'%s'));""" % (
        eid or E, int(php("echo username_exists('%s');" % (login if src == 'employee' else 'admin'))), d, typ, '' if typ == 'in' else '17:00', src)).strip().splitlines()[-1])
check('...the request is refused for the employee (the app and the API both use cx_create): no_work', mk(D7, 'employee').get('error') == 'no_work')
wd = (datetime.date.fromisoformat(D5).isoweekday()) % 7
php("update_option('ews_working_days',%s,false);" % json.dumps([d for d in range(7) if d != wd]))
check('a weekly day off with nothing recorded is refused too', mk(D5, 'employee').get('error') == 'no_work')
hr_ok = mk(D7, 'hr')
check('HR may still correct such a day', 'id' in hr_ok and 'error' not in hr_ok, hr_ok)
php("""update_option('ews_working_days',[0,1,2,3,4,5,6],false); $wpdb->query("DELETE FROM {$p}ews_attendance_corrections WHERE work_date IN ('%(a)s','%(b)s')"); $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date IN ('%(a)s','%(b)s')"); $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=%(e)d AND work_date='%(a)s'");""" % {'a': D7, 'b': D5, 'e': E})

# Owner decisions 2026-10-20: approved overtime on a day off keeps it correctable; no request before the start date; a request whose
# day became a leave is closed when it is decided.
php("update_option('ews_working_days',%s,false);" % json.dumps([d for d in range(7) if d != wd]))
php("""$wpdb->query("DELETE FROM {$p}ews_overtime_requests WHERE employee_id=%(e)d AND overtime_date='%(d)s'");
    $wpdb->insert($p.'ews_overtime_requests',['employee_id'=>%(e)d,'overtime_date'=>'%(d)s','start_time'=>'09:00:00','end_time'=>'13:00:00','requested_minutes'=>240,'reason'=>'stock count','status'=>'Approved','requested_at'=>current_time('mysql')]);""" % {'e': E, 'd': D5})
php("update_option('ews_feature_overtime',1,false);")
tp_ot = emp.req('/app/?ews_view=time')[1]
r_ot = mk(D5, 'employee', 'in')
check('a day off with approved overtime and nothing recorded: the employee may ask (they were expected to work), and the button shows', 'id' in r_ot and 'data-date="%s"' % D5 in tp_ot, r_ot)
php("$wpdb->query(\"DELETE FROM {$p}ews_attendance_corrections WHERE work_date='%s'\"); update_option('ews_feature_overtime',0,false);" % D5)
check('...with the Overtime feature off, approved overtime does not count (as in the reports): refused', mk(D5, 'employee', 'in').get('error') == 'no_work')
php("""update_option('ews_working_days',[0,1,2,3,4,5,6],false); $wpdb->query("DELETE FROM {$p}ews_overtime_requests WHERE employee_id=%(e)d AND overtime_date='%(d)s'"); $wpdb->query("DELETE FROM {$p}ews_attendance_corrections WHERE work_date='%(d)s'");""" % {'e': E, 'd': D5})
# The second employee has no attendance before: added 4 days ago, the 5th day back is before they joined.
created = q("SELECT created_at FROM {p}ews_employees WHERE id=%d" % E2)[0]['created_at']
# Added on D3: with one day of margin (the database clock may be UTC) the first day is D4.
php("$wpdb->update($p.'ews_employees',['created_at'=>'%s 10:00:00'],['id'=>%d]); $wpdb->query(\"DELETE FROM {$p}ews_time_logs WHERE employee_id=%d AND work_date<'%s'\");" % (D3, E2, E2, D3))
check('a day before the employee joined (and no attendance before it): refused (before_start)', mk(D5, 'employee', 'in', E2, 'cxemp2').get('error') == 'before_start')
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>1,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 09:00:00','scheduled_status'=>'Office']);" % (E2, D6, D6))
check('...but an employee imported later whose attendance starts earlier is not limited by the day they were added', mk(D5, 'employee', 'in', E2, 'cxemp2').get('error') != 'before_start')
php("$wpdb->query(\"DELETE FROM {$p}ews_attendance_corrections WHERE work_date='%s'\"); $wpdb->query(\"DELETE FROM {$p}ews_time_logs WHERE employee_id=%d AND work_date='%s'\");" % (D5, E2, D6))
tp2 = emp2.req('/app/?ews_view=time')[1]
check('...and "My recent days" offers no Correct for that day', 'data-date="%s"' % D5 not in tp2 and 'data-date="%s"' % D4 in tp2, re.findall(r'data-date="[^"]+"', tp2)[:8])
php("$wpdb->update($p.'ews_employees',['created_at'=>'%s'],['id'=>%d]);" % (created, E2))
r_pend = mk(D5, 'employee', 'in')
check('a request on a working day goes in...', 'id' in r_pend, r_pend)
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule WHERE employee_id=%(e)d AND work_date='%(d)s'\"); $wpdb->insert($p.'ews_schedule',['employee_id'=>%(e)d,'work_date'=>'%(d)s','status'=>'Vacation']);" % {'e': E, 'd': D5})
res = decide(hr, int(r_pend['id']), 'approve', in_admin=True)
check('...a leave is approved for that day before it is decided: the correction is closed, not applied, and HR is told so', cx(int(r_pend['id']))['status'] == 'rejected' and 'Closed automatically' in (cx(int(r_pend['id']))['decision_note'] or '') and not events(E, D5) and res.get('cx_done') == 'closed', (res, cx(int(r_pend['id']))['status']))
check('...the employee is told, and it is in the Audit Log as closed', q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND message LIKE '%%Closed automatically%%'" % int(php("echo username_exists('emp1');"))) and q("SELECT id FROM {p}ews_audit_log WHERE action='correction_closed'"))
php("$wpdb->query(\"DELETE FROM {$p}ews_attendance_corrections WHERE work_date='%s'\"); $wpdb->query(\"DELETE FROM {$p}ews_schedule WHERE employee_id=%d AND work_date='%s'\");" % (D5, E, D5))
r_pend2 = mk(D5, 'employee', 'in')
php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Vacation']);" % (E, D5))
res2 = decide(mgr, int(r_pend2['id']), 'approve')
check('...the same when the manager approves it in the app', res2.get('cx_done') == 'closed' and cx(int(r_pend2['id']))['status'] == 'rejected' and not events(E, D5), (res2, cx(int(r_pend2['id'])).get('status')))
php("""$wpdb->query("DELETE FROM {$p}ews_attendance_corrections WHERE work_date='%(d)s'"); $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=%(e)d AND work_date='%(d)s'");""" % {'e': E, 'd': D5})

# ---------------------------------------------------------------- API routes
routes = json.loads(emp.req('/wp-json/workforce-one/v1')[1] or '{}').get('routes', {})
check('the API has the correction routes', all(r in routes for r in ['/workforce-one/v1/corrections', '/workforce-one/v1/corrections/days', '/workforce-one/v1/corrections/pending', '/workforce-one/v1/corrections/(?P<id>\\d+)/decision']), list(routes)[:5])

# ---------------------------------------------------------------- switched off again
php("update_option('ews_feature_corrections',0,false);")
check('switched off: requests are refused', request(emp, D1, 'out', time_out='17:30').get('cx_error') == 'disabled')
check('...and the corrected days stay corrected', rd[D1]['sign_out'] == '17:30' and any(e['source'] == 'correction' for e in events(E, D1)))

print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

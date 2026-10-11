"""Behaviour tests for Payroll phase 2: manual bonuses and deductions, closing a month (its figures
are kept and no longer follow attendance), reopening it, and the employee's own "My Pay" page in the
app (closed months and an estimate for the current month), switched on by an administrator.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_payroll_close.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, shutil, subprocess, sys, time, unicodedata, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-payroll'

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
I = ids()
E = I['eid']
seed = json.loads(php(r"""
    foreach(['ews_pay_rates','ews_pay_adjustments','ews_payroll_runs','ews_payslips','ews_schedule','ews_time_logs','ews_overtime_requests','ews_early_leave_requests','ews_leave_requests','ews_leave_schedule_snapshots','ews_company_calendar','ews_break_sessions','ews_audit_log','ews_notifications'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    foreach(['ews_payroll_day_divisor','ews_payroll_day_base','ews_payroll_absence_days','ews_payroll_overtime_rate','ews_payroll_overtime_rate_off','ews_payroll_max_deduction_days','ews_payroll_currency','ews_payroll_employee_view','ews_shifts','ews_notification_policy'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4],false);
    update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'10:00','end'=>'16:00'],false);
    update_option('ews_grace_period',10,false); update_option('ews_feature_breaks',0,false);
    $month=date('Y-m',strtotime('first day of last month',current_time('timestamp')));
    $work=[];
    for($t=strtotime($month.'-01');date('Y-m',$t)===$month;$t=strtotime('+1 day',$t)){if((int)date('w',$t)<=4)$work[]=date('Y-m-d',$t);}
    echo wp_json_encode(['month'=>$month,'work'=>$work,'current'=>current_time('Y-m')]);
""").splitlines()[-1])
MONTH, W, CURRENT = seed['month'], seed['work'], seed['current']
MONTH_LABEL = php("echo date('F Y',strtotime('%s-01'));" % MONTH).strip()
ABSENT, LATE, NO_OUT, LATER = W[1], W[2], W[3], W[5]


def log(d, kind, t):
    php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'%s','event_at'=>'%s %s:00','scheduled_status'=>'Office','location_status'=>'office']);" % (E, d, kind, d, t))


for d in W:
    php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (E, d))
    if d == ABSENT:
        continue
    log(d, 'sign_in', '08:12' if d == LATE else '08:00')
    if d != NO_OUT:
        log(d, 'sign_out', '16:00')
adm = Session('admin', 'admin')
st, page, _ = adm.req(PAGE + '&tab=salaries')
nonce = lambda action, pg: (re.search(r'name="action" value="%s".*?name="_wpnonce" value="([^"]+)"' % action, pg, re.S) or re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="action" value="%s"' % action, pg, re.S))  # noqa: E731
n = nonce('ews_payroll_rate_save', page)
adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_rate_save', '_wpnonce': n.group(1) if n else '', 'employee_id': E, 'effective_from': MONTH + '-01', 'basic': '9000',
                                     'allowance_name[]': ['Transport'], 'allowance_amount[]': ['1500']})
check('setup: emp1 has a salary', len(q("SELECT id FROM {p}ews_pay_rates WHERE employee_id=%d" % E)) == 1)
# 10,500 a month: a day = 350, a minute = 350 / 480. Absent −350.00, late 12 min −8.75 → 10,141.25.
BASE = 10141.25


def detail():
    st, pg, _ = adm.req(PAGE + '&month=%s&employee=%d' % (MONTH, E))
    return pg, html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', pg)))


def money(v):
    return '{:,.2f}'.format(v)


pg, text = detail()
check('before any adjustment the net pay follows attendance', money(BASE) in text, text[:300])

# ---------------------------------------------------------------- adjustments
an = nonce('ews_payroll_adjust_save', pg)
an = an.group(1) if an else ''


def adjust(kind, amount, reason):
    st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_adjust_save', '_wpnonce': an, 'employee_id': E, 'month': MONTH, 'kind': kind, 'amount': amount, 'reason': reason})
    return html.unescape(h.get('Location', ''))


check('an employee\'s month offers bonuses and deductions', bool(an) and 'name="kind"' in pg)
loc = adjust('bonus', '500', 'Project delivery')
adjust('deduction', '100', 'Advance repayment')
pg, text = detail()
check('a bonus is added, with its reason', 'Project delivery' in text and '+500.00' in text)
check('a manual deduction is taken off, with its reason', 'Advance repayment' in text and '−100.00' in text)
check('...and the net pay includes both', money(BASE + 500 - 100) in text, text[:300])
loc = adjust('bonus', '-5', 'Bad')
check('an amount must be more than zero', 'payroll_error' in loc and len(q("SELECT id FROM {p}ews_pay_adjustments")) == 2, loc)
loc = adjust('bonus', '50', '')
check('...and a reason is required', 'payroll_error' in loc and len(q("SELECT id FROM {p}ews_pay_adjustments")) == 2, loc)
audit = q("SELECT details FROM {p}ews_audit_log WHERE action='pay_adjustment_added'")
check('adjustments are in the Audit Log without amounts', len(audit) == 2 and not any('500' in a['details'] or '100' in a['details'] for a in audit), audit)
php("update_option('ews_payroll_max_deduction_days',0.5,false);")
pg, text = detail()
check('the deduction limit covers attendance only, not manual deductions', money(10500 - 175 + 500 - 100) in text, text[:300])
php("delete_option('ews_payroll_max_deduction_days');")

# ---------------------------------------------------------------- closing
st, mpage, _ = adm.req(PAGE + '&month=' + MONTH)
cn = nonce('ews_payroll_close', mpage)
cn = cn.group(1) if cn else ''
check('a past month can be closed', bool(cn))
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_close', '_wpnonce': cn, 'month': MONTH})
loc = html.unescape(h.get('Location', ''))
check('...but not while a day needs review (a missing Sign Out)', 'payroll_error=review' in loc and not q("SELECT id FROM {p}ews_payroll_runs"), loc)
check('...and the message says which', 'review' in adm.req(loc.split('127.0.0.1:8080')[-1])[1].lower())
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_close', '_wpnonce': cn, 'month': CURRENT})
check('the current month cannot be closed before it ends', 'payroll_error=not_ended' in html.unescape(h.get('Location', '')) and not q("SELECT id FROM {p}ews_payroll_runs"), h.get('Location'))
log(NO_OUT, 'sign_out', '16:00')
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_close', '_wpnonce': cn, 'month': MONTH})
run = q("SELECT id,closed_by FROM {p}ews_payroll_runs WHERE month='%s'" % MONTH)
slip = q("SELECT net,data FROM {p}ews_payslips WHERE employee_id=%d" % E)
check('once fixed, the month closes and each payslip is kept', run and slip and abs(float(slip[0]['net']) - (BASE + 400)) < 0.001, (h.get('Location'), run, slip[:1]))
check('...recorded in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='payroll_closed'")) == 1)

# attendance changes after closing do not change a closed month
php("$wpdb->query(\"DELETE FROM {$p}ews_time_logs WHERE employee_id=%d AND work_date='%s'\");" % (E, LATER))
pg, text = detail()
check('a closed month keeps its figures when attendance changes later', money(BASE + 400) in text and 'Closed' in text, text[:300])
check('...and takes no more adjustments', 'name="kind"' not in pg)
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_adjust_save', '_wpnonce': an, 'employee_id': E, 'month': MONTH, 'kind': 'bonus', 'amount': '50', 'reason': 'Late bonus'})
check('...even when posted directly', 'payroll_error=closed' in html.unescape(h.get('Location', '')) and len(q("SELECT id FROM {p}ews_pay_adjustments")) == 2)
st, mpage, _ = adm.req(PAGE + '&month=' + MONTH)
check('the month list says it is closed and offers to reopen it', 'Closed' in mpage and 'name="action" value="ews_payroll_reopen"' in mpage)

# ---------------------------------------------------------------- the employee's My Pay
emp = Session('emp1', 'emp1pass')
st, body, _ = emp.req('/app/?ews_view=pay')
check('My Pay is off until an administrator switches it on', 'Net pay' not in body and 'ews_view=pay' not in body)
st, rpage, _ = adm.req(PAGE + '&tab=rules')
rn = nonce('ews_payroll_rules_save', rpage)
adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_rules_save', '_wpnonce': rn.group(1) if rn else '', 'currency': 'EGP', 'day_divisor': 30, 'day_base': 'gross', 'absence_days': 1,
                                     'overtime_rate': '1.35', 'overtime_rate_off': '2', 'max_deduction_days': 0, 'employee_view': '1'})
check('the Rules tab switches My Pay on', php("echo (int)get_option('ews_payroll_employee_view',0);").strip() == '1')
st, body, _ = emp.req('/app/?ews_view=pay&month=' + MONTH)
t = html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body)))
check('the employee sees My Pay in the menu', 'ews_view=pay' in body)
check('...their closed month: net pay, marked final', money(BASE + 400) in t and 'Final' in t, t[:400])
check('...with the lines and the days behind them', 'Basic salary' in t and 'Transport' in t and 'Project delivery' in t and 'Advance repayment' in t and '12 min' in t)
st, body, _ = emp.req('/app/?ews_view=pay&month=' + CURRENT)
t = html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body)))
check('...and the current month as an estimate', 'Estimate' in t and 'Expected net' in t, t[:400])
st, body, _ = emp.req('/app/?ews_view=pay&month=2020-01')
check('a month without a payslip is not invented', 'No payslip' in html.unescape(body) or 'Expected net' not in body)
note = q("SELECT title FROM {p}ews_notifications WHERE user_id=%d" % I['uid'])
check('closing did not notify while My Pay was off', note == [], note)

# ---------------------------------------------------------------- reopening
rn2 = nonce('ews_payroll_reopen', mpage)
adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_reopen', '_wpnonce': rn2.group(1) if rn2 else '', 'month': MONTH})
check('a closed month can be reopened (payslips removed, logged)', not q("SELECT id FROM {p}ews_payroll_runs") and not q("SELECT id FROM {p}ews_payslips") and len(q("SELECT id FROM {p}ews_audit_log WHERE action='payroll_reopened'")) == 1)
pg, text = detail()
check('...and follows attendance again (the later absence now counts)', money(BASE + 400 - 350) in text, text[:300])
st, body, _ = emp.req('/app/?ews_view=pay&month=' + MONTH)
check('...and the employee no longer sees it as final', 'Final' not in body)
php("$wpdb->update($p.'ews_employees',['name'=>'أحمد محمد'],['id'=>%d]);" % E)  # names may be Arabic


def queued_pushes():
    """Pushes waiting in WP-Cron (DISABLE_WP_CRON is on in the tests, so nothing runs by itself)."""
    return json.loads(php("$o=[];foreach((array)_get_cron_array() as $t=>$hooks){foreach((array)($hooks['ews_push_deliver']??[]) as $e){foreach($e['args'][0] as $x)$o[]=$x;}} echo wp_json_encode($o);"))


# A device subscribed before 3.31.45 with an endpoint that is no longer allowed: delivery removes it.
php("$wpdb->query(\"DELETE FROM {$p}ews_push_subscriptions\"); wp_unschedule_hook('ews_push_deliver'); $wpdb->insert($p.'ews_push_subscriptions',['user_id'=>%d,'endpoint'=>'https://127.0.0.1/push','endpoint_hash'=>hash('sha256','https://127.0.0.1/push'),'p256dh'=>'x','auth'=>'y','content_encoding'=>'aes128gcm','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);" % I['uid'])
# ...and one whose push service is down (a public address that never answers).
php("$wpdb->insert($p.'ews_push_subscriptions',['user_id'=>%d,'endpoint'=>'https://fcm.googleapis.com:443/fcm/send/dead','endpoint_hash'=>hash('sha256','dead'),'p256dh'=>'x','auth'=>'y','content_encoding'=>'aes128gcm','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);" % I['uid'])
started = time.time()
adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_close', '_wpnonce': cn, 'month': MONTH})
took = time.time() - started
check('a dead push endpoint does not slow the action down (the push is not sent during it)', took < 3, took)
note = q("SELECT title,message,entity,entity_id FROM {p}ews_notifications WHERE user_id=%d" % I['uid'])
check('closing with My Pay on tells each employee their payslip is ready (in the app)', len(note) == 1 and 'Payslip' in note[0]['title'] and note[0]['entity'] == 'payroll', note)
pushes = queued_pushes()
check('...and queues a push (Payroll push is on by default; before 3.31.45 there was none)', [(x['user_id'], x['category']) for x in pushes] == [(I['uid'], 'payroll')] and 'ews_view=pay' in pushes[0]['url'], pushes)
check('...sent after the response, not during it (the close did not touch the devices)', len(q("SELECT id FROM {p}ews_push_subscriptions WHERE user_id=%d" % I['uid'])) == 2)
wp('cron', 'event', 'run', 'ews_push_deliver')
last = json.loads(php("echo wp_json_encode(get_option('ews_push_last_delivery'));"))
check('delivery: an endpoint that is not a public HTTPS push service is never contacted, and is removed', [r['endpoint'] for r in q("SELECT endpoint FROM {p}ews_push_subscriptions WHERE user_id=%d" % I['uid'])] == ['https://fcm.googleapis.com:443/fcm/send/dead'] and last['failed'] == 2 and 'private or reserved' in ' '.join(last['errors']), last)
check('...and the removal is in the Audit Log', 'user_id=%d' % I['uid'] in (q("SELECT details FROM {p}ews_audit_log WHERE action='push_endpoint_removed'") or [{'details': ''}])[-1]['details'])
st, npage, _ = adm.req('/wp-admin/admin.php?page=ews31-notifications')
check('...the failures are recorded (the dead endpoint too) and shown on Notification Settings', 'Last push delivery' in npage and 'failed 2' in npage, last)
# The Payroll push follows the Notification Policy like every other category.
php("update_option('ews_notification_policy',['payroll'=>['in_app'=>1,'push'=>0,'mandatory'=>0]],false);")
adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_reopen', '_wpnonce': rn2.group(1) if rn2 else '', 'month': MONTH})
adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_close', '_wpnonce': cn, 'month': MONTH})
check('with Payroll push off, closing notifies in the app only', len(q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND entity='payroll'" % I['uid'])) == 2 and queued_pushes() == [], queued_pushes())
php("delete_option('ews_notification_policy');")

# ---------------------------------------------------------------- payslip PDF


def download(sess, path):
    try:
        r = sess.op.open(urllib.request.Request(B + path))
        return r.headers.get('Content-Type', ''), r.headers.get('Content-Disposition', ''), r.read()
    except urllib.error.HTTPError as e:
        return e.headers.get('Content-Type', ''), '', e.read()


def pdf_text(raw):
    if not shutil.which('pdftotext'):
        return None
    p = subprocess.run(['pdftotext', '-layout', '-', '-'], input=raw, capture_output=True)
    return unicodedata.normalize('NFKC', p.stdout.decode('utf-8', 'replace')) if p.returncode == 0 else 'unreadable: ' + p.stderr.decode()[:200]


pg, _ = detail()
link = re.search(r'href="([^"]*action=ews_payroll_pdf[^"]*)"', pg)
check('a closed month offers each employee\'s payslip as a PDF', link is not None)
ctype, disp, raw = download(adm, html.unescape(link.group(1)).split('127.0.0.1:8080')[-1]) if link else ('', '', b'')
check('...which downloads as a PDF file', ctype.startswith('application/pdf') and 'attachment' in disp and raw.startswith(b'%PDF-') and raw.rstrip().endswith(b'%%EOF'), (ctype, disp, raw[:20]))
check('...small enough for a phone (font subset)', 0 < len(raw) < 120000, len(raw))
txt = pdf_text(raw)
if txt is not None:
    flat = re.sub(r'[\u200e\u200f\u202a-\u202e]', '', txt)
    check('...with the month, the net pay and every line', all(x in flat for x in ('Payslip', MONTH_LABEL, money(BASE + 400 - 350), 'Basic salary', 'Transport', 'Project delivery', 'Advance repayment', 'Late arrival', '12 min')), flat[:600])
    check('...and the Arabic name joined and in order', 'أحمد محمد' in flat or 'محمد أحمد' in flat, flat[:300])
st, mypay, _ = emp.req('/app/?ews_view=pay&month=' + MONTH)
elink = re.search(r'href="([^"]*action=ews_payslip_pdf[^"]*)"', mypay)
check('My Pay offers the closed month\'s payslip PDF', elink is not None)
ctype, disp, raw = download(emp, html.unescape(elink.group(1)).split('127.0.0.1:8080')[-1]) if elink else ('', '', b'')
check('...and the employee can download it', ctype.startswith('application/pdf') and raw.startswith(b'%PDF-'), (ctype, raw[:60]))
st, cur_page, _ = emp.req('/app/?ews_view=pay&month=' + CURRENT)
check('an estimate has no PDF (only closed months)', 'ews_payslip_pdf' not in cur_page)
ctype, disp, raw = download(emp, html.unescape(elink.group(1)).split('127.0.0.1:8080')[-1].replace('month=' + MONTH, 'month=2020-01')) if elink else ('', '', b'')
check('...nor a month without a payslip', not raw.startswith(b'%PDF-'))
ctype, disp, raw = download(emp, html.unescape(link.group(1)).split('127.0.0.1:8080')[-1]) if link else ('', '', b'')
check('an employee cannot use the admin download', not raw.startswith(b'%PDF-'))

# ---------------------------------------------------------------- access
for action in ('ews_payroll_close', 'ews_payroll_reopen', 'ews_payroll_adjust_save'):
    emp.req('/wp-admin/admin-post.php', {'action': action, '_wpnonce': cn, 'month': MONTH, 'employee_id': E, 'kind': 'bonus', 'amount': '9999', 'reason': 'x'})
check('an employee cannot close, reopen or adjust', len(q("SELECT id FROM {p}ews_payroll_runs")) == 1 and len(q("SELECT id FROM {p}ews_pay_adjustments")) == 2)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

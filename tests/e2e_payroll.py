"""Behaviour tests for wp-admin → Payroll: salaries (with the date they take effect), payroll rules,
and each month's pay worked out from attendance: absence, late arrival, early leave without approval,
unpaid leave and approved overtime, with the days behind every figure and an Excel export.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_payroll.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, io, json, os, re, sys, urllib.request, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-payroll'

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
MONTH = php("echo date('Y-m',strtotime('first day of last month',current_time('timestamp')));").strip()
seed = json.loads(php(r"""
    $month='%s';
    foreach(['ews_pay_rates','ews_pay_adjustments','ews_payroll_runs','ews_payslips','ews_schedule','ews_time_logs','ews_overtime_requests','ews_early_leave_requests','ews_leave_requests','ews_leave_schedule_snapshots','ews_company_calendar','ews_break_sessions','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_leave_types WHERE name IN ('Unpaid Leave','Sick Leave')");
    foreach(['ews_payroll_day_divisor','ews_payroll_day_base','ews_payroll_absence_days','ews_payroll_overtime_rate','ews_payroll_overtime_rate_off','ews_payroll_max_deduction_days','ews_payroll_currency','ews_shifts'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4],false);
    update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'10:00','end'=>'16:00'],false);
    update_option('ews_grace_period',10,false); update_option('ews_feature_breaks',0,false); update_option('ews_feature_overtime',1,false);
    $mk=function($name)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1]);return $wpdb->insert_id;};
    $ids=['paula'=>$mk('Paula Pay'),'rafe'=>$mk('Rafe Raise'),'nadia'=>$mk('Nadia Nosalary')];
    $work=[];$fridays=[];
    for($t=strtotime($month.'-01');date('Y-m',$t)===$month;$t=strtotime('+1 day',$t)){$w=(int)date('w',$t);if($w<=4)$work[]=date('Y-m-d',$t);elseif($w===5)$fridays[]=date('Y-m-d',$t);}
    echo wp_json_encode(['ids'=>$ids,'work'=>$work,'friday'=>$fridays[0],'last'=>date('Y-m-t',strtotime($month.'-01'))]);
""" % MONTH).splitlines()[-1])
I, W, FRI = seed['ids'], seed['work'], seed['friday']
RAFE = '{:,.2f}'.format(6000 / 30 * (int(seed['last'][-2:]) - 15))  # from the 16th to the month's end
TOTAL = '{:,.2f}'.format(9690.26 + 6000 / 30 * (int(seed['last'][-2:]) - 15))
P = I['paula']


def log(eid, d, kind, t):
    php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'%s','event_at'=>'%s %s:00','scheduled_status'=>'Office','location_status'=>'office']);" % (eid, d, kind, d, t))


# Paula's month: every working day planned Office, 08:00–16:00, except:
ABSENT, LATE, GRACE, EARLY, EARLY_OK, NO_OUT, UNPAID1, UNPAID2, SICK, OT = W[1:11]
times = {LATE: ('08:12', '16:00'), GRACE: ('08:05', '16:00'), EARLY: ('08:00', '15:40'), EARLY_OK: ('08:00', '15:30'), NO_OUT: ('08:00', None), OT: ('08:00', '17:30')}
for d in W:
    php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (P, d, 'Vacation' if d in (UNPAID1, UNPAID2, SICK) else 'Office'))
    if d in (ABSENT, UNPAID1, UNPAID2, SICK):
        continue
    i, o = times.get(d, ('08:00', '16:00'))
    log(P, d, 'sign_in', i)
    if o:
        log(P, d, 'sign_out', o)
log(P, FRI, 'sign_in', '10:00')
log(P, FRI, 'sign_out', '14:00')
php("""
    $ins=function($d,$s,$e)use($wpdb,$p){$wpdb->insert($p.'ews_overtime_requests',['employee_id'=>%d,'overtime_date'=>$d,'start_time'=>$s,'end_time'=>$e,'requested_minutes'=>0,'status'=>'Approved','requested_by'=>1]);};
    $ins('%s','16:00:00','18:00:00'); $ins('%s','10:00:00','14:00:00');
    $wpdb->insert($p.'ews_early_leave_requests',['employee_id'=>%d,'work_date'=>'%s','leave_minutes'=>30,'status'=>'Approved','requested_by'=>1]);
""" % (P, OT, FRI, P, EARLY_OK))

adm = Session('admin', 'admin')

# ---------------------------------------------------------------- leave types: paid share
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-leaves')
lt_nonce = re.search(r'name="action" value="ews_leave_type_save".*?name="_wpnonce" value="([^"]+)"', page, re.S)
lt_nonce = lt_nonce.group(1) if lt_nonce else ''
check('a leave type can be set as paid in part or unpaid', 'name="paid_percent"' in page, None)
for name, pct in (('Unpaid Leave', 0), ('Sick Leave', 50)):
    adm.req('/wp-admin/admin-post.php', {'action': 'ews_leave_type_save', '_wpnonce': lt_nonce, 'id': 0, 'name': name, 'annual_entitlement': 10, 'deduct_balance': 1, 'paid_percent': pct})
types = {r['name']: r for r in q("SELECT id,name,paid_percent FROM {p}ews_leave_types WHERE name IN ('Unpaid Leave','Sick Leave')")}
check('...and it is saved (Unpaid 0%%, Sick 50%%)', {k: str(v['paid_percent']) for k, v in types.items()} == {'Unpaid Leave': '0', 'Sick Leave': '50'}, types)
check('existing leave types stay fully paid', all(str(r['paid_percent']) == '100' for r in q("SELECT paid_percent FROM {p}ews_leave_types WHERE name NOT IN ('Unpaid Leave','Sick Leave')")))
for tname, days in (('Unpaid Leave', (UNPAID1, UNPAID2)), ('Sick Leave', (SICK,))):
    php("""$wpdb->insert($p.'ews_leave_requests',['employee_id'=>%d,'leave_type_id'=>%s,'start_date'=>'%s','end_date'=>'%s','requested_days'=>%d,'status'=>'Approved','requested_by'=>1]); $rid=$wpdb->insert_id;
           foreach(%s as $d) $wpdb->insert($p.'ews_leave_schedule_snapshots',['leave_request_id'=>$rid,'employee_id'=>%d,'work_date'=>$d,'had_schedule'=>1]);"""
        % (P, types.get(tname, {}).get('id', 0), days[0], days[-1], len(days), json.dumps(list(days)).replace('"', "'"), P))

# ---------------------------------------------------------------- access
emp = Session('emp1', 'emp1pass')
check('an employee cannot open Payroll', 'Monthly Payroll' not in emp.req(PAGE)[1])
st, roles, _ = adm.req('/wp-admin/admin.php?page=ews31-roles')
check('"Manage Payroll" is a permission on the Roles page', 'Manage Payroll' in roles)
check('...that no EWS role has by default (only WordPress administrators)', php("echo get_role('ews_administrator')&&get_role('ews_administrator')->has_cap('ews_manage_payroll')?'yes':'no';").strip() == 'no')

# ---------------------------------------------------------------- salaries
st, page, _ = adm.req(PAGE + '&tab=salaries')
rate_nonce = re.search(r'name="action" value="ews_payroll_rate_save".*?name="_wpnonce" value="([^"]+)"', page, re.S)
rate_nonce = rate_nonce.group(1) if rate_nonce else ''
check('the Salaries tab lists employees without a salary', st == 200 and 'Paula Pay' in page and 'No salary' in page)


def rate(eid, start, basic, allowances=(), note=''):
    data = {'action': 'ews_payroll_rate_save', '_wpnonce': rate_nonce, 'employee_id': eid, 'effective_from': start, 'basic': basic, 'note': note,
            'allowance_name[]': [a[0] for a in allowances] or [''], 'allowance_amount[]': [str(a[1]) for a in allowances] or ['']}
    st, body, h = adm.req('/wp-admin/admin-post.php', data)
    loc = html.unescape(h.get('Location', '')).split('127.0.0.1:8080')[-1]
    return loc, (adm.req(loc)[1] if loc else body)


loc, body = rate(P, MONTH + '-01', '-5')
check('a negative salary is refused, with the reason', 'must be zero or more' in body and not q("SELECT id FROM {p}ews_pay_rates"), loc)
loc, body = rate(P, '2026-02-31', '9000')
check('...and so is an invalid date', 'valid date' in body and not q("SELECT id FROM {p}ews_pay_rates"))
loc, body = rate(P, MONTH + '-01', '9000', [('Transport', 600), ('Meals', 900)], 'Contract 2026')
r = q("SELECT basic,allowances,effective_from FROM {p}ews_pay_rates WHERE employee_id=%d" % P)
check('a salary is saved with its allowances and start date', r and float(r[0]['basic']) == 9000 and json.loads(r[0]['allowances']) == [{'name': 'Transport', 'amount': 600.0}, {'name': 'Meals', 'amount': 900.0}] and r[0]['effective_from'] == MONTH + '-01', r)
audit = q("SELECT details FROM {p}ews_audit_log WHERE action='pay_rate_saved'")
check('...recorded in the Audit Log without the amounts', audit and '9000' not in audit[0]['details'] and '600' not in audit[0]['details'] and 'Paula Pay' in audit[0]['details'], audit)
rate(I['rafe'], MONTH + '-16', '6000')
st, page, _ = adm.req(PAGE + '&tab=salaries')
check('the Salaries tab shows each salary and when it started', re.search(r'Paula Pay.*?9,000\.00.*?1,500\.00.*?%s' % (MONTH + '-01'), page, re.S) is not None)

# ---------------------------------------------------------------- the month
st, page, _ = adm.req(PAGE + '&month=' + MONTH)


def row(name, pg):
    m = re.search(r'<tr[^>]*data-employee="[^"]*"[^>]*>(?:(?!</tr>).)*%s.*?</tr>' % re.escape(name), pg, re.S)
    return html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', m.group(0)))) if m else ''


check('the month lists every employee with a salary and their net pay', '9,690.26' in row('Paula Pay', page), row('Paula Pay', page))
check('a salary that starts mid-month is paid for the days from its start (/30)', RAFE in row('Rafe Raise', page), (RAFE, row('Rafe Raise', page)))
check('an employee without a salary is listed as such, not paid', 'No salary' in row('Nadia Nosalary', page), row('Nadia Nosalary', page))
check('days that need a look are flagged (missing Sign Out)', '1 to review' in row('Paula Pay', page), row('Paula Pay', page))
check('the total is the sum of the net pay', TOTAL in html.unescape(page), TOTAL)

st, page, _ = adm.req(PAGE + '&month=' + MONTH + '&employee=%d' % P)
text = html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', page)))


def fmt(d):
    return php("echo date('D j M',strtotime('%s'));" % d).strip()


check('the breakdown shows basic, allowances and the day value', all(x in text for x in ('9,000.00', 'Transport', '600.00', 'Meals', '900.00', '350.00')), text[:400])
check('absence: one day, its date, one day\'s pay', 'Absent' in text and fmt(ABSENT) in text and '−350.00' in text)
check('late: minutes past the shift start, after the grace, per day', fmt(LATE) in text and '12 min' in text and '−8.75' in text and fmt(GRACE) not in text)
check('early leave: only without an approved request', fmt(EARLY) in text and '20 min' in text and '−14.58' in text and fmt(EARLY_OK) not in text)
check('unpaid leave: the unpaid share of each leave day', fmt(UNPAID1) in text and fmt(UNPAID2) in text and '−700.00' in text and fmt(SICK) in text and '50%' in text and '−175.00' in text)
check('overtime: approved and worked, × 1.35 on work days and × 2 on days off', '1:30' in text and '+88.59' in text and fmt(FRI) in text and '4:00' in text and '+350.00' in text and '+438.59' in text)
check('the day without a Sign Out is listed for review', fmt(NO_OUT) in text and 'No Sign Out' in text)
check('net pay', '9,690.26' in text)

# ---------------------------------------------------------------- rules
st, page, _ = adm.req(PAGE + '&tab=rules')
rules_nonce = re.search(r'name="action" value="ews_payroll_rules_save".*?name="_wpnonce" value="([^"]+)"', page, re.S)
rules_nonce = rules_nonce.group(1) if rules_nonce else ''
base_rules = {'action': 'ews_payroll_rules_save', '_wpnonce': rules_nonce, 'currency': 'EGP', 'day_divisor': 30, 'day_base': 'gross', 'absence_days': 1,
              'overtime_rate': '1.35', 'overtime_rate_off': '2', 'max_deduction_days': 0}
check('the Rules tab shows the rules with their defaults', st == 200 and 'value="30"' in page and 'value="1.35"' in page)
st, _, h = adm.req('/wp-admin/admin-post.php', dict(base_rules, day_divisor=0))
check('an impossible rule is refused', php("echo get_option('ews_payroll_day_divisor','unset');").strip() == 'unset' and 'payroll_error' in h.get('Location', ''), h.get('Location'))
adm.req('/wp-admin/admin-post.php', dict(base_rules, max_deduction_days=1))
check('a saved rule applies at once: deductions capped at one day', '10,588.59' in row('Paula Pay', adm.req(PAGE + '&month=' + MONTH)[1]), row('Paula Pay', adm.req(PAGE + '&month=' + MONTH)[1]))
adm.req('/wp-admin/admin-post.php', base_rules)

# ---------------------------------------------------------------- export
st, page, _ = adm.req(PAGE + '&month=' + MONTH)
exp = re.search(r'href="([^"]*action=ews_payroll_export[^"]*)"', page)
xml, ctype = '', ''
try:
    resp = adm.op.open(urllib.request.Request(B + html.unescape(exp.group(1)).split('127.0.0.1:8080')[-1]))
    ctype, raw = resp.headers.get('Content-Type', ''), resp.read()
    zf = zipfile.ZipFile(io.BytesIO(raw))
    xml = ' '.join(zf.read(n).decode() for n in zf.namelist() if n.startswith('xl/worksheets/'))
except Exception as e:  # noqa: BLE001
    xml = 'not a workbook: %s' % e
h = {'Content-Type': ctype}
check('Excel export: one row per employee with every figure', 'spreadsheetml' in h.get('Content-Type', '') and 'Paula Pay' in xml and '9690.26' in xml and '438.59' in xml, (h.get('Content-Type'), xml[:200]))
check('...and a sheet with the days behind the figures', fmt(LATE) in xml or LATE in xml)
check('...recorded in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='payroll_exported'")) == 1)
st, body, _ = emp.req(html.unescape(exp.group(1)).split('127.0.0.1:8080')[-1] if exp else PAGE)
check('an employee cannot export', 'Paula Pay' not in body)

# ---------------------------------------------------------------- settings overview
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-settings-overview')
check('the payroll rules are on Settings Overview', 'ews_payroll_day_divisor' in page and 'Payroll' in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

"""Behaviour tests for the 3.31.90 money and permission fixes (docs/tasks/bug-review-2026-10.md, second
review): closing a payroll month is all or nothing; an archived employee is paid up to their last
working day; the Sign In / Out Report keeps a manager to their own department; a correction's payroll
carry-over is added once and says so when it could not be saved.

Writes are made to fail on purpose with tests/e2e_break_writes.php (copied to the test site's
mu-plugins while a check needs it).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_money_safety.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, break_writes, check, d, php, q, results, wp, HERE  # noqa: E402

PAY = '/wp-admin/admin.php?page=ews31-payroll'
TR = '/wp-admin/admin.php?page=ews31-time-report'

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
break_writes('')
seed = json.loads(php(r"""
    $month=date('Y-m',strtotime('first day of last month',current_time('timestamp')));
    foreach(['ews_pay_rates','ews_pay_adjustments','ews_payroll_runs','ews_payslips','ews_schedule','ews_time_logs','ews_departments','ews_audit_log','ews_attendance_corrections'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name<>'emp1'");
    foreach(['trmgr'] as $l){$u=get_user_by('login',$l); if($u){require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID);}}
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'10:00','end'=>'16:00'],false);
    foreach(['ews_payroll_max_deduction_days','ews_payroll_day_divisor','ews_payroll_absence_days'] as $o) delete_option($o);
    $wpdb->insert($p.'ews_departments',['name'=>'Sales','active'=>1]); $da=$wpdb->insert_id;
    $wpdb->insert($p.'ews_departments',['name'=>'Stores','active'=>1]); $db=$wpdb->insert_id;
    $mk=function($name,$dept,$active=1)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>$active,'attendance_enabled'=>1,'department_id'=>$dept]);return $wpdb->insert_id;};
    $ids=['stay'=>$mk('Sami Stay',$da),'leave'=>$mk('Lena Leaver',$da),'old'=>$mk('Omar Oldarchive',$db,0),'other'=>$mk('Bassem Stores',$db)];
    $wpdb->update($p.'ews_employees',['department_id'=>$da],['domain_name'=>'emp1']);
    $uid=wp_create_user('trmgr','trmgrpass','trmgr@example.com'); $u=new WP_User($uid); $u->set_role('subscriber'); $u->add_cap('ews_manage_time');
    $ids['mgr']=$mk('Mona Manager',$da); $wpdb->update($p.'ews_employees',['wp_user_id'=>$uid],['id'=>$ids['mgr']]);
    foreach(['stay','leave','old'] as $k) $wpdb->insert($p.'ews_pay_rates',['employee_id'=>$ids[$k],'effective_from'=>$month.'-01','basic'=>6000,'allowances'=>'[]','created_by'=>1]);
    // Every day of the month worked (Sign In 08:00, Sign Out 16:00), so nothing is deducted.
    for($t=strtotime($month.'-01');date('Y-m',$t)===$month;$t=strtotime('+1 day',$t)){$dd=date('Y-m-d',$t);
        foreach(['stay','leave','old'] as $k){ if($k==='leave' && $dd>$month.'-20')continue;
            $wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$dd,'status'=>'Office']);
            foreach([['sign_in','08:00'],['sign_out','16:00']] as $x)$wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids[$k],'user_id'=>0,'work_date'=>$dd,'event_type'=>$x[0],'event_at'=>$dd.' '.$x[1].':00','scheduled_status'=>'Office','source'=>'app']);}}
    echo wp_json_encode(['ids'=>$ids,'month'=>$month,'days'=>(int)date('t',strtotime($month.'-01')),'today'=>current_time('Y-m-d')]);
""").splitlines()[-1])
I, MONTH = seed['ids'], seed['month']
LAST = MONTH + '-20'
adm = Session('admin', 'admin')


def form_nonce(page, action):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    return None


def pay_of(eid):
    out = php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'payroll_month'); $r->setAccessible(true); $x=$r->invoke($m,'%s',%d); echo wp_json_encode($x?$x[0]['pay']:null);" % (MONTH, eid))
    return json.loads(out.splitlines()[-1])


# ---------------------------------------------------------------- archived: paid up to the last working day
emp_page = adm.req('/wp-admin/admin.php?page=ews31-employees&s=Lena')[1]
check('Archive asks for the last working day (today suggested)', 'name="last_working_day"' in emp_page and 'value="%s"' % seed['today'] in emp_page)
n_arch = form_nonce(emp_page, 'ews31_employee_archive')
_, qs, _ = adm.post('ews31_employee_archive', _wpnonce=n_arch, id=I['leave'], last_working_day=LAST)
row = q("SELECT active,last_working_day FROM {p}ews_employees WHERE id=%d" % I['leave'])[0]
check('archived with the last working day given', qs.get('employee_notice') == 'archived' and row['active'] == '0' and row['last_working_day'] == LAST, (qs, row))
check('...it is in the Audit Log', 'last working day ' + LAST in json.dumps(q("SELECT details FROM {p}ews_audit_log WHERE action='employee_status_change'")))
p = pay_of(I['leave'])
check('the month they left is still on the payroll, paid for 20 days (6000 / 30 × 20 = 4000)', p and p['earned'] == 4000 and p['prorated']['to'] == LAST and p['prorated']['days'] == 20, p and p.get('prorated'))
check('...the days after the last working day are not counted as absences', p and p['absence']['amount'] == 0 and p['stats']['absent_days'] == 0, p and p['stats'])
pg = adm.req(PAY + '&month=' + MONTH)[1]
check('the payroll page lists them, marked archived with the last working day', 'Lena Leaver' in pg and 'last working day' in pg and 'data-archived' in pg, [x in pg for x in ('Lena Leaver', 'last working day', 'data-archived')])
check('...and shows "to <last day>" under the pay', re.search(r'to [^<]*20', pg) is not None)
php("$wpdb->update($p.'ews_employees',['last_working_day'=>null],['id'=>%d]);" % I['old'])
check('archived before this version (no last working day) but signed in this month: a warning names them', 'data-no-last-day' in adm.req(PAY + '&month=' + MONTH)[1] and 'Omar Oldarchive' in adm.req(PAY + '&month=' + MONTH)[1])
check('...and they are not paid', pay_of(I['old']) is None)
n_arch2 = form_nonce(adm.req('/wp-admin/admin.php?page=ews31-employees&s=Sami')[1], 'ews31_employee_archive')
_, qs, _ = adm.post('ews31_employee_archive', _wpnonce=n_arch2, id=I['other'], last_working_day='not-a-date')
check('an invalid last working day: today is used', q("SELECT last_working_day FROM {p}ews_employees WHERE id=%d" % I['other'])[0]['last_working_day'] == seed['today'])
php("$wpdb->update($p.'ews_employees',['active'=>1,'last_working_day'=>null],['id'=>%d]);" % I['other'])

# ---------------------------------------------------------------- closing a month is all or nothing
n_close = form_nonce(adm.req(PAY + '&month=' + MONTH)[1], 'ews_payroll_close')
check('the month can be closed (Close form shown)', bool(n_close))
break_writes('ews_payslips')
_, qs, _ = adm.post('ews_payroll_close', _wpnonce=n_close, month=MONTH)
break_writes('')
check('a payslip that cannot be saved: the month is not closed', qs.get('payroll_error') == 'save' and not q("SELECT id FROM {p}ews_payroll_runs") and not q("SELECT id FROM {p}ews_payslips"), (qs, q("SELECT id FROM {p}ews_payroll_runs")))
check('...the page says nothing was closed', 'Nothing was closed' in adm.req(PAY + '&month=' + MONTH + '&payroll_error=save')[1])
_, qs, _ = adm.post('ews_payroll_close', _wpnonce=n_close, month=MONTH)
runs = q("SELECT id,employees FROM {p}ews_payroll_runs")
slips = q("SELECT employee_id FROM {p}ews_payslips")
check('closed again: one run with every payslip (the employee who left included)', qs.get('payroll_saved') == 'closed' and len(runs) == 1 and int(runs[0]['employees']) == len(slips) == 2
      and {int(s['employee_id']) for s in slips} == {I['stay'], I['leave']}, (qs, runs, slips))

# ---------------------------------------------------------------- correction carry-over: once, and said when it failed
def carry(cid, before):
    out = php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'cx_payroll_carry'); $r->setAccessible(true); $wpdb->suppress_errors(true);"
              "echo wp_json_encode($r->invoke($m,(object)['id'=>%d,'employee_id'=>%d,'employee_name'=>'Sami Stay','work_date'=>'%s'],%s));" % (cid, I['stay'], MONTH + '-10', before))
    return json.loads(out.splitlines()[-1])


net = float(pay_of(I['stay'])['net'])
carry(901, net - 100)
carry(901, net - 100)
adj = q("SELECT kind,amount FROM {p}ews_pay_adjustments WHERE reason LIKE 'Attendance correction #901%%'")
check('a correction in a closed month is carried once, even when run twice', len(adj) == 1 and adj[0]['kind'] == 'bonus' and float(adj[0]['amount']) == 100, adj)
break_writes('ews_pay_adjustments')
note = carry(902, net - 50)
break_writes('')
check('the adjustment cannot be saved: the note says NOT CARRIED (not that it was done)', note.startswith('NOT CARRIED') and not q("SELECT id FROM {p}ews_pay_adjustments WHERE reason LIKE 'Attendance correction #902%%'"), note)
check('...and the Audit Log tells HR to add it by hand', len(q("SELECT id FROM {p}ews_audit_log WHERE action='pay_adjustment_failed'")) == 1)

# ---------------------------------------------------------------- Sign In / Out Report: the manager's department only
DAY = d(-1)
for k in ('stay', 'other'):
    php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 09:00:00','scheduled_status'=>'Office','source'=>'app']);" % (I[k], DAY, DAY))
mine = int(q("SELECT id FROM {p}ews_time_logs WHERE employee_id=%d AND work_date='%s'" % (I['stay'], DAY))[0]['id'])
theirs = int(q("SELECT id FROM {p}ews_time_logs WHERE employee_id=%d AND work_date='%s'" % (I['other'], DAY))[0]['id'])
mgr = Session('trmgr', 'trmgrpass')
rng = '&start=%s&end=%s' % (DAY, DAY)
pg = mgr.req(TR + rng)[1]
check('a manager sees their department\'s records', 'Sami Stay' in pg)
check('...not another department\'s', 'Bassem Stores' not in pg)
check('...and the administrator sees both', all(n in adm.req(TR + rng)[1] for n in ('Sami Stay', 'Bassem Stores')))
pg = mgr.req(TR + rng + '&add_time=1')[1]
sel = re.search(r'<select[^>]*name="employee_id".*?</select>', pg, re.S)
check('the manual record form lists only their department', sel and 'Sami Stay' in sel.group(0) and 'Bassem Stores' not in sel.group(0))
check('a record of another department does not open for editing', 'name="time_id" value="%d"' % theirs not in mgr.req(TR + rng + '&edit_time_id=%d' % theirs)[1]
      and 'name="time_id" value="%d"' % mine in mgr.req(TR + rng + '&edit_time_id=%d' % mine)[1])
n_save = form_nonce(pg, 'ews31_time_save')
_, qs, _ = mgr.post('ews31_time_save', _wpnonce=n_save, employee_id=I['other'], work_date=DAY, event_type='sign_out', event_at=DAY + 'T17:00', latitude='', longitude='', accuracy='')
check('...adding a record for another department is refused', qs.get('time_error') == 'scope' and not q("SELECT id FROM {p}ews_time_logs WHERE employee_id=%d AND event_type='sign_out'" % I['other']), qs)
_, qs, _ = mgr.post('ews31_time_save', _wpnonce=n_save, time_id=theirs, employee_id=I['stay'], work_date=DAY, event_type='sign_in', event_at=DAY + 'T08:00', latitude='', longitude='', accuracy='')
check('...moving another department\'s record to their own employee is refused', qs.get('time_error') == 'scope' and int(q("SELECT employee_id FROM {p}ews_time_logs WHERE id=%d" % theirs)[0]['employee_id']) == I['other'], qs)
_, qs, _ = mgr.post('ews31_time_save', _wpnonce=n_save, employee_id=I['stay'], work_date=DAY, event_type='sign_out', event_at=DAY + 'T17:00', latitude='', longitude='', accuracy='')
check('...their own department works', qs.get('time_notice') == 'saved', qs)
n_reset = form_nonce(mgr.req(TR + rng)[1], 'ews31_time_reset')
_, qs, _ = mgr.post('ews31_time_reset', _wpnonce=n_reset, employee_id=I['other'], work_date=DAY)
check('...Reset Day of another department is refused', qs.get('time_error') == 'scope' and q("SELECT id FROM {p}ews_time_logs WHERE id=%d" % theirs), qs)
m = re.search(r'href="([^"]*action=ews31_time_csv[^"]*)"', mgr.req(TR + rng)[1])
url = m.group(1).replace('&amp;', '&').replace('&#038;', '&') if m else '/none'
csv = mgr.req(url[url.index('/wp-admin'):] if '/wp-admin' in url else url)[1]
check('...the CSV has their department only', 'Sami Stay' in csv and 'Bassem Stores' not in csv, csv[:300])
php("$wpdb->update($p.'ews_employees',['department_id'=>null],['id'=>%d]);" % I['mgr'])
check('a manager without a department sees no records', 'Sami Stay' not in mgr.req(TR + rng)[1])

php("""$wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name<>'emp1'"); $wpdb->update($p.'ews_employees',['department_id'=>null],['domain_name'=>'emp1']);
    foreach(['ews_pay_rates','ews_pay_adjustments','ews_payroll_runs','ews_payslips','ews_departments'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $u=get_user_by('login','trmgr'); if($u){require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID);}""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

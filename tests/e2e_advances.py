"""Salary advances (3.31.109, approvals stage 5; owner decisions 2026-10-17, docs/tasks/approvals.md,
mockup docs/mockups/new-types/): off by default; the employee asks from My requests → New request
within the limits (months of salary, a fixed most, instalments, one open at a time, months with the
company); by default the direct manager decides, then Finance; Finance records it as paid (the day and
how) and the instalments come off the pay from the month after, as deduction lines in Payroll; one
month's instalment can move to after the last; a waiting one can be withdrawn; an employee who leaves:
a warning when made inactive, and the rest comes off the last month's pay.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_advances.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'ensure_advances_schema');$m->setAccessible(true);$m->invoke($o);""")
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_salary_advances','ews_advance_instalments','ews_pay_rates','ews_pay_adjustments','ews_payroll_runs','ews_payslips','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_notifications','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_advances','ews_advance_settings','ews_approval_settings','ews_approval_self_rule','ews_approval_reject_note','ews_payroll_currency'] as $o) delete_option($o);
    update_option('ews_feature_my_approvals',1,false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','fina','gone')");
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID,'admin_uid'=>(int)get_user_by('login','admin')->ID];
    foreach(['mona'=>'Mona Adel','fina'=>'Fina Money'] as $login=>$name){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    $wpdb->update($p.'ews_employees',['active'=>1,'last_working_day'=>null,'created_at'=>'2020-01-01 00:00:00'],['id'=>$eid]);
    $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>$eid,'relationship_type'=>'supervisor','related_employee_id'=>$out['mona'],'active'=>1]);
    update_option('ews_approval_settings',['finance_user'=>$out['fina_uid']],false);
    $out['last']=date('Y-m',strtotime('first day of last month',current_time('timestamp')));
    $out['before']=date('Y-m-d',strtotime('first day of -2 month',current_time('timestamp')));
    $out['this']=current_time('Y-m');
    echo wp_json_encode($out);
""").splitlines()[-1])
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
mona, fina = Session('mona', 'monapass'), Session('fina', 'finapass')
ADMIN = '/wp-admin/admin.php?page=ews31-advances'


def call(method, *args):
    return json.loads(php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'%s');$m->setAccessible(true);echo wp_json_encode($m->invoke($o,%s));""" % (method, ','.join(json.dumps(a) for a in args))).splitlines()[-1])


def session_nonce(s, uid, action):
    """The nonce this logged-in session would get for an action (its form is not shown to it)."""
    jar = [h for h in s.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
    c = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
    return php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(%d); echo wp_create_nonce('%s');" % (json.dumps(urllib.parse.unquote(c.value)), uid, action)).strip().splitlines()[-1]


def requests_page(extra=''):
    return emp.req('/app/?ews_view=requests' + extra)[1]


def ask(amount, months, reason='car repair'):
    n = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*><input type="hidden" name="action" value="ews_advance_request_create"', requests_page())
    st, qs, _ = emp.post('ews_advance_request_create', _wpnonce=n.group(1) if n else session_nonce(emp, ids['emp_uid'], 'ews_advance_request_create'), amount=amount, instalments=months, reason=reason)
    return qs


def advances():
    return q("SELECT * FROM {p}ews_salary_advances ORDER BY id")


def items(page):
    return re.findall(r'<article class="wfo-apv-item[^"]*" data-apv-item="(\d+)">(.*?)</article>', page, re.S)


def decide(sess, decision, note=''):
    page = sess.req('/app/?ews_view=approvals')[1]
    for _, body in items(page):
        if 'Salary advance' in body:
            f = re.search(r'<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>', body, re.S)
            data = {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f.group(1))}
            data.update(decision=decision, note=note)
            st, qs, _ = sess.post(data.pop('action'), **data)
            return qs
    return None


def salary(eid, basic, allowances=()):
    php("$wpdb->insert($p.'ews_pay_rates',['employee_id'=>%d,'effective_from'=>'2020-01-01','basic'=>%s,'allowances'=>'%s','created_at'=>current_time('mysql')]);" % (eid, basic, json.dumps([{'name': 'x', 'amount': a} for a in allowances])))


def admin_form(action, key, value):
    page = fina.req(ADMIN)[1]
    for f in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in f and 'name="%s" value="%s"' % (key, value) in f:
            return re.search(r'name="_wpnonce" value="([^"]+)"', f).group(1)
    return 'x'


def close_month(month):
    page = adm.req('/wp-admin/admin.php?page=ews31-payroll&month=' + month)[1]
    m = re.search(r'name="action" value="ews_payroll_close".*?name="_wpnonce" value="([^"]+)"', page, re.S) or re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>(?:(?!</form>).)*?name="action" value="ews_payroll_close"', page, re.S)
    st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_close', '_wpnonce': m.group(1) if m else 'x', 'month': month})
    return html.unescape(h.get('Location', ''))


def reopen_month(month):
    page = adm.req('/wp-admin/admin.php?page=ews31-payroll&month=' + month)[1]
    m = re.search(r'name="action" value="ews_payroll_reopen".*?name="_wpnonce" value="([^"]+)"', page, re.S) or re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>(?:(?!</form>).)*?name="action" value="ews_payroll_reopen"', page, re.S)
    st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_payroll_reopen', '_wpnonce': m.group(1) if m else 'x', 'month': month})
    return html.unescape(h.get('Location', ''))


def taken(eid):
    return [int(r['taken_run_id']) for r in q("SELECT taken_run_id FROM {p}ews_advance_instalments WHERE employee_id=%d ORDER BY month,seq" % eid)]


def make_advance(eid, status, months, amount=300, taken_run=0):
    """An advance straight in the tables: one instalment per month in `months`."""
    return int(php("""$wpdb->insert($p.'ews_salary_advances',['employee_id'=>%d,'amount'=>%s,'instalments'=>%d,'status'=>'%s','requested_at'=>current_time('mysql'),'paid_on'=>%s]); $a=$wpdb->insert_id;
        if('%s'==='Paid') foreach(%s as $i=>$m) $wpdb->insert($p.'ews_advance_instalments',['advance_id'=>$a,'employee_id'=>%d,'month'=>$m,'seq'=>$i+1,'amount'=>%s/%d,'taken_run_id'=>%d]); echo $a;""" % (eid, amount, len(months), status, "'%s-01'" % months[0] if status == 'Paid' else 'null', status, json.dumps(months), eid, amount, len(months), taken_run)).splitlines()[-1])


def lines(month, eid):
    rows = call('payroll_month', month, eid)
    pay = (rows[0] if isinstance(rows, list) else list(rows.values())[0])['pay'] if rows else None
    return [(i['reason'], float(i['amount'])) for i in (pay or {}).get('manual', {}).get('items', [])]


# ---------------------------------------------------------------- off by default
page = requests_page()
check('off by default: no salary advance in New request, no Advances in wp-admin', 'wfo-new-advance' not in page and 'ews31-advances' not in adm.req('/wp-admin/admin.php?page=ews31-features')[1].split('ews-advances')[0][-2000:])
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
check('...the switch is on the Features page, off, with its limits', 'name="advances_enabled"' in page and 'name="advance_months_salary"' in page and 'name="advance_max_instalments"' in page)
check('...the Advances page says it is off', 'Turn them on in Features' in adm.req(ADMIN)[1])
qs = ask(1000, 2)
check('...a request posted directly is refused', qs.get('adv_error') == 'access' and not advances(), qs)
php("update_option('ews_feature_advances',1,false);")

# ---------------------------------------------------------------- limits
page = requests_page()
check('on, without a salary on file: the type says why and has no form', 'Your salary is not on file yet' in page and 'id="wfo-new-advance"' not in page)
salary(ids['emp'], 8000, [1000, 1000])
page = requests_page()
check('with a salary: the form, the limit (one month of basic + allowances) and up to 12 months', 'id="wfo-new-advance"' in page and '10,000 EGP' in page and 'value="12"' in page and 'value="13"' not in page)
check('...the type is in the money group with the cash icon', 'Salary advance' in page and 'data-new-advance-each' in page)
check('more than the limit is refused', ask(10001, 3).get('adv_error') == 'limit' and not advances())
check('no amount is refused', ask(0, 3).get('adv_error') == 'amount')
check('more months than allowed is refused', ask(1000, 13).get('adv_error') == 'instalments')
php("update_option('ews_advance_settings',['months_salary'=>1,'max_amount'=>5000,'max_instalments'=>12,'one_open'=>1,'min_service_months'=>0],false);")
check('a fixed most lowers the limit', ask(6000, 3).get('adv_error') == 'limit' and '5,000 EGP' in requests_page())
php("update_option('ews_advance_settings',['months_salary'=>1,'max_amount'=>0,'max_instalments'=>12,'one_open'=>1,'min_service_months'=>600],false);")
check('months with the company: too new is refused, with the reason', ask(1000, 3).get('adv_error') == 'service' and 'You can ask after 120 months' in requests_page())
php("delete_option('ews_advance_settings');")

# ---------------------------------------------------------------- asking, the path
qs = ask('3,000', 4, 'car repair')
a = advances()
check('a request is saved, waiting', qs.get('adv_sent') == '1' and len(a) == 1 and float(a[0]['amount']) == 3000 and int(a[0]['instalments']) == 4 and a[0]['status'] == 'Pending', (qs, a))
AID = int(a[0]['id']) if a else 0
wf = q("SELECT w.approval_mode,w.active FROM {p}ews_approval_workflows w WHERE w.workflow_key='salary_advance'")
steps = q("SELECT s.step_order,s.resolver_type FROM {p}ews_approval_workflow_steps s JOIN {p}ews_approval_workflows w ON w.id=s.workflow_id WHERE w.workflow_key='salary_advance' ORDER BY s.step_order")
check('the workflow never saved: the direct manager, then Finance', wf and wf[0]['approval_mode'] == 'LEVEL_2' and [s['resolver_type'] for s in steps] == ['SUPERVISOR', 'FINANCE'], (wf, steps))
check('one open at a time: a second request is refused', ask(500, 1).get('adv_error') == 'open' and len(advances()) == 1)
page = requests_page()
blk = re.search(r'data-req="advance-%d".*?</article>' % AID, page, re.S)
check('My requests shows it waiting, with Withdraw', blk and 'Salary advance' in blk.group(0) and 'value="ews_request_withdraw"' in blk.group(0))
check('...and the form now says one at a time', 'You already have an advance open' in page)
mpage = mona.req('/app/?ews_view=approvals')[1]
card = [b for _, b in items(mpage) if 'Salary advance' in b]
check('the manager sees it in My approvals, with the salary, the share of the limit and the reason', card and '3,000 EGP over 4 months (750 EGP a month)' in html.unescape(card[0]) and '10,000 EGP' in card[0] and '30%' in card[0] and 'car repair' in card[0], card[:1])
check('...Finance does not yet', not [b for _, b in items(fina.req('/app/?ews_view=approvals')[1]) if 'Salary advance' in b])
qs = decide(mona, 'approve')
check('the manager approves: it moves to Finance, still waiting', qs and qs.get('adv_done') == '1' and advances()[0]['status'] == 'Pending', qs)
check('...Finance now has it', [b for _, b in items(fina.req('/app/?ews_view=approvals')[1]) if 'Salary advance' in b])
qs = decide(fina, 'approve')
check('Finance approves: approved, waits to be paid', advances()[0]['status'] == 'Approved', (qs, advances()))
check('...the employee is told', q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='Your salary advance was approved'" % ids['emp_uid']))
check('...and Finance that it waits to be paid', q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='An advance waits to be paid'" % ids['fina_uid']))
check('My requests: approved, waits to be paid, no Withdraw', 'Approved · waits to be paid' in re.search(r'data-req="advance-%d".*?</article>' % AID, requests_page(), re.S).group(0))

# ---------------------------------------------------------------- paying
st, page, _ = emp.req(ADMIN)
check('an employee cannot open the Advances page', 'Access denied' in page or st >= 400)
page = fina.req(ADMIN)[1]
check('Finance sees it waiting to be paid', 'data-advances-waiting' in page and 'data-advance="%d"' % AID in page.split('data-advances-paying')[0])
st, qs, _ = fina.post('ews_advance_paid', _wpnonce=admin_form('ews_advance_paid', 'advance_id', AID), advance_id=AID, paid_on='2999-01-01', paid_method='cash')
check('a day in the future is refused', qs.get('adv_error') == 'date' and advances()[0]['status'] == 'Approved', qs)
st, qs, _ = fina.post('ews_advance_paid', _wpnonce=admin_form('ews_advance_paid', 'advance_id', AID), advance_id=AID, paid_on=ids['before'], paid_method='transfer', paid_note='ref 77')
a = advances()[0]
ins = q("SELECT * FROM {p}ews_advance_instalments WHERE advance_id=%d ORDER BY seq" % AID)
check('recorded as paid with the day and how', qs.get('adv_done') == 'paid' and a['status'] == 'Paid' and a['paid_on'] == ids['before'] and a['paid_method'] == 'transfer' and a['paid_note'] == 'ref 77', (qs, a))
check('...4 instalments of 750 from the month after it was paid', [float(i['amount']) for i in ins] == [750.0] * 4 and ins[0]['month'] == ids['last'], ins)
check('...the employee is told when they start', q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='Your salary advance was paid'" % ids['emp_uid']))
check('...the Audit Log has it without the amount', q("SELECT id FROM {p}ews_audit_log WHERE action='advance_paid' AND details NOT LIKE '%%3000%%'"))
st, qs, _ = fina.post('ews_advance_paid', _wpnonce=session_nonce(fina, ids['fina_uid'], 'ews_advance_paid_%d' % AID), advance_id=AID, paid_on=ids['before'], paid_method='cash')
check('...paying it twice is refused (no second set of instalments)', qs.get('adv_error') == 'access' and len(q("SELECT id FROM {p}ews_advance_instalments")) == 4, qs)

# ---------------------------------------------------------------- payroll
check('Payroll: the instalment comes off the month as a deduction line', lines(ids['last'], ids['emp']) == [('Advance instalment (1 of 4)', 750.0)], lines(ids['last'], ids['emp']))
check('...not in the month it was paid', lines(ids['before'][:7], ids['emp']) == [])
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-payroll&month=%s&employee=%d' % (ids['last'], ids['emp']))
check('...the month page shows it with "Move to the end"', 'Advance instalment (1 of 4)' in page and 'value="ews_advance_move"' in page)
page = fina.req(ADMIN)[1]
check('the Advances page: being paid back, 0 of 4 taken, 3,000 left', 'data-advance="%d"' % AID in page.split('data-advances-paying')[1] and '0 of 4 taken' in page and '3,000 EGP' in page)
loc = close_month(ids['last'])
run = q("SELECT id FROM {p}ews_payroll_runs WHERE month='%s'" % ids['last'])
check('a real close of the month: closed, and the instalment is marked taken by that run', run and 'payroll_saved=closed' in loc and taken(ids['emp'])[0] == int(run[0]['id']) and taken(ids['emp'])[1:] == [0, 0, 0], (loc, taken(ids['emp'])))
slip = q("SELECT data FROM {p}ews_payslips ORDER BY id DESC LIMIT 1")
check('...the payslip kept the advance line', slip and 'Advance instalment (1 of 4)' in slip[0]['data'], slip[:1])
page = fina.req(ADMIN)[1]
check('once the month is closed, the instalment is taken: 1 of 4, 2,250 left', '1 of 4 taken' in page and '2,250 EGP' in page)
check('...My requests says so', '2,250 EGP left' in requests_page() and '1 of 4' in requests_page())
loc = reopen_month(ids['last'])
check('reopening the month gives the instalment back (due again, 3,000 left)', taken(ids['emp']) == [0, 0, 0, 0] and 'payroll_saved=reopened' in loc and '3,000 EGP' in fina.req(ADMIN)[1], taken(ids['emp']))
close_month(ids['last'])
first = int(ins[0]['id'])
st, qs, _ = fina.post('ews_advance_move', _wpnonce=session_nonce(fina, ids['fina_uid'], 'ews_advance_move_%d' % first), instalment_id=first)
check('a taken instalment (closed month) cannot move', int(q("SELECT moved FROM {p}ews_advance_instalments WHERE id=%d" % first)[0]['moved']) == 0)
nxt = int(ins[1]['id'])
st, qs, _ = fina.post('ews_advance_move', _wpnonce=admin_form('ews_advance_move', 'instalment_id', nxt), instalment_id=nxt)
moved = q("SELECT month,moved FROM {p}ews_advance_instalments WHERE id=%d" % nxt)[0]
after = php("echo date('Y-m',strtotime('%s-01 +1 month'));" % ins[3]['month']).strip().splitlines()[-1]
check('moving the next instalment: to the month after the last one, marked moved', qs.get('adv_done') == 'moved' and int(moved['moved']) == 1 and moved['month'] == after, (qs, moved, after))
third = int(ins[2]['id'])
st, qs, _ = fina.post('ews_advance_move', _wpnonce=session_nonce(fina, ids['fina_uid'], 'ews_advance_move_%d' % third), instalment_id=third)
check('...only once per advance (the setting): a second move is refused', qs.get('adv_error') == 'moves' and int(q("SELECT moved FROM {p}ews_advance_instalments WHERE id=%d" % third)[0]['moved']) == 0, qs)
check('...an employee cannot move one', emp.post('ews_advance_move', _wpnonce='x', instalment_id=nxt)[1].get('adv_done') != 'moved')

# ---------------------------------------------------------------- leaving
php("$wpdb->update($p.'ews_employees',['active'=>0,'last_working_day'=>'%s-15'],['id'=>%d]);" % (ids['this'], ids['emp']))
rest = lines(ids['this'], ids['emp'])
check('an employee who leaves this month: what is left comes off the last pay, in one line (this month\'s instalment was moved)', rest == [('Advance: the rest (last month)', 2250.0)], rest)
check('...the warning when made inactive says what is left', call('advance_left_for', ids['emp']) == 2250.0, call('advance_left_for', ids['emp']))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employees&employee_notice=updated&advance_left=2250')
check('...shown on the Employees page', 'data-advance-left' in page and '2,250 EGP' in page and 'last pay' in page)
php("$wpdb->update($p.'ews_employees',['active'=>1,'last_working_day'=>null],['id'=>%d]);" % ids['emp'])

# ---------------------------------------------------------------- leaving: the close takes the rest, a rehire is not charged twice
reopen_month(ids['last'])
php("$wpdb->update($p.'ews_employees',['active'=>0,'last_working_day'=>'%s-15'],['id'=>%d]);" % (ids['last'], ids['emp']))
loc = close_month(ids['last'])
slip = q("SELECT data FROM {p}ews_payslips ORDER BY id DESC LIMIT 1")
check('leaving in a closed-again month: the close takes every instalment, the rest on the last payslip', 'payroll_saved=closed' in loc and all(t > 0 for t in taken(ids['emp'])) and slip and 'Advance: the rest' in slip[0]['data'], (loc, taken(ids['emp'])))
php("$wpdb->update($p.'ews_employees',['active'=>1,'last_working_day'=>null],['id'=>%d]);" % ids['emp'])
check('...made active again: no instalment is charged a second time in the next months', lines(ids['this'], ids['emp']) == [] and call('advance_left_for', ids['emp']) == 0.0, lines(ids['this'], ids['emp']))

# ---------------------------------------------------------------- an instalment in a closed month is not lost
php("$wpdb->query(\"DELETE FROM {$p}ews_salary_advances\"); $wpdb->query(\"DELETE FROM {$p}ews_advance_instalments\");")
nxt_month = php("echo date('Y-m',strtotime('first day of next month',current_time('timestamp')));").strip().splitlines()[-1]
late = make_advance(ids['emp'], 'Paid', [ids['last'], ids['this'], nxt_month], 900)
got = lines(ids['this'], ids['emp'])
check('paid late (its first month already closed): that instalment comes off the next month computed, marked as due earlier', [v for _, v in got] == [300.0, 300.0] and any('due ' + ids['last'] in r for r, _ in got), got)
php("update_option('ews_feature_advances',0,false);")
check('the feature switched off in the middle: nothing is taken', lines(ids['this'], ids['emp']) == [])
php("update_option('ews_feature_advances',1,false);")
check('...switched on again: the same instalments are still due', [v for _, v in lines(ids['this'], ids['emp'])] == [300.0, 300.0])

# ---------------------------------------------------------------- the Archive button warns, and the Advances page marks who left
qs = adm.post('ews31_employee_archive', _wpnonce=session_nonce(adm, ids['admin_uid'], 'ews31_employee_archive'), id=ids['emp'], last_working_day=ids['this'] + '-20')[1]
check('the Archive button warns about the advance still owed', qs.get('employee_notice') == 'archived' and float(qs.get('advance_left', 0)) == 900.0, qs)
page = adm.req(ADMIN)[1]
check('...and the Advances page marks the employee as having left', 'data-left-company' in page)
php("$wpdb->update($p.'ews_employees',['active'=>1,'last_working_day'=>null],['id'=>%d]);" % ids['emp'])

# ---------------------------------------------------------------- nobody pays or moves their own advance; nobody is paid who left
own = make_advance(ids['fina'], 'Approved', [ids['this']], 600)
st, qs, _ = fina.post('ews_advance_paid', _wpnonce=session_nonce(fina, ids['fina_uid'], 'ews_advance_paid_%d' % own), advance_id=own, paid_on=ids['before'], paid_method='cash')
check('Finance cannot record their own advance as paid', qs.get('adv_error') == 'self' and q("SELECT status FROM {p}ews_salary_advances WHERE id=%d" % own)[0]['status'] == 'Approved', qs)
own2 = make_advance(ids['fina'], 'Paid', [ids['this'], nxt_month], 600)
iid = int(q("SELECT id FROM {p}ews_advance_instalments WHERE advance_id=%d ORDER BY seq LIMIT 1" % own2)[0]['id'])
st, qs, _ = fina.post('ews_advance_move', _wpnonce=session_nonce(fina, ids['fina_uid'], 'ews_advance_move_%d' % iid), instalment_id=iid)
check('...nor move their own instalments', qs.get('adv_error') == 'self' and int(q("SELECT moved FROM {p}ews_advance_instalments WHERE id=%d" % iid)[0]['moved']) == 0, qs)
gone = int(php("$wpdb->insert($p.'ews_employees',['name'=>'Gone Away','domain_name'=>'gone','email'=>'gone@example.com','active'=>0,'attendance_enabled'=>1]); echo $wpdb->insert_id;").splitlines()[-1])
ga = make_advance(gone, 'Approved', [ids['this']], 600)
st, qs, _ = fina.post('ews_advance_paid', _wpnonce=session_nonce(fina, ids['fina_uid'], 'ews_advance_paid_%d' % ga), advance_id=ga, paid_on=ids['before'], paid_method='cash')
check('an advance for an employee who has left is not paid (no payslip would take it)', qs.get('adv_error') == 'inactive' and q("SELECT status FROM {p}ews_salary_advances WHERE id=%d" % ga)[0]['status'] == 'Approved', qs)

# ---------------------------------------------------------------- withdraw, reject
php("$wpdb->query(\"DELETE FROM {$p}ews_salary_advances\"); $wpdb->query(\"DELETE FROM {$p}ews_advance_instalments\");")
check('an amount under a cent per instalment is refused', ask(0.03, 4).get('adv_error') == 'amount' and not advances())
check('...and one beyond what the table holds', ask(10000000, 12).get('adv_error') == 'amount' and not advances())
qs = ask(1200, 2, 'school')
a = advances()[-1]
page = requests_page()
m = re.search(r'data-req="advance-%d".*?name="_wpnonce" value="([^"]+)"' % int(a['id']), page, re.S)
st, qs, _ = emp.post('ews_request_withdraw', _wpnonce=m.group(1) if m else 'x', kind='advance', id=a['id'])
check('a waiting advance can be withdrawn: cancelled, its approval closed', qs.get('req_done') == 'advance' and advances()[-1]['status'] == 'Cancelled'
      and q("SELECT status FROM {p}ews_approval_requests WHERE entity_type='salary_advance' AND entity_id=%d" % int(a['id']))[0]['status'] == 'CANCELLED', qs)
check('...gone from the manager\'s inbox', not [b for _, b in items(mona.req('/app/?ews_view=approvals')[1]) if 'Salary advance' in b])
ask(900, 3, 'again')
qs = decide(mona, 'reject', 'Not now')
check('the manager rejects: rejected, with the note to the employee', advances()[-1]['status'] == 'Rejected' and q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='Your salary advance was rejected'" % ids['emp_uid']), (qs, advances()[-1]))
check('...and a new one can be asked again', ask(400, 1).get('adv_sent') == '1')

php("""foreach(['ews_salary_advances','ews_advance_instalments','ews_pay_rates','ews_payroll_runs','ews_payslips','ews_approval_requests','ews_approval_steps','ews_employee_relationships'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='gone'");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0"); $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','fina')");
    foreach(['ews_feature_advances','ews_advance_settings','ews_approval_settings','ews_feature_my_approvals'] as $o) delete_option($o);""")
print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

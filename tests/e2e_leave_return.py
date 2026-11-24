"""Leave return (3.31.161, docs/tasks/leave-return.md): with the switch on, the employee is back at work on any day of an
approved leave from today on (the first day = all of it, a single remaining day too) and may change a leave again after an
approved change, a refusal or a withdrawal; the days go back to the balance from the leave days actually removed (never
twice); a decision form from an older round is refused; days in a closed payroll month stay leave; next year's carried days
follow a late return; leave approvals with levels start a new round each time. Off: the one-change rule as before.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_return.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080. Wipes Workforce One leave data.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""file_put_contents(getenv('S').'/leave_return_saved.ser',serialize(['o'=>$wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'",ARRAY_A),
    'w'=>$wpdb->get_results("SELECT id,approval_mode,active FROM {$p}ews_approval_workflows",ARRAY_A),
    't'=>$wpdb->get_results("SELECT id,carry_mode,carry_max FROM {$p}ews_leave_types",ARRAY_A)]));""")
TABLES = "['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_leave_changes','ews_approval_requests','ews_approval_steps','ews_notifications','ews_payroll_runs']"
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(%s as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=$eid");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    $wf=(int)$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='vacation'");
    $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=$wf");
    foreach(['ews_feature_my_requests_leave','ews_feature_leave_page','ews_feature_leave_return','ews_approval_settings'] as $o) delete_option($o);
    update_option('ews_feature_my_approvals',1,false); update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    $annual=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=40,kind='planned',after_days=0,carry_mode='all',carry_max=0 WHERE id=$annual");
    echo wp_json_encode(['emp'=>$eid,'annual'=>$annual,'wf'=>$wf]);
""" % TABLES).splitlines()[-1])
E = ids['emp']
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
fn = re.search(r'name="_wpnonce" value="([^"]+)"', adm.req('/wp-admin/admin.php?page=ews31-features')[1])
features_nonce = fn.group(1) if fn else ''


def features(**on):
    data = {'_wpnonce': features_nonce, 'my_requests_leave_present': '1', 'my_approvals_present': '1', 'my_approvals_enabled': '1', 'leave_return_present': '1'}
    data.update({k: '1' for k, v in on.items() if v})
    return adm.post('ews31_features_save', **data)


def levels(on):
    php("""$wf=%d; $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=$wf");
        $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'%s','active'=>%d],['id'=>$wf]);
        if(%d) $wpdb->insert($p.'ews_approval_workflow_steps',['workflow_id'=>$wf,'step_order'=>1,'step_type'=>'APPROVAL','resolver_type'=>'SPECIFIC_USER','resolver_value'=>(string)get_user_by('login','admin')->ID,'required'=>1,'active'=>1]);"""
        % (ids['wf'], 'LEVEL_1' if on else 'NONE', 1 if on else 0, 1 if on else 0))


def used():
    r = q("SELECT used FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d ORDER BY leave_year LIMIT 1" % (E, ids['annual']))
    return float(r[0]['used']) if r else 0.0


def row(lid):
    return q("SELECT start_date,end_date,status,cancellation_status,requested_days FROM {p}ews_leave_requests WHERE id=%d" % lid)[0]


def snaps(lid):
    return [r['work_date'] for r in q("SELECT work_date FROM {p}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date" % lid)]


def sched(day):
    r = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (E, day))
    return r[0]['status'] if r else None


def make(a, b, shift=0):
    """An approved leave from d(a) to d(b) through the real request and decision; shift moves it back (to start in the past)."""
    n = emp.nonce('ews_vacation_request_create')
    _, qs, _ = emp.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['annual'], start_date=d(a), end_date=d(b), reason='e2e')
    lid = int(q("SELECT MAX(id) id FROM {p}ews_leave_requests")[0]['id'])
    adm.post('ews_vacation_request_respond', _wpnonce=adm.nonce('ews_vacation_request_respond'), request_id=lid, decision='approve')
    if shift:
        php("""$l=%d;$s=%d;
            foreach($wpdb->get_col("SELECT work_date FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=$l ORDER BY work_date") as $w)
                $wpdb->query("UPDATE {$p}ews_schedule SET work_date=DATE_SUB(work_date,INTERVAL $s DAY) WHERE employee_id=%d AND work_date='$w'");
            $wpdb->query("UPDATE {$p}ews_leave_schedule_snapshots SET work_date=DATE_SUB(work_date,INTERVAL $s DAY) WHERE leave_request_id=$l");
            $wpdb->query("UPDATE {$p}ews_leave_requests SET start_date=DATE_SUB(start_date,INTERVAL $s DAY),end_date=DATE_SUB(end_date,INTERVAL $s DAY) WHERE id=$l");""" % (lid, shift, E))
    return lid, qs


def page():
    return emp.req('/app/?ews_view=requests')[1]


def item(pg, lid):
    m = re.search(r'<article class="wfo-apv-item[^"]*" data-req="leave-%d">.*?</article>' % lid, pg, re.S)
    return m.group(0) if m else ''


NONCES = {}


def ask(lid, back=None):
    """The employee's change from My requests: back on day `back`, or all of it (a page without the form: its last nonce)."""
    it = item(page(), lid)
    m = re.search(r'<form[^>]*>(?:(?!</form>).)*value="ews_leave_cancel"(?:(?!</form>).)*</form>', it, re.S)
    if m:
        NONCES[lid] = re.search(r'name="_wpnonce" value="([^"]+)"', m.group(0)).group(1)
    if lid not in NONCES:
        return {'no_form': 1}
    n = NONCES[lid]
    data = {'_wpnonce': n, 'request_id': lid, 'ews_return': 'requests'}
    data.update({'lr_mode': 'all'} if back is None else {'lr_mode': 'back', 'back_on': back})
    return emp.post('ews_leave_cancel', **data)[1]


def form(lid):
    """The decision form of the change on My approvals (its hidden fields, the round included)."""
    apv = adm.req('/app/?ews_view=approvals')[1]
    for f in re.findall(r'<form[^>]*>(?:(?!</form>).)*</form>', apv, re.S):
        if 'ews_leave_cancel_respond' in f and re.search(r'name="request_id" value="%d"' % lid, f):
            return {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f)}
    return None


def decide(lid, decision, data=None):
    data = dict(data or form(lid) or {})
    if not data:
        return {'no_form': 1}
    data.pop('action', None)
    data['decision'] = decision
    return adm.post('ews_leave_cancel_respond', **data)[1]


def withdraw(lid):
    it = item(page(), lid)
    m = re.search(r'<form[^>]*>(?:(?!</form>).)*name="kind" value="leave_cancel"(?:(?!</form>).)*</form>', it, re.S)
    if not m:
        return {'no_form': 1}
    n = re.search(r'name="_wpnonce" value="([^"]+)"', m.group(0)).group(1)
    return emp.post('ews_request_withdraw', _wpnonce=n, kind='leave_cancel', id=lid)[1]


def changes(lid):
    return [(r['round'], r['status'], r['asked_from'], r['applied_from'], float(r['days_back'] or 0)) for r in q("SELECT * FROM {p}ews_leave_changes WHERE leave_request_id=%d ORDER BY id" % lid)]


# ---------------------------------------------------------------- off (the default): one change, as before
features(my_requests_leave_enabled=True, leave_page_enabled=True)
php("$wpdb->insert($p.'ews_leave_balances',['employee_id'=>%d,'leave_type_id'=>%d,'leave_year'=>(int)current_time('Y'),'entitlement'=>40,'used'=>0,'pending'=>0]);" % (E, ids['annual']))
O, _ = make(2, 4)
check('off: the leave is approved, 3 days used, 3 leave days kept', row(O)['status'] == 'Approved' and used() == 3.0 and len(snaps(O)) == 3, (row(O), used(), snaps(O)))
qs = ask(O, d(3))
decide(O, 'approve')
check('off: back on day 2 approved: the leave ends on day 1, 2 days back', row(O)['end_date'] == d(2) and row(O)['cancellation_status'] == 'Approved' and used() == 1.0, (qs, row(O), used()))
check('off: the last day offers no change (one change only)', 'value="ews_leave_cancel"' not in item(page(), O))
check('off: no change rows are written', q("SELECT COUNT(*) c FROM {p}ews_leave_changes")[0]['c'] in ('0', 0))

# ---------------------------------------------------------------- on
features(my_requests_leave_enabled=True, leave_page_enabled=True, leave_return_enabled=True)
check('the switch is saved and audited', php("echo (int)get_option('ews_feature_leave_return');").splitlines()[-1] == '1'
      and int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='leave_return_feature_update'")[0]['c']) >= 1)
it = item(page(), O)
check('on: the leave shortened before (the owner\'s case) offers a change again, its single day included', 'value="ews_leave_cancel"' in it and 'name="lr_mode" value="all"' in it, it[:800])
qs = ask(O)
check('...asking for all of it is sent', qs.get('leave_cancel_sent') == '1' and row(O)['cancellation_status'] == 'Pending', (qs, row(O)))
check('...a change row (round 1, employee) waits', changes(O) == [('1', 'Pending', d(2), None, 0.0)], changes(O))
qs = decide(O, 'approve')
check('...approved: the leave is cancelled, its last day back to the balance, no leave day left', row(O)['status'] == 'Cancelled' and used() == 0.0 and snaps(O) == [] and sched(d(2)) != 'Vacation', (qs, row(O), used(), snaps(O), sched(d(2))))
check('...the change row says so', changes(O) == [('1', 'Approved', d(2), d(2), 1.0)], changes(O))

# The owner's scenario from the start: 3 days, back on day 2, then the last day.
A, _ = make(5, 7)
check('a 3-day leave: 3 days used', used() == 3.0 and snaps(A) == [d(5), d(6), d(7)], (used(), snaps(A)))
ask(A, d(6))
decide(A, 'approve')
check('back on day 2: the leave is day 1 only, 2 days back', row(A)['end_date'] == d(5) and float(row(A)['requested_days']) == 1.0 and used() == 1.0 and snaps(A) == [d(5)], (row(A), used(), snaps(A)))
check('...the two days are not leave in the schedule any more', sched(d(6)) != 'Vacation' and sched(d(7)) != 'Vacation', (sched(d(6)), sched(d(7))))
ask(A)
decide(A, 'approve')
check('then the last day: cancelled, its day back too (0 used), never twice', row(A)['status'] == 'Cancelled' and used() == 0.0 and snaps(A) == [], (row(A), used()))
check('...two rounds recorded', [c[:2] for c in changes(A)] == [('1', 'Approved'), ('2', 'Approved')], changes(A))
st = q("SELECT COUNT(*) c FROM {p}ews_leave_requests WHERE id=%d AND cancellation_status='Approved'" % A)[0]['c']
check('...a cancelled leave offers no change', 'value="ews_leave_cancel"' not in item(page(), A) and st in ('1', 1))

# Started today: back today = the first day = all of it.
T, _ = make(10, 12, shift=10)
check('a leave that started today: back today (all of it) is offered', 'name="lr_mode" value="all"' in item(page(), T) and row(T)['start_date'] == d(0), (row(T), item(page(), T)[:600]))
qs = ask(T)
decide(T, 'approve')
check('...approved: cancelled, 3 days back', row(T)['status'] == 'Cancelled' and used() == 0.0, (qs, row(T), used()))
Y, _ = make(14, 16, shift=15)   # yesterday .. tomorrow
qs = ask(Y, d(-1))
check('a past day cannot be picked by the employee', qs.get('leave_cancel_error') == 'day' and row(Y)['cancellation_status'] != 'Pending', (qs, row(Y)))
qs = ask(Y, d(0))
decide(Y, 'approve')
check('...today: yesterday stays leave, today and tomorrow come back', row(Y)['end_date'] == d(-1) and snaps(Y) == [d(-1)] and used() == 1.0, (qs, row(Y), snaps(Y), used()))

# Refused, withdrawn, asked again.
R, _ = make(20, 21)
base = used()
ask(R, d(21))
decide(R, 'reject')
check('refused: the leave stays, the round is closed Rejected', row(R)['cancellation_status'] == 'Rejected' and used() == base and changes(R)[0][1] == 'Rejected', (row(R), changes(R)))
qs = ask(R, d(21))
check('...the employee may ask again after a refusal', qs.get('leave_cancel_sent') == '1', qs)
qs = ask(R, d(21))
check('...but not twice while one waits (no form shown, a posted one refused)', qs.get('leave_cancel_error') == 'already_pending', qs)
qs = withdraw(R)
check('...withdrawn: the round is Withdrawn', row(R)['cancellation_status'] == 'Withdrawn' and changes(R)[1][1] == 'Withdrawn', (qs, row(R), changes(R)))
ask(R, d(21))
old = form(R)
decide(R, 'reject')
ask(R, d(21))
qs = decide(R, 'approve', old)
check('a decision form from an older round is refused (moved), the new round still waits', qs.get('leave_error') == 'moved' and row(R)['cancellation_status'] == 'Pending', (qs, row(R)))
decide(R, 'approve')
check('...the current form decides it: day 2 back', row(R)['end_date'] == d(20) and used() == base - 1, (row(R), used()))
check('...the rounds: Rejected, Withdrawn, Rejected, Approved', [c[1] for c in changes(R)] == ['Rejected', 'Withdrawn', 'Rejected', 'Approved'], changes(R))

# A payroll month closed meanwhile: its days stay leave.
P, _ = make(25, 27)
base = used()
months = sorted({d(25)[:7], d(26)[:7], d(27)[:7]})
php("$wpdb->insert($p.'ews_payroll_runs',['month'=>'%s','employees'=>1,'total_net'=>0,'currency'=>'EGP','closed_by'=>1,'closed_at'=>current_time('mysql')]);" % d(25)[:7])
ask(P)
f = form(P) or {}
decide(P, 'approve', f)
left = [x for x in (d(25), d(26), d(27)) if x[:7] == d(25)[:7]]
if len(months) == 1:
    check('closed payroll month (all of it): nothing returns, the round ends, the leave stands', row(P)['status'] == 'Approved' and row(P)['cancellation_status'] == 'Ended' and used() == base and len(snaps(P)) == 3, (row(P), used(), changes(P)))
    qs = ask(P)
    check('...the employee may still ask again (Ended)', qs.get('leave_cancel_sent') == '1', qs)
    php("$wpdb->query(\"DELETE FROM {$p}ews_payroll_runs\");")
    decide(P, 'approve')
    check('...the month reopened: cancelled, 3 days back', row(P)['status'] == 'Cancelled' and used() == base - 3, (row(P), used()))
else:
    check('closed payroll month: its days stay leave, the next month\'s come back', snaps(P) == left and used() == base - (3 - len(left)), (snaps(P), used()))

# Year-end carry: next year's row exists; the days that come back are carried too.
php("$wpdb->query(\"DELETE FROM {$p}ews_payroll_runs\");")
C, _ = make(30, 32)
y = int(q("SELECT leave_year FROM {p}ews_leave_balances WHERE id=(SELECT balance_id FROM {p}ews_leave_requests WHERE id=%d)" % C)[0]['leave_year'])
cur = q("SELECT entitlement,used,pending FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d" % (E, ids['annual'], y))[0]
carried = float(cur['entitlement']) - float(cur['used']) - float(cur['pending'])
php("$wpdb->insert($p.'ews_leave_balances',['employee_id'=>%d,'leave_type_id'=>%d,'leave_year'=>%d,'entitlement'=>%f,'carried'=>%f,'used'=>0,'pending'=>0]);" % (E, ids['annual'], y + 1, 40 + carried, carried))
ask(C, d(31))
decide(C, 'approve')
nxt = q("SELECT entitlement,carried FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d" % (E, ids['annual'], y + 1))[0]
check('carry: next year\'s carried days grow by the 2 days back (carry all)', abs(float(nxt['carried']) - (carried + 2)) < 0.01 and abs(float(nxt['entitlement']) - (40 + carried + 2)) < 0.01, (carried, nxt))
check('...recorded on the change and audited', changes(C)[-1][1] == 'Approved' and q("SELECT carry_top_up FROM {p}ews_leave_changes WHERE leave_request_id=%d" % C)[0]['carry_top_up'] in ('2.00', '2', 2)
      and int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='leave_carry_topped_up'")[0]['c']) >= 1)
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_balances WHERE leave_year=%d\");" % (y + 1))

# Leave approvals with a level: each change is a new round of the approval.
levels(True)
L, _ = make(35, 37)
check('with levels: the leave is approved by its level', row(L)['status'] == 'Approved', row(L))
base = used()
qs = ask(L, d(36))
rounds = q("SELECT entity_round,status FROM {p}ews_approval_requests WHERE entity_type='leave_cancellation' AND entity_id=%d ORDER BY id" % L)
check('...the first change starts round 1 of the approval', qs.get('leave_cancel_sent') == '1' and [r['entity_round'] for r in rounds] == ['1'], (qs, rounds))
decide(L, 'approve')
check('...approved at its level: 2 days back', row(L)['end_date'] == d(35) and used() == base - 2, (row(L), used()))
qs = ask(L)
rounds = q("SELECT entity_round,status FROM {p}ews_approval_requests WHERE entity_type='leave_cancellation' AND entity_id=%d ORDER BY id" % L)
check('...the second change starts round 2', qs.get('leave_cancel_sent') == '1' and [r['entity_round'] for r in rounds] == ['1', '2'], (qs, rounds))
decide(L, 'approve')
check('...approved: cancelled, its last day back', row(L)['status'] == 'Cancelled' and used() == base - 3, (row(L), used()))
levels(False)

# Switched off again: a shortened leave keeps the one-change rule.
S2, _ = make(40, 42)
ask(S2, d(41))
decide(S2, 'approve')
features(my_requests_leave_enabled=True, leave_page_enabled=True)
check('switched off again: the shortened leave offers no change', row(S2)['end_date'] == d(40) and 'value="ews_leave_cancel"' not in item(page(), S2), row(S2))

php("""$s=unserialize(file_get_contents(getenv('S').'/leave_return_saved.ser')); $keep=[];
    foreach($s['o'] as $o){ $keep[]=$o['option_name']; $wpdb->replace($wpdb->options,$o); }
    foreach($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'") as $n) if(!in_array($n,$keep,true)) $wpdb->delete($wpdb->options,['option_name'=>$n]);
    wp_cache_flush();
    foreach(""" + TABLES + """ as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=""" + str(E) + """");
    foreach($s['w'] as $w) $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>$w['approval_mode'],'active'=>$w['active']],['id'=>$w['id']]);
    foreach($s['t'] as $t) $wpdb->update($p.'ews_leave_types',['carry_mode'=>$t['carry_mode'],'carry_max'=>$t['carry_max']],['id'=>$t['id']]);""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

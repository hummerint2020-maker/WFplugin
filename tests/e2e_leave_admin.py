"""Behaviour tests for the wp-admin "Leaves" page: leave types, recording leave for an employee,
and annual balances.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_admin.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, today, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-leaves'
YEAR = today.year
NEXT = YEAR + 1


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_approval_requests','ews_approval_steps','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("DELETE FROM {$p}ews_leave_types WHERE name NOT IN ('Annual Leave','Mission')");
        $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21 WHERE name='Annual Leave'");
        if(!$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Mission'")) $wpdb->insert($p.'ews_leave_types',['name'=>'Mission','active'=>1,'deduct_balance'=>0,'annual_entitlement'=>0]);
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        $wf=$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='vacation'");
        $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".(int)$wf);
        $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]);
    """)


def type_id(name):
    rows = q("SELECT id FROM {p}ews_leave_types WHERE name='%s'" % name)
    return int(rows[0]['id']) if rows else 0


def leave_type(name):
    rows = q("SELECT annual_entitlement,deduct_balance,active FROM {p}ews_leave_types WHERE name='%s'" % name)
    return {k: float(v) for k, v in rows[0].items()} if rows else None


def bal(year, t='Annual Leave'):
    rows = q("SELECT id,entitlement,used,pending FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d" % (ids()['eid'], type_id(t), year))
    return {k: float(v) for k, v in rows[0].items()} if rows else None


def last_leave():
    rows = q("SELECT id,status,requested_days,balance_id FROM {p}ews_leave_requests ORDER BY id DESC LIMIT 1")
    return rows[0] if rows else None


def sched(date):
    rows = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ids()['eid'], date))
    return rows[0]['status'] if rows else None


def page_nonce(sess, action):
    st, page, _ = sess.req(PAGE)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    return None


seed()
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
eid = ids()['eid']
annual, mission = type_id('Annual Leave'), type_id('Mission')

st, page, _ = adm.req(PAGE)
check('Leaves page opens for the administrator', st == 200 and 'Leave Management' in page, st)
st, page, _ = emp.req(PAGE)
check('employee cannot open the Leaves page', 'Leave Management' not in page, st)

# ---------------------------------------------------------------- leave types
n_type = page_nonce(adm, 'ews_leave_type_save')
adm.post('ews_leave_type_save', _wpnonce=n_type, id=0, name='Sick Leave', annual_entitlement='7.5')
check('a leave type can be added', leave_type('Sick Leave') == {'annual_entitlement': 7.5, 'deduct_balance': 0.0, 'active': 1.0}, leave_type('Sick Leave'))
sick = type_id('Sick Leave')
adm.post('ews_leave_type_save', _wpnonce=n_type, id=sick, name='Sick Leave', annual_entitlement='10', deduct_balance=1, active=1)
check('a leave type can be edited', leave_type('Sick Leave') == {'annual_entitlement': 10.0, 'deduct_balance': 1.0, 'active': 1.0}, leave_type('Sick Leave'))
adm.post('ews_leave_type_save', _wpnonce=n_type, id=sick, name='Sick Leave', annual_entitlement='10', deduct_balance=1)
check('a leave type can be deactivated', leave_type('Sick Leave')['active'] == 0.0)
before = int(q("SELECT COUNT(*) c FROM {p}ews_leave_types")[0]['c'])
adm.post('ews_leave_type_save', _wpnonce=n_type, id=0, name='  ', annual_entitlement='3')
check('a leave type needs a name', int(q("SELECT COUNT(*) c FROM {p}ews_leave_types")[0]['c']) == before)
emp.post('ews_leave_type_save', _wpnonce=n_type, id=0, name='Hacked', annual_entitlement='99')
check('employee cannot add leave types', type_id('Hacked') == 0)
adm.post('ews_leave_type_save', _wpnonce=n_type, id=999999, name='Ghost', annual_entitlement='1')
check('editing a leave type that does not exist creates nothing', type_id('Ghost') == 0)

# ---------------------------------------------------------------- balances
n_bal = page_nonce(adm, 'ews_leave_balance_save')
adm.post('ews_leave_balance_save', _wpnonce=n_bal, employee_id=eid, leave_type_id=annual, entitlement='15')
check('a balance can be set for the current year', bal(YEAR) and bal(YEAR)['entitlement'] == 15.0, bal(YEAR))
adm.post('ews_leave_balance_save', _wpnonce=n_bal, employee_id=eid, leave_type_id=annual, entitlement='25', leave_year=NEXT)
check('a balance can be set for next year', bal(NEXT) and bal(NEXT)['entitlement'] == 25.0 and bal(YEAR)['entitlement'] == 15.0, (bal(YEAR), bal(NEXT)))
adm.post('ews_leave_balance_save', _wpnonce=n_bal, employee_id=0, leave_type_id=annual, entitlement='5')
check('a balance needs an employee', int(q("SELECT COUNT(*) c FROM {p}ews_leave_balances WHERE employee_id=0")[0]['c']) == 0)
emp.post('ews_leave_balance_save', _wpnonce=n_bal, employee_id=eid, leave_type_id=annual, entitlement='99')
check('employee cannot change balances', bal(YEAR)['entitlement'] == 15.0)
st, page, _ = adm.req(PAGE)
check('the page lists the balances of the year', 'Emp One' in page and '15' in page and 'Balances' in page)

# ---------------------------------------------------------------- record leave
n_rec = page_nonce(adm, 'ews_admin_leave_record')
check('the Record Leave form is shown', bool(n_rec))


def record(start, end, t=annual, employee=None):
    _, qs, _ = adm.post('ews_admin_leave_record', _wpnonce=n_rec, employee_id=employee or eid, leave_type_id=t, start_date=start, end_date=end, reason="admin's note")
    return qs


# An employee request reserves one day first.
n_create = emp.nonce('ews_vacation_request_create')
emp.post('ews_vacation_request_create', _wpnonce=n_create, leave_type_id=annual, start_date=d(30), end_date=d(30), reason='own')
check('employee request reserves 1 pending day', bal(YEAR if d(30)[:4] == str(YEAR) else NEXT)['pending'] == 1.0)

past = [d(-3), d(-2)] if d(-3)[:4] == d(-2)[:4] == str(YEAR) else [f'{YEAR}-01-05', f'{YEAR}-01-06']
before = bal(YEAR)
qs = record(past[0], past[1])
r = last_leave()
check('admin records a past leave as Approved', 'leave_recorded' in qs and r['status'] == 'Approved' and float(r['requested_days']) == 2, (qs, r))
check('...the days are marked Vacation', sched(past[0]) == 'Vacation' and sched(past[1]) == 'Vacation')
check('...the days are counted as used', bal(YEAR)['used'] == before['used'] + 2, bal(YEAR))
check("...the employee's own pending reservation is untouched", bal(YEAR)['pending'] == before['pending'], (before, bal(YEAR)))
check('...the request records the balance it was charged to', int(r['balance_id'] or 0) == int(bal(YEAR)['id']), r)
check('...it is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='admin_leave_record'")[0]['c']) == 1)
check('...an apostrophe in the reason is stored as typed (no backslash)', q("SELECT reason FROM {p}ews_leave_requests WHERE id=%d" % int(r['id']))[0]['reason'] == "admin's note", q("SELECT reason FROM {p}ews_leave_requests WHERE id=%d" % int(r['id'])))

qs = record(f'{NEXT}-01-10', f'{NEXT}-01-11')
r = last_leave()
check('a next-year leave is charged to next year', 'leave_recorded' in qs and bal(NEXT)['used'] == 2.0 and int(r['balance_id'] or 0) == int(bal(NEXT)['id']), (qs, r, bal(NEXT)))
n_cancel = emp.nonce('ews_leave_cancel')
emp.post('ews_leave_cancel', _wpnonce=n_cancel, request_id=r['id'])
n_cresp = adm.nonce('ews_leave_cancel_respond')
adm.post('ews_leave_cancel_respond', _wpnonce=n_cresp, request_id=r['id'], decision='approve')
check("...and cancelling it gives the days back to next year's balance", bal(NEXT)['used'] == 0.0 and bal(YEAR)['used'] == 2.0, (bal(YEAR), bal(NEXT)))

check('a range crossing two years is refused', 'leave_record_error' in record(f'{YEAR}-12-31', f'{NEXT}-01-01'))
check('end before start is refused', 'leave_record_error' in record(d(5), d(4)))
check('an unknown employee is refused', 'leave_record_error' in record(d(5), d(5), employee=999999))
check('an inactive leave type is refused', 'leave_record_error' in record(d(5), d(5), t=sick))
check('an overlapping leave is refused', 'leave_record_error' in record(past[1], past[1]))
php("update_option('ews_working_days',[1],false);")
monday = next(x for x in range(5, 12) if __import__('datetime').date.fromisoformat(d(x)).isoweekday() != 1)
check('a range without working days is refused', 'leave_record_error' in record(d(monday), d(monday)))
php("update_option('ews_working_days',[0,1,2,3,4,5,6],false);")
remaining = bal(YEAR)['entitlement'] - bal(YEAR)['used'] - bal(YEAR)['pending']
start = f'{YEAR}-12-01' if today.month < 12 else f'{NEXT}-02-01'
year_of_start = int(start[:4])
if year_of_start == YEAR:
    end = f'{YEAR}-12-{int(remaining) + 1:02d}'
    qs = record(start, end)
    check('recording more days than the balance has left is refused', 'leave_record_error' in qs and 'balance' in qs['leave_record_error'].lower(), qs)
before_m = bal(YEAR, 'Mission')
qs = record(d(40), d(41), t=mission)
check('a non-deducting leave type records without touching balances', 'leave_recorded' in qs and (bal(YEAR, 'Mission') or {}).get('used', 0.0) == 0.0, (qs, bal(YEAR, 'Mission')))
emp.post('ews_admin_leave_record', _wpnonce=n_rec, employee_id=eid, leave_type_id=annual, start_date=d(50), end_date=d(50))
check('employee cannot record leave', last_leave()['requested_days'] != '1' or sched(d(50)) != 'Vacation')

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

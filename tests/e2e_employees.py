"""Behaviour tests for the wp-admin "Employees" page: add, update and archive employees.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_employees.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-employees'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        foreach(['ews_departments','ews_teams','ews_team_members','ews_employee_relationships','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $d1=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $d2=$wpdb->insert_id;
        $u2=username_exists('emp2')?:wp_create_user('emp2','emp2pass','emp2@example.com');
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        $wpdb->update($p.'ews_employees',['department_id'=>$d1],['id'=>$eid]);
        $wpdb->insert($p.'ews_teams',['name'=>'Ops Team','department_id'=>$d1,'manager_employee_id'=>$eid,'active'=>1]); $t1=$wpdb->insert_id;
        $wpdb->insert($p.'ews_teams',['name'=>'Sales Team','department_id'=>$d2,'active'=>1]); $t2=$wpdb->insert_id;
        $wpdb->insert($p.'ews_team_members',['team_id'=>$t1,'employee_id'=>$eid,'active'=>1]);
        echo wp_json_encode(compact('d1','d2','t1','t2','u2'));
    """)
    return json.loads(out.splitlines()[-1])


def emp(where):
    rows = q("SELECT * FROM {p}ews_employees WHERE %s" % where)
    return rows[0] if rows else None


def supervisor_of(eid):
    rows = q("SELECT related_employee_id FROM {p}ews_employee_relationships WHERE employee_id=%d AND relationship_type='supervisor' AND active=1" % eid)
    return int(rows[0]['related_employee_id']) if rows else 0


def page_nonce(sess, action, employee_id=None):
    st, page, _ = sess.req(PAGE)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form and (employee_id is None or 'name="id" value="%d"' % employee_id in form):
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    # Row forms may keep their fields outside the <form> element (form="..." attribute).
    m = re.search(r'<form[^>]*id="ews-employee-%s"[^>]*>.*?name="_wpnonce" value="([^"]+)"' % employee_id, page, re.S) if employee_id else None
    return m.group(1) if m else None


ctx = seed()
eid = ids()['eid']
adm, emp1 = Session('admin', 'admin'), Session('emp1', 'emp1pass')
st, page, _ = adm.req(PAGE)
check('Employees page opens for the administrator', st == 200 and 'Emp One' in page, st)
st, page, _ = emp1.req(PAGE)
check('employee cannot open the Employees page', 'Add Employee' not in page, st)

n_add = page_nonce(adm, 'ews31_employee_save')
check('the Add Employee form is shown', bool(n_add))


def add(**over):
    data = dict(_wpnonce=n_add, name="Sara O'Neil", domain_name='sara', email='sara@example.com', wp_user_id=ctx['u2'],
                supervisor_employee_id=eid, default_shift_id=0, department_id=ctx['d1'], attendance_enabled=1)
    data.update(over)
    return adm.post('ews31_employee_save', **data)[1]


qs = add(domain_name='emp1')
check('a duplicate domain name is refused', qs.get('employee_error') == 'domain_taken' and emp("name='Sara O''Neil'") is None, qs)
qs = add(email='not-an-email')
check('an invalid email is refused', qs.get('employee_error') == 'required' and emp("domain_name='sara'") is None, qs)
qs = add(department_id=99999)
check('an unknown department is refused', qs.get('employee_error') == 'department' and emp("domain_name='sara'") is None, qs)
qs = add(wp_user_id=ids()['uid'])
check('a WordPress user already linked to another employee is refused', qs.get('employee_error') == 'user_taken' and emp("domain_name='sara'") is None, qs)
qs = add()
sara = emp("domain_name='sara'")
check('an employee is added', sara and sara['name'] == "Sara O'Neil" and int(sara['wp_user_id']) == ctx['u2'] and int(sara['department_id']) == ctx['d1'], (qs, sara))
check('...with the chosen supervisor', sara and supervisor_of(int(sara['id'])) == eid)
check('...and the page confirms it', qs.get('employee_notice') == 'added', qs)
check('...it is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='employee_create'")[0]['c']) == 1)
sid = int(sara['id'])

st, page, _ = adm.req(PAGE)
n_upd = page_nonce(adm, 'ews31_employee_update', sid)
check('each employee has an update form', bool(n_upd))


def update(target, **over):
    e = emp('id=%d' % target)
    data = {'_wpnonce': n_upd, 'id': target, 'name': e['name'], 'domain_name': e['domain_name'], 'email': e['email'],
            'wp_user_id': e['wp_user_id'] or 0, 'default_shift_id': e['default_shift_id'] or 0, 'department_id': e['department_id'] or 0,
            'supervisor_employee_id': supervisor_of(target), 'attendance_enabled': 1, 'active': e['active']}
    data.update(over)
    data = {k: v for k, v in data.items() if v is not None}
    return adm.post('ews31_employee_update', **data)[1]


qs = update(sid, name='Sara Updated', email='sara2@example.com', **{'team_ids[]': [ctx['t1']]})
check('an employee is updated', emp('id=%d' % sid)['name'] == 'Sara Updated' and emp('id=%d' % sid)['email'] == 'sara2@example.com' and qs.get('employee_notice') == 'updated', qs)
check('...and joins a team of their department', int(q("SELECT COUNT(*) c FROM {p}ews_team_members WHERE employee_id=%d AND team_id=%d AND active=1" % (sid, ctx['t1']))[0]['c']) == 1)
qs = update(sid, name='Should Not Save', **{'team_ids[]': [ctx['t2']]})
check('a team from another department is refused', qs.get('employee_error') == 'team_department' and emp('id=%d' % sid)['name'] == 'Sara Updated', qs)
qs = update(sid, name='Should Not Save', supervisor_employee_id=sid)
check('an employee cannot be their own supervisor (and nothing is saved)', qs.get('employee_error') == 'self_supervisor' and emp('id=%d' % sid)['name'] == 'Sara Updated', (qs, emp('id=%d' % sid)['name']))
qs = update(eid, supervisor_employee_id=sid)
check('two employees cannot supervise each other', qs.get('employee_error') == 'supervisor_loop' and supervisor_of(eid) == 0, qs)
qs = update(sid, wp_user_id=ids()['uid'])
check('linking a WordPress user already used by another employee is refused', qs.get('employee_error') == 'user_taken' and int(emp('id=%d' % sid)['wp_user_id']) == ctx['u2'], qs)
qs = update(sid, domain_name='emp1')
check('a duplicate domain name is refused on update', qs.get('employee_error') == 'domain_taken' and emp('id=%d' % sid)['domain_name'] == 'sara', qs)
qs = update(eid, department_id=0)
check('a team manager cannot leave the department of their team', qs.get('employee_error') == 'manages_team' and int(emp('id=%d' % eid)['department_id']) == ctx['d1'], (qs, emp('id=%d' % eid)['department_id']))
qs = update(eid, department_id=ctx['d2'])
check('...nor move to another department', qs.get('employee_error') == 'manages_team' and int(emp('id=%d' % eid)['department_id']) == ctx['d1'], qs)
update(sid, supervisor_employee_id=0)
check('the supervisor can be cleared', supervisor_of(sid) == 0)
update(sid, active=0)
check('an employee can be deactivated', int(emp('id=%d' % sid)['active']) == 0)
check('...and the status change is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='employee_status_change' AND entity_id=%d" % sid)[0]['c']) == 1)
update(sid, active=1)

n_arch = page_nonce(adm, 'ews31_employee_archive', sid)
qs = adm.post('ews31_employee_archive', _wpnonce=n_arch or 'x', id=sid)[1]
check('an employee can be archived', int(emp('id=%d' % sid)['active']) == 0 and qs.get('employee_notice') == 'archived', qs)
emp1.post('ews31_employee_update', _wpnonce=n_upd, id=eid, name='Hacked', domain_name='emp1', email='x@example.com')
check('employee cannot update employees', emp('id=%d' % eid)['name'] == 'Emp One')
st, page, _ = adm.req(PAGE + '&employee_error=user_taken')
check('errors are explained on the page', 'already linked to another employee' in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

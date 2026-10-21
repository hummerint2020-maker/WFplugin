"""Behaviour tests for wp-admin → Departments and Teams.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_organization.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

DEPTS = '/wp-admin/admin.php?page=ews31-departments'
TEAMS = '/wp-admin/admin.php?page=ews31-teams'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        foreach(['ews_departments','ews_teams','ews_team_members','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $mk=function($name)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1]);return $wpdb->insert_id;};
        echo wp_json_encode(['omar'=>$mk('Omar Ops'),'olga'=>$mk('Olga Ops'),'sam'=>$mk('Sam Sales')]);
    """)
    return json.loads(out.splitlines()[-1])


def forms(page, action):
    return [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="action" value="%s"' % action in f]


def nonce(form):
    m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
    return m.group(1) if m else ''


def post(sess, data):
    st, body, h = sess.req('/wp-admin/admin-post.php', data)
    return st, body, html.unescape(h.get('Location', '')).split('127.0.0.1:8080')[-1]


def shown(sess, loc):
    """The notices of the page a save returned to."""
    st, page, _ = sess.req(loc)
    return html.unescape(' '.join(re.sub(r'<[^>]+>', '', n).strip() for n in re.findall(r'<div class="notice[^"]*"[^>]*>(.*?)</div>', page, re.S)))


def audit(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


ids = seed()
adm = Session('admin', 'admin')
st, page, _ = adm.req(DEPTS)
dn = nonce(forms(page, 'ews_department_save')[0]) if forms(page, 'ews_department_save') else ''


def dept(**f):
    return post(adm, dict({'action': 'ews_department_save', '_wpnonce': dn, 'department_id': 0, 'name': '', 'code': '', 'description': '', 'manager_employee_id': 0}, **{k: str(v) for k, v in f.items()}))


def did(name):
    return int(q("SELECT id FROM {p}ews_departments WHERE name='%s'" % name)[0]['id'])


# ---------------------------------------------------------------- departments
check('the Departments page opens with the create form', st == 200 and dn and 'No departments created yet.' in page)
st, _, loc = dept(name='Operations', code='ops')
check('a department is created', 'department_saved=1' in loc and [(r['code'], str(r['active'])) for r in q("SELECT code,active FROM {p}ews_departments WHERE name='Operations'")] == [('ops', '1')], loc)
check('...and recorded in the Audit Log as created', audit('department_create') == ['Operations / ops'], (audit('department_create'), audit('department_update')))
dept(name='Sales', code='sales')
php("$wpdb->update($p.'ews_employees',['department_id'=>%d],['id'=>%d]); $wpdb->update($p.'ews_employees',['department_id'=>%d],['id'=>%d]); $wpdb->update($p.'ews_employees',['department_id'=>%d],['id'=>%d]);"
    % (did('Operations'), ids['omar'], did('Operations'), ids['olga'], did('Sales'), ids['sam']))
st, _, loc = dept(name='Operations', code='ops2')
check('a duplicate name is refused, with the reason', 'Department name or code already exists' in shown(adm, loc) and len(q("SELECT id FROM {p}ews_departments")) == 2, shown(adm, loc))
st, _, loc = dept(name='Support', code='')
check('a code is required', 'Name and code are required' in shown(adm, loc))
st, _, loc = dept(department_id=did('Operations'), name='Operations', code='ops', manager_employee_id=ids['sam'])
check('a manager from another department is refused', 'Manager must belong to this department' in shown(adm, loc) and q("SELECT manager_employee_id FROM {p}ews_departments WHERE name='Operations'")[0]['manager_employee_id'] in (None, '', '0', 0))
st, _, loc = dept(department_id=did('Operations'), name='Operations', code='ops', description='Field work', manager_employee_id=ids['omar'])
check('a manager from the department is saved, and the edit is recorded as an update', str(q("SELECT manager_employee_id FROM {p}ews_departments WHERE name='Operations'")[0]['manager_employee_id']) == str(ids['omar']) and audit('department_update') == ['Operations / ops'], audit('department_update'))
st, page, _ = adm.req(DEPTS)
check('the list shows manager and employee count', re.search(r'<strong>Operations</strong>.*?Field work.*?<td>ops</td><td>Omar Ops</td><td>2</td>', page, re.S) is not None)
arch = next((f for f in forms(page, 'ews_department_delete') if 'name="department_id" value="%d"' % did('Sales') in f), '')
check('archiving asks for confirmation (no inline script)', 'data-ews-confirm="Archive this department?"' in arch and 'onclick' not in arch, arch[:200])
st, _, loc = post(adm, {'action': 'ews_department_delete', '_wpnonce': nonce(arch), 'department_id': did('Sales')})
check('a department with active employees cannot be archived', 'Department still has active employees' in shown(adm, loc))
php("$wpdb->update($p.'ews_employees',['department_id'=>null],['id'=>%d]);" % ids['sam'])
st, _, loc = post(adm, {'action': 'ews_department_delete', '_wpnonce': nonce(arch), 'department_id': did('Sales')})
check('an empty department is archived', 'department_deleted=1' in loc and str(q("SELECT active FROM {p}ews_departments WHERE name='Sales'")[0]['active']) == '0')
st, _, loc = dept(name='Sales', code='sales2')
check('an archived department\'s name says it is archived', 'archived' in shown(adm, loc).lower(), shown(adm, loc))
st, page, _ = adm.req(DEPTS + '&department_error=' + urllib.parse.quote('Your account was hacked, call 0100'))
check('the page shows only its own messages, not text from the link', 'call 0100' not in page)

# ---------------------------------------------------------------- teams
php("$wpdb->update($p.'ews_employees',['department_id'=>%d],['id'=>%d]);" % (did('Operations'), ids['sam']))
dept(name='Support', code='support')
php("$wpdb->update($p.'ews_employees',['department_id'=>%d],['id'=>%d]);" % (did('Support'), ids['sam']))
st, page, _ = adm.req(TEAMS)
tn = nonce(forms(page, 'ews_team_save')[0]) if forms(page, 'ews_team_save') else ''


def team(**f):
    data = {'action': 'ews_team_save', '_wpnonce': tn, 'team_id': 0, 'name': '', 'description': '', 'manager_employee_id': 0, 'department_id': 0}
    data.update({k: v if isinstance(v, list) else str(v) for k, v in f.items()})
    return post(adm, data)


def members(name):
    return sorted(int(r['employee_id']) for r in q("SELECT m.employee_id FROM {p}ews_team_members m JOIN {p}ews_teams t ON t.id=m.team_id WHERE t.name='%s' AND t.active=1 AND m.active=1" % name))


check('the Teams page opens; each employee option names their department', st == 200 and tn and re.search(r'data-department="%d"' % did('Operations'), page) is not None)
st, _, loc = team(name='Field', department_id=did('Operations'), manager_employee_id=ids['omar'])
check('a team is created and its manager is a member', 'team_saved=1' in loc and members('Field') == [ids['omar']], (loc, members('Field')))
check('...recorded in the Audit Log as created', audit('team_create') == ['Field / manager=%d / members=1' % ids['omar']], (audit('team_create'), audit('team_update')))
st, _, loc = team(name='Desk', department_id=did('Operations'), manager_employee_id=ids['omar'], **{'member_ids[]': [str(ids['sam'])]})
check('a member from another department is refused, with the reason', 'belong to the team\'s department' in shown(adm, loc) and not q("SELECT id FROM {p}ews_teams WHERE name='Desk'"), shown(adm, loc))
st, _, loc = team(name='Desk', department_id=did('Operations'), manager_employee_id=ids['sam'])
check('a manager from another department is refused, with the reason', 'manager must belong' in shown(adm, loc).lower(), shown(adm, loc))
st, _, loc = team(name='', department_id=did('Operations'), manager_employee_id=ids['omar'])
check('a name is required', 'name' in shown(adm, loc).lower() and 'required' in shown(adm, loc).lower(), shown(adm, loc))
fid = int(q("SELECT id FROM {p}ews_teams WHERE name='Field'")[0]['id'])
st, _, loc = team(team_id=fid, name='Field', department_id=did('Operations'), manager_employee_id=ids['omar'], **{'member_ids[]': [str(ids['olga'])]})
check('editing the members keeps the manager in the team', members('Field') == sorted([ids['omar'], ids['olga']]) and audit('team_update') == ['Field / manager=%d / members=2' % ids['omar']], (members('Field'), audit('team_update')))
st, page, _ = adm.req(TEAMS)
check('the list shows manager and member count', re.search(r'<strong>Field</strong>.*?<td>Omar Ops</td><td>2</td>', page, re.S) is not None)
arch = next((f for f in forms(page, 'ews_team_delete') if 'name="team_id" value="%d"' % fid in f), '')
check('archiving asks for confirmation (no inline script)', 'data-ews-confirm="Archive this team?"' in arch and 'onclick' not in arch)
post(adm, {'action': 'ews_team_delete', '_wpnonce': nonce(arch), 'team_id': fid})
check('a team is archived with its memberships', members('Field') == [] and str(q("SELECT active FROM {p}ews_teams WHERE id=%d" % fid)[0]['active']) == '0')
st, _, loc = team(name='Field', department_id=did('Operations'), manager_employee_id=ids['olga'])
check('an archived team\'s name says it is archived', 'archived' in shown(adm, loc).lower(), shown(adm, loc))

# ---------------------------------------------------------------- access
emp = Session('emp1', 'emp1pass')
before = len(q("SELECT id FROM {p}ews_teams"))
post(emp, {'action': 'ews_team_save', '_wpnonce': tn, 'name': 'Sneaky', 'department_id': did('Operations'), 'manager_employee_id': ids['omar']})
post(emp, {'action': 'ews_department_save', '_wpnonce': dn, 'name': 'Sneaky', 'code': 'sneaky'})
check('an employee cannot create teams or departments', len(q("SELECT id FROM {p}ews_teams")) == before and not q("SELECT id FROM {p}ews_departments WHERE name='Sneaky'"))
check('...or open the pages', 'Active Teams' not in emp.req(TEAMS)[1] and 'Active Departments' not in emp.req(DEPTS)[1])

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

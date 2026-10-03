"""Behaviour tests for wp-admin → Work Locations: add / edit / archive locations, the default
location, seats, and each employee's assigned location (used by Sign In and the reports).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_locations.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-multi-locations'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_locations','ews_employee_locations_v321','ews_kiosks'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->update($p.'ews_employees',['name'=>'Emp One'],['id'=>$eid]);
        $wpdb->insert($p.'ews_employees',['name'=>'Old Timer','domain_name'=>'old','email'=>'old@example.com','active'=>0,'attendance_enabled'=>1]);
        echo wp_json_encode(['emp'=>$eid]);
    """)
    return json.loads(out.splitlines()[-1])


def forms(page, action):
    return [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="action" value="%s"' % action in f]


def nonce(form):
    m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
    return m.group(1) if m else ''


def locations():
    return [(r['name'], str(r['is_default']), str(r['active']), r['radius'] and int(r['radius']), r['seats'] and int(r['seats']))
            for r in q("SELECT name,is_default,active,radius,seats FROM {p}ews_locations ORDER BY id")]


def save(sess, n, **f):
    data = {'action': 'ews_multi_location_save_v321', '_wpnonce': n, 'location_name': '', 'location_latitude': '30.0444', 'location_longitude': '31.2357', 'location_radius': '200', 'active': '1'}
    data.update({k: str(v) for k, v in f.items()})
    return sess.req('/wp-admin/admin-post.php', data)


def lid(name):
    return int(q("SELECT id FROM {p}ews_locations WHERE name='%s'" % name)[0]['id'])


ids = seed()
adm = Session('admin', 'admin')
st, page, _ = adm.req(PAGE)
check('the page opens with the add form, employees and locations', st == 200 and forms(page, 'ews_multi_location_save_v321') and 'Employees by Location' in page and 'No locations configured.' in page)
n_save = nonce(forms(page, 'ews_multi_location_save_v321')[0])

# ---------------------------------------------------------------- add, validate, default
st, body, h = save(adm, n_save, location_name='HQ', location_seats='40')
check('adding a location returns with a confirmation', 'saved=1' in h.get('Location', ''), (st, h.get('Location')))
check('...the first location becomes the default', locations() == [('HQ', '1', '1', 200, 40)], locations())
st, body, _ = save(adm, n_save, location_name='Bad', location_latitude='95')
check('a latitude outside -90..90 is refused', 'Please enter a valid location name, latitude and longitude.' in body and len(locations()) == 1, locations())
st, body, _ = save(adm, n_save, location_name='', location_latitude='30')
check('a location needs a name', 'valid location name' in body and len(locations()) == 1)
save(adm, n_save, location_name='Branch', location_radius='3', location_default='1')
check('a second location set as default takes over (one default only); the radius is at least 10 m', locations() == [('HQ', '0', '1', 200, 40), ('Branch', '1', '1', 10, None)], locations())
save(adm, n_save, location_name='Far', location_radius='99999')
check('...and at most 5,000 m', locations()[-1] == ('Far', '0', '1', 5000, None), locations())

# ---------------------------------------------------------------- edit
st, page, _ = adm.req(PAGE + '&edit_location=%d' % lid('HQ'))
check('editing shows the location\'s values', 'value="HQ"' in page and 'value="40"' in page and 'Edit Location' in page)
save(adm, n_save, location_id=lid('HQ'), location_name='Head Office', location_seats='', location_default='1')
check('saving an edit renames it, clears the seats and can make it the default again', locations()[0] == ('Head Office', '1', '1', 200, None) and locations()[1][1] == '0', locations())

# ---------------------------------------------------------------- employees
st, page, _ = adm.req(PAGE)
emp_form = next((f for f in forms(page, 'ews_employee_location_save_v321') if 'name="employee_id" value="%d"' % ids['emp'] in f), '')
check('active employees are listed for assignment (inactive ones are not)', emp_form and 'Old Timer' not in page.split('Employees by Location')[1].split('Configured Locations')[0])
adm.req('/wp-admin/admin-post.php', {'action': 'ews_employee_location_save_v321', '_wpnonce': nonce(emp_form), 'employee_id': ids['emp'], 'location_id': lid('Branch')})
check('an employee is assigned a location', q("SELECT location_id FROM {p}ews_employee_locations_v321 WHERE employee_id=%d" % ids['emp'])[0]['location_id'] in (str(lid('Branch')), lid('Branch')))
st, page, _ = adm.req(PAGE)
emp_form = next((f for f in forms(page, 'ews_employee_location_save_v321') if 'name="employee_id" value="%d"' % ids['emp'] in f), '')
check('...shown as selected', re.search(r'<option value="%d"\s+selected' % lid('Branch'), emp_form) is not None)
adm.req('/wp-admin/admin-post.php', {'action': 'ews_employee_location_save_v321', '_wpnonce': nonce(emp_form), 'employee_id': ids['emp'], 'location_id': 0})
check('...and can be cleared (then Sign In uses the default location)', not q("SELECT location_id FROM {p}ews_employee_locations_v321 WHERE employee_id=%d" % ids['emp']))

# ---------------------------------------------------------------- archive
st, page, _ = adm.req(PAGE)
arch = next((f for f in forms(page, 'ews_multi_location_archive_v321') if 'name="location_id" value="%d"' % lid('Far') in f), '')
check('archiving asks for confirmation', 'data-ews-confirm="Archive this location?"' in arch, arch[:300])
adm.req('/wp-admin/admin-post.php', {'action': 'ews_multi_location_archive_v321', '_wpnonce': nonce(arch), 'location_id': lid('Far')})
check('a location is archived (kept, inactive)', locations()[-1][:3] == ('Far', '0', '0'), locations())
st, page, _ = adm.req(PAGE)
check('...listed as Archived without an Archive button', re.search(r'<strong>Far</strong>.*?Archived</td><td><a class="button button-small"[^>]*>Edit</a>\s*</td>', page, re.S) is not None)
check('...and not offered for assignment', '>Far</option>' not in page.split('Employees by Location')[1].split('Configured Locations')[0])
head = next((f for f in forms(page, 'ews_multi_location_archive_v321') if 'name="location_id" value="%d"' % lid('Head Office') in f), '')
adm.req('/wp-admin/admin-post.php', {'action': 'ews_multi_location_archive_v321', '_wpnonce': nonce(head), 'location_id': lid('Head Office')})
adm.req(PAGE)
check('archiving the default location moves the default to an active one', [l[1] for l in locations()] == ['0', '1', '0'], locations())

# ---------------------------------------------------------------- access
emp = Session('emp1', 'emp1pass')
st, body, _ = emp.req(PAGE)
check('an employee cannot open the page', st != 200 or 'Configured Locations' not in body, st)
before = locations()
save(emp, n_save, location_name='Sneaky')
check('...or add a location', locations() == before)
st, page, _ = adm.req(PAGE)
check('the page has no inline script', '<script>' not in page.split('<div class="wrap">')[1].split('</div></div>')[0] if '<div class="wrap">' in page else False)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

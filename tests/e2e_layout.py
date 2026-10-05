"""Behaviour tests for the employee app's frame: the login page, log out, the navigation (as set in
wp-admin → View Navigation), page titles and the profile menu.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_layout.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import http.cookiejar, json, os, re, sys, urllib.parse, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, NoRedirect, Session, check, php, results, wp, HERE  # noqa: E402

APP = '/app/'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Ahmed Monier','profile_image_type'=>'initials'],['id'=>$eid]);
        delete_option('ews_frontend_navigation');
        update_option('ews_employee_profile_settings',['enabled'=>1],false);
    """)


def nav(page):
    """Desktop navigation: [(label, view, active)]."""
    side = page.split('<nav class="ews-nav">')[-1].split('</nav>')[0]
    return [(html_text(m.group(3)), m.group(2), m.group(1) == 'active') for m in re.finditer(r'<a class="(active)?" href="[^"]*ews_view=([a-z-]+)[^"]*"[^>]*>.*?<span class="wfo-rail-label">([^<]*)</span></a>', side)]


def html_text(s):
    return s.replace('&amp;', '&').strip()


def title(page):
    m = re.search(r'<h1 class="ews-title">([^<]*)</h1>', page)
    return m.group(1) if m else None


seed()

# ---------------------------------------------------------------- login page
anon = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)


def anon_req(path, data=None):
    try:
        r = anon.open(urllib.request.Request(B + path, data=urllib.parse.urlencode(data).encode() if data else None))
        return r.status, r.read().decode(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), dict(e.headers)


st, page, _ = anon_req(APP)
check('a visitor sees the login form', 'id="ews_login_username"' in page and 'Welcome back' in page, st)
check('the login styles are in the stylesheet, not inline', '.ews-login-wrap{' not in page and 'workforce-one.css' in page)
anon_req('/wp-login.php')
st, body, h = anon_req('/wp-login.php', {'log': 'emp1', 'pwd': 'wrong', 'testcookie': '1', 'ews_app_login': '1', 'redirect_to': B + APP})
loc = h.get('Location', '')
check('a wrong password returns to the app login, not to wp-login.php', st in (302, 303) and loc.startswith(B + APP) and 'login_error=1' in loc, (st, loc))
st, page, _ = anon_req(APP + '?login_error=1')
check('...which explains the error', 'The username or password is incorrect.' in page)
st, page, _ = anon_req(APP + '?loggedout=1')
check('"logged out" is confirmed', 'You have been logged out successfully.' in page)

# ---------------------------------------------------------------- app frame
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
st, page, _ = emp.req(APP)
links = re.findall(r'href="([^"]*action=logout[^"]*)"', page)
check('log out comes back with the "logged out" confirmation', links and all('loggedout%3D1' in l or 'loggedout=1' in urllib.parse.unquote(l) for l in links), links)
check('the profile menu shows first + last initials', re.search(r'class="ews-profile-menu-initials"[^>]*>MM<', page) is not None, re.findall(r'class="ews-profile-menu-initials"[^>]*>([^<]*)<', page))
check('the frame script is a file (no inline profile-menu script)', 'var profileTrigger' not in page and 'workforce-one.js' in page)
views = [v for _, v, _ in nav(page)]
check('an employee does not see manager pages in the menu', 'attendance' not in views and 'reports' not in views and 'dashboard' in views, views)
st, page, _ = adm.req(APP)
check('a manager does', {'attendance', 'reports', 'attendance-insights', 'people'} <= set(v for _, v, _ in nav(page)), nav(page))

st, page, _ = emp.req(APP + '?ews_view=attendance')
check('a page you may not open shows the Dashboard, titled Dashboard', title(page) == 'Dashboard' and 'Good to see you' in page, title(page))
st, page, _ = adm.req(APP + '?ews_view=employee&employee_id=1')
check('a colleague profile highlights People in the menu', [v for _, v, a in nav(page) if a] == ['people'], [v for _, v, a in nav(page) if a])
st, page, _ = emp.req(APP + '?ews_view=Schedule')
check('the active menu item follows the page shown', [v for _, v, a in nav(page) if a] == ['schedule'] and title(page) == 'Schedule', ([v for _, v, a in nav(page) if a], title(page)))

php("update_option('ews_frontend_navigation',['schedule'=>['label'=>'Rota','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>5],'time'=>['desktop_visible'=>0]],false);")
st, page, _ = emp.req(APP)
items = nav(page)
check('custom labels, order and hidden items from View Navigation apply', items and items[0][:2] == ('Rota', 'schedule') and 'time' not in [v for _, v, _ in items], items)
php("delete_option('ews_frontend_navigation');")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

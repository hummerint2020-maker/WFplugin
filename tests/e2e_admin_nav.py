"""Behaviour tests for the wp-admin menu in sections (3.31.88, docs/mockups/admin-menu/): 8 sections,
each page with its section's tabs, every old page link still opening, a person seeing only what they
may open, a tab hidden while its feature is off, names translated.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_admin_nav.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
EID = ids()['eid']
php("""update_option('ews_feature_tasks',0,false); update_option('ews_feature_recognition',1,false); update_option('ews_feature_face_signin',1,false);
update_option('ews_feature_corrections',0,false); update_option('ews_feature_daily_workers',0,false);
$u=get_user_by('login','navmgr'); if($u) wp_delete_user($u->ID);
$id=wp_create_user('navmgr','navmgrpass','navmgr@example.com'); $u=new WP_User($id); $u->set_role('subscriber');
$u->add_cap('ews_manage_time'); $u->add_cap('ews_view_reports'); update_user_meta($id,'locale','en_US'); update_user_meta(1,'locale','en_US');""")
adm = Session('admin', 'admin')
SECTIONS = ['Home', 'People', 'Attendance', 'Requests', 'Schedule & places', 'Pay', 'Engagement', 'Settings']


def page(sess, slug, extra=''):
    return sess.req('/wp-admin/admin.php?page=' + slug + extra)


def menu(pg):
    """(name, slug, current) of every item of the Workforce One menu, in order, and the hidden slugs."""
    m = re.search(r'<li[^>]*id="toplevel_page_ews31".*?(<ul class=[\'"]wp-submenu.*?</ul>)', pg, re.S)
    items = re.findall(r'<li([^>]*)><a href=[\'"]admin\.php\?page=([a-z0-9-]+)[\'"][^>]*>(.*?)</a>', m.group(1) if m else '', re.S)
    hidden = set(re.findall(r'a\[href="admin\.php\?page=([a-z0-9-]+)"\]', pg))
    return [(html.unescape(re.sub(r'<[^>]+>', '', t)).strip(), s, 'current' in a) for a, s, t in items], hidden


def shown(pg):
    items, hidden = menu(pg)
    return [i for i in items if i[1] not in hidden]


def tabs(pg):
    m = re.search(r'<div class="wfo-admin-nav">(.*?)</div>', pg, re.S)
    if not m:
        return None, []
    sec = re.search(r'<p class="wfo-admin-nav-section">(.*?)</p>', m.group(1))
    return (html.unescape(sec.group(1)) if sec else ''), [(html.unescape(t), 'nav-tab-active' in c) for c, t in re.findall(r'<a [^>]*class="(nav-tab[^"]*)"[^>]*>(.*?)</a>', m.group(1))]


st, pg, _ = page(adm, 'ews31-teams')
items = shown(pg)
check('the menu is "Workforce One" with 8 sections, in order', 'Workforce One' in pg and [i[0] for i in items] == SECTIONS, [i[0] for i in items])
check('...each pointing at its first page', [i[1] for i in items] == ['ews31', 'ews31-employees', 'ews31-time-report', 'ews31-requests', 'ews31-schedule-config', 'ews31-payroll', 'ews31-moments', 'ews31-features'], [i[1] for i in items])
all_items, hidden = menu(pg)
check('...the other pages are still in the menu (WordPress opens only those) but hidden', len(all_items) >= 30 and {'ews31-teams', 'ews31-audit', 'ews31-employee-profile'} <= hidden, (len(all_items), sorted(hidden)[:5]))
check('...and the page shown has its section highlighted (Teams → People)', [i[0] for i in items if i[2]] == ['People'], [i for i in items if i[2]])
sec, t = tabs(pg)
check('a page shows its section and tabs, itself active (People: Employees, Departments, Teams, Branches)', sec == 'People' and t == [('Employees', False), ('Departments', False), ('Teams', True), ('Branches', False)], (sec, t))
check('a section with one page has no tabs (Home)', tabs(page(adm, 'ews31')[1])[1] == [])

SLUGS = ['ews31', 'ews31-employees', 'ews31-departments', 'ews31-teams', 'ews31-employee-branches', 'ews31-time-report', 'ews31-attendance-insights',
         'ews31-auto-attendance', 'ews31-face-reset-requests', 'ews31-requests', 'ews31-leaves', 'ews31-approvals', 'ews31-schedule-config',
         'ews31-multi-locations', 'ews31-presence-kiosks', 'ews31-payroll', 'ews31-moments', 'ews31-smart-nudges', 'ews31-achievements', 'ews31-polls',
         'ews31-recognition', 'ews31-tasks', 'ews31-features', 'ews31-notifications', 'ews31-email', 'ews31-roles', 'ews31-appearance', 'ews31-navigation',
         'ews31-employee-profile-settings', 'ews31-settings-overview', 'ews31-audit']
bad = []
for s in SLUGS:
    st, pg, _ = page(adm, s)
    if st != 200 or 'wfo-admin-nav' not in pg or 'Sorry, you are not allowed' in pg:
        bad.append((s, st))
check('every old page link still opens, inside its section (%d pages)' % len(SLUGS), not bad, bad)
st, pg, _ = page(adm, 'ews31-employee-profile', '&employee_id=%d' % EID)
check('an employee\'s profile (opened from Employees) opens, under People with Employees active', st == 200 and tabs(pg)[0] == 'People' and ('Employees', True) in tabs(pg)[1]
      and [i[0] for i in shown(pg) if i[2]] == ['People'], (st, tabs(pg)))

st, pg, _ = page(adm, 'ews31-moments')
names = [n for n, _ in tabs(pg)[1]]
check('a tab whose feature is off is not shown (Tasks off)', 'Tasks' not in names and 'Recognition' in names, names)
st, pg, _ = page(adm, 'ews31-tasks')
check('...its page still opens from a link, in its section', st == 200 and tabs(pg)[0] == 'Engagement' and 'Sorry, you are not allowed' not in pg, st)
php("update_option('ews_feature_tasks',1,false);")
check('...turned on, the tab is back', 'Tasks' in [n for n, _ in tabs(page(adm, 'ews31-moments')[1])[1]])
st, pg, _ = page(adm, 'ews31-time-report')
check('corrections off: no Corrections tab under Attendance', 'Corrections' not in [n for n, _ in tabs(pg)[1]])
php("update_option('ews_feature_corrections',1,false);")
check('...on: Corrections is a tab under Attendance', 'Corrections' in [n for n, _ in tabs(page(adm, 'ews31-time-report')[1])[1]])

mgr = Session('navmgr', 'navmgrpass')
st, pg, _ = page(mgr, 'ews31-time-report')
check('a manager allowed only Sign In / Out and reports sees Home and Attendance only', [i[0] for i in shown(pg)] == ['Home', 'Attendance'], [i[0] for i in shown(pg)])
check('...with only the tabs they may open', [n for n, _ in tabs(pg)[1]] == ['Sign In / Out', 'Insights'], tabs(pg))
st, pg, _ = page(mgr, 'ews31-payroll')
check('...and a page outside their permissions is still refused', st in (403, 500) or 'Sorry, you are not allowed' in pg or 'not allowed' in pg, st)
emp = Session('emp1', 'emp1pass')
st, pg, _ = page(emp, 'ews31-teams')
check('an employee without access gets no menu and no page', 'toplevel_page_ews31' not in pg and 'wfo-admin-nav' not in pg)

php("update_user_meta(1,'locale','ar');")
st, pg, _ = page(adm, 'ews31-teams')
ar = [i[0] for i in shown(pg)]
check('Arabic: section and tab names are translated', ar == ['الرئيسية', 'الموظفون', 'الحضور', 'الطلبات', 'الجدول والأماكن', 'المرتبات', 'التفاعل', 'الإعدادات'] and tabs(pg)[0] == 'الموظفون' and ('الفرق', True) in tabs(pg)[1], (ar, tabs(pg)))
php("""update_user_meta(1,'locale','en_US'); update_option('ews_feature_tasks',0,false); update_option('ews_feature_corrections',0,false);
$u=get_user_by('login','navmgr'); if($u) wp_delete_user($u->ID);""")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

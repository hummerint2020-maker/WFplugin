"""Behaviour tests for the wp-admin "Achievements" page: feature switch, manual grants and removing
an award from the employee profile.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_achievements.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-achievements'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        $wpdb->query("DELETE FROM {$p}ews_employee_achievements"); $wpdb->query("DELETE FROM {$p}ews_achievements WHERE rule_type='manual'");
        $wpdb->query("DELETE FROM {$p}ews_notifications"); $wpdb->query("DELETE FROM {$p}ews_audit_log");
        update_option('ews_feature_achievements',0,false);
    """)


def form_nonce(sess, url, action):
    st, page, _ = sess.req(url)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1), page
    return None, page


def awards():
    return q("SELECT a.id,d.name,d.icon,d.badge_style,d.description,d.active,d.id def_id FROM {p}ews_employee_achievements a JOIN {p}ews_achievements d ON d.id=a.achievement_id WHERE a.employee_id=%d" % ids()['eid'])


seed()
eid = ids()['eid']
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
st, page, _ = adm.req(PAGE)
check('Achievements page opens for the administrator', st == 200 and 'Achievements Feature' in page, st)
st, page, _ = emp.req(PAGE)
check('employee cannot open the page', 'Achievements Feature' not in page, st)

n_set, page = form_nonce(adm, PAGE, 'ews_achievements_settings_save')
check('while disabled, the grant form is hidden', 'ews_achievement_manual_grant' not in page)
_, qs, _ = adm.post('ews_achievements_settings_save', _wpnonce=n_set, achievements_enabled=1)
st, page, _ = adm.req(PAGE + '&achievement_notice=' + qs.get('achievement_notice', 'none'))
check('the feature can be enabled', php("echo get_option('ews_feature_achievements')?'1':'0';") == '1' and 'Achievement settings saved.' in page, qs)

n_grant, page = form_nonce(adm, PAGE, 'ews_achievement_manual_grant')
check('the grant form is shown', bool(n_grant))
check('the icon picker labels the icons as icons', 'Badge Icon' in page)


def grant(**over):
    data = dict(_wpnonce=n_grant, employee_id=eid, achievement_name="Ahmed's Helper", achievement_description='Covered a shift', achievement_icon='🤝', badge_style='star')
    data.update(over)
    return adm.post('ews_achievement_manual_grant', **data)[1]


qs = grant()
rows = awards()
check('an achievement is granted', len(rows) == 1 and rows[0]['name'] == "Ahmed's Helper" and rows[0]['icon'] == '🤝' and rows[0]['badge_style'] == 'star' and qs.get('achievement_notice') == 'granted', (qs, rows))
check('...the employee is notified', int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE user_id=%d AND entity='achievement'" % ids()['uid'])[0]['c']) == 1)
st, page, _ = adm.req(PAGE)
auto = page.split('Automatic Achievements', 1)[-1]
check('manual achievements are not listed as automatic ones', "Ahmed&#039;s Helper" not in auto and "Ahmed's Helper" not in auto)
qs = grant(achievement_name='Other', achievement_icon='<script>', badge_style='hexagon', achievement_description='')
r = [x for x in awards() if x['name'] == 'Other']
check('an unknown icon or shape falls back to the defaults, and an empty description gets the default text', r and r[0]['icon'] == '🏅' and r[0]['badge_style'] == 'circle' and r[0]['description'], r)
qs = grant(achievement_name='  ')
check('a name is required', qs.get('achievement_error') == 'required' and len(awards()) == 2, qs)
qs = grant(employee_id=999999)
check('an unknown employee is refused', qs.get('achievement_error') == 'employee', qs)
st, page, _ = adm.req(PAGE + '&achievement_error=employee')
check('errors are explained on the page', 'notice-error' in page and 'Employee not found' in page)
emp.post('ews_achievement_manual_grant', _wpnonce=n_grant, employee_id=eid, achievement_name='Self')
check('employee cannot grant achievements', len(awards()) == 2)

# Remove an award from the employee profile.
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employee-profile&employee_id=%d' % eid)
award = [x for x in awards() if x['name'] == "Ahmed's Helper"][0]
m = re.search(r'href="([^"]*action=ews_achievement_award_delete[^"]*award_id=%s[^"]*)"' % award['id'], page)
url = m.group(1).replace('&amp;', '&').replace('&#038;', '&') if m else '/none'
st, body, h = adm.req(url[url.index('/wp-admin'):] if '/wp-admin' in url else url)
loc = h.get('Location', '')
check('an award can be removed from the profile', not [x for x in awards() if x['name'] == "Ahmed's Helper"], (st, url))
check('...the definition of a removed manual achievement is retired', q("SELECT active FROM {p}ews_achievements WHERE id=%d" % int(award['def_id']))[0]['active'] == '0')
st, page, _ = adm.req(loc[loc.index('/wp-admin'):] if '/wp-admin' in loc else '/none')
check('...and the profile confirms it', 'Achievement removed' in page, loc)
check('...it is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='achievement_award_deleted'")[0]['c']) == 1)

n_set, _ = form_nonce(adm, PAGE, 'ews_achievements_settings_save')
adm.post('ews_achievements_settings_save', _wpnonce=n_set)
qs = grant()
check('granting while the feature is off is refused with a message', qs.get('achievement_error') == 'disabled', qs)
php("update_option('ews_feature_achievements',1,false);")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

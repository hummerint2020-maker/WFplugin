"""Behaviour tests for the engagement settings pages in wp-admin: Smart Nudges, Employee Moments,
Employee Profile settings, View Navigation and Recognition.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_engagement.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, today, wp, HERE  # noqa: E402

A = '/wp-admin/admin.php?page='


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name) or 'null')


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        foreach(['ews_smart_nudges','ews_employee_moments','ews_employee_moments_enabled','ews_frontend_navigation','ews_employee_profile_settings'] as $o) delete_option($o);
        $wpdb->query("DELETE FROM {$p}ews_audit_log");
        $wpdb->insert($p.'ews_employees',['name'=>'Old Timer','domain_name'=>'old','email'=>'old@example.com','active'=>0]);
        echo $wpdb->insert_id;
    """)
    return int(out.splitlines()[-1])


def form_nonce(sess, url, action):
    st, page, _ = sess.req(url)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1), page
    return None, page


inactive = seed()
eid = ids()['eid']
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
for page_slug, title in [('ews31-smart-nudges', 'Smart Nudges'), ('ews31-moments', 'Employee Moments'), ('ews31-employee-profile-settings', 'Employee Profile'), ('ews31-navigation', 'View Navigation'), ('ews31-recognition', 'Recognition')]:
    st, page, _ = adm.req(A + page_slug)
    ok = st == 200 and '<h1>%s</h1>' % title in page
    st, page, _ = emp.req(A + page_slug)
    check('%s opens for the administrator only' % title, ok and '<h1>%s</h1>' % title not in page)

# ---------------------------------------------------------------- smart nudges
n, page = form_nonce(adm, A + 'ews31-smart-nudges', 'ews_smart_nudges_save')
qs = adm.post('ews_smart_nudges_save', _wpnonce=n, enabled=1, **{'items[attendance]': 1, 'items[tasks]': 1, 'attendance_after': 30, 'attendance_repeat': 1, 'attendance_repeat_interval': 2, 'attendance_max_reminders': 50})[1]
cfg = opt('ews_smart_nudges')
check('Smart Nudges are saved and clamped', cfg['items'] == {'attendance': 1, 'tasks': 1, 'leave': 0, 'schedule': 0} and cfg['attendance_after'] == 30 and cfg['attendance_repeat_interval'] == 5 and cfg['attendance_max_reminders'] == 10, cfg)
st, page, _ = adm.req(A + 'ews31-smart-nudges&nudges_notice=' + qs.get('nudges_notice', 'none'))
check('...and the page confirms it', 'Smart Nudge settings saved.' in page, qs)
n_test, _ = form_nonce(adm, A + 'ews31-smart-nudges', 'ews_smart_nudge_test_push')
qs = adm.post('ews_smart_nudge_test_push', _wpnonce=n_test)[1]
st, page, _ = adm.req(A + 'ews31-smart-nudges&' + '&'.join('%s=%s' % kv for kv in qs.items() if kv[0] != 'page'))
check('a test push reports the test result, not "settings saved"', 'smart_nudge_test' in qs and 'Smart Nudge settings saved.' not in page and 'Test push' in page, qs)

# ---------------------------------------------------------------- moments
php("update_option('ews_employee_moments',[%d=>['birthday'=>'1980-05-05','join_date'=>'2010-01-01']],false);" % inactive)
n, page = form_nonce(adm, A + 'ews31-moments', 'ews31_employee_moments_save')
birthday = '1990-' + today.strftime('%m-%d') if today.strftime('%m-%d') != '02-29' else '1992-02-29'
qs = adm.post('ews31_employee_moments_save', _wpnonce=n, enabled=1, **{'moments[%d][birthday]' % eid: birthday, 'moments[%d][join_date]' % eid: d(-10)})[1]
m = opt('ews_employee_moments')
check('moment dates are saved', m.get(str(eid)) == {'birthday': birthday, 'join_date': d(-10)}, m)
check("...without wiping an archived employee's dates", m.get(str(inactive)) == {'birthday': '1980-05-05', 'join_date': '2010-01-01'}, m)
st, page, _ = adm.req('/app/?ews_view=dashboard')
check("today's birthday and a recent joiner are celebrated on the dashboard", 'Happy birthday, Emp One!' in page, page.count('class="wfo-moment"'))
qs = adm.post('ews31_employee_moments_save', _wpnonce=n, enabled=1, **{'moments[%d][birthday]' % eid: '2026-02-30'})[1]
check('an impossible date is refused', qs.get('moments_error') == 'date' and opt('ews_employee_moments')[str(eid)]['birthday'] == birthday, qs)
qs = adm.post('ews31_employee_moments_save', _wpnonce=n, enabled=1, **{'moments[%d][birthday]' % eid: d(5)})[1]
check('a birthday in the future is refused', qs.get('moments_error') == 'date', qs)
qs = adm.post('ews31_employee_moments_save', _wpnonce=n, **{'moments[%d][birthday]' % eid: '', 'moments[%d][join_date]' % eid: ''})[1]
check('dates can be cleared and the feature switched off', str(eid) not in opt('ews_employee_moments') and int(opt('ews_employee_moments_enabled')) == 0 and qs.get('moments_notice') == 'saved', qs)

# ---------------------------------------------------------------- employee profile settings
n, page = form_nonce(adm, A + 'ews31-employee-profile-settings', 'ews31_profile_settings_save')
check('profile settings use a normal form post', bool(n))
qs = adm.post('ews31_profile_settings_save', _wpnonce=n, enabled=1, show_name=1, show_email=1)[1]
s = opt('ews_employee_profile_settings')
check('profile visibility is saved', s['enabled'] == 1 and s['show_email'] == 1 and s['show_photo'] == 0 and qs.get('profile_notice') == 'saved', (qs, s))
check('...and audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='employee_profile_settings_update'")[0]['c']) == 1)

# ---------------------------------------------------------------- navigation
n, page = form_nonce(adm, A + 'ews31-navigation', 'ews31_navigation_save')
check('the People page can be configured', 'name="nav[people][label]"' in page)
qs = adm.post('ews31_navigation_save', _wpnonce=n, **{'nav[people][label]': "Team's Room", 'nav[people][desktop_visible]': 1, 'nav[people][desktop_order]': 5, 'nav[people][mobile_label]': 'Team', 'nav[dashboard][label]': 'Home', 'nav[dashboard][desktop_visible]': 1, 'nav[dashboard][desktop_order]': 1})[1]
nav = opt('ews_frontend_navigation')
check('navigation is saved (People included)', nav['people']['label'] == "Team's Room" and nav['people']['mobile_visible'] == 0 and nav['dashboard']['label'] == 'Home' and nav['overtime']['desktop_visible'] == 0 and qs.get('navigation_notice') == 'saved', (qs, nav.get('people')))
php("update_option('ews_employee_profile_settings',['enabled'=>1],false);")
st, page, _ = adm.req('/app/?ews_view=dashboard')
check('...and the app uses the new label', "Team&#039;s Room" in page or "Team's Room" in page)
n_reset, _ = form_nonce(adm, A + 'ews31-navigation', 'ews31_navigation_reset')
adm.post('ews31_navigation_reset', _wpnonce=n_reset)
check('navigation can be restored to the defaults', opt('ews_frontend_navigation')['people']['label'] == 'People')
emp.post('ews31_navigation_save', _wpnonce=n, **{'nav[people][label]': 'Hacked'})
check('employee cannot change navigation', opt('ews_frontend_navigation')['people']['label'] == 'People')

# ---------------------------------------------------------------- recognition
php("$wpdb->query(\"DELETE FROM {$p}ews_kudos\"); $wpdb->insert($p.'ews_kudos',['sender_employee_id'=>%d,'recipient_employee_id'=>%d,'category'=>'teamwork','message'=>'Thanks!','status'=>'active','created_at'=>current_time('mysql')]);" % (eid, inactive))
kid = int(q("SELECT MAX(id) id FROM {p}ews_kudos")[0]['id'])
st, page, _ = adm.req(A + 'ews31-recognition')
m = re.search(r'href="([^"]*kudos_id=%d[^"]*)"' % kid, page)
url = m.group(1).replace('&amp;', '&').replace('&#038;', '&') if m else '/none'
check('the Kudos is listed with a Delete link', 'Thanks!' in page and bool(m))
st, body, h = adm.req(url[url.index('/wp-admin'):] if '/wp-admin' in url else url)
check('a Kudos can be removed (and the page redirects, so a refresh does nothing)', q("SELECT status FROM {p}ews_kudos WHERE id=%d" % kid)[0]['status'] == 'deleted' and st in (301, 302), st)
check('...and it is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='kudos_deleted'")[0]['c']) == 1)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

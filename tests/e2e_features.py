"""Behaviour tests for the wp-admin "Feature Configuration" page.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_features.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-features'
CONFIRM = ['swap_cancel', 'swap_reject', 'leave_cancel', 'leave_cancel_reject', 'overtime_reject', 'early_leave_reject',
           'attendance_reset', 'general_leave_delete', 'employee_delete', 'feature_disable']


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name) or 'null')


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("$wpdb->query(\"DELETE FROM {$p}ews_audit_log\"); foreach(['ews_confirmation_actions','ews_pwa_splash_settings','ews_face_signin_settings'] as $o) delete_option($o);")


def page_nonce(sess):
    st, page, _ = sess.req(PAGE)
    m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="action" value="ews31_features_save"', page)
    if not m:
        for form in re.findall(r'<form.*?</form>', page, re.S):
            if 'value="ews31_features_save"' in form:
                m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
    return m.group(1) if m else None


def full(**over):
    data = {
        'tasks_enabled': 1, 'presence_qr_signin': 1, 'presence_verification': 1,
        'break_enabled': 1, 'breaks_per_day': 4, 'break_duration': 20, 'break_escalation': 35,
        'early_leave_max': 90, 'early_leave_monthly': 300, 'early_leave_office_only': 1,
        'face_signin_enabled': 1,
        'face_signin_settings[face_match_threshold]': '0.55', 'face_signin_settings[detector_input_size]': '416',
        'face_signin_settings[baseline_samples]': '500',
        'pwa_splash[enabled]': 1, 'pwa_splash[duration_ms]': 900, 'pwa_splash[title]': "Ahmed's Team",
        'pwa_splash[subtitle]': 'Work "smart"', 'pwa_splash[background]': '#112233', 'pwa_splash[accent]': 'purple',
        'pwa_splash[logo]': 'https://example.com/logo.png',
        'recognition_enabled': 1, 'recognition_allow_kudos': 1, 'recognition_weekly_limit_mode': 'limited', 'recognition_weekly_limit': 7,
        'overtime_enabled': 1, 'confirm_global': 1,
        'location_retention_days': 90, 'face_delete_inactive': 1, 'presence_request_minutes': 3,
    }
    for k in CONFIRM:
        data['confirm_actions[%s]' % k] = 1
    for k, v in over.items():
        if v is None:
            data.pop(k, None)
        else:
            data[k] = v
    return data


seed()
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
st, page, _ = adm.req(PAGE)
check('page opens for the administrator', st == 200 and 'Feature Configuration' in page, st)
st, page, _ = emp.req(PAGE)
check('employee cannot open the page', 'Feature Configuration' not in page, st)
n = page_nonce(adm)
check('the form is shown', bool(n))

_, qs, _ = adm.post('ews31_features_save', _wpnonce=n, **full(**{'confirm_actions[early_leave_reject]': None}))
st, page, _ = adm.req(PAGE + '&features_saved=1')
check('the page confirms the save', 'Feature configuration saved.' in page, qs)
check('feature switches are saved', all(opt(o) in (True, 1, '1') for o in ['ews_feature_tasks', 'ews_presence_qr_signin', 'ews_presence_verification', 'ews_feature_breaks', 'ews_feature_face_signin', 'ews_feature_recognition', 'ews_feature_overtime']))
check('break settings are saved', (int(opt('ews_breaks_per_day')), int(opt('ews_break_duration_minutes')), int(opt('ews_break_manager_alert_minutes'))) == (4, 20, 35))
check('early leave limits are saved', (int(opt('ews_early_leave_max_minutes')), int(opt('ews_early_leave_monthly_minutes')), int(opt('ews_early_leave_office_only'))) == (90, 300, 1))
face = opt('ews_face_signin_settings')
check('face settings are saved and clamped', face['face_match_threshold'] == 0.55 and face['detector_input_size'] == 416 and face['baseline_samples'] == 60, face)
check('the detector size is a choice of supported sizes', re.search(r'<select name="face_signin_settings\[detector_input_size\]"', page) is not None)
splash = opt('ews_pwa_splash_settings')
check('an apostrophe in the splash title is saved as typed', splash['title'] == "Ahmed's Team", splash['title'])
check('quotes in the subtitle are saved as typed', splash['subtitle'] == 'Work "smart"', splash['subtitle'])
check('an invalid colour falls back to the default', splash['background'] == '#112233' and splash['accent'] == '#6125c9', splash)
check('recognition settings are saved', int(opt('ews_recognition_weekly_limit')) == 7 and opt('ews_recognition_weekly_limit_mode') == 'limited')
check('privacy settings are saved', int(opt('ews_location_retention_days')) == 90 and int(opt('ews_face_delete_inactive')) == 1)
conf = opt('ews_confirmation_actions')
check('the "Reject Early Leave Request" confirmation can be turned off', conf.get('early_leave_reject') == 0, conf)
check('...and the page shows it off', re.search(r'name="confirm_actions\[early_leave_reject\]" value="1"\s+checked', page) is None and re.search(r'name="confirm_actions\[swap_cancel\]" value="1"\s+checked', page) is not None)
check('the save is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='feature_update'")[0]['c']) == 1)

adm.post('ews31_features_save', _wpnonce=n, **full(**{'break_duration': 60, 'break_escalation': 30, 'pwa_splash[title]': '   ', 'face_signin_settings[detector_input_size]': '300'}))
check('the manager alert is always after the break duration', int(opt('ews_break_manager_alert_minutes')) == 61, opt('ews_break_manager_alert_minutes'))
check('an empty splash title falls back to the default', opt('ews_pwa_splash_settings')['title'] == 'Workforce One', opt('ews_pwa_splash_settings'))
check('an unsupported detector size falls back to 320', opt('ews_face_signin_settings')['detector_input_size'] == 320)

_, qs, _ = adm.post('ews31_features_save', _wpnonce=n, **full(**{'early_leave_max': 120, 'early_leave_monthly': 60, 'tasks_enabled': None}))
check('a monthly early leave allowance below the per-request maximum is refused', qs.get('features_error') == 'early_leave_monthly' and int(opt('ews_early_leave_monthly_minutes')) == 300, qs)
check('...and nothing else is saved', opt('ews_feature_tasks') in (True, 1, '1'))
st, page, _ = adm.req(PAGE + '&features_error=early_leave_monthly')
check('...with a clear message', 'Monthly allowance' in page and 'notice-error' in page)

adm.post('ews31_features_save', _wpnonce=n, **full(**{k: None for k in ['tasks_enabled', 'presence_qr_signin', 'break_enabled', 'overtime_enabled', 'face_signin_enabled', 'recognition_enabled', 'confirm_global']}))
check('features can be switched off', not any(opt(o) in (True, 1, '1') for o in ['ews_feature_tasks', 'ews_presence_qr_signin', 'ews_feature_breaks', 'ews_feature_overtime', 'ews_feature_face_signin', 'ews_feature_recognition', 'ews_confirm_global']))
emp.post('ews31_features_save', _wpnonce=n, **full())
check('employee cannot change features', not opt('ews_feature_tasks'))

# ---------------------------------------------------------------- presence request time
adm2 = Session('admin', 'admin')
st, page, _ = adm2.req(PAGE)
check('Presence Verification has a time to answer', 'name="presence_request_minutes"' in page)
adm2.post('ews31_features_save', **full(_wpnonce=page_nonce(adm2), presence_request_minutes=10))
check('...saved in minutes', int(opt('ews_presence_request_minutes')) == 10, opt('ews_presence_request_minutes'))
adm2.post('ews31_features_save', **full(_wpnonce=page_nonce(adm2), presence_request_minutes=500))
check('...from 1 to 60', int(opt('ews_presence_request_minutes')) == 60)
adm2.post('ews31_features_save', **full(_wpnonce=page_nonce(adm2), presence_request_minutes=None))
check('...a form without the field keeps it', int(opt('ews_presence_request_minutes')) == 60)
php("delete_option('ews_presence_request_minutes');")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

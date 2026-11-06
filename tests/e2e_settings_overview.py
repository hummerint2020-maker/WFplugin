"""Behaviour tests for wp-admin → Settings Overview: every Workforce One setting (WordPress option)
in one read-only list with its current value, its default and whether it was changed, plus a JSON
export for support (secrets masked). Also checks that settings still fall back to their defaults.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_settings_overview.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Changes Workforce One settings (restored to defaults); never run against a real site.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-settings-overview'

TOUCHED = ['ews_grace_period', 'ews_early_leave_max_minutes', 'ews_pwa_splash_settings', 'ews_working_days', 'ews_feature_breaks', 'ews_location_radius',
           'ews_vapid_private_key', 'ews_presence_kiosk_key_999999', 'ews_employee_moments', 'ews_made_up_option']
wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("$b=[]; foreach(%s as $o){ $v=get_option($o,null); if($v!==null)$b[$o]=$v; } update_option('e2e_settings_backup',$b,false);" % json.dumps(TOUCHED).replace('"', "'"))
php("""
    foreach(['ews_grace_period','ews_early_leave_max_minutes','ews_pwa_splash_settings','ews_working_days','ews_feature_breaks','ews_location_radius'] as $o) delete_option($o);
    update_option('ews_vapid_private_key','-----BEGIN PRIVATE KEY-----TOPSECRETKEY',false);
    update_option('ews_presence_kiosk_key_999999','kiosksecret999',false);
    update_option('ews_employee_moments',[7=>['birthday'=>'1990-05-17','join_date'=>'2020-01-01']],false);
    update_option('ews_made_up_option','x',false);
    $wpdb->query("DELETE FROM {$p}ews_audit_log");
""")
adm = Session('admin', 'admin')


def row(page, name):
    """The table row of one setting."""
    m = re.search(r'<tr[^>]*data-option="%s".*?</tr>' % re.escape(name), page, re.S)
    return m.group(0) if m else ''


def text(fragment):
    return html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', fragment))).strip()


# ---------------------------------------------------------------- defaults still apply
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-schedule-config')
check('with no saved value the grace period is its default (10)', re.search(r'name="grace_period"[^>]*value="10"', page) is not None)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
check('...and early leave allows its default 120 minutes', re.search(r'name="early_leave_max"[^>]*value="120"', page) is not None)

# ---------------------------------------------------------------- the page
st, page, _ = adm.req(PAGE)
changed_before = int((re.findall(r'\b(\d+) changed\b', text(page)) or ['-99'])[0])
check('the page opens and lists the settings by group', st == 200 and 'Settings Overview' in page and all(g in page for g in ('Attendance &amp; Schedule', 'Features', 'Notifications', 'Privacy', 'System')), st)
r = row(page, 'ews_grace_period')
check('a setting shows its label, current value and default', 'Grace period' in text(r) and '10 min' in text(r) and 'Default' in text(r), text(r))
check('...where it is changed (a link to its page)', 'page=ews31-schedule-config' in r)
check('working days read as day names', 'Sun, Mon, Tue, Wed, Thu' in text(row(page, 'ews_working_days')), text(row(page, 'ews_working_days')))
check('an on/off setting reads On or Off', 'Off' in text(row(page, 'ews_feature_breaks')), text(row(page, 'ews_feature_breaks')))

php("update_option('ews_grace_period',15,false); update_option('ews_working_days',[0,1,2,3,4,6],false); update_option('ews_feature_breaks',true,false);")
st, page, _ = adm.req(PAGE)
r = row(page, 'ews_grace_period')
check('a changed setting is marked Changed, with both values', 'Changed' in text(r) and '15 min' in text(r) and '10 min' in text(r) and 'is-changed' in r, text(r))
check('...working days and on/off too', 'Changed' in text(row(page, 'ews_working_days')) and 'Sat' in text(row(page, 'ews_working_days')) and 'On' in text(row(page, 'ews_feature_breaks')))
check('the summary counts the changed settings', re.findall(r'\b(\d+) changed\b', text(page))[:1] == [str(changed_before + 3)], (changed_before, re.findall(r'\d+ changed', text(page))))
st, only, _ = adm.req(PAGE + '&changed=1')
check('"Only changed" lists just those', row(only, 'ews_grace_period') and row(only, 'ews_working_days') and not row(only, 'ews_location_radius'), len(re.findall('data-option=', only)))

php("update_option('ews_pwa_splash_settings',['enabled'=>1,'duration_ms'=>900,'title'=>'Our Team'],false);")
st, page, _ = adm.req(PAGE)
check('a grouped setting reads Customised when it differs from its built-in defaults', 'Customised' in text(row(page, 'ews_pwa_splash_settings')), text(row(page, 'ews_pwa_splash_settings')))
php("delete_option('ews_pwa_splash_settings');")
st, page, _ = adm.req(PAGE)
check('...and Default when it does not', 'Customised' not in text(row(page, 'ews_pwa_splash_settings')) and 'Default' in text(row(page, 'ews_pwa_splash_settings')))
# Grouped settings read through their own class (the Offline screen was always "Default", even switched on).
php("update_option('ews_offline_screen',['enabled'=>1,'timeout'=>20,'schedule'=>1,'keep_note'=>1,'texts'=>[]],false); update_option('ews_org_chart',['enabled'=>0],false); update_option('ews_approval_reject_note',['overtime'=>1],false);")
st, page, _ = adm.req(PAGE)
check('the Offline screen switched on reads Customised', 'Customised' in text(row(page, 'ews_offline_screen')), text(row(page, 'ews_offline_screen')))
check('...the Organization chart saved with its defaults reads Default', 'Customised' not in text(row(page, 'ews_org_chart')) and 'Default' in text(row(page, 'ews_org_chart')), text(row(page, 'ews_org_chart')))
check('...a reason to reject set for a workflow reads Customised', 'Customised' in text(row(page, 'ews_approval_reject_note')), text(row(page, 'ews_approval_reject_note')))
st, only, _ = adm.req(PAGE + '&changed=1')
check('...and "Only changed" lists the Offline screen', row(only, 'ews_offline_screen'))
# Saving a workflow writes its entry into every per-workflow setting, with the defaults: still Default.
php("update_option('ews_approval_reject_note',['overtime'=>0,'attendance_correction'=>1],false); update_option('ews_approval_self_rule',['overtime'=>'allow'],false); update_option('ews_approval_allow_self',['overtime'=>1],false); update_option('ews_approval_deadlines',['overtime'=>['remind'=>0,'escalate'=>0]],false); update_option('ews_approval_overdue',['overtime'=>0],false); update_option('ews_approval_settings',['hr_user'=>0,'finance_user'=>0,'day_start'=>'09:00','day_end'=>'17:00'],false);")
st, page, _ = adm.req(PAGE)
same = {k: text(row(page, k)) for k in ('ews_approval_reject_note', 'ews_approval_self_rule', 'ews_approval_allow_self', 'ews_approval_deadlines', 'ews_approval_overdue', 'ews_approval_settings')}
check('...a workflow saved with its defaults leaves the approval settings Default', all('Customised' not in v and 'Default' in v for v in same.values()), same)
php("update_option('ews_approval_deadlines',['overtime'=>['remind'=>4,'escalate'=>0]],false);")
st, page, _ = adm.req(PAGE)
check('...a reminder set reads Customised', 'Customised' in text(row(page, 'ews_approval_deadlines')), text(row(page, 'ews_approval_deadlines')))
php("foreach(['ews_offline_screen','ews_org_chart','ews_approval_reject_note','ews_approval_self_rule','ews_approval_allow_self','ews_approval_deadlines','ews_approval_overdue','ews_approval_settings'] as $o) delete_option($o);")

check('secrets are never shown: only Set / Not set', 'TOPSECRETKEY' not in page and 'kiosksecret999' not in page and 'Set' in text(row(page, 'ews_vapid_private_key')), text(row(page, 'ews_vapid_private_key')))
check('kiosk secrets are counted, not shown', re.search(r'\d+ kiosk secret', text(row(page, 'ews_presence_kiosk_key_*'))) is not None, text(row(page, 'ews_presence_kiosk_key_*')))
check('employee data (birthdays) is counted, not shown', '1990-05-17' not in page and '1 item' in text(row(page, 'ews_employee_moments')), text(row(page, 'ews_employee_moments')))
check('stored settings the plugin does not know are named', 'ews_made_up_option' in page)
check('the page changes nothing (no form that saves)', 'name="action" value="ews_settings_export"' in page and len(re.findall(r'<form', page)) == 1)

# ---------------------------------------------------------------- export
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page)
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_settings_export', '_wpnonce': nonce.group(1) if nonce else ''})
data = json.loads(body) if st == 200 and body.startswith('{') else {}
check('Export downloads a JSON file', st == 200 and 'application/json' in h.get('Content-Type', '') and 'attachment' in h.get('Content-Disposition', '') and '.json' in h.get('Content-Disposition', ''), (st, h.get('Content-Type'), h.get('Content-Disposition')))
check('...with the plugin, WordPress and PHP versions', data.get('plugin') == 'Workforce One' and data.get('version') and data.get('wordpress') and data.get('php'), {k: data.get(k) for k in ('plugin', 'version', 'wordpress', 'php')})
by = {s['name']: s for s in data.get('settings', [])}
check('...every setting with value, default and changed', by.get('ews_grace_period', {}).get('value') == 15 and by['ews_grace_period'].get('default') == 10 and by['ews_grace_period'].get('changed') is True, by.get('ews_grace_period'))
check('...secrets and employee data masked', 'TOPSECRETKEY' not in body and 'kiosksecret999' not in body and '1990-05-17' not in body and by.get('ews_vapid_private_key', {}).get('value') == '(set)', by.get('ews_vapid_private_key'))
check('...and unknown stored settings listed by name', 'ews_made_up_option' in data.get('unregistered', []))
check('the export is recorded in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='settings_exported'")) == 1)
st, body, _ = adm.req('/wp-admin/admin-post.php', {'action': 'ews_settings_export', '_wpnonce': 'bad'})
check('an export without a valid nonce is refused', 'TOPSECRET' not in body and not body.startswith('{"plugin"'))

# ---------------------------------------------------------------- access
emp = Session('emp1', 'emp1pass')
st, body, _ = emp.req(PAGE)
check('an employee cannot open the page', 'Settings Overview' not in body or st != 200)
st, body, _ = emp.req('/wp-admin/admin-post.php', {'action': 'ews_settings_export', '_wpnonce': nonce.group(1) if nonce else ''})
check('...or export', not body.startswith('{"plugin"'))

php("$b=(array)get_option('e2e_settings_backup',[]); foreach(%s as $o){ if(array_key_exists($o,$b))update_option($o,$b[$o],false); else delete_option($o); } delete_option('e2e_settings_backup');" % json.dumps(TOUCHED).replace('"', "'"))
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

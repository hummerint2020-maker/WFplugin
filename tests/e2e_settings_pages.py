"""Behaviour tests for the wp-admin "Roles & Permissions" and "Notification Settings" pages.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_settings_pages.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

ROLES = '/wp-admin/admin.php?page=ews31-roles'
NOTIF = '/wp-admin/admin.php?page=ews31-notifications'


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name) or 'null')


def role_caps(slug):
    return json.loads(php("$r=get_role('%s'); echo wp_json_encode($r?$r->capabilities:null);" % slug))


def user_can(login, cap):
    return php("$u=get_user_by('login','%s'); clean_user_cache($u->ID); $u=new WP_User($u->ID); echo $u->has_cap('%s')?'yes':'no';" % (login, cap)) == 'yes'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        $wpdb->query("DELETE FROM {$p}ews_audit_log");
        foreach(['mgr1'=>['ews_manager','ews_employee'],'ewsadmin'=>['ews_administrator']] as $login=>$roles){
            $id=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
            $u=new WP_User($id); $u->set_role($roles[0]); foreach(array_slice($roles,1) as $r) $u->add_role($r);
        }
        delete_option('ews_notification_policy'); update_option('ews_notification_retention_days',90);
    """)


def form_nonce(sess, url, action):
    st, page, _ = sess.req(url)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1), page
    return None, page


seed()
# An install from before 3.31.10 stored a switched-off permission as an explicit "false".
php("delete_option('ews_role_permissions'); get_role('ews_employee')->add_cap('ews_view_people',false); get_role('ews_employee')->remove_cap('ews_manage_tasks');")
php("$m=new ReflectionMethod('EWS_Manager_V31_1','ensure_roles_permissions'); $m->setAccessible(true); $m->invoke(new EWS_Manager_V31_1());")
legacy = role_caps('ews_employee')
check('upgrade: a legacy "false" permission becomes a removed one (still off)', 'ews_view_people' not in legacy, legacy)
check('upgrade: a permission the role never had gets its default', 'ews_manage_tasks' not in legacy and opt('ews_role_permissions')['ews_employee']['ews_manage_tasks'] is False, legacy)
adm, emp, ewsadmin = Session('admin', 'admin'), Session('emp1', 'emp1pass'), Session('ewsadmin', 'ewsadminpass')

# ---------------------------------------------------------------- roles
n, page = form_nonce(adm, ROLES, 'ews31_roles_save')
check('the Roles page shows the permission matrix', bool(n) and 'EWS Supervisor' in page and 'ews_manage_roles' in page)
matrix = {}
for slug, cap in re.findall(r'name="roles\[([a-z_]+)\]\[([a-z_]+)\]" value="1"\s+checked', page):
    matrix.setdefault(slug, set()).add(cap)


def save_roles(sess, nonce, m):
    data = {'_wpnonce': nonce}
    for slug, caps in m.items():
        for cap in caps:
            data['roles[%s][%s]' % (slug, cap)] = 1
    return sess.post('ews31_roles_save', **data)[1]


m = {k: set(v) for k, v in matrix.items()}
m.setdefault('ews_supervisor', set()).add('ews_manage_tasks')
m['ews_supervisor'].discard('ews_view_reports')
qs = save_roles(adm, n, m)
caps = role_caps('ews_supervisor')
check('a permission can be granted to a role', caps.get('ews_manage_tasks') is True, caps)
check('a removed permission is removed from the role, not stored as "false"', 'ews_view_reports' not in caps, caps)
check('...the page confirms it and it is audited', qs.get('roles_notice') == 'saved' and int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='roles_permissions_update'")[0]['c']) == 1, qs)
check('a user with two roles keeps the permissions of either role', user_can('mgr1', 'ews_manage_time') and user_can('mgr1', 'ews_manage_employees'))
php("$m=new ReflectionMethod('EWS_Manager_V31_1','ensure_roles_permissions'); $m->setAccessible(true); $m->invoke(new EWS_Manager_V31_1());")
check('a plugin upgrade does not give a removed permission back', 'ews_view_reports' not in role_caps('ews_supervisor'), role_caps('ews_supervisor'))
n2, _ = form_nonce(ewsadmin, ROLES, 'ews31_roles_save')
m2 = {k: set(v) for k, v in m.items()}
m2['ews_administrator'].discard('ews_manage_roles')
qs = save_roles(ewsadmin, n2, m2)
check('a non-administrator cannot remove "Manage Roles" from their own role', qs.get('roles_error') == 'lockout' and role_caps('ews_administrator').get('ews_manage_roles') is True, qs)
qs = save_roles(adm, n, m2)
check('...a WordPress administrator can', role_caps('ews_administrator').get('ews_manage_roles') is None, role_caps('ews_administrator'))
save_roles(adm, n, m)
save_roles(emp, n, {})
check('employee cannot change permissions', role_caps('ews_supervisor').get('ews_manage_tasks') is True)

# ---------------------------------------------------------------- notifications
n_pol, page = form_nonce(adm, NOTIF, 'ews_notification_policy_save')
check('the Notification Settings page shows the policy form', bool(n_pol) and 'Notification Policy' in page)
cats = sorted(set(re.findall(r'name="policy\[([a-z_]+)\]\[in_app\]"', page)))
data = {'_wpnonce': n_pol}
for c in cats:
    data['policy[%s][in_app]' % c] = 1
data['policy[%s][mandatory]' % cats[0]] = 1
qs = adm.post('ews_notification_policy_save', **data)[1]
pol = opt('ews_notification_policy')
check('the policy is saved; mandatory push turns push on', pol[cats[0]] == {'in_app': 1, 'push': 1, 'mandatory': 1} and pol[cats[1]]['push'] == 0, pol)
st, page, _ = adm.req(NOTIF + '&notifications_notice=' + qs.get('notifications_notice', 'none'))
check('...and the page confirms it', 'Notification policy updated' in page, qs)
n_ret, _ = form_nonce(adm, NOTIF, 'ews_notification_settings_save')
qs = adm.post('ews_notification_settings_save', _wpnonce=n_ret, retention_days=30)[1]
check('retention is saved', int(opt('ews_notification_retention_days')) == 30)
st, page, _ = adm.req(NOTIF + '&notifications_notice=' + qs.get('notifications_notice', 'none'))
check('...and the page confirms it', 'Notification retention saved' in page, qs)
adm.post('ews_notification_settings_save', _wpnonce=n_ret, retention_days=12)
check('an unsupported retention falls back to 90 days', int(opt('ews_notification_retention_days')) == 90)
n_push, _ = form_nonce(adm, NOTIF, 'ews_push_send_test')
adm.post('ews_push_send_test', _wpnonce=n_push, vapid_subject='mailto:ops@example.com')
check('the VAPID subject entered next to "Send Test Push" is saved', opt('ews_vapid_subject') == 'mailto:ops@example.com', opt('ews_vapid_subject'))
adm.post('ews_push_send_test', _wpnonce=n_push, vapid_subject='not a subject')
check('...an invalid subject is ignored', opt('ews_vapid_subject') == 'mailto:ops@example.com')
emp.post('ews_notification_settings_save', _wpnonce=n_ret, retention_days=7)
check('employee cannot change notification settings', int(opt('ews_notification_retention_days')) == 90)

# Leave the default permission matrix for the tests that run next.
php("delete_option('ews_role_permissions'); foreach(['ews_administrator','ews_manager','ews_supervisor','ews_employee'] as $r){$role=get_role($r); foreach(array_keys($role->capabilities) as $c) if(strpos($c,'ews_')===0) $role->remove_cap($c);} $m=new ReflectionMethod('EWS_Manager_V31_1','ensure_roles_permissions'); $m->setAccessible(true); $m->invoke(new EWS_Manager_V31_1());")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

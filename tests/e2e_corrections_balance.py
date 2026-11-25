"""Corrections balance in My requests (3.31.163, docs/tasks/corrections-balance.md): off by default (My requests as before);
on (with Attendance corrections and My approvals on, and a monthly limit): "Your balance" on top of My requests shows how many
attendance corrections are left this month, alone or with the leave balances; none left: the next ones go to HR, or none
(refused); no monthly limit: no card; Attendance corrections off: no card; switched off again, all as before.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_corrections_balance.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080. Wipes Workforce One correction data.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
# Every plugin option is put back at the end (the Features form saves the other switches too).
php("""file_put_contents(getenv('S').'/cx_balance_saved.ser',serialize($wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'",ARRAY_A)));""")
E = int(php("""
    $e=(int)$wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE wp_user_id=".(int)get_user_by('login','emp1')->ID);
    foreach(['ews_feature_my_requests_cx_balance','ews_feature_my_requests_leave','ews_correction_settings'] as $o) delete_option($o);
    $wpdb->query("DELETE FROM {$p}ews_audit_log WHERE action='my_requests_cx_balance_feature_update'");
    update_option('ews_feature_my_approvals',1,false); update_option('ews_feature_corrections',1,false);
    $wpdb->query("DELETE FROM {$p}ews_attendance_corrections WHERE employee_id=$e");
    echo $e;
""").splitlines()[-1])
MONTH = php("echo current_time('Y-m');").splitlines()[-1]
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')


def page():
    return emp.req('/app/?ews_view=requests')[1]


def card(html):
    m = re.search(r'<div class="wfo-lr-bal is-cx[^"]*">.*?</div>', html, re.S)
    return m.group(0) if m else ''


def used(n, status='approved', source='employee', day='01'):
    php("for($i=0;$i<%d;$i++)$wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>%d,'work_date'=>'%s-%s','type'=>'out','status'=>'%s','source'=>'%s','requested_by'=>0,'requested_at'=>current_time('mysql'),'reason'=>'filler']);"
        % (n, E, MONTH, day, status, source))


used(1)
used(1, status='rejected')                       # does not count
used(1, source='hr')                             # HR's own correction: does not count

# ---------------------------------------------------------------- off (the default): as before
pg = page()
check('off: no "Your balance" strip and no corrections card on My requests', 'wfo-lr-bals' not in pg and 'is-cx' not in pg)
n = re.search(r'name="_wpnonce" value="([^"]+)"', adm.req('/wp-admin/admin.php?page=ews31-features')[1])
features_nonce = n.group(1) if n else ''


def features(**on):
    data = {'_wpnonce': features_nonce, 'my_requests_cx_balance_present': '1', 'my_approvals_present': '1', 'my_approvals_enabled': '1',
            'corrections_present': '1', 'corrections_enabled': '1'}
    data.update({k: '1' for k, v in on.items() if v})
    data = {k: v for k, v in data.items() if on.get(k, True) is not False}
    return adm.post('ews31_features_save', **data)


# ---------------------------------------------------------------- on
features(my_requests_cx_balance_enabled=True)
check('the switch is saved and audited', php("echo (int)get_option('ews_feature_my_requests_cx_balance');").splitlines()[-1] == '1'
      and q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='my_requests_cx_balance_feature_update'")[0]['c'] in ('1', 1))
pg = page()
c = card(pg)
check('on: "Your balance" shows the corrections card alone (leave in My requests off)', 'wfo-lr-bals' in pg and c != '' and 'wfo-lr-bal tone-' not in pg, c)
check('...3 a month (built in), 1 counted (rejected and HR\'s own not): 2 of 3 this month', re.search(r'Attendance corrections</span><b>2</b><small>of 3 this month', c) is not None, c)
s1 = re.search(r'<section class="wfo-lr-bals".*?</section>', pg, re.S)
check('...no "This year" note without leave balances', s1 is not None and 'This year' not in s1.group(0))
used(1, status='pending')
check('a request waiting counts too: 1 left', re.search(r'<b>1</b><small>of 3 this month', card(page())) is not None, card(page()))
used(1, status='pending_hr', day='02')
c = card(page())
check('none left: 0, and the next ones go to HR (the built-in rule)', 'is-hr' in c and re.search(r'<b>0</b><small>None left this month · the next ones go to HR', c) is not None, c)
php("update_option('ews_correction_settings',['monthly_limit'=>3,'above_limit'=>'refuse'],false);")
c = card(page())
check('..."refuse": none left this month', 'is-refuse' in c and re.search(r'<b>0</b><small>None left this month</small>', c) is not None, c)
php("update_option('ews_correction_settings',['monthly_limit'=>0],false);")
check('no monthly limit: no card, no strip', 'is-cx' not in page() and 'wfo-lr-bals' not in page())
php("delete_option('ews_correction_settings');")
features(my_requests_cx_balance_enabled=True, my_requests_leave_present=True, my_requests_leave_enabled=True)
pg = page()
strip = re.search(r'<section class="wfo-lr-bals".*?</section>', pg, re.S)
strip = strip.group(0) if strip else ''
check('with leave in My requests: one strip, leave balances then the corrections card', strip.count('is-cx') == 1 and 'This year' in strip and strip.index('wfo-lr-bal tone-') < strip.index('is-cx'), strip[:600])
check('...not in the Leave filter', 'is-cx' not in emp.req('/app/?ews_view=requests&req_kind=leave')[1])
features(my_requests_cx_balance_enabled=True, my_requests_leave_present=True, my_requests_leave_enabled=False, corrections_enabled=False)
check('Attendance corrections off: no card', 'is-cx' not in page())

# ---------------------------------------------------------------- off again
features(my_requests_cx_balance_enabled=False)
pg = page()
check('switched off again: My requests as before', 'wfo-lr-bals' not in pg and 'is-cx' not in pg)
php("""$keep=[]; foreach(unserialize(file_get_contents(getenv('S').'/cx_balance_saved.ser')) as $o){ $keep[]=$o['option_name']; $wpdb->replace($wpdb->options,$o); }
    foreach($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'") as $n) if(!in_array($n,$keep,true)) $wpdb->delete($wpdb->options,['option_name'=>$n]);
    wp_cache_flush();""")
php("$wpdb->query(\"DELETE FROM {$p}ews_attendance_corrections WHERE employee_id=%d AND reason='filler'\");" % E)
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

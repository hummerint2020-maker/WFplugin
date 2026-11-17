"""Schema upgrade check (3.31.73): every CREATE TABLE of the plugin reaches dbDelta in a shape it can
read, so an upgrade adds new indexes to an installed site, sends no broken ALTERs and logs no
database errors, and a second upgrade changes nothing.

Simulates an older site first: drops indexes that 3.31.72 and earlier never added on upgrade
(notifications.user_read_date, leave_requests.employee_status/type_year, employees.default_shift_id).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_schema.py <state-dir>
Needs a site with the plugin active; changes no data. Meant for MySQL / MariaDB.
"""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, break_writes, check, php, results, wp, HERE  # noqa: E402

OLD = {'ews_notifications': 'user_read_date', 'ews_leave_requests': 'employee_status', 'ews_employees': 'default_shift_id'}
for table, key in list(OLD.items()) + [('ews_leave_requests', 'type_year')]:
    php("$t=$p.'%s'; if($wpdb->get_var(\"SHOW INDEX FROM $t WHERE Key_name='%s'\"))$wpdb->query(\"ALTER TABLE $t DROP INDEX %s\");" % (table, key, key))

# 3.31.148: the two indexes of Performance 1, dropped too (a 3.31.147 site has neither); each comes back with its columns.
PERF = [('ews_approval_steps', 'acted_by', ['acted_by_wp_user_id', 'acted_at']), ('ews_time_logs', 'work_date_at', ['work_date', 'event_at'])]
for table, key, _cols in PERF:
    php("$t=$p.'%s'; if($wpdb->get_var(\"SHOW INDEX FROM $t WHERE Key_name='%s'\"))$wpdb->query(\"ALTER TABLE $t DROP INDEX %s\");" % (table, key, key))

out = json.loads(wp('eval-file', os.path.join(HERE, 'schema_check.php')).splitlines()[-1])
first, second = out['runs']

check('the upgrade logs no database errors', not first['errors'], first['errors'][:8])
check('no broken ALTER (an index without a name or columns)', not [a for a in first['alters'] if '``' in a], first['alters'][:8])
check('the missed indexes are added', sum(' ADD KEY ' in a or ' ADD INDEX ' in a for a in first['alters']) >= 4, first['alters'][:8])
check('the upgrade adds the indexes older versions missed', not out['missing'], out['missing'][:10])
check('every plugin table and index was checked', out['tables'] >= 45 and out['indexes'] >= 150, (out['tables'], out['indexes']))
def index_cols(table, key):
    return php("echo implode(',',$wpdb->get_col(\"SELECT Column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$p}%s' AND INDEX_NAME='%s' ORDER BY SEQ_IN_INDEX\"));" % (table, key)).strip().splitlines()[-1:]


for table, key, cols in PERF:
    check('the upgrade adds %s.%s (%s)' % (table, key, ','.join(cols)), index_cols(table, key) == [','.join(cols)], index_cols(table, key))
check('a second upgrade changes nothing', not second['alters'], second['alters'][:8])
check('a second upgrade logs no database errors', not second['errors'], second['errors'][:8])
check('the schema version is recorded', first['version'] == second['version'] == php("$m=new ReflectionMethod('EWS_Manager_V31_1','ews_schema_target'); $m->setAccessible(true); echo $m->invoke(new EWS_Manager_V31_1());"), (first['version'], second['version']))

# ---------------------------------------------------------------- an update that does not finish (3.31.92)
RUN = "delete_option('ews_schema_version'); delete_transient('ews_schema_retry'); new EWS_Manager_V31_1(); echo wp_json_encode(['v'=>get_option('ews_schema_version'),'f'=>get_option('ews_schema_failed'),'retry'=>(bool)get_transient('ews_schema_retry')]);"
php("$wpdb->query(\"DROP TABLE IF EXISTS {$p}ews_signin_attempts\");")
break_writes('ews_signin_attempts')
r = json.loads(php("$wpdb->suppress_errors(true); " + RUN).splitlines()[-1])
break_writes('')
check('a table that cannot be created: the update is not marked done', r['v'] in (False, None, '') and r['f'] and any('ews_signin_attempts' in e for e in r['f']['errors']), r)
check('...it is tried again in 10 minutes, not on every request', r['retry'] is True)
adm = Session('admin', 'admin')
pg = adm.req('/wp-admin/admin.php?page=ews31-features')[1]
check('...and administrators see what failed on Workforce One pages', 'data-schema-failed' in pg and 'ews_signin_attempts' in pg)
r = json.loads(php(RUN).splitlines()[-1])
check('once it works, it is marked done and the warning goes', r['v'] == first['version'] and r['f'] is False and php("echo $wpdb->get_var(\"SHOW TABLES LIKE '{$p}ews_signin_attempts'\");").strip().endswith('ews_signin_attempts'), r)
check('...gone from the page too', 'data-schema-failed' not in adm.req('/wp-admin/admin.php?page=ews31-features')[1])

# ---------------------------------------------------------------- one request per site runs the upgrade (3.31.151)
import subprocess, time
from e2e_support import WP, S
LOCK = "global $wpdb; $n='wfo_'.substr(md5((defined('DB_NAME')?DB_NAME:'').'|'.$wpdb->prefix),0,12).'_schema';"
env = {**os.environ, 'S': S}
holder = subprocess.Popen(WP + ['eval', LOCK + " $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$n)); echo 'HELD'; sleep(30);"], stdout=subprocess.PIPE, text=True, env=env)
for _ in range(100):   # until the holder really has it (a slow runner)
    if php(LOCK + " echo 'FREE'.$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$n));").strip().endswith('FREE0'):
        break
    time.sleep(0.2)
r = json.loads(php("add_filter('ews_schema_lock_wait',function(){return 1;}); " + RUN).splitlines()[-1])
check('while another request holds the upgrade, this one waits, then carries on: no upgrade, no failure, no retry', r['v'] in (False, None, '') and r['f'] is False and r['retry'] is False, r)
holder.kill()
holder.wait()
r = json.loads(php(RUN).splitlines()[-1])
check('...once it is free the next request upgrades', r['v'] == first['version'] and r['f'] is False, r)
check('...and gives the lock back', php(LOCK + " echo 'FREE'.$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$n));").strip().endswith('FREE1'))
# The Annual Leave type missing too (removed before the version: every wp-cli call is a request that upgrades when it loads), which two upgrades at the same moment could add twice
saved_al = php("$r=$wpdb->get_row(\"SELECT * FROM {$p}ews_leave_types WHERE name='Annual Leave'\",ARRAY_A); echo 'ROW'.wp_json_encode($r);").split('ROW')[-1]
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_types WHERE name='Annual Leave'\");")
# Two requests at once on an old site: one upgrades, the other waits and finds it done; no false failure, the index there once.
table, key, cols = PERF[1]
php("$t=$p.'%s'; if($wpdb->get_var(\"SHOW INDEX FROM $t WHERE Key_name='%s'\"))$wpdb->query(\"ALTER TABLE $t DROP INDEX %s\"); delete_option('ews_schema_version'); delete_option('ews_schema_failed'); delete_transient('ews_schema_retry');" % (table, key, key))
both = [subprocess.Popen(WP + ['eval', "new EWS_Manager_V31_1(); echo 'DONE';"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env) for _ in range(3)]
outs = [p.communicate() for p in both]
st = json.loads(php("echo wp_json_encode(['v'=>get_option('ews_schema_version'),'f'=>get_option('ews_schema_failed'),'retry'=>(bool)get_transient('ews_schema_retry')]);").splitlines()[-1])
check('three requests at once: the version is set, nothing marked failed, no retry', st['v'] == first['version'] and st['f'] is False and st['retry'] is False, (st, [o[1][-300:] for o in outs]))
check('...no database error in any of them', not any('Duplicate' in o[0] + o[1] or 'database error' in o[0] + o[1] for o in outs), [o[1][-300:] for o in outs])
check('...and the index is there', index_cols(table, key) == [','.join(cols)], index_cols(table, key))
al = php("echo 'N'.(int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$p}ews_leave_types WHERE name='Annual Leave'\");").split('N')[-1]
check('...and the Annual Leave type added once, not once per request', al == '1', al)
if saved_al and saved_al != 'null':   # the original row back (its id is what the rest of the data points to)
    php("$wpdb->query(\"DELETE FROM {$p}ews_leave_types WHERE name='Annual Leave'\"); $wpdb->insert($p.'ews_leave_types',json_decode(base64_decode('%s'),true));" % __import__('base64').b64encode(saved_al.encode()).decode())
# a failed upgrade gives the lock back too
php("$wpdb->query(\"DROP TABLE IF EXISTS {$p}ews_signin_attempts\");")
break_writes('ews_signin_attempts')
r = json.loads(php("$wpdb->suppress_errors(true); " + RUN).splitlines()[-1])
break_writes('')
check('a failed upgrade (marked failed) gives the lock back', r['f'] and php(LOCK + " echo 'FREE'.$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$n));").strip().endswith('FREE1'), r)
r = json.loads(php(RUN).splitlines()[-1])
check('...and the next upgrade finishes it', r['v'] == first['version'] and r['f'] is False, r)

print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

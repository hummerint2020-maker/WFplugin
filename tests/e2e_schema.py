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
from e2e_support import check, php, results, wp, HERE  # noqa: E402

OLD = {'ews_notifications': 'user_read_date', 'ews_leave_requests': 'employee_status', 'ews_employees': 'default_shift_id'}
for table, key in list(OLD.items()) + [('ews_leave_requests', 'type_year')]:
    php("$t=$p.'%s'; if($wpdb->get_var(\"SHOW INDEX FROM $t WHERE Key_name='%s'\"))$wpdb->query(\"ALTER TABLE $t DROP INDEX %s\");" % (table, key, key))

out = json.loads(wp('eval-file', os.path.join(HERE, 'schema_check.php')).splitlines()[-1])
first, second = out['runs']

check('the upgrade logs no database errors', not first['errors'], first['errors'][:8])
check('the upgrade only adds what is missing (no broken or repeated ALTERs)', all(' ADD KEY ' in a or ' ADD INDEX ' in a for a in first['alters']), first['alters'][:8])
check('the upgrade adds the indexes older versions missed', not out['missing'], out['missing'][:10])
check('every plugin table and index was checked', out['tables'] >= 45 and out['indexes'] >= 150, (out['tables'], out['indexes']))
check('a second upgrade changes nothing', not second['alters'], second['alters'][:8])
check('a second upgrade logs no database errors', not second['errors'], second['errors'][:8])
check('the schema version is recorded', first['version'] == second['version'] == php("$m=new ReflectionMethod('EWS_Manager_V31_1','ews_schema_target'); $m->setAccessible(true); echo $m->invoke(new EWS_Manager_V31_1());"), (first['version'], second['version']))

print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

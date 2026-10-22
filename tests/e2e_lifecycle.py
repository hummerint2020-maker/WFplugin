"""Deactivate and reactivate (3.31.119): every scheduled event of the plugin is cleared, also those scheduled with arguments (a break's
reminders, the push batches), and the recurring ones come back when it is activated again.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_lifecycle.py <state-dir>
"""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import check, php, results, wp  # noqa: E402

HOOKS = ['ews_notifications_cleanup', 'ews_auto_attendance_tick', 'ews_smart_nudges_tick', 'ews_tasks_tick', 'ews_corrections_tick', 'ews_office_minimum_tick',
         'ews_approvals_tick', 'ews_privacy_cleanup', 'ews_health_rollup', 'ews_break_duration_reminder', 'ews_break_manager_escalation', 'ews_push_deliver']


def scheduled():
    out = php("$h=[]; foreach((array)_get_cron_array() as $t=>$hooks) foreach($hooks as $name=>$x) if(strpos($name,'ews_')===0) $h[$name]=1; echo wp_json_encode(array_keys($h));").strip().splitlines()[-1]
    return set(json.loads(out))


php("wp_schedule_single_event(time()+3600,'ews_break_duration_reminder',[987654]); wp_schedule_single_event(time()+3600,'ews_break_manager_escalation',[987654]); wp_schedule_single_event(time()+60,'ews_push_deliver',['e2e',wp_generate_uuid4()]);")
before = scheduled()
check('before: the recurring events and the ones with arguments are scheduled', {'ews_break_duration_reminder', 'ews_break_manager_escalation', 'ews_push_deliver', 'ews_privacy_cleanup'} <= before, sorted(before))
wp('plugin', 'deactivate', 'workforce-one')
after = scheduled()
check('deactivated: no event of the plugin is left at all (also the break reminders and push batches with arguments)', not after, sorted(after))
wp('plugin', 'activate', 'workforce-one')
php("do_action('init');")
back = scheduled()
check('activated again: the recurring events come back', {'ews_privacy_cleanup', 'ews_notifications_cleanup'} <= back, sorted(back))
check('...and the break reminders of before do not', 'ews_break_duration_reminder' not in back, sorted(back))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

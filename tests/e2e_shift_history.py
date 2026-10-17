"""Shift history (3.31.108; owner report: changing an employee's shift changed old reports; owner
decisions 2026-10-17, mockup docs/mockups/shift-history/): each employee's shift periods from a day to
a day, a shift's dated times, and every day counted with the shift and the times of that day.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_shift_history.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_employee_shift_periods','ews_shift_versions','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE employee_id=$eid");
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_shifts',[['id'=>101,'name'=>'Morning','start'=>'09:00','end'=>'17:00','grace'=>10,'sign_in_cutoff_minutes'=>240,'overnight'=>0,'active'=>1],
        ['id'=>102,'name'=>'Evening','start'=>'14:00','end'=>'22:00','grace'=>15,'sign_in_cutoff_minutes'=>240,'overnight'=>0,'active'=>1]],false);
    update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'16:00','end'=>'16:00'],false);
    $wpdb->update($p.'ews_employees',['default_shift_id'=>101],['id'=>$eid]);
    // As the update does it: the history from the beginning, with the shift and times of now.
    $o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'shift_history_seed');$m->setAccessible(true);$m->invoke($o);
    echo wp_json_encode(['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID]);
""").splitlines()[-1])
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
PROFILE = '/wp-admin/admin.php?page=ews31-employee-profile&employee_id=%d' % ids['emp']


def call(method, *args):
    return php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'%s');$m->setAccessible(true);echo wp_json_encode($m->invoke($o,%s));""" % (method, ','.join(json.dumps(a) for a in args))).splitlines()[-1]


def hours(day):
    return json.loads(call('working_hours', ids['emp'], day))


def report(day):
    out = php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'report_days');$m->setAccessible(true);
        $e=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ews_employees WHERE id=%%d",%d));
        foreach($m->invoke($o,[$e],'%s','%s') as $r)echo wp_json_encode(['late'=>$r['late_minutes'],'result'=>$r['result'],'ot'=>$r['ot_extra']??0]);""" % (ids['emp'], day, day)).splitlines()
    return json.loads(out[-1]) if out else {}


def sign(day, at, kind='late_sign_in'):
    php("""$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>%d,'work_date'=>'%s','event_type'=>'%s','event_at'=>'%s %s:00','scheduled_status'=>'Office']);""" % (ids['emp'], ids['emp_uid'], day, kind, day, at))


def nonce(page, action):
    m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*><input type="hidden" name="action" value="%s"' % action, page)
    return m.group(1) if m else 'x'


def change(shift_id, from_date, reason='', sess=None):
    sess = sess or adm
    page = sess.req(PROFILE)[1]
    st, qs, _ = sess.post('ews_shift_period_save', _wpnonce=nonce(page, 'ews_shift_period_save'), employee_id=ids['emp'], shift_id=shift_id, from_date=from_date, reason=reason)
    return qs


def periods():
    return q("SELECT shift_id,from_date,to_date FROM {p}ews_employee_shift_periods WHERE employee_id=%d ORDER BY from_date" % ids['emp'])


past = d(-3)
php("$wpdb->query($wpdb->prepare(\"DELETE FROM {$p}ews_schedule WHERE employee_id=%%d AND work_date=%%s\",%d,'%s')); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (ids['emp'], past, ids['emp'], past))
sign(past, '09:10'); sign(past, '17:00', 'sign_out')
check('on update every employee has one period with their shift from the beginning', periods() == [{'shift_id': '101', 'from_date': '2000-01-01', 'to_date': None}], periods())
check('...and every shift (and the company hours) its times then', len(q("SELECT id FROM {p}ews_shift_versions")) == 3)
before = report(past)
check('a past day on the morning shift: 10 minutes late (signed in 09:10, start 09:00)', before.get('late') == 10, before)

# ---------------------------------------------------------------- the owner's report: change the shift
qs = change(102, d(0), 'Moved to evenings')
check('the shift changed from today on the employee page', qs.get('shift_done') == 'saved' and hours(d(0))['start'] == '14:00', qs)
after = report(past)
check('THE BUG: the old day still counts with the morning shift (10 minutes late, no overtime)', after == before, (before, after))
check('...today counts with the evening shift', hours(d(0))['shift_id'] == 102 and hours(past)['shift_id'] == 101)
p = periods()
check('...the morning period ended yesterday on its own, the evening one is open', [x['to_date'] for x in p] == [d(-1), None] and p[1]['from_date'] == d(0), p)
check('...and the employee is on it now', q("SELECT default_shift_id FROM {p}ews_employees WHERE id=%d" % ids['emp'])[0]['default_shift_id'] == '102')

# a future day: waits; the employee sees it
qs = change(101, d(10))
check('a change on a future day is saved as upcoming; today stays on evenings', qs.get('shift_done') == 'saved' and hours(d(0))['shift_id'] == 102 and hours(d(10))['shift_id'] == 101, qs)
check('...the employee sees it on their profile', 'data-shift-next' in emp.req('/app/?ews_view=profile')[1])
page = adm.req(PROFILE)[1]
check('...and it shows on the employee page as Upcoming, with Remove', 'data-shift-periods' in page and 'Upcoming' in page and 'name="remove"' in page)
pid = q("SELECT id FROM {p}ews_employee_shift_periods WHERE employee_id=%d AND from_date='%s'" % (ids['emp'], d(10)))[0]['id']
st, qs, _ = adm.post('ews_shift_period_save', _wpnonce=nonce(page, 'ews_shift_period_save'), employee_id=ids['emp'], remove=pid)
check('...removed: the evening period is open again', qs.get('shift_done') == 'removed' and periods()[-1]['to_date'] is None and hours(d(10))['shift_id'] == 102, periods())

# a past day: HR only; the days in between count again
php("$u=get_user_by('login','emp1');$u->add_cap('ews_manage_employees');")
st, qs, _ = emp.post('ews_shift_period_save', _wpnonce=nonce(emp.req(PROFILE)[1], 'ews_shift_period_save'), employee_id=ids['emp'], shift_id=101, from_date=d(-1))
check('a past day from someone who is not HR is refused', qs.get('shift_error') == 'past' and hours(d(-1))['shift_id'] == 101, qs)
php("$u=get_user_by('login','emp1');$u->remove_cap('ews_manage_employees');")
qs = change(102, d(-5), 'It was really from the 12th')
check('HR fixes a mistake from a past day: those days count with the evening shift now', qs.get('shift_done') == 'saved' and hours(past)['shift_id'] == 102 and report(past).get('late') == 0, (qs, report(past)))
p = periods()
check('...the periods stay in order, with no gap and no overlap', [(x['from_date'], x['to_date']) for x in p] == [('2000-01-01', d(-6)), (d(-5), d(-1)), (d(0), None)], p)

# ---------------------------------------------------------------- a shift's times from a day
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-schedule-config')
m = re.search(r"name=\"_wpnonce\" value=\"([^\"]+)\"[^<]*<input type=\"hidden\" name=\"_wp_http_referer\"[^>]*><input type=\"hidden\" name=\"action\" value=\"ews31_shifts_save\"", page)
check('Schedule Configuration asks from which day new times count', 'name="times_from"' in page)
shifts = {'shifts[0][id]': 101, 'shifts[0][name]': 'Morning', 'shifts[0][start]': '08:30', 'shifts[0][end]': '16:30', 'shifts[0][grace]': 10, 'shifts[0][sign_in_cutoff_minutes]': 240, 'shifts[0][active]': 1,
          'shifts[1][id]': 102, 'shifts[1][name]': 'Evening', 'shifts[1][start]': '14:00', 'shifts[1][end]': '22:00', 'shifts[1][grace]': 15, 'shifts[1][sign_in_cutoff_minutes]': 240, 'shifts[1][active]': 1}
st, qs, _ = adm.post('ews31_shifts_save', _wpnonce=m.group(1) if m else 'x', times_from=d(2), **shifts)
check('the morning shift gets new times from a later day: before it, the old times', qs.get('schedule_notice') == 'shifts_saved' and json.loads(call('shift_times_on', 101, d(2)))['start'] == '08:30' and json.loads(call('shift_times_on', 101, d(1)))['start'] == '09:00', qs)
check('...the days before keep them in the reports (a morning day before the change: 09:00)', json.loads(call('shift_times_on', 101, d(-10)))['start'] == '09:00')
check('...the page lists the dated times', 'data-shift-versions' in adm.req('/wp-admin/admin.php?page=ews31-schedule-config')[1])
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-schedule-config')
m = re.search(r"name=\"_wpnonce\" value=\"([^\"]+)\"[^<]*<input type=\"hidden\" name=\"_wp_http_referer\"[^>]*><input type=\"hidden\" name=\"action\" value=\"ews31_working_hours_save\"", page)
st, qs, _ = adm.post('ews31_working_hours_save', _wpnonce=m.group(1) if m else 'x', work_start='07:00', work_end='15:00', grace_period=10, absent_after=120, times_from=d(0))
check('the company hours too: new from today, the old ones before', qs.get('schedule_notice') == 'hours_saved' and json.loads(call('shift_times_on', 0, d(0)))['start'] == '07:00' and json.loads(call('shift_times_on', 0, d(-1)))['start'] == '08:00', qs)

# the Employees page change and the Audit Log
check('the Audit Log has every change', len(q("SELECT id FROM {p}ews_audit_log WHERE action IN ('shift_period_set','shift_period_removed')")) >= 4)

php("""foreach(['ews_employee_shift_periods','ews_shift_versions'] as $t) $wpdb->query("DELETE FROM {$p}$t"); $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE employee_id=%d"); $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=%d AND work_date='%s'");
    $wpdb->update($p.'ews_employees',['default_shift_id'=>null],['id'=>%d]); delete_option('ews_shifts'); delete_option('ews_working_hours');
    $o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'shift_history_seed');$m->setAccessible(true);$m->invoke($o);""" % (ids['emp'], ids['emp'], past, ids['emp']))
print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

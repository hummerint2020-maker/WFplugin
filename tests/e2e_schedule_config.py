"""Behaviour tests for the wp-admin "Schedule Configuration" page: working hours, working days,
shifts, schedule types and company holidays (General Leave).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_schedule_config.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-schedule-config'


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name) or 'null')


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_shifts','ews_schedule_types_config','ews_allow_overnight_shift','ews_grace_period'] as $o) delete_option($o);
        update_option('ews_working_hours',['start'=>'08:00','normal_until'=>'17:00','end'=>'17:00'],false);
        $wpdb->query("DELETE FROM {$p}ews_company_calendar"); $wpdb->query("DELETE FROM {$p}ews_audit_log");
    """)


def page_nonce(sess, action):
    st, page, _ = sess.req(PAGE)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    return None


def audited(action):
    return int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='%s'" % action)[0]['c']) >= 1


seed()
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
st, page, _ = adm.req(PAGE)
check('page opens for the administrator', st == 200 and 'Schedule Configuration' in page, st)
st, page, _ = emp.req(PAGE)
check('employee cannot open the page', 'Schedule Configuration' not in page, st)

# ---------------------------------------------------------------- working hours
n = page_nonce(adm, 'ews31_working_hours_save')


def hours(start, end, grace=10, overnight=False):
    data = dict(_wpnonce=n, work_start=start, work_end=end, grace_period=grace)
    if overnight:
        data['allow_overnight_shift'] = 1
    return adm.post('ews31_working_hours_save', **data)[1]


qs = hours('09:00', '17:30', 15)
check('working hours are saved', opt('ews_working_hours')['start'] == '09:00' and opt('ews_working_hours')['end'] == '17:30' and int(opt('ews_grace_period')) == 15, opt('ews_working_hours'))
st, page, _ = adm.req(PAGE + '&schedule_notice=' + qs.get('schedule_notice', 'none'))
check('...and the page confirms it', 'Working hours saved.' in page, qs)
check('...and it is audited', audited('working_hours_update'))
qs = hours('9am', '17:00', 30)
check('an invalid time is refused', qs.get('schedule_error') == 'hours_invalid' and opt('ews_working_hours')['start'] == '09:00', qs)
check('...without saving the grace period either', int(opt('ews_grace_period')) == 15, opt('ews_grace_period'))
qs = hours('09:00', '09:00')
check('the same start and end is refused', qs.get('schedule_error') == 'hours_same', qs)
qs = hours('17:00', '08:00')
check('an end before the start needs overnight shifts', qs.get('schedule_error') == 'hours_overnight' and opt('ews_working_hours')['end'] == '17:30', (qs, opt('ews_working_hours')))
hours('22:00', '06:00', 10, overnight=True)
check('an overnight day is saved when overnight shifts are on', opt('ews_working_hours')['start'] == '22:00' and int(opt('ews_allow_overnight_shift')) == 1, opt('ews_working_hours'))
hours('08:00', '17:00', 400)
check('the grace period is capped at 180 minutes', int(opt('ews_grace_period')) == 180)
emp.post('ews31_working_hours_save', _wpnonce=n, work_start='01:00', work_end='02:00')
check('employee cannot change working hours', opt('ews_working_hours')['start'] == '08:00')

# ---------------------------------------------------------------- working days
n = page_nonce(adm, 'ews31_working_days_save')
_, qs, _ = adm.post('ews31_working_days_save', **{'_wpnonce': n, 'working_days[]': ['4', '0', '1', '9', '2', '3', '1']})
check('working days are saved, sorted, without duplicates or invalid days', opt('ews_working_days') == [0, 1, 2, 3, 4], opt('ews_working_days'))
st, page, _ = adm.req(PAGE + '&schedule_notice=' + qs.get('schedule_notice', 'none'))
check('...and the page confirms it', 'Working days saved.' in page, qs)
_, qs, _ = adm.post('ews31_working_days_save', _wpnonce=n)
check('at least one working day is required', qs.get('schedule_error') == 'days_empty' and opt('ews_working_days') == [0, 1, 2, 3, 4], qs)

# ---------------------------------------------------------------- shifts
n = page_nonce(adm, 'ews31_shifts_save')
shifts = opt('ews_shifts') or json.loads(php("echo wp_json_encode((new ReflectionMethod('EWS_Manager_V31_1','default_shifts'))->invoke(null));") or '[]')


def shift_post(rows, new=None):
    data = {'_wpnonce': n}
    for i, s in enumerate(rows):
        for k in ('id', 'name', 'start', 'end', 'grace', 'sign_in_cutoff_minutes'):
            data['shifts[%d][%s]' % (i, k)] = s.get(k, '')
        for k in ('overnight', 'active'):
            if s.get(k):
                data['shifts[%d][%s]' % (i, k)] = 1
    for k, v in (new or {}).items():
        data['new_shift[%s]' % k] = v
    return adm.post('ews31_shifts_save', **data)[1]


st, page, _ = adm.req(PAGE)
base = [{'id': int(m[0]), 'name': m[1]} for m in re.findall(r'name="shifts\[\d+\]\[id\]" value="(\d+)"><input name="shifts\[\d+\]\[name\]" value="([^"]*)"', page)]
check('the page lists the shifts', len(base) >= 1, page[:0])
rows = [{'id': base[0]['id'], 'name': 'Morning', 'start': '08:00', 'end': '16:00', 'grace': 5, 'sign_in_cutoff_minutes': 120, 'active': 1}]
qs = shift_post(rows, {'name': 'Evening', 'start': '16:00', 'end': '23:00', 'grace': 10, 'sign_in_cutoff_minutes': 240, 'active': 1})
s = opt('ews_shifts')
check('a shift is edited and a new one added', [x['name'] for x in s] == ['Morning', 'Evening'] and s[0]['grace'] == 5 and s[0]['sign_in_cutoff_minutes'] == 120, s)
evening_id = s[1]['id']
php("$wpdb->update($p.'ews_employees',['default_shift_id'=>%d],['id'=>%d]);" % (evening_id, ids()['eid']))
qs = shift_post([rows[0]], {'name': 'Night', 'start': '22:00', 'end': '06:00', 'overnight': 1, 'active': 1})
s = opt('ews_shifts')
check('a removed shift id is never reused by a new shift', [x['name'] for x in s] == ['Morning', 'Night'] and s[1]['id'] != evening_id, s)
qs = shift_post(rows + [{'id': s[1]['id'], 'name': 'Night', 'start': '22:00', 'end': '06:00', 'active': 1}])
check('a shift ending before it starts must be marked overnight', qs.get('schedule_error') == 'shift_overnight' and opt('ews_shifts')[1]['overnight'] == 1, (qs, opt('ews_shifts')))
qs = shift_post(rows, {'name': 'morning', 'start': '09:00', 'end': '12:00', 'active': 1})
check('a duplicate shift name is refused', qs.get('schedule_error') == 'shift_duplicate' and len(opt('ews_shifts')) == 2, (qs, opt('ews_shifts')))
qs = shift_post([])
check('at least one shift is required', qs.get('schedule_error') == 'shifts_empty' and len(opt('ews_shifts')) == 2, qs)
check('shift changes are audited', audited('shifts_update'))

# ---------------------------------------------------------------- schedule types
n = page_nonce(adm, 'ews31_schedule_config_save')
types = opt('ews_schedule_types_config') or json.loads(php("echo wp_json_encode((new ReflectionMethod('EWS_Manager_V31_1','default_schedule_types_config'))->invoke(null));"))


def types_post(rows, new=None):
    data = {'_wpnonce': n}
    for i, t in enumerate(rows):
        for k in ('name', 'icon', 'bg_color', 'text_color', 'border_color', 'attendance_rule'):
            data['types[%d][%s]' % (i, k)] = t.get(k, '')
        for k in ('requires_sign_in', 'requires_location', 'active'):
            if t.get(k):
                data['types[%d][%s]' % (i, k)] = 1
    for k, v in (new or {}).items():
        data['types[new][%s]' % k] = v
    return adm.post('ews31_schedule_config_save', **data)[1]


def type_names():
    return [t['name'] for t in opt('ews_schedule_types_config')]


st, page, _ = adm.req(PAGE)
base_types = [dict(t) for t in types]
names = [t['name'] for t in base_types]
check('the page lists the schedule types', all(('value="%s"' % x) in page for x in names), names)
rows = [dict(t) for t in base_types]
rows[1]['bg_color'] = '#123456'
rows[1]['icon'] = '🏡'
qs = types_post(rows, {'name': 'Lab Day', 'icon': '🧪', 'bg_color': 'red', 'text_color': '#000000', 'border_color': '#ffffff', 'attendance_rule': 'attendance', 'requires_sign_in': 1, 'active': 1})
cfg = opt('ews_schedule_types_config')
lab = next((t for t in cfg if t['name'] == 'Lab Day'), None)
check('a type is edited and a new one added', cfg[1]['bg_color'] == '#123456' and cfg[1]['icon'] == '🏡' and lab and lab['requires_sign_in'] == 1, cfg)
check('...an invalid colour falls back to the default', lab and lab['bg_color'] == '#f2f4f7', lab)
st, page, _ = adm.req(PAGE + '&schedule_notice=' + qs.get('schedule_notice', 'none'))
check('...and the page confirms it', 'Schedule types saved.' in page, qs)
rows = [dict(t) for t in cfg]
idx = names.index('Office')
renamed = [dict(t) for t in rows]
renamed[idx]['name'] = 'Onsite'
qs = types_post(renamed)
check('Office cannot be renamed (the plugin relies on it)', qs.get('schedule_error') == 'type_core' and 'Office' in type_names() and 'Onsite' not in type_names(), (qs, type_names()))
removed = [dict(t) for t in rows]
removed[names.index('Vacation')]['name'] = ''
qs = types_post(removed)
check('Vacation cannot be removed', qs.get('schedule_error') == 'type_core' and 'Vacation' in type_names(), qs)
php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Lab Day']);" % (ids()['eid'], d(5)))
removed = [dict(t) for t in rows]
removed[[t['name'] for t in rows].index('Lab Day')]['name'] = ''
qs = types_post(removed)
check('a type used by the schedule cannot be removed', qs.get('schedule_error') == 'type_in_use' and 'Lab Day' in type_names(), qs)
renamed = [dict(t) for t in rows]
renamed[[t['name'] for t in rows].index('Lab Day')]['name'] = 'Lab'
qs = types_post(renamed)
check('...nor renamed', qs.get('schedule_error') == 'type_in_use' and 'Lab Day' in type_names() and 'Lab' not in type_names(), qs)
off = [dict(t) for t in rows]
off[[t['name'] for t in rows].index('Lab Day')]['active'] = 0
types_post(off)
check('...but it can be deactivated', next(t for t in opt('ews_schedule_types_config') if t['name'] == 'Lab Day')['active'] == 0)
php("$wpdb->delete($p.'ews_schedule',['status'=>'Lab Day']);")
removed = [dict(t) for t in opt('ews_schedule_types_config')]
removed[[t['name'] for t in removed].index('Lab Day')]['name'] = ''
types_post(removed)
check('an unused type can be removed', 'Lab Day' not in type_names(), type_names())
qs = types_post([dict(t) for t in opt('ews_schedule_types_config')], {'name': 'office', 'attendance_rule': 'attendance', 'active': 1})
check('a duplicate type name is refused', qs.get('schedule_error') == 'type_duplicate' and type_names().count('Office') == 1 and 'office' not in type_names(), qs)
check('schedule type changes are audited', audited('schedule_config_update'))

# ---------------------------------------------------------------- general leave
n = page_nonce(adm, 'ews31_general_leave_save')
_, qs, _ = adm.post('ews31_general_leave_save', _wpnonce=n, event_date=d(20), title='National Day')
rows = q("SELECT id,title FROM {p}ews_company_calendar WHERE event_date='%s'" % d(20))
check('a holiday is added', len(rows) == 1 and rows[0]['title'] == 'National Day', rows)
st, page, _ = adm.req(PAGE + '&schedule_notice=' + qs.get('schedule_notice', 'none'))
check('...listed on the page and confirmed', 'National Day' in page and 'General Leave added.' in page, qs)
_, qs, _ = adm.post('ews31_general_leave_save', _wpnonce=n, event_date=d(20), title='Again')
check('a second holiday on the same day is refused', qs.get('schedule_error') == 'holiday_duplicate' and len(q("SELECT id FROM {p}ews_company_calendar WHERE event_date='%s'" % d(20))) == 1, qs)
_, qs, _ = adm.post('ews31_general_leave_save', _wpnonce=n, event_date='31/31/2026', title='Bad')
check('an invalid date is refused', qs.get('schedule_error') == 'holiday_invalid', qs)
m = re.search(r'href="([^"]*action=ews31_general_leave_delete[^"]*)"', page)
url = m.group(1).replace('&amp;', '&').replace('&#038;', '&') if m else ''
st, body, h = adm.req(url[url.index('/wp-admin'):] if '/wp-admin' in url else '/none')
check('a holiday is removed', not q("SELECT id FROM {p}ews_company_calendar WHERE event_date='%s'" % d(20)), (st, url))
check('holiday changes are audited', audited('general_leave_add') and audited('general_leave_delete'))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

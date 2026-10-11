"""Behaviour tests for the wp-admin "Sign In / Out Report": manual records, editing, Reset Day
and the CSV export.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_time_report.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-time-report'
DAY = d(-1)


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_time_logs','ews_break_sessions','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_location_latitude'); delete_option('ews_location_longitude');
    """)


def logs(where='1=1'):
    return q("SELECT id,event_type,event_at,location_status FROM {p}ews_time_logs WHERE employee_id=%d AND %s ORDER BY event_at" % (ids()['eid'], where))


def form_nonce(sess, url, action):
    st, page, _ = sess.req(url)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1), page
    return None, page


seed()
eid = ids()['eid']
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
st, page, _ = adm.req(PAGE)
check('report opens for the administrator', st == 200 and 'Sign In / Out Report' in page, st)
st, page, _ = emp.req(PAGE)
check('employee cannot open the report', 'Sign In / Out Report' not in page, st)

n_save, page = form_nonce(adm, PAGE + '&add_time=1&start=%s&end=%s' % (DAY, DAY), 'ews31_time_save')
check('the manual record form is shown', bool(n_save))


def save(**over):
    data = dict(_wpnonce=n_save, employee_id=eid, work_date=DAY, event_type='sign_in', event_at=DAY + 'T09:05', latitude='', longitude='', accuracy='')
    data.update(over)
    return adm.post('ews31_time_save', **data)[1]


qs = save(latitude='30.0445', longitude='31.2358', accuracy='15')
rows = logs()
check('a manual Sign In is added', len(rows) == 1 and rows[0]['event_type'] == 'sign_in' and rows[0]['event_at'].startswith(DAY + ' 09:05') and qs.get('time_notice') == 'saved', (qs, rows))
check("...its location is checked against the employee's Work Location", rows and rows[0]['location_status'] == 'inside', rows)
check('...it is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='time_manual_add'")[0]['c']) == 1)
qs = save(event_at=DAY + 'T10:00')
check('a second Sign In on the same day is refused', qs.get('time_error') == 'duplicate' and len(logs()) == 1, qs)
qs = save(event_type='sign_out', event_at=DAY + 'T08:00')
check('a Sign Out before the Sign In is refused', qs.get('time_error') == 'order' and len(logs()) == 1, qs)
qs = save(event_type='sign_out', event_at=DAY + 'T17:00')
check('a Sign Out after the Sign In is added', qs.get('time_notice') == 'saved' and len(logs()) == 2, qs)
qs = save(event_type='sign_out', event_at=DAY + 'T18:00')
check('a second Sign Out is refused', qs.get('time_error') == 'duplicate' and len(logs()) == 2, qs)
qs = save(event_at=d(-2) + 'T09:00')
check('the event must be on the Work Date', qs.get('time_error') == 'date_mismatch', qs)
qs = save(employee_id=999999)
check('an unknown employee is refused', qs.get('time_error') == 'employee', qs)
qs = save(event_type='coffee')
check('an unknown event type is refused', qs.get('time_error') == 'invalid', qs)
st, page, _ = adm.req(PAGE + '&time_error=order')
check('errors are explained on the page', 'notice-error' in page and 'Sign Out' in page)

sign_in_id = int(logs("event_type='sign_in'")[0]['id'])
n_edit, page = form_nonce(adm, PAGE + '&start=%s&end=%s&edit_time_id=%d' % (DAY, DAY, sign_in_id), 'ews31_time_save')
check("the edit form selects the record's employee", re.search(r'<option value="%d"\s+selected' % eid, page) is not None)
qs = save(_wpnonce=n_edit, time_id=sign_in_id, event_at=DAY + 'T09:30')
check('a record can be edited (and is not counted as its own duplicate)', qs.get('time_notice') == 'saved' and logs("id=%d" % sign_in_id)[0]['event_at'].startswith(DAY + ' 09:30'), qs)
check('...the edit is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='time_manual_edit'")[0]['c']) == 1)
php("$wpdb->update($p.'ews_time_logs',['event_type'=>'late_sign_in'],['id'=>%d]);" % sign_in_id)
n_edit, page = form_nonce(adm, PAGE + '&start=%s&end=%s&edit_time_id=%d' % (DAY, DAY, sign_in_id), 'ews31_time_save')
check('a Late Sign In shows as Late Sign In in the edit form', re.search(r'<option value="late_sign_in"\s+selected', page) is not None)
save(_wpnonce=n_edit, time_id=sign_in_id, event_type='late_sign_in', event_at=DAY + 'T09:40')
check('...and stays a Late Sign In when saved', logs("id=%d" % sign_in_id)[0]['event_type'] == 'late_sign_in')

# ---------------------------------------------------------------- CSV
php("$wpdb->update($p.'ews_employees',['name'=>'=HYPERLINK(\"http://evil\")'],['id'=>%d]);" % eid)
st, csv, h = adm.req('/wp-admin/admin-post.php?action=ews31_time_csv&start=%s&end=%s' % (DAY, DAY))
check('the CSV export needs the page link (nonce)', 'Sign In' not in csv or st == 403, st)
m = re.search(r'href="([^"]*action=ews31_time_csv[^"]*)"', adm.req(PAGE + '&start=%s&end=%s' % (DAY, DAY))[1])
url = m.group(1).replace('&amp;', '&').replace('&#038;', '&') if m else '/none'
st, csv, h = adm.req(url[url.index('/wp-admin'):] if '/wp-admin' in url else url)
check('the CSV export downloads', st == 200 and 'Scheduled Status' in csv and DAY in csv, (st, csv[:200]))
check('...and neutralises spreadsheet formulas in names', "'=HYPERLINK" in csv and ',=HYPERLINK' not in csv and '"=HYPERLINK' not in csv, csv[:400])
php("$wpdb->update($p.'ews_employees',['name'=>'Emp One'],['id'=>%d]);" % eid)

# ---------------------------------------------------------------- reset day
php("$wpdb->insert($p.'ews_break_sessions',['employee_id'=>%d,'user_id'=>%d,'work_date'=>'%s','start_at'=>'%s 12:00:00','status'=>'Closed']);" % (eid, ids()['uid'], DAY, DAY))
n_reset, page = form_nonce(adm, PAGE + '&start=%s&end=%s' % (DAY, DAY), 'ews31_time_reset')
emp.post('ews31_time_reset', _wpnonce=n_reset, employee_id=eid, work_date=DAY)
check('employee cannot reset a day', len(logs()) == 2)
_, qs, _ = adm.post('ews31_time_reset', _wpnonce=n_reset, employee_id=eid, work_date=DAY)
check('Reset Day removes the day\'s records and breaks', not logs() and not q("SELECT id FROM {p}ews_break_sessions WHERE employee_id=%d" % eid) and qs.get('time_reset') == '2', qs)
check('...it is audited', int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='time_reset'")[0]['c']) == 1)
emp.post('ews31_time_save', _wpnonce=n_save, employee_id=eid, work_date=DAY, event_type='sign_in', event_at=DAY + 'T09:00')
check('employee cannot add records', not logs())

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

"""Company holidays are not leave days (3.31.162, docs/tasks/leave-holidays.md). Off: a leave counts the company holidays inside it
as before and there is no report. On: a new leave skips them (its balance, its leave days, its schedule); HR finds this year's
leaves charged for a holiday before and gives those days back, once only (the balance, the leave's days, its schedule, the Audit
Log, the employee told); a pending leave shows once approved; a leave with a change waiting cannot be corrected; a later
cancellation gives back only what the leave still charges.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_holidays.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080. Wipes Workforce One leave data.
"""
import atexit, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""file_put_contents(getenv('S').'/leave_holidays_saved.ser',serialize(['o'=>$wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'",ARRAY_A),
    'w'=>$wpdb->get_results("SELECT id,approval_mode,active FROM {$p}ews_approval_workflows",ARRAY_A),
    'c'=>$wpdb->get_results("SELECT * FROM {$p}ews_company_calendar",ARRAY_A)]));""")
TABLES = "['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_leave_changes','ews_leave_holiday_refunds','ews_approval_requests','ews_approval_steps','ews_notifications']"
H1, H2, H3, H4 = d(8), d(14), d(20), d(26)   # company holidays inside the leaves below
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(%s as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=$eid");
    $wpdb->query("DELETE FROM {$p}ews_company_calendar");
    foreach(['%s','%s','%s','%s'] as $i=>$h) $wpdb->insert($p.'ews_company_calendar',['event_date'=>$h,'title'=>'Holiday '.($i+1),'event_type'=>'general_leave','active'=>1]);
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_my_requests_leave','ews_feature_leave_page','ews_feature_leave_return','ews_feature_leave_holidays','ews_approval_settings'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    $annual=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=40,kind='planned',after_days=0 WHERE id=$annual");
    echo wp_json_encode(['emp'=>$eid,'annual'=>$annual]);
""" % (TABLES, H1, H2, H3, H4)).splitlines()[-1])
E = ids['emp']


def restore():
    """What this suite changed is put back, even when it stops early."""
    php("""$s=unserialize(file_get_contents(getenv('S').'/leave_holidays_saved.ser')); $keep=[];
    foreach($s['o'] as $o){ $keep[]=$o['option_name']; $wpdb->replace($wpdb->options,$o); }
    foreach($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'") as $n) if(!in_array($n,$keep,true)) $wpdb->delete($wpdb->options,['option_name'=>$n]);
    wp_cache_flush();
    foreach(""" + TABLES + """ as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id=""" + str(E) + """");
    $wpdb->query("DELETE FROM {$p}ews_company_calendar"); foreach($s['c'] as $c) $wpdb->insert($p.'ews_company_calendar',$c);
    foreach($s['w'] as $w) $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>$w['approval_mode'],'active'=>$w['active']],['id'=>$w['id']]);""")


atexit.register(restore)
emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
fn = re.search(r'name="_wpnonce" value="([^"]+)"', adm.req('/wp-admin/admin.php?page=ews31-features')[1])
features_nonce = fn.group(1) if fn else ''
LEAVES = '/wp-admin/admin.php?page=ews31-leaves'


def switch(on):
    data = {'_wpnonce': features_nonce, 'leave_holidays_present': '1'}
    if on:
        data['leave_holidays_enabled'] = '1'
    adm.post('ews31_features_save', **data)
    return php("echo (int)get_option('ews_feature_leave_holidays');").strip().endswith('1') == on


def used():
    r = q("SELECT SUM(used) u,SUM(pending) p FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d" % (E, ids['annual']))
    return (float(r[0]['u'] or 0), float(r[0]['p'] or 0)) if r else (0.0, 0.0)


def row(lid):
    return q("SELECT start_date,end_date,status,cancellation_status,requested_days,holidays_excluded FROM {p}ews_leave_requests WHERE id=%d" % lid)[0]


def snaps(lid):
    return [r['work_date'] for r in q("SELECT work_date FROM {p}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date" % lid)]


def sched(day):
    r = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (E, day))
    return r[0]['status'] if r else None


def ask(a, b):
    emp.post('ews_vacation_request_create', _wpnonce=emp.nonce('ews_vacation_request_create'), leave_type_id=ids['annual'], start_date=d(a), end_date=d(b), reason='e2e')
    return int(q("SELECT MAX(id) id FROM {p}ews_leave_requests")[0]['id'])


def approve(lid):
    adm.post('ews_vacation_request_respond', _wpnonce=adm.nonce('ews_vacation_request_respond'), request_id=lid, decision='approve')


def report():
    return adm.req(LEAVES + '&tab=holidays')[1]


def restore_nonce(pg):
    m = re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>\s*<input type="hidden" name="action" value="ews_leave_holiday_restore"', pg)
    return m.group(1) if m else ''


def give_back(**data):
    pg = report()
    return adm.post('ews_leave_holiday_restore', _wpnonce=restore_nonce(pg) or 'x', **data)


def refunds(lid):
    return [r['work_date'] for r in q("SELECT work_date FROM {p}ews_leave_holiday_refunds WHERE leave_request_id=%d ORDER BY work_date" % lid)]


tag = '[off] '
# --- Off: exactly as before.
a = ask(6, 10)   # 5 days with H1 = d(8)
approve(a)
check(tag + 'a leave over a company holiday counts it (5 days) and is not marked', float(row(a)['requested_days']) == 5.0 and int(row(a)['holidays_excluded']) == 0, row(a))
check(tag + '...the holiday is a leave day, Vacation in the schedule', H1 in snaps(a) and sched(H1) == 'Vacation', (snaps(a), sched(H1)))
check(tag + '...5 days used', used() == (5.0, 0.0), used())
pg = adm.req(LEAVES)[1]
check(tag + 'no Company holidays tab on the Leaves page', 'tab=holidays' not in pg)
check(tag + 'the tab itself shows the balances page', 'data-lh-table' not in report() and 'data-lh-empty' not in report())
st, qs, body = adm.post('ews_leave_holiday_restore', _wpnonce='x', leave_ids=a)
check(tag + 'giving back is refused', float(row(a)['requested_days']) == 5.0 and 'is off' in body, (st, body[:200]))
p_old = ask(12, 16)  # pending, asked while off: 5 days with H2 = d(14)
check(tag + 'a pending leave asked while off counts the holiday', float(row(p_old)['requested_days']) == 5.0 and used() == (5.0, 5.0), (row(p_old), used()))

tag = '[on] '
check(tag + 'the switch turns on, and is audited', switch(True) and int(q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='leave_holidays_feature_update'")[0]['n']) >= 1)
pg = adm.req(LEAVES)[1]
check(tag + 'the Leaves page has the Company holidays tab', 'tab=holidays' in pg)
pg = report()
check(tag + 'the report lists the approved leave charged for H1', 'data-lh-leave="%d"' % a in pg, pg[:300])
check(tag + '...not the pending one (shown once decided)', 'data-lh-leave="%d"' % p_old not in pg)

# A new leave skips the holiday.
n = ask(18, 22)      # H3 = d(20)
check(tag + 'a new leave over a holiday counts 4 days, marked', float(row(n)['requested_days']) == 4.0 and int(row(n)['holidays_excluded']) == 1, row(n))
approve(n)
check(tag + '...approved: no leave day on the holiday, which is not Vacation', H3 not in snaps(n) and len(snaps(n)) == 4 and sched(H3) != 'Vacation', (snaps(n), sched(H3)))
check(tag + '...and never in the report', 'data-lh-leave="%d"' % n not in report())
nn = ask(26, 26)
check(tag + 'a leave on a holiday only is refused (no working day)', nn == n, nn)

# The pending leave asked before: approved the old way, then in the report.
approve(p_old)
check(tag + 'the old pending leave is approved as asked (5 days, H2 a leave day)', float(row(p_old)['requested_days']) == 5.0 and H2 in snaps(p_old), (row(p_old), snaps(p_old)))
check(tag + '...and is in the report now', 'data-lh-leave="%d"' % p_old in report())
u0 = used()[0]

# Giving back one leave.
give_back(one=a)
check(tag + 'given back: the leave counts 4 days', float(row(a)['requested_days']) == 4.0, row(a))
check(tag + '...one day back to the balance', used()[0] == u0 - 1, (u0, used()))
check(tag + '...the holiday is no longer a leave day nor Vacation', H1 not in snaps(a) and len(snaps(a)) == 4 and sched(H1) != 'Vacation', (snaps(a), sched(H1)))
check(tag + '...recorded once', refunds(a) == [H1], refunds(a))
check(tag + '...the Audit Log keeps it', int(q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='leave_holiday_refund' AND entity_id=%d" % a)[0]['n']) == 1)
check(tag + '...the employee is told', int(q("SELECT COUNT(*) n FROM {p}ews_notifications WHERE title='Leave days given back'")[0]['n']) == 1)
check(tag + '...the other leave stays as it was', float(row(p_old)['requested_days']) == 5.0 and H2 in snaps(p_old))
check(tag + '...and the leave left the report', 'data-lh-leave="%d"' % a not in report())
give_back(**{'leave_ids[]': a})
check(tag + 'giving back again changes nothing', float(row(a)['requested_days']) == 4.0 and used()[0] == u0 - 1 and refunds(a) == [H1], (row(a), used()))

# A change waiting: shown, not corrected.
php("$wpdb->query(\"UPDATE {$p}ews_leave_requests SET cancellation_status='Pending' WHERE id=%d\");" % p_old)
pg = report()
check(tag + 'a leave with a change waiting is shown without a button', 'data-lh-leave="%d"' % p_old in pg and 'name="one" value="%d"' % p_old not in pg)
give_back(**{'leave_ids[]': p_old})
check(tag + '...and is not corrected', float(row(p_old)['requested_days']) == 5.0 and refunds(p_old) == [], row(p_old))
php("$wpdb->query(\"UPDATE {$p}ews_leave_requests SET cancellation_status=NULL WHERE id=%d\");" % p_old)
give_back(**{'leave_ids[]': p_old})
check(tag + 'once decided, the selection gives it back', float(row(p_old)['requested_days']) == 4.0 and refunds(p_old) == [H2] and H2 not in snaps(p_old), (row(p_old), refunds(p_old)))

# A later cancellation gives back what the leave still charges, not the holiday again.
before = used()[0]
emp.post('ews_leave_cancel', _wpnonce=emp.nonce('ews_leave_cancel'), request_id=a)
adm.post('ews_leave_cancel_respond', _wpnonce=adm.nonce('ews_leave_cancel_respond'), request_id=a, decision='approve')
check(tag + 'cancelling the corrected leave returns its 4 days, not 5', row(a)['status'] == 'Cancelled' and used()[0] == before - 4, (row(a), before, used()))

# Off again: nothing new is counted differently, the report goes.
check(tag + 'turned off', switch(False))
o = ask(25, 27)      # H4 = d(26)
check('[off again] a new leave counts the holiday again', float(row(o)['requested_days']) == 3.0 and int(row(o)['holidays_excluded']) == 0, row(o))
check('[off again] the report is gone', 'tab=holidays' not in adm.req(LEAVES)[1])
check('[off again] a leave counted while on keeps its days', float(row(n)['requested_days']) == 4.0 and H3 not in snaps(n), (row(n), snaps(n)))

results()

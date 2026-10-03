"""Behaviour tests for wp-admin → Auto Attendance: rules that record Sign In / Sign Out for an
employee at set times (one day or every week), run by the five-minute cron, and the bulk Sign Out.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_auto_attendance.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data and changes the site time zone (restored); never run against a real site.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-auto-attendance'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_auto_attendance_rules','ews_break_sessions','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false); update_option('ews_feature_breaks',1,false);
        $mk=function($name)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1]);return $wpdb->insert_id;};
        echo wp_json_encode(['emp'=>$eid,'vera'=>$mk('Vera Vacation'),'nina'=>$mk('Nina Night'),'wes'=>$mk('Wes West')]);
    """)
    return json.loads(out.splitlines()[-1])


def day(offset=0):
    return php("echo date('Y-m-d',strtotime(current_time('Y-m-d').' %+d day'));" % offset).strip()


def plan(eid, date, status='Office'):
    php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (eid, date, status))


def logs(eid):
    return [(r['work_date'], r['event_type'], r['event_at'][:16], r['location_status']) for r in q("SELECT work_date,event_type,event_at,location_status FROM {p}ews_time_logs WHERE employee_id=%d ORDER BY event_at" % eid)]


def tick():
    php("do_action('ews_auto_attendance_tick');")


def forms(page, action):
    return [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="action" value="%s"' % action in f]


def nonce(form):
    m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
    return m.group(1) if m else ''


ids = seed()
adm = Session('admin', 'admin')
st, page, _ = adm.req(PAGE)
f = forms(page, 'ews_auto_attendance_save')
check('the page opens with the rule form', st == 200 and f and 'Vera Vacation' in f[0])
n = nonce(f[0]) if f else ''


def save(**fields):
    data = {'action': 'ews_auto_attendance_save', '_wpnonce': n, 'rule_id': 0, 'employee_id': 0, 'recurrence': 'one_time', 'run_date': '', 'sign_in_time': '', 'sign_out_time': '', 'enabled': '1'}
    data.update({k: v if isinstance(v, list) else str(v) for k, v in fields.items()})
    return adm.req('/wp-admin/admin-post.php', data)


TODAY, YESTERDAY = day(0), day(-1)

# ---------------------------------------------------------------- saving rules
st, body, _ = save(employee_id=ids['emp'], run_date=TODAY)
check('a rule needs at least one time', 'at least one valid Auto Sign In / Auto Sign Out time' in body and not q("SELECT id FROM {p}ews_auto_attendance_rules"))
st, body, _ = save(employee_id=ids['emp'], run_date='2026-02-31', sign_in_time='08:00')
check('a one-time rule needs a valid date', 'Please provide a valid date.' in body)
st, body, h = save(employee_id=ids['emp'], run_date=TODAY, sign_in_time='00:01', sign_out_time='00:02')
check('a one-time rule is saved', 'saved=1' in h.get('Location', '') and len(q("SELECT id FROM {p}ews_auto_attendance_rules")) == 1)
check('...and recorded in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='auto_attendance_rule_create'")) == 1)

# ---------------------------------------------------------------- running them
plan(ids['emp'], TODAY)
tick()
check('the cron records Sign In and Sign Out at the rule times', logs(ids['emp']) == [(TODAY, 'sign_in', TODAY + ' 00:01', 'auto'), (TODAY, 'sign_out', TODAY + ' 00:02', 'auto')], logs(ids['emp']))
check('...and a one-time rule switches off once both are done', str(q("SELECT enabled FROM {p}ews_auto_attendance_rules")[0]['enabled']) == '0')
php("$wpdb->update($p.'ews_auto_attendance_rules',['enabled'=>1],['employee_id'=>%d]);" % ids['emp'])
tick()
check('running again records nothing twice', len(logs(ids['emp'])) == 2, logs(ids['emp']))

save(employee_id=ids['vera'], recurrence='weekly', sign_in_time='00:01', **{'weekdays[]': [str(d) for d in range(7)]})
plan(ids['vera'], TODAY, 'Vacation')
tick()
check('a day on leave is skipped', logs(ids['vera']) == [], logs(ids['vera']))
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule WHERE employee_id=%d\");" % ids['vera'])
plan(ids['vera'], TODAY)
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>'%s','title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);" % TODAY)
tick()
check('a company holiday is skipped', logs(ids['vera']) == [], logs(ids['vera']))
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")
tick()
check('a weekly rule signs in on its days', [l[1] for l in logs(ids['vera'])] == ['sign_in'], logs(ids['vera']))

# ---------------------------------------------------------------- overnight rule (22:00 → 00:01 next day)
plan(ids['nina'], YESTERDAY)
save(employee_id=ids['nina'], run_date=YESTERDAY, sign_in_time='22:00', sign_out_time='00:01')
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 22:00:00','scheduled_status'=>'Office','location_status'=>'auto']);" % (ids['nina'], YESTERDAY, YESTERDAY))
tick()
check('an overnight rule signs out after midnight, on the day the shift started', logs(ids['nina'])[-1:] == [(YESTERDAY, 'sign_out', TODAY + ' 00:01', 'auto')], logs(ids['nina']))
check('...and then switches off', str(q("SELECT enabled FROM {p}ews_auto_attendance_rules WHERE employee_id=%d" % ids['nina'])[0]['enabled']) == '0')

# ---------------------------------------------------------------- a site west of UTC
php("update_option('timezone_string','America/New_York'); update_option('gmt_offset','');")
wtoday = day(0)
wdow = php("echo (int)date('w',strtotime('%s 12:00:00'));" % wtoday).strip()
plan(ids['wes'], wtoday)
save(employee_id=ids['wes'], recurrence='weekly', sign_in_time='00:01', **{'weekdays[]': [wdow]})
tick()
check('on a site west of UTC a weekly rule runs on its own weekday', [l[1] for l in logs(ids['wes'])] == ['sign_in'], (wtoday, wdow, logs(ids['wes'])))
php("update_option('timezone_string','UTC'); update_option('gmt_offset',0);")

# ---------------------------------------------------------------- page actions
st, page, _ = adm.req(PAGE)
check('rules are listed with their days or date', 'Vera Vacation' in page and 'Sun, Mon, Tue, Wed, Thu, Fri, Sat' in page and YESTERDAY in page)
rid = int(q("SELECT id FROM {p}ews_auto_attendance_rules WHERE employee_id=%d" % ids['vera'])[0]['id'])
toggle = re.search(r'href="([^"]*action=ews_auto_attendance_toggle&(?:amp;|#038;)?rule_id=%d[^"]*)"' % rid, page)
adm.req(html.unescape(toggle.group(1)).split('127.0.0.1:8080')[-1]) if toggle else None
check('a rule can be switched off', str(q("SELECT enabled FROM {p}ews_auto_attendance_rules WHERE id=%d" % rid)[0]['enabled']) == '0')
delete = re.search(r'<a [^>]*href="([^"]*action=ews_auto_attendance_delete&(?:amp;|#038;)?rule_id=%d[^"]*)"[^>]*>' % rid, page)
check('deleting asks for confirmation (no inline script)', delete is not None and 'data-ews-confirm=' in delete.group(0) and 'onclick' not in delete.group(0), delete.group(0) if delete else None)
adm.req(html.unescape(delete.group(1)).split('127.0.0.1:8080')[-1]) if delete else None
check('...and a rule can be deleted', not q("SELECT id FROM {p}ews_auto_attendance_rules WHERE id=%d" % rid))

# ---------------------------------------------------------------- bulk Sign Out
php("$wpdb->query(\"DELETE FROM {$p}ews_time_logs WHERE employee_id=%d AND event_type='sign_out'\"); $wpdb->insert($p.'ews_break_sessions',['employee_id'=>%d,'user_id'=>0,'work_date'=>'%s','start_at'=>current_time('mysql'),'status'=>'Open']);" % (ids['emp'], ids['emp'], TODAY))
bulk = re.search(r'<a [^>]*href="([^"]*action=ews_auto_attendance_bulk_sign_out[^"]*)"[^>]*>', page)
st, _, h = adm.req(html.unescape(bulk.group(1)).split('127.0.0.1:8080')[-1]) if bulk else (0, '', {})
check('bulk Sign Out signs out everyone still signed in today (3)', 'bulk_sign_out=3' in h.get('Location', ''), (h.get('Location'), logs(ids['emp'])))
check('...and closes their open break', q("SELECT status FROM {p}ews_break_sessions WHERE employee_id=%d" % ids['emp'])[0]['status'] == 'Completed')
check('...after asking for confirmation (no inline script)', bulk and 'data-ews-confirm=' in bulk.group(0) and 'onclick' not in bulk.group(0))

emp = Session('emp1', 'emp1pass')
st, body, _ = emp.req(PAGE)
check('an employee cannot open the page', 'Add Rule' not in body)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

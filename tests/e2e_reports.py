"""Behaviour tests for the employee app's Reports (?ews_view=reports) and their CSV / Excel exports.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_reports.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import csv, io, json, os, re, sys, urllib.parse, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

DAY, HOL = d(-3), d(-2)  # an ordinary past day, then a company holiday
REPORT = '/app/?ews_view=reports&report_type=attendance&start=%s&end=%s' % (DAY, HOL)


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_departments','ews_break_sessions','ews_teams','ews_team_members','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_schedule_types_config'); delete_option('ews_shifts');
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_working_hours',['start'=>'09:00','normal_until'=>'11:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false);
        update_option('ews_feature_breaks',1,false); update_option('ews_break_duration_minutes',30,false);
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $mk=function($name,$dep)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1,'department_id'=>$dep]);return $wpdb->insert_id;};
        $ids=['omar'=>$mk('Omar Ontime',$ops),'lina'=>$mk('Lina Late',$ops),'ali'=>$mk('Ali Absent',$ops),'vera'=>$mk('Vera Vacation',$ops),'sam'=>$mk('Sam Sales',$sales)];
        $day='%(day)s'; $hol='%(hol)s';
        foreach(['omar'=>'Office','lina'=>'WFH','ali'=>'Office','vera'=>'Vacation','sam'=>'Office'] as $k=>$st){$wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$day,'status'=>$st]);$wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$hol,'status'=>'Office']);}
        $log=function($k,$type,$time)use($wpdb,$p,$ids,$day){$wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids[$k],'user_id'=>1,'work_date'=>$day,'event_type'=>$type,'event_at'=>$day.' '.$time,'scheduled_status'=>'Office']);};
        $log('omar','sign_in','09:05:00'); $log('omar','sign_out','17:00:00');
        $log('lina','sign_in','09:40:00'); $log('lina','sign_out','16:30:00');
        $log('sam','sign_in','09:00:00'); $log('sam','sign_out','17:00:00');
        $wpdb->insert($p.'ews_break_sessions',['employee_id'=>$ids['omar'],'user_id'=>1,'work_date'=>$day,'start_at'=>$day.' 12:00:00','end_at'=>$day.' 12:45:00','actual_minutes'=>45,'status'=>'Closed']);
        $wpdb->insert($p.'ews_company_calendar',['event_date'=>$hol,'title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);
        echo wp_json_encode($ids);
    """ % {'day': DAY, 'hol': HOL})
    return json.loads(out.splitlines()[-1])


def nonce_url(page, action):
    m = re.search(r'href="([^"]*action=%s[^"]*)"' % action, page)
    return m.group(1).replace('&#038;', '&').replace('&amp;', '&').split('127.0.0.1:8080')[-1] if m else None


def csv_rows(sess, page):
    url = nonce_url(page, 'ews31_report_download')
    st, body, _ = sess.req(url) if url else (0, '', {})
    return list(csv.DictReader(io.StringIO(body.lstrip('﻿')))) if st == 200 else []


def row(rows, name, date):
    return next((r for r in rows if r.get('Employee') == name and r.get('Date') == date), {})


def cards(page):
    return {m.group(2): int(m.group(1)) for m in re.finditer(r'<b>(\d+)</b><small>([^<]+)</small>', page)}


def xlsx_kpis(sess, page):
    """Label → value pairs of the Excel summary cards (rows 6 and 7 of the first sheet)."""
    url = nonce_url(page, 'ews31_report_xlsx')
    if not url:
        return {}
    req = sess.op.open(__import__('urllib.request').request.Request('http://127.0.0.1:8080' + url))
    data = req.read()
    try:
        sheet = zipfile.ZipFile(io.BytesIO(data)).read('xl/worksheets/sheet1.xml').decode()
    except Exception:
        return {}
    def cells(r):
        m = re.search(r'<row r="%d"[^>]*>(.*?)</row>' % r, sheet, re.S)
        return re.findall(r'<c r="([A-Z]+)%d"[^>]*>(?:<v>([^<]*)</v>|<is><t[^>]*>([^<]*)</t></is>)?' % r, m.group(1)) if m else []
    labels = {c: (v or t) for c, v, t in cells(6) if (v or t)}
    values = {c: (v or t) for c, v, t in cells(7)}
    return {labels[c]: values.get(c, '') for c in labels}


ids = seed()
adm = Session('admin', 'admin')
st, page, _ = adm.req(REPORT)
check('the Attendance report opens', st == 200 and 'Report Summary' in page, st)
rows = csv_rows(adm, page)
omar, lina, ali = row(rows, 'Omar Ontime', DAY), row(rows, 'Lina Late', DAY), row(rows, 'Ali Absent', DAY)
check('CSV has the new time columns', rows and all(k in rows[0] for k in ('Late (min)', 'Early Leave (min)', 'Break (min)', 'Net Hours', 'Expected Hours')), list(rows[0].keys()) if rows else rows)
check('breaks are deducted: 09:05-17:00 with a 45 min break is 7:10 net', (omar.get('Result'), omar.get('Break (min)'), omar.get('Net Hours')) == ('Present', '45', '7:10'), omar)
check('expected hours are the shift minus the allowed break (7:30)', omar.get('Expected Hours') == '7:30' and ali.get('Expected Hours') == '7:30', (omar.get('Expected Hours'), ali.get('Expected Hours')))
check('late minutes count from the shift start (09:40 → 40) and early leave from its end (16:30 → 30)', (lina.get('Result'), lina.get('Late (min)'), lina.get('Early Leave (min)'), lina.get('Net Hours')) == ('Late', '40', '30', '6:50'), lina)
check('an absent day has no worked hours', (ali.get('Result'), ali.get('Net Hours')) == ('Absent', '0:00'), ali)
hol = [r.get('Result') for r in rows if r.get('Date') == HOL]
check('a company holiday is Holiday, not Leave, and expects no hours', hol and set(hol) == {'Holiday'} and all(r.get('Expected Hours') in ('', '0:00') for r in rows if r.get('Date') == HOL), hol)
c = cards(page)
check('summary: Leave counts real leave only (1), Holiday separately (6), Absent 1', (c.get('Leave'), c.get('Holiday'), c.get('Absent')) == (1, 6, 1), c)
k = xlsx_kpis(adm, page)
check('the Excel summary shows the same numbers as the screen', k and all(str(c.get(label)) == str(k.get(label)) for label in ('Present', 'Late', 'Absent', 'Leave')), (k, c))
st, page2, _ = adm.req('/app/?ews_view=reports&report_type=attendance')
check('the default period is this month to date', re.search(r'name="start" value="%s"' % today.strftime('%Y-%m-01'), page2) is not None, re.findall(r'name="start" value="([^"]*)"', page2))

# ---------------------------------------------------------------- Report Center: Attendance Summary
SUMMARY = '/app/?ews_view=reports&report_type=summary&start=%s&end=%s' % (DAY, HOL)


def summary_row(page, name):
    m = re.search(r'<tr data-employee="%s">(.*?)</tr>' % re.escape(name), page, re.S)
    return {c: re.sub(r'<[^>]+>', '', v).strip() for c, v in re.findall(r'<td data-col="([a-z_]+)"[^>]*>(.*?)</td>', m.group(1), re.S)} if m else {}


st, page, _ = adm.req('/app/?ews_view=reports')
check('Reports opens on the Attendance Summary', 'data-report="summary"' in page and 'Attendance Summary' in page)
st, page, _ = adm.req(SUMMARY)
lina, omar, ali = summary_row(page, 'Lina Late'), summary_row(page, 'Omar Ontime'), summary_row(page, 'Ali Absent')
check('summary per employee: late days, late minutes, rates', (lina.get('late'), lina.get('late_minutes'), lina.get('attendance_rate'), lina.get('punctuality_rate')) == ('1', '40', '100%', '0%'), lina)
check('...net, expected and balance hours', (lina.get('net'), lina.get('expected'), lina.get('balance')) == ('6:50', '7:30', '−0:40'), lina)
check('...an absent employee', (ali.get('absent'), ali.get('attendance_rate'), omar.get('punctuality_rate')) == ('1', '0%', '100%'), (ali, omar))
kpi = dict(re.findall(r'<div class="ews-rc-kpi" data-kpi="([a-z_]+)">\s*<small>[^<]*</small>\s*<b>([^<]*)</b>', page))
check('KPIs for the whole report (3 of 4 expected days attended = 75%)', kpi.get('attendance_rate') == '75%' and kpi.get('late_minutes') == '40', kpi)
check('with no data before the period, the KPIs say so instead of a fake change', 'No data for the previous period' in page and 'vs previous' not in page)
php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (ids['ali'], d(-4)))
st, page, _ = adm.req(SUMMARY)
check('KPIs compare with the previous period (attendance 0%% before → ▲ 75 pts)', re.search(r'data-kpi="attendance_rate">.*?▲ 75 pts vs previous', page, re.S) is not None, re.findall(r'class="ews-rc-delta[^"]*">([^<]*)', page))
check('a rate with no days behind it shows "—"', summary_row(page, 'Vera Vacation').get('attendance_rate') == '—' and summary_row(page, 'Ali Absent').get('punctuality_rate') == '—')
link = re.search(r'<td data-col="absent"[^>]*><a href="([^"]+)"', page.split('data-employee="Ali Absent"')[-1])
check('every count opens the days behind it', link is not None)
if link:
    st, drill, _ = adm.req(link.group(1).replace('&#038;', '&').replace('&amp;', '&').split('127.0.0.1:8080')[-1])
    names = re.findall(r'<tr><td><strong>([^<]+)</strong><small>', drill)
    check('...e.g. Ali\'s absent day in the Daily Log', names == ['Ali Absent'] and 'data-report="attendance"' in drill, names)
check('the table can be sorted', 'data-ews-sortable' in page and 'assets/js/reports.js' in page)
rows = csv_rows(adm, page)
check('the summary exports with its own columns', rows and {'Employee', 'Attendance %', 'Late (min)', 'Net Hours', 'Expected Hours', 'Balance'} <= set(rows[0].keys()), list(rows[0].keys()) if rows else rows)
check('the status filter belongs to the Daily Log only', 'name="status"' not in page)

uid = q("SELECT wp_user_id FROM {p}ews_employees WHERE name='Mona Manager'")[0]['wp_user_id']
php("$u=new WP_User(%s); $u->add_cap('ews_view_reports');" % uid)
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req(REPORT)
rows = csv_rows(emp, page)
check('a department manager reports on their department only', rows and 'Sam Sales' not in {r['Employee'] for r in rows} and 'Omar Ontime' in {r['Employee'] for r in rows})
php("$u=new WP_User(%s); $u->remove_cap('ews_view_reports');" % uid)
st, page, _ = emp.req(REPORT)
check('without View Reports there is no report', 'Report Summary' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

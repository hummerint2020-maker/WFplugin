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

# ---------------------------------------------------------------- Timesheet (payroll) and multi-sheet Excel
php("""
    update_option('ews_feature_overtime',1,false);
    $wpdb->query("DELETE FROM {$p}ews_overtime_requests");
    $ops=(int)$wpdb->get_var("SELECT department_id FROM {$p}ews_employees WHERE name='Omar Ontime'");
    $wpdb->insert($p.'ews_employees',['name'=>'Otto Overtime','domain_name'=>'otto','email'=>'otto@example.com','active'=>1,'attendance_enabled'=>1,'department_id'=>$ops]); $o=$wpdb->insert_id;
    $wpdb->insert($p.'ews_schedule',['employee_id'=>$o,'work_date'=>'%(day)s','status'=>'Office']);
    $wpdb->insert($p.'ews_time_logs',['employee_id'=>$o,'user_id'=>1,'work_date'=>'%(day)s','event_type'=>'sign_in','event_at'=>'%(day)s 09:00:00','scheduled_status'=>'Office']);
    $wpdb->insert($p.'ews_time_logs',['employee_id'=>$o,'user_id'=>1,'work_date'=>'%(day)s','event_type'=>'sign_out','event_at'=>'%(day)s 18:30:00','scheduled_status'=>'Office']);
    $wpdb->insert($p.'ews_overtime_requests',['employee_id'=>$o,'overtime_date'=>'%(day)s','start_time'=>'17:00:00','end_time'=>'18:00:00','requested_minutes'=>60,'reason'=>'Release','status'=>'Approved','requested_by'=>1,'requested_at'=>current_time('mysql')]);
""" % {'day': DAY})
TIMESHEET = '/app/?ews_view=reports&report_type=timesheet&start=%s&end=%s' % (DAY, HOL)
st, page, _ = adm.req(TIMESHEET)
otto, omar, vera = summary_row(page, 'Otto Overtime'), summary_row(page, 'Omar Ontime'), summary_row(page, 'Vera Vacation')
check('the Timesheet tab opens', st == 200 and 'data-report="timesheet"' in page)
check('timesheet hours, also as decimals for payroll', (omar.get('net'), omar.get('net_decimal'), otto.get('net'), otto.get('net_decimal'), otto.get('balance')) == ('7:10', '7.17', '9:30', '9.50', '+2:00'), (omar, otto))
check('overtime: approved 1:00, worked 1:00, unapproved extra 0:30', (otto.get('ot_approved'), otto.get('ot_actual'), otto.get('ot_extra')) == ('1:00', '1:00', '0:30'), otto)
check('leave taken by type and holidays', (vera.get('leave_days'), vera.get('leave_breakdown'), omar.get('holidays')) == ('1', 'Vacation 1', '1'), (vera, omar))
rows = csv_rows(adm, page)
check('the timesheet CSV has payroll columns', rows and {'Employee ID', 'Worked Days', 'Net Hours', 'Net Hours (decimal)', 'Expected Hours', 'Overtime Worked', 'Unapproved Extra', 'Leave Breakdown'} <= set(rows[0].keys()), list(rows[0].keys()) if rows else rows)


def xlsx_sheets(sess, page):
    url = nonce_url(page, 'ews31_report_xlsx')
    data = sess.op.open(__import__('urllib.request').request.Request('http://127.0.0.1:8080' + url)).read() if url else b''
    try:
        return re.findall(r'<sheet name="([^"]+)"', zipfile.ZipFile(io.BytesIO(data)).read('xl/workbook.xml').decode())
    except Exception:
        return []


check('the timesheet Excel has Timesheet, Daily Details and Definitions sheets', xlsx_sheets(adm, page) == ['Timesheet', 'Daily Details', 'Definitions'], xlsx_sheets(adm, page))
st, page, _ = adm.req(SUMMARY)
check('...and the summary Excel too', xlsx_sheets(adm, page) == ['Attendance Summary', 'Daily Details', 'Definitions'], xlsx_sheets(adm, page))
check('exports are recorded in the audit log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='report_export'")) >= 3)

# ---------------------------------------------------------------- Workforce on the engine
php("""
    $types=get_option('ews_schedule_types_config'); if(!is_array($types)||!$types){$m=new ReflectionMethod('EWS_Manager_V31_1','default_schedule_types_config');$m->setAccessible(true);$types=$m->invoke(new EWS_Manager_V31_1());}
    $types[]=['name'=>'Field Visit','requires_sign_in'=>1,'requires_location'=>0,'attendance_rule'=>'attendance','active'=>1,'icon'=>'•','bg_color'=>'#f2f4f7','text_color'=>'#667085','border_color'=>'#e5e7eb'];
    update_option('ews_schedule_types_config',$types,false);
    $wpdb->update($p.'ews_schedule',['status'=>'Field Visit'],['employee_id'=>%(lina)d,'work_date'=>'%(day)s']);
    $wpdb->update($p.'ews_schedule',['status'=>'Training Course'],['employee_id'=>%(sam)d,'work_date'=>'%(day)s']);
""" % {'lina': ids['lina'], 'sam': ids['sam'], 'day': DAY})
WORKFORCE = '/app/?ews_view=reports&report_type=workforce&start=%s&end=%s' % (DAY, HOL)
st, page, _ = adm.req(WORKFORCE)


def wf_day(page, date):
    m = re.search(r'<tr data-date="%s">(.*?)</tr>' % date, page, re.S)
    return {c: re.sub(r'<[^>]+>', '', v).strip() for c, v in re.findall(r'<td data-col="([a-z_]+)"[^>]*>(.*?)</td>', m.group(1), re.S)} if m else {}


day, hol = wf_day(page, DAY), wf_day(page, HOL)
check('Workforce: every schedule type is counted (custom types under Other, Training Course under Business Trip)', (day.get('office'), day.get('wfh'), day.get('leave'), day.get('trip'), day.get('other'), day.get('not_set')) == ('3', '0', '1', '1', '1', '1'), day)
check('...a company holiday is its own column', (hol.get('holiday'), hol.get('office')) == ('7', '0'), hol)
check('...and the actual absences are shown next to the plan', day.get('absent') == '1', day)
rows = csv_rows(adm, page)
check('...the CSV matches the screen', rows and next((r for r in rows if r['Date'] == DAY), {}).get('Other') == '1', rows[:2] if rows else rows)

uid = q("SELECT wp_user_id FROM {p}ews_employees WHERE name='Mona Manager'")[0]['wp_user_id']
php("$u=new WP_User(%s); $u->add_cap('ews_view_reports');" % uid)
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req(REPORT)
rows = csv_rows(emp, page)
check('a department manager reports on their department only', rows and 'Sam Sales' not in {r['Employee'] for r in rows} and 'Omar Ontime' in {r['Employee'] for r in rows})
# A manager on a different shift (07:00-15:00) sees the employee's overtime against the employee's shift.
php("$s=get_option('ews_shifts'); $s=is_array($s)?$s:[]; $s[]=['id'=>77,'name'=>'Early','start'=>'07:00','end'=>'15:00','grace'=>10,'active'=>1]; update_option('ews_shifts',$s,false); $wpdb->update($p.'ews_employees',['default_shift_id'=>77],['name'=>'Mona Manager']);")
st, page, _ = emp.req(TIMESHEET)
check('overtime follows the employee\'s shift, not the viewer\'s', summary_row(page, 'Otto Overtime').get('ot_extra') == '0:30', summary_row(page, 'Otto Overtime'))
# The weekly schedule email from a department manager goes to their department only.
st, sched, _ = emp.req('/app/?ews_view=schedule')
mail = re.search(r'href="([^"]*action=ews31_report_email[^"]*)"', sched)
st, body, h = emp.req(mail.group(1).replace('&#038;', '&').split('127.0.0.1:8080')[-1]) if mail else (0, '', {})
qs = {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}
total = sum(int(qs.get(k, 0)) for k in ('email_sent', 'email_skipped', 'email_failed'))
check('the schedule email from a department manager reaches their department only (6 of 7)', total == 6, qs)
php("$u=new WP_User(%s); $u->remove_cap('ews_view_reports');" % uid)
st, page, _ = emp.req(REPORT)
check('without View Reports there is no report', 'Report Summary' not in page)

# Shift lookups are batched (3.31.45): one query for a whole list of employees, same shift each.
out = json.loads(php("""
    update_option('ews_shifts',[['id'=>1,'name'=>'Day','start'=>'08:00','end'=>'16:00','grace'=>5,'active'=>1],['id'=>2,'name'=>'Night','start'=>'22:00','end'=>'06:00','grace'=>15,'overnight'=>1,'active'=>1],['id'=>3,'name'=>'Old','start'=>'07:00','end'=>'15:00','active'=>0]],false);
    $ids=[];foreach([1,2,3,0,99] as $i=>$sid){$wpdb->insert($p.'ews_employees',['name'=>'Shift '.$i,'domain_name'=>'shift'.$i,'active'=>1,'attendance_enabled'=>1,'default_shift_id'=>$sid]);$ids[]=$wpdb->insert_id;}
    $o=new EWS_Manager_V31_1(); $c=new ReflectionProperty('EWS_Manager_V31_1','ews_shift_for_employee_cache'); $c->setAccessible(true);
    $one=new ReflectionMethod('EWS_Manager_V31_1','shift_for_employee'); $one->setAccessible(true);
    $prime=new ReflectionMethod('EWS_Manager_V31_1','prime_shift_cache'); $prime->setAccessible(true);
    $c->setValue(null,[]); $before=$wpdb->num_queries; $single=[];foreach($ids as $id){$s=$one->invoke($o,$id);$single[]=$s?$s['id']:null;} $single_q=$wpdb->num_queries-$before;
    $c->setValue(null,[]); $before=$wpdb->num_queries; $prime->invoke($o,$ids); $batched=[];foreach($ids as $id){$s=$one->invoke($o,$id);$batched[]=$s?$s['id']:null;} $batched_q=$wpdb->num_queries-$before;
    $rows=$wpdb->get_results("SELECT * FROM {$p}ews_employees WHERE id IN (".implode(',',$ids).") ORDER BY id"); $c->setValue(null,[]); $before=$wpdb->num_queries; $prime->invoke($o,$rows); $from_rows=[];foreach($ids as $id){$s=$one->invoke($o,$id);$from_rows[]=$s?$s['id']:null;} $rows_q=$wpdb->num_queries-$before;
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE id IN (".implode(',',$ids).")"); delete_option('ews_shifts');
    echo wp_json_encode(compact('single','single_q','batched','batched_q','from_rows','rows_q'));"""))
check('a batch shift lookup gives each employee the same shift as one at a time (inactive and unknown shifts: Company Working Hours)', out['single'] == out['batched'] == out['from_rows'] == [1, 2, None, None, None], out)
check('...in one query for ids, none for loaded employee rows (was one per employee)', (out['single_q'], out['batched_q'], out['rows_q']) == (5, 1, 0), out)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

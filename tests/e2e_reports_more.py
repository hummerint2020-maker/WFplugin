"""Behaviour tests for the Report Center's second phase: the Overtime and Leave & Balances reports,
overtime worked on days off in the Timesheet, the daily attendance trend and saved views.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_reports_more.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

DAY, OFF = d(-3), d(-2)  # an ordinary working day, then a day off (not a configured working day)
RANGE = '&start=%s&end=%s' % (DAY, OFF)
OFF_DOW = (datetime.date.fromisoformat(OFF).isoweekday()) % 7  # WordPress: 0 = Sunday


def seed(overtime=1):
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_break_sessions','ews_teams','ews_team_members','ews_overtime_requests','ews_leave_requests','ews_leave_balances'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_schedule_types_config'); delete_option('ews_shifts'); delete_user_meta(1,'ews_report_views');
        update_option('ews_working_days',array_values(array_diff([0,1,2,3,4,5,6],[%(off)d])),false);
        update_option('ews_working_hours',['start'=>'09:00','normal_until'=>'11:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false);
        update_option('ews_feature_breaks',0,false); update_option('ews_feature_overtime',%(ot)d,false);
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager'],['id'=>$eid]);
        $mk=function($name)use($wpdb,$p){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1]);return $wpdb->insert_id;};
        $ids=['omar'=>$mk('Omar Overtime'),'lina'=>$mk('Lina Late'),'ali'=>$mk('Ali Absent'),'vera'=>$mk('Vera Vacation')];
        $day='%(day)s'; $off='%(off_day)s';
        foreach(['omar'=>'Office','lina'=>'Office','ali'=>'Office','vera'=>'Vacation'] as $k=>$st)$wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$day,'status'=>$st]);
        $log=function($k,$date,$type,$time)use($wpdb,$p,$ids){$wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids[$k],'user_id'=>1,'work_date'=>$date,'event_type'=>$type,'event_at'=>$date.' '.$time,'scheduled_status'=>'Office']);};
        $log('omar',$day,'sign_in','09:00:00'); $log('omar',$day,'sign_out','19:00:00');
        $log('omar',$off,'sign_in','10:00:00'); $log('omar',$off,'sign_out','13:00:00');
        $log('lina',$day,'sign_in','09:40:00'); $log('lina',$day,'sign_out','18:30:00');
        $ot=function($k,$date,$s,$e,$mins,$status)use($wpdb,$p,$ids){$wpdb->insert($p.'ews_overtime_requests',['employee_id'=>$ids[$k],'overtime_date'=>$date,'start_time'=>$s,'end_time'=>$e,'requested_minutes'=>$mins,'reason'=>'x','status'=>$status,'requested_by'=>1,'requested_at'=>current_time('mysql')]);};
        $ot('omar',$day,'17:00:00','19:00:00',120,'Approved');
        $ot('omar',$off,'10:00:00','14:00:00',240,'Approved');
        $ot('omar',$day,'07:00:00','08:00:00',60,'Rejected');
        $ot('lina',$day,'17:00:00','18:00:00',60,'Pending');
        $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
        $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$ids['vera'],'leave_type_id'=>$type,'leave_year'=>(int)substr($off,0,4),'entitlement'=>21,'used'=>3,'pending'=>2]);
        $lr=function($s,$e,$days,$status)use($wpdb,$p,$ids,$type){$wpdb->insert($p.'ews_leave_requests',['employee_id'=>$ids['vera'],'leave_type_id'=>$type,'start_date'=>$s,'end_date'=>$e,'requested_days'=>$days,'status'=>$status,'requested_by'=>1,'requested_at'=>current_time('mysql')]);};
        $lr($day,$day,1,'Approved'); $lr($off,$off,1,'Pending'); $lr('2001-01-01','2001-01-02',2,'Approved');
        echo wp_json_encode($ids);
    """ % {'off': OFF_DOW, 'ot': overtime, 'day': DAY, 'off_day': OFF})
    return json.loads(out.splitlines()[-1])


def cells(page, name):
    """data-col → data-value of the table row of one employee."""
    m = re.search(r'<tr data-employee="%s">(.*?)</tr>' % re.escape(name), page, re.S)
    return {c: html.unescape(v) for c, v in re.findall(r'data-col="([a-z_0-9:-]+)" data-value="([^"]*)"', m.group(1))} if m else {}


def tabs(page):
    return re.findall(r'class="ews-report-tab[^"]*" href="[^"]*report_type=([a-z]+)', page)


def form(page, action):
    for f in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action in f:
            return {n: html.unescape(v) for n, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f)}
    return {}


ids = seed()
adm = Session('admin', 'admin')

# ---------------------------------------------------------------- Overtime report
st, page, _ = adm.req('/app/?ews_view=reports&report_type=overtime' + RANGE)
check('the Report Center has Overtime and Leave & Balances tabs', {'overtime', 'leave'} <= set(tabs(page)), tabs(page))
check('the Overtime report opens', 'data-report="overtime"' in page, re.findall(r'data-report="([a-z]+)"', page))
o = cells(page, 'Omar Overtime')
check('Overtime: requests by status (3 = 2 approved + 1 rejected)', [o.get(k) for k in ('requests', 'approved', 'pending', 'rejected')] == ['3', '2', '0', '1'], o)
check('Overtime: approved 6:00 includes the day off; worked 5:00 (2:00 + 3:00 of the 4:00 on the day off)', [o.get('approved_minutes'), o.get('worked_minutes'), o.get('extra_minutes')] == ['360', '300', '0'], o)
check('Overtime: utilisation is worked / approved (83%)', o.get('utilisation') == '83', o)
l = cells(page, 'Lina Late')
check('Overtime: a pending request and 1:30 unapproved extra after the shift', [l.get('requests'), l.get('pending'), l.get('extra_minutes'), l.get('utilisation')] == ['1', '1', '90', '-1'], l)
check('Overtime: employees without overtime are not listed', cells(page, 'Vera Vacation') == {} and cells(page, 'Ali Absent') == {})
m = re.search(r'href="([^"]*action=ews31_report_download[^"]*)"', page)
st, csv_body, _ = adm.req(html.unescape(m.group(1)).split('127.0.0.1:8080')[-1]) if m else (0, '', {})
check('Overtime: the CSV has the same figures', st == 200 and 'Omar Overtime' in csv_body and ',6:00,' in csv_body and ',5:00,' in csv_body and ',83%' in csv_body, csv_body[:400])

# ---------------------------------------------------------------- Timesheet counts the day off
st, page, _ = adm.req('/app/?ews_view=reports&report_type=timesheet' + RANGE)
t = cells(page, 'Omar Overtime')
check('Timesheet: hours worked on a day off count (10:00 + 3:00 = 13:00 net, 2 worked days)', [t.get('net'), t.get('worked_days')] == ['780', '2'], t)
check('Timesheet: overtime worked on the day off counts (5:00)', t.get('ot_actual') == '300', t)
check('Timesheet: a day off expects no hours', t.get('expected') == '480', t)
st, page, _ = adm.req('/app/?ews_view=reports&report_type=attendance' + RANGE + '&employee=%d' % ids['omar'])
check('Daily Log: still lists working days only', page.count('<tr><td><strong>Omar Overtime') == 1, page.count('<tr><td><strong>Omar Overtime'))

# ---------------------------------------------------------------- Leave & Balances report
st, page, _ = adm.req('/app/?ews_view=reports&report_type=leave' + RANGE)
v = cells(page, 'Vera Vacation')
check('Leave: days on leave in the period, by type', [v.get('leave_days'), v.get('leave_breakdown')] == ['1', 'Vacation 1'], v)
check('Leave: requests overlapping the period, by status (old ones excluded)', [v.get('approved_requests'), v.get('approved_days'), v.get('pending_requests'), v.get('pending_days')] == ['1', '1', '1', '1'], v)
check('Leave: Annual Leave balance remaining 16 of 21 (3 used, 2 pending)', v.get('balance:annual-leave') == '16', v)
check('...shown as "16 / 21"', re.search(r'data-col="balance:annual-leave"[^>]*>\s*<strong>16</strong>\s*/ 21', page) is not None)
check('Leave: an employee without a balance row shows no balance', cells(page, 'Omar Overtime').get('balance:annual-leave') == '', cells(page, 'Omar Overtime'))
m = re.search(r'href="([^"]*action=ews31_report_xlsx[^"]*)"', page)
st, body, h = adm.req(html.unescape(m.group(1)).split('127.0.0.1:8080')[-1]) if m else (0, '', {})
check('Leave: the Excel export downloads', st == 200 and 'spreadsheetml' in h.get('Content-Type', ''), (st, h.get('Content-Type')))

# ---------------------------------------------------------------- trend on the Attendance Summary
st, page, _ = adm.req('/app/?ews_view=reports&report_type=summary' + RANGE)
bars = re.findall(r'<rect class="ews-rc-trend-bar[^"]*" data-date="([^"]+)" data-rate="(-?\d+)"', page)
check('Summary: a daily attendance trend, one bar per working day', bars == [(DAY, '67')], bars)

# ---------------------------------------------------------------- saved views
st, page, _ = adm.req('/app/?ews_view=reports&report_type=overtime&start=%s&end=%s&employee_status=all' % (DAY, OFF))
f = form(page, 'ews_report_view_save')
check('the filter bar offers "Save view"', f.get('report_type') == 'overtime' and f.get('employee_status') == 'all', f)
st, _, h = adm.req('/wp-admin/admin-post.php', dict(f, view_name='Overtime check'))
check('saving a view returns to the report', st in (302, 303) and 'report_type=overtime' in h.get('Location', ''), (st, h.get('Location')))
st, page, _ = adm.req('/app/?ews_view=reports')
chips = re.findall(r'<a class="ews-rc-view" href="([^"]+)">([^<]+)</a>', page)
check('the saved view is listed on every report', [c[1] for c in chips] == ['Overtime check'], chips)
qs = urllib.parse.parse_qs(urllib.parse.urlparse(html.unescape(chips[0][0])).query) if chips else {}
check('...and opens with its filters', [qs.get(k, [''])[0] for k in ('report_type', 'start', 'end', 'employee_status')] == ['overtime', DAY, OFF, 'all'], qs)

# A quick range is saved as the range, not as dates: "This Month" stays this month.
first = today.replace(day=1).isoformat()
st, page, _ = adm.req('/app/?ews_view=reports&report_type=summary&start=%s&end=%s' % (first, today.isoformat()))
st, _, h = adm.req('/wp-admin/admin-post.php', dict(form(page, 'ews_report_view_save'), view_name='Month so far'))
saved = json.loads(php("echo wp_json_encode(get_user_meta(1,'ews_report_views',true));") or '[]')
check('a quick range is saved as the range', [v.get('range') for v in saved] in (['', 'this_month'], ['', 'today']) and 'start' not in saved[1], saved)

st, page, _ = adm.req('/app/?ews_view=reports')
f = next((form(x, 'ews_report_view_delete') for x in re.findall(r'<form.*?</form>', page, re.S) if 'Overtime check' in x), {})
adm.req('/wp-admin/admin-post.php', f)
st, page, _ = adm.req('/app/?ews_view=reports')
check('a saved view can be removed', [c[1] for c in re.findall(r'<a class="ews-rc-view" href="([^"]+)">([^<]+)</a>', page)] == ['Month so far'])
check('saving needs the nonce', adm.req('/wp-admin/admin-post.php', {'action': 'ews_report_view_save', 'view_name': 'x', '_wpnonce': 'bad'})[0] in (403, 200) and len(json.loads(php("echo wp_json_encode(get_user_meta(1,'ews_report_views',true));") or '[]')) == 1)

# ---------------------------------------------------------------- Overtime feature off
php("update_option('ews_feature_overtime',0,false);")
st, page, _ = adm.req('/app/?ews_view=reports&report_type=overtime' + RANGE)
check('with Overtime off there is no Overtime tab, and its URL shows the Summary', 'overtime' not in tabs(page) and 'data-report="summary"' in page, (tabs(page), re.findall(r'data-report="([a-z]+)"', page)))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

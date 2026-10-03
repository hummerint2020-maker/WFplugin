"""Behaviour tests for location seats (wp-admin → Work Locations) and the Report Center's Location
Capacity report: planned and actual people per location and day against its seats.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_capacity.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import csv, html, io, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, today, wp, HERE  # noqa: E402

PAST, FUT = d(-1), d(1)
RANGE = '&start=%s&end=%s' % (PAST, FUT)


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_locations','ews_employee_locations_v321','ews_departments'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_schedule_types_config'); delete_option('ews_shifts');
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        update_option('ews_working_hours',['start'=>'09:00','normal_until'=>'11:00','end'=>'17:00'],false);
        $loc=function($name,$seats,$default=0,$active=1)use($wpdb,$p){$wpdb->insert($p.'ews_locations',['name'=>$name,'latitude'=>30,'longitude'=>31,'radius'=>200,'enforcement'=>0,'is_default'=>$default,'active'=>$active,'seats'=>$seats]);return $wpdb->insert_id;};
        $L=['hq'=>$loc('HQ',3,1),'annex'=>$loc('Annex',null),'branch'=>$loc('Branch',null),'old'=>$loc('Old Office',5,0,0)];
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $mk=function($name,$dep,$lid)use($wpdb,$p,$L){$dn=strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1,'department_id'=>$dep]);$id=$wpdb->insert_id;if($lid)$wpdb->insert($p.'ews_employee_locations_v321',['employee_id'=>$id,'location_id'=>$L[$lid]]);return $id;};
        $E=['mona'=>$eid,'a1'=>$mk('Adam One',$ops,'hq'),'a2'=>$mk('Amr Two',$ops,'hq'),'s1'=>$mk('Sara Sales',$sales,'hq'),
            'b1'=>$mk('Basma One',$sales,'annex'),'b2'=>$mk('Bilal Two',$sales,'annex'),'c1'=>$mk('Carl Branch',$sales,'branch'),'o1'=>$mk('Omar Old',$sales,'old')];
        $plan=function($k,$date,$st)use($wpdb,$p,$E){$wpdb->insert($p.'ews_schedule',['employee_id'=>$E[$k],'work_date'=>$date,'status'=>$st]);};
        $past='%(past)s'; $fut='%(fut)s';
        foreach(['mona','a1','a2','s1','b1','b2','c1'] as $k)$plan($k,$fut,'Office');
        $plan('mona',$past,'Office'); $plan('a1',$past,'WFH'); $plan('a2',$past,'Vacation'); $plan('s1',$past,'Office');
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$E['mona'],'user_id'=>1,'work_date'=>$past,'event_type'=>'sign_in','event_at'=>$past.' 09:00:00','scheduled_status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$E['a1'],'user_id'=>1,'work_date'=>$past,'event_type'=>'sign_in','event_at'=>$past.' 09:00:00','scheduled_status'=>'WFH']);
        echo wp_json_encode(['L'=>$L,'E'=>$E]);
    """ % {'past': PAST, 'fut': FUT})
    return json.loads(out.splitlines()[-1])


def cell(page, loc, date):
    m = re.search(r'<td class="ews-rc-cap[^"]*" data-location="%s" data-date="%s"([^>]*)>' % (re.escape(loc), date), page)
    return dict(re.findall(r'data-([a-z]+)="([^"]*)"', m.group(1))) if m else {}


def summary(page, loc):
    m = re.search(r'<tr data-location="%s">(.*?)</tr>' % re.escape(loc), page, re.S)
    return dict(re.findall(r'data-col="([a-z_]+)" data-value="([^"]*)"', m.group(1))) if m else {}


ids = seed()
adm = Session('admin', 'admin')

# ---------------------------------------------------------------- seats in wp-admin
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-multi-locations&edit_location=%d' % ids['L']['annex'])
check('Work Locations has a Seats field', 'name="location_seats"' in page)
form = next((f for f in re.findall(r'<form.*?</form>', page, re.S) if 'value="ews_multi_location_save_v321"' in f), '')
nonce = (re.search(r'name="_wpnonce" value="([^"]+)"', form) or re.search('()', '')).group(1)
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_multi_location_save_v321', '_wpnonce': nonce, 'location_id': ids['L']['annex'], 'location_name': 'Annex',
                                                'location_latitude': '30', 'location_longitude': '31', 'location_radius': '200', 'active': '1', 'location_seats': '2'})
check('seats are saved', q("SELECT seats FROM {p}ews_locations WHERE name='Annex'")[0]['seats'] in ('2', 2), q("SELECT seats FROM {p}ews_locations WHERE name='Annex'"))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-multi-locations')
check('...and listed with the locations', re.search(r'<strong>Annex</strong>.*?<td>2 seats</td>', page, re.S) is not None and re.search(r'<strong>Branch</strong>.*?<td>No limit</td>', page, re.S) is not None)

# ---------------------------------------------------------------- the report
st, page, _ = adm.req('/app/?ews_view=reports&report_type=capacity')
check('the Report Center has a Location Capacity tab', 'report_type=capacity' in page and 'Location Capacity' in page and 'data-report="capacity"' in page)
dates = sorted(set(re.findall(r'data-location="HQ" data-date="([0-9-]+)"', page)))
check('without dates it plans the next two weeks from today', len(dates) == 14 and dates[0] == today.isoformat(), dates[:2] + dates[-1:])

st, page, _ = adm.req('/app/?ews_view=reports&report_type=capacity' + RANGE)
c = cell(page, 'HQ', FUT)
check('HQ tomorrow: 4 planned for 3 seats = 133%, over capacity', [c.get('planned'), c.get('pct'), c.get('level')] == ['4', '133', 'over'], c)
check('...an employee with no location counts at the default location (HQ)', c.get('planned') == '4')
check('...the future has no actual figure', c.get('actual') == '', c)
c = cell(page, 'HQ', PAST)
check('HQ yesterday: WFH and leave do not take a seat (2 planned, 67%)', [c.get('planned'), c.get('pct'), c.get('level')] == ['2', '67', 'ok'], c)
check('...actual counts who signed in among them (1; the WFH Sign In does not count)', c.get('actual') == '1', c)
c = cell(page, 'Annex', FUT)
check('Annex tomorrow: 2 of 2 seats = 100%, a warning (90% or more)', [c.get('planned'), c.get('pct'), c.get('level')] == ['2', '100', 'warn'], c)
c = cell(page, 'Branch', FUT)
check('Branch has no seat limit: no percentage', [c.get('planned'), c.get('pct'), c.get('level')] == ['1', '-1', 'none'], c)
check('an archived location is not listed', 'data-location="Old Office"' not in page)
s = summary(page, 'HQ')
check('per location: seats, peak and days over / near capacity', [s.get('seats'), s.get('peak'), s.get('days_over'), s.get('days_warn')] == ['3', '4', '1', '0'], s)
check('an admin sees no "your employees" split', 'data-mine="' not in page or cell(page, 'HQ', FUT).get('mine') == '')

st, lpage, _ = adm.req('/wp-admin/admin.php?page=ews31-multi-locations')
cf = re.search(r'name="action" value="ews_capacity_settings_save".*?name="_wpnonce" value="([^"]+)"', lpage, re.S)
check('Work Locations sets when a day is "near capacity"', cf is not None and 'name="capacity_warn_percent"' in lpage and 'value="90"' in lpage)
adm.req('/wp-admin/admin-post.php', {'action': 'ews_capacity_settings_save', '_wpnonce': cf.group(1) if cf else '', 'capacity_warn_percent': '60'})
check('...saved', php("echo (int)get_option('ews_capacity_warn_percent',0);").strip() == '60')
st, page60, _ = adm.req('/app/?ews_view=reports&report_type=capacity' + RANGE)
c = cell(page60, 'HQ', PAST)
check('...and the report follows it: 67% is now near capacity', c.get('level') == 'warn', c)
adm.req('/wp-admin/admin-post.php', {'action': 'ews_capacity_settings_save', '_wpnonce': cf.group(1) if cf else '', 'capacity_warn_percent': '10'})
check('...from 50% to 100%', php("echo (int)get_option('ews_capacity_warn_percent',0);").strip() == '50')
php("delete_option('ews_capacity_warn_percent');")

m = re.search(r'href="([^"]*action=ews31_report_download[^"]*)"', page)
st, body, _ = adm.req(html.unescape(m.group(1)).split('127.0.0.1:8080')[-1]) if m else (0, '', {})
rows = list(csv.DictReader(io.StringIO(body.lstrip('﻿'))))
r = next((x for x in rows if x.get('Location') == 'HQ' and x.get('Date') == FUT), {})
check('the CSV has one row per location and day', [r.get('Seats'), r.get('Planned'), r.get('Occupancy'), r.get('Status')] == ['3', '4', '133%', 'Over capacity'], r or rows[:2])
m = re.search(r'href="([^"]*action=ews31_report_xlsx[^"]*)"', page)
st, _, h = adm.req(html.unescape(m.group(1)).split('127.0.0.1:8080')[-1]) if m else (0, '', {})
check('...and the Excel export downloads', st == 200 and 'spreadsheetml' in h.get('Content-Type', ''), st)

# ---------------------------------------------------------------- a department manager
uid = q("SELECT wp_user_id FROM {p}ews_employees WHERE name='Mona Manager'")[0]['wp_user_id']
php("$u=new WP_User(%s); $u->add_cap('ews_view_reports');" % uid)
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req('/app/?ews_view=reports&report_type=capacity' + RANGE)
c = cell(page, 'HQ', FUT)
check('a department manager sees the whole location (4 of 3 seats)...', [c.get('planned'), c.get('level')] == ['4', 'over'], c)
check('...and how many of them are their department (3)', c.get('mine') == '3', c)
php("$u=new WP_User(%s); $u->remove_cap('ews_view_reports');" % uid)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

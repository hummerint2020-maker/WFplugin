"""Behaviour tests for the office minimum (3.31.77), HTTP on a real site.

Pins: the switch (off: nothing shows); the settings in Feature Configuration (saved, validated,
nothing saved on an error); under each day "in the office / minimum"; the warning card lists only the
days below it; each team's share is proportional to its size (largest remainder, adds up to the
minimum) or a fixed number; who could come in (working from home, not on leave); a weekday's own
number, 0 = none, company holidays skipped; which statuses count; saving the schedule is never
blocked and shows nothing extra; approving leave shows what it does to the day; the weekly reminder
(once a week, to schedule managers).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_office_minimum.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, today, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))

# 40 people: Sales 12, Customer Service 10, Accounts 8, IT 6, and 4 in no team. In the office next
# week (Sun..Thu) per team; the others work from home, every 7th (i+day) on leave.
SUN = php("echo date('Y-m-d',strtotime('next sunday',current_time('timestamp')));").strip()
DATES = [(datetime.date.fromisoformat(SUN) + datetime.timedelta(days=i)).isoformat() for i in range(5)]
setup = json.loads(php("""
    foreach(['ews_schedule','ews_team_members','ews_teams','ews_leave_requests','ews_notifications','ews_audit_log','ews_company_calendar'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    delete_option('ews_office_minimum'); delete_option('ews_office_minimum_reminded'); update_option('ews_feature_office_minimum',0,false);
    update_option('ews_working_days',[0,1,2,3,4],false);
    $wpdb->update($p.'ews_approval_workflows',['active'=>0],['workflow_key'=>'vacation']);
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid; $wpdb->update($p.'ews_employees',['active'=>0],['id'=>$eid]);
    $teams=[['Sales',12,[5,4,1,4,2]],['Customer Service',10,[3,3,3,3,3]],['Accounts',8,[3,2,2,3,1]],['IT',6,[2,2,2,2,3]],[null,4,[1,1,1,1,1]]];
    $first=['Ahmed','Mona','Karim','Sara','Omar','Nour','Youssef','Hana','Mostafa','Laila','Tarek','Dina','Hassan','Rania','Ali','Salma','Mahmoud','Yasmin','Amr','Heba','Khaled','Mariam','Sherif','Aya','Wael','Nada','Hesham','Reem','Adel','Eman','Sameh','Noha','Ibrahim','Ghada','Fady','Shaimaa','Ramy','Asmaa','Waleed','Doaa'];
    $last=['Samir','Adel','Fathy','Nabil','Hamdy','Saeed','Gamal','Lotfy'];
    $n=0;$ids=[];$team_ids=[];
    foreach($teams as [$name,$size,$office]){
      $tid=0; if($name){$wpdb->insert($p.'ews_teams',['name'=>$name,'active'=>1]);$tid=(int)$wpdb->insert_id;$team_ids[$name]=$tid;}
      for($i=0;$i<$size;$i++){
        $wpdb->insert($p.'ews_employees',['name'=>$first[$n].' '.$last[$n%8],'domain_name'=>'om'.$n,'email'=>'om'.$n.'@example.com','active'=>1,'attendance_enabled'=>1]); $id=(int)$wpdb->insert_id; $ids[]=$id;
        if($tid)$wpdb->insert($p.'ews_team_members',['team_id'=>$tid,'employee_id'=>$id,'active'=>1]);
        for($d=0;$d<5;$d++){$date=date('Y-m-d',strtotime('__SUN__'." +$d day"));$wpdb->insert($p.'ews_schedule',['employee_id'=>$id,'work_date'=>$date,'status'=>$i<$office[$d]?'Office':((($i+$d)%7===0)?'Vacation':'WFH')]);}
        $n++;
      }
    }
    echo wp_json_encode(['ids'=>$ids,'teams'=>$team_ids,'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}")]);
""".replace('__SUN__', SUN)).splitlines()[-1])
IDS, TEAMS = setup['ids'], setup['teams']
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
ATT = '/app/?ews_view=attendance&week=' + SUN
FEAT = '/wp-admin/admin.php?page=ews31-features'


def att():
    return adm.req(ATT)[1]


def counters(pg):
    """date -> (in the office, minimum), from the table head."""
    return {d: (int(n), int(m)) for d, n, m in re.findall(r'<th[^>]*>.*?data-om-day="([0-9-]+)" data-om-base="(\d+)" data-om-min="(\d+)"', pg, re.S)}


def banner_days(pg):
    m = re.search(r'data-om-banner.*?</section>', pg, re.S)
    return re.findall(r'data-wfo-sheet="wfo-om-([0-9-]+)"', m.group(0)) if m else []


def sheet(pg, d):
    m = re.search(r'<dialog[^>]*id="wfo-om-%s".*?</dialog>' % d, pg, re.S)
    return m.group(0) if m else ''


def team_rows(sh):
    """team name -> (share, in office, gap text)."""
    out = {}
    for r in re.findall(r'<tbody>(.*?)</tbody>', sh, re.S)[0].split('</tr>'):
        m = re.search(r'<th scope="row">([^<]+)<small>.*?<td>(\d+)</td>\s*<td><b>(\d+)</b></td>\s*<td><span class="wfo-om-gap [^"]+">([^<]+)</span>', r, re.S)
        if m:
            out[html.unescape(m.group(1)).strip()] = (int(m.group(2)), int(m.group(3)), m.group(4))
    return out


def form_fields(pg, action):
    """The fields a browser would send for a form (checked boxes only)."""
    form = [f for f in re.findall(r'<form.*?</form>', pg, re.S) if 'value="%s"' % action in f][0]
    data = []
    for tag in re.findall(r'<(?:input|select|textarea)[^>]*>', form):
        name = re.search(r'name="([^"]+)"', tag)
        if not name:
            continue
        typ = (re.search(r'type="([^"]+)"', tag) or [None, 'text'])[1]
        if typ in ('checkbox', 'radio') and 'checked' not in tag:
            continue
        if tag.startswith('<select'):
            sel = re.search(re.escape(tag) + r'(.*?)</select>', form, re.S).group(1)
            opt = re.search(r'<option value="([^"]*)"[^>]*selected', sel) or re.search(r'<option value="([^"]*)"', sel)
            val = opt.group(1) if opt else ''
        else:
            v = re.search(r'value="([^"]*)"', tag)
            val = html.unescape(v.group(1)) if v else ''
        data.append((name.group(1), val))
    return data


def save_features(over=None, drop=()):
    """Feature Configuration as the browser would post it, with some fields changed."""
    fields = [(k, v) for k, v in form_fields(adm.req(FEAT)[1], 'ews31_features_save') if k not in drop and k not in (over or {})]
    for k, v in (over or {}).items():
        for vv in (v if isinstance(v, list) else [v]):
            fields.append((k, vv))
    st, body, h = adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name).strip())


# ---------------------------------------------------------------- off
pg = att()
check('off: no counters, no warning on the Attendance page', 'data-om-day' not in pg and 'data-om-banner' not in pg)
fp = adm.req(FEAT)[1]
check('Feature Configuration has the Office Minimum section, off', 'name="office_minimum_enabled"' in fp and 'name="office_minimum_enabled" value="1" >' in fp.replace('  ', ' ') or ('name="office_minimum_enabled" value="1"  checked' not in fp and 'name="office_minimum_enabled"' in fp))
check('...with the teams and their automatic shares', all(t in fp for t in ['Sales', 'Customer Service', 'Accounts', 'IT']) and 'name="om_teams[%d]"' % TEAMS['Sales'] in fp)

# ---------------------------------------------------------------- settings: validation, then on
check('validation: a minimum of 0 is refused, nothing saved', save_features({'office_minimum_enabled': '1', 'om_min': '0'}).get('features_error') == 'om_min'
      and php("echo (int)get_option('ews_feature_office_minimum');").strip() == '0' and opt('ews_office_minimum') is False)
check('validation: no status ticked is refused', save_features({'office_minimum_enabled': '1', 'om_min': '12'}, drop=('om_statuses[]',)).get('features_error') == 'om_statuses')
check('validation: a bad reminder time is refused', save_features({'office_minimum_enabled': '1', 'om_min': '12', 'om_reminder_time': '25:00'}).get('features_error') == 'om_time')
qs = save_features({'office_minimum_enabled': '1', 'om_min': '12'})
check('settings saved: on, 12, Office counts', qs.get('features_saved') == '1' and php("echo (int)get_option('ews_feature_office_minimum');").strip() == '1'
      and opt('ews_office_minimum')['min'] == 12 and opt('ews_office_minimum')['statuses'] == ['Office'], qs)
check('the change is in the Audit Log', q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='office_minimum_update'")[0]['n'] == '1')
fp = adm.req(FEAT)[1]
check('the settings page shows the automatic shares (Sales 4 of 12)', re.search(r'<td>Sales</td><td>12</td><td>4</td>', fp) is not None)

# ---------------------------------------------------------------- the Attendance page
pg = att()
c = counters(pg)
check('under each day: in the office / minimum', c == {DATES[0]: (14, 12), DATES[1]: (12, 12), DATES[2]: (9, 12), DATES[3]: (13, 12), DATES[4]: (10, 12)}, c)
check('the "Set… for the whole day" row has them too', len(re.findall(r'data-om-base=', pg)) == 10)
check('short days are red, the others green', pg.count('wfo-om-day is-short') >= 4 and 'wfo-om-day is-ok' in pg)
check('the warning lists only the days below the minimum', banner_days(pg) == [DATES[2], DATES[4]], banner_days(pg))
check('the warning says it is only a warning', 'This is only a warning' in pg)
thu = sheet(pg, DATES[4])
rows = team_rows(thu)
check('teams on Thursday: Sales share 4, 2 in the office, 2 short', rows.get('Sales') == (4, 2, '2 short'), rows)
check('...Accounts 2 / 1, 1 short; IT 2 / 3, above; Customer Service on share', rows.get('Accounts') == (2, 1, '1 short') and rows.get('IT') == (2, 3, '+1 above') and rows.get('Customer Service') == (3, 3, 'On share'))
check('...no team: share 1', rows.get('No team') == (1, 1, 'On share'))
check('...the shares add up to the minimum', sum(r[0] for r in rows.values()) == 12)
check('...the biggest shortfall first', list(rows)[:2] == ['Sales', 'Accounts'], list(rows))
check('...who could come in: Sales people working from home, not the ones on leave', 'Karim Fathy' in thu and 'Sara Nabil' not in thu and 'Ahmed Samir' not in thu)
check('...with the rule explained (40 employees)', 'all employees (40)' in thu)
check('the teams sheet opens from the warning', 'data-wfo-sheet="wfo-om-%s"' % DATES[4] in pg)

# ---------------------------------------------------------------- saving is never blocked, nothing extra shown
n = re.search(r'<form[^>]*id="ews-grid-form".*?name="_wpnonce" value="([^"]+)"', pg, re.S).group(1)
day_idx = 4
st, body, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews31_att_grid_save', '_wpnonce': n, 'week': SUN, 'attendance_client': 'desktop', '_wp_http_referer': ATT,
                                                   'att_changes_json': json.dumps([{'employee': IDS[0], 'day': day_idx, 'status': 'WFH', 'original': 'Office'}])})
loc = h.get('Location', '')
check('saving a change that makes Thursday shorter is not blocked', 'grid_saved' in loc or 'att_saved' in loc or 'saved' in loc, loc)
check('...and the day is saved', q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (IDS[0], DATES[4]))[0]['status'] == 'WFH')
after = adm.req(loc.replace('http://127.0.0.1:8080', ''))[1] if loc else att()
check('...nothing extra after saving (no popup about the minimum)', 'below the minimum' not in re.sub(r'<dialog.*?</dialog>', '', after, flags=re.S).replace('below the office minimum', ''))
check('...Thursday now 9 of 12', counters(att())[DATES[4]] == (9, 12))
php("$wpdb->update($p.'ews_schedule',['status'=>'Office'],['employee_id'=>%d,'work_date'=>'%s']);" % (IDS[0], DATES[4]))

# ---------------------------------------------------------------- weekday number, 0, holidays, statuses, fixed team numbers
save_features({'office_minimum_enabled': '1', 'om_min': '12', 'om_days[4]': '8', 'om_days[1]': '0'})
c = counters(att())
check('a weekday\'s own number: Thursday 8 → not short', c[DATES[4]] == (10, 8) and banner_days(att()) == [DATES[2]], c)
check('0 = no minimum that day (Monday)', DATES[1] not in c and 'No minimum' in att())
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>'%s','event_type'=>'general_leave','title'=>'Holiday','active'=>1]);" % DATES[2])
c = counters(att())
check('a company holiday is not checked (Tuesday)', DATES[2] not in c and banner_days(att()) == [], c)
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")
save_features({'office_minimum_enabled': '1', 'om_min': '12', 'om_days[4]': '', 'om_days[1]': '', 'om_statuses[]': ['Office', 'WFH']})
c = counters(att())
check('statuses: counting WFH too, every day is above', all(v[0] >= 12 for v in c.values()) and banner_days(att()) == [], c)
save_features({'office_minimum_enabled': '1', 'om_min': '12', 'om_statuses[]': ['Office'], 'om_teams[%d]' % TEAMS['Customer Service']: '5'})
rows = team_rows(sheet(att(), DATES[4]))
check('a fixed number for a team (Customer Service 5): the rest shared by the others', rows['Customer Service'] == (5, 3, '2 short') and sum(r[0] for r in rows.values()) == 12, rows)
save_features({'office_minimum_enabled': '1', 'om_min': '12', 'om_statuses[]': ['Office'], 'om_teams[%d]' % TEAMS['Customer Service']: ''})
check('...cleared again', opt('ews_office_minimum')['teams'] == [] or opt('ews_office_minimum')['teams'] == {})

# ---------------------------------------------------------------- approving leave
php("""$lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types ORDER BY id ASC LIMIT 1");
foreach([[%d,'Family errand'],[%d,'Dentist']] as [$e,$why])$wpdb->insert($p.'ews_leave_requests',['employee_id'=>$e,'leave_type'=>'Vacation','leave_type_id'=>$lt,'start_date'=>'%s','end_date'=>'%s','requested_days'=>1,'reason'=>$why,'status'=>'Pending','requested_by'=>0,'requested_at'=>current_time('mysql')]);""" % (IDS[0], IDS[2], DATES[4], DATES[4]))
lv = adm.req('/app/?ews_view=vacation')[1]
cards = {why: lv[lv.find(why) - 1500:lv.find(why) + 1500] for why in ['Family errand', 'Dentist']}
check('approving leave: the manager sees what it does to Thursday', 'Approving makes' in cards['Family errand'] and '9 of 12 in the office' in cards['Family errand'], cards['Family errand'][-900:])
check('...and the team: Sales would have 1 of their share of 4', 'Sales would have 1 of their share of 4' in lv)
check('...no note for someone working from home that day', lv.count('wfo-om-note') == 1)
check('...approving is not blocked (the buttons are there)', 'wfo-btn-approve' in lv)

# ---------------------------------------------------------------- weekly reminder
now = php("echo date('w',current_time('timestamp')).'|'.date('H:i',current_time('timestamp'));").strip().split('|')
php("update_option('ews_office_minimum',array_merge(get_option('ews_office_minimum'),['reminder'=>1,'reminder_day'=>%d,'reminder_time'=>'00:00']),false);" % int(now[0]))
# the reminder covers next week from today; put the short schedule there
php("""$from=date('Y-m-d',strtotime('+7 days',current_time('timestamp')));
$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'week_dates_configured'); $r->setAccessible(true); [$next]=$r->invoke($m,$from);
$src=['%s','%s','%s','%s','%s'];
foreach($next as $i=>$d){ if($d===$src[$i])continue; $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE work_date=%%s",$d)); $wpdb->query($wpdb->prepare("INSERT INTO {$p}ews_schedule (employee_id,work_date,status) SELECT employee_id,%%s,status FROM {$p}ews_schedule WHERE work_date=%%s",$d,$src[$i])); }""" % tuple(DATES))
php("do_action('ews_office_minimum_tick');")
notes = q("SELECT user_id,title,message FROM {p}ews_notifications WHERE type='office_minimum' OR entity IS NULL")
mine = [x for x in q("SELECT title,message FROM {p}ews_notifications") if 'office minimum' in x['title']]
check('weekly reminder: schedule managers are told next week\'s short days', len(mine) >= 1 and '2 days next week are below the office minimum' in mine[0]['title'], mine[:1])
check('...with each day and its count', ' of 12' in mine[0]['message'] if mine else False)
php("do_action('ews_office_minimum_tick');")
check('...once a week only', len([x for x in q("SELECT title FROM {p}ews_notifications") if 'office minimum' in x['title']]) == len(mine))
check('...an employee is not told', not any('office minimum' in x['title'] for x in q("SELECT title FROM {p}ews_notifications WHERE user_id=(SELECT ID FROM {p}users WHERE user_login='emp1')")))

# ---------------------------------------------------------------- off again
save_features(drop=('office_minimum_enabled',))
pg = att()
check('switched off: nothing on the Attendance page again', 'data-om-day' not in pg and 'data-om-banner' not in pg)
check('...nor on leave approvals', 'wfo-om-note' not in adm.req('/app/?ews_view=vacation')[1])
check('...the settings are kept for next time', opt('ews_office_minimum')['min'] == 12)
php("$wpdb->update($p.'ews_approval_workflows',['active'=>1],['workflow_key'=>'vacation']);")

print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

"""Office minimum by department (3.31.154, docs/tasks/office-minimum-departments.md, mockups docs/mockups/office-minimum-departments/).

Each department may have its own full Office minimum settings (minimum, days, statuses, team shares, managers' minimum, reminder);
a department without works with the company's, as before. Pins: with no department settings everything is as before; the
Features tabs (one per active department), saving a department's tab (the company's settings untouched, its own audit line),
validation, the company tab keeps the departments; the Attendance page per viewer (a department manager sees only their
department's numbers; the administrator sees the company and can pick a department, the grid unchanged); team sizes counted in the
department; a manager counted in their own department; the leave note; the reminder (each person one, their department's or the
company's); unticking goes back to the company's, byte for byte.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_office_minimum_departments.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))

SUN = php("echo date('Y-m-d',strtotime('next sunday',current_time('timestamp')));").strip()
DATES = [(datetime.date.fromisoformat(SUN) + datetime.timedelta(days=i)).isoformat() for i in range(5)]
# Sales: Emp One (emp1, the Sales schedule manager), Samy (manager of Cairo), Sara, Sohair, Selim; in the office [5,4,1,4,3].
# Ops: Omar (manager of Field), Ola (in Ops, manager of the Sales team Alex: counted in Ops), Osman; in the office [3,3,3,3,0].
# Nabil: no department, in the office every day. An archived department with no one.
# Samy works from home on Wednesday: Sales's managers' minimum (1) is short that day.
setup = json.loads(php("""
    foreach(['ews_schedule','ews_team_members','ews_teams','ews_leave_requests','ews_notifications','ews_audit_log','ews_company_calendar'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE code LIKE 'omd-%' OR name IN ('Sales','Ops','Gone')");
    delete_option('ews_office_minimum'); delete_option('ews_office_minimum_reminded'); delete_option('ews_office_minimum_reminded_depts'); update_option('ews_feature_office_minimum',0,false);
    update_option('ews_working_days',[0,1,2,3,4],false);
    $wpdb->update($p.'ews_approval_workflows',['active'=>0],['workflow_key'=>'vacation']);
    $was=array_map('intval',$wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE active=1"));
    $wpdb->query("UPDATE {$p}ews_employees SET active=0");
    $dep=function($n,$c,$a=1)use($wpdb,$p){$wpdb->insert($p.'ews_departments',['name'=>$n,'code'=>$c,'active'=>$a]);return (int)$wpdb->insert_id;};
    $ids=['sales'=>$dep('Sales','omd-sales'),'ops'=>$dep('Ops','omd-ops'),'gone'=>$dep('Gone','omd-gone',0),'was'=>$was];
    $emp1=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    $wpdb->update($p.'ews_employees',['active'=>1,'name'=>'Emp One','department_id'=>$ids['sales']],['id'=>$emp1]); $ids['emp1']=$emp1;
    $mk=function($k,$n,$d)use($wpdb,$p,&$ids){$wpdb->insert($p.'ews_employees',['name'=>$n,'domain_name'=>'omd'.$k,'email'=>"omd$k@example.com",'active'=>1,'attendance_enabled'=>1,'department_id'=>$d]);$ids[$k]=(int)$wpdb->insert_id;};
    foreach(['samy'=>'Samy','sara'=>'Sara','sohair'=>'Sohair','selim'=>'Selim'] as $k=>$n)$mk($k,$n,$ids['sales']);
    foreach(['omar'=>'Omar','ola'=>'Ola','osman'=>'Osman'] as $k=>$n)$mk($k,$n,$ids['ops']);
    $mk('nabil','Nabil',0);
    $u=username_exists('omdops')?:wp_create_user('omdops','omdopspass','omdops@example.com'); $ids['opsuser']=(int)$u;
    $wpdb->update($p.'ews_employees',['wp_user_id'=>$u],['id'=>$ids['osman']]);
    foreach(['emp1'=>get_user_by('login','emp1')->ID,'ops'=>$u] as $x)(new WP_User((int)$x))->add_cap('ews_manage_attendance');
    $team=function($n,$d,$m,$members)use($wpdb,$p,&$ids){$wpdb->insert($p.'ews_teams',['name'=>$n,'active'=>1,'department_id'=>$d,'manager_employee_id'=>$ids[$m]]);$t=(int)$wpdb->insert_id;$ids['team_'.$n]=$t;
        foreach($members as $k)$wpdb->insert($p.'ews_team_members',['team_id'=>$t,'employee_id'=>$ids[$k],'active'=>1]);};
    $team('Cairo',$ids['sales'],'samy',['samy','sara','sohair']);
    $team('Alex',$ids['sales'],'ola',['selim','emp1']);
    $team('Field',$ids['ops'],'omar',['omar','ola','osman']);
    $plan=['emp1'=>[1,1,0,1,1],'samy'=>[1,1,1,'WFH',1],'sara'=>[1,1,0,1,1],'sohair'=>[1,0,0,1,0],'selim'=>[1,1,0,0,0],
        'omar'=>[1,1,1,1,0],'ola'=>[1,1,1,1,0],'osman'=>[1,1,1,1,0],'nabil'=>[1,1,1,1,1]];
    foreach($plan as $k=>$days)foreach($days as $d=>$v)$wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>date('Y-m-d',strtotime('__SUN__'." +$d day")),'status'=>$v===1?'Office':($v===0?'WFH':$v)]);
    echo wp_json_encode($ids);
""".replace('__SUN__', SUN)).splitlines()[-1])
IDS = setup
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
ATT = '/app/?ews_view=attendance&week=' + SUN
FEAT = '/wp-admin/admin.php?page=ews31-features'
SALES, OPS = IDS['sales'], IDS['ops']


def norm(pg):
    return re.sub(r'[0-9a-f]{10}', 'N', pg)


def counters(pg):
    return {d: (int(n), int(m)) for d, n, m in re.findall(r'<th[^>]*>.*?data-om-day="([0-9-]+)" data-om-base="(\d+)" data-om-min="(\d+)"', pg, re.S)}


def mgr_counters(pg):
    return {d: (int(n), int(m)) for d, n, m in re.findall(r'<th[^>]*>.*?data-mom-day="([0-9-]+)" data-mom-base="(\d+)" data-mom-min="(\d+)"', pg, re.S)}


def form_fields(pg, action):
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


def save(over=None, drop=(), tab=0):
    page = adm.req(FEAT + ('&om_dept=%d' % tab if tab else ''))[1]
    fields = [(k, v) for k, v in form_fields(page, 'ews31_features_save') if k not in drop and k not in (over or {})]
    for k, v in (over or {}).items():
        for vv in (v if isinstance(v, list) else [v]):
            fields.append((k, vv))
    st, body, h = adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name).strip())


audits = lambda a: [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % a)]

# ---------------------------------------------------------------- the company's minimum only (as before)
qs = save({'office_minimum_enabled': '1', 'om_min': '5'})
check('Office minimum on (5 people), no department settings', qs.get('features_saved') == '1' and 'depts' not in opt('ews_office_minimum'), qs)
php("$wpdb->query(\"DELETE FROM {$p}ews_audit_log\");")
base_adm, base_emp, base_leave = adm.req(ATT)[1], emp.req(ATT)[1], adm.req('/app/?ews_view=vacation')[1]
check('no department settings: no bar, no department attributes', 'wfo-omd-bar' not in base_adm and 'wfo-omd-bar' not in base_emp and 'data-om-people' not in base_adm)
c = counters(base_adm)
check('the company counts everyone: 9,8,5,7,4 of 5', c == {DATES[0]: (9, 5), DATES[1]: (8, 5), DATES[2]: (5, 5), DATES[3]: (7, 5), DATES[4]: (4, 5)}, c)

# ---------------------------------------------------------------- Feature Configuration: the tabs
fp = adm.req(FEAT)[1]
tabs = re.findall(r'<a href="[^"]*om_dept=(\d+)[^"]*"[^>]*>([^<]+)<small>([^<]+)</small>', fp)
check('a tab per active department (not the archived one), "as the company"', [(int(i), n) for i, n, _ in tabs] == [(OPS, 'Ops'), (SALES, 'Sales')] and all(s == 'as the company' for _, _, s in tabs), tabs)
fs = adm.req(FEAT + '&om_dept=%d' % SALES)[1]
check('the Sales tab: "Sales has its own settings", off; the form starts from the company\'s', 'name="om_dept_own"' in fs and 'Sales has its own settings' in fs and re.search(r'name="om_dept_own" value="1"\s+checked', fs) is None
      and 'name="om_min" class="small-text" value="5"' in fs)
check('...with Sales\'s teams (Alex, Cairo) and sizes counted in Sales; its managers (Samy; Ola is in Ops)',
      re.search(r'<td>Alex</td><td>2</td>', fs) is not None and re.search(r'<td>Cairo</td><td>3</td>', fs) is not None and 'Field' not in fs.split('wfo-om-share')[1][:2000]
      and re.search(r'The team managers \(1\): Samy<', fs) is not None, re.findall(r'<td>(\w+)</td><td>(\d+)</td>', fs))

# ---------------------------------------------------------------- saving a department's tab
qs = save({'om_dept_own': '1', 'om_min': '0'}, tab=SALES)
check('validation on a department tab: refused, back on that tab, nothing saved', qs.get('features_error') == 'om_min' and qs.get('om_dept') == str(SALES) and 'depts' not in opt('ews_office_minimum'), qs)
company_before = {k: v for k, v in opt('ews_office_minimum').items()}
qs = save({'om_dept_own': '1', 'om_min': '3', 'om_statuses[]': ['Office'], 'om_mgr': '1', 'om_mgr_min': '1', 'om_teams[%d]' % IDS['team_Cairo']: '2'}, tab=SALES)
o = opt('ews_office_minimum')
d = (o.get('depts') or {}).get(str(SALES), {})
check('Sales has its own settings: stored under depts, the company\'s untouched', qs.get('features_saved') == '1' and qs.get('om_dept') == str(SALES)
      and d.get('min') == 3 and d.get('mgr') == 1 and str(IDS['team_Cairo']) in (d.get('teams') or {}) and {k: v for k, v in o.items() if k != 'depts'} == company_before, (qs, o))
check('...audited as the department\'s, not the company\'s', len(audits('office_minimum_department_update')) == 1 and 'own=on; min=3' in audits('office_minimum_department_update')[0] and audits('office_minimum_update') == [],
      (audits('office_minimum_department_update'), audits('office_minimum_update')))
qs = save({'om_min': '5'})
check('a company save keeps the departments', 'depts' in opt('ews_office_minimum') and opt('ews_office_minimum')['depts'] == o['depts'])
fp = adm.req(FEAT)[1]
check('...the Sales tab now says "own settings"', re.search(r'om_dept=%d[^"]*"[^>]*>Sales<small>own settings</small>' % SALES, fp) is not None)
save(drop=('om_dept_present', 'om_dept', 'om_dept_own'), tab=0)
check('an older form (no department fields) keeps them too', opt('ews_office_minimum').get('depts') == o['depts'])

# ---------------------------------------------------------------- the Attendance page per viewer
pg = emp.req(ATT)[1]
c, m = counters(pg), mgr_counters(pg)
check('the Sales manager: Sales\'s people, 5,4,1,3,3 of 3', c == {DATES[0]: (5, 3), DATES[1]: (4, 3), DATES[2]: (1, 3), DATES[3]: (3, 3), DATES[4]: (3, 3)}, c)
check('...Sales\'s managers (Samy): 1,1,1,0,1 of 1', m == {DATES[0]: (1, 1), DATES[1]: (1, 1), DATES[2]: (1, 1), DATES[3]: (0, 1), DATES[4]: (1, 1)}, m)
check('...the bar says whose minimum it is, no choice', 'wfo-omd-bar' in pg and 'Sales department' in pg and 'data-omd-pick' not in pg)
check('...the card is Sales\'s: Tuesday people, Wednesday managers', 'Sales: 2 days below its minimum' in pg and 'id="wfo-om-%s"' % DATES[2] in pg and 'id="wfo-mom-%s"' % DATES[3] in pg
      and 'id="wfo-om-%s"' % DATES[4] not in pg, re.findall(r'<h3>[^<]*minimum[^<]*</h3>', pg))
tue = re.search(r'<dialog[^>]*id="wfo-om-%s".*?</dialog>' % DATES[2], pg, re.S).group(0)
check('...the teams sheet: Sales\'s teams with Cairo\'s fixed 2, ÷ the department\'s employees (5)', 'Cairo' in tue and 'Alex' in tue and 'Field' not in tue and "the department's employees (5)" in html.unescape(tue))
check('...the live counter counts only Sales\'s cells', sorted(json.loads(html.unescape(re.search(r'data-om-people="([^"]*)"', pg).group(1)))) == sorted([IDS[k] for k in ('emp1', 'samy', 'sara', 'sohair', 'selim')]))
pg = adm.req(ATT)[1]
check('the administrator: the company\'s numbers as before, and a choice', counters(pg) == counters(base_adm) and 'data-omd-pick' in pg and 'The whole company' in pg and 'data-om-people' not in pg)
opts = re.findall(r'<option value="(\d+)"[^>]*>([^<]+)</option>', re.search(r'data-omd-pick>(.*?)</select>', pg, re.S).group(1))
check('...the choice lists the company and the departments with their own settings', opts == [('0', 'The whole company'), (str(SALES), 'Sales')], opts)
pg = adm.req(ATT + '&om_dept=%d' % SALES)[1]
check('...picking Sales: Sales\'s numbers, the whole grid', counters(pg) == {DATES[0]: (5, 3), DATES[1]: (4, 3), DATES[2]: (1, 3), DATES[3]: (3, 3), DATES[4]: (3, 3)} and 'Nabil' in pg and 'Osman' in pg, counters(pg))
check('...picking Ops (no settings of its own): the company\'s', counters(adm.req(ATT + '&om_dept=%d' % OPS)[1]) == counters(base_adm))
check('...a department manager cannot pick another department', counters(emp.req(ATT + '&om_dept=0')[1]) == {DATES[0]: (5, 3), DATES[1]: (4, 3), DATES[2]: (1, 3), DATES[3]: (3, 3), DATES[4]: (3, 3)})

# ---------------------------------------------------------------- leave notes
php("""$lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types ORDER BY id ASC LIMIT 1");
foreach([[%d,'Sales errand'],[%d,'Nabil errand']] as [$e,$why])$wpdb->insert($p.'ews_leave_requests',['employee_id'=>$e,'leave_type'=>'Vacation','leave_type_id'=>$lt,'start_date'=>'%s','end_date'=>'%s','requested_days'=>1,'reason'=>$why,'status'=>'Pending','requested_by'=>0,'requested_at'=>current_time('mysql')]);"""
    % (IDS['sara'], IDS['nabil'], DATES[4], DATES[4]))
lv = adm.req('/app/?ews_view=vacation')[1]
card = lambda why: lv[lv.find(why) - 200:lv.find(why) + 2200]
check('a Sales employee\'s leave: Sales\'s note only (2 of 3), labelled', '<span class="wfo-omd-for">Sales</span>' in card('Sales errand') and '2 of 3 in the office' in card('Sales errand'), card('Sales errand')[-1200:])
check('an employee without a department: the company\'s note (3 of 5), no label', '3 of 5 in the office' in card('Nabil errand') and 'wfo-omd-for' not in card('Nabil errand'))
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_requests\");")

# ---------------------------------------------------------------- the weekly reminder: each person one
now = php("echo date('w',current_time('timestamp'));").strip()
php("""$o=get_option('ews_office_minimum'); $o=array_merge($o,['reminder'=>1,'reminder_day'=>%d,'reminder_time'=>'00:00']); $o['depts'][%d]=array_merge($o['depts'][%d],['reminder'=>1,'reminder_day'=>%d,'reminder_time'=>'00:00']); update_option('ews_office_minimum',$o,false);
$from=date('Y-m-d',strtotime('+7 days',current_time('timestamp')));
$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'week_dates_configured'); $r->setAccessible(true); [$next]=$r->invoke($m,$from);
$src=['%s','%s','%s','%s','%s'];
foreach($next as $i=>$d){ if($d===$src[$i])continue; $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE work_date=%%s",$d)); $wpdb->query($wpdb->prepare("INSERT INTO {$p}ews_schedule (employee_id,work_date,status) SELECT employee_id,%%s,status FROM {$p}ews_schedule WHERE work_date=%%s",$d,$src[$i])); }
$wpdb->query("DELETE FROM {$p}ews_notifications");""" % ((int(now), SALES, SALES, int(now)) + tuple(DATES)))
php("do_action('ews_office_minimum_tick');")
notes = q("SELECT u.user_login,n.title FROM {p}ews_notifications n JOIN {p}users u ON u.ID=n.user_id WHERE n.type='office_minimum' ORDER BY u.user_login")
by = {}
for n in notes:
    by.setdefault(n['user_login'], []).append(n['title'])
check('the Sales manager gets Sales\'s reminder only', by.get('emp1') and len(by['emp1']) == 1 and by['emp1'][0].startswith('Sales: '), by)
check('the administrator and the Ops manager get the company\'s, once each', by.get('admin') and len(by['admin']) == 1 and not by['admin'][0].startswith('Sales')
      and by.get('omdops') and len(by['omdops']) == 1 and not by['omdops'][0].startswith('Sales'), by)
php("do_action('ews_office_minimum_tick');")
check('...once a week, per department', len(q("SELECT id FROM {p}ews_notifications WHERE type='office_minimum'")) == len(notes))

# ---------------------------------------------------------------- back to the company's settings
php("$o=get_option('ews_office_minimum'); unset($o['reminder'],$o['reminder_day'],$o['reminder_time']); update_option('ews_office_minimum',$o,false);")
qs = save({}, drop=('om_dept_own',), tab=SALES)
o = opt('ews_office_minimum')
check('unticked: Sales goes back to the company\'s, nothing more is stored', qs.get('features_saved') == '1' and 'depts' not in o, o)
check('...audited "own=off"', audits('office_minimum_department_update')[-1] == 'own=off')
php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\"); $wpdb->query(\"DELETE FROM {$p}ews_audit_log\");")
check('...the Sales manager\'s page is byte for byte as before', norm(emp.req(ATT)[1]) == norm(base_emp))
check('...and the administrator\'s', norm(adm.req(ATT)[1]) == norm(base_adm))
save(drop=('office_minimum_enabled',))
check('Office minimum off: nothing shows', 'data-om-' not in emp.req(ATT)[1] and 'wfo-omd' not in adm.req(ATT)[1])

php("""$wpdb->update($p.'ews_approval_workflows',['active'=>1],['workflow_key'=>'vacation']); foreach(%s as $id) $wpdb->update($p.'ews_employees',['active'=>1],['id'=>$id]);
foreach(['emp1','omdops'] as $l){$u=get_user_by('login',$l); if($u)(new WP_User($u->ID))->remove_cap('ews_manage_attendance');}
$wpdb->update($p.'ews_employees',['department_id'=>0],['id'=>%d]);""" % (json.dumps(IDS['was']), IDS['emp1']))

print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

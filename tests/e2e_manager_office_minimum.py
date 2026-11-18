"""Manager office minimum (3.31.153, docs/tasks/manager-office-minimum.md, mockups docs/mockups/manager-office-minimum/).

A minimum number of team managers in the office every working day, next to the Office minimum (a sub-section of it, only
with it on). Pins: off (the default) the Office minimum pages are byte for byte as before; the settings (stored only when
used, validated, kept by an older form); the managers' counter under each day; one warning card for days short of people,
of managers or both; the Managers sheet (each manager's plan, who could come in); a manager of two teams counts once; the
manager of an inactive team and a manager without attendance tracking are not counted; a weekday's own number, 0 = none;
approving a manager's leave; the weekly reminder.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_manager_office_minimum.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))

SUN = php("echo date('Y-m-d',strtotime('next sunday',current_time('timestamp')));").strip()
DATES = [(datetime.date.fromisoformat(SUN) + datetime.timedelta(days=i)).isoformat() for i in range(5)]
# Managers' plans Sun..Thu. Adel: Sales; Hesham: Customer Service; Ahmed: Accounts and IT (counts once); Ziad: the manager of an
# inactive team (not a manager); Uma: manager of Ops, attendance off (not counted). Five more people in the office [3,4,1,3,2].
# Managers in the office: 2,1,2,2,1 (minimum 2: Monday and Thursday short); people: 6,6,4,6,4 (minimum 5: Tuesday and Thursday).
setup = json.loads(php("""
    foreach(['ews_schedule','ews_team_members','ews_teams','ews_leave_requests','ews_notifications','ews_audit_log','ews_company_calendar'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    delete_option('ews_office_minimum'); delete_option('ews_office_minimum_reminded'); update_option('ews_feature_office_minimum',0,false);
    update_option('ews_working_days',[0,1,2,3,4],false);
    $wpdb->update($p.'ews_approval_workflows',['active'=>0],['workflow_key'=>'vacation']);
    $was=array_map('intval',$wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE active=1"));
    $wpdb->query("UPDATE {$p}ews_employees SET active=0");
    $plans=['adel'=>['Office','Vacation','Office','Office','WFH'],'hesham'=>['Office','WFH','Office','WFH','Office'],'ahmed'=>['WFH','Office','WFH','Office','Business Trip'],
        'ziad'=>array_fill(0,5,'Office'),'uma'=>array_fill(0,5,'Office')];
    $names=['adel'=>'Adel Hamdy','hesham'=>'Hesham Fathy','ahmed'=>'Ahmed Samir','ziad'=>'Ziad Gone','uma'=>'Uma Untracked'];
    $ids=[];
    foreach($names as $k=>$n){$wpdb->insert($p.'ews_employees',['name'=>$n,'domain_name'=>'mom'.$k,'email'=>"mom$k@example.com",'active'=>1,'attendance_enabled'=>$k==='uma'?0:1]);$ids[$k]=(int)$wpdb->insert_id;}
    $staff=[3,4,1,3,2];
    for($i=0;$i<5;$i++){$wpdb->insert($p.'ews_employees',['name'=>'Staff '.$i,'domain_name'=>'momstaff'.$i,'email'=>"momstaff$i@example.com",'active'=>1,'attendance_enabled'=>1]);$ids['staff'.$i]=(int)$wpdb->insert_id;
        $plans['staff'.$i]=[];for($d=0;$d<5;$d++)$plans['staff'.$i][]=$i<$staff[$d]?'Office':'WFH';}
    $teams=['Sales'=>'adel','Customer Service'=>'hesham','Accounts'=>'ahmed','IT'=>'ahmed','Ops'=>'uma'];
    foreach($teams as $t=>$m){$wpdb->insert($p.'ews_teams',['name'=>$t,'active'=>1,'manager_employee_id'=>$ids[$m]]);$ids['team_'.$t]=(int)$wpdb->insert_id;
        $wpdb->insert($p.'ews_team_members',['team_id'=>$ids['team_'.$t],'employee_id'=>$ids[$m],'active'=>1]);}
    $wpdb->insert($p.'ews_teams',['name'=>'Gone','active'=>0,'manager_employee_id'=>$ids['ziad']]);
    for($i=0;$i<5;$i++)$wpdb->insert($p.'ews_team_members',['team_id'=>$ids['team_Sales'],'employee_id'=>$ids['staff'.$i],'active'=>1]);
    foreach($plans as $k=>$days)foreach($days as $d=>$st)$wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>date('Y-m-d',strtotime('__SUN__'." +$d day")),'status'=>$st]);
    $ids['was']=$was;
    echo wp_json_encode($ids);
""".replace('__SUN__', SUN)).splitlines()[-1])
IDS = setup
adm = Session('admin', 'admin')
ATT = '/app/?ews_view=attendance&week=' + SUN
FEAT = '/wp-admin/admin.php?page=ews31-features'


def att():
    return adm.req(ATT)[1]


def norm(pg):
    return re.sub(r'(name="_wpnonce" value|data-nonce|_wpnonce=)"?[0-9a-f]{10}', r'\1', pg)


def mgr_counters(pg):
    """date -> (managers in the office, minimum), from the table head."""
    return {d: (int(n), int(m)) for d, n, m in re.findall(r'<th[^>]*>.*?data-mom-day="([0-9-]+)" data-mom-base="(\d+)" data-mom-min="(\d+)"', pg, re.S)}


def banner(pg):
    """date -> (people short?, managers short?, Teams button?, Managers button?)"""
    m = re.search(r'data-om-banner.*?</section>', pg, re.S)
    out = {}
    for li in re.findall(r'<li>(.*?)</li>', m.group(0), re.S) if m else []:
        d = re.search(r'wfo-(?:om|mom)-(\d{4}-\d\d-\d\d)', li)
        if d:
            out[d.group(1)] = ('in the office' in li, 'managers</span>' in li, 'data-wfo-sheet="wfo-om-' in li, 'data-wfo-sheet="wfo-mom-' in li)
    return out


def mgr_sheet(pg, d):
    m = re.search(r'<dialog[^>]*id="wfo-mom-%s".*?</dialog>' % d, pg, re.S)
    return m.group(0) if m else ''


def sheet_rows(sh):
    return [(html.unescape(n), html.unescape(st), 'Could come in' in rest) for n, st, rest in re.findall(r'<b>([^<]+)</b><small>.*?<span class="wfo-mom-plan[^"]*">.*?</svg>([^<]*)</span>(.*?)</li>', sh, re.S)]


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


def save_features(over=None, drop=()):
    fields = [(k, v) for k, v in form_fields(adm.req(FEAT)[1], 'ews31_features_save') if k not in drop and k not in (over or {})]
    for k, v in (over or {}).items():
        for vv in (v if isinstance(v, list) else [v]):
            fields.append((k, vv))
    st, body, h = adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name).strip())


audits = lambda: [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='office_minimum_update' ORDER BY id")]

# ---------------------------------------------------------------- the Office minimum on, managers off (the default)
qs = save_features({'office_minimum_enabled': '1', 'om_min': '5'})
check('Office minimum on (5 people)', qs.get('features_saved') == '1', qs)
check('saving with the managers\' part untouched stores no manager keys', not any(k.startswith('mgr') for k in opt('ews_office_minimum')), opt('ews_office_minimum'))
check('...and the audit line is as before (no managers part)', audits() and 'managers=' not in audits()[-1], audits())
base_att, base_leave = att(), None
check('managers off: no managers\' counter, no managers list, the old card', 'data-mom-' not in base_att and 'data-om-managers' not in base_att and 'days below the office minimum' in base_att, re.findall(r'<h3>[^<]*minimum[^<]*</h3>', base_att))
fp = adm.req(FEAT)[1]
check('Features: the managers\' part, unticked, with the team managers (Ahmed once; not Ziad nor Uma)', 'name="om_mgr" value="1" >' in fp or ('name="om_mgr"' in fp and 'name="om_mgr" value="1"  checked' not in fp))
who = re.search(r'The team managers \((\d+)\): ([^<]+)<', fp)
check('...the list: Adel, Ahmed, Hesham (3)', who is not None and who.group(1) == '3' and [n.strip() for n in html.unescape(who.group(2)).split(',')] == ['Adel Hamdy', 'Ahmed Samir', 'Hesham Fathy'], who.group(0) if who else fp[fp.find('Who the managers are'):][:600])

# ---------------------------------------------------------------- settings
check('validation: managers on with a minimum of 0 is refused, nothing saved', save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '0'}).get('features_error') == 'om_mgr_min'
      and 'mgr' not in opt('ews_office_minimum'))
check('validation: a weekday\'s number below 0 is refused', save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '2', 'om_mgr_days[2]': '-1'}).get('features_error') == 'om_mgr_day')
qs = save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '2'})
o = opt('ews_office_minimum')
check('managers on, 2: stored', qs.get('features_saved') == '1' and o.get('mgr') == 1 and 'mgr_min' not in o, o)
check('...audited with the managers part', 'managers=on min=2' in audits()[-1], audits()[-1:])

# ---------------------------------------------------------------- the Attendance page
pg = att()
c = mgr_counters(pg)
check('the managers\' counter under each day: 2,1,2,2,1 of 2', c == {DATES[0]: (2, 2), DATES[1]: (1, 2), DATES[2]: (2, 2), DATES[3]: (2, 2), DATES[4]: (1, 2)}, c)
check('...in the "Set…" row too (10 in all), next to the people\'s', len(re.findall(r'data-mom-base=', pg)) == 10 and len(re.findall(r'data-om-base=', pg)) == 10)
check('...red when short', pg.count('wfo-mom-day is-short') >= 4)
ids = json.loads(html.unescape(re.search(r'data-om-managers="([^"]*)"', pg).group(1)))
check('the grid knows the counted managers (for the live counter)', sorted(ids) == sorted([IDS['adel'], IDS['hesham'], IDS['ahmed']]), ids)
b = banner(pg)
check('one card: Monday managers only, Tuesday people only, Thursday both', b == {DATES[1]: (False, True, False, True), DATES[2]: (True, False, True, False), DATES[4]: (True, True, True, True)}, b)
check('...titled for both minimums', '3 days below a minimum' in pg)
thu = mgr_sheet(pg, DATES[4])
rows = sheet_rows(thu)
check('Managers on Thursday: Hesham in the office first, Adel working from home (could come in), Ahmed away', rows == [('Hesham Fathy', 'Office', False), ('Adel Hamdy', 'WFH', True), ('Ahmed Samir', 'Business Trip', False)], rows)
check('...Ahmed is the manager of Accounts and IT, once', thu.count('Ahmed Samir') == 1 and 'Accounts, IT manager' in thu)
check('...1 manager below the minimum', '1 manager below the minimum' in thu)
check('no Managers sheet for a day with enough managers', mgr_sheet(pg, DATES[2]) == '' and 'id="wfo-om-%s"' % DATES[2] in pg)
check('the people\'s teams sheets are still there (Tuesday, Thursday)', 'id="wfo-om-%s"' % DATES[2] in pg and 'id="wfo-om-%s"' % DATES[4] in pg)

# ---------------------------------------------------------------- weekday number, 0 = none
save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '2', 'om_mgr_days[1]': '0', 'om_mgr_days[4]': '1'})
pg = att()
c = mgr_counters(pg)
check('a weekday\'s own number: Thursday 1 → not short; Monday 0 → no managers\' check', c.get(DATES[4]) == (1, 1) and DATES[1] not in c and banner(pg) == {DATES[2]: (True, False, True, False), DATES[4]: (True, False, True, False)}, (c, banner(pg)))
save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '2', 'om_mgr_days[1]': '', 'om_mgr_days[4]': ''})
check('...cleared again', 'mgr_days' not in opt('ews_office_minimum'))
save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '4'})
check('a minimum above the number of managers: saved, with a warning on the page', opt('ews_office_minimum').get('mgr_min') == 4 and 'Only 3 team managers are counted' in adm.req(FEAT)[1])
save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '2'})
# an older form (no om_mgr_present) keeps the managers' part
save_features({'office_minimum_enabled': '1', 'om_min': '5'}, drop=('om_mgr_present', 'om_mgr', 'om_mgr_min'))
check('an older form keeps the managers\' part', opt('ews_office_minimum').get('mgr') == 1)

# ---------------------------------------------------------------- approving leave
php("""$lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types ORDER BY id ASC LIMIT 1");
foreach([[%d,'Manager errand'],[%d,'Staff dentist']] as [$e,$why])$wpdb->insert($p.'ews_leave_requests',['employee_id'=>$e,'leave_type'=>'Vacation','leave_type_id'=>$lt,'start_date'=>'%s','end_date'=>'%s','requested_days'=>1,'reason'=>$why,'status'=>'Pending','requested_by'=>0,'requested_at'=>current_time('mysql')]);""" % (IDS['hesham'], IDS['staff0'], DATES[4], DATES[4]))
lv = adm.req('/app/?ews_view=vacation')[1]
card = lambda why: lv[lv.find(why) - 200:lv.find(why) + 2500]
check('approving a manager\'s leave: Thursday 0 of 2 managers', 'wfo-om-note is-mgr' in card('Manager errand') and '0 of 2 managers in the office' in card('Manager errand'), card('Manager errand')[-1500:])
check('...who could come in: Adel (working from home)', 'Could come in (working from home): Adel Hamdy.' in card('Manager errand'))
check('...and the people\'s note too (3 of 5)', '3 of 5 in the office' in card('Manager errand'))
check('a staff member\'s leave has no managers\' note', lv.count('wfo-om-note is-mgr') == 1)
check('...approving is not blocked', 'wfo-btn-approve' in lv)
php("$wpdb->query(\"DELETE FROM {$p}ews_leave_requests\");")

# ---------------------------------------------------------------- weekly reminder
now = php("echo date('w',current_time('timestamp'));").strip()
php("update_option('ews_office_minimum',array_merge(get_option('ews_office_minimum'),['reminder'=>1,'reminder_day'=>%d,'reminder_time'=>'00:00']),false);" % int(now))
php("""$from=date('Y-m-d',strtotime('+7 days',current_time('timestamp')));
$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'week_dates_configured'); $r->setAccessible(true); [$next]=$r->invoke($m,$from);
$src=['%s','%s','%s','%s','%s'];
foreach($next as $i=>$d){ if($d===$src[$i])continue; $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE work_date=%%s",$d)); $wpdb->query($wpdb->prepare("INSERT INTO {$p}ews_schedule (employee_id,work_date,status) SELECT employee_id,%%s,status FROM {$p}ews_schedule WHERE work_date=%%s",$d,$src[$i])); }""" % tuple(DATES))
php("delete_option('ews_office_minimum_reminded'); do_action('ews_office_minimum_tick');")
mine = [x for x in q("SELECT title,message FROM {p}ews_notifications") if 'office minimum' in x['title']]
check('the reminder counts the days short of managers too (3 days)', mine and '3 days next week are below the office minimum' in mine[0]['title'], mine[:1])
check('...Monday: managers only; Thursday: both', mine and re.search(r'Mon \d+ \w+ 1 of 2 managers', mine[0]['message']) is not None and re.search(r'Thu \d+ \w+ 4 of 5, 1 of 2 managers', mine[0]['message']) is not None, mine[0]['message'] if mine else '')

# ---------------------------------------------------------------- off again: as before
save_features({'office_minimum_enabled': '1', 'om_min': '5'}, drop=('om_mgr',))
o = opt('ews_office_minimum')
check('managers switched off: the managers keys are gone again', not any(k.startswith('mgr') for k in o) or o.get('mgr') in (None, 0), o)
php("update_option('ews_office_minimum',array_diff_key(get_option('ews_office_minimum'),['reminder'=>1,'reminder_day'=>1,'reminder_time'=>1]),false);")
php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\");")   # the reminder's bell is not this page's
now_att = att()
import difflib
check('...the Attendance page is byte for byte as with the managers never on', norm(now_att) == norm(base_att), ''.join(list(difflib.unified_diff(norm(base_att).splitlines(1), norm(now_att).splitlines(1), n=0))[:12]))
save_features({'office_minimum_enabled': '1', 'om_min': '5', 'om_mgr': '1', 'om_mgr_min': '2'})
save_features(drop=('office_minimum_enabled',))
pg = att()
check('Office minimum off with managers on: nothing shows', 'data-mom-' not in pg and 'data-om-' not in pg)
php("$wpdb->update($p.'ews_approval_workflows',['active'=>1],['workflow_key'=>'vacation']); foreach(%s as $id) $wpdb->update($p.'ews_employees',['active'=>1],['id'=>$id]);" % json.dumps(IDS['was']))

print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

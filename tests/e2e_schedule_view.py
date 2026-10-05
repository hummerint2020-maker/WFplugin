"""Behaviour tests for the employee app's Schedule page (team schedule + swap panel).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_schedule_view.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, ids, php, q, results, today, wp, HERE  # noqa: E402

PAGE = '/app/?ews_view=schedule'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_schedule','ews_company_calendar','ews_teams','ews_team_members','ews_departments','ews_schedule_swaps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $dep=$wpdb->insert_id;
        $mk=function($name)use($wpdb,$p,$dep){$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>strtolower(str_replace(' ','',$name)),'email'=>strtolower(str_replace(' ','',$name)).'@example.com','active'=>1,'department_id'=>$dep]);return $wpdb->insert_id;};
        $wpdb->update($p.'ews_employees',['name'=>'Me Myself','department_id'=>$dep],['id'=>$eid]);
        $ids=['me'=>$eid,'boss'=>$mk('Zed Boss'),'ann'=>$mk('Ann Alpha'),'loner'=>$mk('Aaron Loner')];
        $wpdb->insert($p.'ews_teams',['name'=>'Blue Team','manager_employee_id'=>$ids['boss'],'department_id'=>$dep,'active'=>1]); $t=$wpdb->insert_id;
        foreach(['me','boss','ann'] as $k) $wpdb->insert($p.'ews_team_members',['team_id'=>$t,'employee_id'=>$ids[$k],'active'=>1]);
        echo wp_json_encode($ids);
    """)
    return json.loads(out.splitlines()[-1])


def set_day(eid, date, status):
    php("$wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'%s']);" % (eid, date, eid, date, status))


people = seed()
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req(PAGE)
check('the Schedule page opens', st == 200 and 'Team Schedule' in page, st)
heads = re.findall(r'<th class="(today)?">\s*([A-Z][a-z]{2})\s*<small>([^<]+)</small>', page)
check('one column per working day, today marked', len(heads) == 7 and sum(1 for h in heads if h[0] == 'today') == 1, heads)
order = re.findall(r'<div class="ews-person">([^<]+)<', page)
check('team order: manager first, then you, then the team, then people without a team', order == ['Zed Boss', 'Me Myself', 'Ann Alpha', 'Aaron Loner'], order)
check('"You" and "Manager" badges', 'ews-me-badge' in page and 'ews-manager-badge' in page)
check('the week label says This week only for this week', 'This week' in page)
nxt = re.search(r'href="([^"]*week=[^"]*)" aria-label="Next week"', page)
st, page2, _ = emp.req(nxt.group(1).replace('&#038;', '&').replace('&amp;', '&').split('127.0.0.1:8080')[-1] if nxt else PAGE)
check('...and Next week after moving on', 'Next week</span>' in page2 or '>Next week<' in page2, re.findall(r'<span>([^<]*week[^<]*)</span>', page2))

# A company holiday shows as General Leave for everyone.
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>'%s','title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);" % today.isoformat())
st, page, _ = emp.req(PAGE)
check('a company holiday shows as General Leave', page.count('General Leave') >= 5)
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")

# Swap panel: only my Office/WFH days from today on can be offered.
for i, status in [(-1, 'Office'), (0, 'WFH'), (1, 'Office'), (2, 'Vacation')]:
    set_day(people['me'], d(i), status)
st, page, _ = emp.req(PAGE + '&week=' + today.isoformat())
days = re.findall(r'<select name="work_date"[^>]*>(.*?)</select>', page, re.S)
offered = re.findall(r'<option value="([\d-]+)"', days[0]) if days else []
check('the swap form offers my Office/WFH days from today on', d(-1) not in offered and d(2) not in offered and d(0) in offered, offered)
colleagues = re.findall(r'<select name="target_employee_id"[^>]*>(.*?)</select>', page, re.S)
check('the swap form lists colleagues but not me', colleagues and 'Me Myself' not in colleagues[0] and 'Ann Alpha' in colleagues[0])
n = re.search(r'name="_wpnonce" value="([^"]+)"(?:(?!<form).)*?name="action" value="ews_swap_create"', page, re.S)
set_day(people['ann'], d(-1), 'WFH')
_, qs, _ = emp.post('ews_swap_create', _wpnonce=n.group(1) if n else 'x', target_employee_id=people['ann'], work_date=d(-1))
check('a swap for a past day is refused', qs.get('swap_error') == 'past_date' and not q("SELECT id FROM {p}ews_schedule_swaps"), qs)
st, page, _ = emp.req(PAGE + '&swap_error=past_date')
check('...with a clear message', 'already passed' in page)
# Accepting a request whose day has passed is refused too (and the schedules stay as they were).
set_day(people['me'], d(-1), 'Office')
sw = php("$wpdb->insert($p.'ews_schedule_swaps',['requester_employee_id'=>%d,'target_employee_id'=>%d,'work_date'=>'%s','requester_status'=>'WFH','target_status'=>'Office','status'=>'Pending','requested_by'=>1,'created_at'=>current_time('mysql')]); echo $wpdb->insert_id;" % (people['ann'], people['me'], d(-1))).splitlines()[-1]
st, page, _ = emp.req(PAGE + '&week=' + d(-1))
n = re.search(r'name="_wpnonce" value="([^"]+)"(?:(?!<form).)*?name="swap_id" value="%s"' % sw, page, re.S)
_, qs, _ = emp.post('ews_swap_respond', _wpnonce=n.group(1) if n else 'x', swap_id=sw, decision='accept')
check('accepting a swap for a past day is refused', qs.get('swap_error') == 'past_date' and q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (people['me'], d(-1))) == [{'status': 'Office'}], qs)
st, page, _ = emp.req(PAGE)
mine = re.search(r'<ol class="wfo-myweek-days">(.*?)</ol>', page, re.S)
check('My week shows my seven days, each with an icon and a word', mine and len(re.findall(r'<li class="is-[a-z]+', mine.group(1))) == 7 and mine.group(1).count('wfo-myweek-word') == 7)
check('...with today marked', mine and mine.group(1).count('aria-current="date"') == 1)
check('every cell is a status chip with an icon', page.count('<span class="wfo-chip is-') >= 4 * 7 and 'class="ews-status' not in page)
check('each person has an avatar', page.count('class="wfo-sched-avatar') >= 4)
content = page.split('<div class="wfo-content">', 1)[-1]
check('no emoji on the Schedule page', not re.search('[\U0001F300-\U0001FAFF\u2600-\u27BF]', content), re.findall('[\U0001F300-\U0001FAFF\u2600-\u27BF]', content))
check('the page stylesheet is loaded in the head', 'app-schedule.css' in page.split('</head>')[0])
check('the page script is a file (no inline onclick)', 'schedule.js' in page and 'onclick="(function(){document.title' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

"""Long lists (3.31.71): above 60 people, Team Schedule and Attendance search, filter by team and page
on the server (Ui\\ListPage); the wp-admin Sign In / Out Report pages by 100 records with a search.
Small lists keep the in-page search (covered by e2e_schedule_view / e2e_attendance_grid).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_big_lists.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin) at http://127.0.0.1:8080. Adds and removes its own rows.
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results  # noqa: E402

N = 70
EMP1 = ids()['eid']
made = php("""
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'big%%'");
    foreach($wpdb->get_col("SELECT id FROM {$p}ews_teams WHERE name IN ('Big Team','Small Team')") as $old)$wpdb->query("DELETE FROM {$p}ews_team_members WHERE team_id=".(int)$old);
    $wpdb->query("DELETE FROM {$p}ews_teams WHERE name IN ('Big Team','Small Team')");
    $wpdb->insert($p.'ews_teams',['name'=>'Big Team','active'=>1]); $tm=(int)$wpdb->insert_id;
    $today=current_time('Y-m-d'); $ids=[];
    for($i=1;$i<=%d;$i++){
        $wpdb->insert($p.'ews_employees',['name'=>sprintf('Big Person %%02d',$i),'domain_name'=>'big'.$i,'email'=>"big$i@example.com",'active'=>1,'attendance_enabled'=>1]);
        $e=(int)$wpdb->insert_id; $ids[]=$e;
        $wpdb->insert($p.'ews_schedule',['employee_id'=>$e,'work_date'=>$today,'status'=>'Office']);
        if($i<=30)$wpdb->insert($p.'ews_team_members',['team_id'=>$tm,'employee_id'=>$e,'active'=>1]);
        foreach(['sign_in','sign_out'] as $t)$wpdb->insert($p.'ews_time_logs',['employee_id'=>$e,'work_date'=>$today,'event_type'=>$t,'event_at'=>$today.($t==='sign_in'?' 09:00:00':' 17:00:00'),'scheduled_status'=>'Office','created_at'=>current_time('mysql')]);
    }
    echo $tm;""" % N)
TEAM = int(made)
adm = Session('admin', 'admin')
ROW = 'class="ews-att-employee-row'


def rows(page):
    return len(re.findall(ROW, page))


# Team Schedule
st, page, _ = adm.req('/app/?ews_view=schedule')
check('a long Team Schedule shows 50 people a page, with page links', st == 200 and rows(page) == 50 and 'Page 1 of 2' in page and 'wfo-list-pager' in page, (st, rows(page)))
everyone = int(q("SELECT COUNT(*) c FROM {p}ews_employees WHERE active=1")[0]['c'])
check('...the count says everyone, not just this page', '%d people' % everyone in page, everyone)
check('...and the search is a form the server answers', 'data-ews-server-list' in page and 'name="q"' in page and 'name="ews_view" value="schedule"' in page)
st, page2, _ = adm.req('/app/?ews_view=schedule&pg=2')
check('page 2 holds the rest', 0 < rows(page2) < 50 and 'Page 2 of 2' in page2, rows(page2))
st, page, _ = adm.req('/app/?ews_view=schedule&q=big+person+07')
check('search finds one person', rows(page) == 1 and 'Big Person 07' in page and 'wfo-list-pager' in page, rows(page))
st, page, _ = adm.req('/app/?ews_view=schedule&q=nobody-here')
check('...and says when nobody matches', rows(page) == 0 and re.search(r'<p class="wfo-sched-noresult">', page) is not None)

# An employee's schedule: their team first, their whole department on request.
php("""$wpdb->query("DELETE FROM {$p}ews_departments WHERE code='BIGD'");
    $wpdb->insert($p.'ews_departments',['name'=>'Big Dept','code'=>'BIGD','active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]); $d=(int)$wpdb->insert_id;
    $wpdb->query("UPDATE {$p}ews_employees SET department_id=$d WHERE domain_name LIKE 'big%%' OR id=%d");
    $wpdb->insert($p.'ews_teams',['name'=>'Small Team','active'=>1,'department_id'=>$d]); $t=(int)$wpdb->insert_id;
    foreach($wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE domain_name IN ('big1','big2','big3') OR id=%d") as $e)$wpdb->insert($p.'ews_team_members',['team_id'=>$t,'employee_id'=>$e,'active'=>1]);""" % (EMP1, EMP1))
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req('/app/?ews_view=schedule')
check('an employee sees their own team first', rows(page) == 4 and '4 people' in page and 'aria-current="page">My team' in page and 'wfo-list-pager' not in page, rows(page))
check('...with a link to their whole department', 'scope=department' in page and 'My department' in page)
st, page, _ = emp.req('/app/?ews_view=schedule&scope=department')
check('...which shows everyone in the department (paged: %d people)' % (N + 1), rows(page) == 50 and '%d people' % (N + 1) in page and 'aria-current="page">My department' in page and 'name="scope" value="department"' in page, rows(page))
st, page, _ = emp.req('/app/?ews_view=schedule&scope=department&q=big+person+70')
check('...and its search stays in the department', rows(page) == 1, rows(page))
swap = re.search(r'<select name="target_employee_id".*?</select>', page, re.S)
check('the swap form still offers the whole department', swap is not None and swap.group(0).count('<option value="') - 1 == N, swap.group(0).count('<option value="') if swap else None)

# Attendance
st, page, _ = adm.req('/app/?ews_view=attendance')
check('a long Attendance grid shows 50 people a page', st == 200 and rows(page) == 50 and 'Page 1 of 2' in page, (st, rows(page)))
check('...and the quick "Set…" says it applies to this page', 'for everyone on this page' in page)
st, page, _ = adm.req('/app/?ews_view=attendance&team=Big+Team')
att_all = int(q("SELECT COUNT(*) c FROM {p}ews_employees WHERE active=1 AND attendance_enabled=1")[0]['c'])
st, page0, _ = adm.req('/app/?ews_view=attendance')
check('...the Employees card counts everyone, not the page', re.search(r'<b>%d</b><small>Employees' % att_all, page0) is not None, att_all)
check('the team filter runs on the server, and the cards count that team', re.search(r'<b>30</b><small>Employees', page) is not None and rows(page) == 30 and 'wfo-list-pager' in page and re.search(r'<option value="Big Team" selected', page) is not None, rows(page))
st, page, _ = adm.req('/app/?ews_view=attendance&team=Big+Team&q=big+person+0')
check('...together with the search', rows(page) == 9, rows(page))

# wp-admin Sign In / Out Report: 140 records today
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-time-report')
recs = page.count('name="action" value="ews31_time_reset"')
check('the Sign In / Out Report shows 100 records a page', recs == 100 and 'Page 1 of 2' in page, recs)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-time-report&paged=2')
check('...and the rest on page 2', page.count('name="action" value="ews31_time_reset"') >= 40 and 'Page 2 of 2' in page)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-time-report&s=big12')
check('...and searches by employee', page.count('name="action" value="ews31_time_reset"') == 2, page.count('name="action" value="ews31_time_reset"'))

php("""$ids=$wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE domain_name LIKE 'big%%'"); if($ids){$in=implode(',',array_map('intval',$ids));
    foreach(['ews_schedule','ews_time_logs','ews_team_members'] as $t)$wpdb->query("DELETE FROM {$p}$t WHERE employee_id IN ($in)");
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE id IN ($in)");} $wpdb->query("DELETE FROM {$p}ews_teams WHERE id=%d OR name='Small Team'");
    $wpdb->query("DELETE FROM {$p}ews_team_members WHERE employee_id=%d"); $wpdb->query("UPDATE {$p}ews_employees SET department_id=NULL WHERE id=%d");
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE code='BIGD'");""" % (TEAM, EMP1, EMP1))
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

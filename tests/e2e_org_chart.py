"""Organization chart (3.31.97, owner-approved mockup docs/mockups/org-chart/): off by default, the
Features settings (saved, Audit Log), the job title on the Employees page, the wp-admin chart (counts,
who has no manager, too many direct reports, an archived manager, a loop in old data), a supervisor
that would make a loop refused at any level, and the app's My team (who may open it and whom they see).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_org_chart.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse
from html.parser import HTMLParser

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

FEATURES = '/wp-admin/admin.php?page=ews31-features'
CHART = '/wp-admin/admin.php?page=ews31-org-chart'
EMPLOYEES = '/wp-admin/admin.php?page=ews31-employees'


class Form(HTMLParser):
    """The fields of the first post form on a page, as the browser would send them."""
    def __init__(self):
        super().__init__()
        self.inside, self.done, self.data, self.select = False, False, [], None

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == 'form' and not self.done and a.get('method') == 'post':
            self.inside = True
        if not self.inside:
            return
        if tag == 'input' and a.get('name'):
            t = a.get('type', 'text')
            if t in ('checkbox', 'radio') and 'checked' not in a:
                return
            self.data.append((a['name'], a.get('value', 'on' if t == 'checkbox' else '')))
        elif tag == 'textarea' and a.get('name'):
            self.data.append((a['name'], ''))
        elif tag == 'select' and a.get('name'):
            self.select = [a['name'], None, None]
        elif tag == 'option' and self.select:
            if self.select[2] is None:
                self.select[2] = a.get('value', '')
            if 'selected' in a:
                self.select[1] = a.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'select' and self.select:
            self.data.append((self.select[0], self.select[1] if self.select[1] is not None else self.select[2]))
            self.select = None
        elif tag == 'form' and self.inside:
            self.inside, self.done = False, True


def save_features(sess, **over):
    st, page, _ = sess.req(FEATURES)
    f = Form()
    f.feed(page[page.index('<form method="post"'):])
    out = [(k, v) for k, v in f.data if k not in over] + [(k, v) for k, v in over.items() if v is not None]
    st, body, h = sess.req('/wp-admin/admin-post.php', urllib.parse.urlencode(out).encode())
    return h.get('Location', '')


def chart_data(page):
    m = re.search(r'<script type="application/json" id="wfo-org-data">(.*?)</script>', page, re.S)
    return json.loads(m.group(1)) if m else None


def tabs(page):
    m = re.search(r'<nav class="nav-tab-wrapper[^"]*"[^>]*>(.*?)</nav>', page, re.S)
    return [html.unescape(t) for t in re.findall(r'<a [^>]*>(.*?)</a>', m.group(1))] if m else []


def employee_update_nonce(sess):
    st, page, _ = sess.req(EMPLOYEES)
    m = re.search(r'name="action" value="ews31_employee_update">.*?name="_wpnonce" value="([^"]+)"', page, re.S)
    return m.group(1) if m else ''


wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
EMP1 = ids()['eid']
# Ahmed (CEO) → Mona (Sales) → Nour (lead) → Emp One, Hana, Mostafa; Ahmed → Karim (Ops) → Hassan; Khaled has nobody;
# Sara was Karim's report under Omar, who is archived; Ziad ↔ Yara supervise each other in old data.
out = php("""foreach(['ews_employee_relationships','ews_audit_log','ews_departments'] as $t)$wpdb->query("DELETE FROM {$p}$t");
delete_option('ews_org_chart'); update_user_meta(1,'locale','en_US');
foreach(['orgmgr','orgrep'] as $l){$u=get_user_by('login',$l); if($u) wp_delete_user($u->ID);}
$wpdb->insert("{$p}ews_departments",['name'=>'Sales','code'=>'SAL','active'=>1]); $sales=$wpdb->insert_id;
$wpdb->insert("{$p}ews_departments",['name'=>'Operations','code'=>'OPS','active'=>1]); $ops=$wpdb->insert_id;
$mk=function($n,$d,$t='',$u=0,$a=1)use($wpdb,$p){$wpdb->insert("{$p}ews_employees",['name'=>$n,'domain_name'=>strtolower(str_replace(' ','.',$n)),'email'=>strtolower(str_replace(' ','.',$n)).'@example.com','wp_user_id'=>$u,'department_id'=>$d,'job_title'=>$t?:null,'active'=>$a,'attendance_enabled'=>1]);return $wpdb->insert_id;};
$mgr_user=wp_create_user('orgmgr','orgmgrpass','orgmgr@example.com'); $rep_user=wp_create_user('orgrep','orgreppass','orgrep@example.com');
$e=['ahmed'=>$mk('Ahmed Samir',0,'Chief Executive Officer'),'mona'=>$mk('Mona Adel',$sales,'Sales Director'),'nour'=>$mk('Nour Saeed',$sales,'Team Lead',$mgr_user),
 'hana'=>$mk('Hana Lotfy',$sales,'Sales Representative',$rep_user),'mostafa'=>$mk('Mostafa Samir',$sales,'Sales Representative'),
 'karim'=>$mk('Karim Fathy',$ops,'Operations Director'),'hassan'=>$mk('Hassan Hamdy',$ops,'Supervisor'),'khaled'=>$mk('Khaled Hamdy',0,'Driver'),
 'omar'=>$mk('Omar Hamdy',$ops,'Manager',0,0),'sara'=>$mk('Sara Nabil',$ops,'Accountant'),'ziad'=>$mk('Ziad Loop',0),'yara'=>$mk('Yara Loop',0)];
$e['emp1']=%d; $wpdb->update("{$p}ews_employees",['department_id'=>$sales],['id'=>%d]);
$rt=$wpdb->prefix.'ews_employee_relationships'; $now=current_time('mysql');
foreach([['mona','ahmed'],['nour','mona'],['emp1','nour'],['hana','nour'],['mostafa','nour'],['karim','ahmed'],['hassan','karim'],['sara','omar'],['ziad','yara'],['yara','ziad']] as [$a,$b])
  $wpdb->insert($rt,['employee_id'=>$e[$a],'related_employee_id'=>$e[$b],'relationship_type'=>'supervisor','active'=>1,'created_at'=>$now,'updated_at'=>$now]);
echo wp_json_encode($e);""" % (EMP1, EMP1))
E = json.loads(out.splitlines()[-1])
adm, emp, mgr, rep = Session('admin', 'admin'), Session('emp1', 'emp1pass'), Session('orgmgr', 'orgmgrpass'), Session('orgrep', 'orgreppass')

# ---------------------------------------------------------------- off by default
st, page, _ = adm.req(FEATURES)
check('Features has the Organization Chart section, off by default', 'id="ews-org-chart"' in page and not re.search(r'name="org_enabled"[^>]*checked', page))
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-teams')
check('off: no Org chart tab under People', 'Org chart' not in tabs(page) and 'Teams' in tabs(page), tabs(page))
st, page, _ = adm.req(CHART)
check('off: the page still opens from a link and says it is off', st == 200 and 'The organization chart is switched off' in page and 'Turn it on in Feature Configuration' in page, st)
st, page, _ = adm.req(EMPLOYEES)
check('off: the Employees page has no Job Title', 'name="job_title"' not in page and '<th>Job Title</th>' not in page)
st, page, _ = emp.req('/app/?ews_view=team')
check('off: My team is not in the app (the Dashboard instead)', 'wfo-team' not in page and 'ews_view=team' not in page)

# ---------------------------------------------------------------- settings
loc = save_features(adm, org_enabled='1', org_audience='managers', org_employee_view='line', org_wide_span='2', org_title_required=None)
o = json.loads(php("echo wp_json_encode(get_option('ews_org_chart'));").splitlines()[-1])
check('saved: on, administrators and managers, line, flag above 2', 'features_saved=1' in loc and o == {'enabled': 1, 'audience': 'managers', 'employee_view': 'line', 'wide_span': 2, 'title_required': 0}, (loc, o))
a = q("SELECT details FROM {p}ews_audit_log WHERE action='org_chart_update'")
check('...written to the Audit Log', len(a) == 1 and 'enabled=on audience=managers' in a[0]['details'], a)
loc = save_features(adm, org_audience='nobody', org_wide_span='-5')
o = json.loads(php("echo wp_json_encode(get_option('ews_org_chart'));").splitlines()[-1])
check('...an unknown choice keeps the default, a negative limit becomes 0', o['audience'] == 'admins' and o['wide_span'] == 0 and o['enabled'] == 1, o)
save_features(adm, org_audience='managers', org_wide_span='2')

# ---------------------------------------------------------------- the wp-admin chart
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-teams')
check('on: Org chart is a tab under People, after Teams', tabs(page)[:4] == ['Employees', 'Departments', 'Teams', 'Org chart'], tabs(page))
st, page, _ = adm.req(CHART)
d = chart_data(page)
by = {p['id']: p for p in d['people']} if d else {}
check('the page has the chart data, the switched-off notice gone', st == 200 and d and 'switched off' not in page, st)
check('active people only (Omar, archived, is not in it)', E['omar'] not in by and len(by) == 12, sorted(by))
check('each person has their manager, direct reports and everyone under them',
      by[E['nour']]['m'] == E['mona'] and by[E['nour']]['r'] == 3 and by[E['ahmed']]['u'] == 7 and by[E['mona']]['u'] == 4, (by.get(E['nour']), by.get(E['ahmed'])))
check('...the job title and department', by[E['hana']]['t'] == 'Sales Representative' and d['departments'][str(by[E['hana']]['d'])]['name'] == 'Sales'
      and d['departments'][str(by[E['karim']]['d'])]['name'] == 'Operations')
check('a report of an archived manager has no manager', by[E['sara']]['m'] == 0)
check('two people supervising each other in old data are both shown without a manager (the chart does not go round)', by[E['ziad']]['m'] == 0 and by[E['yara']]['m'] == 0)
kpi = dict(re.findall(r'data-kpi="(\w+)">(\d+)<', page))
check('counts: 8 in the chart, 4 managers, 4 levels, 4 without a manager', kpi == {'people': '8', 'managers': '4', 'levels': '4', 'without': '4'}, kpi)
without = sorted(int(x) for x in re.findall(r'data-without="(\d+)"', page))
check('Needs attention: without a manager, Khaled (not Ahmed, the head), with a link to set one',
      without == [E['khaled']] and 'page=ews31-employees&#038;s=Khaled%20Hamdy' in page, without)
check('...whose manager was archived: Sara, with the archived manager named', re.findall(r'data-archived="(\d+)"', page) == [str(E['sara'])] and 'was under Omar Hamdy' in page)
check('...in a loop in old data: Ziad and Yara', sorted(int(x) for x in re.findall(r'data-looped="(\d+)"', page)) == sorted([E['ziad'], E['yara']]))
check('too many direct reports (more than 2): Nour', re.findall(r'data-wide="(\d+)"', page) == [str(E['nour'])] and d['wide'] == [E['nour']])
check('the profile link of each person', d['profile'].endswith('page=ews31-employee-profile&employee_id='))
check('the chart script and styles are on the page', 'admin-org-chart.js' in page and 'admin-org-chart.css' in page)
st, page, _ = emp.req(CHART)
check('an employee without the permission cannot open it', st in (403, 500) or 'not allowed' in page or 'Access denied' in page, st)

# ---------------------------------------------------------------- the Employees page: job title, loops
st, page, _ = adm.req(EMPLOYEES)
check('on: the Employees page has a Job Title (optional), with the titles in use suggested',
      'name="job_title"' in page and 'Job Title (optional)' in page and '<option value="Sales Representative">' in page and '<th>Job Title</th>' in page)
nonce = employee_update_nonce(adm)
st, r, _ = adm.post('ews31_employee_update', _wpnonce=nonce, id=E['ahmed'], name='Ahmed Samir', domain_name='ahmed.samir', email='ahmed.samir@example.com',
                    department_id=0, supervisor_employee_ref='Nour Saeed · #%d' % E['nour'], attendance_enabled=1, active=1)
check('a supervisor who reports to the employee two levels down is refused', r.get('employee_error') == 'supervisor_loop', r)
st, page, _ = adm.req(EMPLOYEES + '&employee_error=supervisor_loop')
check('...with the message', 'nobody can be put under someone who reports to them' in page)
st, r, _ = adm.post('ews31_employee_update', _wpnonce=nonce, id=E['hassan'], name='Hassan Hamdy', domain_name='hassan.hamdy', email='hassan.hamdy@example.com',
                    department_id=0, supervisor_employee_ref='Mona Adel · #%d' % E['mona'], attendance_enabled=1, active=1, job_title='  Customer <b>Service</b>   Supervisor ')
row = q("SELECT job_title FROM {p}ews_employees WHERE id=%d" % E['hassan'])
check('an allowed move is saved, with the job title cleaned', r.get('employee_notice') == 'updated' and row[0]['job_title'] == 'Customer Service Supervisor', (r, row))
st, r, _ = adm.post('ews31_employee_update', _wpnonce=nonce, id=E['hassan'], name='Hassan Hamdy', domain_name='hassan.hamdy', email='hassan.hamdy@example.com',
                    department_id=0, supervisor_employee_ref='Karim Fathy · #%d' % E['karim'], attendance_enabled=1, active=1)
row = q("SELECT job_title FROM {p}ews_employees WHERE id=%d" % E['hassan'])
check('a form without the job title keeps the saved one', r.get('employee_notice') == 'updated' and row[0]['job_title'] == 'Customer Service Supervisor', (r, row))
save_features(adm, org_title_required='1')
st, page, _ = adm.req(EMPLOYEES)
add_nonce = re.search(r'name="action" value="ews31_employee_save"><input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"', page)
add_nonce = add_nonce.group(1) if add_nonce else re.search(r'value="ews31_employee_save">.*?name="_wpnonce" value="([^"]+)"', page, re.S).group(1)
st, r, _ = adm.post('ews31_employee_save', _wpnonce=add_nonce, name='New Person', domain_name='new.person', email='new.person@example.com', department_id=0, job_title='', attendance_enabled=1)
check('title required: adding an employee without one is refused', r.get('employee_error') == 'title', r)
st, r, _ = adm.post('ews31_employee_save', _wpnonce=add_nonce, name='New Person', domain_name='new.person', email='new.person@example.com', department_id=0, job_title='Driver', attendance_enabled=1)
check('...with one it is added', r.get('employee_notice') == 'added' and q("SELECT job_title FROM {p}ews_employees WHERE domain_name='new.person'")[0]['job_title'] == 'Driver', r)
save_features(adm, org_title_required=None)

# ---------------------------------------------------------------- the app: My team
st, page, _ = mgr.req('/app/?ews_view=team')
check('for managers: a manager (Nour) opens My team, on themselves', 'class="wfo-team"' in page and 'Nour Saeed' in page and 'app-team' in page, st)
check('...their line above (Ahmed › Mona) and their 3 direct reports', 'Reports directly' in page and page.count('class="wfo-team-row"') == 3
      and re.search(r'wfo-team-up.*?Ahmed.*?wfo-team-up.*?Mona', page, re.S))
check('...My team is in their menu', 'ews_view=team' in page and 'My team' in page)
st, page, _ = mgr.req('/app/?ews_view=team&person=%d' % E['hana'])
check('...a person under them can be opened, with their teammates', 'Hana Lotfy' in page and 'Teammates (same manager)' in page and 'Mostafa' in page and 'Back to me' in page)
st, page, _ = mgr.req('/app/?ews_view=team&person=%d' % E['khaled'])
check('...someone outside their line shows themselves instead', 'Khaled' not in page and 'Nour Saeed' in page)
st, page, _ = rep.req('/app/?ews_view=team')
check('for managers: an employee who manages nobody does not get My team', 'class="wfo-team"' not in page and 'ews_view=team' not in page)

save_features(adm, org_audience='everyone', org_employee_view='line')
st, page, _ = rep.req('/app/?ews_view=team')
check('for everyone: an employee opens My team: their manager above, their teammates', 'class="wfo-team"' in page and 'Hana Lotfy' in page and 'Teammates (same manager)' in page and 'Mostafa' in page)
st, page, _ = rep.req('/app/?ews_view=team&person=%d' % E['karim'])
check('...the line view: someone outside their line is not shown', 'Karim' not in page and 'Hana Lotfy' in page)
st, page, _ = rep.req('/app/?ews_view=team&person=%d' % E['mona'])
check('...but a manager above them is', 'Mona Adel' in page and 'Sales Director' in page)
save_features(adm, org_employee_view='company')
st, page, _ = rep.req('/app/?ews_view=team&person=%d' % E['karim'])
check('whole company: anyone can be looked at', 'Karim Fathy' in page and 'Operations Director' in page)
st, page, _ = rep.req('/app/?ews_view=team&person=%d' % E['omar'])
check('...but not an archived employee', 'Omar' not in page and 'Hana Lotfy' in page)
st, page, _ = adm.req('/app/?ews_view=team')
check('an administrator who is not an employee starts at the top of the chart', 'class="wfo-team"' in page and 'Ahmed Samir' in page)
st, page, _ = emp.req('/app/?ews_view=team&person=%d' % E['khaled'])
check('someone without a manager or team is shown with a note', 'Khaled Hamdy' in page and 'No manager or team is set for this person yet.' in page)

php("update_user_meta(1,'locale','ar');")
st, page, _ = adm.req(CHART)
check('Arabic: the page and the chart texts are translated', 'الهيكل التنظيمي' in page and 'مباشر' in (json.dumps(chart_data(page) or {}, ensure_ascii=False)), st)
php("""update_user_meta(1,'locale','en_US'); delete_option('ews_org_chart');
foreach(['orgmgr','orgrep'] as $l){$u=get_user_by('login',$l); if($u) wp_delete_user($u->ID);}
$wpdb->query("DELETE FROM {$p}ews_employee_relationships"); $wpdb->query("DELETE FROM {$p}ews_departments");
$wpdb->query("DELETE FROM {$p}ews_employees WHERE id IN (%s) OR domain_name='new.person'");
$wpdb->update("{$p}ews_employees",['department_id'=>0],['id'=>%d]);""" % (','.join(str(v) for k, v in E.items() if k != 'emp1'), EMP1))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

"""Behaviour tests for the employee app's People directory (?ews_view=people) and a colleague's
profile (?ews_view=employee) with Recognition (Kudos).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_people.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PEOPLE = '/app/?ews_view=people'


def profile(eid):
    return '/app/?ews_view=employee&employee_id=%d' % eid


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_teams','ews_team_members','ews_kudos','ews_employee_achievements'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager'],['id'=>$eid]);
        $mk=function($name,$active=1,$extra=[])use($wpdb,$p){$dn='u'.substr(md5($name),0,6);$wpdb->insert($p.'ews_employees',array_merge(['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>$active,'attendance_enabled'=>1],$extra));return $wpdb->insert_id;};
        $ids=['mona'=>$eid,'omar'=>$mk('Omar Ontime'),'ahmed'=>$mk('أحمد منير'),'arch'=>$mk('Archie Archived',0)];
        // Supervisors are approval relationships (as saved by wp-admin → Employees).
        $rel=new ReflectionMethod('EWS_Manager_V31_1','approval_set_relationship'); $rel->setAccessible(true); $rel->invoke(new EWS_Manager_V31_1(),$ids['omar'],'supervisor',$eid,0);
        $wpdb->insert($p.'ews_teams',['name'=>'Blue Team','manager_employee_id'=>$eid,'active'=>1]); $t=$wpdb->insert_id;
        foreach(['mona','omar'] as $k) $wpdb->insert($p.'ews_team_members',['team_id'=>$t,'employee_id'=>$ids[$k],'active'=>1]);
        update_option('ews_employee_profile_settings',['enabled'=>1,'show_photo'=>1,'show_name'=>1,'show_team'=>1,'show_email'=>1,'show_supervisor'=>1,'show_achievements'=>1,'show_recognition'=>1],false);
        update_option('ews_feature_achievements',1,false); update_option('ews_feature_recognition',1,false); update_option('ews_recognition_allow_kudos',1,false);
        update_option('ews_recognition_weekly_limit_mode','limited',false); update_option('ews_recognition_weekly_limit',1,false);
        $u=new WP_User((int)$wpdb->get_var("SELECT wp_user_id FROM {$p}ews_employees WHERE id=$eid")); $u->add_cap('ews_view_people');
        echo wp_json_encode($ids);
    """)
    return json.loads(out.splitlines()[-1])


def kudos_nonce(page):
    m = re.search(r'name="ews_kudos_nonce" value="([^"]+)"', page)
    return m.group(1) if m else 'x'


def send_kudos(sess, eid, category='teamwork', message='Thanks!'):
    st, page, _ = sess.req(profile(eid))
    cat = re.search(r'<select name="category"[^>]*><option value="([^"]+)"', page)
    data = {'action': 'ews_kudos_submit', 'ews_kudos_nonce': kudos_nonce(page), 'recipient_employee_id': eid, 'category': cat.group(1) if cat else category,
            'message': message, '_wp_http_referer': profile(eid)}
    return sess.req('/wp-admin/admin-post.php', data)


ids = seed()
emp = Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- directory
st, page, _ = emp.req(PEOPLE)
names = re.findall(r'<div class="wfo-person-main"><strong>([^<]+)</strong>', page)
check('People lists active colleagues only', st == 200 and sorted(names) == sorted(['Mona Manager', 'Omar Ontime', 'أحمد منير']), names)
check('initials use first and last name in any script', re.search(r'<span>أم</span></div>\s*<div class="wfo-person-main"><strong>أحمد منير', page) is not None and re.search(r'<span>OO</span>', page) is not None)
st, page, _ = emp.req(PEOPLE + '&people_search=omar')
check('search finds by name', re.findall(r'<div class="wfo-person-main"><strong>([^<]+)</strong>', page) == ['Omar Ontime'])
team = q("SELECT id FROM {p}ews_teams WHERE name='Blue Team'")[0]['id']
st, page, _ = emp.req(PEOPLE + '&people_team=%s' % team)
check('the team filter keeps the team', sorted(re.findall(r'<div class="wfo-person-main"><strong>([^<]+)</strong>', page)) == ['Mona Manager', 'Omar Ontime'])

# ---------------------------------------------------------------- a colleague's profile
st, page, _ = emp.req(profile(ids['omar']))
check('a colleague profile shows name, team, email and supervisor', all(x in page for x in ('<h2>Omar Ontime</h2>', 'Blue Team', '@example.com', 'Mona Manager')))
check('the Achievements panel does not say "Your" on a colleague profile', 'Achievements' in page and 'Your earned milestones' not in page and 'Your achievements will appear here' not in page)
check('the page script and styles are files', 'people.js' in page and '.wfo-employee-profile-head{' not in page and 'onclick=' not in page)
php("update_option('ews_feature_achievements',0,false);")
st, page, _ = emp.req(profile(ids['omar']))
check('with Achievements switched off there is no Achievements panel', '🏆 Achievements' not in page)
php("update_option('ews_feature_achievements',1,false);")
st, page, _ = emp.req(profile(ids['mona']))
check('no "Give Kudos" on your own profile', 'Give Kudos' not in page)
st, page, _ = emp.req(profile(ids['arch']))
check('an archived colleague is not found', 'Employee not found' in page)

# ---------------------------------------------------------------- kudos
send_kudos(emp, ids['omar'])
check('Kudos are saved', len(q("SELECT id FROM {p}ews_kudos WHERE recipient_employee_id=%d" % ids['omar'])) == 1)
st, page, _ = emp.req(profile(ids['omar']))
check('...and shown with a success message', 'Kudos sent successfully.' in page and 'Mona Manager</strong>' in page)
send_kudos(emp, ids['ahmed'], message='Second one')
st, page, _ = emp.req(profile(ids['ahmed']))
check('over the weekly limit: refused, and the reason is given', len(q("SELECT id FROM {p}ews_kudos WHERE recipient_employee_id=%d" % ids['ahmed'])) == 0 and 'weekly' in page.lower() and 'limit' in page.lower(),
      re.findall(r'wfo-recognition-notice error">([^<]*)', page))
php("update_option('ews_recognition_weekly_limit_mode','unlimited',false); $s=get_option('ews_employee_profile_settings'); $s['show_recognition']=0; update_option('ews_employee_profile_settings',$s,false);")
send_kudos(emp, ids['ahmed'], message='Hidden panel')
check('with Recognition hidden on profiles, Kudos cannot be sent', len(q("SELECT id FROM {p}ews_kudos WHERE recipient_employee_id=%d" % ids['ahmed'])) == 0)

# ---------------------------------------------------------------- access
php("$u=new WP_User((int)$wpdb->get_var(\"SELECT wp_user_id FROM {$p}ews_employees WHERE id=%d\")); $u->remove_cap('ews_view_people');" % ids['mona'])
st, page, _ = emp.req(PEOPLE)
check('without View People the directory is not shown', 'wfo-person-card' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

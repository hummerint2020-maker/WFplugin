"""System health (3.31.110, docs/tasks/system-health.md): off by default and inert while off; the Features switch creates the
four tables and the hourly rollup; the collector names each request without personal data (pages, actions, app views, API
routes, scheduled jobs), keeps only the shape of a query, normalises PHP errors, and costs at most one UPDATE per request;
"Look at this first", the admin notice (hidden for a week), the optional email (new alerts only), retention, "Delete collected
data", the permission checks, and switching it off again.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_system_health.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys, time, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

TABLES = ['hourly', 'daily', 'queries', 'errors']
MARK = 'SECRETMARK123'
FEATURES = '/wp-admin/admin.php?page=ews31-features'
SCREEN = '/wp-admin/admin.php?page=ews31-system-health'

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
MU = php("echo WPMU_PLUGIN_DIR;").strip().splitlines()[-1]
php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); copy('%s',$d.'/ews-e2e-health-mu.php'); @unlink($d.'/ews-e2e-health-mail.txt'); @unlink($d.'/ews-e2e-health-q.txt');" % os.path.join(HERE, 'e2e_health_mu.php'))


def reset():
    php("""foreach(['hourly','daily','queries','errors'] as $t) $wpdb->query("DROP TABLE IF EXISTS {$p}ews_health_$t");
        foreach(['ews_feature_health','ews_health_sample_rate','ews_health_retention_days','ews_health_settings','ews_health_schema','ews_health_cron_last','ews_health_alerts','ews_health_findings','ews_health_mail','ews_health_full','ews_health_purged','ews_health_rollup_at'] as $o) delete_option($o);
        delete_metadata('user',0,'ews_health_dismissed','',true); wp_clear_scheduled_hook('ews_health_rollup'); $wpdb->query("DELETE FROM {$p}ews_audit_log");""")


def exists(t):
    return php("$wpdb->suppress_errors(true); $wpdb->get_var(\"SELECT COUNT(*) FROM {$p}ews_health_%s\"); echo $wpdb->last_error===''?1:0;" % t).strip().splitlines()[-1] == '1'


def rows(t, where=''):
    return q("SELECT * FROM {p}ews_health_%s %s" % (t, where)) if exists(t) else []


def routes():
    return {r['route'] for r in rows('hourly')}


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s'));" % name).strip().splitlines()[-1] or 'null')


def call(method, *args):
    return php("""$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'%s');$m->setAccessible(true);$r=$m->invoke($o,%s);echo wp_json_encode($r);""" % (method, ','.join(json.dumps(a) for a in args))).strip().splitlines()[-1]


def rollup():
    php("delete_option('ews_health_purged'); $o=new EWS_Manager_V31_1(); $o->health_rollup();")


def nonce_of(sess, path, action):
    page = sess.req(path)[1]
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1)
    return None


def features(adm, on, rate=None, retention=None):
    n = nonce_of(adm, FEATURES, 'ews31_features_save')
    data = {'_wpnonce': n, 'health_present': 1, 'health_sample_rate': rate if rate is not None else 10, 'health_retention_days': retention if retention is not None else 90,
            'tasks_enabled': 1, 'overtime_enabled': 1}
    if on:
        data['health_enabled'] = 1
    return adm.post('ews31_features_save', **data)[1]


def take(tag):
    p = os.path.join(MU, 'ews-e2e-health-q.txt')
    return [json.loads(l) for l in open(p)] if os.path.exists(p) else []


def median(v):
    v = sorted(v)
    return v[len(v) // 2] if v else 0


reset()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- off by default, and inert
page = adm.req(FEATURES)[1]
check('off by default: the Features section is there, switched off, with its two settings', 'id="ews-system-health"' in page and 'name="health_enabled"' in page and 'name="health_sample_rate"' in page and 'name="health_retention_days"' in page and not re.search(r'name="health_enabled"[^>]*checked', page))
for p in ['/wp-admin/admin.php?page=ews31-payroll', '/app/?ews_view=schedule']:
    (adm if 'wp-admin' in p else emp).req(p)
features(adm, False)
check('saving Features while it is off writes nothing of its own', opt('ews_feature_health') in (None, False) and opt('ews_health_sample_rate') in (None, False) and opt('ews_health_retention_days') in (None, False))
check('...no table, no option, no scheduled rollup while it is off', not any(exists(t) for t in TABLES) and not opt('ews_health_schema') and php("echo wp_next_scheduled('ews_health_rollup')?1:0;").strip().splitlines()[-1] == '0')
check('...its screen says it is off, with the link to the switch', 'System health is off' in adm.req(SCREEN)[1] and 'ews-system-health' in adm.req(SCREEN)[1])
base_q = []
for i in range(6):
    adm.req('/wp-admin/admin.php?page=ews31&e2e_tag=off')
    emp.req('/app/?ews_view=schedule&e2e_tag=off')
    adm.req('/wp-login.php?e2e_tag=foreign_off')
off = take('off')

# ---------------------------------------------------------------- switching on
loc = features(adm, True, 100, 90)
check('the Features switch creates the four tables, the schema version and the hourly rollup', all(exists(t) for t in TABLES) and opt('ews_health_schema') == '1' and php("echo wp_next_scheduled('ews_health_rollup')?1:0;").strip().splitlines()[-1] == '1', loc)
check('...is audited', q("SELECT id FROM {p}ews_audit_log WHERE action='health_enabled'"))
check('...and the screen is open for the administrator, empty before the first rollup', 'The first summary is made within the hour' in adm.req(SCREEN)[1])
check('...but not for an employee', 'Look at this first' not in emp.req(SCREEN)[1])
check('...the sample rate is saved', int(opt('ews_health_sample_rate')) == 100)

# ---------------------------------------------------------------- the collector names each request, without personal data
for i in range(3):
    adm.req('/wp-admin/admin.php?page=ews31-payroll')
adm.req('/wp-admin/admin.php?page=ews31-employees&s=%s' % MARK)
adm.req('/wp-admin/admin.php?page=ews31-employees&s=' + urllib.parse.quote('%s -- #%sB' % (MARK, MARK)))
adm.req('/wp-admin/admin.php?page=ews31-employees&s=' + MARK + 'LONG' + 'z' * 12000)
emp.req('/app/?ews_view=schedule')
emp.req('/app/?ews_view=zzz')
emp.req('/app/')
emp.req('/wp-json/workforce-one/v1/meta')
adm.post('ews_health_dismiss', _wpnonce='x')   # a known action (refused: bad nonce)
adm.req('/wp-admin/admin.php?page=ews31&e2e_tag=on100')
r = routes()
check('each request is classified: a wp-admin page, an app view, an unknown view, the app home, an API route, an action', {'admin:ews31-payroll', 'admin:ews31-employees', 'app:schedule', 'app:other', 'app:default', 'rest:GET /meta', 'post:ews_health_dismiss'} <= r, sorted(r))
pay = [x for x in rows('hourly', "WHERE route='admin:ews31-payroll'")]
check('...a page visited 3 times has 3 runs, with its time, queries and memory', sum(int(x['n']) for x in pay) == 3 and sum(int(x['q_sum']) for x in pay) > 0 and sum(int(x['ms_sum']) for x in pay) > 0 and max(int(x['mem_max']) for x in pay) > 0, pay[:2])
check('...a run lands in a duration bucket', sum(sum(int(x['b%d' % i]) for i in range(20)) for x in pay) == 3)
check('...and its memory as a share of that request\'s own limit (0 when the limit is unlimited)', all('mem_pct_max' in x and int(x['mem_pct_max']) >= 0 for x in pay))
check('...no route carries a value from the request', all(re.match(r'^[a-z]+:[A-Za-z0-9_\-/{} :]+$', x) for x in r) and not any(MARK.lower() in x.lower() for x in r))
qs = rows('queries')
check('sampled requests keep the shape of their queries with the caller', qs and any(x['caller'] and x['shape'] for x in qs), qs[:2])
check('...literals are removed from every shape', not any(re.search(r"'[^?']|\b\d{2,}\b", x['shape'].replace('{p}', '')) for x in qs), [x['shape'] for x in qs if re.search(r"'[^?']|\b\d{2,}\b", x['shape'].replace('{p}', ''))][:2])

# ---------------------------------------------------------------- privacy: nothing about a person or a request is stored
emp_row = q("SELECT name,domain_name,email FROM {p}ews_employees WHERE id=(SELECT MIN(id) FROM {p}ews_employees WHERE wp_user_id>0)")[0]
needles = [MARK, MARK + 'B', emp_row['name'], emp_row['email'], 'emp1pass']
blob = json.dumps({t: rows(t) for t in TABLES}) + json.dumps({o: opt(o) for o in ['ews_health_settings', 'ews_health_alerts', 'ews_health_findings', 'ews_health_cron_last', 'ews_health_mail']})
check('privacy scan: the marker, an employee\'s name and email and a password appear nowhere in the tables or options', not any(n and n.lower() in blob.lower() for n in needles), [n for n in needles if n and n.lower() in blob.lower()])

# ---------------------------------------------------------------- PHP errors
file = php("echo (new ReflectionClass('EWS_Manager_V31_1'))->getFileName();").strip().splitlines()[-1]
php("$o=new EWS_Manager_V31_1(); $o->health_on_php_error(E_WARNING,'Undefined array key \"Ahmed\" for ahmed@example.com',%s,10); $o->health_on_php_error(E_WARNING,'foreign',%s,5); $o->health_on_php_error(E_WARNING,'Undefined array key \"Ahmed\" for ahmed@example.com',%s,10);" % (json.dumps(file), json.dumps('/tmp/some-other-plugin/x.php'), json.dumps(file)))
er = rows('errors')
check('a PHP warning from the plugin is kept, with its value removed and counted once per place; one from another plugin is ignored',
      len(er) == 1 and er[0]['file'] == 'employee-schedule-manager.php' and int(er[0]['line']) == 10 and int(er[0]['n']) >= 2 and 'Ahmed' not in er[0]['message'] and 'ahmed@' not in er[0]['message'] and '"?"' in er[0]['message'], er)

# ---------------------------------------------------------------- a fatal error is written before WordPress ends the request
adm.req('/wp-admin/admin.php?page=ews31&e2e_fatal=1')
rf = [x for x in rows('hourly', "WHERE route='admin:ews31'") if int(x['failed']) > 0]
check('a request that dies on a fatal error is recorded as failed (WordPress ends it inside its own shutdown function)', bool(rf), [x['route'] + ':' + x['failed'] for x in rows('hourly')][:6])
php("$o=new EWS_Manager_V31_1(); $o->health_on_fatal(true,['type'=>E_ERROR,'message'=>'Uncaught Error: boom for ahmed@example.com in /home/acme/x.php:7','file'=>%s,'line'=>7]);" % json.dumps(file))
fe = [x for x in rows('errors') if x['level'] == 'error']
check('...a fatal error from the plugin\'s own file is kept, normalised, and counted as a fatal', fe and 'acme' not in fe[0]['message'] and 'ahmed@' not in fe[0]['message'] and sum(int(x['fatal']) for x in rows('hourly')) >= 1, fe[:1])

# ---------------------------------------------------------------- another plugin's requests are not the plugin's
adm.req('/wp-json/wp/v2/types')
adm.post('some_other_plugin_action', x=1)
other = [x for x in rows('hourly') if x['route'] in ('rest:other', 'post:other')]
check('a REST route or action of another plugin is recorded only when it was sampled for query detail', all(int(x['n']) == int(x['sampled']) for x in other), other)

# ---------------------------------------------------------------- scheduled jobs
php("wp_clear_scheduled_hook('ews_tasks_tick'); wp_schedule_single_event(time()-30,'ews_tasks_tick');")
adm.req('/wp-cron.php')
time.sleep(1)
cron = [x for x in rows('hourly') if x['route'] == 'cron:ews_tasks_tick']
check('a scheduled job is timed on its own by name', cron and sum(int(x['n']) for x in cron) >= 1, sorted(routes()))
check('...and its last run is kept', 'ews_tasks_tick' in (opt('ews_health_cron_last') or {}))

# ---------------------------------------------------------------- jobs scheduled with arguments, and one hook run several times in a request
for i in (11, 12, 13):
    php("wp_schedule_single_event(time()-20,'ews_break_duration_reminder',[%d]);" % i)
adm.req('/wp-cron.php')
time.sleep(1)
rem = [x for x in rows('hourly') if x['route'] == 'cron:ews_break_duration_reminder']
check('a hook run three times in one cron request counts three runs (a queue of events with arguments)', sum(int(x['n']) for x in rem) == 3, rem)
php("wp_clear_scheduled_hook('ews_push_deliver'); wp_schedule_single_event(time()-3*3600,'ews_push_deliver',[1,'uuid-late']); wp_schedule_single_event(time()+3600,'ews_push_deliver',[2,'uuid-later']);")   # after the cron run, which would have run the late one

# ---------------------------------------------------------------- one day split by a release
php("""$h=gmdate('Y-m-d H:00:00'); foreach([['3.31.109',5,100],['3.31.110',5,900]] as [$v,$n,$ms]) $wpdb->insert($p.'ews_health_hourly',['route'=>'app:schedule','hour'=>$h,'slot'=>3,'version'=>$v,'n'=>$n,'ms_sum'=>$n*$ms,'b4'=>$n,'last_at'=>gmdate('Y-m-d H:i:s')]);""")
rollup()
rollup()
dv = [x for x in rows('daily') if x['route'] == 'app:schedule' and x['day'] == time.strftime('%Y-%m-%d', time.gmtime())]
check('the rollup keeps one row per release in a day (the earlier release is not renamed), and twice gives the same', {'3.31.109', '3.31.110'} <= {x['version'] for x in dv}, dv)
php("""$wpdb->query("DELETE FROM {$p}ews_health_hourly WHERE slot=3 AND route='app:schedule' AND n=5");""")


# ---------------------------------------------------------------- the rollup: daily rows, "Look at this first", alerts
php("update_option('ews_health_settings',['repeat'=>2,'slow_ms'=>200,'min_runs'=>5,'cron_late_min'=>5,'queries'=>10,'email'=>0],false); wp_clear_scheduled_hook('ews_corrections_tick'); wp_schedule_single_event(time()-4*3600,'ews_corrections_tick');")
for i in range(4):
    emp.req('/app/?ews_view=schedule')
    adm.req('/wp-admin/admin.php?page=ews31-employees')
rollup()
rollup()
d = rows('daily')
check('the rollup builds the daily rows from the hourly ones (with the version)', d and any(x['route'] == 'admin:ews31-payroll' and int(x['n']) == 3 for x in d) and all(x['version'] for x in d), d[:1])
f = opt('ews_health_findings') or []
rules = {x['rule'] for x in f}
check('"Look at this first": a repeated query, many queries, a late job (all with thresholds lowered)', 'repeat' in rules and 'many_queries' in rules and 'cron_overdue' in rules, sorted(rules))
check('...a new PHP warning is a note', any(x['rule'] == 'new_error' and x['severity'] == 'note' for x in f))
check('...critical before warning before note', [x['severity'] for x in f] == sorted([x['severity'] for x in f], key=lambda s: ['critical', 'warning', 'note'].index(s)))
page = adm.req(SCREEN)[1]
check('the screen lists the most urgent (at most 7), with the pages table', page.count('data-finding="') == min(7, len(f)) and 'data-health-pages' in page and 'data-route="admin:ews31-payroll"' in page, (page.count('data-finding="'), len(f), 'data-health-pages' in page, 'data-route="admin:ews31-payroll"' in page))
check('...a repeated query is on the screen when it is among the urgent ones', 'repeat' not in [x['rule'] for x in f[:7]] or 'data-finding="repeat"' in page)
check('...the page names a route as a person reads it (the menu title), with the technical name beside it', 'Payroll' in page and 'admin:ews31-payroll' in page)
det = adm.req(SCREEN + '&tab=route&route=' + urllib.parse.quote('admin:ews31-employees'))[1]
check('a page in detail: its charts (once there are two days) and its queries', ('<svg' in det or 'Not enough days yet' in det) and 'Queries on this page' in det)
jobs_page = adm.req(SCREEN + '&tab=jobs')[1]
check('the jobs tab says which job is late', 'data-job="ews_corrections_tick"' in jobs_page and 'Overdue' in jobs_page)
check('...also a job that is scheduled with arguments (a push batch), without showing them', re.search(r'data-job="ews_push_deliver".*?Overdue', jobs_page, re.S) and 'uuid-late' not in jobs_page and 'uuid-later' not in jobs_page)
check('...and a queue with nothing waiting is not "not scheduled"', 'Waits for work' in jobs_page and any(x['rule'] == 'cron_overdue' and x['route'] == 'cron:ews_push_deliver' for x in opt('ews_health_findings')))
check('the queries and errors tabs open', 'data-health-queries' in adm.req(SCREEN + '&tab=queries')[1] and 'data-health-errors' in adm.req(SCREEN + '&tab=errors')[1])

# ---------------------------------------------------------------- the admin notice
page = adm.req('/wp-admin/admin.php?page=ews31')[1]
check('the admin notice shows on a Workforce One page and on the Dashboard, with the counts', 'data-health-notice' in page and 'data-health-notice' in adm.req('/wp-admin/index.php')[1])
check('...not on the System health screen itself', 'data-health-notice' not in adm.req(SCREEN)[1])
check('...not for an employee', 'data-health-notice' not in emp.req('/wp-admin/index.php')[1])
n = nonce_of(adm, '/wp-admin/admin.php?page=ews31', 'ews_health_dismiss')
adm.post('ews_health_dismiss', _wpnonce=n)
check('"Hide for 7 days" hides it', 'data-health-notice' not in adm.req('/wp-admin/admin.php?page=ews31')[1])

# ---------------------------------------------------------------- the optional email
mailf = os.path.join(MU, 'ews-e2e-health-mail.txt')
check('no email while the setting is off', not os.path.exists(mailf) or not open(mailf).read().strip())
sn = nonce_of(adm, SCREEN + '&tab=settings', 'ews_health_settings_save')
loc = adm.post('ews_health_settings_save', _wpnonce=sn, health_slow_ms=200, health_repeat=2, health_min_runs=5, health_cron_late_min=5, health_queries=10, health_notice=1, health_email=1, health_email_to='boss@example.com')[1]
check('the settings are saved, clamped', opt('ews_health_settings')['email'] == 1 and opt('ews_health_settings')['email_to'] == 'boss@example.com' and opt('ews_health_settings')['slow_ms'] == 200, loc)
rollup()
mails = [json.loads(l) for l in open(mailf)] if os.path.exists(mailf) else []
check('one email with the new alerts: page names and numbers, a link, no query, no file path', len(mails) == 1 and mails[0]['to'] == 'boss@example.com' and 'ews31-system-health' in mails[0]['message'] and ' FROM ' not in mails[0]['message'].upper() and 'trait-' not in mails[0]['message'] and 'includes/' not in mails[0]['message'], mails[:1])
rollup()
mails = [json.loads(l) for l in open(mailf)]
check('...the next rollup sends none for the same alerts (still open)', len(mails) == 1)

# ---------------------------------------------------------------- permissions
st, body, _ = emp.req(SCREEN)
loc = emp.post('ews_health_settings_save', _wpnonce=sn, health_slow_ms=99999)[1]
pn0 = nonce_of(adm, SCREEN + '&tab=settings', 'ews_health_purge')
check('an employee cannot change the settings or delete the data', opt('ews_health_settings')['slow_ms'] == 200 and emp.post('ews_health_purge', _wpnonce=pn0)[0] != 302 and rows('hourly'))

# ---------------------------------------------------------------- a schema mismatch is repaired by the rollup; a missing table breaks nothing
php("update_option('ews_health_schema','0',false);")
page = adm.req('/wp-admin/admin.php?page=ews31')
check('with the schema version behind, nothing is collected and the page still opens', page[0] == 200 and 'WordPress database error' not in page[1])
rollup()
check('...and the rollup repairs it', opt('ews_health_schema') == '1' and all(exists(t) for t in TABLES))
php("$wpdb->query(\"DROP TABLE {$p}ews_health_hourly\");")
st, body, _ = adm.req('/wp-admin/admin.php?page=ews31')
check('a missing table does not break a request or print a database error', st == 200 and 'WordPress database error' not in body)
php("delete_option('ews_health_schema');")
rollup()
check('...and the rollup creates it again', exists('hourly'))

# ---------------------------------------------------------------- retention and deleting
php("""$wpdb->insert($p.'ews_health_hourly',['route'=>'old:x','hour'=>gmdate('Y-m-d H:00:00',time()-9*86400),'slot'=>0,'n'=>1,'last_at'=>gmdate('Y-m-d H:i:s')]);
    $wpdb->insert($p.'ews_health_daily',['day'=>gmdate('Y-m-d',time()-200*86400),'route'=>'old:x','version'=>'1','n'=>1,'last_at'=>gmdate('Y-m-d H:i:s')]);
    $wpdb->insert($p.'ews_health_queries',['day'=>gmdate('Y-m-d',time()-40*86400),'route'=>'old:x','fp'=>'0123456789abcdef','n'=>1]);
    $wpdb->insert($p.'ews_health_errors',['day'=>gmdate('Y-m-d',time()-40*86400),'fp'=>'0123456789abcdef','route'=>'old:x','n'=>1,'first_at'=>gmdate('Y-m-d H:i:s'),'last_at'=>gmdate('Y-m-d H:i:s')]);""")
rollup()
check('retention: hourly rows older than 7 days, daily older than the setting, query and error detail older than 30 days are deleted', not any(rows(t, "WHERE route='old:x'") for t in TABLES), [t for t in TABLES if rows(t, "WHERE route='old:x'")])
check('...and what is recent stays', rows('hourly') and rows('daily'))
pn = nonce_of(adm, SCREEN + '&tab=settings', 'ews_health_purge')
adm.post('ews_health_purge', _wpnonce=pn)
check('"Delete collected data" empties the four tables (but for the request that deleted them and the wp-cli calls of this test) and is audited', all(x['route'] in ('post:ews_health_purge', 'other:front') for t in TABLES for x in rows(t)) and q("SELECT id FROM {p}ews_audit_log WHERE action='health_data_deleted'"))

# ---------------------------------------------------------------- overhead: what the collector adds to a request (design: docs/tasks/system-health.md §7)
def measure(tag, n=20):
    for i in range(n):
        adm.req('/wp-admin/admin.php?page=ews31&e2e_tag=%s' % tag)
        emp.req('/app/?ews_view=schedule&e2e_tag=%s' % tag)
        adm.req('/wp-login.php?e2e_tag=%s_foreign' % tag)
    t = take('')
    pick = lambda name: [x for x in t if x[0] == name]
    return pick(tag), pick(tag + '_foreign')


features(adm, True, 1, 90)
on1, foreign_on1 = measure('on1')
features(adm, True, 100, 90)
on100, foreign_on100 = measure('on100')
off_q = [x[1] for x in off if x[0] == 'off']
foreign_off = [x[1] for x in off if x[0] == 'foreign_off']
q1, q100 = [x[1] for x in on1], [x[1] for x in on100]
check('queries added to a Workforce One request at 1%% sampling: at most one (off median %d, on median %d)' % (median(off_q), median(q1)), median(q1) - median(off_q) <= 1, (off_q, q1))
check('...at 100%% sampling the added queries are the one UPDATE plus a bounded number of detail rows (median %d)' % median(q100), median(q100) - median(off_q) <= 30, q100)
check('...a request that is not the plugin\'s adds none (sign-in page: %d queries off, %d on)' % (median(foreign_off), median([x[1] for x in foreign_on1])), median([x[1] for x in foreign_on1]) <= median(foreign_off), (foreign_off, [x[1] for x in foreign_on1]))
off_ms = [x[2] for x in off if x[0] == 'off']
off_mem = [x[3] for x in off if x[0] == 'off']
ms1, ms100 = median([x[2] for x in on1]), median([x[2] for x in on100])
print('INFO time per request: off %.1f ms, on 1%% %.1f ms, on 100%% %.1f ms; peak memory: off %d KB, on 1%% %d KB, on 100%% %d KB' % (median(off_ms), ms1, ms100, median(off_mem), median([x[3] for x in on1]), median([x[3] for x in on100])))
check('time: the median request at 1%% is at most 1.5x the off median plus 25 ms (%.1f vs %.1f)' % (ms1, median(off_ms)), ms1 <= median(off_ms) * 1.5 + 25)
check('...at 100%% (query detail on every request) at most 2.5x plus 40 ms (%.1f)' % ms100, ms100 <= median(off_ms) * 2.5 + 40)
check('peak memory: at most 8 MB more at 100%% than off', median([x[3] for x in on100]) - median(off_mem) <= 8192, (median(off_mem), median([x[3] for x in on100])))

# 3.31.112: the daily rows are written in a few statements, not one INSERT per route
php("""$h=gmdate('Y-m-d H:00:00'); $row=function($r,$v,$n)use($h){return ['route'=>$r,'hour'=>$h,'slot'=>4,'version'=>$v,'n'=>$n,'ms_sum'=>$n*100,'b2'=>$n,'last_at'=>gmdate('Y-m-d H:i:s')];};
for($i=0;$i<40;$i++) $wpdb->insert($p.'ews_health_hourly',$row('batch:r'.$i,'3.31.111',2));
$wpdb->insert($p.'ews_health_hourly',$row('batch:dup','',1)); $wpdb->insert($p.'ews_health_hourly',$row('batch:dup',EWS_VERSION,3));""")
ins = php("""$GLOBALS['ins']=0; add_filter('query',function($q)use($p){ if(preg_match('/^\\s*INSERT INTO `?'.$p.'ews_health_daily/i',$q))$GLOBALS['ins']++; return $q;}); delete_option('ews_health_purged'); $o=new EWS_Manager_V31_1(); $o->health_rollup(); echo 'INS='.$GLOBALS['ins'];""")
n_ins = int(re.search(r'INS=(\d+)', ins).group(1))
dd = [x for x in rows('daily') if x['route'].startswith('batch:') and x['day'] == time.strftime('%Y-%m-%d', time.gmtime())]
check('the rollup writes its daily rows in a few INSERTs (about 2 for 42 routes, not one per route)', 0 < n_ins <= 3, ins)
check('...every route is there once, and the "" release duplicate keeps the first row as before', len([x for x in dd if x['route'] != 'batch:dup']) == 40 and [int(x['n']) for x in dd if x['route'] == 'batch:dup'] == [1], dd[-2:])
php("""$wpdb->query("DELETE FROM {$p}ews_health_hourly WHERE route LIKE 'batch:%'"); $wpdb->query("DELETE FROM {$p}ews_health_daily WHERE route LIKE 'batch:%'");""")

# ---------------------------------------------------------------- switching off
features(adm, False, 1, 90)
before = sum(int(x['n']) for x in rows('hourly'))
for i in range(3):
    adm.req('/wp-admin/admin.php?page=ews31')
    emp.req('/app/?ews_view=schedule')
check('switched off: the rollup is unscheduled, new requests add nothing, collected data is kept', php("echo wp_next_scheduled('ews_health_rollup')?1:0;").strip().splitlines()[-1] == '0' and sum(int(x['n']) for x in rows('hourly')) == before and exists('hourly'))
check('...the audit log has it, and the screen says it is off', q("SELECT id FROM {p}ews_audit_log WHERE action='health_disabled'") and 'System health is off' in adm.req(SCREEN)[1])

php("$d=WPMU_PLUGIN_DIR; @unlink($d.'/ews-e2e-health-mu.php'); @unlink($d.'/ews-e2e-health-mail.txt'); @unlink($d.'/ews-e2e-health-q.txt');")
reset()
print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)

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
check('...a run lands in a duration bucket', sum(sum(int(x['b%d' % i]) for i in range(12)) for x in pay) == 3)
check('...no route carries a value from the request', all(re.match(r'^[a-z]+:[A-Za-z0-9_\-/{} :]+$', x) for x in r) and not any(MARK.lower() in x.lower() for x in r))
qs = rows('queries')
check('sampled requests keep the shape of their queries with the caller', qs and any(x['caller'] and x['shape'] for x in qs), qs[:2])
check('...literals are removed from every shape', not any(re.search(r"'[^?']|\b\d{2,}\b", x['shape'].replace('{p}', '')) for x in qs), [x['shape'] for x in qs if re.search(r"'[^?']|\b\d{2,}\b", x['shape'].replace('{p}', ''))][:2])

# ---------------------------------------------------------------- privacy: nothing about a person or a request is stored
emp_row = q("SELECT name,domain_name,email FROM {p}ews_employees WHERE id=(SELECT MIN(id) FROM {p}ews_employees WHERE wp_user_id>0)")[0]
needles = [MARK, emp_row['name'], emp_row['email'], 'emp1pass']
blob = json.dumps({t: rows(t) for t in TABLES}) + json.dumps({o: opt(o) for o in ['ews_health_settings', 'ews_health_alerts', 'ews_health_findings', 'ews_health_cron_last', 'ews_health_mail']})
check('privacy scan: the marker, an employee\'s name and email and a password appear nowhere in the tables or options', not any(n and n.lower() in blob.lower() for n in needles), [n for n in needles if n and n.lower() in blob.lower()])

# ---------------------------------------------------------------- PHP errors
file = php("echo (new ReflectionClass('EWS_Manager_V31_1'))->getFileName();").strip().splitlines()[-1]
php("$o=new EWS_Manager_V31_1(); $o->health_on_php_error(E_WARNING,'Undefined array key \"Ahmed\" for ahmed@example.com',%s,10); $o->health_on_php_error(E_WARNING,'foreign',%s,5); $o->health_on_php_error(E_WARNING,'Undefined array key \"Ahmed\" for ahmed@example.com',%s,10);" % (json.dumps(file), json.dumps('/tmp/some-other-plugin/x.php'), json.dumps(file)))
er = rows('errors')
check('a PHP warning from the plugin is kept, with its value removed and counted once per place; one from another plugin is ignored',
      len(er) == 1 and er[0]['file'] == 'employee-schedule-manager.php' and int(er[0]['line']) == 10 and int(er[0]['n']) >= 2 and 'Ahmed' not in er[0]['message'] and 'ahmed@' not in er[0]['message'] and '"?"' in er[0]['message'], er)

# ---------------------------------------------------------------- scheduled jobs
php("wp_clear_scheduled_hook('ews_tasks_tick'); wp_schedule_single_event(time()-30,'ews_tasks_tick');")
adm.req('/wp-cron.php')
time.sleep(1)
cron = [x for x in rows('hourly') if x['route'] == 'cron:ews_tasks_tick']
check('a scheduled job is timed on its own by name', cron and sum(int(x['n']) for x in cron) >= 1, sorted(routes()))
check('...and its last run is kept', 'ews_tasks_tick' in (opt('ews_health_cron_last') or {}))

# ---------------------------------------------------------------- the rollup: daily rows, "Look at this first", alerts
php("update_option('ews_health_settings',['repeat'=>2,'slow_ms'=>200,'min_runs'=>5,'cron_late_min'=>5,'queries'=>10,'email'=>0],false); wp_clear_scheduled_hook('ews_corrections_tick'); wp_schedule_single_event(time()-4*3600,'ews_corrections_tick');")
for i in range(4):
    emp.req('/app/?ews_view=schedule')
    adm.req('/wp-admin/admin.php?page=ews31-employees')
rollup()
d = rows('daily')
check('the rollup builds the daily rows from the hourly ones (with the version)', d and any(x['route'] == 'admin:ews31-payroll' and int(x['n']) == 3 for x in d) and all(x['version'] for x in d), d[:1])
f = opt('ews_health_findings') or []
rules = {x['rule'] for x in f}
check('"Look at this first": a repeated query, many queries, a late job (all with thresholds lowered)', 'repeat' in rules and 'many_queries' in rules and 'cron_overdue' in rules, sorted(rules))
check('...a new PHP warning is a note', any(x['rule'] == 'new_error' and x['severity'] == 'note' for x in f))
check('...critical before warning before note', [x['severity'] for x in f] == sorted([x['severity'] for x in f], key=lambda s: ['critical', 'warning', 'note'].index(s)))
page = adm.req(SCREEN)[1]
check('the screen lists them, with the pages table', 'data-finding="repeat"' in page and 'data-health-pages' in page and 'data-route="admin:ews31-payroll"' in page)
check('...the page names a route as a person reads it (the menu title), with the technical name beside it', 'Payroll' in page and 'admin:ews31-payroll' in page)
det = adm.req(SCREEN + '&tab=route&route=' + urllib.parse.quote('admin:ews31-employees'))[1]
check('a page in detail: its charts (once there are two days) and its queries', ('<svg' in det or 'Not enough days yet' in det) and 'Queries on this page' in det)
check('the jobs tab says which job is late', 'data-job="ews_corrections_tick"' in adm.req(SCREEN + '&tab=jobs')[1] and 'Overdue' in adm.req(SCREEN + '&tab=jobs')[1])
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

# ---------------------------------------------------------------- overhead: queries added to a request
features(adm, True, 1, 90)
on = []
for i in range(10):
    adm.req('/wp-admin/admin.php?page=ews31&e2e_tag=on1')
    emp.req('/app/?ews_view=schedule&e2e_tag=on1')
    adm.req('/wp-login.php?e2e_tag=foreign')
tags = take('')
on1 = [x[1] for x in tags if x[0] == 'on1']
foreign_on = [x[1] for x in tags if x[0] == 'foreign']
off_q = [x[1] for x in off if x[0] == 'off']
foreign_off = [x[1] for x in off if x[0] == 'foreign_off']
check('queries added to a Workforce One request at 1%% sampling: at most one on average (off median %d, on median %d)' % (median(off_q), median(on1)), median(on1) - median(off_q) <= 1, (off_q, on1))
check('...a request that is not the plugin\'s adds none (sign-in page: %d queries off, %d on)' % (median(foreign_off), median(foreign_on)), median(foreign_on) <= median(foreign_off), (foreign_off, foreign_on))
off_ms = [x[2] for x in off if x[0] == 'off']
on_ms = [x[2] for x in tags if x[0] == 'on1']
print('INFO time per request: off median %.1f ms, on (1%%) median %.1f ms' % (median(off_ms), median(on_ms)))

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

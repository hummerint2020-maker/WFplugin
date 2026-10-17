#!/usr/bin/env python3
"""Concurrent Sign In load test: how many employees can press Sign In in the same second?

The 3.31.71 load test timed each page on its own. This one measures the hardest minute of the
day: the start of a shift, when many employees sign in at once. It uses the real Web form
(admin-post.php, action ews31_time_event) with a logged-in session per employee, so every
request goes through the nonce check, the per-employee lock, the rules, the insert, the
achievements and the audit log, exactly as on a phone.

Three steps:

  1. seed     (on the server, needs wp-cli) creates N test employees "wfo_lt_00000" … with a
              WordPress user, an Office schedule for today and a test work location, and opens
              the sign-in window (face and QR sign-in off). Running it again resets today's
              sign-ins of the test employees, so the same users can be used for another run.
  2. run      (from ANOTHER machine, so the load generator does not take the server's CPU)
              logs every test employee in, then fires their Sign In together, level by level
              (e.g. 50, then 100, then 200 at once), and prints the response times.
  3. cleanup  (on the server) removes the test employees, their users and rows, the test
              location, and puts back the options that seed changed.

  count       (on the server) checks the database after a run: sign-ins recorded today for the
              test employees, and any duplicates.

NEVER run seed against a live site: it switches face and QR sign-in off and moves the working
hours for the whole site until cleanup. Use a staging copy on the same kind of server.

Examples:
  WP_CLI="wp --path=/var/www/html" python3 tools/load_signin.py seed --count 1000
  python3 tools/load_signin.py run --url https://staging.example.com --levels 50,100,200,400
  WP_CLI="wp --path=/var/www/html" python3 tools/load_signin.py count
  WP_CLI="wp --path=/var/www/html" python3 tools/load_signin.py cleanup

Levels use different employees (50 + 100 + 200 + 400 = 750 here), so seed at least that many.
Only the Python standard library is used.
"""
import argparse, http.cookiejar, json, os, random, re, shlex, ssl, subprocess, sys, threading, time
import urllib.error, urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor

PREFIX = 'wfo_lt_'
PASSWORD = 'wfo-load-test-pass'
# ~15 m from the test location that seed creates (30.0444, 31.2357, radius 200 m).
LAT, LNG = '30.0445', '31.2358'


def login_name(prefix, i):
    return '%s%05d' % (prefix, i)


# ------------------------------------------------------------------------------ server side

def wp(code, **env):
    cmd = shlex.split(os.environ.get('WP_CLI', 'wp')) + ['eval', code]
    r = subprocess.run(cmd, capture_output=True, text=True,
                       env={**os.environ, **{k: str(v) for k, v in env.items()}})
    if r.returncode:
        sys.exit('wp-cli failed:\n' + (r.stderr or r.stdout)[-3000:])
    return r.stdout.strip()


PHP_PREPARE = r"""
global $wpdb; $p=$wpdb->prefix; $pre=getenv('LT_PREFIX');
$st=get_option('wfo_loadtest_state');
if($st && $st['prefix']!==$pre){ fwrite(STDERR,"A load test with prefix {$st['prefix']} is still seeded; run cleanup first.\n"); exit(1); }
if(!$st){
    $bk=[]; foreach(['ews_feature_face_signin','ews_presence_qr_signin','ews_working_hours','ews_working_days'] as $k) $bk[$k]=get_option($k,null);
    $wpdb->insert($p.'ews_locations',['name'=>'Load Test Site','latitude'=>30.0444,'longitude'=>31.2357,'radius'=>200,'enforcement'=>0,'is_default'=>0,'active'=>1]);
    if(!$wpdb->insert_id){ fwrite(STDERR,"Could not create the test location: {$wpdb->last_error}\n"); exit(1); }
    $st=['prefix'=>$pre,'backup'=>$bk,'location_id'=>(int)$wpdb->insert_id];
    update_option('wfo_loadtest_state',$st,false);
}
update_option('ews_feature_face_signin',0,false);
update_option('ews_presence_qr_signin',0,false);
update_option('ews_working_days',[0,1,2,3,4,5,6],false);
$wh=get_option('ews_working_hours',[]); if(!is_array($wh)) $wh=[];
$h=(int)current_time('G'); $wh['start']=sprintf('%02d:00',max(0,$h-1)); $wh['end']='23:59';
update_option('ews_working_hours',$wh,false);
echo wp_json_encode(['location_id'=>$st['location_id'],'today'=>current_time('Y-m-d'),'local_time'=>current_time('H:i')]);
"""

PHP_USERS = r"""
global $wpdb; $p=$wpdb->prefix; $pre=getenv('LT_PREFIX');
$st=get_option('wfo_loadtest_state'); $lid=(int)$st['location_id'];
$hash=wp_hash_password(getenv('LT_PASS')); $day=current_time('Y-m-d'); $made=0;
for($i=(int)getenv('LT_FROM');$i<(int)getenv('LT_TO');$i++){
    $login=sprintf('%s%05d',$pre,$i);
    $uid=(int)username_exists($login);
    if(!$uid){
        $wpdb->insert($wpdb->users,['user_login'=>$login,'user_pass'=>$hash,'user_nicename'=>$login,'user_email'=>$login.'@loadtest.invalid','user_registered'=>current_time('mysql',true),'display_name'=>'Load Test '.$i]);
        $uid=(int)$wpdb->insert_id;
        update_user_meta($uid,$p.'capabilities',['subscriber'=>true]);
        update_user_meta($uid,$p.'user_level',0);
        $made++;
    } else {
        $wpdb->update($wpdb->users,['user_pass'=>$hash],['ID'=>$uid]); clean_user_cache($uid);
    }
    $eid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d",$uid));
    if(!$eid){
        $wpdb->insert($p.'ews_employees',['name'=>'Load Test '.$i,'domain_name'=>$login,'email'=>$login.'@loadtest.invalid','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
        $eid=(int)$wpdb->insert_id;
        $wpdb->insert($p.'ews_employee_locations_v321',['employee_id'=>$eid,'location_id'=>$lid]);
    }
    $wpdb->delete($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>$day]);
    $wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>$day,'status'=>'Office']);
    $wpdb->delete($p.'ews_time_logs',['employee_id'=>$eid,'work_date'=>$day]);
}
echo $made;
"""

PHP_COUNT = r"""
global $wpdb; $p=$wpdb->prefix; $st=get_option('wfo_loadtest_state');
if(!$st){ echo wp_json_encode(null); return; }
$like=$wpdb->esc_like($st['prefix']).'%'; $day=current_time('Y-m-d');
$r=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) sign_ins, COUNT(DISTINCT t.employee_id) employees
    FROM {$p}ews_time_logs t JOIN {$p}ews_employees e ON e.id=t.employee_id
    WHERE e.domain_name LIKE %s AND t.work_date=%s AND t.event_type='sign_in'",$like,$day),ARRAY_A);
$r['test_employees']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ews_employees WHERE domain_name LIKE %s",$like));
$r['today']=$day;
echo wp_json_encode($r);
"""

PHP_CLEANUP = r"""
global $wpdb; $p=$wpdb->prefix; $st=get_option('wfo_loadtest_state');
if(!$st){ echo "nothing seeded\n"; return; }
$like=$wpdb->esc_like($st['prefix']).'%';
$eids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE domain_name LIKE %s",$like)));
$uids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s",$like)));
$rows=0;
foreach($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->esc_like($p.'ews_').'%')) as $t){
    $cols=$wpdb->get_col("SHOW COLUMNS FROM `$t`");
    foreach(['employee_id'=>$eids,'user_id'=>$uids,'wp_user_id'=>$uids] as $c=>$ids){
        if(!$ids || !in_array($c,$cols,true)) continue;
        foreach(array_chunk($ids,500) as $ch) $rows+=(int)$wpdb->query("DELETE FROM `$t` WHERE `$c` IN (".implode(',',$ch).")");
    }
}
foreach(array_chunk($eids,500) as $ch) $rows+=(int)$wpdb->query("DELETE FROM {$p}ews_employees WHERE id IN (".implode(',',$ch).")");
foreach(array_chunk($uids,500) as $ch){
    $in=implode(',',$ch);
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id IN ($in)");
    $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID IN ($in)");
}
foreach($uids as $u) clean_user_cache($u);
$wpdb->delete($p.'ews_locations',['id'=>(int)$st['location_id']]);
foreach($st['backup'] as $k=>$v){ if($v===null) delete_option($k); else update_option($k,$v,false); }
delete_option('wfo_loadtest_state');
echo 'removed '.count($eids).' test employees, '.count($uids).' users, '.$rows." rows; options restored\n";
"""


def cmd_seed(a):
    info = json.loads(wp(PHP_PREPARE, LT_PREFIX=a.prefix).splitlines()[-1])
    print('Site day %(today)s, local time %(local_time)s; sign-in window opened, test location #%(location_id)s.' % info)
    made, t0 = 0, time.time()
    for start in range(0, a.count, a.batch):
        end = min(a.count, start + a.batch)
        made += int(wp(PHP_USERS, LT_PREFIX=a.prefix, LT_PASS=a.password, LT_FROM=start, LT_TO=end).splitlines()[-1])
        print('  %d / %d employees ready' % (end, a.count), flush=True)
    print('Seeded %d test employees (%d new) in %.0f s. Today\'s sign-ins of these employees are cleared.'
          % (a.count, made, time.time() - t0))
    if info['local_time'] >= '23:00':
        print('Warning: the site day ends within the hour; run before midnight site time.')


def cmd_count(a):
    print(json.dumps(json.loads(wp(PHP_COUNT).splitlines()[-1]), indent=2))


def cmd_cleanup(a):
    print(wp(PHP_CLEANUP))


# ------------------------------------------------------------------------------ client side

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Employee:
    """One phone: its own cookies, its own connection per request (like a phone on mobile data)."""

    def __init__(self, base, login, password, timeout, ctx):
        self.base, self.login, self.password, self.timeout = base.rstrip('/'), login, password, timeout
        self.jar = http.cookiejar.CookieJar()
        handlers = [urllib.request.HTTPCookieProcessor(self.jar), NoRedirect]
        if ctx is not None:
            handlers.append(urllib.request.HTTPSHandler(context=ctx))
        self.op = urllib.request.build_opener(*handlers)
        self.nonce = None

    def req(self, path, data=None):
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
        r = urllib.request.Request(self.base + path, data=data, headers={'User-Agent': 'WorkforceOne-LoadTest/1'})
        try:
            with self.op.open(r, timeout=self.timeout) as resp:
                return resp.status, resp.read().decode('utf-8', 'replace'), dict(resp.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)

    def sign_in_page(self, app_path):
        """Log in and read the Sign In form's nonce (session-bound). Returns seconds or raises."""
        t0 = time.perf_counter()
        self.req('/wp-login.php')
        st, body, _ = self.req('/wp-login.php', {'log': self.login, 'pwd': self.password, 'testcookie': '1'})
        if not any(c.name.startswith('wordpress_logged_in_') for c in self.jar):
            raise RuntimeError('login failed (HTTP %s)' % st)
        took = time.perf_counter() - t0
        st, page, _ = self.req(app_path + ('&' if '?' in app_path else '?') + 'ews_view=time')
        for form in re.findall(r'<form.*?</form>', page, re.S):
            if 'name="event_type" value="sign_in"' in form and 'value="ews31_time_event"' in form:
                m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
                if m:
                    self.nonce = m.group(1)
                    return took
        raise RuntimeError('no Sign In form on %s (HTTP %s)' % (app_path, st))

    def sign_in(self):
        data = {'action': 'ews31_time_event', 'event_type': 'sign_in', '_wpnonce': self.nonce, 'face_verified': '0',
                'latitude': LAT, 'longitude': LNG, 'accuracy': '20',
                'location_timestamp': str(int(time.time() * 1000))}
        t0 = time.perf_counter()
        try:
            st, body, h = self.req('/wp-admin/admin-post.php', data)
        except Exception as e:  # timeouts, resets, refused connections
            return time.perf_counter() - t0, 'error', type(e).__name__ + ': ' + str(e)[:120]
        took = time.perf_counter() - t0
        q = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
        if q.get('time_success'):
            return took, 'ok', q['time_success'][0]
        if q.get('time_error'):
            return took, 'rejected', q['time_error'][0]
        return took, 'error', 'HTTP %s %s' % (st, re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body))[:120])


def pct(values, p):
    if not values:
        return float('nan')
    s = sorted(values)
    return s[min(len(s) - 1, max(0, int(round(p / 100.0 * len(s) + 0.5)) - 1))]


def run_level(a, ctx, start, size):
    emps = [Employee(a.url, login_name(a.prefix, i), a.password, a.timeout, ctx) for i in range(start, start + size)]

    # Log everyone in first (not part of the burst; most phones stay signed in to the app).
    login_times, failed = [], []
    def prep(e):
        try:
            login_times.append(e.sign_in_page(a.app_path))
        except Exception as ex:
            failed.append('%s: %s' % (e.login, ex))
    with ThreadPoolExecutor(max_workers=a.login_workers) as ex:
        list(ex.map(prep, emps))
    if failed:
        print('  %d of %d could not log in, e.g. %s' % (len(failed), size, failed[0]))
    ready = [e for e in emps if e.nonce]
    if not ready:
        return None

    # The burst: every thread waits at the barrier, then signs in (spread over --ramp seconds).
    barrier = threading.Barrier(len(ready) + 1)
    results = []
    lock = threading.Lock()
    def fire(e, delay):
        barrier.wait()
        if delay:
            time.sleep(delay)
        r = e.sign_in()
        with lock:
            results.append(r)
    threads = [threading.Thread(target=fire, args=(e, random.uniform(0, a.ramp) if a.ramp else 0), daemon=True) for e in ready]
    for t in threads:
        t.start()
    time.sleep(0.5)                      # let every thread reach the barrier
    t0 = time.perf_counter()
    barrier.wait()
    for t in threads:
        t.join()
    wall = time.perf_counter() - t0

    ok = [r[0] for r in results if r[1] == 'ok']
    reasons = {}
    for r in results:
        if r[1] != 'ok':
            reasons[r[2]] = reasons.get(r[2], 0) + 1
    all_t = [r[0] for r in results]
    return {'level': size, 'ready': len(ready), 'login_failed': len(failed), 'ok': len(ok),
            'rejected': sum(1 for r in results if r[1] == 'rejected'), 'errors': sum(1 for r in results if r[1] == 'error'),
            'p50': pct(all_t, 50), 'p95': pct(all_t, 95), 'p99': pct(all_t, 99), 'max': max(all_t),
            'wall': wall, 'per_sec': len(ok) / wall if wall else 0,
            'login_p50': pct(login_times, 50), 'login_p95': pct(login_times, 95), 'reasons': reasons}


def cmd_run(a):
    levels = [int(x) for x in a.levels.split(',') if x.strip()]
    ctx = None
    if a.url.startswith('https://') and a.insecure:
        ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    need = a.offset + sum(levels)
    print('Levels %s use employees %d…%d (seed --count %d or more). Ramp: %s.'
          % (levels, a.offset, need - 1, need, '%g s' % a.ramp if a.ramp else 'all at once'))
    rows, start = [], a.offset
    for size in levels:
        print('\nLevel %d: logging in…' % size, flush=True)
        r = run_level(a, ctx, start, size)
        start += size
        if not r:
            print('  nobody could log in; stopping.')
            break
        rows.append(r)
        print('  %(ok)d ok, %(rejected)d rejected, %(errors)d errors | p50 %(p50).2fs p95 %(p95).2fs '
              'max %(max).2fs | all done in %(wall).2fs (%(per_sec).1f sign-ins/s)' % r)
        for msg, n in sorted(r['reasons'].items(), key=lambda kv: -kv[1])[:5]:
            print('    %4d × %s' % (n, msg))
        bad = (r['rejected'] + r['errors']) / float(r['ready'])
        if bad > a.max_fail:
            print('  More than %d%% failed; not going higher.' % (a.max_fail * 100))
            break
        if a.pause and size != levels[-1]:
            time.sleep(a.pause)

    print('\n| At once | OK | Failed | p50 | p95 | p99 | Max | All done in | Sign-ins/s |')
    print('|---|---|---|---|---|---|---|---|---|')
    for r in rows:
        print('| %d | %d | %d | %.2f s | %.2f s | %.2f s | %.2f s | %.2f s | %.1f |' % (
            r['ready'], r['ok'], r['rejected'] + r['errors'], r['p50'], r['p95'], r['p99'], r['max'], r['wall'], r['per_sec']))
    if rows:
        print('\nLogin (before the burst): p50 %.2f s, p95 %.2f s.' % (rows[-1]['login_p50'], rows[-1]['login_p95']))
    if a.json:
        with open(a.json, 'w') as f:
            json.dump({'url': a.url, 'ramp': a.ramp, 'levels': rows, 'at': time.strftime('%Y-%m-%d %H:%M:%S')}, f, indent=2)
        print('Saved %s' % a.json)
    print('Next: after a run, `count` on the server should show one sign-in per successful employee.')


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    for name in ('seed', 'run', 'count', 'cleanup'):
        p = sub.add_parser(name)
        p.add_argument('--prefix', default=PREFIX, help='login prefix of the test users (default %(default)s)')
        if name in ('seed', 'run'):
            p.add_argument('--password', default=PASSWORD, help='password of every test user')
        if name == 'seed':
            p.add_argument('--count', type=int, required=True, help='how many test employees')
            p.add_argument('--batch', type=int, default=500, help='employees per wp-cli call')
        if name == 'run':
            p.add_argument('--url', required=True, help='site address, e.g. https://staging.example.com')
            p.add_argument('--app-path', default='/app/', help='path of the page with [employee_app] (default %(default)s)')
            p.add_argument('--levels', default='25,50,100,200', help='employees signing in at once, per level')
            p.add_argument('--offset', type=int, default=0, help='first test employee to use')
            p.add_argument('--ramp', type=float, default=0, help='spread each burst over this many seconds (0 = same moment)')
            p.add_argument('--login-workers', type=int, default=20, help='parallel logins while preparing')
            p.add_argument('--timeout', type=float, default=30, help='seconds before a request counts as an error')
            p.add_argument('--max-fail', type=float, default=0.05, help='stop when more than this share fails')
            p.add_argument('--pause', type=float, default=5, help='seconds between levels')
            p.add_argument('--insecure', action='store_true', help='accept a self-signed HTTPS certificate (staging only)')
            p.add_argument('--json', help='also save the results to this file')
    a = ap.parse_args()
    {'seed': cmd_seed, 'run': cmd_run, 'count': cmd_count, 'cleanup': cmd_cleanup}[a.cmd](a)


if __name__ == '__main__':
    main()

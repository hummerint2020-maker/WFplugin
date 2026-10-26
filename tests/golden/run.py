"""Golden harness (3.31.127, docs/tasks/layered-modules.md §2.2): the old and the new code, the same site, the same data.

For each side (the base's plugin folder, then this tree's): the plugin link points at it, the database is put back to one
snapshot, a PHP server is started, and every page of pages.json is fetched with the same login cookies; then the actions
(actions.php) run from the snapshot again. The pass:
  - every page's HTML is the same (after pages.json's short "normalise" list: the version), and the base gives the same
    HTML twice (else the page is not stable enough to compare and says so);
  - the new code runs no more queries per page than the old;
  - the actions leave the same trace (what each call returned, the rows written, times as placeholders);
  - the hooks, admin-post actions, shortcodes and REST routes the plugin registers are the same (a text inventory).
Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/golden/run.py <state-dir> --base <base-plugin-dir> [--keep]
Needs the port 8080 free (it starts and stops its own server) and the tests/e2e_setup.php users. Never on a real site.
"""
import difflib, json, os, re, shlex, subprocess, sys, time, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
TESTS = os.path.dirname(HERE)
sys.path.insert(0, TESTS)
from e2e_support import Session, check, results  # noqa: E402

S = sys.argv[1]
BASE = os.path.abspath(sys.argv[sys.argv.index('--base') + 1])
HEAD = os.path.abspath(os.path.join(TESTS, '..', 'workforce-one'))
WP = shlex.split(os.environ.get('WP_CLI', 'wp'))
CFG = json.load(open(os.path.join(HERE, 'pages.json')))
EXPECTED = json.load(open(os.path.join(HERE, 'expected.json')))
if not (os.environ.get('CI') or os.environ.get('GOLDEN_OK')):
    sys.exit('the golden harness empties every ews_* table: it runs in CI, or with GOLDEN_OK=1 on a test site')


def wp(*args, env=None):
    r = subprocess.run(WP + list(args), capture_output=True, text=True, env={**os.environ, 'S': S, **(env or {})})
    if r.returncode:
        raise RuntimeError('wp ' + ' '.join(args[:2]) + '\n' + r.stderr[-2000:])
    return r.stdout.strip()


def php(code):
    return wp('eval', code)


ABSPATH = php('echo rtrim(ABSPATH,"/");').splitlines()[-1]
LINK = php('echo WP_PLUGIN_DIR;').splitlines()[-1] + '/workforce-one'
MU = php('echo WPMU_PLUGIN_DIR;').splitlines()[-1]
SITE = php('echo home_url();').splitlines()[-1]
if not re.match(r'^https?://(127\.0\.0\.1|localhost)(:\d+)?/?$', SITE):
    sys.exit('not a test site (%s): the golden harness runs only on 127.0.0.1' % SITE)
if not os.path.islink(LINK):
    sys.exit('the plugin folder must be a link (%s): the harness points it at each side' % LINK)
ORIGINAL = os.readlink(LINK)
SNAP = os.path.join(S, 'golden-snapshot.json')
server = None


def point(target):
    tmp = LINK + '.golden-tmp'
    if os.path.lexists(tmp):
        os.unlink(tmp)
    os.symlink(target, tmp)
    os.replace(tmp, LINK)


def port_free():
    import socket
    with socket.socket() as s:
        return s.connect_ex(('127.0.0.1', 8080)) != 0


def start():
    global server
    if not port_free():
        sys.exit('the port 8080 is taken: the golden harness starts its own server (stop the other one)')
    # A fresh process for each side: no file or realpath cache of the other side's code.
    server = subprocess.Popen(['php', '-d', 'realpath_cache_size=0', '-S', '127.0.0.1:8080', '-t', ABSPATH, os.path.join(TESTS, 'router.php')],
                              stdout=subprocess.DEVNULL, stderr=open(os.path.join(S, 'golden-server.log'), 'a'))
    for _ in range(60):
        if server.poll() is not None:
            sys.exit('the golden server stopped (see golden-server.log)')
        try:
            import urllib.request
            urllib.request.urlopen('http://127.0.0.1:8080/wp-login.php', timeout=2)
            return
        except Exception:
            time.sleep(0.5)
    sys.exit('the golden server did not start')


def stop():
    global server
    if server:
        server.terminate()
        server.wait(10)
        server = None


def restore():
    wp('eval-file', os.path.join(HERE, 'snapshot.php'), env={'GOLDEN_MODE': 'restore', 'GOLDEN_FILE': SNAP})


def fill(url, ctx):
    url = url.replace('{week}', ctx['week'])
    for i, d in enumerate(ctx['dates']):
        url = url.replace('{d%d}' % i, d)
    return url


def normalise(html):
    for pat, to in CFG['normalise']:
        html = re.sub(pat, to, html)
    return html


def render(side, sessions, ctx, only=None):
    """{page index: (html, queries, status)} for the code the link points at."""
    out = {}
    for i, p in enumerate(CFG['pages']):
        if only is not None and i not in only:
            continue
        opts = p.get('options') or {}
        if opts:
            php(''.join("update_option(%s,%s,false);" % (json.dumps(k), json.dumps(v)) for k, v in opts.items()))
        status, html, _ = sessions[p['user']].req(fill(p['url'], ctx))
        m = re.findall(r'<!--EWSQ=(\d+)-->', html)
        out[i] = (normalise(html), int(m[-1]) if m else -1, status)
        if opts:
            php(''.join("delete_option(%s);" % json.dumps(k) for k in opts))
    return out


def actions(ctx):
    restore()
    return json.loads(wp('eval-file', os.path.join(HERE, 'actions.php'), env={'GOLDEN_CTX': json.dumps(ctx)}).splitlines()[-1])


HOOK = re.compile(r"""(?:add_action|add_filter|add_shortcode|register_rest_route)\(\s*['"]([^'"]+)['"]""")


def inventory(root):
    names = []
    for d, _, files in os.walk(root):
        for f in files:
            if f.endswith('.php'):
                names += HOOK.findall(open(os.path.join(d, f), encoding='utf-8', errors='replace').read())
    return sorted(names)


try:
    # The same data for both sides: the usual seed, then one snapshot.
    point(HEAD)
    wp('eval-file', os.path.join(TESTS, 'e2e_setup.php'))
    seed = subprocess.run([sys.executable, os.path.join(TESTS, 'e2e_attendance_cards.py'), S, '--seed', 'big'], capture_output=True, text=True, env={**os.environ, 'S': S})
    if seed.returncode:
        sys.exit(seed.stderr[-2000:])
    ctx = json.loads(seed.stdout.strip().splitlines()[-1])
    php("""delete_option('ews_feature_attendance_cards'); global $wpdb; $p=$wpdb->prefix;
        $u=username_exists('acomar')?:wp_create_user('acomar','acomar-pass','acomar@example.com');
        $wpdb->update($p.'ews_employees',['wp_user_id'=>(int)$u],['id'=>%d]);
        foreach([%d,%d] as $e) for($i=1;$i<=14;$i++){ $d=gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +'.$i.' days')); $wpdb->replace($p.'ews_schedule',['employee_id'=>$e,'work_date'=>$d,'status'=>'Office']); }""" % (ctx['omar'], ctx['omar'], ctx['mona']))
    # The query count of a page, printed at its end (web requests only).
    os.makedirs(MU, exist_ok=True)   # a fresh WordPress has no mu-plugins folder
    open(os.path.join(MU, 'ews-golden-q.php'), 'w').write('<?php if(defined("WP_CLI")&&WP_CLI) return; add_action("shutdown",function(){ global $wpdb; if(!wp_doing_ajax() && !(defined("REST_REQUEST")&&REST_REQUEST)) echo "<!--EWSQ=".(int)$wpdb->num_queries."-->"; },PHP_INT_MAX);')
    wp('eval-file', os.path.join(HERE, 'snapshot.php'), env={'GOLDEN_MODE': 'save', 'GOLDEN_FILE': SNAP})

    pages = {}
    sessions = None
    for side, target in (('base', BASE), ('base2', BASE), ('head', HEAD)):
        point(target)
        restore()
        start()
        # One login for all sides: the same session, so the same nonces on every page.
        if sessions is None:
            sessions = {'admin': Session('admin', 'admin'), 'emp1': Session('emp1', 'emp1pass')}
        pages[side] = render(side, sessions, ctx)
        stop()
        os.makedirs(os.path.join(S, 'golden-pages', side), exist_ok=True)
        for i, (html, _) in pages[side].items():
            open(os.path.join(S, 'golden-pages', side, '%02d.html' % i), 'w').write(html)
    # A page that differs is fetched once more on both sides (a clock boundary between two sides is not a difference).
    differ = [i for i in pages['head'] if pages['base'][i][0] != pages['head'][i][0] and pages['base'][i][0] == pages['base2'][i][0]]
    if differ:
        for side, target in (('base', BASE), ('head', HEAD)):
            point(target)
            restore()
            start()
            again = render(side, sessions, ctx, set(differ))
            stop()
            for i in differ:
                pages[side + '_again'] = pages.get(side + '_again', {})
                pages[side + '_again'][i] = again[i]
    allowed = {(p['url'], json.dumps(p.get('options') or {}, sort_keys=True)) for p in EXPECTED.get('pages', [])}
    for i, p in enumerate(CFG['pages']):
        name = '%s %s%s' % (p['user'], p['url'], (' with ' + ','.join(p['options'])) if p.get('options') else '')
        b, b2, h = pages['base'][i], pages['base2'][i], pages['head'][i]
        check('the page answers 200 on both sides: ' + name, b[2] == 200 and h[2] == 200 and 'critical error' not in h[0], (b[2], h[2]))
        if b[0] != b2[0]:
            check('stable on the old code: ' + name, False, 'the old code gave two different pages: add a pattern to pages.json normalise')
            continue
        same = b[0] == h[0] or (i in differ and pages['base_again'][i][0] == pages['head_again'][i][0])
        if (p['url'], json.dumps(p.get('options') or {}, sort_keys=True)) in allowed:
            print('EXPECTED (expected.json) %s: %s' % ('the same' if same else 'changed', name))
            continue
        diff = '' if same else ''.join(list(difflib.unified_diff(b[0].splitlines(True), h[0].splitlines(True), n=1))[:30])
        check('the same page: ' + name, same and len(b[0]) > 500, diff or len(b[0]))
        check('...no more queries (%d → %d)' % (b[1], h[1]), 0 <= h[1] <= b[1], (b[1], h[1]))

    point(BASE)
    tb = actions(ctx)
    point(HEAD)
    th = actions(ctx)
    for k in tb:
        check('the same action trace: ' + k, tb[k] == th.get(k), (json.dumps(tb[k])[:300], json.dumps(th.get(k))[:300]))
    check('...no action the old code had is missing', set(tb) == set(th), sorted(set(tb) ^ set(th)))
    check('...the actions really saved, notified and claimed the week (else they prove nothing)', tb.get('week_lock_notified') and tb.get('notifications') and tb.get('grid_insert', {}).get('ok'),
          (tb.get('week_lock_notified'), len(tb.get('notifications') or [])))
    ib, ih = inventory(BASE), inventory(HEAD)
    from collections import Counter
    removed, added = Counter(ib) - Counter(ih), Counter(ih) - Counter(ib)
    check('no hook, admin-post action, shortcode or REST route removed (%d)' % len(ih), not removed, sorted(removed)[:20])
    extra = sorted(set(added) - set(EXPECTED.get('hooks_added', [])))
    check('...and none added that expected.json does not name', not extra, extra[:20])
finally:
    stop()
    point(ORIGINAL)
    try:
        os.unlink(os.path.join(MU, 'ews-golden-q.php'))
    except OSError:
        pass
    if '--keep' not in sys.argv and os.path.exists(SNAP):
        restore()

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

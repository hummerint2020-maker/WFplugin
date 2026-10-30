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
    for k, v in ctx.items():   # the ids of the seeds ({omar}, {ops}, ...)
        if isinstance(v, (int, str)):
            url = url.replace('{%s}' % k, str(v))
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
            # An array option is written as decoded JSON (a PHP array), a scalar as itself.
            php(''.join("update_option(%s,json_decode(%s,true),false);" % (json.dumps(k), json.dumps(json.dumps(v))) for k, v in opts.items()))
        status, html, _ = sessions[p['user']].req(fill(p['url'], ctx))
        m = re.findall(r'<!--EWSQ=(\d+)-->', html)
        out[i] = (normalise(html), int(m[-1]) if m else -1, status)
        if opts:
            php(''.join("delete_option(%s);" % json.dumps(k) for k in opts))
    return out


def actions(ctx):
    """actions.php's trace, and the trace of every other actions_<name>.php under 'name:<key>' (each from the same snapshot)."""
    out = {}
    for f in sorted(x for x in os.listdir(HERE) if re.fullmatch(r'actions(_\w+)?\.php', x)):
        restore()
        t = json.loads(wp('eval-file', os.path.join(HERE, f), env={'GOLDEN_CTX': json.dumps(ctx), 'GOLDEN_DIR': HERE}).splitlines()[-1])
        prefix = '' if f == 'actions.php' else f[8:-4] + ':'
        out.update({prefix + k: v for k, v in t.items()})
    return out


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
    # The approvals' people, workflows and open requests (plain SQL; the pages of the approvals read them).
    wp('eval-file', os.path.join(HERE, 'seed_approvals.php'))
    # The people module's reporting lines, managers, teams and org-move history (ids merged into ctx for the page urls).
    ctx.update(json.loads(wp('eval-file', os.path.join(HERE, 'seed_people.php'), env={'GOLDEN_CTX': json.dumps(ctx), 'GOLDEN_DIR': HERE}).splitlines()[-1]))
    # The attendance module's rows: today's events (one corrected), coordinates, breaks, an approved overtime request.
    ctx.update(json.loads(wp('eval-file', os.path.join(HERE, 'seed_attendance.php'), env={'GOLDEN_CTX': json.dumps(ctx), 'GOLDEN_DIR': HERE}).splitlines()[-1]))
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
            sessions = {'admin': Session('admin', 'admin'), 'emp1': Session('emp1', 'emp1pass'),
                        'gdmgr': Session('gdmgr', 'gdmgr-pass'), 'gdreq': Session('gdreq', 'gdreq-pass'), 'acomar': Session('acomar', 'acomar-pass')}
        pages[side] = render(side, sessions, ctx)
        stop()
        os.makedirs(os.path.join(S, 'golden-pages', side), exist_ok=True)
        for i, (html, _q, _st) in pages[side].items():
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
        if p.get('expect'):
            check('the page shows what it should (%s): %s' % (p['expect'], name), p['expect'] in h[0] and p['expect'] in b[0] and 'id="loginform"' not in h[0], len(h[0]))
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
    json.dump({k: v for k, v in th.items() if k.startswith(('org:', 'employees:'))}, open(os.path.join(S, 'golden-org-trace.json'), 'w'), indent=1)
    def first_difference(a, b):
        """Where two traces first differ, for the failure message: the first SQL statement that is not the same."""
        if isinstance(a, dict) and isinstance(b, dict) and isinstance(a.get('sql'), list) and isinstance(b.get('sql'), list):
            for i in range(max(len(a['sql']), len(b['sql']))):
                x, y = (a['sql'][i] if i < len(a['sql']) else None), (b['sql'][i] if i < len(b['sql']) else None)
                if x != y:
                    return 'statement %d: old %s | new %s' % (i, json.dumps(x)[:260], json.dumps(y)[:260])
        return (json.dumps(a)[:300], json.dumps(b)[:300])
    intended = EXPECTED.get('actions', {})
    for k in tb:
        if k in intended:
            want = intended[k]
            changed = tb[k] != th.get(k)
            check('the intended change is there: ' + k + ' (' + (want['reason'] if isinstance(want, dict) else want) + ')', changed, 'listed in expected.json but the same on both sides')
            if isinstance(want, dict) and 'return' in want:
                check('...and the new code returns what expected.json says', isinstance(th.get(k), dict) and th[k].get('return') == want['return'], (th.get(k) or {}).get('return') if isinstance(th.get(k), dict) else th.get(k))
            continue
        check('the same action trace: ' + k, tb[k] == th.get(k), first_difference(tb[k], th.get(k)))
    check('...no action the old code had is missing', set(tb) == set(th), sorted(set(tb) ^ set(th)))
    check('...the actions really saved, notified and claimed the week (else they prove nothing)', tb.get('week_lock_notified') and tb.get('notifications') and tb.get('grid_insert', {}).get('ok'),
          (tb.get('week_lock_notified'), len(tb.get('notifications') or [])))
    org = {k[4:]: v for k, v in tb.items() if k.startswith('org:')}
    def ret(k):
        return (org.get(k) or {}).get('return')
    def to(k):
        r = ret(k)
        return r.get('to', '') if isinstance(r, dict) else ''
    check('...the org-writes trace really moved, scheduled, failed, imported, saved and archived (else it proves nothing)',
          bool(org) and 'org_notice=moved' in to('submit_move_now') and 'org_notice=scheduled' in to('submit_scheduled') and 'org_error=apply' in to('fault_move_commit')
          and ret('apply_due') == 4 and 'requests=' in to('submit_move_now') and bool(tb.get('org:_rows:notifications')) and 'org_notice=imported' in to('import_now') and 'department_saved=1' in to('dept_edit_ok') and 'team_saved=1' in to('team_new')
          and 'department_deleted=1' in to('dept_archive_empty') and 'department_error=has_teams' in to('dept_archive_with_teams') and 'team_error=save' in to('fault_team_commit')
          and any('ROLLBACK' in q for q in (org.get('fault_team_member_insert') or {}).get('sql', []))
          and ret('check_loop') == 'loop' and ret('set_supervisor') is True and ret('fault_set_only_commit') is False,
          {k: ret(k) for k in ('submit_move_now', 'apply_due', 'check_loop', 'set_supervisor', 'fault_set_only_commit')})
    emp = {k[10:]: v for k, v in tb.items() if k.startswith('employees:')}
    def eto(k):
        r = (emp.get(k) or {}).get('return')
        return r.get('to', '') if isinstance(r, dict) else ''
    check('...the employee-writes trace really added, updated, archived, revoked sessions, moved a supervisor and saved a picture (else it proves nothing)',
          bool(emp) and 'employee_notice=added' in eto('save_ok') and 'employee_notice=updated' in eto('update_nochange') and 'employee_notice=archived' in eto('archive_ok')
          and 'advance_left=' in eto('archive_advance') and 'employee_error=save' in eto('update_fault_employee_update') and 'employee_error=teams' in eto('update_fault_team_members_insert')
          and 'profile_updated=avatar' in eto('profile_avatar_ok') and 'profile_error=size' in eto('profile_upload_size')
          and any(r.get('revoked_reason') == 'employee_archived' for r in emp.get('_rows:devices', []))
          and any(r.get('action') == 'org_move' and 'waiting approval step(s) moved' in r.get('details', '') for r in emp.get('_rows:audit', [])) and bool(emp.get('_rows:notifications'))
          and any('ROLLBACK' in q for q in (emp.get('update_fault_periods_insert') or {}).get('sql', [])),
          {k: eto(k) for k in ('save_ok', 'update_nochange', 'archive_ok')})
    att = {k[11:]: v for k, v in tb.items() if k.startswith('attendance:')}
    def aret(k):
        return (att.get(k) or {}).get('return')
    json.dump({k: v for k, v in th.items() if k.startswith(('attendance:', 'signin:', 'records:'))}, open(os.path.join(S, 'golden-att-trace.json'), 'w'), indent=1)
    check('...the attendance trace really read events, a superseded one hidden, locations, breaks and an overtime day (else it proves nothing)',
          bool(att) and set(aret('today_events_omar') or {}) == {'sign_in'} and set(aret('today_events_lina') or {}) == {'sign_in', 'sign_out'}
          and str((aret('today_events_mona') or {}).get('sign_in', {}).get('id')) == str(ctx['att_corrected'])
          and isinstance(aret('last_device_location_omar'), dict) and aret('last_device_location_lina') is None
          and len(aret('break_sessions_lina') or []) == 2 and (aret('break_open_omar') or {}).get('status') == 'Open' and aret('break_remaining_lina') == 0
          and all((aret('hours_overnight_' + w) or {}).get('shift_id') == 0 for w in ('sam', 'twin', 'nour')) and aret('attendance_day_overnight_open') == 'D-1' and aret('attendance_day_overnight_closed') == 'D0' and aret('attendance_day_overnight_none') == 'D0' and set(aret('today_events_overnight_open') or {}) == {'sign_in'} and (aret('overtime_summary_lina') or {}).get('sign_out') == 'T'
          and all(att.get('_rows:' + t) for t in ('time_logs', 'break_sessions')),
          {k: aret(k) for k in ('today_events_omar', 'last_device_location_omar', 'break_remaining_lina', 'attendance_day_overnight_open')})
    sg = {k[7:]: v for k, v in tb.items() if k.startswith('signin:')}
    def sto(k):
        r = (sg.get(k) or {}).get('return')
        return (r.get('to', '') if isinstance(r, dict) else '') or ''
    def sret(k):
        return (sg.get(k) or {}).get('return') or {}
    check('...the Sign In trace really signed in and out, refused, took a Face photo, checked the position, claimed keys, took breaks, failed each write and ran the break crons (else it proves nothing)',
          bool(sg) and 'time_success' in sto('in_ok') and 'time_success' in sto('out_ok') and 'already%20signed%20in' in sto('in_again') and 'time_success' in sto('face_ok_photo')
          and 'outside%20your%20assigned' in sto('loc_outside') and 'time_success' in sto('loc_inside_in') and 'Unable%20to%20record' in sto('fault_insert')
          and 'already%20signed%20in' in sto('fault_first_row') and 'time_success' in sto('fault_lock_busy')
          and sret('idem_replay').get('details', {}).get('event_id') == sret('idem_first').get('details', {}).get('event_id') and sret('idem_conflict').get('code') == 'idempotency_conflict'
          and sret('idem_claim_broken').get('code') == 'in_progress' and sret('idem_save_failed_released').get('code') == 'save_failed' and sret('idem_retry_after_release').get('ok') is True
          and 'break_success' in sto('break_1_start') and 'break_success' in sto('break_1_resume') and 'break_error' in sto('break_3_none_left') and 'break_error' in sto('fault_break_insert')
          and len(sg.get('crons_after_break_1') or []) == 2 and any(r.get('photo_key') for r in sg.get('_rows:time_logs', []))
          and any(r.get('title') == 'Break Time Ended' for r in sg.get('_rows:notifications', [])) and any(r.get('title') == 'Extended Break Alert' for r in sg.get('_rows:notifications', []))
          and any(r.get('action') == 'time_sign_in' for r in sg.get('_rows:audit', [])) and any(r.get('action') == 'time_sign_in_failed' for r in sg.get('_rows:audit', []))
          and bool(sg.get('_rows:break_sessions')) and bool(sg.get('_rows:idempotency'))
          and (any('GET_LOCK' in q for q in sg['in_ok']['sql']) == any('RELEASE_LOCK' in q for q in sg['in_ok']['sql'])) and all(any('GET_LOCK' in q for q in sg[k]['sql']) == any('GET_LOCK' in q for q in sg['in_ok']['sql']) for k in ('fault_lock_busy', 'fault_lock_null', 'out_ok')),
          {k: sto(k)[-70:] for k in ('in_ok', 'face_ok_photo', 'loc_outside', 'fault_insert', 'break_1_start')})
    rc = {k[8:]: v for k, v in tb.items() if k.startswith('records:')}
    def rto(k):
        r = (rc.get(k) or {}).get('return')
        return (r.get('to', '') if isinstance(r, dict) else '') or ''
    def rret(k):
        return (rc.get(k) or {}).get('return') or {}
    audit_actions = [r.get('action') for r in rc.get('_rows:audit', [])]
    check('...the records trace really ran the tick, recorded and refused automatic events, saved / toggled / deleted rules, signed out in bulk with a break, added / edited / refused manual records and reset a day (else it proves nothing)',
          bool(rc) and 'auto_sign_in' in audit_actions and 'auto_sign_out' in audit_actions and 'auto_sign_out_bulk' in audit_actions and 'break_auto_closed' in audit_actions
          and 'auto_sign_in_failed' in audit_actions and 'auto_attendance_rule_create' in audit_actions and 'auto_attendance_rule_update' in audit_actions
          and 'auto_attendance_rule_toggle' in audit_actions and 'auto_attendance_rule_delete' in audit_actions
          and 'time_manual_add' in audit_actions and 'time_manual_edit' in audit_actions and 'time_manual_add_failed' in audit_actions and 'time_manual_edit_failed' in audit_actions and 'time_reset' in audit_actions
          and rret('event_in_ok').get('ok') is True and rret('event_in_again').get('reason') == 'already_signed_in' and rret('event_out_before_in').get('reason') == 'sign_in_after_target'
          and rret('event_fault_insert').get('reason') == 'db_error' and rret('event_fault_first_row').get('reason') == 'already_signed_in' and rret('event_attendance_disabled').get('reason') == 'attendance_disabled'
          and 'saved=1' in rto('rule_save_create') and 'saved=1' in rto('rule_save_update_deleted_id') and 'bulk_sign_out=' in rto('bulk_ok') and 'bulk_failed=0' in rto('bulk_ok') and 'bulk_sign_out=0' in rto('bulk_again')
          and rc.get('crons_after_bulk') == [] and len(rc.get('crons_before_bulk') or []) == 2
          and 'time_notice=saved' in rto('man_add_in') and 'time_error=duplicate' in rto('man_add_in_duplicate') and 'time_error=order' in rto('man_add_out_before_in') and 'time_error=scope' in rto('man_mgr_other_department')
          and 'time_error=save' in rto('man_fault_insert') and 'time_error=not_found' in rto('man_edit_not_found') and 'break_reset=2' in rto('reset_with_breaks') and 'time_error=scope' in rto('reset_scope')
          and any(r.get('enabled') == '0' for r in rc.get('rules_after_ticks', [])) and any(r.get('enabled') == '1' for r in rc.get('rules_after_ticks', []))
          and all(sum('GET_LOCK' in q for q in rc[k]['sql']) == sum('RELEASE_LOCK' in q for q in rc[k]['sql']) for k in ('tick_1', 'bulk_ok', 'event_in_ok')),
          {k: rto(k)[-60:] for k in ('rule_save_create', 'bulk_ok', 'man_add_in', 'reset_with_breaks')})
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

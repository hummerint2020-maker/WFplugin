"""Open every Workforce One admin page and employee-app view and fail on PHP fatals.

Usage: python3 tests/smoke.py <wp-content/debug.log path>
Needs the site from e2e_setup.php (admin/admin and emp1/emp1pass) at http://127.0.0.1:8080.
"""
import http.cookiejar, re, sys, urllib.request, urllib.parse

B = 'http://127.0.0.1:8080'
ADMIN_PAGES = ['ews31', 'ews31-achievements', 'ews31-approvals', 'ews31-attendance-insights', 'ews31-audit',
               'ews31-auto-attendance', 'ews31-departments', 'ews31-email', 'ews31-employee-profile-settings',
               'ews31-employees', 'ews31-features', 'ews31-leaves', 'ews31-moments', 'ews31-multi-locations',
               'ews31-navigation', 'ews31-notifications', 'ews31-polls', 'ews31-presence-kiosks', 'ews31-recognition',
               'ews31-requests', 'ews31-roles', 'ews31-schedule-config', 'ews31-smart-nudges', 'ews31-teams',
               'ews31-time-report']
APP_VIEWS = ['', 'attendance', 'employees', 'notifications', 'overtime', 'people', 'presence', 'profile',
             'reports', 'schedule', 'tasks', 'time', 'vacation']
FATAL = re.compile(r'critical error on this website|Fatal error|There has been a critical error', re.I)


def session(user, pwd):
    cj = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    op.open(B + '/wp-login.php').read()
    op.open(B + '/wp-login.php', urllib.parse.urlencode({'log': user, 'pwd': pwd, 'testcookie': '1'}).encode()).read()
    return op


def get(op, path):
    try:
        r = op.open(B + path)
        return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')


failures = []
log_path = sys.argv[1] if len(sys.argv) > 1 else None
log_start = 0
if log_path:
    try:
        log_start = len(open(log_path, encoding='utf-8', errors='replace').read())
    except FileNotFoundError:
        pass

for who, user, pwd in [('admin', 'admin', 'admin'), ('employee', 'emp1', 'emp1pass')]:
    op = session(user, pwd)
    pages = [('/wp-admin/admin.php?page=' + p) for p in ADMIN_PAGES] if who == 'admin' else []
    pages += [('/app/' + ('?ews_view=' + v if v else '')) for v in APP_VIEWS]
    for path in pages:
        st, body = get(op, path)
        ok = st in (200, 403) and not FATAL.search(body)
        print(('ok   ' if ok else 'FAIL ') + f'{who:8} {st} {path}')
        if not ok:
            failures.append(f'{who} {path} -> {st}')

if log_path:
    try:
        new = open(log_path, encoding='utf-8', errors='replace').read()[log_start:]
    except FileNotFoundError:
        new = ''
    plugin_errors = [l for l in new.splitlines()
                     if re.search(r'PHP (Fatal|Warning|Parse)', l) and 'workforce-one' in l]
    for l in sorted(set(plugin_errors)):
        print('LOG  ' + l[:300])
    failures += plugin_errors

print(f'{len(failures)} failure(s)')
sys.exit(1 if failures else 0)

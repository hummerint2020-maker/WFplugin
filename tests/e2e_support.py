"""Shared helpers for the HTTP behaviour tests (e2e_leave.py, e2e_overtime.py).

Environment: WP_CLI (command used to run wp-cli, default "wp"); argv[1] = state dir that
receives ids.json from e2e_setup.php.
"""
import datetime, http.cookiejar, json, os, re, shlex, subprocess, sys, urllib.parse, urllib.request

B = 'http://127.0.0.1:8080'
S = sys.argv[1]
WP = shlex.split(os.environ.get('WP_CLI', 'wp'))
HERE = os.path.dirname(os.path.abspath(__file__))
results = []


def wp(*args):
    r = subprocess.run(WP + list(args), capture_output=True, text=True, env={**os.environ, 'S': S})
    if r.returncode:
        raise RuntimeError('wp ' + ' '.join(args) + '\n' + r.stderr[-2000:])
    return r.stdout.strip()


def php(code):
    return wp('eval', 'global $wpdb; $p=$wpdb->prefix; ' + code)


def q(sql):
    """Run a SELECT and return rows as dicts ({p} = table prefix)."""
    out = php('echo wp_json_encode($wpdb->get_results("' + sql.replace('{p}', '{$p}').replace('"', '\\"') + '",ARRAY_A));')
    return json.loads(out or '[]')


def check(name, cond, extra=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '  | ' + str(extra)[:400]))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Session:
    def __init__(self, user, pwd):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
        self.req('/wp-login.php')
        self.req('/wp-login.php', {'log': user, 'pwd': pwd, 'testcookie': '1'})

    def req(self, path, data=None):
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data, doseq=True).encode()
        try:
            r = self.op.open(urllib.request.Request(B + path, data=data))
            return r.status, r.read().decode('utf-8', 'replace'), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)

    def nonce(self, action, view='vacation'):
        st, page, _ = self.req('/app/?ews_view=' + view)
        for form in re.findall(r'<form.*?</form>', page, re.S):
            if 'name="action" value="%s"' % action in form:
                m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
                if m:
                    return m.group(1)
        return None

    def admin_request_nonce(self, rtype, rid):
        """Nonce of a decision form on the wp-admin "Requests" page."""
        st, page, _ = self.req('/wp-admin/admin.php?page=ews31-requests')
        for form in re.findall(r'<form.*?</form>', page, re.S):
            if 'value="ews31_requests_decision"' in form and 'name="request_type" value="%s"' % rtype in form and 'name="request_id" value="%d"' % rid in form:
                m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
                if m:
                    return m.group(1)
        return None

    def post(self, action, **data):
        st, body, h = self.req('/wp-admin/admin-post.php', {'action': action, **data})
        loc = h.get('Location', '')
        qs = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
        return st, {k: v[0] for k, v in qs.items()}, body


today = datetime.date.today()


def d(days):
    return (today + datetime.timedelta(days=days)).isoformat()


def next_weekday(wp_dow, after=3):
    """Next date (at least `after` days ahead) whose WordPress weekday (0=Sunday) is wp_dow."""
    x = today + datetime.timedelta(days=after)
    while (x.isoweekday() % 7) != wp_dow:
        x += datetime.timedelta(days=1)
    return x


def ids():
    return json.load(open(os.path.join(S, 'ids.json')))



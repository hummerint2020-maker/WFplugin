"""The installed app's offline screen (3.31.93), server side: the Features settings (off by default,
every text editable, saving keeps the other features, Audit Log), the page and the ping, what the service
worker and the app page carry, and who can open the preview. The behaviour on a phone (no internet, the
server down, back online) is in tests/offline_browser.js.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_offline_screen.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse
from html.parser import HTMLParser

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-features'


class Form(HTMLParser):
    """The Features form's fields as the browser would send them."""
    def __init__(self):
        super().__init__()
        self.inside, self.data, self.area, self.select = False, [], None, None

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == 'form':
            self.inside = True
        if not self.inside:
            return
        if tag == 'input' and a.get('name'):
            t = a.get('type', 'text')
            if t in ('checkbox', 'radio') and 'checked' not in a:
                return
            self.data.append((a['name'], a.get('value', 'on' if t == 'checkbox' else '')))
        elif tag == 'textarea' and a.get('name'):
            self.area = [a['name'], '']
        elif tag == 'select' and a.get('name'):
            self.select = [a['name'], None, None]
        elif tag == 'option' and self.select:
            if self.select[2] is None:
                self.select[2] = a.get('value', '')
            if 'selected' in a:
                self.select[1] = a.get('value', '')

    def handle_data(self, d):
        if self.area is not None:
            self.area[1] += d

    def handle_endtag(self, tag):
        if tag == 'textarea' and self.area is not None:
            self.data.append((self.area[0], html.unescape(self.area[1])))
            self.area = None
        elif tag == 'select' and self.select:
            self.data.append((self.select[0], self.select[1] if self.select[1] is not None else self.select[2]))
            self.select = None
        elif tag == 'form':
            self.inside = False


def form(sess):
    st, page, _ = sess.req(PAGE)
    f = Form()
    f.feed(page[page.index('<form method="post"'):] if '<form method="post"' in page else '')
    return dict(f.data), f.data, page


def save(sess, **over):
    _, fields, _ = form(sess)
    out = [(k, v) for k, v in fields if k not in over]
    for k, v in over.items():
        if v is not None:
            out.append((k, v))
    st, body, h = sess.req('/wp-admin/admin-post.php', urllib.parse.urlencode(out).encode())
    return h.get('Location', '')


def opt():
    return json.loads(php("echo wp_json_encode(get_option('ews_offline_screen'));").splitlines()[-1] or 'null')


wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""$wpdb->query("DELETE FROM {$p}ews_audit_log"); delete_option('ews_offline_screen'); update_option('ews_feature_tasks',1,false); update_option('ews_breaks_per_day',4,false);""")
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- off by default
d, _, page = form(adm)
check('Features shows the Offline Screen section, off by default', 'id="ews-offline-screen"' in page and 'offline_enabled' not in d)
check('...every text with its default as a hint, empty boxes', all(('name="offline_texts[%s]"' % k) in page for k in ('off_title', 'down_msg', 'ns_title', 'toast', 'today', 'hours'))
      and 'placeholder="No internet connection"' in page and d.get('offline_texts[off_title]') == '')
check('...and the preview of the real page', 'id="ews-offline-preview"' in page and 'ews_pwa=offline' in page and 'preview=off' in page)
st, body, _ = adm.req('/?ews_pwa=offline')
check('off: the page is not served', st == 404)
st, sw, _ = adm.req('/?ews_pwa=sw')
check('off: the service worker is as before (no offline page)', 'const OFFLINE_URL' not in sw and 'openPage' not in sw)
st, app, _ = emp.req('/app/?ews_view=time')
check('off: the app page keeps nothing for it', "})([]);" in app and 'ewsOfflineSchedule' in app)

# ---------------------------------------------------------------- saving
loc = save(adm, offline_enabled='1', offline_timeout='12', offline_keep_note=None, **{
    'offline_texts[off_title]': '  <script>alert(1)</script>No network ', 'offline_texts[retry]': 'Try again',
    'offline_texts[down_msg]': 'Call HR on 19999.\r\nWe are on it.', 'offline_texts[toast0]': 'Welcome back </script><b>x',
})
o = opt()
check('saved: on, the time, the note off, the schedule kept on', 'features_saved=1' in loc and o and o['enabled'] == 1 and o['timeout'] == 12 and o['keep_note'] == 0 and o['schedule'] == 1, (loc, o))
check('...texts without tags; one equal to its default is not kept', o['texts'].get('off_title') == 'alert(1)No network' and 'retry' not in o['texts']
      and o['texts'].get('down_msg') == 'Call HR on 19999.\nWe are on it.' and o['texts'].get('toast0') == 'Welcome back x', o['texts'])
check('...the other features are kept', php("echo (int)get_option('ews_feature_tasks').'/'.(int)get_option('ews_breaks_per_day');").splitlines()[-1] == '1/4')
a = q("SELECT details FROM {p}ews_audit_log WHERE action='offline_screen_update'")
check('...written to the Audit Log', len(a) == 1 and 'on; timeout=12s' in a[0]['details'] and 'off_title' in a[0]['details'], a)
save(adm)
check('saving again without a change adds nothing to the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='offline_screen_update'")) == 1)
d, _, page = form(adm)
check('the form shows the saved texts', d.get('offline_texts[off_title]') == 'alert(1)No network' and d.get('offline_texts[down_msg]') == 'Call HR on 19999.\nWe are on it.' and d.get('offline_enabled') == '1')

# ---------------------------------------------------------------- the page, the ping, the service worker
st, page, h = emp.req('/?ews_pwa=offline')
check('on: the page is served, not stored by the browser', st == 200 and 'id="wfo-offline"' in page and 'no-store' in h.get('Cache-Control', '') + h.get('cache-control', ''), (st, h.get('Cache-Control')))
cfg = json.loads(re.search(r'\}\)\((\{.*\})\);\s*</script>', page, re.S).group(1))
check('...with the texts set (the defaults for the others)', cfg['t']['off_title'] == 'alert(1)No network' and cfg['t']['retry'] == 'Try again' and cfg['t']['down_title'] == 'Workforce One is not responding' and cfg['keep'] == 0)
check('...nothing in it can close its script', '</script><b>' not in page and page.count('</script>') == 1)
check('...the same for everyone: no employee name or data', 'Employee One' not in page and 'emp1' not in page and cfg['preview'] == '')
st2, page2, _ = Session('admin', 'admin').req('/?ews_pwa=offline')
check('...an administrator gets exactly the same page', re.sub(r'\s+', '', page2) == re.sub(r'\s+', '', page))
st, body, h = emp.req('/?ews_pwa=ping')
check('the ping answers 204, not stored', st == 204 and body == '')
st, sw, _ = emp.req('/?ews_pwa=sw')
m = re.search(r'const OFFLINE_URL="([^"]+)"', sw)
check('the service worker carries the page with its revision, the wait, the app page and admin-post.php',
      m and 'rev=' in m.group(1) and 'const OFFLINE_WAIT=12000;' in sw and 'const APP_PATHS=["\\/app\\/"]' in sw and 'admin-post.php' in re.search(r'const POST_PATH=(.*);', sw).group(1), sw[:900])
rev1 = m.group(1) if m else ''
save(adm, **{'offline_texts[off_title]': 'Changed'})
st, sw2, _ = emp.req('/?ews_pwa=sw')
rev2 = re.search(r'const OFFLINE_URL="([^"]+)"', sw2).group(1)
check('a changed text changes the revision, so phones fetch the page again', rev1 != rev2)
st, again, _ = emp.req('/?ews_pwa=sw')
check('...and the service worker stays byte for byte the same between requests', again == sw2)
check('a form is never timed out (only a page being opened)', 'const sendForm=req=>fetch(req).then' in sw2 and 'setTimeout' not in re.search(r'const sendForm=.*', sw2).group(0))

# ---------------------------------------------------------------- what the app page keeps on the phone
eid = ids()['eid']
php("""$wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%%d",%d));
    $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>current_time('Y-m-d'),'status'=>'Office']);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);""" % (eid, eid))
st, app, _ = emp.req('/app/?ews_view=time')
m = re.search(r'\}\)\((\{"user":.*?\})\);', app, re.S)
cfg = json.loads(m.group(1)) if m else {}
days = cfg.get('days', [])
check('the app page carries the employee\'s 7 days for the phone', len(days) == 7 and days[0]['status'] == 'Office' and days[0]['hours'] != '' and days[1]['status'] == 'Not set', days[:2])
check('...and the "back online" texts', cfg.get('toast0') == 'Welcome back x' and '{time}' in cfg.get('toast', ''))
save(adm, offline_schedule=None)
st, app, _ = emp.req('/app/?ews_view=time')
m = re.search(r'\}\)\((\{"user":.*?\})\);', app, re.S)
check('schedule card off: no schedule is sent to the phone', m and json.loads(m.group(1))['days'] == [])

# ---------------------------------------------------------------- the preview
st, body, _ = emp.req('/?ews_pwa=offline&preview=down')
check('an employee cannot open the preview', st == 403)
save(adm, offline_enabled=None)
st, body, _ = adm.req('/?ews_pwa=offline&preview=press')
check('an administrator can, also while the screen is off', st == 200 and '"preview":"press"' in body)
st, body, _ = adm.req('/?ews_pwa=offline&preview=other')
check('...for the known states only', st == 403)
st, body, _ = adm.req('/?ews_pwa=offline')
check('turned off again: the page is not served', st == 404)

php("delete_option('ews_offline_screen');")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

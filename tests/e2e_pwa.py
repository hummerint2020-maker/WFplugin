"""Behaviour tests for the installable app (PWA) that employees use on their phones: the manifest,
the service worker (offline assets, push notifications), the page tags, the splash screen, the
install banner and the push-subscription helpers. tests/pwa_browser.js checks the same in Chromium.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_pwa.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("delete_option('ews_pwa_splash_settings');")
VERSION = php("echo EWS_VERSION;").strip()
emp = Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- manifest
st, body, h = emp.req('/?ews_pwa=manifest')
m = json.loads(body) if st == 200 else {}
check('the manifest is served as a web app manifest', st == 200 and h.get('Content-Type', '').startswith('application/manifest+json'), (st, h.get('Content-Type')))
check('...installable: name, standalone display, scope and start URL', m.get('name') == 'Employee Hub' and m.get('short_name') == 'Employee Hub' and m.get('display') == 'standalone'
      and m.get('scope') == '/' and m.get('start_url', '').endswith('?ews_view=time') and m.get('theme_color') == '#101828' and m.get('orientation') == 'portrait-primary', m)
icons = {i['sizes']: i for i in m.get('icons', [])}
check('...with 192 and 512 px PNG icons that load', set(icons) == {'192x192', '512x512'} and all(emp.req(i['src'].split('127.0.0.1:8080')[-1])[0] == 200 for i in icons.values()), icons)
check('...and is never cached', 'no-cache' in h.get('Cache-Control', '') or 'no-store' in h.get('Cache-Control', ''), h.get('Cache-Control'))
st, body, _ = emp.req('/app/?ews_pwa=manifest')
check('requested from the app page, it starts on the app page', st == 200 and json.loads(body).get('start_url') == B + '/app/?ews_view=time', body[:200])
st, body, h = emp.req('/?ews_pwa=icon')
check('the SVG icon is served', st == 200 and h.get('Content-Type', '').startswith('image/svg+xml') and body.startswith('<svg'))

# ---------------------------------------------------------------- service worker
st, sw, h = emp.req('/?ews_pwa=sw')
check('the service worker is served as JavaScript for the whole site', st == 200 and 'javascript' in h.get('Content-Type', '') and h.get('Service-Worker-Allowed') == '/', (st, h.get('Content-Type'), h.get('Service-Worker-Allowed')))
check('...never cached by the browser (updates reach phones)', 'no-cache' in h.get('Cache-Control', '') or 'no-store' in h.get('Cache-Control', ''))
check('...its cache is named after the plugin version (a new version replaces it)', 'const CACHE="employee-hub-v%s";' % VERSION in sw, sw[:200])
assets = json.loads(re.search(r'const STATIC_ASSETS=(\[.*?\]);', sw).group(1)) if 'STATIC_ASSETS' in sw else []
check('...the assets it stores offline all load', len(assets) == 5 and all(emp.req(a.split('127.0.0.1:8080')[-1])[0] == 200 for a in assets), assets)
apath = json.loads(re.search(r'const ASSETS_PATH=(".*?");', sw).group(1)) if 'ASSETS_PATH' in sw else ''
check('...it caches only the plugin\'s own static files', 'u.pathname.startsWith(ASSETS_PATH)' in sw and apath == '/wp-content/plugins/workforce-one/assets/', apath)
check('...and handles install, activate, fetch, push and notification clicks', all("self.addEventListener('%s'" % e in sw for e in ('install', 'activate', 'fetch', 'push', 'notificationclick')))
check('...a notification opens its link, else the site', 'data:{url:d.url||HOME_URL}' in sw and 'client.navigate(url)' in sw)

# ---------------------------------------------------------------- the app page
st, page, _ = emp.req('/app/')
check('the app page links the manifest and the iPhone icon', 'href="%s/?ews_pwa=manifest"' % B in page and re.search(r'<link rel="apple-touch-icon" href="[^"]*workforce-one-192\.png">', page) is not None)
check('...with theme colour and home-screen app tags', all(t in page for t in ('<meta name="theme-color" content="#101828">', '<meta name="mobile-web-app-capable" content="yes">',
      '<meta name="apple-mobile-web-app-capable" content="yes">', '<meta name="apple-mobile-web-app-title" content="Employee Hub">')))
check('...hides the WordPress admin bar', '#wpadminbar{display:none!important}' in page)
check('...registers the service worker for the whole site', "navigator.serviceWorker.register(%s,{updateViaCache:'none'})" % json.dumps(B + '/?ews_pwa=sw').replace('/', '\\/') in page, re.findall(r'serviceWorker\.register\([^)]*\)', page))
check('...offers "Add to Home Screen" (with iPhone instructions)', 'id="ews-pwa-install"' in page and 'Add to Home Screen' in page)
check('...and the push helpers with the server key, URL and nonce', all(x in page for x in ('window.ewsEnablePush=function', 'window.ewsDisablePush=function', 'window.EWS_PUSH_URL=', 'window.EWS_PUSH_NONCE="')))
check('...shows the default splash screen', 'id="ews-pwa-splash"' in page and 'Workforce One' in page.split('id="ews-pwa-splash"')[1][:600])

php("update_option('ews_pwa_splash_settings',['enabled'=>1,'duration_ms'=>900,'title'=>'Ahmed\\'s Team','subtitle'=>'Work & <b>smile</b>','background'=>'#ffeedd','accent'=>'#123456','logo'=>'https://example.com/logo.png'],false);")
st, page, _ = emp.req('/app/')
splash = page.split('id="ews-pwa-splash-style"')[1].split('</script>')[0] if 'id="ews-pwa-splash-style"' in page else ''
check('the splash screen uses the saved title, colours, logo and duration', 'Ahmed&#039;s Team' in splash and 'background:#ffeedd' in splash and 'background:#123456' in splash
      and 'src="https://example.com/logo.png"' in splash and 'minDuration=900' in splash, splash[:300])
check('...with its text cleaned and escaped', 'Work &amp; smile' in splash and '<b>' not in splash, re.findall(r'ews-splash-subtitle">[^<]*', splash))
php("update_option('ews_pwa_splash_settings',['enabled'=>0],false);")
st, page, _ = emp.req('/app/')
check('a switched-off splash is not shown (the rest stays)', 'id="ews-pwa-splash"' not in page and 'rel="manifest"' in page and 'serviceWorker.register' in page)
php("delete_option('ews_pwa_splash_settings');")

st, page, _ = emp.req('/')
check('other pages of the site get none of it', 'rel="manifest"' not in page and 'ews-pwa-install' not in page and 'ews-pwa-splash' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)

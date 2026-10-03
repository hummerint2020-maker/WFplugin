/* The installable app in a real browser (Chromium): the service worker installs and controls the
   app page, the offline cache is filled, the manifest is valid and the splash screen goes away.
   Usage: node tests/pwa_browser.js   (needs tests/e2e_setup.php users at http://127.0.0.1:8080) */
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8080';
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };

  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'emp1'); await page.fill('#user_pass', 'emp1pass'); await page.click('#wp-submit');
  await page.goto(B + '/app/');
  const sw = await page.evaluate(async () => {
    const reg = await Promise.race([navigator.serviceWorker.ready, new Promise(r => setTimeout(() => r(null), 15000))]);
    if (!reg) return null;
    return { script: reg.active && reg.active.scriptURL, scope: reg.scope, state: reg.active && reg.active.state };
  });
  check('the service worker installs and activates for the whole site', sw && /\?ews_pwa=sw$/.test(sw.script) && sw.scope === B + '/' && sw.state === 'activated', sw);
  await page.reload();
  const controlled = await page.evaluate(() => !!navigator.serviceWorker.controller);
  check('...and controls the app page', controlled);
  const cache = await page.evaluate(async () => { const keys = await caches.keys(); const c = keys.length ? await caches.open(keys[0]) : null; return { keys, n: c ? (await c.keys()).length : 0 }; });
  check('...its offline cache holds the app\'s static files', cache.keys.length === 1 && /^employee-hub-v/.test(cache.keys[0]) && cache.n === 5, cache);
  const manifest = await page.evaluate(async () => { const l = document.querySelector('link[rel=manifest]'); if (!l) return null; const r = await fetch(l.href); return r.ok ? r.json() : null; });
  check('the manifest loads and is installable (standalone, icons)', manifest && manifest.display === 'standalone' && manifest.icons.length === 2, manifest);
  const cdp = await page.context().newCDPSession(page);
  const parsed = await cdp.send('Page.getAppManifest');
  const pm = parsed.manifest || {};
  check('Chromium reads the manifest without errors; the app keeps its id and opens on the app page', parsed.errors.length === 0 && pm.id === B + '/?ews_view=time'
    && pm.startUrl === B + '/app/?ews_view=time', { errors: parsed.errors, id: pm.id, start: pm.startUrl });
  await page.waitForTimeout(1500);
  check('the splash screen is gone once the page has loaded', await page.locator('#ews-pwa-splash').count() === 0);
  check('the push helpers are ready', await page.evaluate(() => typeof window.ewsEnablePush === 'function' && typeof window.ewsDisablePush === 'function'));
  check('no JavaScript errors', errors.length === 0, errors);
  console.log(ok + ' / ' + (ok + fail));
  await browser.close();
  process.exit(fail ? 1 : 0);
})();

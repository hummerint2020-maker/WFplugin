/* The app keeps the server's copy of this device's push subscription current (3.31.87), in a real
   browser (Chromium). The browser's push system is simulated (a headless browser never grants the
   notification permission), the page and the server are real:
   - the server has no device for the user: the subscription is saved at once;
   - saved less than 3 days ago and the server has it: nothing is sent;
   - older than 3 days: re-saved 1 to 5 minutes after the app opens (never at once: shift start);
   - turned off on this device: nothing is made or sent;
   - made with another server key: replaced by a new one.
   Usage: node tests/push_sync_browser.js   (needs tests/e2e_setup.php users at http://127.0.0.1:8080) */
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8080';
const EP = 'https://fcm.googleapis.com/fcm/send/wfo-browser-sync-test';

// Runs in the page before its own scripts: a granted permission, a fake push manager, recorded
// subscribe calls and timers (a timer of a minute or more runs at once, its delay kept).
function stub(opts) {
  Object.defineProperty(Notification, 'permission', { get: () => 'granted' });
  window.__t = { posts: 0, delays: [], subscribed: 0, unsubscribed: 0 };
  const sub = (endpoint, foreignKey) => ({
    endpoint,
    options: { applicationServerKey: foreignKey ? new Uint8Array(65).fill(7).buffer : null },
    toJSON: () => ({ endpoint, keys: { p256dh: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', auth: 'tBHItJI5svbpez7KI4CCXg' } }),
    unsubscribe: () => { window.__t.unsubscribed++; return Promise.resolve(true); },
  });
  let current = opts.has ? sub(opts.endpoint, opts.foreignKey) : null;
  const reg = { pushManager: {
    getSubscription: () => Promise.resolve(current),
    subscribe: () => { window.__t.subscribed++; current = sub(opts.endpoint + '-new', false); return Promise.resolve(current); },
  } };
  Object.defineProperty(navigator.serviceWorker, 'ready', { get: () => Promise.resolve(reg) });
  navigator.serviceWorker.register = () => Promise.resolve(reg);
  const realTimeout = window.setTimeout;
  window.setTimeout = function (fn, ms) { if (ms >= 60000) { window.__t.delays.push(ms); fn(); return 0; } return realTimeout.apply(this, arguments); };
  const realFetch = window.fetch;
  window.fetch = function (url, init) {
    if (init && init.body && String(init.body).indexOf('action=ews_push_subscribe') !== -1) window.__t.posts++;
    return realFetch.apply(this, arguments);
  };
  if (opts.synced) localStorage.setItem('ewsPushSynced', opts.synced);
  if (opts.off) localStorage.setItem('ewsPushOff', '1');
}

(async () => {
  const browser = await chromium.launch();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  // No shell in between, so the quotes in a query reach PHP as they are.
  const sql = q => execFileSync(wp[0], wp.slice(1).concat(['eval', "global $wpdb; echo $wpdb->get_var(str_replace('{p}', $wpdb->prefix, '" + q.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'));"])).toString().trim();
  const devices = () => Number(sql("SELECT COUNT(*) FROM {p}ews_push_subscriptions WHERE endpoint LIKE '" + EP + "%'"));
  sql('DELETE FROM {p}ews_push_subscriptions');

  // One run of the app page with the given simulated state; returns what the page did.
  const run = async (opts) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(B + '/wp-login.php');
    await page.fill('#user_login', 'emp1'); await page.fill('#user_pass', 'emp1pass'); await page.click('#wp-submit');
    await page.addInitScript(stub, Object.assign({ endpoint: EP }, opts));
    await page.goto(B + '/app/');
    await page.waitForTimeout(2500);
    const t = await page.evaluate(() => Object.assign({}, window.__t, { synced: localStorage.getItem('ewsPushSynced') }));
    await ctx.close();
    return Object.assign(t, { errors });
  };

  let t = await run({ has: true });
  check('the server has no device for the user: the subscription is saved at once, no waiting', t.posts === 1 && t.delays.length === 0 && devices() === 1, t);
  check('...and the time it was saved is remembered on the device', t.synced && t.synced.startsWith(EP + '|'), t.synced);

  t = await run({ has: true, synced: EP + '|' + Date.now() });
  check('saved less than 3 days ago and the server has it: nothing is sent', t.posts === 0 && t.delays.length === 0, t);

  t = await run({ has: true, synced: EP + '|' + (Date.now() - 4 * 864e5) });
  check('saved more than 3 days ago: re-saved 1 to 5 minutes after the app opens, never at once', t.posts === 1 && t.delays.length === 1 && t.delays[0] >= 60000 && t.delays[0] <= 300000, t);

  t = await run({ has: true, synced: 'https://fcm.googleapis.com/fcm/send/another|' + Date.now() });
  check('the browser has another subscription than the one saved: re-saved (after the random wait)', t.posts === 1, t);

  t = await run({ has: true, off: true });
  check('turned off on this device: nothing is made or sent', t.posts === 0 && t.subscribed === 0, t);

  sql('DELETE FROM {p}ews_push_subscriptions');
  t = await run({ has: false });
  check('no subscription in the browser but permission granted: one is made and saved', t.subscribed === 1 && t.posts === 1 && Number(sql("SELECT COUNT(*) FROM {p}ews_push_subscriptions WHERE endpoint='" + EP + "-new'")) === 1, t);

  sql('DELETE FROM {p}ews_push_subscriptions');
  t = await run({ has: true, foreignKey: true });
  check('a subscription made with another server key is replaced, the new one saved', t.unsubscribed === 1 && t.subscribed === 1 && t.posts === 1 && Number(sql("SELECT COUNT(*) FROM {p}ews_push_subscriptions WHERE endpoint='" + EP + "-new'")) === 1, t);
  check('no JavaScript errors', !t.errors.length, t.errors);

  sql('DELETE FROM {p}ews_push_subscriptions');
  await browser.close();
  console.log(`${ok} / ${ok + fail}`);
  process.exit(fail ? 1 : 0);
})();

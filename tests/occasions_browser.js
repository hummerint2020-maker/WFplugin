/* Occasions (3.31.145, docs/tasks/occasions.md) in a real browser (Chromium):
   - a phone with Ramadan falling over the whole screen and its moment: the Sign In / Out button of the tab bar is what a tap reaches
     (elementFromPoint), the decorations never take a tap, no sideways scroll;
   - the moment is gone after a few seconds; the next page has none (once a day);
   - reduced motion: nothing moves, nothing falls;
   - the kiosk: the code's card is on top of the decorations;
   - every request stays on the site; no JavaScript errors.
   Usage: WP_CLI="wp --path=..." S=<state dir> node tests/occasions_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080) */
const path = require('path');
const fs = require('fs');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const ids = JSON.parse(fs.readFileSync(path.join(process.env.S || '/tmp/wf-e2e', 'ids.json'), 'utf8'));
  const clean = `global $wpdb; foreach(['ews_feature_occasions','ews_occasions'] as $o) delete_option($o); $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ews_occasion%'");`;
  php(clean + ` update_option('ews_feature_occasions',1,false); update_option('ews_occasions',['active'=>'ramadan','mode'=>'full','started'=>current_time('mysql'),'kiosk'=>1],false);`);

  const browser = await chromium.launch();
  const errors = [], offsite = [];
  const watch = (pg, who) => { pg.on('pageerror', e => errors.push(who + ': ' + e.message)); pg.on('request', r => { if (!r.url().startsWith(B) && !r.url().startsWith('data:') && !r.url().startsWith('blob:' + B)) offsite.push(r.url()); }); };

  // A phone, the first page of the day: the moment and the falling decorations.
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pg = await ctx.newPage(); watch(pg, 'phone');
  await pg.goto(B + '/wp-login.php');
  await pg.fill('#user_login', 'emp1'); await pg.fill('#user_pass', 'emp1pass'); await pg.click('#wp-submit');
  await pg.goto(B + '/app/');
  await pg.waitForTimeout(1500);
  check('the moment and the falling layer are there', await pg.locator('.wfo-rm').count() === 1 && await pg.locator('.wfo-occ.is-full').count() === 1);
  // The probes below make the layers take taps for a moment: elementFromPoint skips pointer-events:none, so without this a layer
  // drawn on top would never be seen. What is checked is that they sit under the controls, not only that they ignore taps.
  const LAYERS = '.wfo-occ,.wfo-rm,.wfo-mo,.wfo-bday,.wfo-hw';
  const probe = (p, sel) => p.evaluate(sel => { const s = document.createElement('style'); s.id = 'wfo-probe'; s.textContent = sel + '{pointer-events:auto!important}'; document.head.appendChild(s); }, sel);
  const unprobe = p => p.evaluate(() => { const s = document.getElementById('wfo-probe'); if (s) s.remove(); });
  const layersIgnore = await pg.evaluate(sel => [...document.querySelectorAll(sel)].map(x => getComputedStyle(x).pointerEvents), LAYERS);
  await probe(pg, LAYERS);
  const tap = await pg.evaluate(() => {
    const btn = document.querySelector('.wfo-tab-clock'); if (!btn) return null;
    const r = btn.getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return { onButton: !!el && btn.contains(el), el: el && el.className };
  });
  check('during the moment a tap on Sign In / Out reaches the button', tap && tap.onButton, tap);
  check('...the decorations never take a tap', layersIgnore.length > 0 && layersIgnore.every(p => p === 'none'), layersIgnore);
  check('no sideways scroll', await pg.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await pg.waitForTimeout(6000);
  // The top bar's menu and the QR sign-in window open above the decorations.
  const above = await pg.evaluate(() => {
    const out = {};
    // A sign-in window like the QR one (time page, only with QR presence on), opened inside the page as it is there.
    const main = document.querySelector('.ews-main'); if (!main) return out;
    const m = document.createElement('div'); m.className = 'ews-face-modal is-open'; m.innerHTML = '<div class="ews-face-card"><button type="button" id="wfo-probe-btn">Sign In with QR</button></div>';
    main.appendChild(m);
    const b = document.getElementById('wfo-probe-btn'); const r = b.getBoundingClientRect();
    const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); out.qr = !!el && b.contains(el); out.el = el && el.className;
    m.remove();
    return out;
  });
  await unprobe(pg);
  // Without the probe: a tap on a card reaches the card (the falling layer draws over the page but never takes it).
  const card = await pg.evaluate(() => { const b = document.querySelector('.wfo-home .wfo-card, .wfo-home-today'); if (!b) return null; const r = b.getBoundingClientRect(); const el = document.elementFromPoint(r.left + 20, r.top + 20); return !!el && !el.closest('.wfo-occ,.wfo-rm'); });
  check('...the QR window\'s button is above the decorations', above.qr === true, above);
  const gone = await pg.evaluate(() => { const m = document.querySelector('.wfo-rm'); return !m || getComputedStyle(m).visibility === 'hidden' || getComputedStyle(m).opacity === '0'; });
  check('the moment is gone after a few seconds', gone);
  check('...and the cards are reachable under the decorations', card !== false, card);
  await pg.goto(B + '/app/');
  check('the next page: no moment (once a day)', await pg.locator('.wfo-rm').count() === 0 && await pg.locator('.wfo-occ-ramadan').count() === 1);

  // Reduced motion: nothing moves and nothing falls.
  php(`delete_user_meta((int)get_user_by('login','emp1')->ID,'ews_occasion_moment');`);
  const rctx = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce', storageState: await ctx.storageState() });
  const rp = await rctx.newPage(); watch(rp, 'reduced');
  await rp.goto(B + '/app/');
  await rp.waitForTimeout(500);
  const still = await rp.evaluate(() => ({ falling: (() => { const f = document.querySelector('.wfo-occ.is-full'); return f ? getComputedStyle(f).display : 'none'; })(),
    moving: [...document.querySelectorAll('.wfo-occ i,.wfo-rm *')].filter(x => getComputedStyle(x).animationName !== 'none').length }));
  check('reduced motion: nothing falls, nothing moves', still.falling === 'none' && still.moving === 0, still);

  // The kiosk: the code's card is on top.
  const kctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const kp = await kctx.newPage(); watch(kp, 'kiosk');
  await kp.goto(B + '/?ews_kiosk=' + ids.kid + '&kiosk_key=' + ids.secret);
  await kp.waitForTimeout(1200);
  await probe(kp, '.wfo-occ');
  const qr = await kp.evaluate(() => { const q = document.getElementById('qr'); if (!q) return null; const r = q.getBoundingClientRect();
    return [[r.left + 4, r.top + 4], [r.right - 4, r.top + 4], [r.left + 4, r.bottom - 4], [r.right - 4, r.bottom - 4]].every(([x, y]) => { const el = document.elementFromPoint(x, y); return !!el && !el.closest('.wfo-occ'); }); });
  check('kiosk: the decorations are there and the code\'s corners are not covered', await kp.locator('body.k-occ .wfo-occ').count() === 1 && qr === true, qr);

  check('every request stays on the site', offsite.length === 0, offsite.slice(0, 5));
  check('no JavaScript errors', errors.length === 0, errors);
  await browser.close();
  php(clean);
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

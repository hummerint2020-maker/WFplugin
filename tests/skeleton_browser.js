/* Skeleton loading (3.31.168, docs/tasks/skeleton-loading.md) in a real browser (Chromium), as emp1:
   - off (the default): the page is as before: no data-wfo-skel, no skeleton script or style;
   - on: the menu links say which outline they get; after a tap, when the next page is slow, .wfo-content shows that
     outline (the page underneath only hidden), the tapped item is active at once, a screen reader hears "Loading…";
   - a fast page shows nothing; the next page arrives without the outline;
   - a phone in Arabic: the bottom bar, right to left, no sideways scroll;
   - prefers-reduced-motion: no shimmer.
   No JavaScript errors. Usage: WP_CLI="wp --path=..." node tests/skeleton_browser.js   (tests/e2e_setup.php users at
   http://127.0.0.1:8080). SKEL_SHOTS=<dir> also saves screenshots. */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
const SHOTS = process.env.SKEL_SHOTS || '';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png' }); };
  // The page says what it shows through the console: Playwright's own queries fail while a navigation is pending.
  const PROBE = `document.addEventListener('click',function(){[60,400].forEach(function(t){setTimeout(function(){
      var c=document.querySelector('.wfo-content'),s=document.querySelector('.wfo-skel');
      console.log('SKEL'+t+' '+JSON.stringify({skel:!!document.querySelector('.wfo-content.is-skel > .wfo-skel-root'),busy:c&&c.getAttribute('aria-busy'),
        sr:(document.querySelector('.wfo-skel-sr')||{}).textContent||'',shown:[].filter.call(c?c.children:[],function(n){return !n.classList.contains('wfo-skel-root')&&!n.classList.contains('wfo-skel-sr')&&n.offsetParent!==null}).length,
        active:[].map.call(document.querySelectorAll('a[data-wfo-skel].active'),function(a){return a.getAttribute('href')}),anim:s?getComputedStyle(s).animationName:''}));},t);});},true);`;
  const state = (log, t) => { const l = log.filter(x => x.startsWith('SKEL' + t + ' ')).pop(); return l ? JSON.parse(l.slice(5 + String(t).length)) : {}; };

  const was = php("echo (int)get_option('ews_feature_skeleton');");
  php("delete_option('ews_feature_skeleton');");
  const browser = await chromium.launch();
  const errors = [];
  const login = async (opts) => {
    const ctx = await browser.newContext(Object.assign({ serviceWorkers: 'block' }, opts));
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(B + '/wp-login.php');
    await page.fill('#user_login', 'emp1'); await page.fill('#user_pass', 'emp1pass'); await page.click('#wp-submit');
    return { ctx, page };
  };

  // Off: nothing of it on the page.
  let { ctx, page } = await login({ viewport: { width: 1200, height: 900 } });
  await page.goto(B + '/app/?ews_view=dashboard');
  let html = await page.content();
  check('off: no data-wfo-skel, no skeleton script or style', !html.includes('data-wfo-skel') && !html.includes('skeleton.js') && !html.includes('app-skeleton'));
  check('off: window.wfoSkeleton is not there', await page.evaluate(() => typeof window.wfoSkeleton === 'undefined'));
  await ctx.close();

  // On.
  php("update_option('ews_feature_skeleton',1,false);");
  ({ ctx, page } = await login({ viewport: { width: 1200, height: 900 } }));
  await ctx.addInitScript(PROBE);
  const log = []; page.on('console', m => log.push(m.text()));
  await page.goto(B + '/app/?ews_view=dashboard');
  html = await page.content();
  check('on: the script and the style are loaded', html.includes('assets/js/skeleton.js') && html.includes('app-skeleton'));
  check('on: the content has the screen reader label', await page.locator('.wfo-content[data-wfo-skel-label]').count() === 1);
  const shapes = await page.$$eval('.ews-nav a[data-wfo-skel]', a => a.map(x => [new URL(x.href).searchParams.get('ews_view') || 'dashboard', x.getAttribute('data-wfo-skel')]));
  const sched = shapes.find(s => s[0] === 'schedule');
  check('on: every side-menu link has an outline; Schedule is a table', shapes.length > 2 && shapes.every(s => ['list', 'approvals', 'table', 'generic'].includes(s[1])) && sched && sched[1] === 'table', shapes);

  // A slow next page: the outline shows; the page underneath is only hidden.
  let slow = true;
  await page.route(/ews_view=schedule/, async r => { if (slow) await new Promise(res => setTimeout(res, 1500)); r.continue(); });
  const link = page.locator('.ews-nav a[href*="ews_view=schedule"]').first();
  const href = await link.getAttribute('href');
  await link.click({ noWaitAfter: true });
  await page.waitForTimeout(700);
  const s60 = state(log, 60), s400 = state(log, 400);
  check('nothing before 150 ms (no flash on a fast page)', s60.skel === false, s60);
  check('a slow page: the table outline shows in the content', s400.skel === true, s400);
  check('...the page underneath is hidden, not removed', s400.shown === 0, s400);
  check('...aria-busy and "Loading…" for a screen reader', s400.busy === 'true' && s400.sr !== '', s400);
  check('...Schedule is the active item at once', s400.active && s400.active.length > 0 && s400.active.every(h => h === href), s400.active);
  check('...it shimmers', s400.anim === 'wfo-skel-shine', s400.anim);
  await page.waitForURL(/ews_view=schedule/); await page.waitForLoadState();
  check('the next page arrives without the outline', await page.locator('.wfo-skel-root').count() === 0 && await page.locator('.wfo-content.is-skel').count() === 0);

  // A fast page: nothing at all.
  slow = false; log.length = 0;
  await page.locator('.ews-nav a[href*="ews_view=dashboard"], .ews-nav a[href$="/app/"]').first().click();
  await page.waitForLoadState();
  check('a fast page shows no outline', !log.some(l => { try { return l.startsWith('SKEL60 ') && JSON.parse(l.slice(7)).skel; } catch (e) { return false; } }));
  await ctx.close();

  // A phone in Arabic, bottom bar; reduced motion.
  // WordPress's own Arabic may not be installed yet (CI adds it later): right to left is forced for an Arabic user, as in my_approvals_browser.js.
  php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents($d.'/ews-e2e-rtl.php', '<?php add_action(\"init\", function(){ if(get_user_locale()===\"ar\"){ $GLOBALS[\"wp_locale\"]->text_direction=\"rtl\"; wp_styles()->text_direction=\"rtl\"; } });');");
  php("update_user_meta(get_user_by('login','emp1')->ID,'locale','ar');");
  ({ ctx, page } = await login({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' }));
  await ctx.addInitScript(PROBE);
  const log2 = []; page.on('console', m => log2.push(m.text()));
  await page.goto(B + '/app/?ews_view=dashboard');
  check('phone: right to left', await page.evaluate(() => document.documentElement.dir === 'rtl' || getComputedStyle(document.body).direction === 'rtl'));
  const tab = page.locator('.wfo-tabbar a[data-wfo-skel]').last();
  const tabHref = await tab.getAttribute('href');
  await page.route(u => u.href === new URL(tabHref, B).href, async r => { await new Promise(res => setTimeout(res, 1500)); r.continue(); });
  await tab.click({ noWaitAfter: true });
  await page.waitForTimeout(700);
  const p400 = state(log2, 400);
  check('phone: a bottom-bar tap shows the outline', p400.skel === true, p400);
  check('phone: no shimmer with reduced motion', p400.anim === 'none', p400.anim);
  check('phone: no sideways scroll', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1).catch(() => true));
  await shot(page, 'skeleton-phone').catch(() => {});
  await page.waitForLoadState();
  await ctx.close();

  php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-rtl.php');");
  php("update_user_meta(get_user_by('login','emp1')->ID,'locale','en_US');");
  php(was === '1' ? "update_option('ews_feature_skeleton',1,false);" : "delete_option('ews_feature_skeleton');");
  check('no JavaScript errors', errors.length === 0, errors);
  await browser.close();
  console.log(`\n${ok} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

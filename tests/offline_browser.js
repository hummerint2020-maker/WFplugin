/* The installed app's offline screen (3.31.93) in a real browser (Chromium), with its service worker:
   - off: nothing changes (the browser's own error page, as before);
   - on: no internet → the kept page ("No internet connection") with the schedule kept on the phone;
   - Sign In pressed with no internet → "You are not signed in" with the time; back online → the app
     opens by itself, the attempt is sent and a message says so;
   - the server down (503) or not answering in time → "not responding"; back by itself when it answers;
   - a text changed in Features reaches the phone the next time the app opens online;
   - logging out removes the schedule kept on the phone;
   - wp-admin: the preview follows what is typed.
   The server's state (cut off, down, slow) is set by a flag file read by a small mu-plugin.
   Usage: WP_CLI="wp --path=..." node tests/offline_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080) */
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8080';
const SHOTS = process.env.OFFLINE_SHOTS || '';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png' }); };

  // The server "down" or "slow" for the app page and the ping, switched by a flag file.
  const MU = "WPMU_PLUGIN_DIR.'/ews-e2e-offline.php'";
  php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents(" + MU + ", '<?php $f=__DIR__.\"/ews-e2e-offline.flag\"; $u=$_SERVER[\"REQUEST_URI\"]??\"\"; if(is_file($f)){ $m=trim((string)file_get_contents($f)); $app=strpos($u,\"/app/\")===0 || strpos($u,\"ews_pwa=ping\")!==false; if($m===\"cut\" && ($app || strpos($u,\"admin-post.php\")!==false)){ header(\"Content-Length: 5\"); header(\"Content-Length: 9\", false); exit(\"gone!\"); } if($app && $m===\"slow\"){sleep(7);} elseif($app && $m===\"down\"){status_header(503); exit(\"down\");} }');");
  const server = mode => php(mode ? "file_put_contents(WPMU_PLUGIN_DIR.'/ews-e2e-offline.flag','" + mode + "');" : "@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-offline.flag');");
  server('');
  const EID = Number(php("global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare(\"SELECT id FROM {$wpdb->prefix}ews_employees WHERE wp_user_id=%d\",get_user_by('login','emp1')->ID));"));
  php("global $wpdb; $p=$wpdb->prefix; $wpdb->query(\"DELETE FROM {$p}ews_signin_attempts\"); $wpdb->query(\"DELETE FROM {$p}ews_time_logs\"); $wpdb->query(\"DELETE FROM {$p}ews_audit_log\");"
    + " $wpdb->query($wpdb->prepare(\"DELETE FROM {$p}ews_schedule WHERE employee_id=%d\"," + EID + "));"
    + " $wpdb->insert($p.'ews_schedule',['employee_id'=>" + EID + ",'work_date'=>current_time('Y-m-d'),'status'=>'Office']);"
    + " $wpdb->insert($p.'ews_schedule',['employee_id'=>" + EID + ",'work_date'=>date('Y-m-d',strtotime(current_time('Y-m-d').' +1 day')),'status'=>'WFH']);"
    + " update_option('ews_working_days',[0,1,2,3,4,5,6],false); update_option('ews_working_hours',['start'=>date('H:i',current_time('timestamp')-3600),'end'=>'23:59','normal_until'=>'23:59'],false);"
    + " update_option('ews_signin_evidence',['attempts'=>1],false); delete_option('ews_offline_screen');");

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, permissions: ['geolocation'], geolocation: { latitude: 30.0444, longitude: 31.2357 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(page.url() + ': ' + e.message));
  const ready = () => page.evaluate(async () => {
    const reg = await navigator.serviceWorker.ready;
    // As a phone does when the app opens: look for a newer service worker, and wait until it runs.
    try { await reg.update(); } catch (e) {}
    for (let i = 0; i < 40 && (reg.installing || reg.waiting || (reg.active && reg.active.state !== 'activated')); i++) await new Promise(r => setTimeout(r, 250));
    return !!reg.active;
  });
  const swText = () => page.evaluate(async () => (await fetch('/?ews_pwa=sw', { cache: 'no-store' })).text());
  const state = () => page.evaluate(() => { const m = document.getElementById('wfo-offline'); return m ? m.getAttribute('data-state') : null; }).catch(() => null);
  const text = sel => page.locator(sel).innerText().catch(() => '');
  // No internet: the browser's offline mode (navigator.onLine, the page's own requests) and, for the
  // service worker's requests (which that mode does not reach in every Chromium version), the test server
  // answers the app's pages, the ping and admin-post.php with a response every browser refuses as a
  // network failure (two different Content-Length headers).
  const offline = async on => { await ctx.setOffline(on); server(on ? 'cut' : ''); };

  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'emp1'); await page.fill('#user_pass', 'emp1pass'); await page.click('#wp-submit');
  await page.goto(B + '/app/');
  await ready(); await page.reload(); await ready();

  // ------------------------------------------------------------ off (the default)
  check('off by default: the service worker has no offline page and the page is not served', !/const OFFLINE_URL/.test(await swText())
    && (await page.evaluate(async () => (await fetch('/?ews_pwa=offline')).status)) === 404);
  check('...and nothing is kept on the phone', await page.evaluate(() => localStorage.getItem('ewsOfflineSchedule')) === null);
  await offline(true);
  let failed = false; try { await page.goto(B + '/app/?ews_view=schedule'); } catch (e) { failed = true; }
  check('...with no internet the browser shows its own error, as before', failed);
  await offline(false);

  // ------------------------------------------------------------ on
  php("update_option('ews_offline_screen',['enabled'=>1,'timeout'=>5],false);");
  await page.goto(B + '/app/'); await ready(); await page.reload(); await ready();
  const sw = await swText();
  check('on: the service worker carries the offline page (with its revision) and the wait', /OFFLINE_URL=.*ews_pwa=offline.*rev=/.test(sw) && /OFFLINE_WAIT=5000/.test(sw));
  const kept = await page.evaluate(async () => { for (const k of await caches.keys()) { const c = await caches.open(k); for (const r of await c.keys()) if (/ews_pwa=offline/.test(r.url)) return true; } return false; });
  check('...the page is kept in the app\'s cache', kept);
  const sched = await page.evaluate(() => JSON.parse(localStorage.getItem('ewsOfflineSchedule') || 'null'));
  check('...the coming week is kept on the phone (7 days, today Office, tomorrow WFH)', sched && sched.days.length === 7 && sched.days[0].status === 'Office' && sched.days[1].status === 'WFH' && sched.days[0].hours !== '', sched && sched.days.slice(0, 2));

  await offline(true);
  await page.goto(B + '/app/?ews_view=schedule');
  check('no internet: the offline screen instead of the blank page', await state() === 'off' && (await text('#o-title')) === 'No internet connection', await state());
  check('...with the badge, the button and today\'s schedule from the phone', (await text('#o-badge')) === 'Offline' && (await text('#o-retry')) === 'Try again'
    && /Office/.test(await text('#o-today')) && /WFH/.test(await text('#o-week')) && /Last updated today at/.test(await text('#o-upd')));
  await shot(page, 'offline-1-no-internet');

  // ------------------------------------------------------------ Sign In pressed with no internet
  await offline(false);
  await page.waitForSelector('#ews-back-online', { timeout: 15000 }).catch(() => {});
  check('back online with nothing pending: the page opens by itself and says "You are back online."', (await text('#ews-back-online')) === 'You are back online.' && /ews_view=schedule/.test(page.url()), page.url());
  await page.goto(B + '/app/?ews_view=time');
  await offline(true);
  await page.evaluate(() => { const i = document.querySelector('form input[name="action"][value="ews31_time_event"]'); const f = i.form; window.ewsNoteAttempt(f); f.submit(); });
  await page.waitForSelector('#wfo-offline', { timeout: 10000 }).catch(() => {});
  check('Sign In pressed with no internet: "You are not signed in" with the time', await state() === 'press' && (await text('#o-ns-title')) === 'You are not signed in'
    && /Your Sign In attempt at \d{1,2}:\d\d [AP]M is saved on this phone/.test(await text('#o-ns-msg')), [await state(), await text('#o-ns-msg')]);
  check('...the clock and the "keep the app open" note', /\d{1,2}:\d\d/.test(await text('#o-clock-t')) && /Keep the app open/.test(await text('#o-keep')));
  await shot(page, 'offline-2-signin-pressed');
  check('...nothing was recorded', Number(php("global $wpdb; echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}ews_time_logs\");")) === 0);

  // Back online: the page opens the app by itself, the attempt is sent, a message says so.
  await offline(false);
  await page.waitForSelector('#ews-back-online', { timeout: 15000 }).catch(() => {});
  const toast = await text('#ews-back-online');
  check('back online: the app opens by itself and says the attempt was sent', /You are back online\. Your Sign In attempt at \d{1,2}:\d\d [AP]M was sent to your manager\. Sign In now\./.test(toast) && /ews_view=time/.test(page.url()), [toast, page.url()]);
  await page.waitForTimeout(1500);
  await shot(page, 'offline-3-back-online');
  const att = php("global $wpdb; echo wp_json_encode($wpdb->get_results(\"SELECT result,event_type FROM {$wpdb->prefix}ews_signin_attempts\"));");
  check('...the server has it as "did not reach the server", not as attendance', /"result":"not_reached".*"event_type":"sign_in"/.test(att)
    && Number(php("global $wpdb; echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}ews_time_logs\");")) === 0, att);
  check('...and it is not kept on the phone any more', await page.evaluate(() => localStorage.getItem('ewsPendingAttempt')) === null);

  // ------------------------------------------------------------ the server down / not answering
  server('down');
  await page.goto(B + '/app/?ews_view=schedule');
  check('the server down (503): "Workforce One is not responding", not the server\'s error page', await state() === 'down'
    && (await text('#o-title')) === 'Workforce One is not responding' && (await text('#o-badge')) === 'Not responding', [await state(), await text('body')]);
  await shot(page, 'offline-4-server-down');
  server('');
  await page.waitForSelector('#ews-back-online', { timeout: 15000 }).catch(() => {});
  check('...when it answers again the page opens by itself: "You are back online."', (await text('#ews-back-online')) === 'You are back online.' && /ews_view=schedule/.test(page.url()), page.url());
  server('slow');
  const t0 = Date.now();
  await page.goto(B + '/app/?ews_view=schedule').catch(() => {});
  const waited = Date.now() - t0;
  check('no answer within the set time (5 s here): the screen shows, not an endless wait', await state() === 'down' && waited >= 4500 && waited < 7000, waited);
  server('');
  await page.waitForTimeout(3000);

  // ------------------------------------------------------------ texts from Features reach the phone
  php("update_option('ews_offline_screen',['enabled'=>1,'timeout'=>5,'schedule'=>0,'texts'=>['off_title'=>'No network: call HR on 19999']],false);");
  await page.goto(B + '/app/'); await ready(); await page.reload(); await ready();
  await offline(true);
  await page.goto(B + '/app/?ews_view=schedule');
  check('a text changed in Features shows the next time the app opens online', (await text('#o-title')) === 'No network: call HR on 19999', await text('#o-title'));
  check('...and the schedule card can be turned off', !(await page.locator('#o-today').isVisible()));
  await offline(false);
  await page.waitForSelector('#ews-back-online', { timeout: 15000 }).catch(() => {});

  // ------------------------------------------------------------ logout
  await page.goto(B + '/app/');
  const out = await page.evaluate(() => { const a = document.querySelector('a[href*="action=logout"]'); return a ? a.href : ''; });
  if (out) await page.goto(out); else await ctx.clearCookies();
  await page.goto(B + '/app/');
  check('after logging out the schedule is no longer kept on the phone', await page.evaluate(() => localStorage.getItem('ewsOfflineSchedule')) === null);

  // ------------------------------------------------------------ wp-admin preview
  php("delete_option('ews_offline_screen');");
  const admin = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  admin.on('pageerror', e => errors.push(admin.url() + ': ' + e.message));
  await admin.goto(B + '/wp-login.php');
  await admin.fill('#user_login', 'admin'); await admin.fill('#user_pass', 'admin'); await admin.click('#wp-submit');
  await admin.goto(B + '/wp-admin/admin.php?page=ews31-features#ews-offline-screen');
  const frame = admin.frameLocator('#ews-offline-preview');
  await frame.locator('#o-title').waitFor({ timeout: 10000 }).catch(() => {});
  check('Features: the preview shows the real page while the screen is off', (await frame.locator('#o-title').innerText().catch(() => '')) === 'No internet connection');
  await admin.fill('#ews-offline-off_title', 'Typed in Features');
  await admin.waitForTimeout(300);
  check('...and follows what is typed', (await frame.locator('#o-title').innerText().catch(() => '')) === 'Typed in Features');
  await admin.click('[data-offline-state="down"]');
  await admin.waitForTimeout(1200);
  check('...for each state', (await frame.locator('#o-badge').innerText().catch(() => '')) === 'Not responding');
  if (SHOTS) { await admin.locator('#ews-offline-screen').screenshot({ path: SHOTS + '/offline-5-settings.png' }); }

  check('no JavaScript errors', errors.length === 0, errors);
  php("@unlink(" + MU + "); @unlink(WPMU_PLUGIN_DIR.'/ews-e2e-offline.flag'); delete_option('ews_offline_screen'); delete_option('ews_signin_evidence');");
  console.log(ok + ' / ' + (ok + fail));
  await browser.close();
  process.exit(fail ? 1 : 0);
})();

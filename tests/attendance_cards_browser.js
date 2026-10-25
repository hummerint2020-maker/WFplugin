/* Attendance cards you can open (3.31.125) in a real browser (Chromium):
   - a card is a button; it opens the side sheet at the end of the screen on a computer, with that card's list;
   - By day / By employee, "Show more" (20 at a time), the rate's day bars, a row opens the person's profile, "Open in Reports";
   - Esc closes it and the focus goes back to the card;
   - a phone in Arabic: a bottom sheet, right to left, no sideways scroll;
   - switched off: the cards do nothing.
   No JavaScript errors in the app. Seeds with tests/e2e_attendance_cards.py --seed (last week, 78 people) and cleans up.
   Usage: WP_CLI="wp --path=..." S=<state dir> node tests/attendance_cards_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080)
   ATC_SHOTS=<dir> also saves screenshots. */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
const SHOTS = process.env.ATC_SHOTS || '';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const shot = async (page, name) => { if (SHOTS) { await page.waitForTimeout(400); await page.screenshot({ path: SHOTS + '/' + name + '.png' }); } };

  const out = execFileSync('python3', [path.join(__dirname, 'e2e_attendance_cards.py'), process.env.S || '/tmp', '--seed', 'big'], { env: process.env }).toString().trim().split('\n').pop();
  const ctx = JSON.parse(out);
  const W = B + '/app/?ews_view=attendance&week=' + ctx.week;

  const browser = await chromium.launch();
  const errors = [];
  const login = async (viewport) => {
    const page = await (await browser.newContext({ viewport })).newPage();
    page.on('pageerror', e => { if (!/\/wp-admin\//.test(page.url())) errors.push(page.url() + ' :: ' + e.message); });
    await page.goto(B + '/wp-login.php');
    await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin'); await page.click('#wp-submit');
    await page.waitForLoadState('load');
    return page;
  };
  const rows = page => page.locator('#wfo-ac-sheet .wfo-ac-list > li').count();
  const waitRows = async (page) => { await page.waitForFunction(() => { const b = document.querySelector('#wfo-ac-sheet [data-ac-body]'); return b && !b.hasAttribute('aria-busy') && b.children.length; }, null, { timeout: 15000 }); };

  // ---------------------------------------------------------------- a computer, English
  const page = await login({ width: 1280, height: 860 });
  await page.goto(W);
  await page.waitForTimeout(1500);   // the app's splash
  const late = page.locator('[data-ews-card="late"]');
  check('the Late card is a button that opens a dialog', await late.evaluate(b => b.tagName === 'BUTTON' && b.getAttribute('aria-haspopup') === 'dialog'));
  await shot(page, 'atc-cards');
  await late.focus(); await page.keyboard.press('Enter');
  await waitRows(page);
  await page.waitForTimeout(400);   // the sheet's opening animation
  const box = await page.locator('#wfo-ac-sheet').boundingBox();
  check('...Enter opens a side sheet at the end of the screen', await page.$eval('#wfo-ac-sheet', d => d.open) && box && Math.abs(box.x + box.width - 1280) <= 2 && box.height >= 850, box);
  check('...with the late days, their sign-in and the minutes late', (await page.textContent('#wfo-ac-sheet [data-ac-title]')) === 'Late days' && /\d+ min late/.test(await page.textContent('#wfo-ac-sheet [data-ac-body]')) && (await rows(page)) >= 5);
  check('...the card says it is open', (await late.getAttribute('aria-expanded')) === 'true');
  const href = await page.locator('#wfo-ac-sheet a.wfo-ac-row').first().getAttribute('href');
  check('...a row opens the person\'s profile (owner decision 2)', /ews_view=employee/.test(href || '') && /employee_id=\d+/.test(href || ''), href);
  const rep = await page.locator('#wfo-ac-sheet [data-ac-report]').getAttribute('href');
  check('..."Open in Reports" opens the Daily Log of that week, on the late days', /report_type=attendance/.test(rep || '') && rep.includes('start=' + ctx.dates[0]) && /status=Late/.test(rep), rep);
  await shot(page, 'atc-late');
  await page.click('#wfo-ac-sheet [data-ac-order="employee"]');
  await page.waitForTimeout(300); await waitRows(page);
  const groups = await page.$$eval('#wfo-ac-sheet .wfo-ac-day h3', hs => hs.map(h => h.textContent));
  check('...By employee: one group per person', groups.length >= 3 && groups.every(g => !/Oct/.test(g)), groups);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  check('Esc closes it, the focus is back on the card, and the card is no longer marked open', !(await page.$eval('#wfo-ac-sheet', d => d.open)) && await late.evaluate(b => document.activeElement === b) && (await late.getAttribute('aria-expanded')) === 'false');

  await page.click('[data-ews-card="absent"]');
  await waitRows(page);
  const before = await rows(page);
  const shown = await page.textContent('#wfo-ac-sheet [data-ac-shown]');
  await page.click('#wfo-ac-sheet [data-ac-more]');
  await page.waitForFunction(n => document.querySelectorAll('#wfo-ac-sheet .wfo-ac-list > li').length > n, before, { timeout: 15000 });
  const after = await rows(page);
  check('Absent (a long list): 20 rows, "Show more" adds 20 and the count follows', before === 20 && after === 40 && /Showing 20 of \d+/.test(shown) && /Showing 40 of/.test(await page.textContent('#wfo-ac-sheet [data-ac-shown]')), [before, after, shown]);
  await shot(page, 'atc-absent');
  await page.click('#wfo-ac-sheet [data-wfo-sheet-close]');
  await page.click('[data-ews-card="rate"]');
  await page.waitForSelector('#wfo-ac-sheet .wfo-ac-bars li');
  check('Attendance rate: a bar for each day, the holiday without a rate', (await page.locator('#wfo-ac-sheet .wfo-ac-bars li').count()) === ctx.dates.length && (await page.locator('#wfo-ac-sheet .wfo-ac-bars li').last().textContent()).includes('—'));
  check('...no By day / By employee there', await page.$eval('#wfo-ac-sheet [data-ac-seg]', s => s.hidden));
  await shot(page, 'atc-rate');
  await page.click('#wfo-ac-sheet [data-wfo-sheet-close]');
  // A slow answer for one card, arriving after another card was opened, is never drawn.
  await page.route(/action=ews_att_cards.*kind=present/, async route => { await new Promise(r => setTimeout(r, 2000)); await route.continue(); });
  await page.click('[data-ews-card="present"]');
  await page.click('#wfo-ac-sheet [data-wfo-sheet-close]');
  await page.click('[data-ews-card="people"]');
  await waitRows(page);
  await page.waitForTimeout(2500);
  check('a slow answer for another card never replaces the list on screen', (await page.textContent('#wfo-ac-sheet [data-ac-title]')) === 'Employees' && (await page.locator('#wfo-ac-sheet .wfo-ac-mini').count()) > 0 && !/\d{2}:\d{2}/.test(await page.textContent('#wfo-ac-sheet [data-ac-body]')));
  await page.unroute(/action=ews_att_cards.*kind=present/);
  // "Show more" keeps the focus; on the last page it moves to the count, which is announced.
  await page.click('#wfo-ac-sheet [data-wfo-sheet-close]');
  await page.click('[data-ews-card="people"]');
  await waitRows(page);
  let guard = 0;
  while (await page.locator('#wfo-ac-sheet [data-ac-more]').isVisible() && guard++ < 10) {
    const n = await rows(page);
    await page.focus('#wfo-ac-sheet [data-ac-more]'); await page.keyboard.press('Enter');
    await page.waitForFunction(k => document.querySelectorAll('#wfo-ac-sheet .wfo-ac-list > li').length > k, n, { timeout: 15000 });
    await page.waitForTimeout(150);
  }
  check('..."Show more" to the end: the focus is on the count, which is announced', await page.evaluate(() => { const s = document.querySelector('#wfo-ac-sheet [data-ac-shown]'); return document.activeElement === s && s.getAttribute('aria-live') === 'polite'; }));
  await page.click('#wfo-ac-sheet [data-wfo-sheet-close]');

  // ---------------------------------------------------------------- a phone, Arabic
  // WordPress's own Arabic may not be installed yet (CI adds it later, and WPLANG then refuses "ar"): while this
  // mu-plugin is there, the page is right to left whatever the language.
  php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents($d.'/ews-e2e-rtl-atc.php', '<?php add_action(\"init\", function(){ $GLOBALS[\"wp_locale\"]->text_direction=\"rtl\"; wp_styles()->text_direction=\"rtl\"; });'); update_option('WPLANG','ar');");
  const phone = await login({ width: 390, height: 844 });
  await phone.goto(W);
  await phone.waitForTimeout(1500);
  await phone.click('[data-ews-card="late"]');
  await waitRows(phone);
  await phone.waitForTimeout(400);
  const pb = await phone.locator('#wfo-ac-sheet').boundingBox();
  check('a phone: a bottom sheet', pb && Math.abs(pb.y + pb.height - 844) <= 2 && pb.width >= 388, pb);
  const dir = await phone.evaluate(() => [getComputedStyle(document.body).direction, document.documentElement.scrollWidth, window.innerWidth]);
  check('...right to left', dir[0] === 'rtl', dir);
  check('...no sideways scroll', dir[1] <= dir[2] + 1, dir);
  await shot(phone, 'atc-phone-ar');
  php("update_option('WPLANG',''); @unlink(WPMU_PLUGIN_DIR.'/ews-e2e-rtl-atc.php');");

  // ---------------------------------------------------------------- off
  php("delete_option('ews_feature_attendance_cards');");
  await page.goto(W);
  await page.waitForTimeout(1200);
  await page.click('.ews-att-stat.late');
  await page.waitForTimeout(300);
  check('switched off: a card does nothing (no dialog on the page)', (await page.locator('#wfo-ac-sheet').count()) === 0 && (await page.locator('dialog[open]').count()) === 0);
  check('no JavaScript errors in the app', errors.length === 0, errors);
  await browser.close();

  php(`global $wpdb; $p=$wpdb->prefix; $u=new WP_User((int)get_user_by('login','emp1')->ID); $u->remove_cap('ews_manage_attendance');
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'ac%' AND domain_name<>'emp1'"); $wpdb->query("DELETE FROM {$p}ews_departments WHERE code LIKE 'ac-%'");
    $wpdb->query("DELETE FROM {$p}ews_employee_shift_periods"); delete_option('ews_shifts'); delete_option('ews_feature_attendance_cards');`);
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

/* HR letters (HL3, docs/tasks/hr-letter-hl3.md) in a real browser (Chromium):
   - My requests → New request → HR letter: the sheet opens from the list; "Other" shows its field (required); each type offers only
     its languages; the line under the form follows the type (made as a PDF / HR attaches it / collect it, "it shows your salary");
     the addressee's hint follows the language; sent, back on My requests with the letter waiting;
   - HR approves it in My approvals: the PDF is made, the employee downloads it from My requests (a PDF, not stored by the browser);
   - Types & templates: a placeholder chip goes where the cursor is, a mistyped placeholder is shown before saving;
   - a phone width: the sheet and My requests fit, no sideways scroll.
   No JavaScript errors anywhere. Seeds its own people and removes them after.
   Usage: WP_CLI="wp --path=..." node tests/hr_letters_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080)
   HL_SHOTS=<dir> also saves screenshots. */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
const SHOTS = process.env.HL_SHOTS || '';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: true }); };
  const clean = `global $wpdb; $p=$wpdb->prefix;
    foreach(['ews_hr_letters','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_hr_letters','ews_hr_letters','ews_hr_letter_types','ews_hr_letters_ever','ews_approval_settings','ews_feature_my_approvals'] as $o) delete_option($o);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='hlbhr'");`;

  // Hala is HR (EWS Administrator); letters and My approvals are on; both languages, "Other" allowed.
  php(clean + `
    update_option('ews_feature_my_approvals',1,false); update_option('ews_feature_hr_letters',1,false);
    update_option('ews_hr_letters',['languages'=>['ar','en'],'other'=>1,'one_open'=>1],false);
    $uid=username_exists('hlbhr')?:wp_create_user('hlbhr','hlbhrpass','hlbhr@example.com');
    (new WP_User($uid))->set_role('ews_administrator'); update_user_meta($uid,'locale','en_US');
    $wpdb->insert($p.'ews_employees',['name'=>'Hala HR','domain_name'=>'hlbhr','email'=>'hlbhr@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
    update_option('ews_approval_settings',['hr_user'=>(int)$uid],false);`);

  const browser = await chromium.launch();
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const ep = await ctx.newPage();
  ep.on('pageerror', e => errors.push('employee: ' + e.message));
  await ep.goto(B + '/wp-login.php');
  await ep.fill('#user_login', 'emp1'); await ep.fill('#user_pass', 'emp1pass'); await ep.click('#wp-submit');
  await ep.goto(B + '/app/?ews_view=requests');
  await ep.click('[data-wfo-sheet="wfo-new-sheet"]');
  await ep.waitForTimeout(300);
  check('New request lists HR letter', await ep.locator('[data-new-kind="letter"]').count() === 1);
  await ep.click('[data-new-kind="letter"]');
  await ep.waitForTimeout(300);
  check('...picking it opens the HR letter sheet', await ep.$eval('#wfo-new-letter', dl => dl.open) && !(await ep.$eval('#wfo-new-sheet', dl => dl.open)));
  const sheet = ep.locator('#wfo-new-letter');
  const hint = () => sheet.locator('[data-hl-hint]').textContent();
  check('the first type (a PDF): the line says it is made as a PDF, "Other" is hidden', /made as a PDF/.test(await hint()) && await sheet.locator('[data-hl-other]').isHidden(), await hint());
  // Each type offers only its own languages.
  const types = await sheet.locator('select[name="type"] option').evaluateAll(os => os.map(o => ({ v: o.value, l: o.getAttribute('data-languages') })));
  let langsOk = true;
  for (const t of types) {
    if (t.v === 'other') continue;
    await sheet.locator('select[name="type"]').selectOption(t.v);
    const on = await sheet.locator('input[name="language"]').evaluateAll(rs => rs.filter(r => !r.disabled).map(r => r.value).join(','));
    if (on !== t.l) { langsOk = false; console.log('  ' + t.v + ': ' + on + ' vs ' + t.l); }
  }
  check('each type offers only the languages it has (a PDF type: those with a template)', langsOk && types.length >= 5, types);
  await sheet.locator('select[name="type"]').selectOption('salary');
  check('the salary certificate: "It shows your salary."', /It shows your salary\./.test(await hint()), await hint());
  await sheet.locator('select[name="type"]').selectOption('embassy');
  check('an upload type: HR attaches it', /HR prepares the letter and attaches it/.test(await hint()), await hint());
  await sheet.locator('select[name="type"]').selectOption('bank');
  check('request only: where to collect it', /tells you where to collect it/.test(await hint()), await hint());
  await sheet.locator('select[name="type"]').selectOption('other');
  check('"Other" shows its field, required', await sheet.locator('[data-hl-other]').isVisible() && await sheet.locator('input[name="other_text"]').evaluate(i => i.required));
  await sheet.locator('input[name="language"][value="ar"]').check();
  check('the addressee\'s hint follows the language (Arabic)', await sheet.locator('input[name="addressee"]').getAttribute('placeholder') === 'إلى من يهمه الأمر');
  await sheet.locator('input[name="language"][value="en"]').check();
  check('...and English', await sheet.locator('input[name="addressee"]').getAttribute('placeholder') === 'To whom it may concern');
  await sheet.locator('[type="submit"]').click();
  await ep.waitForTimeout(300);
  check('"Other" without saying which letter is not sent (the browser asks)', await ep.$eval('#wfo-new-letter', dl => dl.open) && php(`global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ews_hr_letters");`) === '0');
  await sheet.locator('select[name="type"]').selectOption('employment');
  await sheet.locator('input[name="language"][value="en"]').check();
  await sheet.locator('input[name="addressee"]').fill('Bank Misr');
  await shot(ep, 'hl-sheet');
  await Promise.all([ep.waitForURL(/hl_sent=/), sheet.locator('[type="submit"]').click()]);
  const L = JSON.parse(php(`global $wpdb; echo wp_json_encode($wpdb->get_row("SELECT id,status,language,addressee FROM {$wpdb->prefix}ews_hr_letters ORDER BY id DESC LIMIT 1"));`) || 'null');
  check('sent: the letter waits, in English, to the bank', L && L.status === 'pending' && L.language === 'en' && L.addressee === 'Bank Misr', L);
  check('...listed on My requests', await ep.locator('[data-req="letter-' + (L ? L.id : 0) + '"]').count() === 1);

  // HR approves it in My approvals.
  const hctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const hp = await hctx.newPage();
  hp.on('pageerror', e => errors.push('HR: ' + e.message));
  await hp.goto(B + '/wp-login.php');
  await hp.fill('#user_login', 'hlbhr'); await hp.fill('#user_pass', 'hlbhrpass'); await hp.click('#wp-submit');
  await hp.goto(B + '/app/?ews_view=approvals');
  const card = hp.locator('.wfo-apv-item.tone-letter');
  check('HR sees the letter\'s card with "Approve and issue the letter"', await card.count() === 1 && /Approve and issue the letter/.test(await card.first().textContent()) && /Bank Misr/.test(await card.first().textContent()));
  await shot(hp, 'hl-hr-card');
  await Promise.all([hp.waitForURL(/hl_done=/, { timeout: 30000 }), card.first().locator('button[value="approve"]').click()]);
  check('...approved: the page says so and the letter is issued', await hp.locator('.wfo-apv-toast').count() === 1 && php(`global $wpdb; echo $wpdb->get_var("SELECT status FROM {$wpdb->prefix}ews_hr_letters WHERE id=${L ? L.id : 0}");`) === 'issued');

  // The employee downloads it.
  await ep.goto(B + '/app/?ews_view=requests');
  const link = ep.locator('[data-req="letter-' + (L ? L.id : 0) + '"] a[href*="ews_hr_letter_file"]');
  check('My requests offers the download', await link.count() === 1);
  const res = (await link.count()) ? await ep.request.get(await link.first().getAttribute('href')) : null;
  const body = res ? await res.body() : Buffer.from('');
  check('...a PDF, not kept by the browser', res && res.status() === 200 && body.slice(0, 5).toString() === '%PDF-' && /no-store/.test(res.headers()['cache-control'] || ''), res && res.headers());

  // Types & templates: the chips and the placeholder check.
  await hp.goto(B + '/wp-admin/admin.php?page=ews31-hr-letters&tab=types&type=employment');
  const area = hp.locator('textarea[data-hl-tpl]').last();
  await area.fill('Dear reader, ');
  await area.evaluate(a => { a.focus(); a.selectionStart = a.selectionEnd = a.value.length; });
  await hp.locator('[data-hl-chip="{employee_name}"]').last().click();
  check('a placeholder chip goes where the cursor is', (await area.inputValue()) === 'Dear reader, {employee_name}', await area.inputValue());
  await area.fill('Dear reader, {employee_name} {salary_net}');
  const bad = area.locator('xpath=..').locator('[data-hl-bad]');
  check('a mistyped placeholder is shown before saving', await bad.isVisible() && /\{salary_net\}/.test(await bad.textContent()), await bad.textContent());
  await area.fill('{g:يعمل|تعمل} {employee_name}');
  check('...a gender pair is fine', await bad.isHidden());
  await shot(hp, 'hl-types');

  // A phone: the sheet and My requests fit.
  const pctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pp = await pctx.newPage();
  pp.on('pageerror', e => errors.push('phone: ' + e.message));
  await pp.goto(B + '/wp-login.php');
  await pp.fill('#user_login', 'emp1'); await pp.fill('#user_pass', 'emp1pass'); await pp.click('#wp-submit');
  await pp.goto(B + '/app/?ews_view=requests');
  check('My requests on a phone: no sideways scroll', await pp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await pp.click('[data-wfo-sheet="wfo-new-sheet"]');
  await pp.waitForTimeout(300);
  await pp.click('[data-new-kind="letter"]');
  await pp.waitForTimeout(300);
  const fit = await pp.$eval('#wfo-new-letter', dl => { const r = dl.getBoundingClientRect(); return { open: dl.open, left: r.left, right: r.right, w: window.innerWidth, wide: [...dl.querySelectorAll('select,input,textarea,button')].every(el => el.getBoundingClientRect().right <= window.innerWidth + 1) }; });
  check('...the HR letter sheet fits the screen', fit.open && fit.left >= -1 && fit.right <= fit.w + 1 && fit.wide, fit);
  await shot(pp, 'hl-sheet-phone');

  check('no JavaScript errors', errors.length === 0, errors);
  await browser.close();
  php(clean + ` foreach(glob((function(){$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'private_files_dir'); $r->setAccessible(true); return $r->invoke($m,'workforce-one-letters');})().'/*.pdf') as $f) @unlink($f);`);
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

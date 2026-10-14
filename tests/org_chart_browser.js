/* Organization chart (3.31.97) in a real browser (Chromium):
   - wp-admin → People → Org chart: the head and the level under them drawn, deeper branches drawn when
     opened, a person's panel, the reporting line, search (opens the branches with a match), zoom;
   - Arabic: the chart reads from the right;
   - a phone-width wp-admin: one person at a time;
   - the app's My team on a phone: the reporting line, the direct reports, going down and back up.
   No JavaScript errors anywhere. Seeds its own people and removes them after.
   Usage: WP_CLI="wp --path=..." node tests/org_chart_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080)
   ORG_SHOTS=<dir> also saves screenshots. */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
const SHOTS = process.env.ORG_SHOTS || '';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: false }); };

  // The mockup's company: Ahmed (CEO) → 4 directors → leads → people; Khaled and Mariam have no manager.
  const E = JSON.parse(php(`global $wpdb; $p=$wpdb->prefix;
    $wpdb->query("DELETE FROM {$p}ews_employee_relationships"); $wpdb->query("DELETE FROM {$p}ews_departments");
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'org.%'");
    update_option('ews_org_chart',['enabled'=>1,'audience'=>'everyone','employee_view'=>'line','wide_span'=>3,'title_required'=>0],false);
    update_user_meta(1,'locale','en_US');
    $d=[]; foreach(['Management','Sales','Operations','Finance','IT'] as $n){$wpdb->insert("{$p}ews_departments",['name'=>$n,'code'=>strtoupper(substr($n,0,4)),'active'=>1]);$d[$n]=$wpdb->insert_id;}
    $emp1=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d",get_user_by('login','emp1')->ID));
    $rows=[['ahmed','Ahmed Samir','Chief Executive Officer','Management',''],['mona','Mona Adel','Sales Director','Sales','ahmed'],['karim','Karim Fathy','Operations Director','Operations','ahmed'],
      ['sara','Sara Nabil','Finance Manager','Finance','ahmed'],['omar','Omar Hamdy','IT Manager','IT','ahmed'],['nour','Nour Saeed','Team Lead, Cairo','Sales','mona'],['youssef','Youssef Gamal','Team Lead, Alexandria','Sales','mona'],
      ['hana','Hana Lotfy','Sales Representative','Sales','nour'],['mostafa','Mostafa Samir','Sales Representative','Sales','nour'],['laila','Laila Adel','Sales Representative','Sales','nour'],
      ['tarek','Tarek Fathy','Sales Representative','Sales','youssef'],['dina','Dina Nabil','Sales Representative','Sales','youssef'],['hassan','Hassan Hamdy','Customer Service Supervisor','Operations','karim'],
      ['rania','Rania Saeed','Customer Service Agent','Operations','hassan'],['ali','Ali Gamal','Customer Service Agent','Operations','hassan'],['wael','Wael Samir','Logistics Coordinator','Operations','karim'],
      ['mahmoud','Mahmoud Samir','Accountant','Finance','sara'],['yasmin','Yasmin Adel','Accountant','Finance','sara'],['amr','Amr Fathy','Systems Administrator','IT','omar'],
      ['heba','Heba Nabil','Developer','IT','omar'],['khaled','Khaled Hamdy','Driver','',''],['mariam','Mariam Saeed','Receptionist','','']];
    $e=['emp1'=>$emp1]; $now=current_time('mysql');
    foreach($rows as $r){$wpdb->insert("{$p}ews_employees",['name'=>$r[1],'domain_name'=>'org.'.$r[0],'email'=>$r[0].'@org.example.com','job_title'=>$r[2],'department_id'=>$r[3]?$d[$r[3]]:0,'active'=>1,'attendance_enabled'=>1]);$e[$r[0]]=$wpdb->insert_id;}
    $wpdb->update("{$p}ews_employees",['job_title'=>'Customer Service Agent','department_id'=>$d['Operations']],['id'=>$emp1]);
    $rows[]=['emp1','','','','hassan'];
    foreach($rows as $r)if($r[4])$wpdb->insert("{$p}ews_employee_relationships",['employee_id'=>$e[$r[0]],'related_employee_id'=>$e[$r[4]],'relationship_type'=>'supervisor','active'=>1,'created_at'=>$now,'updated_at'=>$now]);
    echo wp_json_encode($e);`).split('\n').pop());

  const browser = await chromium.launch();
  const errors = [];
  const login = async (ctx, user, pass) => {
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push(page.url() + ': ' + e.message));
    await page.goto(B + '/wp-login.php');
    await page.fill('#user_login', user); await page.fill('#user_pass', pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto('about:blank');
    errors.length = 0;
    return page;
  };
  const card = id => '#wfo-org-tree [data-sel="' + id + '"]';

  // ---------------------------------------------------------------- wp-admin, desktop
  let ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  let page = await login(ctx, 'admin', 'admin');
  await page.goto(B + '/wp-admin/admin.php?page=ews31-org-chart');
  await page.waitForSelector('.wfo-org-pc');
  check('the head and the level under them are drawn', await page.isVisible(card(E.ahmed)) && await page.isVisible(card(E.mona)) && await page.isVisible(card(E.omar)));
  check('...deeper branches only when opened', (await page.$(card(E.nour))) === null);
  check('the head is selected in the panel', (await page.textContent('#wfo-org-panel')).includes('Ahmed Samir') && (await page.textContent('#wfo-org-panel')).includes('Everyone under'));
  check('a card says how many report to them (Mona: 2 direct · 7 in total)', (await page.textContent(card(E.mona) + ' .wfo-org-more')).replace(/\s+/g, ' ').includes('2 direct · 7 in total'));
  await shot(page, 'admin-chart');
  await page.click(card(E.mona) + ' .wfo-org-more');
  check('opening Mona draws her two leads', await page.isVisible(card(E.nour)) && await page.isVisible(card(E.youssef)));
  await page.click(card(E.nour) + ' .wfo-org-more');
  check('opening Nour draws her people as a list', await page.isVisible('#wfo-org-tree .wfo-org-leaf[data-sel="' + E.hana + '"]') && (await page.$$('.wfo-org-stack .wfo-org-leaf')).length === 3);
  await page.click(card(E.mona) + ' .wfo-org-more');
  check('closing Mona hides her branch', (await page.$(card(E.nour))) === null);
  await page.click(card(E.sara));
  let panel = await page.textContent('#wfo-org-panel');
  check('selecting Sara shows her in the panel: department, manager, 2 direct reports', panel.includes('Sara Nabil') && panel.includes('Finance') && panel.includes('Ahmed Samir') && /Direct reports\s*2/.test(panel), panel.slice(0, 200));
  check('...with links to her profile and to change her manager', (await page.getAttribute('#wfo-org-panel a.button', 'href')).endsWith('employee_id=' + E.sara)
    && (await page.getAttribute('#wfo-org-panel a.button-primary', 'href')).includes('page=ews31-employees&s=Sara%20Nabil'));
  // Too many direct reports (more than 3): Nour has 3, Ahmed 4.
  check('a manager with more than 3 direct reports is flagged (Ahmed), with a chip above the chart', await page.isVisible(card(E.ahmed) + ' .wfo-org-flag') && (await page.$('[data-wide="' + E.ahmed + '"]')) !== null);
  check('people without a manager are listed above the chart, not in it', (await page.$('[data-without="' + E.khaled + '"]')) !== null && (await page.$(card(E.khaled))) === null);

  await page.fill('#wfo-org-q', 'rania');
  await page.waitForTimeout(400);
  check('search opens the branches down to the match (Rania under Hassan under Karim)', await page.isVisible('#wfo-org-tree [data-sel="' + E.rania + '"]'));
  check('...and dims the others on the way', await page.$eval(card(E.karim), el => el.classList.contains('is-dim')) && !(await page.$eval('#wfo-org-tree [data-sel="' + E.rania + '"]', el => el.classList.contains('is-dim'))));
  check('...other branches are not drawn', (await page.$(card(E.nour))) === null && (await page.$(card(E.sara))) === null);
  await shot(page, 'admin-search');
  await page.fill('#wfo-org-q', 'nobody-like-this');
  await page.waitForTimeout(400);
  check('a search with no match says so', (await page.textContent('#wfo-org-tree')).includes('Nobody matches this search.'));
  await page.fill('#wfo-org-q', '');
  await page.waitForTimeout(400);
  await page.click('[data-wide="' + E.ahmed + '"]');
  await page.click(card(E.ahmed) + ' .wfo-org-more');
  await page.click(card(E.ahmed) + ' .wfo-org-more');
  await page.click(card(E.karim) + ' .wfo-org-more');
  await page.click(card(E.hassan) + ' .wfo-org-more');
  await page.click('#wfo-org-tree [data-sel="' + E.emp1 + '"]');
  panel = await page.textContent('#wfo-org-panel');
  check('the panel shows the reporting line (3 people above)', (await page.$$('#wfo-org-panel .wfo-org-line button')).length === 3, panel.slice(0, 120));
  await page.click('#wfo-org-panel .wfo-org-line button >> nth=1');
  check('...selecting one of them there selects them in the chart', (await page.textContent('#wfo-org-panel')).includes('Karim Fathy') && await page.$eval(card(E.karim), el => el.classList.contains('is-sel')));
  await page.click('[data-zoom="-1"]');
  check('zoom out makes the chart smaller', (await page.$eval('.wfo-org-chart', el => el.style.transform)) === 'scale(0.85)');
  await page.click('[data-zoom="0"]');
  check('...and fit goes back', (await page.$eval('.wfo-org-chart', el => el.style.transform)) === 'scale(1)');
  await page.focus(card(E.omar));
  await page.keyboard.press('Enter');
  check('a card can be selected with the keyboard', (await page.textContent('#wfo-org-panel')).includes('Omar Hamdy'));
  check('no JavaScript errors (desktop)', errors.length === 0, errors);

  // ---------------------------------------------------------------- Arabic
  // Right to left even where WordPress's own Arabic files are not installed yet (CI installs them later).
  php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents($d.'/ews-e2e-rtl.php', '<?php add_action(\"init\", function(){ if(get_user_locale()===\"ar\") $GLOBALS[\"wp_locale\"]->text_direction=\"rtl\"; });'); update_user_meta(1,'locale','ar');");
  await page.goto(B + '/wp-admin/admin.php?page=ews31-org-chart');
  await page.waitForSelector('.wfo-org-pc');
  const pos = await page.$$eval('#wfo-org-tree > .wfo-org-chart > ul > li > ul > li > .wfo-org-pc', els => els.map(e => [e.getAttribute('data-sel'), Math.round(e.getBoundingClientRect().left)]));
  check('Arabic: the page is right to left and the chart reads from the right (the first of the 4 is rightmost)', await page.$eval('html', el => el.dir) === 'rtl' && pos.length === 4 && pos[0][1] > pos[1][1] && pos[1][1] > pos[3][1], pos);
  check('...the stylesheet for right to left is used', (await page.content()).includes('admin-org-chart-rtl.css'));
  check('...the texts are in Arabic', (await page.textContent('#wfo-org')).includes('الهيكل التنظيمي') && (await page.textContent(card(E.mona) + ' .wfo-org-more')).includes('مباشر'));
  await page.click(card(E.mona) + ' .wfo-org-more');
  await page.click(card(E.nour) + ' .wfo-org-more');
  await shot(page, 'admin-chart-ar');
  check('no JavaScript errors (Arabic)', errors.length === 0, errors);
  php("update_user_meta(1,'locale','en_US'); @unlink(WPMU_PLUGIN_DIR.'/ews-e2e-rtl.php');");
  await ctx.close();

  // ---------------------------------------------------------------- wp-admin at phone width
  ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  page = await login(ctx, 'admin', 'admin');
  await page.goto(B + '/wp-admin/admin.php?page=ews31-org-chart');
  await page.waitForSelector('.wfo-org-focus');
  check('phone width: one person at a time (the head, with the 4 who report to them)', (await page.textContent('.wfo-org-focus')).includes('Ahmed Samir') && (await page.$$('.wfo-org-row')).length === 4 && !(await page.isVisible('#wfo-org-panel')));
  await page.click('.wfo-org-row[data-focus="' + E.mona + '"]');
  check('...tapping someone goes to them, their manager above', (await page.textContent('.wfo-org-focus')).includes('Mona Adel') && (await page.$('.wfo-org-up[data-focus="' + E.ahmed + '"]')) !== null);
  await page.click('.wfo-org-row[data-focus="' + E.nour + '"]');
  check('...with teammates', (await page.textContent('.wfo-org-mates')).includes('Youssef'));
  await shot(page, 'admin-phone');
  const width = await page.evaluate(() => document.documentElement.scrollWidth);
  check('...no sideways scroll on the page', width <= 400, width);
  check('no JavaScript errors (phone width)', errors.length === 0, errors);
  await ctx.close();

  // ---------------------------------------------------------------- the app: My team
  ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  page = await login(ctx, 'emp1', 'emp1pass');
  await page.goto(B + '/app/?ews_view=team');
  await page.waitForSelector('.wfo-team');
  check('My team opens on the employee, their line above (Ahmed › Karim › Hassan)', (await page.textContent('.wfo-team-focus')).includes('Emp One') && (await page.$$('.wfo-team-up')).length === 3);
  check('...teammates under the same manager', (await page.textContent('.wfo-team-mates')).includes('Rania') && (await page.textContent('.wfo-team-mates')).includes('Ali'));
  await shot(page, 'app-team');
  await page.click('.wfo-team-up >> nth=2');
  await page.waitForSelector('.wfo-team-focus');
  check('...tapping the manager goes up: Hassan with his 3 direct reports', (await page.textContent('.wfo-team-focus')).includes('Hassan Hamdy') && (await page.$$('.wfo-team-row')).length === 3);
  await page.click('.wfo-team-stats a');
  check('...and "Back to me" comes back', (await page.textContent('.wfo-team-focus')).includes('Emp One'));
  await page.goto(B + '/app/?ews_view=team&person=' + E.mona);
  check('the line view: someone outside the line opens the employee instead', (await page.textContent('.wfo-team-focus')).includes('Emp One'));
  const w2 = await page.evaluate(() => document.documentElement.scrollWidth);
  check('...no sideways scroll', w2 <= 400, w2);
  check('no JavaScript errors (app)', errors.length === 0, errors);
  await ctx.close();
  await browser.close();

  php(`global $wpdb; $p=$wpdb->prefix; $wpdb->query("DELETE FROM {$p}ews_employee_relationships"); $wpdb->query("DELETE FROM {$p}ews_departments");
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'org.%'"); delete_option('ews_org_chart');
    $wpdb->update("{$p}ews_employees",['job_title'=>null,'department_id'=>0],['wp_user_id'=>get_user_by('login','emp1')->ID]);`);
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})();

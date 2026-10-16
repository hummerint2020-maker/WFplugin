/* My approvals (3.31.103) in a real browser (Chromium):
   - "Select" approves several requests at once (each with its own form, one after the other) and the
     page says how many;
   - rejecting without a reason, when the type needs one, opens the details and asks for it (nothing sent);
   - a phone width in Arabic: no sideways scroll, the cards read from the right;
   - My requests on a phone.
   No JavaScript errors anywhere. Seeds its own people and requests and removes them after.
   Usage: WP_CLI="wp --path=..." node tests/my_approvals_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080)
   APV_SHOTS=<dir> also saves screenshots. */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
const SHOTS = process.env.APV_SHOTS || '';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: true }); };

  // Mona manages emp1; three leave requests and one overtime wait for her. Overtime needs a reason to reject.
  const ids = JSON.parse(php(`global $wpdb; $p=$wpdb->prefix;
    foreach(['ews_leave_requests','ews_leave_balances','ews_overtime_requests','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    update_option('ews_feature_my_approvals',1,false); update_option('ews_feature_overtime',1,false);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false); update_option('ews_approval_reject_note',['overtime'=>1],false);
    $uid=username_exists('apvmona')?:wp_create_user('apvmona','apvmonapass','apvmona@example.com');
    update_user_meta($uid,'locale','en_US');
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='apvmona'");
    $wpdb->insert($p.'ews_employees',['name'=>'Mona Adel','domain_name'=>'apvmona','email'=>'apvmona@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]); $mona=(int)$wpdb->insert_id;
    $eid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d",get_user_by('login','emp1')->ID));
    $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>$eid,'relationship_type'=>'supervisor','related_employee_id'=>$mona,'active'=>1]);
    $o=new EWS_Manager_V31_1(); $cfg=new ReflectionMethod($o,'approval_configure_workflow'); $cfg->setAccessible(true);
    foreach(['vacation','overtime'] as $k) $cfg->invoke($o,$k,'LEVEL_1',[1=>['resolver_type'=>'SUPERVISOR','resolver_value'=>0]]);
    $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$eid,'leave_type_id'=>$type,'leave_year'=>(int)current_time('Y'),'entitlement'=>21,'used'=>0,'pending'=>3]);
    $bal=(int)$wpdb->insert_id; $st=new ReflectionMethod($o,'approval_start'); $st->setAccessible(true); $leaves=[];
    for($i=0;$i<3;$i++){$d=gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +'.(20+$i).' days'));
      $wpdb->insert($p.'ews_leave_requests',['employee_id'=>$eid,'leave_type_id'=>$type,'start_date'=>$d,'end_date'=>$d,'requested_days'=>1,'reason'=>'Family visit','status'=>'Pending','requested_by'=>get_user_by('login','emp1')->ID,'requested_at'=>current_time('mysql'),'balance_id'=>$bal]);
      $leaves[]=(int)$wpdb->insert_id; $st->invoke($o,'vacation','leave',(int)$wpdb->insert_id,$eid,['amount'=>1]);}
    $wpdb->insert($p.'ews_overtime_requests',['employee_id'=>$eid,'overtime_date'=>gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +2 days')),'start_time'=>'18:00:00','end_time'=>'21:00:00','requested_minutes'=>180,'reason'=>'Month end','status'=>'Pending','requested_by'=>get_user_by('login','emp1')->ID,'requested_at'=>current_time('mysql')]);
    $ot=(int)$wpdb->insert_id; $st->invoke($o,'overtime','overtime_request',$ot,$eid,['amount'=>3]);
    echo wp_json_encode(['leaves'=>$leaves,'ot'=>$ot]);`).split('\n').pop());

  const browser = await chromium.launch();
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'apvmona'); await page.fill('#user_pass', 'apvmonapass'); await page.click('#wp-submit');
  await page.goto(B + '/app/?ews_view=approvals');
  check('the inbox shows the four requests', await page.locator('[data-apv-item]').count() === 4);
  await shot(page, 'apv-desktop');

  // A reason is needed to reject overtime: nothing is sent without one.
  const otCard = page.locator('[data-apv-item]').filter({ hasText: 'Overtime' });
  await otCard.locator('[data-apv-reject]').click();
  await page.waitForTimeout(300);
  check('rejecting overtime without a reason opens the details and asks for one (no "Are you sure?" dialog)', await otCard.locator('details').evaluate(d => d.open) && await otCard.locator('[data-apv-note-err]').isVisible() && !(await page.locator('#ews-action-confirm-modal').count()));
  check('...nothing was sent', php(`global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}ews_overtime_requests WHERE id=%d",${ids.ot}));`) === 'Pending');

  // Select the three leave requests and approve them together.
  check('the "approve the selected" bar stays hidden until Select', !(await page.locator('[data-apv-bulk]').isVisible()) && await page.locator('.wfo-apv-acts').first().isVisible());
  await page.click('[data-apv-select]');
  check('"Select" shows a box on each card and the bar', await page.locator('.wfo-apv-pick:not([hidden])').count() === 4 && await page.locator('[data-apv-bulk]').isVisible());
  const leaveCards = page.locator('[data-apv-item]').filter({ hasText: 'Annual Leave' });
  for (let i = 0; i < 3; i++) await leaveCards.nth(i).locator('[data-apv-pick]').check();
  check('...the bar counts 3', (await page.locator('[data-apv-count]').textContent()).trim() === '3 selected');
  await shot(page, 'apv-selected');
  await Promise.all([page.waitForURL(/apv_bulk=/, { timeout: 30000 }), page.click('[data-apv-bulk-go]')]);
  check('...approved together: the page says 3 requests approved', (await page.locator('.wfo-apv-toast').textContent()).includes('3 requests approved'));
  const st = php(`global $wpdb; echo implode(',',$wpdb->get_col("SELECT status FROM {$wpdb->prefix}ews_leave_requests WHERE id IN (${ids.leaves.join(',')}) ORDER BY id"));`);
  check('...all three leaves are approved', st === 'Approved,Approved,Approved', st);
  check('...one request is left', await page.locator('[data-apv-item]').count() === 1);

  // A reason, then reject.
  const left = page.locator('[data-apv-item]').first();
  await left.locator('summary').click();
  await left.locator('textarea[name="note"]').fill('Not this month');
  await left.locator('[data-apv-reject]').click();
  // The app asks "Are you sure?" before rejecting overtime (as on the Overtime page).
  check('...with a reason, the app asks to confirm the rejection', await page.locator('#ews-action-confirm-modal').isVisible());
  await Promise.all([page.waitForURL(/ews_view=approvals/), page.click('#ews-action-confirm-modal .ews-ux-confirm')]);
  check('...with a reason the overtime is rejected and the inbox is empty', php(`global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}ews_overtime_requests WHERE id=%d",${ids.ot}));`) === 'Rejected' && await page.locator('.wfo-apv-empty').isVisible());

  // A phone in Arabic.
  // WordPress's own Arabic may not be installed yet (CI adds it later): right to left is forced for an Arabic user, as in org_chart_browser.js.
  php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents($d.'/ews-e2e-rtl.php', '<?php add_action(\"init\", function(){ if(get_user_locale()===\"ar\"){ $GLOBALS[\"wp_locale\"]->text_direction=\"rtl\"; wp_styles()->text_direction=\"rtl\"; } });');");
  php(`update_user_meta(username_exists('apvmona'),'locale','ar'); global $wpdb; $p=$wpdb->prefix; $o=new EWS_Manager_V31_1(); $st=new ReflectionMethod($o,'approval_start'); $st->setAccessible(true);
    $eid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d",get_user_by('login','emp1')->ID));
    $wpdb->insert($p.'ews_overtime_requests',['employee_id'=>$eid,'overtime_date'=>gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +3 days')),'start_time'=>'18:00:00','end_time'=>'20:00:00','requested_minutes'=>120,'reason'=>'Inventory','status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);
    $st->invoke($o,'overtime','overtime_request',(int)$wpdb->insert_id,$eid);`);
  const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() });
  const pp = await phone.newPage();
  pp.on('pageerror', e => errors.push(e.message));
  await pp.goto(B + '/app/?ews_view=approvals');
  const rtl = await pp.evaluate(() => document.documentElement.dir || getComputedStyle(document.body).direction);
  check('Arabic on a phone: right to left, no sideways scroll', rtl === 'rtl' && await pp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), rtl);
  const side = await pp.locator('[data-apv-item]').first().evaluate(el => { const s = getComputedStyle(el); return [s.borderRightWidth, s.borderLeftWidth]; });
  check('...the coloured edge of a card is on the right, the right-to-left styles are used', side[0] === '4px' && side[1] === '0px' && (await pp.content()).includes('app-approvals-rtl.css'), side);
  await pp.locator('[data-apv-item] summary').first().click();
  await shot(pp, 'apv-phone-ar');

  const ep = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  ep.on('pageerror', e => errors.push(e.message));
  await ep.goto(B + '/wp-login.php');
  await ep.fill('#user_login', 'emp1'); await ep.fill('#user_pass', 'emp1pass'); await ep.click('#wp-submit');
  await ep.goto(B + '/app/?ews_view=requests');
  check('My requests on a phone: the requests listed, no sideways scroll', await ep.locator('[data-req]').count() >= 5 && await ep.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await shot(ep, 'req-phone');
  check('no JavaScript errors', errors.length === 0, errors);

  await browser.close();
  php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-rtl.php');");
  php(`global $wpdb; $p=$wpdb->prefix; foreach(['ews_leave_requests','ews_leave_balances','ews_overtime_requests','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0"); $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='apvmona'");
    foreach(['ews_feature_my_approvals','ews_approval_reject_note'] as $o) delete_option($o); update_option('ews_feature_overtime',0,false);`);
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

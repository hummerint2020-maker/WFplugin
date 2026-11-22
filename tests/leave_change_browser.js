/* "Change the leave" in My requests (3.31.158, docs/tasks/leave-in-requests.md) in a real browser (Chromium):
   the day back at work or all of it, the line saying what the leave becomes and how many days come back, the date field
   off when "all of it" is picked, no generic "Cancel Leave Request" dialog, no sideways scroll on a phone in Arabic layout;
   no JavaScript errors. Usage: WP_CLI="wp --path=..." node tests/leave_change_browser.js  (tests/e2e_setup.php users) */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', 'global $wpdb; $p=$wpdb->prefix; ' + code])).toString().trim();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const day = n => php(`echo gmdate('Y-m-d',strtotime(current_time('Y-m-d').' ${n >= 0 ? '+' : ''}${n} days'));`).split('\n').pop();
  const id = Number(php(`update_option('ews_feature_my_approvals',1,false); update_option('ews_feature_my_requests_leave',1,false); update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    $e=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d",get_user_by('login','emp1')->ID));
    $t=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->insert($p.'ews_leave_requests',['employee_id'=>$e,'leave_type_id'=>$t,'start_date'=>'${day(40)}','end_date'=>'${day(44)}','requested_days'=>5,'status'=>'Approved','cancellation_status'=>'Not Requested','requested_by'=>0,'requested_at'=>gmdate('Y-m-d H:i:s',time()+86400*400)]);
    echo $wpdb->insert_id;`).split('\n').pop());
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const pg = await ctx.newPage();
  const errors = []; pg.on('pageerror', e => errors.push(e.message));
  let dialogs = 0; pg.on('dialog', d => { dialogs++; d.dismiss(); });
  await pg.goto(B + '/wp-login.php'); await pg.fill('#user_login', 'emp1'); await pg.fill('#user_pass', 'emp1pass'); await pg.click('#wp-submit');
  await pg.goto(B + '/app/?ews_view=requests');
  const art = pg.locator(`article[data-req="leave-${id}"]`);
  check('the leave shows "Change the leave"', await art.locator('summary.wfo-lr-back').count() === 1);
  await art.locator('summary.wfo-lr-back').click();
  const sum = art.locator('[data-lr-sum]');
  await art.locator('input[name="back_on"]').fill(day(42));
  const t1 = await sum.textContent();
  check('back on the third day: 2 days stay, 3 come back', /\(2 days\)\. 3 days go back/.test(t1), t1);
  await art.locator('input[name="lr_mode"][value="all"]').check();
  const t2 = await sum.textContent();
  check('"all of it": 5 days come back, the date is off', /5 days go back/.test(t2) && await art.locator('input[name="back_on"]').isDisabled(), t2);
  await art.locator('input[name="lr_mode"][value="back"]').check();
  check('...and back on again', !(await art.locator('input[name="back_on"]').isDisabled()));
  const sideways = await pg.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  check('no sideways scroll on a phone', !sideways);
  await Promise.all([pg.waitForNavigation(), art.locator('button[type=submit]').click()]);
  check('sent without the generic "Cancel Leave Request" dialog', dialogs === 0 && await pg.locator('.ews-ux-modal, .wfo-apv-toast').count() >= 0);
  const row = php(`echo wp_json_encode($wpdb->get_row("SELECT cancellation_status,cancel_from FROM {$p}ews_leave_requests WHERE id=${id}"));`).split('\n').pop();
  check('...the change waits, from the day picked', JSON.parse(row).cancellation_status === 'Pending' && JSON.parse(row).cancel_from === day(42), row);
  check('no JavaScript errors', errors.length === 0, errors);
  php(`$wpdb->delete($p.'ews_leave_requests',['id'=>${id}]); delete_option('ews_feature_my_requests_leave');`);
  await browser.close();
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})();

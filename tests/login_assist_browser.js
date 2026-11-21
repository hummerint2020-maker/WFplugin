/* Login page: the empty-form fix and the sign-in helpers (3.31.157, docs/tasks/login-assist.md) in a real browser (Chromium).
   - helpers off (the default): the page is as before; an empty form goes to the server and comes back to the app login, not wp-login.php;
   - helpers on: an empty field is pointed at without sending; a wrong password comes back with the username kept and the cursor in the
     password (never the password); Caps Lock shows a line; one tap on Sign In (a second tap sends nothing); a good sign-in opens the app;
   - no JavaScript errors.
   Usage: WP_CLI="wp --path=..." node tests/login_assist_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080) */
const path = require('path');
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
  const setAssist = on => php(`$c=(array)get_option('ews_appearance',[]); if(${on ? 1 : 0}) $c['login_assist']='1'; else unset($c['login_assist']); update_option('ews_appearance',$c,false);`);
  const back = pg => { const r = []; pg.on('response', x => { if (x.url().includes('wp-login.php') && x.status() === 302) r.push(x.headers()['location'] || ''); }); return r; };
  const app = php("echo wp_make_link_relative(get_permalink((int)get_option('ews_app_page_id')));") || '/app/';

  const browser = await chromium.launch();
  const errors = [];
  const fresh = async () => { const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } }); const pg = await ctx.newPage(); pg.on('pageerror', e => errors.push(e.message)); return [ctx, pg]; };

  // ---------------------------------------------------------------- off (the default)
  setAssist(false);
  let [ctx, pg] = await fresh();
  let loc = back(pg);
  await pg.goto(B + app);
  check('off: the card has no helpers', await pg.locator('.wfo-login-card[data-assist]').count() === 0 && await pg.locator('.wfo-login-field-msg').count() === 0);
  await pg.click('#ews_login_submit');
  await pg.waitForLoadState('load');
  check('off: an empty form comes back to the app login, not wp-login.php', pg.url().startsWith(B + app) && (loc[0] || '').includes('login_error=empty'), [pg.url(), loc]);
  check('...asking for both fields', (await pg.textContent('.ews-login-error')).includes('Please enter your username and password.'));
  await pg.fill('#ews_login_username', 'emp1'); await pg.click('#ews_login_submit'); await pg.waitForLoadState('load');
  check('off: a password left empty comes back the same way', pg.url().startsWith(B + app) && (loc[1] || '').includes('login_error=empty'), loc);
  await ctx.close();

  // ---------------------------------------------------------------- on
  setAssist(true);
  [ctx, pg] = await fresh();
  loc = back(pg);
  const posts = [];
  pg.on('request', r => { if (r.method() === 'POST' && r.url().includes('wp-login.php')) posts.push(r.url()); });
  await pg.goto(B + app);
  check('on: the card carries the helpers', await pg.locator('.wfo-login-card[data-assist="1"]').count() === 1);
  await pg.click('#ews_login_submit');
  await pg.waitForTimeout(300);
  check('on: an empty form is not sent', posts.length === 0, posts);
  check('...the username field says so and has the cursor', await pg.locator('#ews_login_username.is-invalid[aria-invalid=true]').count() === 1
    && await pg.evaluate(() => document.activeElement.id) === 'ews_login_username'
    && (await pg.textContent('#ews_login_user_msg')).includes('Enter your username or email')
    && await pg.getAttribute('#ews_login_username', 'aria-describedby') === 'ews_login_user_msg');
  await pg.type('#ews_login_username', 'emp1');
  check('...typing clears it', await pg.locator('#ews_login_username.is-invalid').count() === 0 && await pg.isHidden('#ews_login_user_msg'));
  await pg.click('#ews_login_submit'); await pg.waitForTimeout(300);
  check('a password left empty is pointed at, not sent', posts.length === 0 && await pg.evaluate(() => document.activeElement.id) === 'ews_login_password'
    && (await pg.textContent('#ews_login_pass_msg')).includes('Enter your password'));

  await pg.focus('#ews_login_password');
  // Headless Chromium does not toggle Caps Lock from the keyboard: the key events carry the modifier as a real one would.
  const key = on => pg.dispatchEvent('#ews_login_password', 'keyup', { key: 'a', modifierCapsLock: on });
  await key(true);
  const capsOn = await pg.isVisible('#ews_login_caps') && (await pg.getAttribute('#ews_login_password', 'aria-describedby') || '').includes('ews_login_caps');
  await key(false);
  const capsOff = await pg.isHidden('#ews_login_caps');
  check('Caps Lock shows a line under the password, and it goes when Caps Lock is off', capsOn && capsOff, { capsOn, capsOff });

  await pg.fill('#ews_login_password', 'wrong-pass');
  await Promise.all([pg.waitForNavigation(), pg.click('#ews_login_submit')]);
    await pg.waitForLoadState('load');
  check('a wrong password comes back to the app login', pg.url().startsWith(B + app) && (loc[loc.length - 1] || '').includes('login_error=1'), [pg.url(), loc]);
  const after = [await pg.inputValue('#ews_login_username'), await pg.inputValue('#ews_login_password'), await pg.evaluate(() => document.activeElement && document.activeElement.id)];
  check('...with the username kept and the cursor in the password', after[0] === 'emp1' && after[1] === '' && after[2] === 'ews_login_password', after);
  const kept = await pg.evaluate(() => JSON.stringify(Object.assign({}, sessionStorage)) + JSON.stringify(Object.assign({}, localStorage)) + document.cookie);
  check('...the password is kept nowhere', !kept.includes('wrong-pass'), kept);

  // One tap: the second submit while the first is on its way sends nothing more.
  posts.length = 0;
  await pg.fill('#ews_login_password', 'emp1pass');
  // What the page looks like the moment the form goes (a listener after the helpers' own), and a second tap right then.
  await pg.evaluate(() => document.addEventListener('submit', () => setTimeout(() => {
    const btn = document.querySelector('.wfo-login-submit.is-busy');
    sessionStorage.setItem('t_busy', JSON.stringify({ btn: !!btn && btn.disabled, text: btn ? btn.textContent : '', hidden: document.getElementById('ews_login_submit').hidden,
      said: (document.querySelector('.wfo-login-sr[role=status]') || {}).textContent || '' }));
    if (!sessionStorage.getItem('t_second')) { sessionStorage.setItem('t_second', '1'); document.getElementById('loginform').requestSubmit(); }
  }, 0)));
  await Promise.all([pg.waitForNavigation(), pg.click('#ews_login_submit')]);
  await pg.waitForLoadState('load');
  const snap = JSON.parse(await pg.evaluate(() => sessionStorage.getItem('t_busy')) || '{}');
  const busy = snap.btn && snap.text.includes('Signing in...') && snap.hidden && snap.said.includes('Signing in...');
  const second = snap;
  check('after tapping Sign In the button says "Signing in..." and waits', busy);
  check('...a second tap sends nothing more', posts.length === 1, [posts, second]);
  check('a good sign-in opens the app', !(await pg.content()).includes('id="ews_login_username"') && pg.url().startsWith(B + app), pg.url());
  check('...which forgets the username kept for the login', await pg.evaluate(() => sessionStorage.getItem('ewsLoginUser')) === null);
  await ctx.close();

  // Another script stops the sending (a captcha, a second step): the button does not wait, and the form still works.
  [ctx, pg] = await fresh();
  await pg.goto(B + app);
  await pg.evaluate(() => document.getElementById('loginform').addEventListener('submit', e => e.preventDefault()));
  await pg.fill('#ews_login_username', 'emp1'); await pg.fill('#ews_login_password', 'x');
  await pg.click('#ews_login_submit'); await pg.waitForTimeout(200);
  check('a sending stopped by another script leaves the Sign In button as it was', await pg.locator('.wfo-login-submit.is-busy').count() === 0 && await pg.isVisible('#ews_login_submit')
    && await pg.getAttribute('#loginform', 'data-busy') === null);
  await ctx.close();

  // A fresh tab after a wrong password elsewhere: nothing to bring back.
  [ctx, pg] = await fresh();
  await pg.goto(B + app + '?login_error=1');
  check('a fresh tab fills in nothing', await pg.inputValue('#ews_login_username') === '');
  await ctx.close();

  setAssist(false);
  check('no JavaScript errors', errors.length === 0, errors);
  await browser.close();
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})();

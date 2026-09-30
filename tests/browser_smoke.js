// Open every employee-app view in Chromium (as admin and as employee) and fail on
// JavaScript errors. Usage: node tests/browser_smoke.js [base-url]
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }

const B = process.argv[2] || 'http://127.0.0.1:8080';
const VIEWS = ['', 'schedule', 'time', 'vacation', 'overtime', 'tasks', 'attendance', 'reports',
               'attendance-insights', 'people', 'notifications', 'profile', 'presence'];
const USERS = [['admin', 'admin'], ['emp1', 'emp1pass']];

(async () => {
  const browser = await chromium.launch();
  let failures = 0;
  for (const [user, pass] of USERS) {
    const ctx = await browser.newContext({ permissions: ['geolocation'], geolocation: { latitude: 30.0445, longitude: 31.2358, accuracy: 20 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(B + '/wp-login.php');
    await page.fill('#user_login', user); await page.fill('#user_pass', pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    // Errors raised by WordPress core pages during login (e.g. profile.php) are not ours.
    await page.goto('about:blank');
    for (const v of VIEWS) {
      errors.length = 0;
      await page.goto(B + '/app/' + (v ? '?ews_view=' + v : ''), { waitUntil: 'load' });
      await page.waitForTimeout(400);
      const ok = errors.length === 0;
      if (!ok) failures++;
      console.log((ok ? 'ok   ' : 'FAIL ') + user.padEnd(6) + ' ' + (v || 'dashboard') + (ok ? '' : '  ' + errors.join(' | ')));
    }
    await ctx.close();
  }
  await browser.close();
  console.log(failures + ' failure(s)');
  process.exit(failures ? 1 : 0);
})();

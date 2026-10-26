/* My Profile → Upload Photo in a real browser (Chromium), 3.31.126:
   - a big photo is made 512 px at most in the browser before it is sent (it took the server seconds to receive and resize);
   - a small photo is sent as chosen; a PNG stays a PNG;
   - the saved picture is at most 512 px, and the page shows it.
   Usage: WP_CLI="wp --path=..." node tests/profile_photo_browser.js   (tests/e2e_setup.php users at http://127.0.0.1:8080) */
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const B = 'http://127.0.0.1:8080';
(async () => {
  const { execFileSync } = require('child_process');
  const wp = (process.env.WP_CLI || 'wp').split(' ').filter(Boolean);
  const php = code => execFileSync(wp[0], wp.slice(1).concat(['eval', code])).toString().trim().split('\n').pop();
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const saved = () => JSON.parse(php("global $wpdb; $e=$wpdb->get_row(\"SELECT profile_image_type t,profile_image_url u FROM {$wpdb->prefix}ews_employees WHERE domain_name='emp1'\"); $f=$e&&$e->u?str_replace(wp_upload_dir()['baseurl'],wp_upload_dir()['basedir'],$e->u):''; $s=$f&&is_file($f)?getimagesize($f):[0,0,'mime'=>'']; echo wp_json_encode(['type'=>$e->t??'','w'=>$s[0],'h'=>$s[1],'mime'=>$s['mime']??'','bytes'=>$f&&is_file($f)?filesize($f):0]);"));

  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 860 } })).newPage();
  const errors = [];
  page.on('pageerror', e => { if (!/\/wp-admin\//.test(page.url())) errors.push(page.url() + ' :: ' + e.message); });
  await page.goto(B + '/wp-login.php');
  await page.fill('#user_login', 'emp1'); await page.fill('#user_pass', 'emp1pass'); await page.click('#wp-submit');
  await page.waitForLoadState('load');

  // A picture drawn in the page, as a phone camera would give it.
  const make = async (w, h, type) => Buffer.from(await page.evaluate(([w, h, type]) => new Promise(res => {
    const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d');
    for (let i = 0; i < 400; i++) { g.fillStyle = 'hsl(' + (i * 37 % 360) + ',70%,50%)'; g.fillRect((i * 97) % w, (i * 53) % h, w / 7, h / 9); }
    c.toBlob(b => { const r = new FileReader(); r.onload = () => res(r.result.split(',')[1]); r.readAsDataURL(b); }, type, 0.95);
  }), [w, h, type]), 'base64');
  const choose = async (buf, name, mime) => {
    await page.goto(B + '/app/?ews_view=profile');
    await page.waitForTimeout(1200);
    await page.click('#ews-open-profile-picture');
    await page.click('#ews-open-photo-upload');
    await page.setInputFiles('#ews-photo-upload-panel input[type=file]', { name, mimeType: mime, buffer: buf });
    await page.waitForFunction(() => { const b = document.querySelector('#ews-photo-upload-panel button[type=submit]'); return b && !b.disabled; }, null, { timeout: 10000 });
    return page.evaluate(() => { const f = document.querySelector('#ews-photo-upload-panel input[type=file]').files[0]; return { name: f.name, type: f.type, size: f.size }; });
  };
  const send = async () => { await Promise.all([page.waitForNavigation(), page.click('#ews-photo-upload-panel button[type=submit]')]); };

  await page.goto(B + '/app/?ews_view=profile');
  const big = await make(3000, 2000, 'image/jpeg');
  const sent = await choose(big, 'IMG_2041.jpg', 'image/jpeg');
  check('a big photo (3000×2000, ' + Math.round(big.length / 1024) + ' KB) is made smaller in the browser before it is sent', sent.size < big.length / 3 && sent.type === 'image/jpeg' && sent.name === 'IMG_2041.jpg', [sent, big.length]);
  await send();
  let s = saved();
  check('...the saved picture is 512 × 341, a JPEG', s.type === 'photo' && s.w === 512 && s.h === 341 && s.mime === 'image/jpeg', s);
  check('...the page says it was saved', (await page.content()).includes('Profile picture updated successfully.'));

  const png = await make(1600, 1600, 'image/png');
  const sentPng = await choose(png, 'logo.png', 'image/png');
  check('a PNG stays a PNG', sentPng.type === 'image/png' && sentPng.name === 'logo.png' && sentPng.size < png.length, sentPng);
  await send();
  s = saved();
  check('...saved as 512 × 512', s.w === 512 && s.h === 512 && s.mime === 'image/png', s);

  const small = await make(300, 200, 'image/jpeg');
  const sentSmall = await choose(small, 'small.jpg', 'image/jpeg');
  check('a small photo is sent as chosen', sentSmall.size === small.length && sentSmall.name === 'small.jpg', [sentSmall, small.length]);
  await send();
  s = saved();
  check('...and saved at its own size', s.w === 300 && s.h === 200, s);
  // A second photo chosen while the first is still being made smaller: the second is sent (review, 3.31.126).
  const a = await make(3200, 2400, 'image/jpeg'), b2 = await make(240, 160, 'image/jpeg');
  await page.goto(B + '/app/?ews_view=profile');
  await page.waitForTimeout(1200);
  await page.click('#ews-open-profile-picture');
  await page.click('#ews-open-photo-upload');
  await page.setInputFiles('#ews-photo-upload-panel input[type=file]', { name: 'first.jpg', mimeType: 'image/jpeg', buffer: a });
  await page.setInputFiles('#ews-photo-upload-panel input[type=file]', { name: 'second.jpg', mimeType: 'image/jpeg', buffer: b2 });
  await page.waitForTimeout(2500);
  const last = await page.evaluate(() => { const f = document.querySelector('#ews-photo-upload-panel input[type=file]').files[0]; const b = document.querySelector('#ews-photo-upload-panel button[type=submit]'); return { name: f.name, size: f.size, disabled: b.disabled }; });
  check('a second photo chosen while the first is made smaller: the second is kept, and the button is free', last.name === 'second.jpg' && last.size === b2.length && !last.disabled, last);
  check('no JavaScript errors in the app', errors.length === 0, errors);
  await browser.close();
  php("global $wpdb; $wpdb->update($wpdb->prefix.'ews_employees',['profile_image_type'=>'initials','profile_image_url'=>'','avatar_key'=>null],['domain_name'=>'emp1']);");
  console.log(ok + ' / ' + (ok + fail));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

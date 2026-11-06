/* Confirmations before a decision (workforce-one.js, ewsConfirmationMeta) in a real browser (Chromium), with no server:
   the forms are written into a blank page and the plugin's own script is loaded. Covers both ways a form carries its
   decision: on the pressed button (name="decision", the Leave page) and in a hidden field (the Overtime page, whose
   Reject skipped the confirmation before). Usage: node tests/confirmations_browser.js */
const path = require('path');
const fs = require('fs');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const SCRIPT = fs.readFileSync(path.join(__dirname, '..', 'workforce-one', 'assets', 'js', 'workforce-one.js'), 'utf8');
(async () => {
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const hidden = (decision) => `<form method="post" action="/x" data-case="ot-${decision}"><input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="7"><input type="hidden" name="decision" value="${decision}"><button type="submit">${decision}</button></form>`;
  const named = `<form method="post" action="/x" data-case="el"><input type="hidden" name="action" value="ews_early_leave_respond"><button type="submit" name="decision" value="approve">approve</button><button type="submit" name="decision" value="reject">reject</button></form>`;
  // The test records a send from a listener on the document, which runs after the forms' own listeners.
  const load = async (cfg) => {
    await page.setContent(`<!doctype html><html><body>${hidden('reject')}${hidden('approve')}${named}</body></html>`);
    await page.evaluate(c => { window.ewsConfirmationConfig = c; document.addEventListener('submit', e => { if (!e.defaultPrevented) { e.preventDefault(); window.sent = (window.sent || []).concat(e.target.dataset.case); } }); }, cfg);
    await page.addScriptTag({ content: SCRIPT });
  };
  const press = async (sel) => { await page.click(sel); await page.waitForTimeout(100); return page.evaluate(() => ({ modal: !!document.getElementById('ews-action-confirm-modal'), title: (document.querySelector('#ews-action-confirm-modal h3') || {}).textContent || '', sent: window.sent || [] })); };

  await load({ global: true, actions: { overtime_reject: true, early_leave_reject: true } });
  let r = await press('form[data-case="ot-reject"] button');
  check('Overtime page: Reject (decision in a hidden field) asks first', r.modal && r.title === 'Reject Overtime Request' && r.sent.length === 0, r);
  if (r.modal) await page.click('#ews-action-confirm-modal .ews-ux-cancel');
  r = await press('form[data-case="ot-approve"] button');
  check('...Approve does not ask', !r.modal && r.sent.includes('ot-approve'), r);
  r = await press('form[data-case="el"] button[value="reject"]');
  check('Early leave: Reject on a named button asks, as before', r.modal && r.title === 'Reject Early Leave Request', r);
  await load({ global: true, actions: { overtime_reject: false } });
  r = await press('form[data-case="ot-reject"] button');
  check('the overtime confirmation switched off: Reject is sent at once', !r.modal && r.sent.includes('ot-reject'), r);
  await load({ global: false });
  r = await press('form[data-case="ot-reject"] button');
  check('all confirmations switched off: Reject is sent at once', !r.modal && r.sent.includes('ot-reject'), r);
  check('no JavaScript errors', errors.length === 0, errors);
  await browser.close();
  console.log(`${ok} / ${ok + fail}`);
  process.exit(fail ? 1 : 0);
})();

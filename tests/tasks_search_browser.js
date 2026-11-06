/* Tasks → search in a real browser (Chromium), with no server: the board is written into a blank page and the plugin's
   own tasks.js is loaded. Each column's count follows the search (owner decision 2026-11-06, QA report on 3.31.138: the
   counts count what is shown) and comes back when the search is cleared. Usage: node tests/tasks_search_browser.js */
const path = require('path');
const fs = require('fs');
let chromium;
try { ({ chromium } = require('playwright')); }
catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const SCRIPT = fs.readFileSync(path.join(__dirname, '..', 'workforce-one', 'assets', 'js', 'tasks.js'), 'utf8');
(async () => {
  let ok = 0, fail = 0;
  const check = (name, cond, extra) => { if (cond) { ok++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  | ' + JSON.stringify(extra) : '')); } };
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const card = (t) => `<article class="wfo-tk-card" data-ews-task="${t}"><h4 class="wfo-tk-title">${t}</h4></article>`;
  const col = (key, titles, total) => `<section class="wfo-tk-col is-${key}" data-ews-col="${key}"><h3 class="wfo-tk-col-head">${key} <span data-ews-col-count="${total}">${total}</span></h3>${titles.map(card).join('')}</section>`;
  await page.setContent(`<!doctype html><html><body><div class="wfo-tk"><input id="wfo-tk-search"><div class="wfo-tk-board">
    ${col('todo', ['order chairs', 'order badges', 'call supplier'], 3)}${col('in_progress', ['prepare roster'], 1)}${col('completed', ['order paper', 'file report'], 5)}
    <p class="wfo-tk-noresult" hidden>none</p></div></div></body></html>`);
  await page.addScriptTag({ content: SCRIPT });
  const counts = () => page.evaluate(() => Array.from(document.querySelectorAll('[data-ews-col-count]')).map(n => n.textContent));
  const type = async (q) => { await page.fill('#wfo-tk-search', q); await page.waitForTimeout(50); };

  check('without a search: the page\'s own counts (Completed counts every finished task, not only the cards shown)', JSON.stringify(await counts()) === '["3","1","5"]', await counts());
  await type('order');
  check('searching "order": To Do 2, In Progress 0, Completed 1', JSON.stringify(await counts()) === '["2","0","1"]', await counts());
  await type('zzz');
  check('...nothing matches: every count 0 and the message shows', JSON.stringify(await counts()) === '["0","0","0"]' && await page.isVisible('.wfo-tk-noresult'), await counts());
  await type('');
  check('...cleared: the counts come back', JSON.stringify(await counts()) === '["3","1","5"]', await counts());
  check('no script errors', errors.length === 0, errors);
  await browser.close();
  console.log(`${ok} / ${ok + fail}`);
  process.exit(fail ? 1 : 0);
})();

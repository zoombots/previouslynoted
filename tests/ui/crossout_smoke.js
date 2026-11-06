// Crossing out transcript lines from the list, hiding them, restoring; deleting added lines. node tests/ui/crossout_smoke.js
const { chromium } = require('playwright');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const shots = '/tmp/claude-0/pn-share-shots';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#notes li', { timeout: 15000 });
  await page.click('#tabScript');
  await page.waitForSelector('#script .tline', { timeout: 10000 });
  // Start clean: restore any crossed-out line on the first rows.
  const row = (n) => page.locator('#script li.tline[data-i]').nth(n);
  const r0 = row(2);
  const text = await r0.locator('.seg-text').innerText();
  if (await r0.locator('.seg-main.crossed').count()) { await r0.hover(); await r0.locator('.seg-x').click(); await page.waitForTimeout(600); }
  await r0.hover();
  check(await r0.locator('.seg-x').isVisible(), 'cross-out button shows on hover');
  await r0.locator('.seg-x').click();
  await page.waitForTimeout(700);
  const crossed = page.locator('#script li.tline', { hasText: text });
  check(await crossed.locator('.seg-main.crossed').count() === 1, 'line is crossed out');
  check(await crossed.locator('.seg-text.struck').count() === 1, 'text is struck through');
  check(await page.locator('#toast', { hasText: 'crossed out' }).count() === 1, 'toast offers undo');
  await page.screenshot({ path: `${shots}/crossed.png` });
  check(await page.locator('#scriptCutWrap').isVisible(), 'show crossed-out toggle appears');
  await page.uncheck('#scriptShowCut');
  check(await page.locator('#script li.tline', { hasText: text }).count() === 0, 'hidden when toggle off');
  await page.check('#scriptShowCut');
  // Keyboard: focus the line and press X to restore.
  await page.locator('#script li.tline', { hasText: text }).focus();
  await page.keyboard.press('x');
  await page.waitForTimeout(700);
  check(await page.locator('#script li.tline', { hasText: text }).locator('.seg-main.crossed').count() === 0, 'X restores the line');
  // Script view: add a line, approve it, delete it.
  await page.click('#scriptWideBtn');
  await page.waitForSelector('#script .tline.wide .cell-new', { timeout: 5000 });
  const w = page.locator('#script li.tline.wide[data-i]').nth(3);
  const before = await page.locator('#script li.tline.added').count();
  await w.locator('button:has-text("+ Line after")').click();
  await page.fill('#script .cell-new textarea', 'A brand new line to delete');
  await page.click('#script button:has-text("Save as approved")');
  await page.waitForTimeout(700);
  const added = page.locator('#script li.tline.added', { hasText: 'A brand new line to delete' });
  check(await added.count() === 1, 'added line is there');
  await added.locator('button:has-text("Delete line")').click();
  await page.waitForTimeout(700);
  check(await page.locator('#script li.tline.added', { hasText: 'A brand new line to delete' }).count() === 0, 'approved added line deleted');
  check(await page.locator('#script li.tline.added').count() === before, 'no extra lines left');
  await page.click('#scriptWideBtn');
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

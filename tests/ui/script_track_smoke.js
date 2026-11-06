// The Script track on the timeline: lines at their times, added lines, moving and resizing. node tests/ui/script_track_smoke.js
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
  await page.waitForSelector('#tlScript .sl', { timeout: 10000 }).catch(() => fails.push('script track shows lines'));
  check(await page.locator('#tlGutter .g-script').innerText() === 'TX', 'TX label in the gutter');
  // Add a line after the 4th transcript line in Script view; it appears on the track.
  await page.click('#tabScript');
  await page.click('#scriptWideBtn');
  await page.waitForSelector('#script .tline.wide .cell-new');
  const row = page.locator('#script li.tline.wide[data-i]').nth(1);
  await row.locator('button:has-text("+ Line after")').click();
  await page.fill('#script .cell-new textarea', 'A new line for the track test with a few more words');
  await page.click('#script button:has-text("Save draft")');
  await page.waitForTimeout(800);
  // The new line's row shows its time; clicking it goes there, which brings it into the zoomed timeline.
  const addedRow = page.locator('#script li.tline.added', { hasText: 'A new line for the track test' }).last();
  const key = await addedRow.getAttribute('data-key');
  check(/\d\d:\d\d:\d\d:\d\d/.test(await addedRow.locator('.seg-tc').innerText()), 'added row shows its time');
  await addedRow.locator('.seg-tc').click();
  await page.click('#scriptWideBtn');
  // Zoom in around the playhead so the line is wide enough to grab.
  await page.evaluate(() => { document.getElementById('zoom').value = '900'; document.getElementById('zoom').dispatchEvent(new Event('input')); });
  await page.waitForTimeout(500);
  const blk = page.locator(`#tlScript .sl[data-key="${key}"]`);
  check(await blk.count() === 1 && (await blk.getAttribute('class')).includes('added'), 'added line appears on the script track');
  await blk.scrollIntoViewIfNeeded();
  let b = await blk.boundingBox();
  if (!b) { fails.push('added block not visible'); }
  else {
    await page.screenshot({ path: `${shots}/script-track.png` });
    // Drag the right edge 60px to the right.
    const w0 = b.width;
    await blk.hover();
    await page.mouse.move(b.x + b.width - 2, b.y + b.height / 2);
    await page.mouse.down(); await page.mouse.move(b.x + b.width + 60, b.y + b.height / 2, { steps: 5 }); await page.mouse.up();
    await page.waitForTimeout(800);
    b = await blk.boundingBox();
    check(Math.abs(b.width - (w0 + 60)) < 8, `right edge lengthens the line (${w0} → ${b.width})`);
    check(await page.locator('#toast', { hasText: 'New line now' }).count() === 1, 'toast confirms the new timing');
    // Move the whole line 40px left.
    const x0 = b.x;
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.down(); await page.mouse.move(b.x + b.width / 2 - 40, b.y + b.height / 2, { steps: 5 }); await page.mouse.up();
    await page.waitForTimeout(800);
    b = await blk.boundingBox();
    check(Math.abs(b.x - (x0 - 40)) < 8, `dragging moves the line (${x0} → ${b.x})`);
    // Reload: timing kept.
    await page.reload();
    await page.waitForSelector('#tlScript .sl', { timeout: 10000 });
    await page.click('#tabScript');
    await page.click('#scriptWideBtn');
    await page.locator(`#script li.tline.added[data-key="${key}"] .seg-tc`).click();
    await page.click('#scriptWideBtn');
    await page.evaluate(() => { document.getElementById('zoom').value = '900'; document.getElementById('zoom').dispatchEvent(new Event('input')); });

    await page.waitForTimeout(500);
    const b2 = await page.locator(`#tlScript .sl[data-key="${key}"]`).boundingBox();
    check(b2 && Math.abs(b2.width - b.width) < 8, 'timing kept after reload');
    await page.screenshot({ path: `${shots}/script-track-after.png` });
  }
  // Clean up: delete the added line.
  await page.click('#tabScript');
  await page.click('#scriptWideBtn');
  await page.locator(`#script li.tline.added[data-key="${key}"] button:has-text("Delete line")`).click();
  await page.waitForTimeout(700);
  check(await page.locator(`#tlScript .sl[data-key="${key}"]`).count() === 0, 'deleted line leaves the track');
  await page.click('#scriptWideBtn');
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

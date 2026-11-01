// Browser smoke test of the multi-tenant UI. Run after scenario.py: node tests/mt/ui_smoke.js
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8082/';
const shots = '/tmp/claude-0/pnmt-shots';
require('fs').mkdirSync(shots, { recursive: true });
const fails = [];
async function session(browser, email, pw) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('ERR_TUNNEL')) errors.push(m.text()); });
  await page.goto(BASE);
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', pw);
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  return { ctx, page, errors };
}
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  const browser = await chromium.launch();
  // Francis: owner and platform admin.
  const f = await session(browser, 'francis@example.com', 'recovered-pass1');
  await f.page.waitForSelector('#notes li', { timeout: 10000 });
  check(await f.page.locator('#wsSelect option').count() === 2, 'Francis sees two productions in the picker');
  await f.page.screenshot({ path: `${shots}/francis.png` });
  for (const [btn, heading] of [['#peopleBtn', 'People'], ['#companyBtn', 'Rotating Planet'], ['#settingsBtn', 'Settings'], ['#platformBtn', 'All companies']]) {
    await f.page.click('#whoMenu summary');
    await f.page.click(btn);
    await f.page.waitForSelector(`#dlg h2:text-is("${heading}")`, { timeout: 5000 }).catch(() => fails.push(`dialog ${heading} didn’t open`));
    await f.page.screenshot({ path: `${shots}/dlg-${btn.slice(1)}.png` });
    await f.page.keyboard.press('Escape');
  }
  // Switch to Doc B.
  await Promise.all([f.page.waitForNavigation(), f.page.selectOption('#wsSelect', { label: 'Doc B' })]);
  await f.page.waitForSelector('#notes li', { timeout: 10000 });
  check((await f.page.locator('#filmSelect option').allTextContents()).join() === 'Film b', 'Doc B shows only its film');
  // See the app as Rex, then stop.
  await f.page.click('#whoMenu summary'); await f.page.click('#platformBtn');
  await f.page.waitForSelector('#dlg h2:text-is("All companies")');
  f.page.once('dialog', (d) => d.accept());
  await Promise.all([f.page.waitForNavigation(), f.page.locator('#dlg li', { hasText: 'rex@example.com' }).locator('button:text-is("See as them")').click()]);
  await f.page.waitForSelector('.imp-bar');
  await f.page.screenshot({ path: `${shots}/impersonating.png` });
  check(await f.page.locator('.imp-bar').innerText().then((t) => t.includes('Rex')), 'impersonation bar names Rex');
  await Promise.all([f.page.waitForNavigation(), f.page.click('#impStop')]);
  check(await f.page.locator('.imp-bar').count() === 0, 'impersonation stopped');
  check(f.errors.length === 0, 'Francis console errors: ' + f.errors.join(' | '));

  // Mia: viewer in Documentaries only.
  const m = await session(browser, 'mia@example.com', 'test-pass-123');
  await m.page.waitForSelector('#notes li', { timeout: 10000 });
  check(await m.page.locator('#wsSelect').count() === 0, 'Mia has one production, so no picker');
  check(await m.page.locator('#peopleBtn, #companyBtn, #settingsBtn, #platformBtn').count() === 0, 'Mia has no admin menus');
  check(await m.page.locator('#addBtn').isHidden(), 'viewer has no Add note');
  check(m.errors.length === 0, 'Mia console errors: ' + m.errors.join(' | '));

  // Nora: in the company but no production.
  const n = await session(browser, 'nora@example.com', 'test-pass-123');
  await n.page.waitForSelector('#dlg h2:text-is("No productions yet")', { timeout: 5000 }).catch(() => fails.push('Nora sees no-production message'));
  await n.page.screenshot({ path: `${shots}/nora.png` });

  // Rex: reviewer in Documentaries, editor in Doc B; production admin in neither.
  const r = await session(browser, 'rex@example.com', 'test-pass-123');
  await r.page.waitForSelector('#notes li', { timeout: 10000 });
  check(await r.page.locator('#wsSelect option').count() === 2, 'Rex sees his two productions');
  check(await r.page.locator('#peopleBtn').count() === 0, 'Rex has no People');
  check(r.errors.length === 0, 'Rex console errors: ' + r.errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

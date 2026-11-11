// Share links that also show the film's earlier versions. node tests/ui/share_older_smoke.js
const { chromium } = require('playwright');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#notes li', { timeout: 15000 });
  // The film with the most versions; share its second-to-last, so there's one newer version the guest mustn't see.
  const pick = await page.evaluate(async () => {
    const st = await (await fetch('api.php?action=state')).json();
    const byFilm = {};
    for (const v of st.versions) (byFilm[v.film_id] ||= []).push(v);
    const vs = Object.values(byFilm).sort((a, b) => b.length - a.length)[0].sort((a, b) => a.id - b.id);
    return { film: vs[0].film_id, shared: vs[vs.length - 2], newer: vs[vs.length - 1], older: vs.slice(0, -2) };
  });
  check(pick.older.length >= 1, 'test film has earlier versions');
  await page.selectOption('#filmSelect', String(pick.film));
  await page.waitForTimeout(800);
  await page.selectOption('#versionSelect', String(pick.shared.id));
  await page.waitForTimeout(800);
  await page.click('#shareBtn');
  await page.waitForSelector('#dlg h2:has-text("Share")');
  const olderBox = page.locator('#dlg label.inline', { hasText: 'earlier version' });
  check(await olderBox.count() === 1, 'option to show earlier versions');
  await olderBox.locator('input').check();
  await page.click('#dlg button.primary');
  await page.waitForSelector('#dlg .prow.fresh:has-text("with earlier versions")', { timeout: 5000 }).catch(() => fails.push('list says with earlier versions'));
  const link = await page.locator('#dlg .prow.fresh code').innerText();

  const g = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const gerr = [];
  g.on('pageerror', (e) => gerr.push(String(e)));
  await g.goto(link);
  check((await g.content()).includes('earlier versions'), 'welcome mentions earlier versions');
  await g.fill('input[name=name]', 'Olivia');
  await Promise.all([g.waitForNavigation(), g.click('button.primary')]);
  await g.waitForTimeout(2500);
  const opts = await g.locator('#versionSelect option').evaluateAll((os) => os.map((o) => +o.value));
  check(opts.length === pick.older.length + 1 && !opts.includes(pick.newer.id), `guest sees shared + earlier only (${opts})`);
  check(+(await g.locator('#versionSelect').inputValue()) === pick.shared.id, 'guest starts on the shared version');
  await g.selectOption('#versionSelect', String(pick.older[0].id));
  await g.waitForTimeout(1500);
  check(+(await g.locator('#versionSelect').inputValue()) === pick.older[0].id, 'guest opens an earlier version');
  const r = await g.evaluate(async ([older, newer]) => {
    const share = document.body.dataset.share;
    const st = async (v) => (await fetch('api.php?' + new URLSearchParams({ action: 'notes', share, version_id: v }))).status;
    return [await st(older), await st(newer)];
  }, [pick.older[0].id, pick.newer.id]);
  check(r[0] === 200 && r[1] === 404, 'API: earlier version allowed, newer one refused: ' + r);
  check(gerr.length === 0, 'guest page errors: ' + gerr.join(' | '));
  // Turn the link off (the Share box is still open on the team's page).
  page.once('dialog', (dd) => dd.accept());
  await page.locator('#dlg .prow', { hasText: link }).locator('button:has-text("Turn off")').click();
  await page.waitForTimeout(600);
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

// Pickers with actions, Tracks beside Add note, and share links with a guest. Run against the local test site:
//   node tests/ui/share_smoke.js   (http://127.0.0.1:8081, francis@example.com)
const { chromium } = require('playwright');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const shots = '/tmp/claude-0/pn-share-shots';
require('fs').mkdirSync(shots, { recursive: true });
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
const watch = (page, errors) => {
  page.on('pageerror', (e) => errors.push(String(e)));
  // Failed requests are recorded with their address below; the console's copy of them carries no address.
  page.on('console', (m) => { if (m.type() === 'error' && !/ERR_TUNNEL|Failed to load resource/.test(m.text())) errors.push(m.text()); });
  page.on('response', (r) => { if (r.url().includes('api.php') && r.status() >= 400) errors.push(`${r.status()} ${r.url()}`); });
};
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const page = await ctx.newPage();
  const errors = [];
  watch(page, errors);
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  // Sign in with Adobe again (the mock's tokens are used up by earlier runs), so Frame.io calls work.
  await page.goto(BASE + 'oauth.php?start=1');
  await page.waitForSelector('#notes li', { timeout: 15000 });
  check(await page.locator('#manageMenu').count() === 0, 'Manage menu is gone');
  const filmOpts = await page.locator('#filmSelect option').allTextContents();
  check(filmOpts.includes('＋ New film…') && filmOpts.includes('Rename or manage films…'), 'film picker actions: ' + filmOpts.join('|'));
  const verOpts = await page.locator('#versionSelect option').allTextContents();
  check(verOpts.includes('＋ New version…') && verOpts.includes('Manage versions…'), 'version picker actions: ' + verOpts.join('|'));
  const before = await page.locator('#versionSelect').inputValue();
  await page.selectOption('#versionSelect', '__new');
  await page.waitForSelector('#dlg details.new-cut[open]', { timeout: 5000 }).catch(() => fails.push('New version opens the new cut form'));
  check(await page.locator('#versionSelect').inputValue() === before, 'version picker goes back to the current version');
  await page.screenshot({ path: `${shots}/new-version.png` });
  await page.keyboard.press('Escape');
  await page.selectOption('#filmSelect', '__new');
  await page.waitForSelector('#dlg details.add-film[open]', { timeout: 5000 }).catch(() => fails.push('New film opens the add film form'));
  await page.keyboard.press('Escape');
  // Tracks sits next to Add note.
  const t = await page.locator('#tracksBtn').boundingBox(), a = await page.locator('#addBtn').boundingBox();
  check(t && a && Math.abs(t.y - a.y) < 4 && a.x - (t.x + t.width) < 20, 'Tracks is beside Add note');
  await page.click('#tracksBtn');
  await page.waitForSelector('#dlg h2', { timeout: 5000 });
  await page.keyboard.press('Escape');
  await page.screenshot({ path: `${shots}/header.png` });

  // Make a Playback link.
  await page.click('#shareBtn');
  await page.waitForSelector('#dlg h2:has-text("Share")');
  await page.selectOption('#dlg select >> nth=0', 'playback');
  await page.fill('#dlg input[maxlength="80"]', 'Broadcaster');
  await page.click('#dlg button.primary');
  await page.waitForSelector('#dlg .prow.fresh code', { timeout: 5000 });
  const link = await page.locator('#dlg .prow.fresh code').innerText();
  await page.screenshot({ path: `${shots}/share-dialog.png` });
  check(/\?share=[0-9a-f]{40}$/.test(link), 'link looks right: ' + link);
  const version = await page.locator('#versionSelect').inputValue();
  await page.keyboard.press('Escape');

  // The guest.
  const gctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const g = await gctx.newPage();
  const gerr = [];
  watch(g, gerr);
  await g.goto(link);
  check(await g.locator('input[name=name]').count() === 1, 'guest is asked their name');
  await g.screenshot({ path: `${shots}/guest-name.png` });
  await g.fill('input[name=name]', 'Brenda');
  await Promise.all([g.waitForNavigation(), g.click('button.primary')]);
  await g.waitForSelector('body[data-view="playback"]', { timeout: 15000 }).catch(() => fails.push('guest lands in Playback'));
  await g.waitForTimeout(1500);
  check(await g.locator('#views').isHidden(), 'guest has no view switcher');
  check(await g.locator('#filmSelect option').count() === 1 && await g.locator('#versionSelect option').count() === 1, 'guest sees one film and one version');
  check(await g.locator('#shareBtn, #tracksBtn:visible, #peopleBtn, #settingsBtn').count() === 0, 'guest has no team tools');
  await g.keyboard.press('1');
  check(await g.locator('body').getAttribute('data-view') === 'playback', 'guest stays in Playback');
  // Add a note and a reply from the guest's page.
  const res = await g.evaluate(async (vid) => {
    const share = document.body.dataset.share, csrf = document.body.dataset.csrf;
    const call = async (action, body, query = {}) => {
      const q = new URLSearchParams({ action, share, ...query });
      const r = await fetch('api.php?' + q, body ? { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
      return [r.status, await r.json()];
    };
    const out = {};
    out.create = await call('note.create', { version_id: vid, frame: 240, text: 'Guest note: the music is too loud here' });
    out.reply = await call('note.reply', { note_id: out.create[1].id, version_id: vid, text: 'And the title card is late' });
    out.edit = await call('note.edit', { note_id: out.create[1].id, text: 'Guest note: the music is a bit loud here' });
    out.other = await call('notes', null, { version_id: vid - 1 });
    out.status = await call('note.status', { note_id: out.create[1].id, version_id: vid, status: 'addressed' });
    out.people = await call('people');
    out.shares = await call('shares', null, { version_id: vid });
    return out;
  }, +version);
  check(res.create[0] === 200 && res.reply[0] === 200 && res.edit[0] === 200, 'guest adds, replies, rewords: ' + JSON.stringify([res.create, res.reply, res.edit]));
  check(res.other[0] === 404, 'guest can’t read another version: ' + res.other[0]);
  check(res.status[0] === 403 && res.people[0] === 403 && res.shares[0] === 403, 'guest can’t change status or see team tools');
  // Errors the test itself caused aren't page errors.
  await g.reload();
  await g.waitForTimeout(2000);
  await g.screenshot({ path: `${shots}/guest-playback.png` });

  // The team sees the guest's note with the chip.
  await page.reload();
  await page.waitForSelector('#notes li', { timeout: 15000 });
  const gl = page.locator(`#notes li[data-id="${res.create[1].id}"]`);
  check(await gl.locator('.guest-chip').count() === 1, 'guest note has the guest chip');
  await gl.click();
  await page.waitForSelector('.slate .thread .guest-chip', { timeout: 5000 }).catch(() => fails.push('guest reply has the chip'));
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${shots}/team-sees-guest.png` });
  // Turn the link off: the guest is out.
  await page.click('#shareBtn');
  await page.waitForSelector('#dlg .prow');
  page.once('dialog', (dd) => dd.accept());
  await page.locator('#dlg .prow', { hasText: link }).locator('button:has-text("Turn off")').click();
  await page.waitForTimeout(800);
  await g.goto(link);
  check((await g.content()).includes('doesn’t work any more'), 'turned-off link is dead');
  check(errors.length === 0, 'team page errors: ' + errors.join(' | '));
  const gReal = gerr.filter((e) => !(/40[34]/.test(e) && /(note\.status|people|shares|action=notes)/.test(e)));
  check(gReal.length === 0, 'guest page errors: ' + gReal.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

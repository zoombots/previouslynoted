// The Download script dialog and its three files. node tests/ui/script_download_smoke.js
const { chromium } = require('playwright');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#notes li', { timeout: 15000 });
  await page.click('#tabScript');
  await page.waitForSelector('#script .tline');
  // Make one rewrite, one cross-out and one added line, through the API, then reload.
  const setup = await page.evaluate(async () => {
    const v = document.getElementById('versionSelect').value, csrf = document.body.dataset.csrf;
    const sc = await (await fetch(`api.php?action=script&version_id=${v}`)).json();
    const post = (body) => fetch('api.php?action=script.edit', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      body: JSON.stringify({ version_id: +v, transcript_id: sc.transcript_id, ...body }) }).then((r) => r.json());
    await post({ seg_idx: 10, slot: 0, state: 'approved', text: 'DLTEST rewritten line ten' });
    await post({ seg_idx: 11, slot: 0, state: 'cut', text: '' });
    const r = await post({ seg_idx: 12, slot: 'new', state: 'approved', text: 'DLTEST added after twelve' });
    return { v, slot: r.line.slot, tr: (await (await fetch(`api.php?action=transcript&version_id=${v}`)).json()).segments.slice(10, 13).map((s) => s.text) };
  });
  await page.reload();
  await page.waitForSelector('#notes li', { timeout: 15000 });
  await page.click('#tabScript');
  await page.click('#scriptDl');
  await page.waitForSelector('#dlg h2:has-text("Download the script")');
  check(await page.locator('#dlg .dl-choice').count() === 6, 'three content choices and two file types');
  await page.screenshot({ path: '/tmp/claude-0/pn-share-shots/script-download.png' });
  const get = async (content, format, extra = {}) => page.evaluate(async ([v, c, f, x]) => {
    const q = new URLSearchParams({ action: 'script.export', version_id: v, content: c, format: f, ...x });
    return (await fetch('api.php?' + q)).text();
  }, [setup.v, content, format, extra]);
  const orig = await get('original', 'txt');
  // Leave the test lines in place until the PDFs are made (cleanup is at the end).
  check(orig.includes(setup.tr[0]) && orig.includes(setup.tr[1]) && !orig.includes('DLTEST'), 'original: transcript only');
  const merged = await get('merged', 'txt');
  check(merged.includes('* [') && merged.includes('DLTEST rewritten line ten') && !merged.includes(setup.tr[0]), 'merged: new wording replaces the line');
  check(!merged.includes(setup.tr[1]), 'merged: crossed-out line left out');
  check(merged.includes('+ [') && merged.includes('DLTEST added after twelve'), 'merged: added line in place');
  check(merged.indexOf('DLTEST rewritten') < merged.indexOf('DLTEST added'), 'merged: in order');
  const mergedCut = await get('merged', 'txt', { show_cut: '1' });
  check(mergedCut.includes('[crossed out] ' + setup.tr[1]), 'merged with crossed-out lines marked');
  const changes = await get('changes', 'txt');
  check(changes.includes('DLTEST rewritten') && changes.includes('CROSSED OUT') && changes.includes('DLTEST added') && !changes.includes(setup.tr[2] + '\n'), 'changes only');
  const csv = await get('merged', 'csv');
  check(csv.split('\n')[0].replace('\ufeff', '').startsWith('Change,') && csv.includes('REWRITTEN,') && csv.includes('NEW,') && csv.includes('★ DLTEST rewritten'), 'merged CSV marks changes');
  // PDFs of each kind, saved for a look.
  for (const c of ['original', 'merged', 'changes']) {
    const b64 = await page.evaluate(async ([v, c]) => {
      const r = await fetch('api.php?' + new URLSearchParams({ action: 'script.export', version_id: v, content: c, format: 'pdf', show_cut: '1' }));
      const buf = new Uint8Array(await r.arrayBuffer());
      let s = ''; for (const x of buf) s += String.fromCharCode(x);
      return [r.headers.get('content-type'), btoa(s)];
    }, [setup.v, c]);
    check(b64[0] === 'application/pdf', 'PDF content type');
    require('fs').writeFileSync(`/tmp/claude-0/pn-share-shots/script-${c}.pdf`, Buffer.from(b64[1], 'base64'));
  }
  // Download through the dialog.
  await page.locator('#dlg .dl-choice', { hasText: 'Only the script changes' }).click();
  check(await page.locator('#dlg input[name=dlfmt][value=pdf]').isChecked(), 'PDF is the default');
  await page.locator('#dlg .dl-choice', { hasText: 'Spreadsheet' }).click();
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#dlg button.primary')]);
  check(/_script_changes\.csv$/.test(dl.suggestedFilename()), 'dialog downloads the chosen file: ' + dl.suggestedFilename());
  // Clean up.
  await page.evaluate(async ([v, slot]) => {
    const csrf = document.body.dataset.csrf;
    const sc = await (await fetch(`api.php?action=script&version_id=${v}`)).json();
    const post = (body) => fetch('api.php?action=script.edit', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      body: JSON.stringify({ version_id: +v, transcript_id: sc.transcript_id, ...body }) });
    await post({ seg_idx: 10, slot: 0, state: 'same', text: '' });
    await post({ seg_idx: 11, slot: 0, state: 'same', text: '' });
    await post({ seg_idx: 12, slot, state: 'removed', text: '' });
  }, [setup.v, setup.slot]);
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

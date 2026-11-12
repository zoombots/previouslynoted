// A note can be added on a new line (one added after a transcript line) in Script view; it sits at that line's
// time and shows on that row, not on the line before. node tests/ui/added_line_note_smoke.js
const { chromium } = require('playwright');
const fs = require('fs'), { execSync } = require('child_process');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const WAV = '/tmp/claude-0/pn-sound/narration.wav';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  if (!fs.existsSync(WAV)) {
    fs.mkdirSync('/tmp/claude-0/pn-sound', { recursive: true });
    execSync(`ffmpeg -loglevel error -y -f lavfi -i "sine=frequency=330:duration=30" -ac 2 -ar 48000 ${WAV}`);
  }
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  const api = (action, body, query) => page.evaluate(async ([a, b, q]) => {
    const r = await fetch('api.php?' + new URLSearchParams({ action: a, ...(q || {}) }), b ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.dataset.csrf }, body: JSON.stringify(b) } : {});
    return r.json();
  }, [action, body, query]);
  for (const v of (await api('state')).versions.filter((x) => x.label === 'Added note test')) await api('version.delete', { version_id: v.id });
  await page.reload();
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });

  // A throwaway cut with a transcript.
  await page.selectOption('#versionSelect', '__new');
  await page.waitForSelector('dialog[open] select[name=kind]');
  await page.fill('dialog input[name=label]', 'Added note test');
  await page.selectOption('dialog select[name=carry]', '').catch(() => {});
  await page.selectOption('dialog select[name=kind]', 'sound');
  await page.setInputFiles('dialog .sound-new input[type=file]', WAV);
  await page.click('dialog button:has-text("Add this cut")');
  await page.waitForSelector('#toast:has-text("Transcript ready")', { timeout: 40000 }).catch(() => fails.push('transcribed'));
  const vid = +(await page.inputValue('#versionSelect'));

  // Add a line after the 2nd transcript line, in Script view.
  await page.click('#tabScript');
  await page.click('#scriptWideBtn');
  await page.waitForSelector('#script .tline.wide .cell-new');
  await page.locator('#script li.tline.wide[data-i="1"] button:has-text("+ Line after")').click();
  await page.fill('#script .cell-new textarea', 'ADDEDNOTE a new line that needs a note');
  await page.click('#script button:has-text("Save draft")');
  await page.waitForTimeout(800);
  const row = page.locator('#script li.tline.added', { hasText: 'ADDEDNOTE' }).last();
  const key = await row.getAttribute('data-key');
  const input = row.locator('.add-note input');
  check(await input.count() === 1, 'note box on the added line');
  await input.fill('Needs a warmer read on this new line');
  await input.press('Enter');
  await page.waitForSelector('#toast:has-text("Note added on this line")', { timeout: 8000 }).catch(() => fails.push('note added toast'));
  await page.waitForTimeout(500);

  const sc = await api('script', null, { version_id: vid });
  const t = sc.times?.[key];
  const notes = (await api('notes', null, { version_id: vid })).notes || [];
  const n = notes.find((x) => x.text === 'Needs a warmer read on this new line');
  check(n && t && n.frame === t[0], `note sits at the added line's time: ${n?.frame} vs ${t && t[0]}`);
  const rowAgain = page.locator(`#script li.tline.added[data-key="${key}"]`);
  check(await rowAgain.locator('.nchip', { hasText: 'Needs a warmer read' }).count() === 1, 'note shows on the added row');
  check(await page.locator('#script li.tline.wide[data-i="1"] .nchip', { hasText: 'Needs a warmer read' }).count() === 0, 'not on the line before');
  await page.screenshot({ path: '/tmp/claude-0/pn-sound/added-note.png' });

  await api('version.delete', { version_id: vid });
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

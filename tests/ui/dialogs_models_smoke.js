// Every dialog closes on a click outside it (but not when a text selection ends outside), and the voice-over
// model picker lists the speech models: the account's, or ElevenLabs' standard ones when the key can't list them.
// Eleven v3 works even though it refuses the neighbouring lines. node tests/ui/dialogs_models_smoke.js
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const MOCK = process.env.PN_MOCK_DIR || '/home/claude/build/mock';
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
  const isOpen = () => page.evaluate(() => document.getElementById('dlg').open);
  const outsideClick = async () => { await page.mouse.click(8, 450); await page.waitForTimeout(150); };

  // Dialogs that used to stay open.
  const openers = {
    'Films & versions': async () => { await page.selectOption('#versionSelect', '__manage'); },
    'New film': async () => { await page.selectOption('#filmSelect', '__new'); },
    'Download script': async () => { await page.click('#tabScript'); await page.click('#scriptDl'); },
    'Help': async () => { await page.keyboard.press('?'); },
  };
  for (const [name, open] of Object.entries(openers)) {
    await open();
    await page.waitForTimeout(300);
    if (!(await isOpen())) { fails.push(name + ': did not open'); continue; }
    await outsideClick();
    check(!(await isOpen()), name + ': closes on a click outside');
    if (await isOpen()) await page.keyboard.press('Escape');
  }

  // Selecting text in a field and letting go outside keeps it open.
  await page.selectOption('#filmSelect', '__new');
  await page.waitForSelector('dialog[open] input:visible', { timeout: 5000 });
  const box = await page.locator('dialog[open] input:visible').first().boundingBox();
  await page.mouse.move(box.x + 5, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(8, 450, { steps: 4 });
  await page.mouse.up();
  await page.waitForTimeout(150);
  check(await isOpen(), 'a selection that ends outside does not close it');
  await page.keyboard.press('Escape');

  // Voice-over: the account's speech models, with a word on each.
  await page.click('#tabScript');
  await page.click('#voBtn');
  await page.waitForSelector('#dlg h2:has-text("Voice-over")');
  await page.waitForSelector('#dlg .vo-line, #dlg p', { timeout: 10000 });
  const modelSel = page.locator('#dlg label:has-text("Model") select').first();
  const ids = await modelSel.locator('option').evaluateAll((o) => o.map((x) => x.value));
  check(ids.join() === 'eleven_v3,eleven_multilingual_v2', 'account models, best first: ' + ids.join());
  const hint1 = await modelSel.locator('xpath=..').locator('.hint').textContent(); check(/narration|expressive/.test(hint1), 'note on the chosen model: ' + hint1 + ' | ' + await modelSel.inputValue());
  await outsideClick();
  check(!(await isOpen()), 'voice-over closes on a click outside');

  // A key that can't list models: ElevenLabs' standard ones.
  const save = (body) => page.evaluate(async (b) => (await fetch('api.php?action=settings.elevenlabs', { method: 'POST',
    headers: { 'X-CSRF-Token': document.body.dataset.csrf, 'Content-Type': 'application/json' }, body: JSON.stringify({ model: 'scribe_v2', diarize: true, ...b }) })).status, body);
  check(await save({ tts_key: 'el-no-models' }) === 200, 'set a key without Models (Read)');
  await page.click('#voBtn');
  await page.waitForSelector('#dlg label:has-text("Model") select', { timeout: 10000 });
  const ids2 = await modelSel.locator('option').evaluateAll((o) => o.map((x) => x.value));
  check(ids2.includes('eleven_v3') && ids2.includes('eleven_flash_v2_5') && ids2.length >= 5, 'standard models offered: ' + ids2.join());
  check(/Models \(Read\)/.test(await modelSel.locator('xpath=..').locator('.hint').textContent()), 'says how to list the account’s models');
  await page.keyboard.press('Escape');
  check(await save({ tts_key: '-' }) === 200, 'restore');

  // Eleven v3 refuses the neighbouring lines: the take is made without them.
  const r = await page.evaluate(async () => {
    const csrf = document.body.dataset.csrf, v = +document.getElementById('versionSelect').value;
    const sc = await (await fetch(`api.php?action=script&version_id=${v}`)).json();
    const post = (a, b) => fetch('api.php?action=' + a, { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then((x) => x.json());
    const text = 'MODELTEST v3 line ' + Date.now();
    await post('script.edit', { version_id: v, transcript_id: sc.transcript_id, seg_idx: 40, slot: 0, state: 'approved', text });
    const take = await post('vo.line', { version_id: v, key: '40:0', scope: 'changes', drafts: '0', voices: { '*': 'voiceNarr01' }, model: 'eleven_v3', speed: '1', format: 'mp3' });
    await post('script.edit', { version_id: v, transcript_id: sc.transcript_id, seg_idx: 40, slot: 0, state: 'same', text: '' });
    return take;
  });
  check(r.hash && !r.error, 'v3 take made: ' + JSON.stringify(r).slice(0, 160));
  const last = fs.existsSync(MOCK + '/tts_last.txt') ? fs.readFileSync(MOCK + '/tts_last.txt', 'utf8') : '';
  check(/^eleven_v3 prev=no/.test(last), 'sent to v3 without the neighbouring lines: ' + last);

  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

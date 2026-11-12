// A transcription-only ElevenLabs key: voice-over explains which permissions to turn on; a separate voice-over key works.
// node tests/ui/voiceover_key_smoke.js
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
  const save = (body) => page.evaluate(async (b) => (await fetch('api.php?action=settings.elevenlabs', { method: 'POST',
    headers: { 'X-CSRF-Token': document.body.dataset.csrf, 'Content-Type': 'application/json' }, body: JSON.stringify({ model: 'scribe_v2', diarize: true, ...b }) })).status, body);
  check(await save({ key: 'el-stt-only', tts_key: '-' }) === 200, 'save a transcription-only key');
  const r = await page.evaluate(async () => { const x = await fetch('api.php?action=vo.voices'); return [x.status, (await x.json()).error]; });
  check(r[0] >= 400 && /Text to Speech/.test(r[1]) && /Voices \(Read\)/.test(r[1]) && /Edit/.test(r[1]), 'clear message about permissions: ' + r[1]);
  check(!/didn’t accept/.test(r[1]), 'not called a wrong key');
  check(await save({ tts_key: 'el-test-key' }) === 200, 'add a separate voice-over key');
  const ok = await page.evaluate(async () => (await fetch('api.php?action=vo.voices')).status);
  check(ok === 200, 'voice-over works with its own key');
  // The settings dialog shows the field and the advice.
  await page.click('#whoMenu summary'); await page.click('#settingsBtn');
  await page.waitForSelector('#dlg h3:has-text("transcription and voice-over")');
  check(await page.locator('#dlg label', { hasText: 'Voice-over key' }).count() === 1, 'voice-over key field');
  await page.screenshot({ path: '/tmp/claude-0/pn-share-shots/el-settings.png' });
  check(await page.locator('#dlg a[href="https://elevenlabs.io/app/developers/api-keys"]').count() === 1, 'link to the ElevenLabs API keys page');
  await page.mouse.click(10, 890);
  await page.waitForTimeout(300);
  check(!(await page.locator('#dlg').evaluate((d) => d.open)), 'click outside closes Settings');
  // Back to the normal test key.
  check(await save({ key: 'el-test-key', tts_key: '-' }) === 200, 'restore');
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

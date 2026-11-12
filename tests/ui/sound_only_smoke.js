// A sound-only cut: add a version with only a sound file, upload it in pieces, play it, transcribe it with
// ElevenLabs (mock, file upload), then the voice-over plan works on it. node tests/ui/sound_only_smoke.js
const { chromium } = require('playwright');
const fs = require('fs'), { execSync } = require('child_process');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const WAV = '/tmp/claude-0/pn-sound/narration.wav';
const MOCK = process.env.PN_MOCK_DIR || '/home/claude/build/mock';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  if (!fs.existsSync(WAV)) {
    fs.mkdirSync('/tmp/claude-0/pn-sound', { recursive: true });
    execSync(`ffmpeg -loglevel error -y -f lavfi -i "sine=frequency=330:duration=30" -ac 2 -ar 48000 ${WAV}`);
  }
  fs.rmSync(MOCK + '/stt_file.txt', { force: true });
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  // Leftovers from an interrupted run.
  await page.evaluate(async () => {
    const csrf = document.body.dataset.csrf;
    const st = await (await fetch('api.php?action=state')).json();
    for (const v of st.versions.filter((x) => x.label === 'Sound test')) {
      await fetch('api.php?action=version.delete', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ version_id: v.id }) });
    }
  });
  await page.reload();
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  const chunk = await page.evaluate(async () => (await (await fetch('api.php?action=state')).json()).upload_chunk);
  check(chunk >= 256 * 1024, 'server says how big a piece may be: ' + chunk);
  // Pretend the server takes 2 MB a request, like a small shared-hosting setup, so the file goes in pieces.
  await page.route('**/api.php?action=state*', async (route) => {
    const res = await route.fetch(); const body = await res.json(); body.upload_chunk = 2 * 1024 * 1024;
    await route.fulfill({ response: res, json: body });
  });
  await page.reload();
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });

  // New version → Sound only.
  await page.selectOption('#versionSelect', '__new');
  await page.waitForSelector('dialog[open] select[name=kind]');
  await page.fill('dialog input[name=label]', 'Sound test');
  await page.selectOption('dialog select[name=kind]', 'sound');
  check(await page.isVisible('dialog .sound-new'), 'sound file field shows for sound only');
  await page.setInputFiles('dialog .sound-new input[type=file]', WAV);
  let pieces = 0;
  page.on('request', (r) => { if (r.url().includes('action=version.audio') && r.method() === 'POST') pieces++; });
  await page.click('dialog button:has-text("Add this cut")');
  await page.waitForSelector('#toast:has-text("Transcript ready")', { timeout: 40000 }).catch(() => fails.push('transcript ready toast'));
  check(pieces >= 3, 'uploaded in pieces: ' + pieces);

  const v = await page.evaluate(async () => (await (await fetch('api.php?action=state')).json()).versions.find((x) => x.label === 'Sound test'));
  check(v && v.audio_name === 'narration.wav' && +v.audio_size === 5760078, 'version has the sound: ' + JSON.stringify(v && [v.audio_name, v.audio_size]));
  check(fs.existsSync(`/var/www/pn/data/media/${v.id}.wav`) && fs.statSync(`/var/www/pn/data/media/${v.id}.wav`).size === 5760078, 'file on the server, whole');
  const sent = fs.existsSync(MOCK + '/stt_file.txt') ? fs.readFileSync(MOCK + '/stt_file.txt', 'utf8') : '';
  check(sent === `${v.id}.wav 5760078`, 'ElevenLabs got the file itself: ' + sent);

  // It plays: sound-only look, duration known, seeking uses ranges.
  check(await page.locator('#screen.sound-only.has-video').count() === 1, 'screen shows sound only');
  check((await page.textContent('#screenSoundName')) === 'narration.wav', 'file name on the screen');
  await page.waitForFunction(() => document.getElementById('video').duration > 29, null, { timeout: 10000 }).catch(() => fails.push('audio loads in the player'));
  const range = await page.evaluate(async (id) => {
    const r = await fetch(`api.php?action=version.audio&version_id=${id}`, { headers: { Range: 'bytes=100-199' } });
    return [r.status, r.headers.get('content-range'), (await r.arrayBuffer()).byteLength];
  }, v.id);
  check(range[0] === 206 && range[1] === 'bytes 100-199/5760078' && range[2] === 100, 'range request: ' + range.join(' '));
  check(await page.locator('#script .tline').count() > 0, 'transcript lines on screen');
  if (process.env.PN_SHOT) await page.screenshot({ path: process.env.PN_SHOT });
  check(!(await page.isHidden('#transcribeBtn')), 'Transcribe button available for a sound-only cut');

  // Voice-over works from it.
  const plan = await page.evaluate(async (id) => (await (await fetch(`api.php?action=vo.plan&version_id=${id}&scope=all`)).json()), v.id);
  check((plan.lines || []).length > 0, 'voice-over plan has lines: ' + (plan.lines || []).length + (plan.error ? ' ' + plan.error : ''));

  // Films & versions shows it, and Remove takes it off.
  await page.selectOption('#versionSelect', '__manage');
  await page.waitForSelector('dialog[open] .vrow[open]');
  check(await page.locator('dialog .vrow[open] dd:has-text("narration.wav")').count() === 1, 'manage shows the sound file');
  if (process.env.PN_SHOT) await page.locator('dialog').screenshot({ path: process.env.PN_SHOT.replace('.png', '-manage.png') });
  page.once('dialog', (d) => d.accept());
  await page.click('dialog .vrow[open] dd:has-text("narration.wav") button:has-text("Remove")', { timeout: 5000 })
    .catch(async () => fails.push('remove button: ' + await page.locator('dialog .vrow[open]').innerText().catch(() => '?')));
  await page.waitForSelector('#toast:has-text("Sound removed")', { timeout: 10000 }).catch(() => fails.push('removed toast'));
  check(!fs.existsSync(`/var/www/pn/data/media/${v.id}.wav`), 'file removed from the server');
  await page.keyboard.press('Escape');

  // Clean up.
  await page.evaluate(async (id) => {
    const csrf = document.body.dataset.csrf;
    await fetch('api.php?action=version.delete', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ version_id: id }) });
  }, v.id);
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

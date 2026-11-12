// Every way of getting a sound-only cut offers ElevenLabs transcription: a new sound cut without the tick, and a
// sound file opened from the computer (uploaded first). node tests/ui/sound_transcribe_paths_smoke.js
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
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  const api = (action, body) => page.evaluate(async ([a, b]) => {
    const r = await fetch('api.php?action=' + a, b ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.dataset.csrf }, body: JSON.stringify(b) } : {});
    return r.json();
  }, [action, body]);
  for (const v of (await api('state')).versions.filter((x) => /^Sound path/.test(x.label))) await api('version.delete', { version_id: v.id });
  await page.reload();
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });

  // 1. New sound cut, transcription not ticked: the Transcript tab offers it, and so does the toast.
  await page.selectOption('#versionSelect', '__new');
  await page.waitForSelector('dialog[open] select[name=kind]');
  await page.fill('dialog input[name=label]', 'Sound path A');
  await page.selectOption('dialog select[name=carry]', '').catch(() => {});
  await page.selectOption('dialog select[name=kind]', 'sound');
  await page.setInputFiles('dialog .sound-new input[type=file]', WAV);
  await page.uncheck('dialog .sound-new input[type=checkbox]');
  await page.click('dialog button:has-text("Add this cut")');
  await page.waitForSelector('#toast:has-text("Sound uploaded") button:has-text("Transcribe it")', { timeout: 20000 }).catch(() => fails.push('toast offers Transcribe it'));
  await page.click('#tabScript');
  check(await page.isVisible('#transcribeBtn'), 'Transcribe button visible on a new sound cut');
  check((await page.textContent('#transcribeBtn')) === 'Transcribe this cut with ElevenLabs', 'button label: ' + await page.textContent('#transcribeBtn'));
  page.once('dialog', (d) => d.accept());
  await page.click('#transcribeBtn');
  await page.waitForSelector('#toast:has-text("Transcript ready")', { timeout: 40000 }).catch(() => fails.push('A transcribed'));

  // 2. Empty version, sound opened from the computer: offered to upload and transcribe.
  await page.selectOption('#versionSelect', '__new');
  await page.waitForSelector('dialog[open] select[name=kind]');
  await page.fill('dialog input[name=label]', 'Sound path B');
  await page.selectOption('dialog select[name=carry]', '').catch(() => {});
  await page.click('dialog button:has-text("Add this cut")');
  await page.waitForSelector('#screenEmpty', { state: 'visible' });
  fs.rmSync(MOCK + '/stt_file.txt', { force: true });
  await page.setInputFiles('#fileInput', WAV);
  await page.waitForSelector('#toast button:has-text("Upload and transcribe")', { timeout: 5000 }).catch(() => fails.push('toast offers Upload and transcribe'));
  check(await page.locator('#screen.sound-only').count() === 1, 'local sound shows Sound only');
  await page.click('#tabScript');
  check(await page.isVisible('#transcribeBtn'), 'Transcribe button visible for a local sound');
  check(/^Upload narration\.wav and transcribe/.test(await page.textContent('#transcribeBtn')), 'label says it uploads: ' + await page.textContent('#transcribeBtn'));
  page.once('dialog', (d) => d.accept());
  await page.click('#transcribeBtn');
  await page.waitForSelector('#toast:has-text("Transcript ready")', { timeout: 40000 }).catch(() => fails.push('B transcribed'));
  const b = (await api('state')).versions.find((x) => x.label === 'Sound path B');
  check(b?.audio_name === 'narration.wav', 'B now has the sound on the server');
  const sent = fs.existsSync(MOCK + '/stt_file.txt') ? fs.readFileSync(MOCK + '/stt_file.txt', 'utf8') : '';
  check(sent === `${b?.id}.wav 5760078`, 'ElevenLabs got B’s file: ' + sent);

  // 3. Films & versions has Transcribe next to the sound.
  await page.selectOption('#versionSelect', '__manage');
  await page.waitForSelector('dialog[open] .vrow[open]');
  check(await page.locator('dialog .vrow[open] dd:has-text("narration.wav") button:has-text("Transcribe")').count() === 1, 'Transcribe next to the sound in Films & versions');
  await page.keyboard.press('Escape');

  for (const v of (await api('state')).versions.filter((x) => /^Sound path/.test(x.label))) await api('version.delete', { version_id: v.id });
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

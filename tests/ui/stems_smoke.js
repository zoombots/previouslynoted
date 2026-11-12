// Voice & rest: split a cut's sound with ElevenLabs (mock), VOX and BG on the timeline, hear them separately, hear
// the new VO in place with the old voice taken out, and export the VO with the rest underneath.
// node tests/ui/stems_smoke.js
const { chromium } = require('playwright');
const fs = require('fs'), { execSync } = require('child_process');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const WAV = '/tmp/claude-0/pn-sound/narration.wav';
const MOCK = process.env.PN_MOCK_DIR || '/home/claude/build/mock';
const OUT = '/tmp/claude-0/pn-stems';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  fs.rmSync(OUT, { recursive: true, force: true }); fs.mkdirSync(OUT, { recursive: true });
  if (!fs.existsSync(WAV)) {
    fs.mkdirSync('/tmp/claude-0/pn-sound', { recursive: true });
    execSync(`ffmpeg -loglevel error -y -f lavfi -i "sine=frequency=330:duration=30" -ac 2 -ar 48000 ${WAV}`);
  }
  fs.rmSync(MOCK + '/stems_req.txt', { force: true });
  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE);
  await page.fill('input[name=email]', 'francis@example.com');
  await page.fill('input[name=password]', 'recovered-pass1');
  await Promise.all([page.waitForNavigation(), page.click('button.primary')]);
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  const api = (action, body, query) => page.evaluate(async ([a, b, q]) => {
    const qs = new URLSearchParams({ action: a, ...(q || {}) });
    const r = await fetch('api.php?' + qs, b ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.dataset.csrf }, body: JSON.stringify(b) } : {});
    return r.json();
  }, [action, body, query]);
  for (const v of (await api('state')).versions.filter((x) => x.label === 'Stems test')) await api('version.delete', { version_id: v.id });
  await page.reload();
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });

  // A sound-only cut, transcribed.
  await page.selectOption('#versionSelect', '__new');
  await page.waitForSelector('dialog[open] select[name=kind]');
  await page.fill('dialog input[name=label]', 'Stems test');
  await page.selectOption('dialog select[name=carry]', '').catch(() => {});
  await page.selectOption('dialog select[name=kind]', 'sound');
  await page.setInputFiles('dialog .sound-new input[type=file]', WAV);
  await page.click('dialog button:has-text("Add this cut")');
  await page.waitForSelector('#toast:has-text("Transcript ready")', { timeout: 40000 }).catch(() => fails.push('transcribed'));
  const vid = +(await page.inputValue('#versionSelect'));
  check(await page.isVisible('#hearRow'), 'Hear switch shows once there is a transcript');
  check(await page.isDisabled('#hearSeg button[data-hear=split]'), 'Voice + rest is off until the sound is split');

  // Split it.
  await page.click('#splitBtn');
  await page.waitForSelector('#dlg h2:has-text("Voice & rest")');
  check(await page.locator('#dlg p:has-text("the uploaded sound (narration.wav)")').count() === 1, 'dialog says what it splits');
  await page.click('#dlg button:has-text("Split the sound")');
  await page.waitForSelector('#toast:has-text("Voice and rest are ready")', { timeout: 30000 }).catch(() => fails.push('split finished'));
  const req = fs.existsSync(MOCK + '/stems_req.txt') ? fs.readFileSync(MOCK + '/stems_req.txt', 'utf8') : '';
  check(req === `cut-${vid}.wav 5760078 two_stems_v1`, 'ElevenLabs got the sound file, two stems: ' + req);
  check(fs.existsSync(`/var/www/pn/data/media/stems/${vid}-voice.mp3`) && fs.existsSync(`/var/www/pn/data/media/stems/${vid}-rest.mp3`), 'stems on the server');
  check(fs.statSync(`/var/www/pn/data/media/stems/${vid}-rest.mp3`).size === fs.statSync(MOCK + '/rest.mp3').size, 'rest is the instrumental stem');
  check(await page.locator('#tlGutter .g-audio').count() === 2, 'VOX and BG in the timeline gutter');
  check(await page.isVisible('#tlAudio'), 'audio lanes shown');
  await page.waitForFunction(() => {
    const c = document.querySelector('#tlAudio .tl-wave[data-kind=rest] canvas');
    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i]) n++;
    return n > c.width * 4;
  }, null, { timeout: 15000 }).catch(() => fails.push('rest waveform drawn'));

  // Voice + rest: the picture's own sound is muted and the stems play in step; VOX mutes the voice.
  await page.click('#hearSeg button[data-hear=split]');
  await page.click('#playBtn');
  await page.waitForTimeout(1200);
  const split = await page.evaluate(() => { const m = window.__pnMix, v = document.getElementById('video');
    return { muted: v.muted, voice: !m.els.voice.paused, rest: !m.els.rest.paused, drift: Math.abs(m.els.rest.currentTime - v.currentTime) }; });
  check(split.muted && split.voice && split.rest, 'stems playing with the picture muted: ' + JSON.stringify(split));
  check(split.drift < 0.25, 'rest in step with the picture: ' + split.drift.toFixed(3));
  await page.click('#tlGutter .g-audio:has-text("VOX")');
  await page.waitForTimeout(200);
  check(await page.evaluate(() => window.__pnMix.els.voice.volume === 0 && window.__pnMix.els.rest.volume === 1), 'VOX mutes only the voice');
  await page.click('#tlGutter .g-audio:has-text("VOX")');
  await page.click('#playBtn');
  await page.screenshot({ path: OUT + '/split.png' });

  // A rewritten line with a take; New VO plays it and takes the old voice out under the old line.
  const sc = await api('script', null, { version_id: vid });
  const seg = (await api('transcript', null, { version_id: vid })).segments[0];
  await api('script.edit', { version_id: vid, transcript_id: sc.transcript_id, seg_idx: 0, slot: 0, state: 'approved', text: 'STEMTEST a brand new first line' });
  const voices = await api('vo.voices');
  const take = await api('vo.line', { version_id: vid, key: '0:0', scope: 'changes', drafts: '0', voices: { '*': voices.voices[0].id }, model: 'eleven_multilingual_v2', speed: '1', format: 'mp3' });
  check(take.hash, 'take made: ' + JSON.stringify(take).slice(0, 120));
  await page.reload();
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  await page.click('#tabScript');
  await page.click('#hearSeg button[data-hear=newvo]');
  await page.waitForFunction(() => window.__pnMix.preview, null, { timeout: 10000 });
  const pv = await page.evaluate(() => window.__pnMix.preview);
  check(pv.takes === 1 && pv.lines.find((l) => l.key === '0:0')?.take?.hash === take.hash, 'preview has the take');
  check(pv.mute.some((m) => m[0] === seg.start && m[1] === seg.end), 'old voice taken out under the old line');
  check(await page.locator('#tlScript .sl.vo').count() === 1, 'TX line marked with ♪');
  check(/1 line with new VO/.test(await page.textContent('#hearNote')), 'note: ' + await page.textContent('#hearNote'));
  // Play inside the line.
  await page.click('#script .tline[data-i="0"] .seg-tc');
  await page.waitForTimeout(300);
  await page.click('#playBtn');
  await page.waitForTimeout(800);
  const nv = await page.evaluate(() => { const m = window.__pnMix; return { playing: [...m.playing.keys()], voice: m.els.voice.volume, rest: m.els.rest.volume, muted: document.getElementById('video').muted }; });
  check(nv.playing.includes('0:0'), 'the take plays: ' + JSON.stringify(nv));
  check(nv.voice === 0 && nv.rest === 1 && nv.muted, 'old voice out, rest in');
  await page.click('#playBtn');
  await page.screenshot({ path: OUT + '/newvo.png' });

  // Export with the rest under the VO.
  const zipPath = OUT + '/vo.zip';
  const resp = await page.evaluate(async ([v, voice]) => {
    const q = new URLSearchParams({ action: 'vo.export', version_id: v, scope: 'changes', drafts: '0', voices: JSON.stringify({ '*': voice }), model: 'eleven_multilingual_v2', speed: '1', format: 'mp3', folder: '', rest: 1 });
    const r = await fetch('api.php?' + q); const b = new Uint8Array(await r.arrayBuffer()); return [r.status, Array.from(b)];
  }, [vid, voices.voices[0].id]);
  check(resp[0] === 200, 'export ok: ' + resp[0]);
  fs.writeFileSync(zipPath, Buffer.from(resp[1]));
  const list = execSync(`unzip -l ${zipPath}`).toString();
  check(/REST_no_voice\.mp3/.test(list), 'zip has the rest: ' + list);
  const xml = execSync(`unzip -p ${zipPath} '*.xml'`).toString();
  check(/clipitem-bed/.test(xml) && (xml.match(/<track>/g) || []).length === 2, 'XML: rest track and VO track');
  execSync(`python3 -c "import sys,xml.dom.minidom;xml.dom.minidom.parseString(sys.stdin.read())"`, { input: xml });

  // Remove the split.
  await page.click('#splitBtn');
  await page.click('#dlg button:has-text("Remove the split")');
  await page.waitForSelector('#toast:has-text("Split removed")');
  check(!fs.existsSync(`/var/www/pn/data/media/stems/${vid}-voice.mp3`), 'stems removed');
  check(await page.isHidden('#tlAudio'), 'audio lanes gone');

  await api('version.delete', { version_id: vid });

  // A cut on Frame.io: the server fetches the proxy and sends it as it is.
  await page.goto(BASE + 'oauth.php?start=1');
  await page.waitForSelector('#versionSelect option', { state: 'attached', timeout: 15000 });
  const fio = (await api('state')).versions.find((x) => x.fio_file_id);
  const job = await api('stems.create', { version_id: fio.id });
  await api('stems.run', { job_id: job.job_id });
  let st;
  for (let n = 0; n < 30; n++) { st = (await api('stems.status', null, { version_id: fio.id })).job; if (st.status === 'done' || st.status === 'error') break; await page.waitForTimeout(1000); }
  check(st.status === 'done' && /Frame\.io file/.test(st.message), 'Frame.io cut split: ' + JSON.stringify(st).slice(0, 200));
  const req2 = fs.readFileSync(MOCK + '/stems_req.txt', 'utf8');
  check(req2 === `cut-${fio.id}.mp4 ${fs.statSync('/var/www/pn/media/long24.webm').size} two_stems_v1`, 'proxy sent: ' + req2);
  await api('stems.remove', { version_id: fio.id });
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

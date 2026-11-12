// Voice-over with ElevenLabs (mock): plan, voices, make takes, reuse, download the zip, check the XML.
// node tests/ui/voiceover_smoke.js   (needs the mock from tests/up.sh)
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), { execSync } = require('child_process');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
const OUT = '/tmp/claude-0/pn-vo';
const fails = [];
const check = (ok, msg) => { if (!ok) fails.push(msg); };
(async () => {
  fs.rmSync(OUT, { recursive: true, force: true }); fs.mkdirSync(OUT, { recursive: true });
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
  // Three changes: a rewrite, a new line (both approved), and a draft.
  const v = await page.evaluate(async () => {
    const v = document.getElementById('versionSelect').value, csrf = document.body.dataset.csrf;
    const sc = await (await fetch(`api.php?action=script&version_id=${v}`)).json();
    const post = (body) => fetch('api.php?action=script.edit', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      body: JSON.stringify({ version_id: +v, transcript_id: sc.transcript_id, ...body }) }).then((r) => r.json());
    // Leftovers from an interrupted run.
    for (const l of sc.lines.filter((x) => x.slot > 0 && x.state !== 'removed' && (x.text || '').startsWith('VOTEST'))) await post({ seg_idx: l.i, slot: l.slot, state: 'removed', text: '' });
    await post({ seg_idx: 30, slot: 0, state: 'approved', text: 'VOTEST the new opening of scene thirty ' + Date.now() });
    const n = await post({ seg_idx: 31, slot: 'new', state: 'approved', text: 'VOTEST a brand new line after thirty one ' + Date.now() });
    await post({ seg_idx: 32, slot: 0, state: 'draft', text: 'VOTEST a draft that should not be voiced' });
    return { v, slot: n.line.slot, tid: sc.transcript_id };
  });
  await page.reload();
  await page.waitForSelector('#notes li', { timeout: 15000 });
  await page.click('#tabScript');
  await page.waitForSelector('#voBtn:visible');
  await page.click('#voBtn');
  await page.waitForSelector('#dlg h2:has-text("Voice-over")');
  await page.waitForSelector('#dlg .vo-line', { timeout: 10000 });
  const lines = await page.locator('#dlg .vo-line').allInnerTexts();
  check(lines.length === 2 && lines.some((t) => t.includes('scene thirty')) && lines.some((t) => t.includes('thirty one')), 'plan: the two approved changes: ' + lines.join(' | '));
  check(!lines.some((t) => t.includes('draft that should')), 'drafts left out by default');
  check(await page.locator('#dlg .vo-voices select').count() >= 1, 'a voice picker');
  check((await page.locator('#dlg select >> nth=1').locator('option').allTextContents()).every((t) => !t.includes('Scribe')), 'speech-to-text models not offered');
  await page.screenshot({ path: '/tmp/claude-0/pn-share-shots/vo-dialog.png' });
  const ttsCount = () => { try { return +fs.readFileSync('/home/claude/build/mock/tts.txt', 'utf8').trim() || 0; } catch { return 0; } };
  const ttsBefore = ttsCount();
  await page.click('#dlg button:has-text("Make the voice-over")');
  await page.waitForSelector('#dlg .vo-progress p:has-text("ready")', { timeout: 30000 }).catch(() => fails.push('takes made'));
  check(await page.locator('#dlg .vo-play').count() === 2, 'each line has a take to play');
  const ttsAfter = ttsCount();
  check(ttsAfter - ttsBefore === 2, 'two calls to ElevenLabs: ' + (ttsAfter - ttsBefore));
  // Again: reused, no new calls.
  await page.click('#dlg button:has-text("Make the missing lines")');
  await page.waitForSelector('#dlg .vo-progress p:has-text("already made")', { timeout: 30000 });
  check(ttsCount() === ttsAfter, 'takes are reused, not paid for twice');
  check((await page.locator('#dlg .vo-progress p').innerText()).includes('0 made now, 2 already made'), 'progress says they were reused');
  await page.screenshot({ path: '/tmp/claude-0/pn-share-shots/vo-ready.png' });
  // Download.
  await page.fill('#dlg input[placeholder^="e.g."]', '/Users/francis/Film/VO');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#dlg button:has-text("Download clips and XML")')]);
  const zip = path.join(OUT, dl.suggestedFilename());
  await dl.saveAs(zip);
  execSync(`cd ${OUT} && unzip -q -o "${zip}"`);
  const dir = fs.readdirSync(OUT).find((f) => fs.statSync(path.join(OUT, f)).isDirectory());
  const clips = fs.readdirSync(path.join(OUT, dir, 'clips'));
  check(clips.length === 2 && clips.every((c) => /^\d{4}_\d\d-\d\d-\d\d-\d\d_.*\.wav$/.test(c)), 'two WAV clips named in order: ' + clips.join(', '));
  check(clips.some((c) => c.includes('.new.')), 'the added line is marked new');
  const xmlFile = fs.readdirSync(path.join(OUT, dir)).find((f) => f.endsWith('.xml'));
  const xml = fs.readFileSync(path.join(OUT, dir, xmlFile), 'utf8');
  fs.writeFileSync(path.join(OUT, 'check.py'), `import xml.etree.ElementTree as ET, sys, json
t = ET.parse(sys.argv[1]).getroot()
seq = t.find('sequence')
items = []
for i, tr in enumerate(seq.find('media/audio').findall('track')):
    for c in tr.findall('clipitem'):
        items.append({'track': i, 'start': int(c.find('start').text), 'end': int(c.find('end').text), 'path': c.find('file/pathurl').text, 'dur': int(c.find('duration').text)})
print(json.dumps({'tb': seq.find('rate/timebase').text, 'items': items, 'tc': seq.find('timecode/string').text}))`);
  const parsed = JSON.parse(execSync(`python3 -I ${path.join(OUT, 'check.py')} "${path.join(OUT, dir, xmlFile)}"`).toString());
  check(parsed.items.length === 2, 'XML places two clips');
  check(parsed.items.every((i) => i.path.startsWith('file://localhost/Users/francis/Film/VO/clips/')), 'clip paths use the folder: ' + parsed.items[0]?.path);
  check(parsed.items.every((i) => i.end - i.start === i.dur && i.dur > 0), 'clip lengths match');
  // Each clip at its line's time.
  const times = await page.evaluate(async ([v]) => (await (await fetch(`api.php?action=script&version_id=${v}`)).json()).times, [v.v]);
  const starts = parsed.items.map((i) => i.start).sort((a, b) => a - b);
  const want = [times['30:0'][0], times[`31:${v.slot}`][0]].sort((a, b) => a - b);
  check(JSON.stringify(starts) === JSON.stringify(want), `clips at their TX times (${starts} vs ${want})`);
  check(xml.includes('<xmeml version="5">') && xml.includes('<mastercomment1>VOTEST'), 'XML is FCP7 and carries the line text');
  // ffprobe the WAVs.
  for (const c of clips) {
    const d = +execSync(`ffprobe -v error -show_entries format=duration -of csv=p=0 "${path.join(OUT, dir, 'clips', c)}"`).toString();
    check(d > 0.9, `${c} plays (${d}s)`);
  }
  // MP3 too.
  await page.locator('#dlg .dl-choice', { hasText: 'MP3' }).click();
  await page.click('#dlg button:has-text("Make the")');
  await page.waitForSelector('#dlg .vo-progress p:has-text("ready")', { timeout: 30000 });
  const [dl2] = await Promise.all([page.waitForEvent('download'), page.click('#dlg button:has-text("Download clips and XML")')]);
  const zip2 = path.join(OUT, 'mp3.zip'); await dl2.saveAs(zip2);
  const listing = execSync(`unzip -l "${zip2}"`).toString();
  check((listing.match(/\.mp3/g) || []).length === 2, 'MP3 zip has two clips');
  // Clean up the script changes.
  await page.evaluate(async ([v, slot, tid]) => {
    const csrf = document.body.dataset.csrf;
    const post = (body) => fetch('api.php?action=script.edit', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      body: JSON.stringify({ version_id: +v, transcript_id: tid, ...body }) });
    await post({ seg_idx: 30, slot: 0, state: 'same', text: '' });
    await post({ seg_idx: 31, slot, state: 'removed', text: '' });
    await post({ seg_idx: 32, slot: 0, state: 'same', text: '' });
  }, [v.v, v.slot, v.tid]);
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

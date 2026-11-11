// A long ElevenLabs transcription shows up without reloading the page. The status replies are made to look like
// the job started ten minutes ago (a long cut) and ran for a while. node tests/ui/transcribe_refresh_smoke.js
const { chromium } = require('playwright');
const BASE = process.env.PN_BASE || 'http://127.0.0.1:8081/';
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
  await page.goto(BASE + 'oauth.php?start=1');   // fresh Frame.io sign-in with the mock
  await page.waitForSelector('#notes li', { timeout: 15000 });
  // A version linked to Frame.io, so ElevenLabs can fetch its audio.
  const linked = await page.evaluate(async () => (await (await fetch('api.php?action=state')).json()).versions.find((v) => v.fio_file_id)?.id);
  await page.selectOption('#versionSelect', String(linked));
  await page.waitForTimeout(1500);
  await page.click('#tabScript');
  const before = await page.evaluate(async (v) => (await (await fetch(`api.php?action=transcript&version_id=${v}`)).json()).latest_id, linked);
  let polls = 0;
  await page.route('**/api.php?action=transcribe.status*', async (route) => {
    const res = await route.fetch();
    const body = await res.json();
    polls++;
    if (body.job) {
      body.job.created_at = new Date(Date.now() - 10 * 60 * 1000).toISOString();
      if (polls <= 2) { body.job.status = 'running'; body.job.message = 'Transcribing…'; }
    }
    await route.fulfill({ response: res, json: body });
  });
  page.once('dialog', (d) => d.accept());
  await page.click('#transcribeBtn');
  await page.waitForSelector('#toast:has-text("Transcript ready")', { timeout: 30000 }).catch(() => fails.push('transcript ready toast without reload'));
  const shown = await page.evaluate(() => document.querySelector('#scriptVersions, #scriptUpload, select')?.value);
  const after = await page.evaluate(async (v) => (await (await fetch(`api.php?action=transcript&version_id=${v}`)).json()).latest_id, linked);
  check(after !== before, `a new transcript was made (${before} → ${after})`);
  check(polls >= 3, 'status was polled while running: ' + polls);
  const tabCount = await page.locator('#tabScript').innerText();
  check(/\d/.test(tabCount), 'transcript tab shows a count: ' + tabCount);
  check(await page.locator('#script .tline').count() > 0, 'transcript lines on screen');
  check(errors.length === 0, 'page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(fails.length ? 'FAIL\n  ' + fails.join('\n  ') : 'ok');
  process.exit(fails.length ? 1 : 0);
})();

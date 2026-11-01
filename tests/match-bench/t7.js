// Matcher on fingerprints made in the browser; frames from ffmpeg.
const M = require('/home/claude/previouslynoted/assets/match.js');
const D = require('./data');
const { score } = require('./score');
const fs = require('fs');
const BENCH = process.env.BENCH || '/tmp/claude-0/bench';
const PFX = process.env.FP || '/tmp/claude-0/e2e/last';
const T = require(BENCH + '/truth.json');
(async () => {
  const A = M.unpack(fs.readFileSync(PFX + '.fpA')), B = M.unpack(fs.readFileSync(PFX + '.fpB'));
  const fa = D.frames(BENCH + '/A.g64'), fb = D.frames(BENCH + '/B.g64');
  const m = await M.matchShots(A, B);
  const res = M.placeNotes(m.pa, m.pb, m.SA, m.SB, m.dec, T.notes.map((n) => ({ id: n.n, t: n.frameA / 24 })));
  const get = async (side, first, count) => { const fr = side === 'A' ? fa : fb; first = Math.max(0, first); count = Math.max(0, Math.min(count, fr.n - first)); return { first, count, frames: fr.f.subarray(first * D.FP, (first + count) * D.FP) }; };
  const coarse = JSON.parse(JSON.stringify(res));
  score(T, coarse.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'browser prints, coarse');
  await M.refine(res, m, get, { rateA: 24, rateB: 24 });
  const rows = score(T, res.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'browser prints + frames');
  if (process.argv.includes('-v')) for (const x of rows) if (x.out === 'WRONG' || x.out.startsWith('FALSE')) { const r = res.find((q) => q.id === x.n); console.log(x.n, x.ckind, x.fx.join('+'), 'got', x.got, 'truth', x.truth.join(','), r.reason, r.how, 'A', r.shotA, 'B', r.shotB, x.out); }
})();

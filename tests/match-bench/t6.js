// The assembled assets/match.js, end to end on fingerprints + frames, through pack/unpack.
const M = require('/home/claude/previouslynoted/assets/match.js');
const { fp2 } = require('./fp');
const D = require('./data');
const { score } = require('./score');
const BENCH = process.env.BENCH || __dirname + '/..';
const T = require(BENCH + '/truth.json');
(async () => {
  const A0 = fp2(BENCH + '/A.g64'), B0 = fp2(BENCH + '/B.g64');
  const A = M.unpack(M.pack(A0).buffer), B = M.unpack(M.pack(B0).buffer);
  console.log('bytes per 10 min', Math.round(M.pack(A0).length / (A0.dur / 600)));
  const t0 = Date.now();
  const m = await M.matchShots(A, B);
  const res = M.placeNotes(m.pa, m.pb, m.SA, m.SB, m.dec, T.notes.map((n) => ({ id: n.n, t: n.frameA / 24 })));
  const get = async (side, first, count) => { const fr = side === 'A' ? A0.fr : B0.fr; first = Math.max(0, first); count = Math.max(0, Math.min(count, fr.n - first)); return { first, count, frames: fr.f.subarray(first * D.FP, (first + count) * D.FP) }; };
  await M.refine(res, m, get, { rateA: 24, rateB: 24 });
  console.log('total ms', Date.now() - t0);
  const rows = score(T, res.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'assets/match.js: ' + BENCH);
  for (const r of rows) if (r.out === 'FALSE (sure)' || r.out === 'WRONG') { const x = res.find((q) => q.id === r.n); if (process.argv.includes('-v')) console.log(r.n, r.kind, r.ckind, 'got', r.got, x.reason, x.how, 'shotA', x.shotA, 'shotB', x.shotB, r.out); }
  const rs = {}; for (const r of res) rs[r.reason + (r.confident ? '' : '?')] = (rs[r.reason + (r.confident ? '' : '?')] || 0) + 1; console.log('reasons', JSON.stringify(rs));
})();

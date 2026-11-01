// Baseline: the current matcher's fast pass on the same pictures (no AI stage, no video refinement).
const T = require((process.env.BENCH || '..') + '/truth.json');
const D = require('./data');
const M = require('../match_old.js');
const { score } = require('./score');
function fpOld(file) {
  const fr = D.frames(file), ks = D.sampleTimes(fr.n, 24);
  const fp = M.emptyPrint(ks.length); fp.dur = fr.n / 24;
  ks.forEach((k, i) => { const s = M.describe(D.half(fr, k)); fp.t[i] = k / 24; fp.h[2 * i] = s.h0; fp.h[2 * i + 1] = s.h1; fp.th.set(s.thumb, i * 144); fp.flat[i] = s.flat; });
  return fp;
}
const A = fpOld((process.env.BENCH || __dirname + '/..') + '/A.g64'), B = fpOld((process.env.BENCH || __dirname + '/..') + '/B.g64');
const notes = T.notes.map((n) => ({ id: n.n, t: n.frameA / 24 }));
const f = M.fastPlace(A, B, notes);
// same fallback as run(): unplaced → after previous placed note
const placed = f.results.filter((r) => r.status === 'matched').sort((p, q) => p.tA - q.tA);
for (const r of f.results) if (r.status !== 'matched') { const prev = placed.filter((p) => p.tA < r.tA).pop(); Object.assign(r, { tB: Math.max(0, r.tA + (prev ? prev.tB - prev.tA : 0)), reason: r.status === 'flat' ? 'ambiguous' : 'removed', confident: false }); }
console.log('shots A', f.shotsA.list.length, 'B', f.shotsB.list.length, '(truth', T.A.length, T.B.length, ')');
score(T, f.results.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'current matcher, fast pass');

const X = require('./t1');
const C = require('./core');
const { T, SA, SB, pa, pb } = X;
const segAt = (segs, t) => segs.find((s) => t * 24 >= s.rec && t * 24 < s.rec + s.n);
const majority = (S, s, segs) => { const c = {}; for (let i = s.i0; i < s.i1; i++) { const g = segAt(segs, S === 'A' ? pa.t[i] : pb.t[i]); if (g) c[g.id] = (c[g.id] || 0) + 1; } return +Object.entries(c).sort((a, b) => b[1] - a[1])[0][0]; };
const aSeg = SA.list.map((s) => majority('A', s, T.A));
const bSeg = SB.list.map((s) => majority('B', s, T.B));
const overl = (a, b) => a.clip === b.clip && Math.min(a.src + a.dur, b.src + b.dur) > Math.max(a.src, b.src);
const t0 = Date.now();
const cands = C.candidates(pa, pb, SA, SB);
const t1 = Date.now();
let stats = { top: 0, notTop: 0, noTrue: 0, trueMissing: 0 };
const H = [];
for (const a of SA.list) {
  const A = T.A[aSeg[a.k]];
  const trueB = new Set(SB.list.filter((b) => overl(A, T.B[bSeg[b.k]])).map((b) => b.k));
  const hyps = cands[a.k].map((c) => C.align(pa, pb, a, SB.list[c.b])).filter(Boolean).sort((x, y) => y.score - x.score);
  H.push(hyps);
  const fx = T.B.filter((b) => b.from === A.id).flatMap((b) => b.fx).map((f) => Array.isArray(f) ? f[0] : f).join('+');
  const tag = `${a.k} ${A.clip.padEnd(14)} len ${a.len} ${fx}`;
  if (!trueB.size) { stats.noTrue++; console.log('[gone]', tag, hyps.slice(0, 2).map((h) => `${h.b}:${h.score.toFixed(3)} ms ${h.ms.toFixed(3)} mm ${h.mm.toFixed(2)}(${h.mc}) rs ${h.rs.toFixed(2)}(${h.rc})`).join(' ')); continue; }
  if (!hyps.length || !hyps.some((h) => trueB.has(h.b))) { stats.trueMissing++; console.log('[no cand]', tag, 'true', [...trueB], 'cands', cands[a.k].map((c) => `${c.b}:${c.sim.toFixed(2)}`).join(' ')); continue; }
  const rank = hyps.findIndex((h) => trueB.has(h.b));
  const ht = hyps[rank], hf = hyps.find((h) => !trueB.has(h.b));
  if (rank === 0) stats.top++; else stats.notTop++;
  console.log(rank === 0 ? '[ok]' : '[RANK ' + rank + ']', tag, `true ${ht.b} ms ${ht.ms.toFixed(3)} mm ${ht.mm.toFixed(2)}(${ht.mc}) rs ${ht.rs.toFixed(2)}(${ht.rc}) s ${ht.score.toFixed(3)}`, hf ? `| false ${hf.b} ms ${hf.ms.toFixed(3)} mm ${hf.mm.toFixed(2)}(${hf.mc}) rs ${hf.rs.toFixed(2)}(${hf.rc}) s ${hf.score.toFixed(3)}` : '');
}
console.log(stats, 'candidates ms', t1 - t0);
module.exports = { ...X, cands, H, aSeg, bSeg };

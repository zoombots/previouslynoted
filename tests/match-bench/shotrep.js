// Shot-level report: truth vs pick for every A shot.
(async () => {
const X = await require('./t4');
const { T, m } = X;
const seg = (segs, t) => segs.find((s) => t * 24 >= s.rec && t * 24 < s.rec + s.n);
const maj = (pp, s, segs) => { const c = {}; for (let i = s.i0; i < s.i1; i++) { const g = seg(segs, pp.t[i]); if (g) c[g.id] = (c[g.id] || 0) + 1; } return +Object.entries(c).sort((a, b) => b[1] - a[1])[0][0]; };
const aSeg = m.SA.list.map((s) => maj(m.pa, s, T.A)), bSeg = m.SB.list.map((s) => maj(m.pb, s, T.B));
const overl = (a, b) => a.clip === b.clip && Math.min(a.src + a.dur, b.src + b.n / 24) > Math.max(a.src, b.src);
let bad = 0;
for (const a of m.SA.list) {
  const A = T.A[aSeg[a.k]];
  const trueB = m.SB.list.filter((b) => overl(A, T.B[bSeg[b.k]])).map((b) => b.k);
  const p = m.dec.pick[a.k];
  const ok = p ? trueB.includes(p.h.b) : !trueB.length;
  const okFlag = !p && trueB.length ? 'MISSED' : p && !trueB.length ? (['slot', 'close call', 'duplicate'].includes(p.how) ? 'gone/flagged' : 'GONE-BUT-SURE') : ok ? '' : 'WRONG';
  if (okFlag && okFlag !== 'gone/flagged') bad++;
  const fx = T.B.filter((b) => b.from === A.id).flatMap((b) => b.fx).map((f) => (Array.isArray(f) ? f[0] : f)).join('+');
  const hs = m.hyps[a.k].slice(0, 4).map((h) => `B${h.b}:${h.score.toFixed(2)}/${h.ms.toFixed(2)}/r${h.rs.toFixed(2)}${h.reframe ? 'R' : ''}`).join(' ');
  if (process.argv.includes('-a') || okFlag) console.log(okFlag.padEnd(13), `A${a.k}`.padEnd(4), A.clip.padEnd(16), `len ${a.len}`.padEnd(7), fx.padEnd(12), 'true', JSON.stringify(trueB).padEnd(8), 'pick', p ? `B${p.h.b} ${p.how}` : '-', '|', hs);
}
console.log('bad shots', bad);
})();

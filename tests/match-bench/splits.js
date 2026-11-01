const X = require('./t4');
const { T, m } = X;
const seg = (segs, t) => segs.find((s) => t * 24 >= s.rec && t * 24 < s.rec + s.n);
// a split is "true" if the boundary falls within 0.5 s of a real cut in A
const cutsA = T.A.slice(1).map((s) => s.rec / 24);
const orig = require('./core').shots(m.pa, X.A.g).list.map((s) => s.i0);
for (const s of m.SA.list) if (!orig.includes(s.i0)) {
  const t = s.t0; const real = cutsA.some((c) => Math.abs(c - t) < 0.5);
  console.log(real ? 'TRUE split ' : 'FALSE split', 'at', t.toFixed(2), seg(T.A, t - 0.3)?.clip, '|', seg(T.A, t + 0.3)?.clip);
}

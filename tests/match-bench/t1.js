const T = require('../truth.json');
const D = require('./data');
const C = require('./core');
function fp2(file) {
  const fr = D.frames(file), ks = D.sampleTimes(fr.n, 24);
  const n = ks.length, g = new Uint8Array(n * C.P), t = new Float32Array(n);
  ks.forEach((k, i) => { const h = D.half(fr, k); for (let q = 0; q < C.P; q++) g[i * C.P + q] = Math.round(h[q]); t[i] = k / 24; });
  return { v: 2, n, dur: fr.n / 24, t, g };
}
const A = fp2(__dirname + '/../A.g64'), B = fp2(__dirname + '/../B.g64');
const pa = C.prep(A), pb = C.prep(B);
const SA = C.shots(pa, A.g), SB = C.shots(pb, B.g);
// cut detection vs truth
function cutEval(S, segs, label, dis = []) {
  const truth = segs.slice(1).map((s) => s.rec / 24);
  const det = S.list.slice(1).map((s) => s.t0);
  const hit = truth.filter((t) => det.some((d) => Math.abs(d - t) <= 0.3)).length;
  const fa = det.filter((d) => !truth.some((t) => Math.abs(d - t) <= 0.3));
  console.log(label, 'true cuts', truth.length, 'found', hit, 'detected', det.length, 'false', fa.length, fa.map((x) => x.toFixed(2)).join(' '));
  const miss = truth.filter((t) => !det.some((d) => Math.abs(d - t) <= 0.3));
  console.log('  missed at', miss.map((x) => x.toFixed(2)).join(' '));
}
cutEval(SA, T.A, 'A'); cutEval(SB, T.B, 'B');
module.exports = { A, B, pa, pb, SA, SB, T };

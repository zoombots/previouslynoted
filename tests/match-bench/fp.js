const D = require('./data');
const P = 576;
function fp2(file, jitter = 0) {
  const fr = D.frames(file), ks = D.sampleTimes(fr.n, 24, 0.25, jitter);
  const n = ks.length, g = new Uint8Array(n * P), t = new Float32Array(n);
  ks.forEach((k, i) => { const h = D.half(fr, k); for (let q = 0; q < P; q++) g[i * P + q] = Math.round(h[q]); t[i] = k / 24; });
  return { v: 2, n, dur: fr.n / 24, t, g, fr };
}
module.exports = { fp2 };

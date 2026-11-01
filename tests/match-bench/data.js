// Loads the extracted 64×36 grey frames and makes fingerprints the way the browser would (a sample every 0.25 s).
const fs = require('fs');
const FW = 64, FH = 36, FP = FW * FH;
function frames(file) { const b = fs.readFileSync(file); return { n: b.length / FP, f: new Uint8Array(b.buffer, b.byteOffset, b.length) }; }
function half(fr, k) { // 64×36 frame → 32×18 Float32
  const o = new Float32Array(32 * 18), s = fr.f.subarray(k * FP, (k + 1) * FP);
  for (let y = 0; y < 18; y++) for (let x = 0; x < 32; x++) { const i = 2 * y * FW + 2 * x; o[y * 32 + x] = (s[i] + s[i + 1] + s[i + FW] + s[i + FW + 1]) / 4; }
  return o;
}
function sampleTimes(nFrames, fps, sample = 0.25, jitter = 0) {
  const n = Math.floor(nFrames / fps / sample), out = [];
  for (let i = 0; i < n; i++) { let k = Math.round((i * sample + 0.02) * fps + (jitter ? (Math.random() - 0.5) * jitter : 0)); k = Math.max(0, Math.min(nFrames - 1, k)); out.push(k); }
  return out;
}
module.exports = { frames, half, sampleTimes, FW, FH, FP };

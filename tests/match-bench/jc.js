const C = require('./core');
const { fp2 } = require('./fp');
for (const B of ['/tmp/claude-0/bench', '/tmp/claude-0/bench2']) {
  const T = require(B + '/truth.json');
  for (const side of ['A', 'B']) {
    const fp = fp2(`${B}/${side}.g64`), pp = C.prep(fp), S = C.shots(pp, fp.g);
    const n = pp.n, g = new Float32Array(n);
    for (let i = 1; i < n; i++) g[i] = 1 - C.dotv(pp.v, i * C.P, pp.v, (i - 1) * C.P, C.P);
    const segs = T[side];
    const jumps = [];
    for (let q = 1; q < segs.length; q++) if (segs[q].clip === segs[q - 1].clip) jumps.push(Math.round((segs[q].rec / 24 - 0.02) / 0.25));
    const show = (i) => Array.from(g.slice(i - 3, i + 4)).map((x) => x.toFixed(4)).join(' ');
    for (const i of jumps) console.log(B.slice(-6), side, 'JUMP', i, '|', show(i), '| detected', S.list.some((s) => Math.abs(s.i0 - i) <= 1));
    // strongest non-cut single-sample spikes
    const truthCuts = new Set(segs.slice(1).map((s) => Math.round((s.rec / 24 - 0.02) / 0.25)));
    const near = (i) => [...truthCuts].some((c) => Math.abs(c - i) <= 1);
    const spikes = [];
    for (let i = 4; i < n - 4; i++) {
      if (near(i)) continue;
      const nb = [g[i - 3], g[i - 2], g[i + 2], g[i + 3]].sort((a, b) => a - b);
      const med = (nb[1] + nb[2]) / 2;
      if (g[i] >= g[i - 1] && g[i] >= g[i + 1]) spikes.push({ i, ratio: g[i] / (med + 0.0005), v: g[i] });
    }
    spikes.sort((a, b) => b.ratio - a.ratio);
    console.log('   top non-cut spikes', spikes.slice(0, 6).map((s) => `${s.i}:${s.v.toFixed(4)}x${s.ratio.toFixed(1)}`).join(' '));
    for (const i of jumps) { const nb = [g[i - 3], g[i - 2], g[i + 2], g[i + 3]].sort((a, b) => a - b); console.log('   jump ratio', (g[i] / ((nb[1] + nb[2]) / 2 + 0.0005)).toFixed(1)); }
  }
}

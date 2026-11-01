const X = require('./t1');
const { T, SA, SB, pa } = X;
for (const [S, segs, L] of [[SA, T.A, 'A'], [SB, T.B, 'B']]) {
  for (let q = 1; q < segs.length; q++) {
    const t = segs[q].rec / 24;
    if (S.list.some((s) => Math.abs(s.t0 - t) <= 0.3)) continue;
    const i = Math.round((t - 0.02) / 0.25);
    console.log(L, t.toFixed(2), segs[q - 1].clip, '→', segs[q].clip, 'c:', Array.from(S.c.slice(i - 2, i + 3)).map((x) => x.toFixed(3)).join(' '));
  }
}

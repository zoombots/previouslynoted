// Scores placements against the truth. results: [{note_id, frame, reason, confident}]
function score(T, results, label) {
  const res = new Map(results.map((r) => [r.note_id, r]));
  const fps = T.fps;
  const shotOf = (frame) => T.B.find((s) => frame >= s.rec && frame < s.rec + s.n);
  const rows = [];
  const tally = {};
  const bump = (k, v) => { tally[k] ??= {}; tally[k][v] = (tally[k][v] || 0) + 1; };
  const errs = [];
  for (const n of T.notes) {
    const r = res.get(n.n);
    const found = r.reason !== 'removed';
    let out;
    if (n.kind === 'found') {
      const err = Math.min(...n.truth.map((t) => Math.abs(r.frame - t)));
      const same = n.truth.some((t) => { const s = shotOf(t); return s && r.frame >= s.rec && r.frame < s.rec + s.n; });
      if (!found) out = 'missed';
      else if (err <= 1) out = 'exact';
      else if (err <= 3) out = '≤3f';
      else if (err <= 12) out = '≤½s';
      else if (same && n.static) out = 'same still';
      else if (same) out = 'right shot, off';
      else out = 'WRONG';
      if (found && (same || err <= 12)) errs.push(err / fps);
      bump('all found', out); bump(n.ckind, out); if (n.fx.length) bump('fx:' + n.fx.join('+'), out);
      if (out === 'WRONG' && r.confident) bump('all found', 'WRONG & sure');
    } else if (n.kind === 'trimmed') {
      const inNear = n.near.some(([a, b]) => r.frame >= a - 2 && r.frame <= b + 2);
      out = !found ? 'flagged missing' : inNear ? 'in the trimmed shot' : 'WRONG';
      bump('trimmed', out);
    } else {
      out = r.confident && found ? 'FALSE (sure)' : found ? 'placed, flagged' : 'flagged removed';
      bump('removed', out);
    }
    rows.push({ n: n.n, kind: n.kind, ckind: n.ckind, fx: n.fx, frameA: n.frameA, truth: n.truth, got: r.frame, reason: r.reason, confident: r.confident, out });
  }
  errs.sort((a, b) => a - b);
  const q = (p) => errs.length ? errs[Math.min(errs.length - 1, Math.floor(p * errs.length))].toFixed(2) : '-';
  console.log(`\n== ${label} ==`);
  for (const [k, v] of Object.entries(tally)) {
    const tot = Object.values(v).reduce((a, b) => a + b, 0);
    console.log(k.padEnd(22), String(tot).padStart(4), Object.entries(v).map(([a, b]) => `${a}:${b}`).join('  '));
  }
  console.log('error (s) median', q(0.5), 'p80', q(0.8), 'p95', q(0.95));
  return rows;
}
module.exports = { score };

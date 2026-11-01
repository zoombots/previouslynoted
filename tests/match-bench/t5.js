const XP = require('./t4');
const C = require('./core');
const D = require('./data');
const { score } = require('./score');
let T, A, B, m, res;
const get = async (side, first, count) => {
  const fr = side === 'A' ? A.fr : B.fr;
  first = Math.max(0, first); count = Math.max(0, Math.min(count, fr.n - first));
  return { first, count, frames: fr.f.subarray(first * D.FP, (first + count) * D.FP) };
};
(async () => {
  ({ T, A, B, m, res } = await XP);
  const t0 = Date.now();
  const r2 = JSON.parse(JSON.stringify(res));
  let fetched = 0; const get2 = async (s, f, c) => { fetched += c; return get(s, f, c); };
  await C.refine(r2, m, get2, { rateA: 24, rateB: 24 });
  console.log('refine ms', Date.now() - t0, 'frames fetched', fetched);
  const rows = score(T, r2.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'new matcher + frames');
  const by = {}; for (const r of r2) by[r.fine || '-'] = (by[r.fine || '-'] || 0) + 1; console.log('fine by', JSON.stringify(by));
  if (process.argv.includes('-v')) for (const r of rows) if (!['exact', 'in the trimmed shot', 'flagged removed', 'placed, flagged'].includes(r.out)) { const x = r2.find((q) => q.id === r.n); console.log(r.n, r.kind, r.ckind, r.fx.join('+'), 'A', r.frameA, 'truth', r.truth.join(','), 'got', r.got, r.reason, x.how, x.fine, x.anchored || '', 'shotA', x.shotA, 'shotB', x.shotB, '→', r.out); }
})();

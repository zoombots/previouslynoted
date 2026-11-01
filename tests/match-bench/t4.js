const T = require((process.env.BENCH || '..') + '/truth.json');
const C = require('./core');
const { score } = require('./score');
const { fp2 } = require('./fp');
const A = fp2((process.env.BENCH || __dirname + '/..') + '/A.g64'), B = fp2((process.env.BENCH || __dirname + '/..') + '/B.g64');
module.exports = (async () => {
const t0 = Date.now();
const m = await C.matchShots(A, B);
const res = C.placeNotes(m.pa, m.pb, m.SA, m.SB, m.dec, T.notes.map((n) => ({ id: n.n, t: n.frameA / 24 })));
console.log('match ms', Date.now() - t0);
const rows = score(T, res.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'new matcher, fingerprints only');
const how = {}; for (const p of m.dec.pick) { const k = p ? p.how : 'none'; how[k] = (how[k] || 0) + 1; } console.log('shots by', JSON.stringify(how));
if (process.argv.includes('-v')) for (const r of rows) if (!['exact', '≤3f', 'in the trimmed shot', 'flagged removed'].includes(r.out)) { const x = res.find((q) => q.id === r.n); console.log(r.n, r.kind, r.ckind, r.fx.join('+'), 'A', r.frameA, 'truth', r.truth.join(','), 'got', r.got, r.reason, x.how, 'k', x.k, 'shotA', x.shotA, 'shotB', x.shotB, x.anchored ? 'anchored' : '', '→', r.out); }
return { T, A, B, m, res, rows };
})();

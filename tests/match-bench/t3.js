const X = require('./t2');
const C = require('./core');
const { score } = require('./score');
const { T, SA, SB, pa, pb, H } = X;
const dec = C.decide(pa, pb, SA, SB, H);
const res = C.placeNotes(pa, pb, SA, SB, dec, T.notes.map((n) => ({ id: n.n, t: n.frameA / 24 })));
const rows = score(T, res.map((r) => ({ note_id: r.id, frame: Math.round(r.tB * 24), reason: r.reason, confident: r.confident })), 'new matcher, fingerprints only');
const how = {}; for (const p of dec.pick) { const k = p ? p.how : 'none'; how[k] = (how[k] || 0) + 1; } console.log('shots by', how);
if (process.argv.includes('-v')) for (const r of rows) if (!['exact', 'in the trimmed shot', 'flagged removed'].includes(r.out)) { const x = res.find((q) => q.id === r.n); console.log(r.n, r.kind, r.ckind, r.fx.join('+'), 'A', r.frameA, 'truth', r.truth.join(','), 'got', r.got, r.reason, x.how, 'k', x.k, 'shotA', x.shotA, 'shotB', x.shotB, x.anchored ? 'anchored' : '', '→', r.out); }
module.exports = { ...X, dec, res, rows };

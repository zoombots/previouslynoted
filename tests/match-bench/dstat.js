globalThis.__dstat = [];
const C = require('./core');
const { fp2 } = require('./fp'); const D = require('./data');
const BENCH = process.env.BENCH || '/tmp/claude-0/bench'; const T = require(BENCH + '/truth.json');
(async () => {
  const A = fp2(BENCH + '/A.g64'), B = fp2(BENCH + '/B.g64');
  const m = await C.matchShots(A, B);
  const res = C.placeNotes(m.pa, m.pb, m.SA, m.SB, m.dec, T.notes.map((n) => ({ id: n.n, t: n.frameA / 24 })));
  let reads = 0;
  const get = async (side, first, count) => { reads++; const fr = side === 'A' ? A.fr : B.fr; first = Math.max(0, first); count = Math.max(0, Math.min(count, fr.n - first)); return { first, count, frames: fr.f.subarray(first * D.FP, (first + count) * D.FP) }; };
  await C.refine(res, m, get, { rateA: 24, rateB: 24 });
  const h = {}; for (const d of globalThis.__dstat) h[d] = (h[d] || 0) + 1;
  console.log('reads', reads, 'shift histogram', JSON.stringify(h));
})();

(async () => {
const X = await require('./t4');
const { m } = X;
for (const q of process.argv.slice(2).map(Number)) {
  console.log('--- A shot', q, 'len', m.SA.list[q].len);
  for (const d of [-1, 0, 1]) { const p = m.dec.pick[q + d]; console.log('  ', q + d, p ? `→ B${p.h.b} ${p.how} k ${p.h.k} Δ ${p.h.delta?.toFixed(2)} s ${p.h.score?.toFixed(3)} ms ${p.h.ms?.toFixed(3)}` : 'none'); }
  console.log('   hyps', m.hyps[q].slice(0, 8).map((h) => `B${h.b}:${h.score.toFixed(3)}/${h.ms.toFixed(2)}/rs${h.rs.toFixed(2)}${h.reframe ? 'R' : ''}`).join(' '));
}
})();

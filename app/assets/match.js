/* Previously Noted — placing carried notes by matching pictures between two cuts.
 *
 * 1. Fingerprint each cut in the browser: a 32×18 grey picture every quarter second, taken from the picture
 *    area (black bars left out). The video stays on this computer; only the fingerprints are saved.
 * 2. Shots: cuts are found in both. Each shot of the old cut is lined up against the shots of the new cut that
 *    look like it, comparing the pictures (brightness and contrast don't count, so regrades still match), how they
 *    move, and what moves within the shot (which tells apart bites of the same interview). Reframed shots are
 *    found by trying zooms and moves.
 * 3. Deciding: clear matches first, then the shots around them as context, the way an editor would ("the shots
 *    either side didn't move, so this slot is this shot, even if its VFX changed"). Shots used twice, moved
 *    sections and hidden cuts between takes of the same set-up are handled.
 * 4. Optional AI image model (in the browser) for shots that changed beyond recognition.
 * 5. To the frame: exact cut frames in both videos, and short runs of real frames around the notes.
 *
 * Pure matching functions work on plain arrays, so they can be tested outside a browser.
 */
(function (root) {
  'use strict';
  const MODEL_LIB = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.1/dist/transformers.min.js';
  const MODEL_ID = 'Xenova/clip-vit-base-patch32';
  const SAMPLE = 0.25;
  const GW = 32, GH = 18, P = GW * GH;
  const RW = 16, RH = 9, RP = RW * RH;

  const dotv = (a, ao, b, bo, len) => { let s = 0; for (let k = 0; k < len; k++) s += a[ao + k] * b[bo + k]; return s; };

  // Unit-length, zero-mean copy (so a dot product is a correlation: brightness and contrast don't count).
  function unitZ(src, so, len, dst, dO) {
    let m = 0; for (let k = 0; k < len; k++) m += src[so + k]; m /= len;
    let q = 0; for (let k = 0; k < len; k++) { const d = src[so + k] - m; q += d * d; }
    const sd = Math.sqrt(q / len);
    if (sd > 0.5) { const inv = 1 / Math.sqrt(q); for (let k = 0; k < len; k++) dst[dO + k] = (src[so + k] - m) * inv; }
    else for (let k = 0; k < len; k++) dst[dO + k] = 0;
    return { m, sd };
  }

  function prep(fp) {
    if (fp._p) return fp._p;
    const n = fp.n, g = fp.g;
    const v = new Float32Array(n * P), r = new Float32Array(n * RP), sd = new Float32Array(n), mean = new Float32Array(n), flat = new Uint8Array(n);
    const tmp = new Float32Array(RP);
    for (let i = 0; i < n; i++) {
      const o = i * P;
      const z = unitZ(g, o, P, v, o);
      mean[i] = z.m; sd[i] = z.sd; flat[i] = z.sd < 1.5 ? 1 : 0;
      for (let y = 0; y < RH; y++) for (let x = 0; x < RW; x++) { const a = o + 2 * y * GW + 2 * x; tmp[y * RW + x] = g[a] + g[a + 1] + g[a + GW] + g[a + GW + 1]; }
      unitZ(tmp, 0, RP, r, i * RP);
    }
    // Movement between neighbouring samples: the change in the (normalised) picture, and how big it is.
    const mo = new Float32Array(n * P), me = new Float32Array(n);
    for (let i = 1; i < n; i++) {
      const o = i * P, p = o - P;
      let q = 0; for (let k = 0; k < P; k++) { const d = v[o + k] - v[p + k]; mo[o + k] = d; q += d * d; }
      const e = Math.sqrt(q); me[i] = e;
      if (e > 1e-6) for (let k = 0; k < P; k++) mo[o + k] /= e;
    }
    return (fp._p = { n, t: fp.t, dur: fp.dur, v, r, sd, mean, flat, mo, me });
  }

  /* ---------- shots ---------- */
  const CUT_REL = 4, CUT_ABS = 0.05;
  // How much of the picture changed between two samples: the middle value over 12 areas, so a hand coming
  // into frame or a blink doesn't count as a cut, but a change of shot (which changes every area) does.
  function blockChange(g, i, j) {
    const out = [];
    for (let by = 0; by < 3; by++) for (let bx = 0; bx < 4; bx++) {
      let ma = 0, mb = 0, n = 0;
      for (let y = by * 6; y < by * 6 + 6; y++) for (let x = bx * 8; x < bx * 8 + 8; x++) { ma += g[i * P + y * GW + x]; mb += g[j * P + y * GW + x]; n++; }
      ma /= n; mb /= n;
      let sab = 0, saa = 0, sbb = 0;
      for (let y = by * 6; y < by * 6 + 6; y++) for (let x = bx * 8; x < bx * 8 + 8; x++) {
        const a = g[i * P + y * GW + x] - ma, b = g[j * P + y * GW + x] - mb; sab += a * b; saa += a * a; sbb += b * b;
      }
      const sa = Math.sqrt(saa / n), sb = Math.sqrt(sbb / n);
      // Plain areas: compare brightness. Textured: compare the pattern.
      const d = sa < 4 || sb < 4 ? Math.min(1, Math.abs(ma - mb) / 30 + Math.abs(sa - sb) / 30) : 1 - sab / Math.sqrt(saa * sbb);
      out.push(d);
    }
    out.sort((x, y) => x - y);
    return (out[5] + out[6]) / 2;
  }
  function shots(pp, g) {
    const n = pp.n, c = new Float32Array(n);
    for (let i = 1; i < n; i++) c[i] = blockChange(g, i, i - 1);
    const cuts = [0];
    for (let i = 1; i < n; i++) {
      const near = [];
      for (let k = Math.max(1, i - 6); k <= Math.min(n - 1, i + 6); k++) if (Math.abs(k - i) > 1) near.push(c[k]);
      near.sort((a, b) => a - b);
      const med = near.length ? near[near.length >> 1] : 0;
      // A cut: a big change that stands out from the movement around it, and is the biggest of its neighbours.
      if (c[i] > 0.2 && c[i] > CUT_REL * med + CUT_ABS && c[i] >= c[i - 1] && (i + 1 >= n || c[i] >= c[i + 1])) {
        if (i - cuts[cuts.length - 1] >= 2 || c[i] > 0.6) cuts.push(i);
      }
    }
    const list = [], of = new Int32Array(n);
    for (let s = 0; s < cuts.length; s++) {
      const i0 = cuts[s], i1 = s + 1 < cuts.length ? cuts[s + 1] : n;
      for (let i = i0; i < i1; i++) of[i] = list.length;
      list.push(makeShot(pp, i0, i1, list.length));
    }
    return { list, of, c };
  }
  function makeShot(pp, i0, i1, k) {
    let flatN = 0, mov = 0;
    for (let i = i0; i < i1; i++) { flatN += pp.flat[i]; if (i > i0) mov += pp.me[i]; }
    return { k, i0, i1, len: i1 - i0, t0: pp.t[i0], t1: i1 < pp.n ? pp.t[i1] : pp.dur, flat: flatN / (i1 - i0) > 0.5, motion: i1 - i0 > 1 ? mov / (i1 - i0 - 1) : 0 };
  }
  function indexShots(list, n) {
    const of = new Int32Array(n);
    list.forEach((s, k) => { s.k = k; for (let i = s.i0; i < s.i1; i++) of[i] = k; });
    return { list, of };
  }

  /* ---------- finding which shots of B could hold a shot of A ---------- */
  function keyframes(s) {
    const lo = s.len >= 3 ? s.i0 + 1 : s.i0, hi = s.len >= 3 ? s.i1 - 1 : s.i1;
    const cnt = Math.min(8, hi - lo), out = [];
    for (let q = 0; q < cnt; q++) out.push(lo + Math.floor(((q + 0.5) * (hi - lo)) / cnt));
    return out;
  }
  // Small pictures of every sample, enlarged about the centre (for finding reframed shots).
  function zoomedR(pp, g, sc) {
    pp.zr ??= {};
    if (pp.zr[sc]) return pp.zr[sc];
    const out = new Float32Array(pp.n * RP), tmp = new Float32Array(P), red = new Float32Array(RP);
    for (let i = 0; i < pp.n; i++) {
      warp(g, i * P, sc, 0, 0, tmp);
      for (let y = 0; y < RH; y++) for (let x = 0; x < RW; x++) { const a = 2 * y * GW + 2 * x; red[y * RW + x] = tmp[a] + tmp[a + 1] + tmp[a + GW] + tmp[a + GW + 1]; }
      unitZ(red, 0, RP, out, i * RP);
    }
    return (pp.zr[sc] = out);
  }
  function candidatesZoom(pa, pb, ga, gb, a, SB) {
    const best = new Float32Array(SB.list.length).fill(-1);
    for (const sc of [1.2, 1.4]) {
      const za = zoomedR(pa, ga, sc), zb = zoomedR(pb, gb, sc);
      for (const i of keyframes(a)) {
        if (pa.flat[i]) continue;
        for (let j = 0; j < pb.n; j++) {
          if (pb.flat[j]) continue;
          const s = Math.max(dotv(za, i * RP, pb.r, j * RP, RP), dotv(pa.r, i * RP, zb, j * RP, RP));
          if (s > best[SB.of[j]]) best[SB.of[j]] = s;
        }
      }
    }
    const list = [];
    for (let b = 0; b < best.length; b++) if (best[b] >= 0.7) list.push({ b, sim: best[b] });
    return list.sort((x, y) => y.sim - x.sim).slice(0, 5);
  }
  function candidates(pa, pb, SA, SB, o = {}) { return SA.list.map((a) => candidatesFor(pa, pb, a, SB, o)); }
  function candidatesFor(pa, pb, a, SB, { minSim = 0.55, maxC = 24 } = {}) {
    {
      const best = new Float32Array(SB.list.length).fill(-1);
      if (!a.flat) for (const i of keyframes(a)) {
        if (pa.flat[i]) continue;
        for (let j = 0; j < pb.n; j++) {
          if (pb.flat[j]) continue;
          const s = dotv(pa.r, i * RP, pb.r, j * RP, RP);
          const b = SB.of[j];
          if (s > best[b]) best[b] = s;
        }
      }
      const list = [];
      for (let b = 0; b < best.length; b++) if (best[b] >= minSim) list.push({ b, sim: best[b] });
      list.sort((x, y) => y.sim - x.sim);
      const top = list.length ? list[0].sim : 0;
      return list.filter((x) => x.sim >= top - 0.3).slice(0, maxC);
    }
  }

  /* ---------- lining up a shot of A against a shot of B ---------- */
  /* ---------- a shot's pictures, ready to compare ---------- */
  // v: normalised pictures; mo/me: movement to the next sample and its size; res/e: what's left once the shot's
  // average picture is taken away (the parts that move: two bites of one interview share the rest).
  function shotFromLuma(g, L) {
    const v = new Float32Array(L * P), flat = new Uint8Array(L);
    for (let l = 0; l < L; l++) flat[l] = unitZ(g, l * P, P, v, l * P).sd < 1.5 ? 1 : 0;
    return finishShot(v, flat, L);
  }
  function finishShot(v, flat, L) {
    const mo = new Float32Array(L * P), me = new Float32Array(L), res = new Float32Array(L * P), e = new Float32Array(L), mu = new Float32Array(P);
    for (let l = 1; l < L; l++) {
      let q = 0;
      for (let k = 0; k < P; k++) { const d = v[l * P + k] - v[(l - 1) * P + k]; mo[l * P + k] = d; q += d * d; }
      me[l] = Math.sqrt(q);
      if (me[l] > 1e-6) for (let k = 0; k < P; k++) mo[l * P + k] /= me[l];
    }
    // Against the average of the neighbouring samples (about four seconds) rather than the whole shot: a title or
    // lower third that comes and goes in one cut then doesn't swamp what moves.
    const RW2 = 8;
    for (let l = 0; l < L; l++) {
      if (flat[l]) continue;
      mu.fill(0);
      let cnt = 0;
      for (let m = Math.max(0, l - RW2); m <= Math.min(L - 1, l + RW2); m++) if (!flat[m]) { cnt++; for (let k = 0; k < P; k++) mu[k] += v[m * P + k]; }
      for (let k = 0; k < P; k++) mu[k] /= cnt;
      let q = 0;
      for (let k = 0; k < P; k++) { const d = v[l * P + k] - mu[k]; res[l * P + k] = d; q += d * d; }
      e[l] = Math.sqrt(q);
      if (e[l] > 1e-6) for (let k = 0; k < P; k++) res[l * P + k] /= e[l];
    }
    return { L, v, flat, mo, me, res, e };
  }
  function shotData(pp, s) {
    pp.cache ??= new Map();
    const key = s.i0 + ':' + s.i1;
    if (!pp.cache.has(key)) pp.cache.set(key, finishShot(pp.v.slice(s.i0 * P, s.i1 * P), pp.flat.slice(s.i0, s.i1), s.len));
    return pp.cache.get(key);
  }

  /* ---------- reframes: find the zoom and position that turns one shot's picture into the other's ---------- */
  function warp(g, o, sc, dx, dy, out) {
    // out(x, y) = g at the point that lands on (x, y) when g is enlarged sc times about its centre and moved by (dx, dy) picture widths.
    for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) {
      const sx = Math.min(GW - 1, Math.max(0, (x + 0.5 - GW / 2) / sc + GW / 2 + dx * GW - 0.5));
      const sy = Math.min(GH - 1, Math.max(0, (y + 0.5 - GH / 2) / sc + GH / 2 + dy * GW - 0.5));
      const x0 = Math.floor(sx), y0 = Math.floor(sy), x1 = Math.min(GW - 1, x0 + 1), y1 = Math.min(GH - 1, y0 + 1), fx = sx - x0, fy = sy - y0;
      out[y * GW + x] = (g[o + y0 * GW + x0] * (1 - fx) + g[o + y0 * GW + x1] * fx) * (1 - fy) + (g[o + y1 * GW + x0] * (1 - fx) + g[o + y1 * GW + x1] * fx) * fy;
    }
    return out;
  }
  // The zoom and move (on A's side, or on B's for a wider shot) that best turns A's picture at sample ia into B's at jb.
  function findReframe(ga, ia, gb, jb) {
    const tmp = new Float32Array(P), za = new Float32Array(P), zb = new Float32Array(P);
    let best = { s: -2 };
    const tryT = (side, sc, dx, dy) => {
      if (side === 'A') { warp(ga, ia * P, sc, dx, dy, tmp); unitZ(tmp, 0, P, za, 0); unitZ(gb, jb * P, P, zb, 0); }
      else { warp(gb, jb * P, sc, dx, dy, tmp); unitZ(tmp, 0, P, zb, 0); unitZ(ga, ia * P, P, za, 0); }
      const s = dotv(za, 0, zb, 0, P);
      if (s > best.s) best = { s, side, sc, dx, dy };
    };
    for (const side of ['A', 'B']) for (const sc of [1.1, 1.2, 1.3, 1.45]) for (let dx = -0.12; dx <= 0.121; dx += 0.03) for (let dy = -0.09; dy <= 0.091; dy += 0.03) tryT(side, sc, dx, dy);
    const c = { ...best };
    for (let sc = c.sc - 0.06; sc <= c.sc + 0.061; sc += 0.02) for (let dx = c.dx - 0.02; dx <= c.dx + 0.021; dx += 0.01) for (let dy = c.dy - 0.02; dy <= c.dy + 0.021; dy += 0.01) tryT(c.side, sc, dx, dy);
    return best;
  }
  function warpShot(g, s, T) {
    const out = new Float32Array(s.len * P), tmp = new Float32Array(P);
    for (let l = 0; l < s.len; l++) { warp(g, (s.i0 + l) * P, T.sc, T.dx, T.dy, tmp); out.set(tmp, l * P); }
    return shotFromLuma(out, s.len);
  }

  // For each shift k (B sample = A sample + k, in shot-local indices), how well the pictures, their movement,
  // and what moves within the shot agree.
  const MOVE = 0.05, RESID = 0.06;
  function alignData(A, Bd) {
    const LA = A.L, LB = Bd.L;
    const S = new Float32Array(LA * LB), M = new Float32Array(LA * LB), Mw = new Uint8Array(LA * LB), R = new Float32Array(LA * LB), Rw = new Uint8Array(LA * LB);
    for (let i = 0; i < LA; i++) for (let j = 0; j < LB; j++) {
      const x = i * LB + j;
      S[x] = A.flat[i] || Bd.flat[j] ? 0 : dotv(A.v, i * P, Bd.v, j * P, P);
      if (i > 0 && j > 0 && A.me[i] > MOVE && Bd.me[j] > MOVE) { M[x] = dotv(A.mo, i * P, Bd.mo, j * P, P); Mw[x] = 1; }
      if (A.e[i] > RESID && Bd.e[j] > RESID) { R[x] = dotv(A.res, i * P, Bd.res, j * P, P); Rw[x] = 1; }
    }
    const prof = [];
    for (let k = -(LA - 1); k <= LB - 1; k++) {
      let s = 0, cnt = 0, m = 0, mc = 0, r = 0, rc = 0;
      const i0 = Math.max(0, -k), i1 = Math.min(LA, LB - k);
      const ov = i1 - i0;
      if (ov < Math.min(3, LA, LB)) continue;
      for (let i = i0; i < i1; i++) {
        const j = i + k, x = i * LB + j;
        // Samples right at a cut may be blended with the next shot; leave them out when there's enough else.
        if (ov > 4 && (i === 0 || i === LA - 1 || j === 0 || j === LB - 1)) continue;
        s += S[x]; cnt++;
        if (Mw[x]) { m += M[x]; mc++; }
        if (Rw[x]) { r += R[x]; rc++; }
      }
      if (!cnt) continue;
      const ms = s / cnt, mm = mc ? m / mc : 0, rs = rc ? r / rc : 0;
      // More frames that agree is more evidence: a whole shot lining up beats a short stretch of a look-alike.
      prof.push({ k, ov, cnt, ms, mm, mc, rs, rc, score: ms + 0.35 * (mc / (mc + 2)) * mm + 0.35 * (rc / (rc + 2)) * rs + 0.08 * Math.log(cnt) });
    }
    if (!prof.length) return null;
    let best = prof[0];
    for (const p of prof) if (p.score > best.score) best = p;
    return { ...best, prof };
  }
  function align(pa, pb, a, b, { reframe = false, ga, gb } = {}) {
    let r = alignData(shotData(pa, a), shotData(pb, b));
    if (reframe && ga && gb && (!r || r.ms < 0.9 || (r.rc >= 3 && r.rs < 0.7)) && a.len >= 2 && b.len >= 2) {
      // Try the zoom at a few likely line-ups: first frames together, last frames together, and the best by content.
      // (Finding the zoom at a fixed line-up keeps a slow zoom-in from passing for a reframe at the wrong moment.)
      const ks = new Set([0, b.len - a.len, ...(r ? [r.k] : [])]);
      for (const k of ks) {
        const il = Math.max(0, Math.min(a.len - 1, (a.len >> 1) - (k < 0 ? 0 : 0)));
        let i = il; if (i + k < 0) i = -k; if (i + k >= b.len) i = b.len - 1 - k;
        if (i < 0 || i >= a.len) continue;
        const T = findReframe(ga, a.i0 + i, gb, b.i0 + i + k);
        if (T.s < 0.8) continue;
        const A2 = T.side === 'A' ? warpShot(ga, a, T) : shotData(pa, a);
        const B2 = T.side === 'B' ? warpShot(gb, b, T) : shotData(pb, b);
        const r2 = alignData(A2, B2);
        // A zoom can make almost any two soft pictures look alike: it has to earn its place.
        if (r2 && r2.ms >= 0.85 && (!r || r2.score > r.score + 0.05)) r = { ...r2, reframe: T };
      }
    }
    return r ? { a: a.k, b: b.k, ...r } : null;
  }


  /* ---------- deciding, shot by shot, with the neighbours as context ---------- */
  // Time offset (B − A) of a hypothesis, from the samples it lines up.
  function deltaOf(pa, pb, a, b, k) {
    const ds = [];
    for (let i = Math.max(0, -k); i < Math.min(a.len, b.len - k); i++) ds.push(pb.t[b.i0 + i + k] - pa.t[a.i0 + i]);
    ds.sort((x, y) => x - y);
    return ds.length ? ds[ds.length >> 1] : 0;
  }
  function strong(h, a) {
    if (!h || h.ms < 0.9) return false;
    if (h.rc >= 5 && h.rs < 0.5) return false;
    if (h.mc >= 3 && h.mm < 0.4) return false;
    // A moving shot matched only to still pictures: not the same shot.
    if (a.motion > MOVE * 1.5 && h.cnt >= 4 && h.mc + h.rc < 2) return false;
    return true;
  }
  function decide(pa, pb, SA, SB, hypsByA, opt = {}) {
    const nA = SA.list.length, nB = SB.list.length;
    const pick = new Array(nA).fill(null);
    const info = SA.list.map((a, q) => {
      const hs = hypsByA[q];
      for (const h of hs) h.delta = deltaOf(pa, pb, a, SB.list[h.b], h.k);
      const best = hs[0];
      const second = hs.find((h) => h !== best && (h.b !== best.b || Math.abs(h.k - best.k) > 2));
      // Several shots of B match about as well (bites of one interview, say): the picture alone can't decide.
      const rivals = hs.filter((h) => h !== best && strong(h, a) && h.score >= best.score - 0.1).length;
      return { best, margin: best ? best.score - (second ? second.score : 0.5) : 0, strong: strong(best, a), rivals };
    });
    const sure = (p) => p && ['clear', 'context', 'gap', 'reassigned'].includes(p.how);
    const claimedB = () => new Set(pick.filter((p) => p && p.how !== 'duplicate').map((p) => p.h.b));
    const next1 = (h, nb, dir) => nb && (h.b - nb.h.b === dir || (h.b === nb.h.b && Math.abs(h.delta - nb.h.delta) < 0.13));

    // 1. Clear winners: a strong match well ahead of anything else.
    // (What moves in the shot has to agree too: bites of one interview share everything else.)
    const clearH = (h) => h.rc < 5 || h.rs >= 0.7;
    // (A held picture has only its look to go on: titles and stills can look alike, so it has to be near-perfect.)
    // (A zoom found to make two shots match can make look-alikes match too: a reframe needs its neighbours' support.)
    const stillOk = (h) => (h.mc + h.rc > 0 || h.ms >= 0.97) && !h.reframe;
    for (let q = 0; q < nA; q++) if (info[q].strong && info[q].margin >= 0.1 && clearH(info[q].best) && stillOk(info[q].best)) pick[q] = { h: info[q].best, how: 'clear' };

    // 2. Context: a match that sits next to a sure neighbour's shot in B (the edit kept them together).
    //    It can choose between pictures that match about as well; it can't overrule a clearly better one.
    const runContext = () => {
      let any = false;
      for (let pass = 0; pass < 10; pass++) {
        let changed = false;
        for (let q = 0; q < nA; q++) {
          if (pick[q]) continue;
          const prev = q > 0 && sure(pick[q - 1]) ? pick[q - 1] : null, next = q + 1 < nA && sure(pick[q + 1]) ? pick[q + 1] : null;
          if (!prev && !next) continue;
          // (A look-alike found only by zooming doesn't outrank a neighbour's support.)
          const top = hypsByA[q].find((h) => !h.reframe) || hypsByA[q][0];
          const both = (h) => next1(h, prev, 1) && next1(h, next, -1);
          // One neighbour needs a good picture match; with both neighbours agreeing, a fair one will do.
          const hs = hypsByA[q].filter((h) => (both(h) ? h.ms >= 0.6 : (next1(h, prev, 1) || next1(h, next, -1)) && h.ms >= 0.85) &&
            (!info[q].strong || h.score >= top.score - (both(h) ? 0.15 : 0.08)));
          hs.sort((x, y) => both(y) - both(x) || y.score - x.score);
          if (hs.length) { pick[q] = { h: hs[0], how: 'context' }; changed = any = true; }
        }
        if (!changed) break;
      }
      return any;
    };

    // 3. Gaps: a run of unplaced shots of A between two sure ones, and the unclaimed shots of B between theirs.
    //    Paired off in order, by how well they match (allowing for reframes); with as many on each side, in order.
    const fillGap = (run, lo, hi, prev, next) => {
      const taken = claimedB();
      const gap = []; for (let b = lo; b <= hi; b++) if (!taken.has(b)) gap.push(b);
      if (!gap.length) return false;
      const H = run.map((q) => gap.map((b) => {
        const known = hypsByA[q].find((h) => h.b === b);
        const h = known && known.ms >= 0.85 ? known : align(pa, pb, SA.list[q], SB.list[b], opt);
        return h ? { ...h, a: q, b } : null;
      }));
      // A shot whose picture clearly matches somewhere else was moved: it isn't part of this gap.
      const moved = run.map((q, x) => {
        const top = hypsByA[q].find((h) => !taken.has(h.b) && !gap.includes(h.b));
        if (!top || !info[q].strong || !strong(top, SA.list[q])) return false;
        return Math.max(-1, ...H[x].map((h) => (h ? h.score : -1))) < top.score - 0.15;
      });
      const R = run.length, G = gap.length, forced = R === G && !moved.some(Boolean) && hi - lo + 1 === G;
      // A changed or replaced shot (no good match anywhere) may take a place between two placed neighbours.
      const weakOk = (x) => forced || (prev && next && !info[run[x]].strong);
      const val = (x, y) => { if (moved[x]) return -1; const h = H[x][y]; if (!h) return weakOk(x) ? 0.05 : -1; return h.ms >= 0.5 ? h.score : weakOk(x) ? 0.05 + h.ms * 0.1 : -1; };
      // Best pairing in order, each side used at most once. Leaving a shot of B out costs more than leaving out one
      // of A: edits keep neighbours together, so a changed shot in its own place beats a look-alike further on.
      const SKIP_B = 0.25, SKIP_A = 0.15;
      const D = Array.from({ length: R + 1 }, () => new Float64Array(G + 1)), W = Array.from({ length: R + 1 }, () => new Int8Array(G + 1));
      for (let y = G - 1; y >= 0; y--) { D[R][y] = D[R][y + 1] - SKIP_B; W[R][y] = 1; }
      for (let x = R - 1; x >= 0; x--) for (let y = G; y >= 0; y--) {
        let best = D[x + 1][y] - SKIP_A, how = 0;
        if (y < G && D[x][y + 1] - SKIP_B > best) { best = D[x][y + 1] - SKIP_B; how = 1; }
        if (y < G && val(x, y) > 0 && val(x, y) + D[x + 1][y + 1] > best) { best = val(x, y) + D[x + 1][y + 1]; how = 2; }
        D[x][y] = best; W[x][y] = how;
      }
      let any = false;
      for (let x = 0, y = 0; x < R && y <= G;) {
        const how = W[x][y];
        if (how === 2) {
          const h = H[x][y] || { a: run[x], b: gap[y], k: 0, score: 0, ms: 0, cnt: 0 }, a = SA.list[run[x]], b = SB.list[gap[y]];
          // Too changed to line up by content: line up by the cuts.
          if (h.ms < 0.85) h.k = Math.abs(b.len - a.len) <= 1 || !next ? 0 : !prev ? b.len - a.len : 0;
          h.delta = deltaOf(pa, pb, a, b, h.k);
          pick[run[x]] = { h, how: h.ms >= 0.85 ? 'gap' : 'slot' };
          any = true; x++; y++;
        } else if (how === 1) y++; else x++;
      }
      return any;
    };
    const runGaps = () => {
      let any = false;
      for (let q1 = 0; q1 < nA; q1++) {
        if (pick[q1]) continue;
        let q2 = q1; while (q2 + 1 < nA && !pick[q2 + 1]) q2++;
        const prev = q1 > 0 ? pick[q1 - 1] : null, next = q2 + 1 < nA ? pick[q2 + 1] : null;
        const run = []; for (let q = q1; q <= q2; q++) run.push(q);
        q1 = q2;
        if (!sure(prev) && !sure(next)) continue;
        const lo = prev ? prev.h.b + 1 : 0, hi = next ? next.h.b - 1 : nB - 1;
        if (sure(prev) && sure(next) && hi >= lo && hi - lo <= run.length + 3) { if (fillGap(run, lo, hi, prev, next)) any = true; continue; }
        // The neighbours went to different places (a section moved): try just after the previous one, then just before the next.
        if (sure(prev) && prev.h.b + 1 < nB && fillGap(run, prev.h.b + 1, Math.min(nB - 1, prev.h.b + run.length + 1), prev, null)) { any = true; continue; }
        if (sure(next) && next.h.b > 0 && fillGap(run, Math.max(0, next.h.b - run.length - 1), next.h.b - 1, null, next)) any = true;
      }
      return any;
    };
    for (let round = 0; round < 4; round++) { const c1 = runContext(); const c2 = runGaps(); if (!c1 && !c2) break; }

    // 4. Still unplaced but with a strong match: take it (flagged when it has rivals).
    for (let q = 0; q < nA; q++) if (!pick[q] && info[q].strong) pick[q] = { h: info[q].best, how: info[q].rivals || !stillOk(info[q].best) ? 'close call' : 'clear' };
    for (let round = 0; round < 2; round++) { const c1 = runContext(); const c2 = runGaps(); if (!c1 && !c2) break; }

    // 5. Two shots of A on the same moment of B (a picture used twice in A, once in B): keep the one its neighbours
    //    support; the other tries its next-best match among the shots of B nobody has claimed.
    const support = (q) => (q > 0 && pick[q - 1] && next1(pick[q].h, pick[q - 1], 1) ? 1 : 0) + (q + 1 < nA && pick[q + 1] && next1(pick[q].h, pick[q + 1], -1) ? 1 : 0);
    const overlapsB = (q1, q2) => {
      const p = pick[q1], r = pick[q2], sb = SB.list[p.h.b];
      const ta = [SA.list[q1].t0 + p.h.delta, SA.list[q1].t1 + p.h.delta], tb = [SA.list[q2].t0 + r.h.delta, SA.list[q2].t1 + r.h.delta];
      return Math.min(ta[1], tb[1], sb.t1) - Math.max(ta[0], tb[0], sb.t0) > 0.3;
    };
    for (let round = 0; round < 3; round++) {
      const byB = new Map();
      pick.forEach((p, q) => { if (p && p.how !== 'duplicate') { const l = byB.get(p.h.b) || []; l.push(q); byB.set(p.h.b, l); } });
      const losers = [];
      for (const qs of byB.values()) {
        if (qs.length < 2) continue;
        for (let x = 0; x < qs.length; x++) for (let y = x + 1; y < qs.length; y++) {
          if (pick[qs[x]].how === 'duplicate' || pick[qs[y]].how === 'duplicate' || !overlapsB(qs[x], qs[y])) continue;
          const rank = (q) => support(q) * 2 + (sure(pick[q]) ? 1 : 0) + (pick[q].h.ms >= 0.9 ? 1 : pick[q].h.ms < 0.5 ? -1 : 0);
          const sx = rank(qs[x]), sy = rank(qs[y]);
          const lose = sx > sy || (sx === sy && pick[qs[x]].h.score >= pick[qs[y]].h.score) ? qs[y] : qs[x];
          pick[lose] = { ...pick[lose], how: 'duplicate' };
          losers.push(lose);
        }
      }
      if (!losers.length) break;
      const taken = claimedB();
      for (const q of losers) {
        const alt = hypsByA[q].find((h) => !taken.has(h.b) && strong(h, SA.list[q]) && h.score >= hypsByA[q][0].score - 0.15);
        if (alt) { pick[q] = { h: alt, how: 'reassigned' }; taken.add(alt.b); }
      }
    }
    // Shots that lost their place: try the gaps and context again; if nothing, they stay where they were, flagged.
    const lost = [];
    pick.forEach((p, q) => { if (p && p.how === 'duplicate') { lost.push([q, p]); pick[q] = null; } });
    if (lost.length) {
      for (let round = 0; round < 3; round++) { const c1 = runContext(); const c2 = runGaps(); if (!c1 && !c2) break; }
      for (const [q, p] of lost) if (!pick[q]) pick[q] = p;
    }
    // 6. Structure beats a look-alike: when the shots either side kept the same offset and the shot of B between
    //    them starts and ends exactly where this shot would, this shot is that one, whatever the picture says
    //    (a changed VFX shot, say, while a different bite of the same interview happens to look closer).
    //    And a shot placed away from both neighbours, while a free shot sits between them, is flagged.
    for (let q = 1; q < nA - 1; q++) {
      const prev = pick[q - 1], next = pick[q + 1], p = pick[q];
      if (!sure(prev) || !sure(next) || next.h.b - prev.h.b !== 2) continue;
      const g = prev.h.b + 1, a = SA.list[q], gb = SB.list[g];
      if (p && p.h.b === g) continue;
      const held = pick.some((x, y) => y !== q && x && x.how !== 'duplicate' && x.h.b === g);
      if (held) continue;
      const d = prev.h.delta, fits = Math.abs(d - next.h.delta) < 0.13 && Math.abs(gb.t0 - (a.t0 + d)) < 0.3 && Math.abs(gb.t1 - (a.t1 + d)) < 0.3;
      const h = align(pa, pb, a, gb, opt) || { a: q, b: g, k: 0, score: 0, ms: 0, cnt: 0 };
      h.a = q; h.b = g;
      if (fits || !p || h.ms >= 0.5) {
        if (h.ms < 0.85) h.k = Math.abs(gb.len - a.len) <= 1 ? 0 : h.k;
        h.delta = deltaOf(pa, pb, a, gb, h.k);
        pick[q] = { h, how: fits && h.ms >= 0.85 ? 'context' : fits ? 'slot' : 'close call' };
      } else if (p && !next1(p.h, prev, 1) && !next1(p.h, next, -1)) pick[q] = { ...p, how: 'close call' };
    }
    return { pick, info };
  }

  /* ---------- placing each note ---------- */
  function placeNotes(pa, pb, SA, SB, dec, notes) {
    const out = [];
    for (const note of notes) {
      const i = Math.max(0, Math.min(pa.n - 1, Math.round((note.t - 0.02) / SAMPLE)));
      const q = SA.of[i], a = SA.list[q], p = dec.pick[q];
      if (!p) { out.push({ id: note.id, tA: note.t, status: 'unplaced', shotA: q }); continue; }
      const h = p.h, b = SB.list[h.b];
      let k = h.k;
      // Still pictures look the same all through the shot: line up by the cut instead (first frame, else last).
      // Near-best line-ups: a slow zoom or a held picture matches almost as well a little earlier or later.
      let plateau = [];
      if (h.prof) {
        const sc = h.prof.map((x) => x.score).sort((x, y) => x - y), base = sc[sc.length >> 1];
        const tol = Math.max(0.004, 0.06 * (h.score - base));
        plateau = h.prof.filter((x) => x.score >= h.score - tol).map((x) => x.k);
      }
      // Same length in both cuts (not trimmed): first frames line up, unless the pictures clearly say otherwise.
      const at0 = h.prof && h.prof.find((x) => x.k === 0);
      if (Math.abs(b.len - a.len) <= 1 && k !== 0 && at0 && at0.score >= h.score - 0.03) k = 0;
      let anchored = false;
      if (plateau.length > 1) {
        const head = 0, tail = b.len - a.len;
        // Both ends fit: take the one nearer the best line-up by content.
        const hIn = plateau.includes(head), tIn = plateau.includes(tail);
        if (hIn && (!tIn || Math.abs(head - h.k) <= Math.abs(tail - h.k))) { k = head; anchored = 'head'; } else if (tIn) { k = tail; anchored = 'tail'; }
      }
      const il = i - a.i0, jl = il + k;
      let tB, reason;
      if (jl >= 0 && jl < b.len) {
        tB = note.t + (pb.t[b.i0 + jl] - pa.t[i]);
        // Sure only when the picture says so, including what moves in the shot (bites of one interview look alike).
        const movesAgree = !(h.rc >= 5) || h.rs >= 0.7;
        reason = { clear: 'visual', context: movesAgree ? 'visual' : 'likely', reassigned: movesAgree ? 'visual' : 'likely', gap: 'likely', slot: 'changed', 'close call': 'likely', duplicate: 'ambiguous', ai: 'visual-ai' }[p.how] || 'likely';
        if (p.how === 'context' && h.ms < 0.85) reason = 'changed';
      } else {
        const jc = Math.max(0, Math.min(b.len - 1, jl));
        tB = pb.t[b.i0 + jc];
        reason = 'trimmed';
      }
      out.push({ id: note.id, tA: note.t, status: 'matched', tB, reason, confident: reason === 'visual' || (reason === 'visual-ai' && !!p.sure), shotA: q, shotB: h.b, k, anchored, how: p.how });
    }
    // Not found: just after where the previous note went, flagged.
    const placed = out.filter((r) => r.status === 'matched').sort((x, y) => x.tA - y.tA);
    for (const r of out) {
      if (r.status === 'matched') continue;
      const prev = placed.filter((x) => x.tA < r.tA).pop();
      Object.assign(r, { tB: Math.max(0, r.tA + (prev ? prev.tB - prev.tA : 0)), reason: 'removed', confident: false });
    }
    return out;
  }

  async function matchShots(A, B, { onProgress, signal } = {}) {
    const pause = () => new Promise((r) => setTimeout(r, 0));
    const pa = prep(A), pb = prep(B);
    let SA = shots(pa, A.g);
    const SB = shots(pb, B.g);
    const opt = { reframe: true, ga: A.g, gb: B.g };
    const hypsFor = (a) => {
      const cands = candidatesFor(pa, pb, a, SB);
      let hs = cands.map((c) => align(pa, pb, a, SB.list[c.b])).filter(Boolean).sort((x, y) => y.score - x.score);
      // Nothing matches well: maybe reframed. Try the likeliest few again, allowing for a zoom and move.
      // (Also when the best match is good but not near-perfect: a slow zoom can pass for a reframe at the wrong moment.)
      if (!hs.length || !strong(hs[0], a) || hs[0].ms < 0.98) {
        const extra = candidatesZoom(pa, pb, A.g, B.g, a, SB).filter((c) => !cands.slice(0, 5).some((x) => x.b === c.b));
        const again = [...cands.slice(0, 5), ...extra].map((c) => align(pa, pb, a, SB.list[c.b], opt)).filter(Boolean);
        const seen = new Set(again.map((h) => h.b));
        hs = [...again, ...hs.filter((h) => !seen.has(h.b))].sort((x, y) => y.score - x.score);
      }
      return hs;
    };
    // A cut between two takes of the same set-up (a jump cut in an interview) can hide in the fingerprint. It shows
    // when one part of a shot matches one place and the rest matches somewhere else: split it there.
    const trySplit = (a, hs) => {
      const h = hs[0];
      // Only moving shots: a cut inside a held picture can't be seen, and doesn't matter.
      if (!h || !strong(h, a) || a.motion < MOVE * 1.5) return null;
      const b = SB.list[h.b];
      const c0 = Math.max(0, -h.k), c1 = Math.min(a.len, b.len - h.k);
      for (const [s0, s1, at] of [[0, c0, c0], [c1, a.len, c1]]) {
        if (s1 - s0 < 4) continue;
        const sub = makeShot(pa, a.i0 + s0, a.i0 + s1, -1);
        const h2 = hypsFor(sub)[0];
        if (!h2 || !strong(h2, sub) || h2.rc < 3 || h2.rs < 0.75) continue;
        const d1 = deltaOf(pa, pb, a, b, h.k), d2 = deltaOf(pa, pb, sub, SB.list[h2.b], h2.k);
        // The other part continues nearby in B (the two takes kept together, or merged there too). A look-alike
        // far away is more likely a different take of the same set-up.
        const ok = h2.b === h.b ? Math.abs(d1 - d2) >= 1 : Math.abs(h2.b - h.b) <= 2;
        if (!ok) continue;
        return [makeShot(pa, a.i0, a.i0 + at, -1), makeShot(pa, a.i0 + at, a.i1, -1)];
      }
      return null;
    };
    let hyps = [];
    for (const a of SA.list) {
      hyps.push(hypsFor(a));
      if (a.k % 8 === 7) { if (signal?.aborted) throw new DOMException('Stopped', 'AbortError'); onProgress?.(a.k / SA.list.length * 0.9); await pause(); }
    }
    for (let round = 0; round < 3; round++) {
      const list = [], hs = [];
      let split = false;
      SA.list.forEach((a, q) => {
        const parts = a.len >= 8 ? trySplit(a, hyps[q]) : null;
        if (parts) { split = true; for (const p of parts) { list.push(p); hs.push(null); } } else { list.push(a); hs.push(hyps[q]); }
      });
      if (!split) break;
      SA = indexShots(list, pa.n);
      hyps = hs.map((h, q) => h ? h.map((x) => ({ ...x, a: q })) : hypsFor(SA.list[q]));
    }
    hyps.forEach((hs, q) => hs.forEach((h) => { h.a = q; }));
    const dec = decide(pa, pb, SA, SB, hyps, opt);
    return { pa, pb, SA, SB, hyps, dec };
  }

  /* ---------- to the frame: real frames around each note ---------- */
  // The frames are 64×36 grey pictures, fetched through `get(side, first, count)` (the browser plays or seeks the
  // video; tests read them from a file). Frame numbers are the video's own (time × frame rate).
  const FW = 64, FH = 36, FPX = FW * FH;
  function zf(src, o) { const out = new Float32Array(FPX); unitZ(src, o, FPX, out, 0); return out; }
  function frameChange(a, ao, b, bo) {
    // Middle value over 12 areas of how much each changed (as for samples, at frame size).
    const out = [];
    for (let by = 0; by < 3; by++) for (let bx = 0; bx < 4; bx++) {
      let ma = 0, mb = 0, n = 0;
      for (let y = by * 12; y < by * 12 + 12; y++) for (let x = bx * 16; x < bx * 16 + 16; x++) { ma += a[ao + y * FW + x]; mb += b[bo + y * FW + x]; n++; }
      ma /= n; mb /= n;
      let sab = 0, saa = 0, sbb = 0;
      for (let y = by * 12; y < by * 12 + 12; y++) for (let x = bx * 16; x < bx * 16 + 16; x++) {
        const p = a[ao + y * FW + x] - ma, q = b[bo + y * FW + x] - mb; sab += p * q; saa += p * p; sbb += q * q;
      }
      const sa = Math.sqrt(saa / n), sb = Math.sqrt(sbb / n);
      out.push(sa < 4 || sb < 4 ? Math.min(1, Math.abs(ma - mb) / 30 + Math.abs(sa - sb) / 30) : 1 - sab / Math.sqrt(saa * sbb));
    }
    out.sort((x, y) => x - y);
    return (out[5] + out[6]) / 2;
  }
  function warpFrame(src, o, T, out) {
    for (let y = 0; y < FH; y++) for (let x = 0; x < FW; x++) {
      const sx = Math.min(FW - 1, Math.max(0, (x + 0.5 - FW / 2) / T.sc + FW / 2 + T.dx * FW - 0.5));
      const sy = Math.min(FH - 1, Math.max(0, (y + 0.5 - FH / 2) / T.sc + FH / 2 + T.dy * FW - 0.5));
      const x0 = Math.floor(sx), y0 = Math.floor(sy), x1 = Math.min(FW - 1, x0 + 1), y1 = Math.min(FH - 1, y0 + 1), fx = sx - x0, fy = sy - y0;
      out[y * FW + x] = (src[o + y0 * FW + x0] * (1 - fx) + src[o + y0 * FW + x1] * fx) * (1 - fy) + (src[o + y1 * FW + x0] * (1 - fx) + src[o + y1 * FW + x1] * fx) * fy;
    }
    return out;
  }

  // The exact first frame of a shot: the biggest change between neighbouring frames between two samples.
  async function exactCut(get, side, t0, t1, rate) {
    const f0 = Math.max(0, Math.floor(t0 * rate) - 1), f1 = Math.ceil(t1 * rate) + 1;
    const { frames, first, count } = await get(side, f0, f1 - f0 + 1);
    let best = -1, at = null;
    for (let i = 1; i < count; i++) {
      const c = frameChange(frames, (i - 1) * FPX, frames, i * FPX);
      if (c > best) { best = c; at = first + i; }
    }
    return best > 0.15 ? at : null;
  }

  async function refine(results, m, get, { rateA, rateB, onProgress, signal } = {}) {
    const { pa, pb, SA, SB, dec } = m;
    const cutCache = new Map();
    const cutAt = async (side, pp, s, which) => {
      const key = side + s.k + which;
      if (!cutCache.has(key)) {
        const rate = side === 'A' ? rateA : rateB;
        let v = null;
        if (which === 'in') v = s.i0 === 0 ? 0 : await exactCut(get, side, pp.t[s.i0 - 1], pp.t[s.i0], rate);
        if (which === 'out' && s.i1 < pp.n) v = await exactCut(get, side, pp.t[s.i1 - 1], pp.t[s.i1], rate);
        cutCache.set(key, v);
      }
      return cutCache.get(key);
    };
    // Notes in the same shot, close together, share one look at the frames: within a shot the offset doesn't change.
    const groups = [];
    for (const r of results.filter((x) => x.status === 'matched').sort((x, y) => x.tA - y.tA)) {
      const g = groups[groups.length - 1];
      if (g && g.shotA === r.shotA && g.shotB === r.shotB && g.k === r.k && r.tA - g.notes[0].tA < 4 && r.reason !== 'trimmed' && !g.trimmed) g.notes.push(r);
      else groups.push({ shotA: r.shotA, shotB: r.shotB, k: r.k, notes: [r], trimmed: r.reason === 'trimmed' });
    }
    let done = 0;
    for (const g of groups) {
      if (signal?.aborted) throw new DOMException('Stopped', 'AbortError');
      onProgress?.(done++ / groups.length);
      const p = dec.pick[g.shotA], a = SA.list[g.shotA], b = SB.list[g.shotB], T = p.h.reframe;
      const rep = g.notes[g.notes.length >> 1];
      // Frames surely inside each shot (from the samples); the exact cut frames are read only when needed.
      const aLo = Math.ceil(a.t0 * rateA), aHi = Math.floor(pa.t[a.i1 - 1] * rateA);
      const sLo = Math.ceil(b.t0 * rateB), sHi = Math.floor(pb.t[b.i1 - 1] * rateB);
      let cuts = null;
      const exact = async () => {
        if (!cuts) {
          const [aIn, bIn, aOut, bOut] = await Promise.all([cutAt('A', pa, a, 'in'), cutAt('B', pb, b, 'in'), cutAt('A', pa, a, 'out'), cutAt('B', pb, b, 'out')]);
          cuts = { aIn, bIn, aOut, bOut, lo: bIn ?? sLo, hi: bOut != null ? bOut - 1 : sHi };
        }
        return cuts;
      };
      const nA = Math.round(rep.tA * rateA);
      let nB = Math.round(rep.tB * rateB);
      let strongFrames = false;
      const apply = async (how, shift) => {
        const fs = g.notes.map((r) => Math.round(Math.round(r.tA * rateA) * rateB / rateA + shift));
        // Near (or past) the edge of the shot: clamp to the exact cut frames.
        const c = fs.some((f) => f < sLo || f > sHi) ? await exact() : { lo: sLo - 1e9, hi: sHi + 1e9 };
        g.notes.forEach((r, x) => {
          const f = fs[x];
          r.tB = Math.max(c.lo, Math.min(c.hi, f)) / rateB; r.fine = how;
          // Called trimmed by a sample's width, but the frames clearly find it well inside the shot: it's there.
          if (r.reason === 'trimmed' && how === 'frames' && strongFrames && f > sLo + 2 && f < sHi - 2) r.reason = 'visual';
        });
      };
      if (g.trimmed && (nB < sLo - 8 || nB > sHi + 8)) { await apply('edge', nB - nA * rateB / rateA); continue; }
      nB = Math.max(sLo, Math.min(sHi, nB));
      const byCut = async () => {
        const c = await exact();
        if (rep.anchored === 'tail' && c.aOut != null && c.bOut != null) return c.bOut - (c.aOut - nA) * rateB / rateA;
        if (c.aIn != null && c.bIn != null) return c.bIn + (nA - c.aIn) * rateB / rateA;
        return null;
      };
      const base0 = nB - nA * rateB / rateA;
      // Held picture: the frames can't say more than the cut does.
      if (rep.anchored) { const cut = await byCut(); if (cut != null) { await apply('cut', cut - nA * rateB / rateA); continue; } }
      // By content: 11 frames around the note against the frames around the estimate.
      const W = 5, SR = 12;
      const fa0 = Math.max(aLo, nA - W), fa1 = Math.min(aHi, nA + W);
      const b0 = Math.max(sLo, nB - SR - W), b1 = Math.min(sHi, nB + SR + W);
      if (fa1 < fa0 || b1 < b0) { const cut = await byCut(); await apply(cut != null ? 'cut' : 'coarse', cut != null ? cut - nA * rateB / rateA : base0); continue; }
      const [A, B] = await Promise.all([get('A', fa0, fa1 - fa0 + 1), get('B', b0, b1 - b0 + 1)]);
      const tmp = new Float32Array(FPX);
      const za = [], zb = [];
      for (let i = 0; i < A.count; i++) za.push(T && T.side === 'A' ? zf(warpFrame(A.frames, i * FPX, T, tmp), 0) : zf(A.frames, i * FPX));
      for (let j = 0; j < B.count; j++) zb.push(T && T.side === 'B' ? zf(warpFrame(B.frames, j * FPX, T, tmp), 0) : zf(B.frames, j * FPX));
      const motion = (z, i) => { if (i === 0) return null; const d = new Float32Array(FPX); let q = 0; for (let k = 0; k < FPX; k++) { d[k] = z[i][k] - z[i - 1][k]; q += d[k] * d[k]; } q = Math.sqrt(q); if (q < 0.04) return null; for (let k = 0; k < FPX; k++) d[k] /= q; return d; };
      const ma = za.map((_, i) => motion(za, i)), mb = zb.map((_, j) => motion(zb, j));
      const prof = [];
      for (let d = -SR; d <= SR; d++) {
        let s = 0, c = 0, mm = 0, mc = 0;
        for (let i = 0; i < A.count; i++) {
          const j = A.first + i - nA + nB + d - B.first;
          if (j < 0 || j >= B.count) continue;
          s += dotv(za[i], 0, zb[j], 0, FPX); c++;
          if (ma[i] && mb[j]) { mm += dotv(ma[i], 0, mb[j], 0, FPX); mc++; }
        }
        if (c >= Math.min(5, A.count)) prof.push({ d, s: s / c + 0.3 * (mc / (mc + 2)) * (mc ? mm / mc : 0), ms: s / c, mc });
      }
      if (!prof.length) { const cut = await byCut(); await apply(cut != null ? 'cut' : 'coarse', cut != null ? cut - nA * rateB / rateA : base0); continue; }
      const best = prof.reduce((x, y) => (y.s > x.s ? y : x));
      const base = prof.map((x) => x.s).sort((x, y) => x - y)[prof.length >> 1];
      const sharp = best.s - base;
      const plateau = prof.filter((x) => x.s >= best.s - Math.max(0.002, 0.08 * sharp));
      const clear = best.ms >= 0.6 && sharp > 0.01 && plateau.length <= 3;
      strongFrames = best.ms >= 0.9 && plateau.length <= 2;
      if (clear && Math.abs(best.d) < SR - 1) { await apply('frames', base0 + best.d); continue; }
      if (g.trimmed) { await apply('edge', base0); continue; }
      // Not conclusive (or at the edge of the search): see what the cut says.
      const cut = await byCut();
      const cutD = cut != null ? Math.round(cut) - nB : null;
      const atEdge = Math.abs(best.d) >= SR - 1 && cutD != null && Math.abs(cutD - best.d) > 2;
      if (clear && !atEdge) await apply('frames', base0 + best.d);
      else if (cutD != null && Math.abs(cutD) <= SR + 6 && (best.ms < 0.6 || atEdge || plateau.some((x) => x.d === cutD) || plateau.length > 3)) await apply('cut', cut - nA * rateB / rateA);
      else if (best.ms >= 0.6) await apply('frames~', base0 + plateau[plateau.length >> 1].d);
      else await apply('coarse', base0);
    }
    onProgress?.(1);
    return results;
  }

  /* ---------- fingerprint files ---------- */
  const FV = 2;
  function emptyPrint(n) { return { v: FV, sample: SAMPLE, n, dur: 0, t: new Float32Array(n), g: new Uint8Array(n * P), box: null }; }
  function pack(fp) {
    const enc = new TextEncoder();
    const head = enc.encode(JSON.stringify({ v: FV, n: fp.n, dur: fp.dur, sample: fp.sample, gw: GW, gh: GH, box: fp.box || null, vw: fp.vw || 0, vh: fp.vh || 0 }));
    const parts = [enc.encode('PNFP'), new Uint8Array(new Uint32Array([head.length]).buffer), head,
      new Uint8Array(fp.t.buffer, fp.t.byteOffset, fp.n * 4), fp.g.subarray(0, fp.n * P)];
    const out = new Uint8Array(parts.reduce((s, p) => s + p.length, 0));
    let o = 0;
    for (const p of parts) { out.set(p, o); o += p.length; }
    return out;
  }
  // Packed and gzipped, for the upload (the server unzips it to check it).
  async function packForUpload(fp) {
    const raw = pack(fp);
    if (typeof CompressionStream === 'undefined') return raw;
    const stream = new Blob([raw]).stream().pipeThrough(new CompressionStream('gzip'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
  }
  function unpack(buf) {
    const u = new Uint8Array(buf);
    if (String.fromCharCode(...u.subarray(0, 4)) !== 'PNFP') throw new Error('Not a fingerprint file.');
    const hl = new DataView(u.buffer, u.byteOffset + 4, 4).getUint32(0, true);
    const head = JSON.parse(new TextDecoder().decode(u.subarray(8, 8 + hl)));
    // Made by an earlier version of the matcher: the video has to be read again.
    if (head.v !== FV) return { v: head.v, stale: true, n: head.n, dur: head.dur };
    const n = head.n;
    let o = 8 + hl;
    const take = (len) => { const s = u.slice(o, o + len); o += len; return s; };
    return { v: FV, sample: head.sample, n, dur: head.dur, box: head.box || null, vw: head.vw || 0, vh: head.vh || 0, t: new Float32Array(take(n * 4).buffer), g: take(n * P) };
  }

  /* ---------- reading pictures in the browser ---------- */
  function openVideo(src) {
    return new Promise((resolve, reject) => {
      const v = document.createElement('video');
      v.muted = true; v.playsInline = true; v.preload = 'auto';
      if (/^https?:/i.test(src) && new URL(src, location.href).origin !== location.origin) v.crossOrigin = 'anonymous';
      const done = () => { cleanup(); resolve(v); };
      const fail = () => { cleanup(); reject(new Error('This video couldn’t be opened in the browser.')); };
      const cleanup = () => { v.removeEventListener('loadeddata', done); v.removeEventListener('error', fail); };
      v.addEventListener('loadeddata', done);
      v.addEventListener('error', fail);
      v.src = src;
    });
  }
  function seek(v, t) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => { v.removeEventListener('seeked', ok); reject(new Error('The video stopped responding while reading frames.')); }, 15000);
      const ok = () => { clearTimeout(timer); v.removeEventListener('seeked', ok); resolve(); };
      v.addEventListener('seeked', ok);
      v.currentTime = t;
    });
  }
  // Draws the picture area at 128×72 with high-quality smoothing (so fine detail and film grain average out instead
  // of flickering), then averages it down: 64×36 for frame-level work, 32×18 for the fingerprint.
  function grabber(box) {
    const c = document.createElement('canvas');
    c.width = 128; c.height = 72;
    const ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    const l128 = new Float32Array(128 * 72);
    return (v) => {
      const b = box || { x: 0, y: 0, w: v.videoWidth, h: v.videoHeight };
      ctx.drawImage(v, b.x, b.y, b.w, b.h, 0, 0, 128, 72);
      let d;
      try { d = ctx.getImageData(0, 0, 128, 72).data; } catch (e) {
        throw new Error('This video’s pictures can’t be read in the browser (the server doesn’t allow it). Download the version and choose the file instead.');
      }
      for (let i = 0, p = 0; i < l128.length; i++, p += 4) l128[i] = 0.299 * d[p] + 0.587 * d[p + 1] + 0.114 * d[p + 2];
      const f64 = new Float32Array(FPX), g32 = new Float32Array(P);
      for (let y = 0; y < FH; y++) for (let x = 0; x < FW; x++) { const i = 2 * y * 128 + 2 * x; f64[y * FW + x] = (l128[i] + l128[i + 1] + l128[i + 128] + l128[i + 129]) / 4; }
      for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) { const i = 2 * y * FW + 2 * x; g32[y * GW + x] = (f64[i] + f64[i + 1] + f64[i + FW] + f64[i + FW + 1]) / 4; }
      return { f64, g32 };
    };
  }
  // Black bars (letterbox or pillarbox) that are there all through the video: left out, so a version with bars
  // and one without still match.
  async function detectBox(v) {
    const W = 192, H = 108;
    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const ctx = c.getContext('2d', { willReadFrequently: true });
    const rowMax = new Float32Array(H), colMax = new Float32Array(W);
    let seen = 0;
    for (let q = 1; q <= 12; q++) {
      const t = (v.duration * q) / 13;
      try { await seek(v, t); } catch (e) { continue; }
      ctx.drawImage(v, 0, 0, W, H);
      let d;
      try { d = ctx.getImageData(0, 0, W, H).data; } catch (e) { return null; }
      let bright = 0;
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
        const p = (y * W + x) * 4, l = 0.299 * d[p] + 0.587 * d[p + 1] + 0.114 * d[p + 2];
        if (l > rowMax[y]) rowMax[y] = l;
        if (l > colMax[x]) colMax[x] = l;
        if (l > 40) bright++;
      }
      if (bright > W * H * 0.05) seen++;
    }
    if (seen < 4) return null;   // mostly dark video: can't tell bars from picture
    const edge = (arr, from, step, lim) => { let k = 0; while (k < lim && arr[from + k * step] < 22) k++; return k; };
    const top = edge(rowMax, 0, 1, H * 0.2), bottom = edge(rowMax, H - 1, -1, H * 0.2);
    const left = edge(colMax, 0, 1, W * 0.2), right = edge(colMax, W - 1, -1, W * 0.2);
    if (Math.max(top, bottom) < H * 0.02 && Math.max(left, right) < W * 0.02) return null;
    const sx = v.videoWidth / W, sy = v.videoHeight / H;
    return { x: Math.round(left * sx), y: Math.round(top * sy), w: Math.round((W - left - right) * sx), h: Math.round((H - top - bottom) * sy), W: v.videoWidth, H: v.videoHeight };
  }

  // Bars in one cut and not the other: either the picture was squeezed in between them, or cropped by a matte.
  // Fingerprints are of each video's own picture area; for the matte reading the other cut is cropped to match.
  // Both readings are tried, and the one whose pictures agree better is used.
  const FULL = { x: 0, y: 0, w: 1, h: 1 };
  const relBox = (fp) => (fp.box && fp.box.W ? { x: fp.box.x / fp.box.W, y: fp.box.y / fp.box.H, w: fp.box.w / fp.box.W, h: fp.box.h / fp.box.H } : FULL);
  function cropPrint(fp, r) {
    const g = new Uint8Array(fp.n * P);
    for (let i = 0; i < fp.n; i++) for (let y = 0; y < GH; y++) for (let x = 0; x < GW; x++) {
      const sx = Math.min(GW - 1, Math.max(0, (r.x + (x + 0.5) / GW * r.w) * GW - 0.5)), sy = Math.min(GH - 1, Math.max(0, (r.y + (y + 0.5) / GH * r.h) * GH - 0.5));
      const x0 = Math.floor(sx), y0 = Math.floor(sy), x1 = Math.min(GW - 1, x0 + 1), y1 = Math.min(GH - 1, y0 + 1), fx = sx - x0, fy = sy - y0, o = i * P;
      g[o + y * GW + x] = Math.round((fp.g[o + y0 * GW + x0] * (1 - fx) + fp.g[o + y0 * GW + x1] * fx) * (1 - fy) + (fp.g[o + y1 * GW + x0] * (1 - fx) + fp.g[o + y1 * GW + x1] * fx) * fy);
    }
    return { ...fp, g, _p: undefined, cache: undefined };
  }
  function harmonize(A, B) {
    const ra = relBox(A), rb = relBox(B);
    const same = (p, q) => Math.abs(p.x - q.x) + Math.abs(p.y - q.y) + Math.abs(p.w - q.w) + Math.abs(p.h - q.h) < 0.04;
    // An area (as a fraction of the whole frame) in each video's pixels, and relative to its own picture area.
    const vpx = (fp, r) => ({ x: Math.round(r.x * fp.vw), y: Math.round(r.y * fp.vh), w: Math.round(r.w * fp.vw), h: Math.round(r.h * fp.vh) });
    const within = (r, own) => ({ x: (r.x - own.x) / own.w, y: (r.y - own.y) / own.h, w: r.w / own.w, h: r.h / own.h });
    const squeeze = { A, B, boxA: A.box, boxB: B.box };
    if (same(ra, rb) || !A.vw || !B.vw) return squeeze;
    const x0 = Math.max(ra.x, rb.x), y0 = Math.max(ra.y, rb.y), x1 = Math.min(ra.x + ra.w, rb.x + rb.w), y1 = Math.min(ra.y + ra.h, rb.y + rb.h);
    if (x1 - x0 < 0.2 || y1 - y0 < 0.2) return squeeze;
    const I = { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
    const matte = { A: same(I, ra) ? A : cropPrint(A, within(I, ra)), B: same(I, rb) ? B : cropPrint(B, within(I, rb)), boxA: vpx(A, I), boxB: vpx(B, I) };
    let pick = squeeze, best = -2;
    for (const mdl of [squeeze, matte]) {
      const pa = prep(mdl.A), pb = prep(mdl.B);
      let sum = 0, cnt = 0;
      for (let q = 0; q < 40; q++) {
        const i = Math.floor(((q + 0.5) * pa.n) / 40);
        if (pa.flat[i]) continue;
        let m = -1;
        for (let j = 0; j < pb.n; j += 2) if (!pb.flat[j]) m = Math.max(m, dotv(pa.r, i * RP, pb.r, j * RP, RP));
        sum += m; cnt++;
      }
      const score = cnt ? sum / cnt : -1;
      if (score > best) { best = score; pick = mdl; }
    }
    return pick;
  }

  // Reads the whole video once: plays it muted, fast, and keeps for each quarter-second slot the frame shown
  // closest to it. The speed adapts so that's within about a frame (around 6× on a 60 Hz screen); slots still
  // off (a hidden tab, a decoder that can't keep up) are read by seeking.
  async function fingerprint(src, { onProgress, signal, host } = {}) {
    const v = await openVideo(src);
    const dur = v.duration;
    if (!isFinite(dur) || dur <= 0) throw new Error('This video has no length the browser can read.');
    const n = Math.floor(dur / SAMPLE);
    const fp = emptyPrint(n);
    fp.dur = dur;
    v.className = 'pn-match-video';
    (host || document.body).append(v);
    try {
      fp.box = await detectBox(v);
      fp.vw = v.videoWidth; fp.vh = v.videoHeight;
      const grab = grabber(fp.box);
      const err = new Float32Array(n).fill(9);
      const slot = (i) => i * SAMPLE + 0.02;
      let good = 0;
      const store = (i, t) => {
        const { g32 } = grab(v);
        fp.t[i] = t;
        for (let k = 0; k < P; k++) fp.g[i * P + k] = Math.round(g32[k]);
        if (err[i] > 0.05 && Math.abs(t - slot(i)) <= 0.05) good++;
        err[i] = Math.abs(t - slot(i));
      };
      if ('requestVideoFrameCallback' in v) {
        await seek(v, 0);
        let rate = 6;
        v.playbackRate = rate;
        await new Promise((resolve) => {
          let lastMoved = performance.now(), lastT = -1, calls = 0, t0 = 0;
          const dog = setInterval(() => { if (performance.now() - lastMoved > 2000 || signal?.aborted) finish(); }, 500);
          const finish = () => { clearInterval(dog); v.pause(); resolve(); };
          const cb = (now, meta) => {
            const t = meta.mediaTime;
            if (t > lastT) { lastT = t; lastMoved = performance.now(); }
            const i = Math.round((t - 0.02) / SAMPLE);
            if (i >= 0 && i < n && Math.abs(t - slot(i)) < err[i]) store(i, t);
            // Keep the step between shown frames near 0.1 s: close enough to every slot, as fast as allowed.
            if (++calls % 30 === 0) {
              const step = (t - t0) / 30; t0 = t;
              if (step > 0.11 && rate > 1) { rate = Math.max(1, rate * 0.75); v.playbackRate = rate; }
              else if (step > 0 && step < 0.07 && rate < 16) { rate = Math.min(16, rate * 1.25); v.playbackRate = rate; }
            }
            onProgress?.(Math.min(0.97, t / dur));
            if (v.ended || t >= dur - 0.05) finish(); else v.requestVideoFrameCallback(cb);
          };
          v.addEventListener('ended', finish, { once: true });
          v.requestVideoFrameCallback(cb);
          v.play().catch(finish);
        });
      }
      if (signal?.aborted) throw new DOMException('Stopped', 'AbortError');
      const left = [];
      for (let i = 0; i < n; i++) if (err[i] > 0.05) left.push(i);
      let k = 0;
      for (const i of left) {
        if (signal?.aborted) throw new DOMException('Stopped', 'AbortError');
        await seek(v, slot(i));
        store(i, slot(i));
        if (++k % 20 === 0) onProgress?.(0.97 + 0.03 * (k / left.length));
      }
      onProgress?.(1);
    } finally {
      v.pause(); v.remove(); v.removeAttribute('src'); v.load();
    }
    return fp;
  }

  // Exact frames (64×36 grey) from a video: plays the stretch at 2× and catches every frame; any it misses are
  // fetched by seeking. Frame f is the picture shown from f / rate seconds.
  function frameReader(v, rate, box, signal) {
    const grab = grabber(box);
    const last = Math.floor(v.duration * rate) - 1;
    // One read at a time per video (the reads of the two videos run side by side).
    let queue = Promise.resolve();
    const read = (first, count) => { const p = queue.then(() => readNow(first, count)); queue = p.catch(() => {}); return p; };
    return read;
    async function readNow(first, count) {
      first = Math.max(0, first);
      count = Math.max(0, Math.min(count, last - first + 1));
      const frames = new Float32Array(count * FPX), got = new Uint8Array(count);
      let have = 0;
      const put = (i) => { if (i >= 0 && i < count && !got[i]) { frames.set(grab(v).f64, i * FPX); got[i] = 1; have++; } };
      if (count > 4 && 'requestVideoFrameCallback' in v) {
        await seek(v, Math.max(0, (first + 0.5) / rate));
        put(Math.round(v.currentTime * rate - 0.5) - first);
        v.playbackRate = 3;
        await new Promise((resolve) => {
          let moved = performance.now();
          const dog = setInterval(() => { if (performance.now() - moved > 1500 || signal?.aborted) finish(); }, 250);
          const finish = () => { clearInterval(dog); v.pause(); resolve(); };
          const cb = (now, meta) => {
            moved = performance.now();
            const i = Math.round(meta.mediaTime * rate) - first;
            put(i);
            if (i >= count - 1 || have >= count || v.ended) finish(); else v.requestVideoFrameCallback(cb);
          };
          v.requestVideoFrameCallback(cb);
          v.play().catch(finish);
        });
      }
      for (let i = 0; i < count; i++) {
        if (got[i]) continue;
        if (signal?.aborted) throw new DOMException('Stopped', 'AbortError');
        await seek(v, (first + i + 0.5) / rate);
        put(i);
      }
      return { first, count, frames };
    }
  }

  /* ---------- the AI pass, for shots nothing else could place ---------- */
  let modelPromise = null;
  function loadModel(onStatus) {
    modelPromise ??= (async () => {
      onStatus?.('Loading the AI image model (about 90 MB the first time; your browser keeps it afterwards)…');
      const T = await import(MODEL_LIB);
      T.env.allowLocalModels = false;
      const processor = await T.AutoProcessor.from_pretrained(MODEL_ID);
      const model = await T.CLIPVisionModelWithProjection.from_pretrained(MODEL_ID, { dtype: 'q8', device: 'wasm' });
      const canvas = document.createElement('canvas');
      canvas.width = 224; canvas.height = 224;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      return {
        async embed(v) {
          const s = Math.min(v.videoWidth, v.videoHeight);
          ctx.drawImage(v, (v.videoWidth - s) / 2, (v.videoHeight - s) / 2, s, s, 0, 0, 224, 224);
          const inputs = await processor(T.RawImage.fromCanvas(canvas));
          const { image_embeds } = await model(inputs);
          const e = Float32Array.from(image_embeds.data);
          let q = 0; for (const x of e) q += x * x; q = Math.sqrt(q) || 1;
          for (let i = 0; i < e.length; i++) e[i] /= q;
          return e;
        },
      };
    })().catch((e) => { modelPromise = null; throw e; });
    return modelPromise;
  }
  // Shots of A that hold notes and weren't placed: compare them with the unclaimed shots of B, preferring the
  // stretch between where their neighbours went.
  async function aiPass(m, vA, vB, shotsWithNotes, { onStatus, onProgress, signal }) {
    const { SA, SB, dec } = m;
    const todo = shotsWithNotes.filter((q) => !dec.pick[q]);
    if (!todo.length) return { note: '', used: false };
    let emb;
    try { emb = await loadModel(onStatus); } catch (e) {
      console.warn('[picture match] AI model unavailable:', e);
      return { note: 'The AI model couldn’t be downloaded, so shots that changed beyond recognition were left to check.', used: false };
    }
    const taken = new Set(dec.pick.filter(Boolean).map((p) => p.h.b));
    const free = SB.list.filter((b) => !taken.has(b.k));
    onStatus?.(`Comparing ${todo.length} changed shot${todo.length === 1 ? '' : 's'} with ${free.length} unclaimed shot${free.length === 1 ? '' : 's'} of the new cut…`);
    const bVec = new Map();
    const vecB = async (b) => {
      if (!bVec.has(b.k)) { await seek(vB, (b.t0 + b.t1) / 2); bVec.set(b.k, await emb.embed(vB)); }
      return bVec.get(b.k);
    };
    let done = 0;
    for (const q of todo) {
      if (signal?.aborted) throw new DOMException('Stopped', 'AbortError');
      const a = SA.list[q];
      await seek(vA, (a.t0 + a.t1) / 2);
      const qa = await emb.embed(vA);
      // Between the neighbours first; anywhere unclaimed if nothing fits there.
      const prev = dec.pick.slice(0, q).reverse().find(Boolean), next = dec.pick.slice(q + 1).find(Boolean);
      const lo = prev ? prev.h.b : -1, hi = next ? next.h.b : SB.list.length;
      const near = free.filter((b) => b.k > lo && b.k < hi);
      let best = null, second = null;
      for (const pool of [near, free]) {
        if (!pool.length) continue;
        const scored = [];
        for (const b of pool) { const e = await vecB(b); let s = 0; for (let i = 0; i < e.length; i++) s += e[i] * qa[i]; scored.push({ b, s }); }
        scored.sort((x, y) => y.s - x.s);
        [best, second] = scored;
        if (best.s >= 0.86 && best.s - (second ? second.s : 0) >= 0.02) break;
        best = null;
      }
      if (best) {
        const h = { a: q, b: best.b.k, k: 0, score: best.s, ms: 0, cnt: 0 };
        h.delta = deltaOf(m.pa, m.pb, a, best.b, 0);
        dec.pick[q] = { h, how: 'ai', sure: best.s >= 0.93 && best.s - (second ? second.s : 0) >= 0.05 };
        free.splice(free.indexOf(best.b), 1);
      }
      onProgress?.(++done / todo.length);
    }
    return { note: '', used: true };
  }

  /* ---------- the whole job ---------- */
  // notes: [{ id, frameA }]; rates: frames per second of each version.
  async function run({ A, B, srcA, srcB, notes, rateA, rateB, useModel, onStatus, onProgress, signal, host }) {
    onStatus?.('Comparing the shots of the two cuts…');
    const fit = harmonize(A, B);
    A = fit.A; B = fit.B;
    const m = await matchShots(A, B, { onProgress, signal });
    const list = notes.map((n) => ({ id: n.id, t: n.frameA / rateA }));
    let note = '', kind = null;
    let vA = null, vB = null;
    try {
      if (srcA && srcB) {
        [vA, vB] = await Promise.all([openVideo(srcA), openVideo(srcB)]);
        for (const v of [vA, vB]) { v.className = 'pn-match-video'; (host || document.body).append(v); }
      }
      if (vA && useModel) {
        const shotsWithNotes = [...new Set(list.map((n) => m.SA.of[Math.max(0, Math.min(m.pa.n - 1, Math.round((n.t - 0.02) / SAMPLE)))]))];
        const ai = await aiPass(m, vA, vB, shotsWithNotes, { onStatus, onProgress, signal });
        note = ai.note; kind = ai.used ? 'ai' : null;
      }
      const placed = placeNotes(m.pa, m.pb, m.SA, m.SB, m.dec, list);
      if (vA) {
        onStatus?.('Lining up each note to the frame…');
        const rA = frameReader(vA, rateA, fit.boxA, signal), rB = frameReader(vB, rateB, fit.boxB, signal);
        const get = (side, first, count) => (side === 'A' ? rA : rB)(first, count);
        await refine(placed, m, get, { rateA, rateB, onProgress, signal });
      }
      return {
        results: placed.map((r) => ({ note_id: r.id, frame: Math.max(0, Math.round(r.tB * rateB)), reason: r.reason, confident: !!r.confident })),
        note, kind, shots: { a: m.SA.list.length, b: m.SB.list.length },
      };
    } finally {
      for (const v of [vA, vB]) if (v) { v.pause(); v.remove(); v.removeAttribute('src'); v.load(); }
    }
  }

  const api = { SAMPLE, FV, prep, shots, matchShots, placeNotes, refine, pack, packForUpload, unpack, emptyPrint, fingerprint, run };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.PNMatch = api;
})(typeof window !== 'undefined' ? window : globalThis);

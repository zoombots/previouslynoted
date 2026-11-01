
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

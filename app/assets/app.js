/* Previously Noted — player synced to review notes. No build step, no dependencies. */
(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const CSRF = document.body.dataset.csrf;
  // What this person may do. The server checks every action too; this only hides controls they can't use.
  const RANK = { viewer: 1, reviewer: 2, editor: 3, admin: 4 };
  let ROLE = document.body.dataset.role || 'viewer';
  const can = (r) => (RANK[ROLE] || 0) >= RANK[r];
  // The production this page is on (every request says which), and what this person is in its company.
  const WS = +document.body.dataset.ws || 0;
  const COMPANY_ROLE = document.body.dataset.companyRole || '';
  const PLATFORM = document.body.dataset.platform || '';
  const companyAdmin = () => COMPANY_ROLE === 'owner' || COMPANY_ROLE === 'admin';
  const wsq = (q) => `api.php?ws=${WS}&${q}`;

  const STATUS = {
    open:       { label: 'Open',          verb: 'Reopen',          done: false },
    addressed:  { label: 'Addressed',     verb: 'Addressed',       done: false },
    discuss:    { label: 'To discuss',    verb: 'Discuss',         done: false },
    approved:   { label: 'Approved',      verb: 'Approve',         done: true },
    wont_do:    { label: 'Won’t do',      verb: 'Won’t do',        done: true },
    removed:    { label: 'Cut from film', verb: 'Cut from film',   done: true },
    superseded: { label: 'Superseded',    verb: 'Superseded',      done: true },
  };
  const STATUS_ORDER = ['addressed', 'approved', 'discuss', 'wont_do', 'removed', 'superseded', 'open'];
  // The note card shows the three everyday decisions; the rest sit under More.
  const STATUS_MAIN = ['addressed', 'discuss', 'wont_do'];
  const STATUS_MORE = ['approved', 'removed', 'superseded', 'open'];
  const STATUS_KEYS = { addressed: 'A', discuss: 'D', wont_do: 'W' };
  // Tracks (note categories) come from the film; these are the colours they can use.
  const TRACK_COLORS = [
    ['tag-e', 'Amber'], ['tag-n', 'Blue'], ['tag-a', 'Lilac'], ['cat-green', 'Green'], ['cat-red', 'Red'],
    ['cat-teal', 'Teal'], ['cat-pink', 'Pink'], ['cat-olive', 'Olive'], ['cat-sand', 'Sand'], ['cat-gray', 'Grey'],
  ];
  const UNTAGGED = { key: '', label: 'Untagged', color: 'tag-none', kind: 'picture' };
  // Revision colours, as script pages are coloured by draft. Each version has one; new cuts take the next.
  const REV = ['white', 'blue', 'pink', 'yellow', 'green', 'goldenrod', 'buff', 'salmon', 'cherry', 'tan'];
  const revName = (c) => (c ? c[0].toUpperCase() + c.slice(1) : '');
  const verOf = (id) => S.versions.find((v) => v.id === id) || null;
  const revVar = (c) => `var(--rev-${REV.includes(c) ? c : 'white'})`;
  // A small swatch of a version's colour, with its name for anyone who can't tell the colours apart.
  const swatch = (v, cls = 'vdot') => (v ? el('span', { class: cls, style: `--v:${revVar(v.color)}`, title: `${v.label} · ${revName(v.color)} pages` }) : null);

  const S = {
    films: [], versions: [], user: '',
    filmId: null, versionId: null, version: null,
    notes: [], visible: [],
    frame: 0, activeKey: '',
    filter: { q: '', tags: new Set(), status: '', author: '' },
    sources: new Map(), // versionId -> { url, name, local }
    userScrolledAt: 0,
    shots: [], edit: null,
    script: { uploads: [], segments: [], gone: [], compare: null, activeId: null, latestId: null, loadedFor: null },
    scriptScrolledAt: 0,
    tab: 'notes',
    conn: { frameio: false, elevenlabs: false },
    cats: [], hidden: new Set(),
    scriptWide: false, scriptEdits: { transcriptId: null, lines: new Map() }, editingLine: null,
    jobTimer: null,
  };
  // J/K/L shuttle state (see the Shuttle section).
  const SHUTTLE = [1, 2, 4, 8];
  const shuttle = { rate: 0, rev: null, kHeld: false, base: 1 };
  const REASON = {
    picture: { short: '', long: 'Placed automatically: same shot, same frame.' },
    'narration-clip': { short: '', long: 'Placed automatically by its VO clip.' },
    transcript: { short: '', long: 'Placed automatically by its narration line.' },
    removed: { short: 'shot removed', long: 'The shot this note was on isn’t in this cut any more, so it may already be addressed. It sits just after where the shot used to be.', done: true },
    trimmed: { short: 'trimmed', long: 'The frame this note was on was trimmed out of the shot. It sits on the nearest frame that’s still in the cut.', done: true },
    ambiguous: { short: 'shot used twice', long: 'This moment appears more than once in this cut. The note sits at the likeliest one; check it’s right.' },
    'line-reworded': { short: 'line reworded', long: 'The narration line was reworded in this cut. Check whether the note still applies.' },
    'line-removed': { short: 'line gone', long: 'This narration line isn’t in this cut’s transcript, so the note may already be addressed.', done: true },
    'beyond-edit': { short: 'past the edit', long: 'This note is past the end of the previous version’s edit file, so it couldn’t be placed from it.' },
    visual: { short: '', long: 'Placed automatically by matching the picture.' },
    'visual-ai': { short: 'found by look', long: 'Found by picture similarity: the shot looks different in this cut (regraded, reframed or replaced). Check it’s the right moment.' },
    likely: { short: 'likely', long: 'Placed where the picture and the shots around it point, but another shot looks much the same (another bite of the same interview, say). Check it’s the right moment.' },
    changed: { short: 'changed shot', long: 'This shot’s picture changed in this cut (new VFX, reframed, regraded or replaced). The note sits in the same place in the shot, between the same neighbours; check it.' },
    'visual-disagrees': { short: 'edit and picture disagree', long: 'The edit file and the picture match put this note in different places. It’s where the edit file says; check it.' },
  };

  /* ---------- helpers ---------- */
  // Scroll a list so an item is visible, without ever scrolling the page (which would pull the video away on a phone).
  function revealIn(box, item, where) {
    if (!box || !item || box.scrollHeight <= box.clientHeight) return;
    const b = box.getBoundingClientRect(), r = item.getBoundingClientRect();
    let top = box.scrollTop + (r.top - b.top);
    if (where === 'center') top -= (box.clientHeight - r.height) / 2;
    else if (r.top >= b.top && r.bottom <= b.bottom) return;
    else if (r.bottom > b.bottom) top -= box.clientHeight - r.height;
    box.scrollTo({ top: Math.max(0, top), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }
  // Drawn icons (one stroke family, matching the transport buttons in index.php).
  const ICONS = { plus: 'M12 6v12M6 12h12', up: 'M12 18V6M6.5 11.5 12 6l5.5 5.5', down: 'M12 6v12M6.5 12.5 12 18l5.5-5.5' };
  function svgIcon(name) {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('class', 'i'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
    const path = document.createElementNS(NS, 'path'); path.setAttribute('d', ICONS[name]); svg.append(path);
    return svg;
  }
  function el(tag, attrs = {}, ...kids) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v == null || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'style') n.style.cssText = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else if (k === 'text') n.textContent = v;
      else n.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) {
      if (kid == null || kid === false) continue;
      n.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    }
    return n;
  }

  async function api(action, { body, form, query } = {}) {
    const qs = new URLSearchParams({ action, ...(WS ? { ws: WS } : {}), ...(query || {}) });
    const opt = { headers: { 'X-CSRF-Token': CSRF }, credentials: 'same-origin' };
    if (form) { opt.method = 'POST'; opt.body = form; }
    else if (body) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    const res = await fetch('api.php?' + qs, opt);
    let data = {};
    try { data = await res.json(); } catch { /* non-JSON */ }
    if (res.status === 401) { location.reload(); throw new Error('Signed out'); }
    if (!res.ok || data.error) throw new Error(data.error || `The server answered ${res.status}.`);
    return data;
  }

  let toastTimer;
  function toast(msg, action) {
    const t = $('toast');
    t.replaceChildren(el('span', { text: msg }));
    if (action) t.append(el('button', { type: 'button', text: action.label, onclick: () => { t.classList.remove('show'); action.run(); } }));
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), action ? 9000 : 3200);
  }
  const fail = (e) => toast(e.message || String(e));

  const tcBase = () => (S.version ? +S.version.tc_base : 24);
  const rate = () => (S.version ? +S.version.rate : 24);
  // The timecode of the video's first frame: positions count from the start of the video, timecodes shown add this.
  const tcStart = () => (S.version ? +S.version.tc_start || 0 : 0);
  function tc(f) {
    const b = tcBase(); f = Math.max(0, Math.round(f)) + tcStart();
    const s = Math.floor(f / b), ff = f % b;
    const p = (n) => String(n).padStart(2, '0');
    return `${p(Math.floor(s / 3600))}:${p(Math.floor(s / 60) % 60)}:${p(s % 60)}:${p(ff)}`;
  }
  const firstKey = (tag) => (tag || '').split('-')[0];
  const catOf = (key) => S.cats.find((c) => c.key === key) || null;
  function tagColor(tag) {
    const c = catOf(firstKey(tag));
    return c ? `var(--${c.color})` : 'var(--tag-none)';
  }
  const trackName = (key) => (key ? catOf(key)?.label || key : 'Untagged');
  function statusColor(st) {
    return { open: 'var(--st-open)', addressed: 'var(--st-addressed)', approved: 'var(--st-approved)', discuss: 'var(--st-discuss)' }[st] || 'var(--st-closed)';
  }
  const isDone = (n) => STATUS[n.status]?.done;
  const fmtDate = (s) => {
    if (!s) return '';
    const d = new Date(s);
    return isNaN(d) ? s : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  };
  const ls = {
    get(k) { try { return localStorage.getItem('prevnoted:' + k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem('prevnoted:' + k, v); } catch { /* storage off */ } },
  };

  /* ---------- state loading ---------- */
  async function loadState(prefer = {}) {
    const d = await api('state');
    S.ws = d.workspace;
    S.films = d.films; S.versions = d.versions; S.user = d.user; S.conn = d.connections || S.conn;
    const hash = new URLSearchParams(location.hash.slice(1));
    const num = (x) => (x ? +x : null);
    let film = prefer.filmId ?? num(hash.get('film')) ?? num(ls.get('film'));
    if (!S.films.some((f) => f.id === film)) film = S.films[0]?.id ?? null;
    S.filmId = film;
    const vs = filmVersions();
    let ver = prefer.versionId ?? num(hash.get('v')) ?? num(ls.get('version'));
    if (!vs.some((v) => v.id === ver)) ver = vs[vs.length - 1]?.id ?? null;
    renderPickers();
    await selectVersion(ver);
    if (!S.films.length && can('editor')) openManage();
  }
  const filmVersions = () => S.versions.filter((v) => v.film_id === S.filmId);
  function loadTracks() {
    const film = S.films.find((f) => f.id === S.filmId);
    S.cats = film?.categories || [];
    let h = [];
    try { h = JSON.parse(ls.get('hidden:' + S.filmId) || '[]'); } catch { h = []; }
    S.hidden = new Set(h);
  }
  function saveHidden() { ls.set('hidden:' + S.filmId, JSON.stringify([...S.hidden])); }

  function renderPickers() {
    const fs = $('filmSelect');
    fs.replaceChildren(...(S.films.length ? S.films.map((f) => el('option', { value: f.id, text: f.name, selected: f.id === S.filmId })) : [el('option', { text: 'No films yet' })]));
    fs.disabled = !S.films.length;
    const vs = $('versionSelect');
    const list = filmVersions();
    vs.replaceChildren(...(list.length ? list.map((v) => el('option', { value: v.id, text: `${v.label} · ${revName(v.color)} · ${v.note_count} notes`, selected: v.id === S.versionId })) : [el('option', { text: 'No versions yet' })]));
    vs.disabled = !list.length;
    // The version's colour runs along the top of the window and beside the version picker.
    const cur = verOf(S.versionId);
    document.body.style.setProperty('--ver', cur ? revVar(cur.color) : 'transparent');
    document.body.dataset.rev = cur?.color || '';
    $('verSwatch').title = cur ? `${revName(cur.color)} pages` : '';
  }

  async function selectVersion(id) {
    loadTracks();
    S.versionId = id;
    S.version = S.versions.find((v) => v.id === id) || null;
    if (id) { ls.set('version', id); ls.set('film', S.filmId); history.replaceState(null, '', `#film=${S.filmId}&v=${id}`); }
    renderPickers();
    TL.span = 0;
    S.script = { uploads: [], segments: [], gone: [], compare: null, activeId: null, latestId: null, loadedFor: null };
    if (!id) { S.notes = []; S.shots = []; applyFilters(); setSource(null); renderScript(); return; }
    await loadNotes();
    loadScript().catch(fail);
    updateLinkUI();
    const linked = S.conn.frameio && S.version.fio_file_id;
    const src = S.sources.get(id) || (!linked && S.version.video_url ? { url: S.version.video_url, name: 'Video link' } : null);
    setSource(src);
    if (!src && linked) streamFrameio().catch(fail);
    if (linked && (!S.version.fio_synced_at || Date.now() - new Date(S.version.fio_synced_at) > 5 * 60 * 1000)) syncComments(true).catch(() => {});
    checkJob(id);
  }

  /* ---------- Frame.io ---------- */
  function updateLinkUI() {
    const linked = !!(S.conn.frameio && S.version?.fio_file_id);
    $('syncBtn').hidden = !linked || !can('editor');
    $('streamBtn').hidden = !linked;
    $('qualityRow').hidden = !(linked && video.dataset.remote === '1');
    $('transcribeBtn').hidden = !can('editor') || !(S.conn.elevenlabs && S.version && (S.version.fio_file_id || S.version.video_url));
    const note = $('syncNote');
    note.hidden = $('syncBtn').hidden;
    note.textContent = S.version?.fio_synced_at ? `Last synced ${fmtDate(S.version.fio_synced_at)}` : 'Not synced yet';
  }

  async function streamFrameio(resumeFrame) {
    if (!S.version?.fio_file_id) return;
    const vid = S.versionId;
    const m = await api('version.media', { query: { version_id: vid, quality: $('quality').value } });
    if (vid !== S.versionId) return;
    S.sources.delete(vid);
    setSource({ url: m.url, name: m.name || 'Frame.io', remote: true, fetchedAt: Date.now() });
    if (resumeFrame != null) video.addEventListener('loadedmetadata', () => seekFrame(resumeFrame), { once: true });
  }

  const syncing = new Map();
  function syncComments(silent) {
    if (!S.version?.fio_file_id) return Promise.resolve();
    const vid = S.versionId;
    if (syncing.has(vid)) return syncing.get(vid);
    const run = doSync(vid, silent).finally(() => syncing.delete(vid));
    syncing.set(vid, run);
    return run;
  }
  async function doSync(vid, silent) {
    const btn = $('syncBtn');
    btn.disabled = true;
    btn.textContent = 'Getting comments from Frame.io…';
    try {
      const r = await api('version.sync', { body: { version_id: vid } });
      const v = S.versions.find((x) => x.id === vid);
      if (v) v.fio_synced_at = new Date().toISOString();
      if (S.version && S.version.id === vid) S.version.fio_synced_at = v?.fio_synced_at;
      if (vid === S.versionId) await refreshKeepPlace();
      const bits = [r.new ? `${r.new} new note${r.new > 1 ? 's' : ''}` : '', r.replies ? `${r.replies} new repl${r.replies > 1 ? 'ies' : 'y'}` : '', r.completed ? `${r.completed} marked complete` : ''].filter(Boolean);
      if (!silent || bits.length) toast(bits.length ? `From Frame.io: ${bits.join(', ')}.` : `Up to date with Frame.io (${r.total} comments).`);
      if (r.new_codes?.length) checkCodes(true);
      return r;
    } catch (e) {
      if (!silent) throw e;
    } finally {
      btn.disabled = false;
      btn.textContent = 'Sync comments from Frame.io';
      updateLinkUI();
    }
  }

  function openLinkDialog(v, after, prefill) {
    const input = el('input', { type: 'url', placeholder: 'https://next.frame.io/project/…/view/…', value: prefill || v.frameio_url || '' });
    const result = el('div', { class: 'fio-result' });
    let choice = null;
    const linkBtn = el('button', { type: 'button', class: 'btn primary', text: 'Link and get comments', disabled: true, onclick: async () => {
      if (!choice) return;
      try {
        await api('version.link', { body: { version_id: v.id, file_id: choice.id, stack_id: choice.stack || null, name: choice.name, account_id: choice.account || null } });
        closeDialog();
        await loadState({ filmId: S.filmId, versionId: v.id });
        const r = await syncComments(false);
        if (r && !r.new && !r.replies) toast(`Linked to Frame.io. All ${r.total} comments were already here.`);
        else if (!r) toast('Linked to Frame.io.');
        after?.();
      } catch (err) { fail(err); }
    } });
    const find = async () => {
      result.replaceChildren(el('p', { text: 'Looking it up in Frame.io…' }));
      try {
        const r = await api('fio.resolve', { body: { link: input.value } });
        const files = r.stack ? r.stack.files : [r.file];
        const current = r.file ? r.file.id : files[files.length - 1]?.id;
        result.replaceChildren(
          r.stack ? el('p', { text: `“${r.stack.name || 'Version stack'}” has ${files.length} version${files.length > 1 ? 's' : ''}${r.account_name ? ' (in ' + r.account_name + ')' : ''}. Pick the one that matches ${v.label}:` }) : el('p', { text: `Found${r.account_name ? ' in ' + r.account_name : ''}:` }),
          el('ul', { class: 'fio-files' }, ...files.map((f, i) => {
            const pick = { id: f.id, name: f.name, stack: r.stack?.id, account: r.account_id };
            const radio = el('input', { type: 'radio', name: 'fiofile', value: f.id, checked: f.id === current, onchange: () => { choice = pick; linkBtn.disabled = false; } });
            if (f.id === current) { choice = pick; linkBtn.disabled = false; }
            return el('li', {}, el('label', {}, radio, ` ${r.stack ? 'v' + (i + 1) + ' · ' : ''}${f.name}`, el('small', { text: ` ${fmtDate(f.created_at)}${f.status && f.status !== 'transcoded' ? ' · ' + f.status : ''}` })));
          })));
      } catch (err) { result.replaceChildren(el('p', { class: 'err', text: err.message })); }
    };
    showDialog(
      el('h2', { text: `Link ${v.label} to Frame.io` }),
      el('p', { text: 'Paste the Frame.io link of the file (or its version stack). The comments come across automatically, and the video can play straight from Frame.io.' }),
      el('form', { onsubmit: (e) => { e.preventDefault(); find(); } },
        el('label', {}, 'Frame.io link', input),
        el('div', { class: 'dlg-actions' }, el('button', { class: 'btn', text: 'Find the file' }))),
      result,
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost', text: 'Cancel', onclick: closeDialog }), linkBtn));
    if (input.value) find(); else input.focus();
  }

  /* ---------- Tracks editor ---------- */
  function openTracks(focusNew) {
    const film = S.films.find((f) => f.id === S.filmId);
    if (!film) return;
    const counts = {};
    for (const n of S.notes) { const k = laneOf(n.tag); counts[k] = (counts[k] || 0) + 1; }
    const save = async (body) => {
      const r = await api('category.save', { body: { film_id: film.id, ...body } });
      film.categories = r.categories;
      loadTracks();
      await refreshKeepPlace();
      return r;
    };
    // The dot at the start of the row shows the chosen colour.
    const colorSel = (val) => el('select', { 'aria-label': 'Colour', onchange: (e) => { e.target.closest('.trow-main')?.querySelector('.tdot')?.style.setProperty('--c', `var(--${e.target.value})`); } },
      ...TRACK_COLORS.map(([v, l]) => el('option', { value: v, text: l, selected: v === val })));
    const kindSel = (val) => el('select', { 'aria-label': 'When carried to a new cut, place by' },
      el('option', { value: 'picture', text: 'By picture', selected: val !== 'narration' }),
      el('option', { value: 'narration', text: 'By transcript', selected: val === 'narration' }));
    const row = (c, i) => {
      const code = el('input', { value: c.key, maxlength: 8, 'aria-label': 'Code', class: 'code' });
      const name = el('input', { value: c.label, maxlength: 40, 'aria-label': 'Name' });
      const col = colorSel(c.color), kind = kindSel(c.kind);
      const moveTo = el('select', { 'aria-label': 'Move its notes to' }, ...S.cats.filter((x) => x.id !== c.id).map((x) => el('option', { value: x.key, text: `${x.key} · ${x.label}` })), el('option', { value: '', text: '— Untagged' }));
      return el('li', { class: 'trow' },
        el('div', { class: 'trow-main' },
          el('span', { class: 'tdot', style: `--c:var(--${c.color})` }), code, name, col, kind),
        el('div', { class: 'trow-actions' },
          el('small', { text: `${counts[c.key] || 0} notes in this version` }),
          el('button', { type: 'button', class: 'btn ghost icon', title: 'Move up', 'aria-label': `Move ${c.label} up`, disabled: i === 0, onclick: () => reorder(i, -1) }, svgIcon('up')),
          el('button', { type: 'button', class: 'btn ghost icon', title: 'Move down', 'aria-label': `Move ${c.label} down`, disabled: i === S.cats.length - 1, onclick: () => reorder(i, 1) }, svgIcon('down')),
          el('button', { type: 'button', class: 'btn', text: 'Save', onclick: async () => {
            try { await save({ id: c.id, key: code.value, label: name.value, color: col.value, kind: kind.value }); toast(`${name.value} saved.`); openTracks(); } catch (err) { fail(err); }
          } }),
          el('details', { class: 'tdel' }, el('summary', { text: 'Delete' }),
            el('div', {}, el('span', { text: 'Move its notes to ' }), moveTo,
              el('button', { type: 'button', class: 'btn ghost danger', text: `Delete ${c.label}`, onclick: async () => {
                try {
                  const r = await api('category.delete', { body: { film_id: film.id, id: c.id, move_to: moveTo.value } });
                  film.categories = r.categories; loadTracks(); await refreshKeepPlace();
                  toast(`${c.label} deleted. ${r.moved} note${r.moved === 1 ? '' : 's'} moved to ${trackName(moveTo.value)}.`); openTracks();
                } catch (err) { fail(err); }
              } })))));
    };
    const reorder = async (i, d) => {
      const ids = S.cats.map((c) => c.id);
      [ids[i], ids[i + d]] = [ids[i + d], ids[i]];
      try { const r = await api('category.order', { body: { film_id: film.id, ids } }); film.categories = r.categories; loadTracks(); applyFilters(); openTracks(); } catch (err) { fail(err); }
    };
    const nCode = el('input', { maxlength: 8, placeholder: 'M', 'aria-label': 'Code', class: 'code', required: true });
    const nName = el('input', { maxlength: 40, placeholder: 'Music', 'aria-label': 'Name', required: true,
      oninput: (e) => { if (!nCode.dataset.touched) nCode.value = (e.target.value.match(/[A-Za-z]/) || [''])[0].toUpperCase(); } });
    nCode.addEventListener('input', () => { nCode.dataset.touched = '1'; });
    const used = new Set(S.cats.map((c) => c.color));
    const nCol = colorSel((TRACK_COLORS.find(([v]) => !used.has(v)) || TRACK_COLORS[9])[0]);
    const nKind = kindSel('picture');
    showDialog(
      el('h2', { text: `Tracks for ${film.name}` }),
      el('p', { text: 'Each track is a lane on the timeline. Comments starting with a track’s code (for example “M: …”) land on it when imported or synced. Everyone on the film shares these tracks; showing and hiding them only changes your own view.' }),
      el('div', { class: 'trow-main thead', 'aria-hidden': 'true' }, el('span'), el('span', { text: 'Code' }), el('span', { text: 'Name' }), el('span', { text: 'Colour' }), el('span', { text: 'Placed in a new cut' })),
      el('ol', { class: 'tracks' }, ...S.cats.map(row)),
      el('h3', { text: 'Add a track' }),
      el('form', { class: 'trow-main add', onsubmit: async (e) => {
        e.preventDefault();
        try { await save({ key: nCode.value, label: nName.value, color: nCol.value, kind: nKind.value }); toast(`${nName.value} added. Drag notes onto its lane, or start comments with “${nCode.value.toUpperCase()}:”.`); openTracks(); } catch (err) { fail(err); }
      } }, el('span', { class: 'tdot', style: `--c:var(--${nCol.value})` }), nCode, nName, nCol, nKind, el('button', { class: 'btn primary', text: 'Add track' })),
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost left', text: 'Find tracks in the notes', title: 'Make tracks from codes the notes start with, like ARCH-001 or CG', onclick: () => openCodes() }),
        el('button', { type: 'button', class: 'btn ghost', text: 'Close', onclick: closeDialog })));
    if (focusNew) nName.focus();
  }

  /* ---------- Settings ---------- */
  async function openSettings() {
    let d;
    try { d = await api('settings'); } catch (e) { fail(e); return; }
    const f = d.frameio, e11 = d.elevenlabs;
    let mode = f.mode || 'user';
    const cid = el('input', { value: f.client_id, autocomplete: 'off', spellcheck: 'false' });
    const sec = el('input', { type: 'password', placeholder: f.secret ? `Saved (${f.secret}). Leave empty to keep it.` : '', autocomplete: 'new-password' });
    const acct = el('select', {}, ...(f.accounts.length ? f.accounts.map((a) => el('option', { value: a.id, text: a.name, selected: a.id === f.account_id })) : [el('option', { value: '', text: 'Connect first' })]));
    const statusText = () => {
      if (mode === 'user') {
        if (f.expired) return 'The Adobe sign-in has expired. Sign in again to reconnect.';
        if (f.signed_in) return `Signed in with Adobe${f.adobe_email ? ' as ' + f.adobe_email : ''} by ${f.connected_by} on ${fmtDate(f.connected_at)}.` + (f.account_id ? ` Using “${f.account_name}”.` : ' Choose the account below.');
        return f.client_id ? 'Not signed in yet.' : 'Not set up.';
      }
      return f.account_id ? `Connected to “${f.account_name}”. Checked ${fmtDate(f.checked_at)}.` : f.client_id ? 'Not connected yet.' : 'Not set up.';
    };
    const fioStatus = el('p', { class: 'conn-status' + (f.expired ? ' err' : ''), text: statusText() });
    const key = el('input', { type: 'password', placeholder: e11.key ? `Saved (${e11.key}). Leave empty to keep it.` : 'sk_…', autocomplete: 'new-password' });
    const model = el('input', { value: e11.model || 'scribe_v2', spellcheck: 'false' });
    const lang = el('input', { value: e11.language || '', placeholder: 'Detect automatically', maxlength: 3 });
    const diar = el('input', { type: 'checkbox', checked: e11.diarize !== false });
    const copyRow = (label, value) => el('div', { class: 'copyrow' },
      el('span', { text: label }), el('code', { text: value }),
      el('button', { type: 'button', class: 'btn ghost', text: 'Copy', onclick: () => { navigator.clipboard?.writeText(value); toast('Copied'); } }));
    const userHelp = el('div', { class: 'help' },
      el('p', { text: 'In the Adobe Developer Console, add the Frame.io API to a project with User Authentication → OAuth Web App, and enter these two values:' }),
      copyRow('Default redirect URI', f.redirect_uri),
      copyRow('Redirect URI pattern', f.redirect_pattern),
      el('p', { text: 'Then copy the client ID and secret from the credential here. The person who signs in should be able to see the film’s projects in Frame.io; the app uses Frame.io as them.' }));
    const s2sHelp = el('div', { class: 'help' },
      el('p', { text: 'Only on Frame.io Enterprise plans: add the Frame.io API with Server-to-Server authentication, and copy its client ID and secret here. Nobody needs to sign in.' }));
    const modeRadios = el('div', { class: 'modes', role: 'radiogroup', 'aria-label': 'How to connect' },
      ...[['user', 'Sign in with Adobe', 'Works on every plan'], ['s2s', 'Server-to-server', 'Enterprise plans only']].map(([val, label, hint]) =>
        el('label', { class: 'mode' }, el('input', { type: 'radio', name: 'fiomode', value: val, checked: mode === val, onchange: () => { mode = val; syncMode(); } }),
          el('span', {}, el('strong', { text: label }), el('small', { text: hint })))));
    const submitBtn = el('button', { class: 'btn primary' });
    const syncMode = () => {
      userHelp.hidden = mode !== 'user';
      s2sHelp.hidden = mode !== 's2s';
      fioStatus.textContent = mode === (f.mode || 'user') ? statusText() : '';
      submitBtn.textContent = mode === 'user' ? (f.signed_in && f.mode === 'user' ? 'Save' : 'Save and sign in with Adobe') : (f.client_id && f.mode === 's2s' ? 'Save and test' : 'Connect');
    };
    syncMode();
    const busy = (b, on) => { b.disabled = on; };
    showDialog(
      el('h2', { text: 'Settings' }),
      el('p', { class: 'small', text: `Connections for ${d.company.name}. Every production in the company uses them.` }),
      el('h3', { text: 'Frame.io' }),
      el('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        const b = submitBtn; busy(b, true); fioStatus.classList.remove('err'); fioStatus.textContent = 'Checking…';
        try {
          const r = await api('settings.frameio', { body: { mode, client_id: cid.value, client_secret: sec.value, account_id: acct.value || null } });
          if (r.needs_signin) { fioStatus.textContent = 'Taking you to Adobe to sign in…'; location.href = `oauth.php?start=1&ws=${WS}`; return; }
          await loadState({ filmId: S.filmId, versionId: S.versionId });
          toast(r.account_id ? 'Frame.io connected.' : 'Connected. Choose which Frame.io account to use.');
          openSettings();
        } catch (err) { fioStatus.textContent = err.message; fioStatus.classList.add('err'); }
        finally { busy(b, false); }
      } },
      modeRadios, userHelp, s2sHelp,
      el('div', { class: 'two' }, el('label', {}, 'Client ID', cid), el('label', {}, 'Client secret', sec)),
      f.accounts.length > 1 ? el('label', {}, 'Frame.io account', acct) : null,
      fioStatus,
      el('div', { class: 'dlg-actions' },
        f.client_id ? el('button', { type: 'button', class: 'btn ghost danger left', text: 'Disconnect', onclick: async () => {
          if (!confirm('Disconnect Frame.io? Links on versions are kept for when you reconnect.')) return;
          try { await api('settings.frameio.clear', { body: {} }); await loadState({ filmId: S.filmId, versionId: S.versionId }); openSettings(); } catch (err) { fail(err); }
        } }) : null,
        mode === 'user' && (f.signed_in || f.expired) ? el('button', { type: 'button', class: f.expired ? 'btn' : 'btn ghost', text: 'Sign in again', onclick: () => { location.href = `oauth.php?start=1&ws=${WS}`; } }) : null,
        submitBtn)),
      el('h3', { text: 'ElevenLabs transcription' }),
      el('p', { text: 'Transcribes a linked cut straight from Frame.io (Scribe, about $0.22 per hour of audio). Create an API key with Speech to Text access in ElevenLabs → Developers → API keys.' }),
      el('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        try {
          await api('settings.elevenlabs', { body: { key: key.value, model: model.value, language: lang.value, diarize: diar.checked } });
          await loadState({ filmId: S.filmId, versionId: S.versionId });
          toast('ElevenLabs settings saved.');
          openSettings();
        } catch (err) { fail(err); }
      } },
      el('label', {}, 'API key', key),
      el('div', { class: 'two' }, el('label', {}, 'Model', model), el('label', {}, 'Language (optional)', lang)),
      el('label', { class: 'inline' }, diar, ' Label different speakers'),
      el('div', { class: 'dlg-actions' },
        e11.key ? el('button', { type: 'button', class: 'btn ghost danger left', text: 'Remove key', onclick: async () => {
          try { await api('settings.elevenlabs.clear', { body: {} }); await loadState({ filmId: S.filmId, versionId: S.versionId }); openSettings(); } catch (err) { fail(err); }
        } }) : null,
        el('button', { class: 'btn primary', text: 'Save' }))),
      d.mail.staff ? mailSection(d.mail) : el('section', { class: 'mail-settings' }, el('h3', { text: 'Email' }),
        el('p', { text: d.mail.configured ? `Invites and password resets are emailed from ${d.mail.from}.` : 'This server doesn’t send email yet, so invites are copied and sent by hand. Previously Noted support sets up email for everyone.' })),
      el('div', { class: 'dlg-actions' }, el('button', { type: 'button', class: 'btn ghost', text: 'Close', onclick: closeDialog })));
  }

  /* ---------- Email (invites and password resets) ---------- */
  function mailSection(m) {
    const from = el('input', { type: 'email', value: m.from || '', placeholder: `hello@${m.domain || 'yourdomain.com'}`, autocomplete: 'off', required: true });
    const fromName = el('input', { value: m.from_name || '', placeholder: document.querySelector('.brand span')?.textContent || 'Previously Noted', maxlength: 60 });
    const host = el('input', { value: m.host || '', placeholder: `mail.${m.domain || 'yourdomain.com'}`, autocomplete: 'off', spellcheck: 'false', required: true });
    const port = el('input', { type: 'number', value: m.port || 465, min: 1, max: 65535 });
    const security = el('select', {}, el('option', { value: 'ssl', text: 'SSL/TLS', selected: m.security !== 'starttls' }), el('option', { value: 'starttls', text: 'STARTTLS', selected: m.security === 'starttls' }));
    security.addEventListener('change', () => { port.value = security.value === 'ssl' ? 465 : 587; });
    const user = el('input', { value: m.user || '', placeholder: 'Same as the From address', autocomplete: 'off', spellcheck: 'false' });
    const pass = el('input', { type: 'password', placeholder: m.pass ? 'Saved. Leave empty to keep it.' : 'The mailbox’s password', autocomplete: 'new-password' });
    const status = el('p', { class: 'conn-status', text: m.configured ? `Sending from ${m.from}. Last checked ${fmtDate(m.checked_at)}.` : 'Not set up. Invites and reset links are copied and sent by hand.' });
    const preset = (h) => { host.value = h; security.value = 'ssl'; port.value = 465; host.focus(); };
    const save = el('button', { class: 'btn primary', text: 'Save and send a test' });
    return el('section', { class: 'mail-settings' },
      el('h3', { text: 'Email' }),
      el('p', { text: 'Invites and password-reset links go out from your own mailbox, through your domain’s mail server, so they’re far less likely to land in spam. Each one is sent with the inviting admin as the reply-to address.' }),
      status,
      el('form', { onsubmit: async (ev) => {
        ev.preventDefault();
        save.disabled = true; status.classList.remove('err'); status.textContent = 'Connecting to the mail server and sending a test…';
        try {
          const r = await api('settings.mail', { body: { from: from.value, from_name: fromName.value, host: host.value, port: +port.value, security: security.value, user: user.value, pass: pass.value } });
          await loadState({ filmId: S.filmId, versionId: S.versionId });
          toast(`Saved. A test email is on its way to ${r.sent_to}.`);
          openSettings();
        } catch (err) { status.classList.add('err'); status.textContent = err.message; save.disabled = false; }
      } },
      el('div', { class: 'two' }, el('label', {}, 'Send from', from), el('label', {}, 'Sender name', fromName)),
      el('div', { class: 'presets' }, el('span', { text: 'Mail server:' }),
        el('button', { type: 'button', class: 'btn ghost', text: 'This hosting (cPanel)', onclick: () => preset(`mail.${(from.value.split('@')[1] || m.domain || '').trim()}`) }),
        el('button', { type: 'button', class: 'btn ghost', text: 'Namecheap Private Email', onclick: () => preset('mail.privateemail.com') })),
      el('div', { class: 'three' }, el('label', {}, 'Server', host), el('label', {}, 'Security', security), el('label', {}, 'Port', port)),
      el('div', { class: 'two' }, el('label', {}, 'Username', user), el('label', {}, 'Password', pass)),
      el('div', { class: 'dlg-actions' },
        m.configured ? el('button', { type: 'button', class: 'btn ghost danger left', text: 'Stop sending email', onclick: async () => {
          if (!confirm('Stop sending email? Invites and reset links go back to copy-and-send by hand.')) return;
          try { await api('settings.mail.clear', { body: {} }); await loadState({ filmId: S.filmId, versionId: S.versionId }); openSettings(); } catch (err) { fail(err); }
        } }) : null,
        m.configured ? el('button', { type: 'button', class: 'btn ghost', text: 'Send another test', onclick: async (e) => {
          e.target.disabled = true;
          try { const r = await api('settings.mail.test', { body: {} }); toast(`Test sent to ${r.sent_to}.`); } catch (err) { fail(err); }
          e.target.disabled = false;
        } }) : null,
        save)));
  }

  /* ---------- ElevenLabs transcription ---------- */
  async function startTranscribe(vid) {
    const v = S.versions.find((x) => x.id === vid);
    if (!confirm(`Transcribe ${v ? v.label : 'this cut'} with ElevenLabs? The audio is sent to ElevenLabs. A 45-minute cut costs about 17 cents.`)) return;
    const r = await api('transcribe.create', { body: { version_id: vid } });
    if (!r.already) api('transcribe.run', { body: { job_id: r.job_id } }).catch(() => { /* the status poll reports problems */ });
    toast('Transcription started. You can keep working; it appears in the Transcript tab when it’s done.');
    checkJob(vid);
  }

  async function checkJob(vid) {
    clearTimeout(S.jobTimer);
    const line = $('jobLine');
    let job;
    try { job = (await api('transcribe.status', { query: { version_id: vid } })).job; } catch { return; }
    if (vid !== S.versionId) return;
    if (!job) { line.hidden = true; return; }
    const age = Math.round((Date.now() - new Date(job.created_at)) / 1000);
    if (job.status === 'queued' || job.status === 'running') {
      line.hidden = false;
      line.className = 'job running';
      line.textContent = `${job.message || 'Waiting to start…'} ${fmtClock(age)}`;
      $('transcribeBtn').disabled = true;
      S.jobTimer = setTimeout(() => checkJob(vid), 4000);
      return;
    }
    $('transcribeBtn').disabled = false;
    const recent = age < 120;
    if (job.status === 'done') {
      line.hidden = !recent;
      line.className = 'job done';
      line.textContent = `Transcribed: ${job.message}`;
      if (recent && S.script.latestId !== job.result?.transcript_id) {
        await loadScript();
        await refreshKeepPlace();
        toast(`Transcript ready: ${job.result?.segments} lines.` + placementSummary(job.result?.placement, vid));
      }
    } else {
      line.hidden = false;
      line.className = 'job error';
      line.textContent = `Transcription failed: ${job.message}`;
    }
  }

  async function loadNotes() {
    if (!S.versionId) return;
    const d = await api('notes', { query: { version_id: S.versionId } });
    S.version = { ...S.version, ...d.version, id: +d.version.id, film_id: +d.version.film_id, tc_base: +d.version.tc_base, rate: +d.version.rate, tc_start: +d.version.tc_start || 0 };
    S.notes = d.notes;
    S.shots = d.shots || [];
    S.edit = d.edit;
    renderAuthorFilter();
    applyFilters();
    checkStartTc();
    checkCodes(false);
  }

  /* ---------- Tracks from comment codes ----------
     Comments that start with codes ("ARCH-001 : …", "CHART-002: …", "CG") can each get a track. */
  let codesAsked = null;
  async function checkCodes(force) {
    const box = $('codesFix');
    if (!can('editor') || !S.filmId) { box.hidden = true; return; }
    const untagged = S.notes.filter((n) => !n.tag).length;
    if (!force) {
      if (codesAsked === S.filmId) return;   // already checked this film
      box.hidden = true;
      codesAsked = S.filmId;
      if (untagged < 3 || ls.get('codesDismissed:' + S.filmId)) return;
    }
    codesAsked = S.filmId;
    let d;
    try { d = await api('category.detect', { query: { film_id: S.filmId } }); } catch { return; }
    if (!d.codes.length) { box.hidden = true; return; }
    const names = d.codes.slice(0, 4).map((c) => c.code).join(', ') + (d.codes.length > 4 ? '…' : '');
    box.replaceChildren(
      el('p', {}, el('strong', { text: 'These notes start with codes. ' }), `Make a track for each (${names})?`),
      el('div', { class: 'banner-actions' },
        el('button', { type: 'button', class: 'btn primary', text: 'Review tracks', onclick: () => openCodes(d) }),
        el('button', { type: 'button', class: 'btn ghost', text: 'Not now', onclick: () => { ls.set('codesDismissed:' + S.filmId, '1'); box.hidden = true; } })));
    box.hidden = false;
  }

  async function openCodes(found) {
    const film = S.films.find((f) => f.id === S.filmId);
    if (!film) return;
    let d = found;
    if (!d) { try { d = await api('category.detect', { query: { film_id: film.id } }); } catch (e) { fail(e); return; } }
    if (!d.codes.length) { toast('No codes found at the start of this film’s untagged notes.'); return; }
    const rows = d.codes.map((c) => {
      const on = el('input', { type: 'checkbox', checked: true, 'aria-label': `Create a ${c.code} track` });
      const label = el('input', { value: c.label, maxlength: 40, 'aria-label': `Name for ${c.code}` });
      return { c, on, label, li: el('li', { class: 'code-row' }, on, el('span', { class: 'tag', text: c.code }), label, el('small', { text: `${c.count} note${c.count === 1 ? '' : 's'}` })) };
    });
    const starters = ['E', 'N', 'A'].filter((k) => k in (d.track_notes || {}));
    const unusedStarters = starters.filter((k) => !d.track_notes[k]);
    const drop = el('input', { type: 'checkbox', checked: unusedStarters.length > 0 });
    showDialog(
      el('h2', { text: `Tracks for ${film.name}` }),
      el('p', { text: 'These codes start the notes. Each one you keep becomes a track, and its notes move onto it. The number stays in the note’s text (ARCH-001 stays ARCH-001). New comments that start with these codes land on their track automatically.' }),
      el('ul', { class: 'code-list' }, ...rows.map((r) => r.li)),
      unusedStarters.length ? el('label', { class: 'inline' }, drop, ` Remove the starter tracks no note uses (${unusedStarters.join(', ')})`) : null,
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost', text: 'Not now', onclick: () => { ls.set('codesDismissed:' + film.id, '1'); $('codesFix').hidden = true; closeDialog(); } }),
        el('button', { type: 'button', class: 'btn primary', text: 'Create tracks', onclick: async () => {
          const codes = rows.filter((r) => r.on.checked).map((r) => ({ code: r.c.code, label: r.label.value }));
          if (!codes.length) { closeDialog(); return; }
          try {
            const r = await api('category.adopt', { body: { film_id: film.id, codes, drop_defaults: drop.checked } });
            film.categories = r.categories;
            loadTracks();
            closeDialog();
            $('codesFix').hidden = true;
            await refreshKeepPlace();
            toast(`${r.created.length} track${r.created.length === 1 ? '' : 's'} created, ${r.tagged} notes moved onto them` + (r.dropped.length ? `; removed ${r.dropped.join(', ')}` : '') + '.');
          } catch (err) { fail(err); }
        } })));
  }

  /* ---------- Start timecode ----------
     Frame.io (and most edit suites) show timecode from the file's start, often 01:00:00:00 or 10:00:00:00.
     If notes came in counted from that timecode while the version still starts at 00:00:00:00, they all land an
     hour or more past the end. Spot that and offer to fix it. */
  function suggestedStart() {
    if (!S.notes.length || tcStart()) return 0;
    const hour = 3600 * tcBase();
    const frames = S.notes.map((n) => n.frame);
    const min = Math.min(...frames);
    const dur = video.src && isFinite(video.duration) ? video.duration * rate() : null;
    const beyond = dur ? frames.filter((f) => f > dur).length / frames.length : 0;
    if (min >= hour && (dur == null || beyond > 0.5)) return Math.floor(min / hour) * hour;
    return 0;
  }
  function checkStartTc() {
    const box = $('tcFix');
    const start = can('editor') ? suggestedStart() : 0;
    if (!start || ls.get('tcFixDismissed:' + S.versionId)) { box.hidden = true; return; }
    const b = tcBase(), p = (n) => String(n).padStart(2, '0');
    const label = `${p(Math.floor(start / b / 3600))}:00:00:00`;
    box.replaceChildren(
      el('p', {}, el('strong', { text: 'These notes are an hour or more past the start of the video. ' }),
        `They look like they use timecode that starts at ${label} (as Frame.io shows it), while this version starts at 00:00:00:00.`),
      el('div', { class: 'banner-actions' },
        el('button', { type: 'button', class: 'btn primary', text: `Start this version at ${label}`, onclick: async () => {
          try {
            const r = await api('version.tc_start', { body: { version_id: S.versionId, start_tc: label, move_notes: true } });
            toast(`This version now starts at ${label}; ${r.moved} notes moved to match.`);
            await refreshKeepPlace();
            await loadState({ filmId: S.filmId, versionId: S.versionId });
          } catch (e) { fail(e); }
        } }),
        el('button', { type: 'button', class: 'btn ghost', text: 'Not now', onclick: () => { ls.set('tcFixDismissed:' + S.versionId, '1'); box.hidden = true; } })));
    box.hidden = false;
  }

  /* ---------- filters ---------- */
  // Every track the film has, plus Untagged when some notes have no track.
  function trackList() {
    const list = [...S.cats];
    if (S.notes.some((n) => !catOf(firstKey(n.tag)))) list.push(UNTAGGED);
    return list;
  }
  const laneOf = (tag) => (catOf(firstKey(tag)) ? firstKey(tag) : '');

  function toggleTrack(key, solo) {
    const keys = trackList().map((t) => t.key);
    if (solo) {
      const alreadySolo = keys.every((k) => (k === key) !== S.hidden.has(k));
      S.hidden = alreadySolo ? new Set() : new Set(keys.filter((k) => k !== key));
    } else if (S.hidden.has(key)) S.hidden.delete(key);
    else S.hidden.add(key);
    saveHidden();
    applyFilters();
  }

  function renderTagChips() {
    const counts = {};
    for (const n of S.notes) { const k = laneOf(n.tag); counts[k] = (counts[k] || 0) + 1; }
    $('tagChips').replaceChildren(...trackList().map((t) => el('button', {
      type: 'button', class: 'chip' + (S.hidden.has(t.key) ? ' off' : ''), style: `--c:var(--${t.color})`,
      'aria-pressed': S.hidden.has(t.key) ? 'false' : 'true',
      title: `${S.hidden.has(t.key) ? 'Show' : 'Hide'} ${t.label} notes. Alt-click to show only this track.`,
      onclick: (e) => toggleTrack(t.key, e.altKey || e.shiftKey),
    }, t.key ? el('span', { class: 'tag', style: `--c:var(--${t.color})`, text: t.key }) : null, ' ', t.label, el('span', { class: 'n', text: counts[t.key] || 0 }))));
  }

  function renderAuthorFilter() {
    const authors = [...new Set(S.notes.map((n) => n.author))].sort();
    const sel = $('authorFilter');
    if (!authors.includes(S.filter.author)) S.filter.author = '';
    sel.replaceChildren(el('option', { value: '', text: 'Everyone' }), ...authors.map((a) => el('option', { value: a, text: a, selected: a === S.filter.author })));
  }

  function passes(n) {
    const f = S.filter;
    if (S.hidden.has(laneOf(n.tag))) return false;
    if (f.author && n.author !== f.author) return false;
    if (f.status === 'todo' && !['open', 'discuss'].includes(n.status)) return false;
    if (f.status === 'open' && n.status !== 'open') return false;
    if (f.status === 'discuss' && n.status !== 'discuss') return false;
    if (f.status === 'check' && n.status !== 'addressed') return false;
    if (f.status === 'done' && !isDone(n)) return false;
    if (f.status === 'unconfirmed' && n.confirmed) return false;
    if (f.q) {
      const hay = (n.text + ' ' + n.author + ' ' + n.replies.map((r) => r.text).join(' ')).toLowerCase();
      if (!hay.includes(f.q)) return false;
    }
    return true;
  }

  function applyFilters() {
    S.visible = S.notes.filter(passes);
    renderTagChips();
    renderTally();
    renderList();
    renderTimeline();
    renderScriptNotes();
    $('tabNotesN').textContent = S.notes.length || '';
    S.activeKey = '__force';
    updateActive();
  }

  function renderTally() {
    const t = $('tally');
    if (!S.notes.length) { t.replaceChildren(); return; }
    const c = (fn) => S.notes.filter(fn).length;
    const items = [
      ['open', 'open', c((n) => n.status === 'open')],
      ['check', 'addressed', c((n) => n.status === 'addressed')],
      ['discuss', 'to discuss', c((n) => n.status === 'discuss')],
      ['done', 'done', c(isDone)],
    ];
    const cur = S.filter.status;
    // Always all four, so the header never changes shape as counts reach zero. Click one to show only those.
    t.replaceChildren(...items.map(([k, label, v]) => el('button', {
      type: 'button', class: 't-' + k + (v ? '' : ' zero'), 'aria-pressed': String(cur === k),
      title: cur === k ? 'Show all notes again' : `Show only the notes ${({ done: 'that are finished (approved, won’t do, cut or superseded)' })[k] || 'that are ' + label}`,
      onclick: () => setStatusFilter(cur === k ? '' : k),
    }, el('b', { text: v }), ' ', label)));
    renderPlaceQueue();
  }

  // Carried notes not yet placed in this cut: a queue of their own, for editors, only while there are any.
  function renderPlaceQueue() {
    const b = $('placeQueue');
    const n = S.notes.filter((x) => !x.confirmed).length;
    const on = S.filter.status === 'unconfirmed';
    b.hidden = !can('editor') || (!n && !on);
    b.setAttribute('aria-pressed', String(on));
    b.textContent = on ? `Showing ${n} note${n === 1 ? '' : 's'} to place · show all` : `${n} carried note${n === 1 ? '' : 's'} to place or check`;
  }

  function setStatusFilter(v) {
    S.filter.status = v;
    applyFilters();
  }

  /* ---------- list ---------- */
  function renderList() {
    const ol = $('notes');
    if (!S.versionId) { ol.replaceChildren(el('li', { class: 'notes-empty', text: 'Create a film and a version to start.' })); return; }
    if (!S.notes.length) { ol.replaceChildren(el('li', { class: 'notes-empty', text: 'No notes yet. Import the Frame.io comment export for this version from Films & versions, or play the video and add notes here.' })); return; }
    if (!S.visible.length) { ol.replaceChildren(el('li', { class: 'notes-empty', text: 'No notes match these filters.' })); return; }
    ol.replaceChildren(...S.visible.map((n) => el('li', {
      class: 'note' + (isDone(n) ? ' done' : ''), 'data-id': n.id, style: `--c:${tagColor(n.tag)}`, tabindex: '0',
      onclick: () => seekFrame(n.frame, n.id),
      onkeydown: (e) => {
        if (e.key === 'Enter') seekFrame(n.frame, n.id);
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault(); e.stopPropagation();
          const to = e.key === 'ArrowDown' ? e.currentTarget.nextElementSibling : e.currentTarget.previousElementSibling;
          if (to?.dataset.id) { to.focus(); const m = S.notes.find((x) => x.id === +to.dataset.id); if (m) seekFrame(m.frame, m.id); }
        }
      },
    },
    el('span', { class: 'note-tc' }, tc(n.frame), n.end != null ? el('small', { text: `to ${tc(n.end)}` }) : null),
    el('div', { class: 'note-main' },
      el('p', { class: 'note-text', text: n.text }),
      el('div', { class: 'note-meta' },
        el('span', { class: 'tag', style: `--c:${tagColor(n.tag)}`, text: n.tag || '—', title: trackName(laneOf(n.tag)) }),
        el('span', { class: 'status st-' + n.status, style: `--sc:${statusColor(n.status)}`, text: STATUS[n.status]?.label || n.status }),
        el('span', { text: n.author }),
        n.carried ? swatch(verOf(n.origin_version_id)) : null,
        n.replies.length ? el('span', { text: `${n.replies.length} repl${n.replies.length > 1 ? 'ies' : 'y'}` }) : null,
        !n.confirmed ? el('span', { class: 'flag', title: REASON[n.reason]?.long || 'Carried from an earlier version; not placed in this cut yet.', text: REASON[n.reason]?.short || 'to place' }) : null,
      )))));
  }

  /* ---------- timeline: zoomable, one lane per note type ---------- */
  function totalFrames() {
    const v = $('video');
    const max = S.notes.reduce((m, n) => Math.max(m, n.frame), 0);
    const shotEnd = S.shots.length ? S.shots[S.shots.length - 1][1] : 0;
    const fromVideo = v.duration && isFinite(v.duration) ? Math.round(v.duration * rate()) : 0;
    // A note past the end of the file (wrong file, or the cut got shorter) still stays on the timeline.
    return Math.max(1, fromVideo || shotEnd || Math.max(tcBase() * 60, Math.round(max * 1.02) + tcBase() * 5), max + 1);
  }
  const TL = { start: 0, span: 0, heldUntil: 0 };
  const minSpan = () => Math.round(rate() * 8);
  const viewW = () => $('tlView').clientWidth || 1;
  const fx = (f) => ((f - TL.start) / TL.span) * viewW();

  function tlClamp() {
    const total = totalFrames();
    TL.span = Math.min(total, Math.max(Math.min(minSpan(), total), TL.span || total));
    TL.start = Math.min(Math.max(0, TL.start), Math.max(0, total - TL.span));
  }
  function zoomTo(span, anchorFrame, anchorX) {
    const total = totalFrames();
    const next = Math.min(total, Math.max(Math.min(minSpan(), total), span));
    const ax = anchorX ?? fx(anchorFrame);
    TL.start = anchorFrame - (ax / viewW()) * next;
    TL.span = next;
    TL.heldUntil = Date.now() + 2500;
    renderTimeline();
  }
  function zoomBy(factor, anchorFrame) {
    tlClamp();
    const a = anchorFrame ?? (S.frame >= TL.start && S.frame <= TL.start + TL.span ? S.frame : TL.start + TL.span / 2);
    zoomTo(TL.span * factor, a);
  }
  function zoomFit() { TL.start = 0; TL.span = totalFrames(); renderTimeline(); }

  function fmtClock(sec) {
    sec = Math.max(0, Math.round(sec));
    const h = Math.floor(sec / 3600), m = Math.floor(sec / 60) % 60, ss = sec % 60;
    return h ? `${h}:${String(m).padStart(2, '0')}:${String(ss).padStart(2, '0')}` : `${m}:${String(ss).padStart(2, '0')}`;
  }

  function renderTimeline() {
    tlClamp();
    const total = totalFrames();
    const w = viewW();
    const end = TL.start + TL.span;
    const vis = new Set(S.visible.map((n) => n.id));

    // Zoom control and range label.
    const z = Math.log(total / TL.span) / Math.log(Math.max(1.0001, total / Math.min(minSpan(), total)));
    $('zoom').value = String(Math.round(Math.max(0, Math.min(1, z || 0)) * 1000));
    $('tlRange').textContent = TL.span >= total - 1 ? `Whole cut · ${fmtClock(total / rate())}` : `${tc(TL.start)} – ${tc(end)}`;

    // Time scale.
    const pxPerSec = (w / TL.span) * rate();
    const steps = [1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];
    const step = steps.find((st) => st * pxPerSec >= 72) || 3600;
    const marks = [];
    for (let sec = Math.ceil(TL.start / rate() / step) * step; sec * rate() <= end; sec += step) {
      marks.push(el('span', { class: 'tl-mark', style: `left:${fx(sec * rate())}px`, text: fmtClock(sec + tcStart() / tcBase()) }));
    }
    $('tlScale').replaceChildren(...marks);

    // Shots from the edit, when it's zoomed in enough to tell them apart.
    const shotEls = [];
    if (S.shots.length) {
      const inView = S.shots.filter((sh) => sh[2] === 1 && sh[1] > TL.start && sh[0] < end);
      if (inView.length && w / inView.length >= 3) {
        inView.forEach((sh, i) => {
          const x0 = Math.max(0, fx(sh[0])), x1 = Math.min(w, fx(sh[1]));
          shotEls.push(el('span', { class: 'tl-shot' + (i % 2 ? ' alt' : ''), style: `left:${x0}px;width:${Math.max(1, x1 - x0)}px`, title: `${sh[3]} · ${tc(sh[0])}` }));
        });
      }
    }
    $('tlShots').replaceChildren(...shotEls);
    $('tlShots').hidden = !S.shots.length;

    // Lanes: one per track. A hidden track folds down to a thin row.
    const lanes = trackList();
    // The letters column grows to fit the longest track code (CHART, VFX…).
    const longest = Math.max(1, ...lanes.map((l) => (l.key || '·').length));
    $('tl').style.setProperty('--gutter-w', `${Math.max(22, longest * 8 + 8)}px`);
    $('tlGutter').replaceChildren(...[el('span', { class: 'g-scale' }), S.shots.length ? el('span', { class: 'g-shots', title: 'Shots from the edit' }) : null].filter(Boolean),
      ...lanes.map((l) => el('button', {
        type: 'button', class: 'g-lane' + (S.hidden.has(l.key) ? ' off' : ''), style: `--c:var(--${l.color})`,
        title: `${l.label}: click to ${S.hidden.has(l.key) ? 'show' : 'hide'}, Alt-click to show only this track`,
        'aria-pressed': S.hidden.has(l.key) ? 'false' : 'true',
        'aria-label': `${l.label} track`,
        onclick: (e) => toggleTrack(l.key, e.altKey || e.shiftKey),
        text: l.key || '·',
      })),
      ...(can('editor') ? [el('button', { type: 'button', class: 'g-add', title: 'Add a track', 'aria-label': 'Add a track', onclick: () => openTracks(true) }, svgIcon('plus'))] : []));

    const laneEls = lanes.map((l) => {
      if (S.hidden.has(l.key)) return el('div', { class: 'tl-lane off', 'data-lane': l.key, title: `${l.label} is hidden. Click its letter to show it.` });
      const list = S.notes.filter((n) => laneOf(n.tag) === l.key).sort((a, b) => a.frame - b.frame);
      // Group notes that would sit on top of each other at this zoom.
      const groups = [];
      for (const n of list) {
        const x = fx(n.frame);
        const g = groups[groups.length - 1];
        if (g && x - g.x1 < 7) { g.notes.push(n); g.x1 = x; } else groups.push({ notes: [n], x0: x, x1: x });
      }
      const kids = [];
      groups.forEach((g, i) => {
        if (g.x1 < -40 || g.x0 > w + 40) return;
        const next = groups[i + 1];
        const room = (next ? next.x0 : w + 200) - g.x0;
        if (g.notes.length === 1) {
          const n = g.notes[0];
          // A note with an out-point is drawn as a bar from in to out; a plain note is a tick with its text beside it.
          const span = n.end != null ? Math.max(4, fx(n.end) - g.x0) : 0;
          const label = (span || room) > 56 ? n.text : '';
          const width = span ? `;width:${span}px` : (label ? `;max-width:${Math.min(260, room - 6)}px` : '');
          kids.push(el('button', {
            type: 'button', class: `mk st-${n.status}` + (isDone(n) ? ' done' : '') + (n.confirmed ? '' : ' unconfirmed') + (vis.has(n.id) ? '' : ' filtered') + (span ? ' range' : label ? ' wide' : ''),
            'data-id': n.id, 'data-frame': n.frame, tabindex: '-1',
            style: `left:${g.x0}px;--c:${tagColor(n.tag)}` + width,
            title: `${tc(n.frame)}${n.end != null ? ' to ' + tc(n.end) : ''} · ${trackName(laneOf(n.tag))} · ${STATUS[n.status]?.label} — ${n.text.slice(0, 140)}`
              + (can('editor') ? `\nDrag up or down to move it to another track. Drag its right edge to ${n.end != null ? 'change' : 'set'} where it ends.` : ''),
            'aria-label': `${tc(n.frame)}${n.end != null ? ' to ' + tc(n.end) : ''} ${n.text.slice(0, 80)}`,
          }, label ? el('span', { text: label }) : null,
          can('editor') ? el('span', { class: 'out-handle', 'data-handle': 'out', 'aria-hidden': 'true' }) : null));
        } else {
          const open = g.notes.filter((n) => !isDone(n)).length;
          kids.push(el('button', {
            type: 'button', class: 'mk cluster' + (g.notes.every((n) => !vis.has(n.id)) ? ' filtered' : ''),
            'data-zoom': `${g.notes[0].frame},${g.notes[g.notes.length - 1].frame}`, tabindex: '-1',
            style: `left:${g.x0}px;width:${Math.max(16, g.x1 - g.x0 + 10)}px;--c:${tagColor(l.key)}`,
            title: `${g.notes.length} notes close together (${open} not finished). Click to zoom in.`,
            'aria-label': `${g.notes.length} ${l.label} notes close together, ${open} not finished. Zoom in.`,
          }, el('span', { text: g.notes.length })));
        }
      });
      return el('div', { class: 'tl-lane', 'data-lane': l.key, 'data-label': l.label }, ...kids);
    });
    $('tlLanes').replaceChildren(...laneEls, el('div', { class: 'tl-lane add' }));

    // Overview of the whole cut.
    $('tlOverMarks').replaceChildren(...S.notes.filter((n) => vis.has(n.id) && !S.hidden.has(laneOf(n.tag))).map((n) => el('span', {
      class: 'om' + (isDone(n) ? ' done' : ''), style: `left:${(n.frame / total) * 100}%;--c:${tagColor(n.tag)}`,
    })));
    const win = $('tlWin');
    win.style.left = `${(TL.start / total) * 100}%`;
    win.style.width = `${Math.max(0.6, (TL.span / total) * 100)}%`;
    win.hidden = TL.span >= total - 1;
    S.activeKey = '__force';
    moveHead();
  }

  function moveHead() {
    const total = totalFrames();
    // While playing, page the zoomed view so the playhead stays in sight.
    if (TL.span && TL.span < total - 1 && Date.now() > TL.heldUntil) {
      if (S.frame < TL.start || S.frame > TL.start + TL.span * 0.94) {
        TL.start = Math.max(0, Math.min(total - TL.span, S.frame - TL.span * 0.06));
        renderTimeline();
        return;
      }
    }
    const x = fx(S.frame);
    const head = $('tlHead');
    head.style.left = `${x}px`;
    head.hidden = x < -1 || x > viewW() + 1;
    $('tlOverHead').style.left = `${Math.min(1, S.frame / total) * 100}%`;
    for (const m of document.querySelectorAll('.mk.on')) m.classList.remove('on');
    for (const n of activeNotes().notes) document.querySelector(`.mk[data-id="${n.id}"]`)?.classList.add('on');
  }

  // Pointer: click a note to go to it, click a group to zoom into it, drag to move, click empty space to seek.
  (() => {
    const view = $('tlView');
    let drag = null;
    const laneAt = (x, y) => document.elementsFromPoint(x, y).map((n) => n.closest?.('.tl-lane')).find((n) => n && !n.classList.contains('off') && !n.classList.contains('add'));
    const frameAtX = (x) => { const r = view.getBoundingClientRect(); return Math.round(TL.start + ((x - r.left) / r.width) * TL.span); };
    let scrubRaf = 0;
    const scrubTo = (x) => {
      const f = Math.max(0, Math.min(totalFrames() - 1, frameAtX(x)));
      cancelAnimationFrame(scrubRaf);
      scrubRaf = requestAnimationFrame(() => seekFrame(f));
    };
    view.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      const target = e.target.closest('.mk');
      drag = { x: e.clientX, y: e.clientY, start: TL.start, moved: false, mode: null, target };
      // Scrub: press on the ruler, or on the playhead itself, and drag.
      const head = $('tlHead').getBoundingClientRect();
      if (e.target.closest('.tl-scale') || (!target && Math.abs(e.clientX - (head.left + head.width / 2)) <= 6)) {
        drag.mode = 'scrub'; drag.moved = true;
        view.classList.add('scrubbing');
        scrubTo(e.clientX);
      } else if (e.target.closest('.out-handle') && target?.dataset.id) {
        // Drag the right edge of a note to set where it ends.
        const n = S.notes.find((x) => x.id === +target.dataset.id);
        if (n) { drag.mode = 'out'; drag.moved = true; drag.note = n; drag.oldEnd = n.end; view.classList.add('trimming'); }
      }
      view.setPointerCapture(e.pointerId);
    });
    view.addEventListener('pointermove', (e) => {
      if (!drag) return;
      if (drag.mode === 'scrub') { scrubTo(e.clientX); return; }
      if (drag.mode === 'out') {
        const f = frameAtX(e.clientX);
        drag.note.end = f > drag.note.frame ? f : null;
        renderTimeline();
        return;
      }
      const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      if (!drag.mode) {
        // Up or down on a note moves it to another track; sideways moves along the cut.
        if (can('editor') && drag.target?.dataset.id && Math.abs(dy) > 8 && Math.abs(dy) > Math.abs(dx)) drag.mode = 'track';
        else if (Math.abs(dx) >= 4) drag.mode = 'pan';
        else return;
        drag.moved = true;
      }
      if (drag.mode === 'track') {
        view.classList.add('moving');
        drag.target.style.transform = `translateY(${dy}px)`;
        drag.target.classList.add('lifted');
        const lane = laneAt(e.clientX, e.clientY);
        for (const l of view.querySelectorAll('.tl-lane.drop')) if (l !== lane) l.classList.remove('drop');
        lane?.classList.add('drop');
        drag.lane = lane;
        return;
      }
      view.classList.add('dragging');
      TL.start = drag.start - (dx / viewW()) * TL.span;
      TL.heldUntil = Date.now() + 2500;
      renderTimeline();
    });
    const up = (e) => {
      if (!drag) return;
      view.classList.remove('dragging', 'moving', 'scrubbing', 'trimming');
      const d = drag;
      drag = null;
      if (d.mode === 'scrub') return;
      if (d.mode === 'out') { saveOut(d.note, d.note.end, d.oldEnd); return; }
      if (d.mode === 'track') {
        for (const l of view.querySelectorAll('.tl-lane.drop')) l.classList.remove('drop');
        const n = S.notes.find((x) => x.id === +d.target.dataset.id);
        const to = d.lane?.dataset.lane;
        if (n && to !== undefined && to !== laneOf(n.tag)) moveToTrack(n, to);
        else renderTimeline();
        return;
      }
      if (d.moved) return;
      if (d.target?.dataset.zoom) {
        const [a, b] = d.target.dataset.zoom.split(',').map(Number);
        const pad = Math.max(rate() * 4, (b - a) * 0.6);
        TL.start = a - pad; TL.span = Math.max(minSpan(), b - a + pad * 2);
        TL.heldUntil = Date.now() + 4000;
        renderTimeline();
        return;
      }
      if (d.target?.dataset.id) { seekFrame(+d.target.dataset.frame, +d.target.dataset.id); return; }
      const r = view.getBoundingClientRect();
      seekFrame(Math.round(TL.start + ((e.clientX - r.left) / r.width) * TL.span));
    };
    view.addEventListener('pointerup', up);
    view.addEventListener('pointercancel', () => {
      if (drag?.mode === 'out') { drag.note.end = drag.oldEnd; renderTimeline(); }
      drag = null; view.classList.remove('dragging', 'moving', 'scrubbing', 'trimming');
    });
    view.addEventListener('wheel', (e) => {
      e.preventDefault();
      const r = view.getBoundingClientRect();
      const ax = e.clientX - r.left;
      if (e.shiftKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
        const d = e.shiftKey && !e.deltaX ? e.deltaY : e.deltaX;
        TL.start += (d / viewW()) * TL.span;
        TL.heldUntil = Date.now() + 2500;
        renderTimeline();
        return;
      }
      const unit = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? 400 : 1;
      zoomTo(TL.span * Math.exp(e.deltaY * unit * (e.ctrlKey ? 0.01 : 0.0022)), TL.start + (ax / viewW()) * TL.span, ax);
    }, { passive: false });

    const over = $('tlOver');
    let overDrag = false;
    const overMove = (e) => {
      const r = over.getBoundingClientRect();
      const f = ((e.clientX - r.left) / r.width) * totalFrames();
      if (TL.span >= totalFrames() - 1) { seekFrame(Math.round(f)); return; }
      TL.start = f - TL.span / 2;
      TL.heldUntil = Date.now() + 2500;
      renderTimeline();
    };
    over.addEventListener('pointerdown', (e) => { overDrag = true; over.setPointerCapture(e.pointerId); overMove(e); });
    over.addEventListener('pointermove', (e) => { if (overDrag) overMove(e); });
    over.addEventListener('pointerup', () => { overDrag = false; });

    $('zoom').addEventListener('input', (e) => {
      const total = totalFrames();
      const lo = Math.min(minSpan(), total);
      const span = total * Math.pow(lo / total, +e.target.value / 1000);
      const anchor = S.frame >= TL.start && S.frame <= TL.start + TL.span ? S.frame : TL.start + TL.span / 2;
      zoomTo(span, anchor);
    });
    $('zoomIn').addEventListener('click', () => zoomBy(1 / 2));
    $('zoomOut').addEventListener('click', () => zoomBy(2));
    $('zoomFit').addEventListener('click', zoomFit);
    new ResizeObserver(() => renderTimeline()).observe(view);
  })();

  /* ---------- the current note ---------- */
  function activeNotes() {
    // The notes at the last note position at or before the playhead (within the filtered set).
    // Notes with an out-point also stay current until it.
    let f = -1;
    for (const n of S.visible) { if (n.frame <= S.frame) f = n.frame; else break; }
    const ranged = S.visible.filter((n) => n.end != null && n.frame <= S.frame && S.frame <= n.end && n.frame !== f);
    if (f < 0 && !ranged.length) return { notes: [], frame: -1 };
    const notes = [...ranged, ...S.visible.filter((n) => n.frame === f)];
    return { notes, frame: ranged.length ? S.frame : f };
  }

  function updateActive() {
    const { notes, frame } = activeNotes();
    const key = notes.map((n) => n.id).join(',') || 'none';
    const stale = frame >= 0 && S.frame - frame > tcBase() * 12;
    $('nowCard').classList.toggle('is-stale', stale);
    document.querySelectorAll('.slate').forEach((s) => s.classList.toggle('stale', stale));
    if (key === S.activeKey && !(document.body.dataset.view === 'playback' && $('screenNotes').dataset.stale !== String(stale))) return;
    $('screenNotes').dataset.stale = String(stale);
    renderScreenNotes(notes, stale);
    if (key === S.activeKey) return;
    S.activeKey = key;
    renderNow(notes);
    document.querySelectorAll('.note.on, .tick.on').forEach((x) => x.classList.remove('on'));
    for (const n of notes) {
      document.querySelectorAll(`[data-id="${n.id}"]`).forEach((x) => x.classList.add('on'));
    }
    if (notes.length && $('follow').checked && Date.now() - S.userScrolledAt > 4000) {
      const li = document.querySelector(`.note[data-id="${notes[0].id}"]`);
      revealIn($('notes'), li, 'nearest');
    }
  }

  // Playback shows the notes at the playhead on the picture, like subtitles.
  function renderScreenNotes(notes, stale) {
    const box = $('screenNotes');
    if (document.body.dataset.view !== 'playback' || stale || !notes.length) { box.replaceChildren(); return; }
    box.replaceChildren(...notes.slice(0, 3).map((n) => el('p', { class: 'sn', style: `--c:${tagColor(n.tag)}` },
      el('span', { class: 'tag', style: `--c:${tagColor(n.tag)}`, text: n.tag || '—' }), ' ',
      el('span', { class: 'sn-text', text: n.text }),
      el('small', { text: ` ${n.author} · ${STATUS[n.status]?.label || ''}` }))));
  }

  function renderNow(notes) {
    const card = $('nowCard');
    // Keep a half-typed reply if the same note is still showing.
    const draft = {};
    card.querySelectorAll('textarea[data-id]').forEach((t) => { if (t.value) draft[t.dataset.id] = t.value; });
    if (!notes.length) {
      card.replaceChildren(el('p', { class: 'now-empty', text: S.notes.length ? 'The current note appears here as the video plays.' : '' }));
      return;
    }
    card.replaceChildren(...notes.map((n) => slate(n, draft[n.id] || '')));
  }

  function shotAt(f) {
    let best = null;
    for (const sh of S.shots) {
      if (sh[0] > f) break;
      if (f < sh[1] && (!best || sh[2] >= best[2])) best = sh;
    }
    return best;
  }

  function placementBanner(n, prev) {
    if (!can('editor')) return null;   // placing notes is the editors' job; reviewers don't need the explanation
    const from = prev.find((h) => h.version_id === S.version.carried_from) || (prev.length ? prev[prev.length - 1] : null);
    const r = REASON[n.reason];
    if (n.confirmed) {
      if (n.method !== 'auto' || !r) return null;
      return el('p', { class: 'placed' }, r.long + ' ',
        can('editor') ? el('button', { type: 'button', class: 'linklike', text: 'Move it to the playhead', onclick: () => pin(n, S.frame) }) : null);
    }
    const text = r ? r.long : `Carried from ${from ? from.label + ' at ' + from.tc : 'an earlier version'}. Its place in this cut isn’t confirmed yet: find the moment, then pin it.`;
    const hint = !r && !S.edit ? ' Import the edit or transcript for both cuts and notes are placed for you.' : '';
    return el('div', { class: 'banner', style: `--c:${tagColor(n.tag)}` },
      el('p', { text: text + hint }),
      !can('editor') ? null : el('div', { class: 'banner-actions' },
        el('button', { type: 'button', class: 'btn', text: 'Pin to playhead', onclick: () => pin(n, S.frame) }),
        el('button', { type: 'button', class: 'btn ghost', text: 'Position is right', onclick: () => pin(n, n.frame) }),
        r?.done && n.status !== 'addressed' ? el('button', { type: 'button', class: 'btn ghost', text: 'Mark addressed', onclick: async () => {
          try {
            await api('note.status', { body: { note_id: n.id, version_id: S.versionId, status: 'addressed', comment: r.short ? `Auto: ${r.short} in ${S.version.label}` : '' } });
            await api('note.position', { body: { note_id: n.id, version_id: S.versionId, frame: n.frame } });
            await refreshKeepPlace();
          } catch (e) { fail(e); }
        } }) : null));
  }

  /** Set or clear a note's out-point in this version, with Undo. */
  async function saveOut(n, end, oldEnd) {
    end = end != null && end > n.frame ? end : null;
    n.end = end;
    renderTimeline();
    try {
      await api('note.range', { body: { note_id: n.id, version_id: S.versionId, end } });
      await refreshKeepPlace();
      if ((oldEnd ?? null) === end) return;
      toast(end != null ? `Ends at ${tc(end)} (${fmtDur(end - n.frame)})` : 'Out-point removed', {
        label: 'Undo', run: async () => { try { await api('note.range', { body: { note_id: n.id, version_id: S.versionId, end: oldEnd ?? null } }); await refreshKeepPlace(); } catch (e) { fail(e); } },
      });
    } catch (e) { n.end = oldEnd; renderTimeline(); fail(e); }
  }
  const fmtDur = (frames) => {
    const s = frames / tcBase();
    return s < 60 ? `${s.toFixed(s < 10 ? 1 : 0)} s` : `${Math.floor(s / 60)} min ${Math.round(s % 60)} s`;
  };

  async function moveToTrack(n, key) {
    const old = n.tag || '';
    const rest = old.split('-').filter((k) => k && k !== firstKey(old) && k !== key);
    const tag = [key, ...rest].filter(Boolean).join('-');
    try {
      await api('note.tag', { body: { note_id: n.id, tag } });
      n.tag = tag;
      await refreshKeepPlace();
      toast(`Moved to ${trackName(key)}: ${n.text.slice(0, 40)}${n.text.length > 40 ? '…' : ''}`, {
        label: 'Undo', run: async () => { try { await api('note.tag', { body: { note_id: n.id, tag: old } }); await refreshKeepPlace(); } catch (e) { fail(e); } },
      });
    } catch (e) { fail(e); renderTimeline(); }
  }

  function trackOptions(current) {
    const opts = S.cats.map((c) => el('option', { value: c.key, text: `${c.key} · ${c.label}`, selected: c.key === current }));
    if (current && !S.cats.some((c) => c.key === current)) opts.unshift(el('option', { value: current, text: current, selected: true }));
    return [...opts, el('option', { value: '', text: '— Untagged', selected: !current })];
  }

  // The note's wording, with Reword for editors (any note) and reviewers (their own notes).
  const canReword = (n) => can('editor') || (can('reviewer') && n.author === S.user);
  function noteText(n) {
    if (S.editingNote === n.id) {
      const ta = el('textarea', { class: 'reword', rows: 3, 'aria-label': 'Note text' });
      ta.value = S.editDraft ?? n.text;
      ta.addEventListener('input', () => { S.editDraft = ta.value; });
      const cancel = () => { S.editingNote = null; S.editDraft = null; S.activeKey = '__force'; updateActive(); };
      const save = async () => {
        const text = ta.value.trim();
        if (!text) { ta.focus(); return; }
        try {
          await api('note.edit', { body: { note_id: n.id, text } });
          S.editingNote = null; S.editDraft = null;
          await refreshKeepPlace();
          toast('Note reworded. The earlier wording is kept in its history.');
        } catch (e) { fail(e); }
      };
      ta.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); save(); }
        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
      });
      setTimeout(() => { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); }, 0);
      return el('div', { class: 'reword-box' }, ta,
        el('div', { class: 'row' },
          el('button', { type: 'button', class: 'btn primary', text: 'Save', title: 'Ctrl+Enter', onclick: save }),
          el('button', { type: 'button', class: 'btn ghost', text: 'Cancel', title: 'Esc', onclick: cancel }),
          n.fio_comment_id ? el('small', { text: 'Changes the wording here only; Frame.io keeps the original.' }) : null));
    }
    return el('div', { class: 'slate-text-row' },
      el('p', { class: 'slate-text', text: n.text }),
      canReword(n) ? el('button', { type: 'button', class: 'linklike reword-btn', text: 'Reword', title: 'Change the wording of this note',
        onclick: () => { setShuttle(0); video.pause(); S.editingNote = n.id; S.editDraft = null; S.activeKey = '__force'; updateActive(); } }) : null);
  }

  function rangeRow(n) {
    const ed = can('editor');
    if (n.end == null && !ed) return null;
    return el('div', { class: 'range-row' },
      n.end != null
        ? el('span', {}, 'Runs to ', el('button', { type: 'button', class: 'slate-tc small', text: tc(n.end), title: 'Go to the out-point', onclick: () => seekFrame(n.end) }), ` · ${fmtDur(n.end - n.frame)}`)
        : el('span', { text: 'A single moment.' }),
      ed ? el('button', { type: 'button', class: 'linklike', text: n.end != null ? 'End at the playhead (O)' : 'Set the end at the playhead (O)', onclick: () => (S.frame > n.frame ? saveOut(n, S.frame, n.end) : toast('Move the playhead past the start of the note first.')) }) : null,
      ed && n.end != null ? el('button', { type: 'button', class: 'linklike', text: 'Remove the end', onclick: () => saveOut(n, null, n.end) }) : null);
  }

  function slate(n, draft) {
    const prev = n.history.filter((h) => h.version_id !== S.versionId);
    const shot = S.shots.length ? shotAt(n.frame) : null;
    const ta = el('textarea', { 'data-id': n.id, rows: 2, placeholder: can('editor') ? 'Reply, or explain a status change' : 'Reply' });
    ta.value = draft;
    const tagSel = !can('editor') ? el('span', { class: 'tag', style: `--c:${tagColor(n.tag)}`, text: n.tag || '—', title: trackName(laneOf(n.tag)) }) : el('select', { class: 'tagsel', style: `--c:${tagColor(n.tag)}`, 'aria-label': 'Track', title: 'Track',
      onchange: (e) => moveToTrack(n, e.target.value) },
    ...trackOptions(n.tag));

    const setStatus = (st) => { const c = ta.value.trim(); ta.value = ''; return changeStatus(n, st, c); };
    const statusBtn = (st) => el('button', {
      type: 'button', class: 'btn seg' + (n.status === st ? ' on' : ''), style: `--sc:${statusColor(st)}`,
      'aria-pressed': n.status === st ? 'true' : 'false',
      title: n.status === st ? `This note is ${STATUS[st].label.toLowerCase()}` : `${STATUS[st].verb}${STATUS_KEYS[st] ? ' (' + STATUS_KEYS[st] + ')' : ''}. Anything typed in the box is saved with it.`,
      onclick: () => setStatus(st),
    }, STATUS[st].verb, STATUS_KEYS[st] ? el('kbd', { text: STATUS_KEYS[st] }) : null);
    const more = STATUS_MORE.filter((st) => st !== n.status && (st !== 'open' || n.status !== 'open'));
    const moreOn = STATUS_MORE.includes(n.status) && n.status !== 'open';
    const reply = async () => {
      const text = ta.value.trim(); if (!text) { ta.focus(); return; }
      try { await api('note.reply', { body: { note_id: n.id, version_id: S.versionId, text } }); ta.value = ''; await refreshKeepPlace(); } catch (e) { fail(e); }
    };
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); reply(); } if (e.key === 'Escape') ta.blur(); });

    return el('section', { class: 'slate', 'data-id': n.id, style: `--c:${tagColor(n.tag)}` },
      el('div', { class: 'slate-head' },
        el('button', { type: 'button', class: 'slate-tc', text: tc(n.frame), title: 'Jump to this note', onclick: () => seekFrame(n.frame, n.id) }),
        tagSel,
        el('span', { class: 'status st-' + n.status, style: `--sc:${statusColor(n.status)}`, text: STATUS[n.status]?.label }),
        el('span', { text: `${n.author} · ${fmtDate(n.created_at)}` }),
        n.carried ? el('span', { class: 'flag' }, swatch(verOf(n.origin_version_id)), ` from ${n.origin_label || 'an earlier version'}`) : null,
        shot ? el('span', { class: 'shot', title: 'Shot at this note, from the edit', text: shot[3] }) : null,
      ),
      noteText(n),
      rangeRow(n),
      placementBanner(n, prev),
      n.replies.length ? el('ul', { class: 'thread' }, ...n.replies.map((r) => el('li', {},
        el('div', { class: 'meta', text: `${r.author} · ${fmtDate(r.created_at)}${r.version_label && r.version_label !== S.version.label ? ' · on ' + r.version_label : ''}` }),
        el('div', { class: 'body', text: r.text })))) : null,
      el('details', { class: 'history' },
        el('summary', { text: `History · seen in ${n.history.length} version${n.history.length > 1 ? 's' : ''}` }),
        el('ul', {},
          ...n.history.map((h) => el('li', {}, swatch(verOf(h.version_id)), ` ${h.label}: ${h.tc}${h.confirmed ? '' : ' (not confirmed)'}`)),
          ...(n.edits || []).map((x) => el('li', { text: `Reworded by ${x.by_name} · ${fmtDate(x.at)} — was “${x.old_text}”` })),
          ...n.events.map((e) => el('li', { text: (e.by_name === 'Frame.io import'
            ? `${STATUS[e.status]?.label || e.status}, from the Frame.io export${e.version_label ? ' of ' + e.version_label : ''}`
            : `${STATUS[e.status]?.label || e.status} by ${e.by_name}${e.version_label ? ' on ' + e.version_label : ''}`)
            + ` · ${fmtDate(e.at)}${e.comment && e.by_name !== 'Frame.io import' ? ' — “' + e.comment + '”' : ''}` })))),
      // Pinned to the bottom of the card, so the decision is always in reach however long the note is.
      !can('reviewer') ? null : el('div', { class: 'actions' },
        ta,
        el('div', { class: 'row' },
          el('button', { type: 'button', class: 'btn', text: 'Reply', title: 'Send the reply (Ctrl+Enter). R puts the cursor in the box.', onclick: reply }),
          can('editor') ? el('span', { class: 'seg-group', role: 'group', 'aria-label': 'Status' },
            ...STATUS_MAIN.map(statusBtn),
            el('details', { class: 'pop up more' + (moreOn ? ' on' : '') },
              el('summary', { class: 'btn seg' + (moreOn ? ' on' : ''), style: moreOn ? `--sc:${statusColor(n.status)}` : '', text: moreOn ? STATUS[n.status].label : 'More' }),
              el('div', { class: 'menu-pop', role: 'menu' }, ...more.map((st) => el('button', { type: 'button', role: 'menuitem', text: STATUS[st].verb, onclick: () => setStatus(st) }))))) : null)),
    );
  }

  // Change a note's status, with Undo (puts the previous status back).
  async function changeStatus(n, st, comment = '') {
    if (!n || n.status === st) return;
    const before = n.status;
    try {
      await api('note.status', { body: { note_id: n.id, version_id: S.versionId, status: st, comment } });
      toast(`${STATUS[st].label}: ${n.text.slice(0, 48)}${n.text.length > 48 ? '…' : ''}`, {
        label: 'Undo', run: async () => { try { await api('note.status', { body: { note_id: n.id, version_id: S.versionId, status: before, comment: 'Undo' } }); await refreshKeepPlace(); } catch (e) { fail(e); } },
      });
      await refreshKeepPlace();
    } catch (e) { fail(e); }
  }
  // The note the status keys act on: the first one in the card under the picture.
  const cardNote = () => { const id = +$('nowCard').querySelector('.slate')?.dataset.id; return S.notes.find((x) => x.id === id) || null; };

  async function pin(n, frame) {
    try {
      await api('note.position', { body: { note_id: n.id, version_id: S.versionId, frame } });
      toast(`Placed at ${tc(frame)}`);
      await refreshKeepPlace();
    } catch (e) { fail(e); }
  }

  async function refreshKeepPlace() {
    const scroll = $('notes').scrollTop;
    await loadNotes();
    $('notes').scrollTop = scroll;
    updateScriptActive(true);
    S.versions = (await api('state')).versions;
    renderPickers();
  }

  /* ---------- video ---------- */
  const video = $('video');
  let vfcSupported = 'requestVideoFrameCallback' in HTMLVideoElement.prototype;
  const rateProbe = { last: null, sum: 0, n: 0, asked: false };

  // Show the video choices over the picture, with an optional explanation (used when a video won't play,
  // and by "Change video").
  function showSourceChoice(why) {
    const screen = $('screen');
    screen.classList.add('choosing');
    $('screenWhy').textContent = why || '';
    $('screenWhy').hidden = !why;
    $('keepVideoBtn').hidden = !!why || !screen.classList.contains('has-video');
    $('removeLinkBtn').hidden = !S.version?.video_url || !can('editor');
    video.pause();
  }
  function hideSourceChoice() { $('screen').classList.remove('choosing'); $('screenWhy').hidden = true; }

  function setSource(src) {
    const screen = $('screen');
    hideSourceChoice();
    if (!src) {
      video.dataset.remote = '';
      video.removeAttribute('src'); video.load();
      screen.classList.remove('has-video');
      const last = S.versionId && ls.get('file:' + S.versionId);
      $('screenEmpty').querySelector('p').textContent = last
        ? `Open the video for this version. Last time it was “${last}”.`
        : 'Open the video for this version to play it with the notes.';
      S.frame = 0; tick();
      return;
    }
    video.dataset.remote = src.remote ? '1' : '';
    $('quality').hidden = !src.remote;
    if (video.getAttribute('src') !== src.url) {
      video.src = src.url;
      rateProbe.last = null; rateProbe.sum = 0; rateProbe.n = 0; rateProbe.asked = false;
    }
    screen.classList.add('has-video');
  }

  function frameFromTime(t) { return Math.floor(t * rate() + 1e-3); }

  function tick(mediaTime) {
    if (video.src && video.readyState >= 1) {
      S.frame = frameFromTime(mediaTime ?? video.currentTime);
    }
    $('tcNow').textContent = tc(S.frame);
    const running = !video.paused || !!shuttle.rev;
    if (running !== S.wasRunning) {
      S.wasRunning = running;
      $('playBtn').classList.toggle('playing', running);
      $('playBtn').setAttribute('aria-label', running ? 'Pause (space or K)' : 'Play (space or L)');
      if (!running && shuttle.rate) { shuttle.rate = 0; showSpeed(shuttle.base); }
    }
    moveHead();
    updateActive();
    updateScriptActive();
  }

  function onFrame(now, meta) {
    tick(meta.mediaTime);
    probeRate(meta);
    video.requestVideoFrameCallback(onFrame);
  }
  if (vfcSupported) video.requestVideoFrameCallback(onFrame);
  else {
    const loop = () => { if (!video.paused) tick(); requestAnimationFrame(loop); };
    requestAnimationFrame(loop);
  }
  ['seeked', 'pause', 'play', 'loadedmetadata', 'timeupdate'].forEach((ev) => video.addEventListener(ev, () => tick()));
  video.addEventListener('loadedmetadata', () => { TL.span = 0; renderTimeline(); checkStartTc(); });
  let lastRefetch = 0;
  video.addEventListener('error', () => {
    if (!video.getAttribute('src')) return;
    // Frame.io links expire: fetch a fresh one and carry on from the same frame.
    if (video.dataset.remote === '1' && Date.now() - lastRefetch > 30000) {
      lastRefetch = Date.now();
      streamFrameio(S.frame).catch(fail);
      return;
    }
    const why = video.dataset.remote === '1'
      ? 'Frame.io’s video won’t play in this browser. Try another quality, or download the file and open it.'
      : S.version?.video_url && video.getAttribute('src') === S.version.video_url
        ? 'The saved video link doesn’t play: it may be a web page rather than the video file, or it may have expired. Use another link, or open a file.'
        : 'This video won’t play in the browser. Use an H.264 MP4.';
    showSourceChoice(why);
  });

  // Notice if the file runs at 23.976 / 29.97 while the version is set to 24 / 30 (or the reverse).
  function probeRate(meta) {
    if (video.paused || rateProbe.asked || video.playbackRate !== 1) { rateProbe.last = null; return; }
    if (rateProbe.last && meta.presentedFrames === rateProbe.last.pf + 1) {
      const dt = meta.mediaTime - rateProbe.last.mt;
      if (dt > 0 && dt < 0.1) { rateProbe.sum += dt; rateProbe.n++; }
    }
    rateProbe.last = { pf: meta.presentedFrames, mt: meta.mediaTime };
    if (rateProbe.n >= 240) {
      const measured = rateProbe.n / rateProbe.sum;
      const std = [23.976, 24, 25, 29.97, 30, 50, 59.94, 60].reduce((a, b) => (Math.abs(b - measured) < Math.abs(a - measured) ? b : a));
      rateProbe.asked = true;
      if (Math.abs(std - rate()) > 0.01 && Math.abs(std - measured) / std < 0.002) {
        toast(`This file plays at ${std} fps, but the version is set to ${rate()}. Notes will drift.`, {
          label: `Use ${std}`,
          run: () => saveVersion({ rate: std }),
        });
      }
    }
  }

  function seekFrame(frame, noteId) {
    S.frame = Math.max(0, frame);
    if (video.src && video.readyState >= 1) {
      video.currentTime = (frame + 0.5) / rate();
    }
    if (noteId) S.userScrolledAt = 0;
    tick();
  }

  /* ---------- Shuttle: J / K / L, like an edit suite ----------
     L plays forward, and each press goes faster (1×, 2×, 4×, 8×); J does the same backwards; K stops.
     Holding K while tapping J or L steps one frame. The speed menu shows (and sets) the current speed. */
  function stopReverse() {
    if (!shuttle.rev) return;
    cancelAnimationFrame(shuttle.rev.raf);
    video.removeEventListener('seeked', shuttle.rev.onSeeked);
    shuttle.rev = null;
  }
  function startReverse(speed) {
    stopReverse();
    video.pause();
    // Browsers can't play video backwards, so step the picture back in time as fast as it can seek.
    const r = { target: video.currentTime, last: performance.now(), pending: false, raf: 0 };
    r.onSeeked = () => { r.pending = false; };
    video.addEventListener('seeked', r.onSeeked);
    const step = (now) => {
      if (shuttle.rev !== r) return;
      r.target -= ((now - r.last) / 1000) * speed;
      r.last = now;
      if (r.target <= 0) { video.currentTime = 0; setShuttle(0); return; }
      if (!r.pending) { r.pending = true; video.currentTime = r.target; }
      r.raf = requestAnimationFrame(step);
    };
    shuttle.rev = r;
    r.raf = requestAnimationFrame(step);
  }
  function setShuttle(rate) {
    if (!video.src) return;
    shuttle.rate = rate;
    if (rate > 0) {
      stopReverse();
      video.playbackRate = rate;
      if (video.paused) video.play().catch(fail);
    } else if (rate < 0) {
      startReverse(-rate);
    } else {
      stopReverse();
      video.pause();
    }
    // The menu shows the speed while J/L shuttle; stopping puts back the speed chosen in the menu.
    showSpeed(rate || shuttle.base);
    tick();
  }
  function showSpeed(rate) {
    const sel = $('speed');
    if (![...sel.options].some((o) => +o.value === rate)) return;
    sel.value = String(rate);
    $('speedLbl').textContent = sel.selectedOptions[0]?.text || '1×';
  }
  function shuttleKey(dir) {
    if (!video.src) return;
    const cur = shuttle.rate;
    if (Math.sign(cur) !== dir) { setShuttle(dir); return; }  // stopped or going the other way: start at 1×
    const i = SHUTTLE.indexOf(Math.abs(cur));
    setShuttle(dir * SHUTTLE[Math.min(SHUTTLE.length - 1, (i < 0 ? 0 : i + 1))]);
  }
  function playPause() {
    if (!video.src) return;
    if (!video.paused || shuttle.rev) setShuttle(0);
    else setShuttle(shuttle.base);
  }
  // Rapid steps build on the last requested frame, not on a seek that hasn't landed yet.
  let stepAt = { frame: 0, t: 0 };
  function stepFrames(d) {
    if (!video.src) return;
    if (!video.paused || shuttle.rev) setShuttle(0);
    const from = performance.now() - stepAt.t < 400 ? stepAt.frame : S.frame;
    stepAt = { frame: Math.max(0, from + d), t: performance.now() };
    seekFrame(stepAt.frame);
  }
  function jumpNote(dir) {
    const list = S.visible;
    if (!list.length) return;
    let target;
    if (dir > 0) target = list.find((n) => n.frame > S.frame);
    else {
      const af = activeNotes().frame;
      // Back to the start of the current note first, like a previous-track button.
      if (af >= 0 && S.frame - af > tcBase()) target = list.find((n) => n.frame === af);
      else target = [...list].reverse().find((n) => n.frame < (af >= 0 ? af : S.frame));
    }
    if (target) seekFrame(target.frame, target.id);
  }

  $('fileInput').addEventListener('change', (e) => {
    const f = e.target.files[0];
    if (!f || !S.versionId) return;
    const old = S.sources.get(S.versionId);
    if (old?.local) URL.revokeObjectURL(old.url);
    const src = { url: URL.createObjectURL(f), name: f.name, local: true };
    S.sources.set(S.versionId, src);
    ls.set('file:' + S.versionId, f.name);
    setSource(src);
    e.target.value = '';
  });
  $('urlBtn').addEventListener('click', openVideoLink);
  $('changeVideoBtn').addEventListener('click', () => showSourceChoice());
  $('keepVideoBtn').addEventListener('click', hideSourceChoice);
  $('removeLinkBtn').addEventListener('click', async () => {
    try { await saveVersion({ video_url: '' }); S.sources.delete(S.versionId); setSource(null); toast('Video link removed.'); } catch (err) { fail(err); }
  });

  /* ---------- transcript ---------- */
  async function loadScript(transcriptId) {
    if (!S.versionId) return;
    const vid = S.versionId;
    const d = await api('transcript', { query: { version_id: vid, ...(transcriptId ? { transcript_id: transcriptId } : {}) } });
    if (vid !== S.versionId) return;
    S.script = { uploads: d.uploads, segments: d.segments, gone: d.gone || [], compare: d.compare, activeId: d.active_id ?? null, latestId: d.latest_id ?? null, loadedFor: vid };
    S.editingLine = null;
    await loadScriptEdits(false);
    renderScript();
  }

  async function loadScriptEdits(render = true) {
    S.scriptEdits = { transcriptId: null, lines: new Map() };
    if (!S.versionId || !S.script.segments.length) return;
    const r = await api('script', { query: { version_id: S.versionId } });
    setScriptLines(r.transcript_id, r.lines);
    if (render) renderScript();
  }
  function setScriptLines(tid, lines) {
    S.scriptEdits = { transcriptId: tid, lines: new Map(lines.map((l) => [`${l.i}:${l.slot}`, l])) };
  }

  function segmentIndexAt(f) {
    const segs = S.script.segments;
    let lo = 0, hi = segs.length - 1, ans = -1;
    while (lo <= hi) {
      const mid = (lo + hi) >> 1;
      if (segs[mid].start <= f) { ans = mid; lo = mid + 1; } else hi = mid - 1;
    }
    return ans;
  }

  function renderScript() {
    const sc = S.script;
    $('tabScriptN').textContent = sc.segments.length ? sc.segments.length : '';
    const sel = $('scriptUpload');
    sel.replaceChildren(...(sc.uploads.length ? sc.uploads.map((u) => el('option', {
      value: u.id, selected: +u.id === sc.activeId,
      text: `${fmtDate(u.uploaded_at)} · ${u.filename}${+u.id === sc.latestId ? ' (current)' : ''}`,
    })) : [el('option', { text: 'No transcript yet' })]));
    sel.disabled = sc.uploads.length < 2;
    const changes = !!sc.compare;
    $('scriptChangesWrap').hidden = !changes;
    $('scriptChangesLabel').textContent = changes ? `Show changes since ${sc.compare}` : 'Show changes';
    const note = $('scriptNote');
    const older = sc.activeId && sc.activeId !== sc.latestId;
    note.hidden = !older && sc.uploads.length > 0;
    note.replaceChildren(...(older
      ? [el('span', { text: 'You’re looking at an older upload. Notes are placed using the current one. ' }),
         el('button', { type: 'button', class: 'linklike', text: 'Delete this upload', onclick: () => deleteScript(sc.activeId) })]
      : !sc.uploads.length ? [el('span', { text: S.versionId ? 'Upload a transcript (.srt, .vtt, or text with timecodes) to read along and to place narration notes by their line.' : '' })] : []));

    const gone = $('scriptGone');
    gone.hidden = !(changes && $('scriptChanges').checked && sc.gone.length);
    if (!gone.hidden) {
      gone.querySelector('summary').textContent = `${sc.gone.length} line${sc.gone.length > 1 ? 's' : ''} from ${sc.compare} ${sc.gone.length > 1 ? 'aren’t' : 'isn’t'} in this cut`;
      gone.querySelector('ul').replaceChildren(...sc.gone.map((g) => el('li', { text: g.text })));
    }

    const q = $('scriptSearch').value.trim().toLowerCase();
    const showChanges = changes && $('scriptChanges').checked;
    const ol = $('script');
    const wide = S.scriptWide && sc.segments.length && sc.activeId === sc.latestId && S.scriptEdits.transcriptId;
    document.getElementById('workspace').classList.toggle('script-wide', !!(S.scriptWide && S.tab === 'script'));
    $('scriptWideBtn').setAttribute('aria-pressed', String(!!S.scriptWide));
    $('scriptWideBtn').textContent = S.scriptWide ? 'Back to the list' : 'Script view';
    const cur = sc.uploads.find((u) => +u.id === sc.activeId);
    $('scriptSetupSum').textContent = cur ? `Transcript: ${cur.filename}` : 'Transcript files';
    if (!sc.uploads.length && can('editor')) $('scriptSetup').open = true;
    $('scriptWideBtn').hidden = !sc.segments.length;
    $('scriptDl').hidden = !wide;
    if (!sc.segments.length) { ol.replaceChildren(); $('caption').hidden = true; return; }
    const keepScroll = ol.scrollTop;
    const rows = [];
    if (wide) rows.push(el('li', { class: 'tline wide thead', 'aria-hidden': 'true' }, el('span'), el('span', {}, swatch(S.version), ` Now in ${S.version?.label || 'this cut'}`), el('span', { text: 'Notes' }), el('span', { text: 'New script' })));
    sc.segments.forEach((g, i) => {
      const extra = wide ? [...S.scriptEdits.lines.values()].filter((l) => l.i === i && l.slot > 0 && l.state !== 'removed').sort((a, b) => a.slot - b.slot) : [];
      const own = S.scriptEdits.lines.get(`${i}:0`);
      if (q && !(g.text + ' ' + g.speaker + ' ' + (own?.text || '') + extra.map((x) => x.text).join(' ')).toLowerCase().includes(q)) return;
      const ch = showChanges && g.change && g.change !== 'same' ? g.change : '';
      const notesBox = el('div', { class: 'seg-notes', 'data-i': i });
      rows.push(el('li', { class: 'tline' + (wide ? ' wide' : '') + (ch ? ' ch-' + ch + ' revised' : ''), 'data-i': i, tabindex: '0',
        onclick: (e) => { if (!e.target.closest('.nchip, details, .cell-new, .cell-notes, button, textarea, input')) seekFrame(g.start); },
        onkeydown: (e) => { if (e.key === 'Enter' && e.target === e.currentTarget) seekFrame(g.start); } },
        el('span', { class: 'seg-tc', text: tc(g.start) }),
        el('div', { class: 'seg-main' },
          g.speaker ? el('button', { type: 'button', class: 'seg-speaker', text: g.speaker, title: can('editor') ? 'Rename this speaker throughout' : g.speaker, onclick: (e) => { e.stopPropagation(); if (can('editor')) renameSpeaker(g.speaker); } }) : null,
          el('p', { class: 'seg-text' + (own?.state === 'cut' ? ' struck' : ''), text: g.text }),
          ch === 'new' ? el('span', { class: 'seg-change', text: 'new line' }) : null,
          ch === 'reworded' ? el('details', { class: 'seg-change' }, el('summary', { text: 'reworded' }), el('p', { text: g.prev })) : null,
          wide ? null : notesBox),
        wide ? el('div', { class: 'cell-notes' }, notesBox, addNoteBox(g)) : null,
        wide ? newCell(i, 0, g) : null));
      for (const x of extra) {
        rows.push(el('li', { class: 'tline wide added', 'data-after': i },
          el('span', { class: 'seg-tc', text: '+' }),
          el('div', { class: 'seg-main' }, el('span', { class: 'seg-change', text: 'New line after ' + tc(g.start) })),
          el('div', { class: 'cell-notes' }),
          newCell(i, x.slot, g)));
      }
      if (wide && S.editingLine === `${i}:new`) {
        rows.push(el('li', { class: 'tline wide added' }, el('span', { class: 'seg-tc', text: '+' }),
          el('div', { class: 'seg-main' }, el('span', { class: 'seg-change', text: 'New line after ' + tc(g.start) })), el('div', { class: 'cell-notes' }), newCell(i, 'new', g)));
      }
    });
    ol.replaceChildren(...rows);
    ol.scrollTop = keepScroll;
    renderScriptNotes();
    updateScriptActive(true);
    ol.querySelector('.cell-new textarea')?.focus();
  }

  const LINE_LABEL = { same: '', draft: 'Draft', approved: 'Approved', cut: 'Cut', removed: 'Removed' };
  function newCell(i, slot, g) {
    const key = `${i}:${slot}`;
    const L = slot === 'new' ? null : S.scriptEdits.lines.get(key);
    const state = L?.state || 'same';
    const cell = el('div', { class: `cell-new st-${state}` });
    const save = async (st, text) => {
      try {
        const r = await api('script.edit', { body: { version_id: S.versionId, transcript_id: S.scriptEdits.transcriptId, seg_idx: i, slot, state: st, text: text ?? '' } });
        setScriptLines(S.scriptEdits.transcriptId, r.lines);
        S.editingLine = null;
        renderScript();
      } catch (e) { fail(e); }
    };
    if (S.editingLine === key) {
      const ta = el('textarea', { rows: 3, placeholder: slot ? 'Write the new line' : 'Rewrite this line',
        onkeydown: (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); save(e.shiftKey && can('editor') ? 'approved' : 'draft', ta.value); } if (e.key === 'Escape') { S.editingLine = null; renderScript(); } } });
      ta.value = L && ['draft', 'approved'].includes(state) ? L.text : (slot === 0 ? g.text : '');
      cell.append(ta, el('div', { class: 'line-actions' },
        el('button', { type: 'button', class: 'btn primary', text: 'Save draft', title: 'Ctrl+Enter', onclick: () => save('draft', ta.value) }),
        can('editor') ? el('button', { type: 'button', class: 'btn', text: 'Save as approved', title: 'Ctrl+Shift+Enter', onclick: () => save('approved', ta.value) }) : null,
        el('button', { type: 'button', class: 'btn ghost', text: 'Cancel', onclick: () => { S.editingLine = null; renderScript(); } })));
      return cell;
    }
    const edit = (k = key) => { S.editingLine = k; renderScript(); };
    if (state === 'same') cell.append(el('span', { class: 'same', text: 'Same as now' }));
    else if (state === 'cut') cell.append(el('p', { class: 'new-text', text: 'Cut this line' }));
    else cell.append(el('p', { class: 'new-text', text: L.text }));
    if (L && state !== 'same') {
      cell.append(el('div', { class: 'line-meta' },
        el('span', { class: 'line-state', text: LINE_LABEL[state] }), ` ${L.by} · ${fmtDate(L.at)}`,
        L.recorded ? el('button', { type: 'button', class: 'recorded', text: `Recorded in ${L.recorded.label}`, title: 'It’s in that cut’s transcript' }) : null));
    }
    const B = (text, fn, cls = 'btn ghost') => el('button', { type: 'button', class: cls, text, onclick: fn });
    const acts = [];
    if (!can('editor')) {
      // Reviewers suggest wording as drafts; viewers only read.
      if (can('reviewer')) {
        if (state === 'same') acts.push(B('Suggest a rewrite', () => edit()));
        if (state === 'draft') acts.push(B('Edit draft', () => edit()));
        if (slot === 0 && state !== 'cut') acts.push(B('+ Line after', () => edit(`${i}:new`)));
      }
    } else {
    if (state === 'same') acts.push(B('Rewrite', () => edit()), B('Cut', () => save('cut', '')));
    if (state === 'draft') acts.push(B('Edit', () => edit()), B('Approve', () => save('approved', L.text), 'btn'), slot ? B('Remove', () => save('removed', '')) : B('Back to original', () => save('same', '')));
    if (state === 'approved') acts.push(B('Edit', () => edit()), B('Unapprove', () => save('draft', L.text)));
    if (state === 'cut') acts.push(B('Restore', () => save('same', '')));
    if (slot === 0 && state !== 'cut') acts.push(B('+ Line after', () => edit(`${i}:new`)));
    }
    if (acts.length) cell.append(el('div', { class: 'line-actions' }, ...acts));
    if (L && L.history.length > 1) {
      cell.append(el('details', { class: 'line-history' },
        el('summary', { text: `History (${L.history.length})` }),
        el('ol', {}, ...[...L.history].reverse().map((h, k) => el('li', {},
          el('span', { class: 'line-state', text: LINE_LABEL[h.state] || 'Original' }), ` ${h.by} · ${fmtDate(h.at)}`,
          h.text ? el('p', { text: h.text }) : null,
          k > 0 && h.text && (can('editor') || (can('reviewer') && state === 'draft')) ? B('Use this wording', () => save('draft', h.text)) : null)))));
    }
    return cell;
  }

  function addNoteBox(g) {
    if (!can('reviewer')) return null;
    const narration = S.cats.find((c) => c.kind === 'narration') || S.cats[0];
    const input = el('input', { type: 'text', placeholder: `+ Note on this line${narration ? ' (' + narration.key + ')' : ''}`, 'aria-label': 'Add a note on this line',
      onkeydown: async (e) => {
        if (e.key !== 'Enter' || !input.value.trim()) return;
        try {
          await api('note.create', { body: { version_id: S.versionId, frame: g.start, text: input.value.trim(), tag: narration?.key || '' } });
          input.value = '';
          await refreshKeepPlace();
          toast('Note added on this line.');
        } catch (err) { fail(err); }
      } });
    return el('div', { class: 'add-note' }, input);
  }

  // Notes that fall within each line (or in the gap before the next one).
  function renderScriptNotes() {
    const segs = S.script.segments;
    if (!segs.length) return;
    const by = new Map();
    for (const n of S.visible) {
      const i = segmentIndexAt(n.frame);
      if (i < 0) continue;
      if (!by.has(i)) by.set(i, []);
      by.get(i).push(n);
    }
    for (const box of document.querySelectorAll('.seg-notes')) {
      const list = by.get(+box.dataset.i) || [];
      box.replaceChildren(...list.map((n) => el('button', {
        type: 'button', class: 'nchip' + (isDone(n) ? ' done' : ''), style: `--c:${tagColor(n.tag)}`,
        title: n.text, onclick: () => seekFrame(n.frame, n.id),
      }, el('b', { text: n.tag || '·' }), ' ', n.text.length > 42 ? n.text.slice(0, 40) + '…' : n.text)));
    }
  }

  let scriptIdx = -2;
  function updateScriptActive(force) {
    const segs = S.script.segments;
    const cap = $('caption');
    if (!segs.length) { cap.hidden = true; scriptIdx = -2; return; }
    const i = segmentIndexAt(S.frame);
    const inside = i >= 0 && S.frame < segs[i].end;
    cap.hidden = false;
    cap.classList.toggle('quiet', !inside);
    cap.replaceChildren(...(inside
      ? [segs[i].speaker ? el('b', { text: segs[i].speaker + '  ' }) : '', segs[i].text]
      : [el('span', { text: i + 1 < segs.length ? `Next line at ${tc(segs[i + 1].start)}` : '' })]));
    if (i === scriptIdx && !force) return;
    scriptIdx = i;
    for (const x of document.querySelectorAll('.tline.now')) x.classList.remove('now');
    const li = document.querySelector(`.tline[data-i="${i}"]`);
    if (!li) return;
    li.classList.add('now');
    if (S.tab === 'script' && $('scriptFollow').checked && Date.now() - S.scriptScrolledAt > 4000) {
      revealIn($('script'), li, 'center');
    }
  }

  async function uploadScript(versionId, file) {
    const form = new FormData(); form.append('version_id', versionId); form.append('transcript', file);
    const r = await api('version.transcript', { form });
    if (versionId === S.versionId) await loadScript();
    await refreshKeepPlace();
    const moved = r.offset_tc ? ` It started at ${r.offset_tc}, so that’s treated as the start of the cut.` : '';
    toast(`Transcript added: ${r.segments} lines.${moved}` + placementSummary(r.placement, versionId));
  }

  async function renameSpeaker(from) {
    const to = prompt(`Rename “${from}” everywhere in this transcript to:`, from);
    if (!to || to.trim() === from) return;
    try { await api('transcript.speaker', { body: { transcript_id: S.script.activeId, from, to: to.trim() } }); await loadScript(S.script.activeId); } catch (e) { fail(e); }
  }

  async function deleteScript(id) {
    if (!confirm('Delete this transcript upload? Other uploads are kept.')) return;
    try { const r = await api('transcript.delete', { body: { transcript_id: id } }); await loadScript(); await refreshKeepPlace(); toast('Upload deleted.' + placementSummary(r.placement, S.versionId)); } catch (e) { fail(e); }
  }

  function placementSummary(placement, versionId) {
    if (!placement || Array.isArray(placement) || !Object.keys(placement).length) {
      const v = S.versions.find((x) => x.id === versionId);
      const parent = v && S.versions.find((x) => x.id === v.carried_from);
      const child = S.versions.find((x) => x.carried_from === versionId);
      if (parent) return ` Add the same kind of file to ${parent.label} too, and carried notes are placed automatically.`;
      if (child) return ` Add the same kind of file to ${child.label} and its carried notes are placed automatically.`;
      return '';
    }
    return Object.entries(placement).map(([vid, p]) => {
      const v = S.versions.find((x) => x.id === +vid);
      const parts = [`${p.placed} of ${p.total} carried notes placed automatically`];
      if (p.review) parts.push(`${p.review} to check`);
      if (p.unplaced) parts.push(`${p.unplaced} still to place${v && !v.edit ? ' (import this cut’s edit to place picture notes)' : ''}`);
      return ` In ${v ? v.label : 'the next version'}: ${parts.join(', ')}.`;
    }).join('');
  }

  function setTab(t) {
    S.tab = t;
    ls.set('tab', t);
    $('tabNotes').setAttribute('aria-selected', String(t === 'notes'));
    $('tabScript').setAttribute('aria-selected', String(t === 'script'));
    $('panelNotes').hidden = t !== 'notes';
    $('panelScript').hidden = t !== 'script';
    document.getElementById('workspace').classList.toggle('script-wide', !!(S.scriptWide && t === 'script'));
    if (t === 'script') updateScriptActive(true);
  }

  /* ---------- dialogs ---------- */
  const dlg = $('dlg');
  function showDialog(...kids) { dlg.replaceChildren(...kids.filter((k) => k != null && k !== false)); if (!dlg.open) dlg.showModal(); }
  const closeDialog = () => dlg.close();

  // Frame.io page links (share, review, view) open a web page, not a video file.
  const isFrameioPage = (u) => {
    try { const h = new URL(u).hostname; return /(^|\.)frame\.io$/i.test(h) || /(^|\.)f\.io$/i.test(h); } catch { return false; }
  };
  // Check that a link really plays as video here before saving it.
  function probeVideo(url) {
    return new Promise((resolve) => {
      const v = document.createElement('video');
      v.muted = true; v.preload = 'metadata';
      const done = (ok) => { clearTimeout(timer); v.removeAttribute('src'); v.load(); resolve(ok); };
      const timer = setTimeout(() => done(false), 12000);
      v.addEventListener('loadedmetadata', () => done(true), { once: true });
      v.addEventListener('error', () => done(false), { once: true });
      v.src = url;
    });
  }
  function openVideoLink() {
    if (!S.versionId) return;
    const had = S.version.video_url || '';
    const input = el('input', { type: 'url', name: 'url', placeholder: 'https://…', value: had });
    const msg = el('div', { class: 'link-msg', role: 'status' });
    const use = el('button', { class: 'btn primary', text: 'Use this link' });
    const save = async (url) => {
      // Editors save the link on the version for everyone; others use it just here.
      if (can('editor')) await saveVersion({ video_url: url });
      S.sources.delete(S.versionId);
      setSource(url ? { url, name: 'Video link' } : null);
      closeDialog();
    };
    showDialog(
      el('h2', { text: 'Use a video link' }),
      el('p', { text: 'Paste a direct link to the video file itself (an MP4 or MOV), for example a download link. Links that open a web page, like a Frame.io share or review link, won’t play here.' }),
      el('form', { method: 'dialog', onsubmit: async (e) => {
        e.preventDefault();
        const url = input.value.trim();
        msg.replaceChildren();
        if (!url) { try { await save(''); } catch (err) { fail(err); } return; }
        if (isFrameioPage(url)) {
          // A Frame.io page: offer to link the version instead, which streams the real video.
          msg.replaceChildren(el('p', { class: 'err', text: 'That’s a Frame.io page, not a video file, so it can’t play here.' }),
            S.conn.frameio && can('editor')
              ? el('p', {}, 'Link this version to it instead: the video then plays straight from Frame.io and the comments come across too. ',
                el('button', { type: 'button', class: 'btn', text: 'Link to Frame.io', onclick: () => {
                  const v = S.versions.find((x) => x.id === S.versionId);
                  openLinkDialog(v, () => streamFrameio().catch(fail), url);
                } }))
              : el('p', { text: S.conn.frameio ? 'Ask an editor to link this version to Frame.io, or download the video from Frame.io and use Open a video file.' : 'Download the video from Frame.io and use Open a video file instead (or ask an admin to connect Frame.io in Settings).' }));
          return;
        }
        use.disabled = true; use.textContent = 'Checking the link…';
        const ok = await probeVideo(url);
        use.disabled = false; use.textContent = 'Use this link';
        if (!ok) {
          msg.replaceChildren(el('p', { class: 'err', text: 'That link doesn’t play as a video in this browser. It may be a web page rather than the file, it may have expired, or the format may not be supported (H.264 MP4 works best).' }));
          return;
        }
        try { await save(url); } catch (err) { fail(err); }
      } },
      el('label', {}, 'Video link', input),
      msg,
      el('div', { class: 'dlg-actions' },
        had ? el('button', { type: 'button', class: 'btn ghost danger left', text: 'Remove this link', onclick: async () => {
          try { await save(''); toast('Video link removed.'); } catch (err) { fail(err); }
        } }) : null,
        el('button', { type: 'button', class: 'btn ghost', text: 'Cancel', onclick: closeDialog }),
        use)));
    input.focus();
    input.select();
  }

  async function saveVersion(fields) {
    await api('version.update', { body: { version_id: S.versionId, ...fields } });
    Object.assign(S.version, fields);
    const v = S.versions.find((x) => x.id === S.versionId);
    if (v) Object.assign(v, fields);
    renderPickers();
    applyFilters();
    tick();
  }

  function openAddNote() {
    if (!S.versionId) return;
    if (!video.paused) video.pause();
    const at = S.frame;
    const text = el('textarea', { name: 'text', rows: 4, required: true, placeholder: 'What should change here?' });
    const tag = el('select', { name: 'tag' }, ...trackOptions(S.cats[0]?.key || ''));
    showDialog(
      el('h2', { text: `Add a note at ${tc(at)}` }),
      el('form', { method: 'dialog', onsubmit: async (e) => {
        e.preventDefault();
        try {
          await api('note.create', { body: { version_id: S.versionId, frame: at, text: text.value, tag: tag.value } });
          closeDialog();
          await refreshKeepPlace();
          toast('Note added');
        } catch (err) { fail(err); }
      } },
      el('label', {}, 'Track', tag),
      el('label', {}, 'Note', text),
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost', text: 'Cancel', onclick: closeDialog }),
        el('button', { class: 'btn primary', text: 'Add note' }))));
    text.focus();
  }

  function openManage() {
    const film = S.films.find((f) => f.id === S.filmId);
    const versions = filmVersions();

    const filmName = el('input', { name: 'name', required: true, maxlength: 200, placeholder: 'e.g. Rise from the Ashes' });
    const newFilm = el('form', { onsubmit: async (e) => {
      e.preventDefault();
      try { const r = await api('film.create', { body: { name: filmName.value } }); closeDialog(); await loadState({ filmId: r.id }); openManage(); } catch (err) { fail(err); }
    } }, el('div', { class: 'two' }, el('label', {}, 'Film name', filmName), el('div', { class: 'dlg-actions', style: 'align-items:end' }, el('button', { class: 'btn', text: 'Add film' }))));

    const parts = [el('h2', { text: film ? film.name : 'Films & versions' })];

    if (film) {
      const label = el('input', { name: 'label', required: true, maxlength: 200, placeholder: 'e.g. Part 1 · Oct 21 cut' });
      const carry = el('select', { name: 'carry' },
        el('option', { value: '', text: 'Start empty' }),
        ...[...versions].reverse().map((v, i) => el('option', { value: v.id, text: `Carry unfinished notes from ${v.label}`, selected: i === 0 })));
      const fioPick = el('select', { name: 'fio' }, el('option', { value: '', text: 'Don’t link yet' }));
      const colorPick = el('select', { name: 'color' }, ...REV.map((c, i) => el('option', { value: c, text: `${revName(c)} pages`, selected: i === versions.length % REV.length })));
      const fioWrap = el('label', { hidden: true }, 'Frame.io file', fioPick);
      const loadStack = async () => {
        const parent = S.versions.find((x) => x.id === +carry.value);
        fioWrap.hidden = true;
        if (!S.conn.frameio || !parent?.fio_stack_id) return;
        try {
          const r = await api('fio.stack', { query: { version_id: parent.id } });
          const files = (r.stack?.files || []).filter((f) => !f.linked_to);
          fioPick.replaceChildren(el('option', { value: '', text: 'Don’t link yet' }), ...files.map((f, i) => el('option', {
            value: f.id, 'data-name': f.name, 'data-stack': r.stack.id, selected: i === files.length - 1, text: `${f.name} · ${fmtDate(f.created_at)}`,
          })));
          fioWrap.hidden = !files.length;
        } catch { /* stack unavailable: link by hand later */ }
      };
      carry.addEventListener('change', loadStack);
      loadStack();
      const newVersion = el('form', { onsubmit: async (e) => {
        e.preventDefault();
        try {
          const opt = fioPick.selectedOptions[0];
          const r = await api('version.create', { body: { film_id: S.filmId, label: label.value, carry_from: carry.value || null,
            fio_file_id: fioPick.value || null, fio_stack_id: opt?.dataset.stack || null, fio_name: opt?.dataset.name || null, color: colorPick.value } });
          closeDialog();
          await loadState({ filmId: S.filmId, versionId: r.id });
          if (fioPick.value) await syncComments(false).catch(fail);
          toast(r.carried ? `${r.carried} unfinished notes carried over. Import this cut’s edit or transcript to place them automatically.` + (r.placement ? placementSummary({ [r.id]: r.placement }, r.id) : '') : 'Version added');
        } catch (err) { fail(err); }
      } },
      el('div', { class: 'two' }, el('label', {}, 'Name of the new cut', label), el('label', {}, 'Colour', colorPick)),
      versions.length ? el('label', {}, 'Notes', carry) : null,
      fioWrap,
      el('p', { class: 'small', text: 'Carrying notes brings every note that isn’t approved, dropped or cut into the new version. Then import its edit or transcript (or match pictures) to place them.' }),
      el('div', { class: 'dlg-actions' }, el('button', { class: 'btn primary', text: 'Add this cut' })));

      const terms = el('textarea', { rows: 3, placeholder: 'One per line: names, places, species… (Montane, wood bison, James)' });
      terms.value = film.keyterms || '';
      // Newest cut first; the one you're on is open, the rest are one line each.
      const openId = S.manageOpen && versions.some((v) => v.id === S.manageOpen) ? S.manageOpen : (S.versionId || versions[versions.length - 1]?.id);
      parts.push(
        el('details', { class: 'new-cut', open: !versions.length },
          el('summary', { class: 'btn primary', text: versions.length ? 'Add a new cut…' : 'Add the first cut…' }),
          newVersion),
        versions.length ? el('div', { class: 'vlist' }, ...[...versions].reverse().map((v) => versionRow(v, v.id === openId))) : el('p', { text: 'No versions yet. Add one for each cut you send for review.' }),
        S.conn.elevenlabs ? el('details', { class: 'terms' },
          el('summary', { text: `Names and terms for transcription${film.keyterms ? ' (' + film.keyterms.split('\n').length + ')' : ''}` }),
          el('p', { text: 'ElevenLabs gets these words right more often. Adds about 20% to the transcription cost.' }),
          terms,
          el('div', { class: 'dlg-actions' }, el('button', { type: 'button', class: 'btn', text: 'Save terms', onclick: async () => {
            try { const r = await api('film.update', { body: { film_id: film.id, keyterms: terms.value } }); film.keyterms = terms.value; toast(`${r.count} terms saved.`); } catch (err) { fail(err); }
          } }))) : null,
      );
    }
    parts.push(el('details', { class: 'add-film', open: !S.films.length },
      el('summary', { text: S.films.length ? 'Add another film' : 'Add your first film' }), newFilm),
    el('div', { class: 'dlg-actions' },
      film ? el('a', { class: 'btn ghost left', href: wsq(`action=export&film_id=${S.filmId}`), text: 'Download all notes (CSV)' }) : null,
      el('button', { type: 'button', class: 'btn', text: 'Close', onclick: closeDialog })));
    showDialog(...parts);
  }
  // After an import or change inside Films & versions: reload, and show the dialog again with that version open.
  async function reopenManage(v, msg) {
    S.manageOpen = v.id;
    await loadState({ filmId: S.filmId, versionId: S.versionId });
    openManage();
    if (msg) toast(msg);
  }

  function fmtTcFor(v, f) {
    const b = +v.tc_base || 24; f = Math.max(0, Math.round(f)) + (+v.tc_start || 0);
    const s = Math.floor(f / b), p = (n) => String(n).padStart(2, '0');
    return `${p(Math.floor(s / 3600))}:${p(Math.floor(s / 60) % 60)}:${p(s % 60)}:${p(f % b)}`;
  }
  function openStartTc(v) {
    const input = el('input', { value: fmtTcFor(v, 0), pattern: '\\d{1,2}:\\d{2}:\\d{2}[:;]\\d{2}', required: true, spellcheck: 'false' });
    const move = el('input', { type: 'checkbox' });
    showDialog(
      el('h2', { text: `Start timecode of ${v.label}` }),
      el('p', { text: 'The timecode of the first frame of this version’s video, as Frame.io or the edit suite shows it (often 00:00:00:00, 01:00:00:00 or 10:00:00:00). Notes stay where they are in the picture; only the timecodes shown change.' }),
      el('form', { onsubmit: async (e) => {
        e.preventDefault();
        try {
          const r = await api('version.tc_start', { body: { version_id: v.id, start_tc: input.value.trim(), move_notes: move.checked } });
          closeDialog();
          await loadState({ filmId: S.filmId, versionId: S.versionId });
          toast(`Start timecode saved.${r.moved ? ` ${r.moved} notes moved by the difference.` : ''}`);
        } catch (err) { fail(err); }
      } },
      el('label', {}, 'Starts at', input),
      el('label', { class: 'inline' }, move, ' Also move this version’s notes by the difference (when they came in counted from this timecode and sit past the end)'),
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost', text: 'Cancel', onclick: closeDialog }),
        el('button', { class: 'btn primary', text: 'Save' }))));
    input.focus(); input.select();
  }

  function versionRow(v, open) {
    const pick = (accept, onFile) => el('input', { type: 'file', accept, hidden: true, onchange: async (e) => {
      const f = e.target.files[0]; if (!f) return;
      e.target.closest('.vrow')?.classList.add('busy');
      try { await onFile(f); } catch (err) { fail(err); } finally { e.target.closest('.vrow')?.classList.remove('busy'); }
    } });
    const csv = pick('.csv,text/csv', async (f) => {
      const form = new FormData(); form.append('version_id', v.id); form.append('csv', f);
      const r = await api('version.import', { form });
      const msg = `Imported ${r.new} new notes and ${r.replies} replies` + (r.updated ? `, updated ${r.updated}` : '') + (r.completed ? `. ${r.completed} were marked complete in Frame.io` : '');
      if (r.new_codes?.length) { closeDialog(); await loadState({ filmId: S.filmId, versionId: v.id }); toast(msg); openCodes(); }
      else await reopenManage(v, msg);
    });
    const edit = pick('.otio,.xml,.edl,.aaf', async (f) => {
      const form = new FormData(); form.append('version_id', v.id); form.append('edit', f);
      const r = await api('version.edit', { form });
      const kind = { otio: 'OTIO', fcp7: 'FCP7 XML', edl: 'EDL' }[r.format] || r.format;
      await reopenManage(v, `${kind} read: ${r.shots} picture shots, cut starts at ${r.start_tc}.` + (r.warnings.length ? ' ' + r.warnings[0] : '') + placementSummary(r.placement, v.id));
    });
    const script = pick('.srt,.vtt,.txt,text/plain,text/vtt', async (f) => {
      await uploadScript(v.id, f);
      await reopenManage(v);
    });
    const rateSel = el('select', { 'aria-label': `Frame rate of ${v.label}`, onchange: async (e) => {
      try { await api('version.update', { body: { version_id: v.id, rate: +e.target.value } }); v.rate = +e.target.value; if (v.id === S.versionId) { S.version.rate = v.rate; tick(); } toast('Frame rate saved'); } catch (err) { fail(err); }
    } }, ...[23.976, 24, 25, 29.97, 30, 50, 59.94, 60].map((r) => el('option', { value: r, text: `${r} fps`, selected: Math.abs(r - v.rate) < 0.001 })));
    const parent = S.versions.find((x) => x.id === v.carried_from);
    const meta = [`${v.note_count} notes`, parent ? `carried from ${parent.label}` : null].filter(Boolean).join(' · ');
    // What this version has, at a glance, for the one-line (closed) view.
    const has = [v.fio_file_id ? 'Frame.io' : null, v.edit ? 'edit' : null, v.transcript_count > 0 ? 'transcript' : null].filter(Boolean);
    return el('details', { class: 'vrow', open, ontoggle: (e) => { if (e.target.open) S.manageOpen = v.id; } },
      el('summary', { class: 'vrow-head' },
        swatch(v, 'vchip'),
        el('span', { class: 'grow' }, el('strong', { text: v.label }), v.id === S.versionId ? el('span', { class: 'here', text: 'open now' }) : null, el('br'),
          el('small', { text: meta + (has.length ? ' · ' + has.join(', ') : '') }))),
      el('dl', { class: 'vrow-data' },
        el('dt', { text: 'Frame.io' }),
        el('dd', {},
          el('span', { text: !S.conn.frameio ? 'Connect Frame.io in Settings to link this version.' : v.fio_file_id ? `${v.fio_name || 'Linked'}${v.fio_synced_at ? ' · synced ' + fmtDate(v.fio_synced_at) : ''}` : 'Not linked' }),
          S.conn.frameio && v.fio_file_id ? el('button', { type: 'button', class: 'btn', text: 'Sync comments', onclick: async () => {
            closeDialog(); if (v.id !== S.versionId) await selectVersion(v.id); syncComments(false).catch(fail);
          } }) : null,
          S.conn.frameio ? el('button', { type: 'button', class: 'btn' + (v.fio_file_id ? ' ghost' : ''), text: v.fio_file_id ? 'Change link…' : 'Link…', onclick: () => openLinkDialog(v) }) : null,
          S.conn.frameio && v.fio_file_id ? el('button', { type: 'button', class: 'btn ghost', text: 'Unlink', onclick: async () => {
            try { await api('version.unlink', { body: { version_id: v.id } }); await reopenManage(v, 'Unlinked from Frame.io.'); } catch (err) { fail(err); }
          } }) : null),
        el('dt', { text: 'Comments' }),
        el('dd', {}, el('span', { text: 'Frame.io export (.csv)' }), el('label', { class: 'btn' + (v.fio_file_id ? ' ghost' : '') }, 'Import', csv)),
        can('editor') ? el('dt', { text: 'Notes out' }) : null,
        can('editor') ? el('dd', {},
          el('span', { text: 'Markers for the edit, or comments on the Frame.io file' }),
          el('button', { type: 'button', class: 'btn ghost', text: 'Markers…', onclick: () => openMarkers(v) }),
          S.conn.frameio && v.fio_file_id ? el('button', { type: 'button', class: 'btn ghost', text: 'Post to Frame.io…', onclick: () => openPush(v) }) : null) : null,
        el('dt', { text: 'Timecode' }),
        el('dd', {},
          el('span', { text: `Starts at ${fmtTcFor(v, 0)}`, title: 'The timecode of the video’s first frame, as Frame.io or the edit shows it' }),
          el('button', { type: 'button', class: 'btn ghost', text: 'Change start…', onclick: () => openStartTc(v) })),
        el('dt', { text: 'Edit' }),
        el('dd', {},
          el('span', { text: v.edit ? `${v.edit.filename} · ${v.edit.shots} clips · starts ${v.edit.start_tc}` : 'None yet: .otio, FCP7 .xml or .edl' }),
          v.edit ? el('button', { type: 'button', class: 'btn ghost', text: 'Where the video starts…', title: 'Which record timecode of the edit the uploaded video starts at (when the video is only part of the sequence)', onclick: async () => {
            const tcIn = prompt('The uploaded video starts at which record timecode?', v.edit.start_tc);
            if (!tcIn) return;
            try { const r = await api('edit.start', { body: { version_id: v.id, start_tc: tcIn.trim() } }); await reopenManage(v, 'Saved.' + placementSummary(r.placement, v.id)); } catch (err) { fail(err); }
          } }) : null,
          el('label', { class: 'btn' }, v.edit ? 'Replace' : 'Import', edit)),
        el('dt', { text: 'Transcript' }),
        el('dd', {},
          el('span', { text: v.transcript_count > 0 ? `${v.transcript_count} upload${v.transcript_count > 1 ? 's' : ''} (latest is used)` : 'None yet: .srt, .vtt or timecoded .txt' }),
          S.conn.elevenlabs && (v.fio_file_id || v.video_url) ? el('button', { type: 'button', class: 'btn', text: 'Transcribe', onclick: async () => {
            closeDialog(); if (v.id !== S.versionId) await selectVersion(v.id); setTab('script'); startTranscribe(v.id).catch(fail);
          } }) : null,
          el('label', { class: 'btn' + (S.conn.elevenlabs ? ' ghost' : '') }, v.transcript_count > 0 ? 'Upload new' : 'Upload', script)),
        parent ? el('dt', { text: 'Placement' }) : null,
        parent ? el('dd', {},
          el('span', { text: 'Place carried notes from the edits and transcripts, or by matching the pictures' }),
          el('button', { type: 'button', class: 'btn', text: 'Place again', onclick: async () => {
            try { const r = await api('version.place', { body: { version_id: v.id } }); await reopenManage(v, r.placement ? placementSummary({ [v.id]: r.placement }, v.id).trim() : 'Import the edit or transcript for both versions first.'); } catch (err) { fail(err); }
          } }),
          el('button', { type: 'button', class: 'btn ghost', text: 'Match pictures…', onclick: () => openPictureMatch(v) })) : null,
        el('dt', { text: 'Settings' }),
        el('dd', {}, el('span', { text: 'Colour and frame rate' }),
          el('select', { 'aria-label': `Colour of ${v.label}`, title: 'Revision colour: shown along the top of the window and beside this version wherever it appears',
            onchange: async (e) => {
              try { await api('version.update', { body: { version_id: v.id, color: e.target.value } }); v.color = e.target.value; renderPickers(); openManage(); applyFilters(); } catch (err) { fail(err); }
            } }, ...REV.map((c) => el('option', { value: c, text: `${revName(c)} pages`, selected: c === v.color }))),
          rateSel,
          !can('admin') ? null : el('button', { type: 'button', class: 'btn ghost danger', text: 'Delete version…', onclick: async () => {
            if (!confirm(`Delete ${v.label}? Notes first raised on it are deleted too; status history on other versions is kept.`)) return;
            try { await api('version.delete', { body: { version_id: v.id } }); closeDialog(); await loadState({ filmId: S.filmId }); openManage(); } catch (err) { fail(err); }
          } }))));
  }


  /* ---------- Placing notes by matching pictures ---------- */
  // Loads assets/match.js on first use. Fingerprints are made here in the browser; the videos never upload.
  let matchLib = null;
  function loadMatchLib() {
    matchLib ??= new Promise((resolve, reject) => {
      if (window.PNMatch) { resolve(window.PNMatch); return; }
      const sc = document.createElement('script');
      sc.src = `assets/match.js?v=${encodeURIComponent(document.querySelector('link[href*="app.css"]')?.href.split('v=')[1] || '')}`;
      sc.onload = () => resolve(window.PNMatch);
      sc.onerror = () => { matchLib = null; reject(new Error('Couldn’t load the picture matcher. Reload the page and try again.')); };
      document.head.append(sc);
    });
    return matchLib;
  }

  function openPictureMatch(v) {
    const parent = S.versions.find((x) => x.id === v.carried_from);
    if (!parent) return;
    const pair = [parent, v];
    // A source per version: the video already open here, a file, or the Frame.io stream.
    const src = new Map();
    const refresh = [];
    const sourceRow = (ver) => {
      const status = el('span', { class: 'pm-status' });
      const open = S.sources.get(ver.id);
      const fileIn = el('input', { type: 'file', accept: 'video/*,.mp4,.mov,.m4v,.webm', hidden: true, onchange: (e) => {
        const f = e.target.files[0]; if (!f) return;
        src.set(ver.id, { url: URL.createObjectURL(f), name: f.name }); show();
      } });
      const show = () => {
        const s = src.get(ver.id);
        const fp = ver.fingerprint ? `Fingerprinted ${fmtDate(ver.fingerprint.at)}` : 'Not fingerprinted yet';
        status.textContent = s ? `${fp} · video: ${s.name}` : `${fp} · no video chosen`;
      };
      if (open?.url) src.set(ver.id, { url: open.url, name: open.name || 'the open video' });
      show();
      refresh.push(show);
      return el('div', { class: 'pm-row' },
        el('div', { class: 'pm-who' }, el('strong', { text: ver.label }), status),
        el('div', { class: 'pm-acts' },
          el('label', { class: 'btn ghost' }, 'Choose file…', fileIn),
          ver.fio_file_id ? el('button', { type: 'button', class: 'btn ghost', text: 'Use Frame.io', onclick: async (e) => {
            e.target.disabled = true;
            try {
              const m = await api('version.media', { query: { version_id: ver.id, quality: 'efficient' } });
              if (!m.url) throw new Error('Frame.io didn’t return a video for this version.');
              src.set(ver.id, { url: m.url, name: 'Frame.io stream' }); show();
            } catch (err) { fail(err); }
            e.target.disabled = false;
          } }) : null));
    };
    const useAi = el('input', { type: 'checkbox', checked: ls.get('pmAi') !== '0' });
    const redo = el('input', { type: 'checkbox' });
    const bar = el('progress', { max: 1, value: 0, hidden: true });
    const step = el('p', { class: 'pm-step', role: 'status' });
    const host = el('div', { class: 'pm-host', 'aria-hidden': 'true' });
    const go = el('button', { class: 'btn primary', text: 'Match pictures' });
    const stop = el('button', { type: 'button', class: 'btn ghost', text: 'Stop', hidden: true });
    let ctl = null;
    stop.addEventListener('click', () => ctl?.abort());

    const run = async () => {
      const P = await loadMatchLib();
      ctl = new AbortController();
      const signal = ctl.signal;
      const say = (t, frac) => { step.textContent = t; bar.hidden = frac == null; if (frac != null) bar.value = frac; };
      const prints = {};
      for (const ver of pair) {
        const s = src.get(ver.id);
        if (ver.fingerprint && !redo.checked) {
          say(`Loading the fingerprint of ${ver.label}…`);
          const r = await fetch(wsq(`action=fingerprint&version_id=${ver.id}`), { credentials: 'same-origin' });
          if (r.ok) {
            const fp = P.unpack(await r.arrayBuffer());
            if (!fp.stale) { prints[ver.id] = fp; continue; }
            // Made by the earlier matcher: read again (needs the video).
            if (!s) throw new Error(`The fingerprint of ${ver.label} was made by an earlier version of the matcher and needs redoing. Choose its video (or open it in the player).`);
          }
        }
        if (!s) throw new Error(`Choose the video for ${ver.label} first (or open it in the player).`);
        say(`Reading the pictures of ${ver.label}…`, 0);
        const fp = await P.fingerprint(s.url, { signal, host, onProgress: (f) => say(`Reading the pictures of ${ver.label}… ${Math.round(f * 100)}%`, f) });
        prints[ver.id] = fp;
        const form = new FormData();
        form.append('version_id', ver.id);
        form.append('source', s.name);
        form.append('fp', new Blob([await P.packForUpload(fp)], { type: 'application/octet-stream' }), 'fingerprint.bin');
        await api('fingerprint.save', { form });
        ver.fingerprint = { at: new Date().toISOString(), source: s.name };
      }
      // Notes to place: every note of this version that was carried from the earlier one.
      const [nA, nB] = await Promise.all(pair.map((ver) => api('notes', { query: { version_id: ver.id } })));
      const inA = new Map(nA.notes.map((n) => [n.id, n.frame]));
      const notes = nB.notes.filter((n) => inA.has(n.id) && n.carried).map((n) => ({ id: n.id, frameA: inA.get(n.id) }));
      if (!notes.length) { say('There are no carried notes to place in this version.'); return null; }
      const haveVideo = src.get(parent.id) && src.get(v.id);
      say(`Matching ${notes.length} notes…`);
      const out = await P.run({
        A: prints[parent.id], B: prints[v.id], srcA: haveVideo ? src.get(parent.id).url : null, srcB: haveVideo ? src.get(v.id).url : null,
        notes, rateA: +parent.rate || 24, rateB: +v.rate || 24, useModel: useAi.checked, signal, host,
        onStatus: (t) => say(t), onProgress: (f) => { bar.hidden = false; bar.value = f; },
      });
      say('Saving…');
      const r = await api('version.place_visual', { body: { version_id: v.id, results: out.results, coarse: !haveVideo } });
      return { stats: r.stats, note: out.note, haveVideo, kind: out.kind };
    };

    showDialog(
      el('h2', { text: 'Place notes by matching pictures' }),
      el('p', { text: `Finds where each note carried from ${parent.label} belongs in ${v.label} by comparing the pictures, for when there’s no edit file. Each shot is lined up with the shot it became (regrades, reframes, trims and moves are allowed for), using the shots around it as context, then each note is lined up to the frame.` }),
      el('p', { class: 'small', text: 'The videos are read in this browser and never uploaded. Only the fingerprints are saved (about 1.4 MB per 10 minutes), so each version is read once. With both videos chosen, notes land on the exact frame; without them, to within a few frames.' }),
      el('div', { class: 'pm-rows' }, ...pair.map(sourceRow)),
      el('form', { onsubmit: async (e) => {
        e.preventDefault();
        ls.set('pmAi', useAi.checked ? '1' : '0');
        go.disabled = true; stop.hidden = false;
        try {
          const r = await run();
          if (r) {
            const st = r.stats;
            const parts = [`${st.placed} placed`];
            if (st.review) parts.push(`${st.review} to check`);
            if (st.unplaced) parts.push(`${st.unplaced} not found (possibly cut)`);
            if (st.already) parts.push(`${st.already} already placed to the frame by an earlier match`);
            if (st.kept) parts.push(`${st.kept} left where the edit file or transcript put them`);
            if (st.disagree) parts.push(`${st.disagree} where the edit file and the picture disagree`);
            step.textContent = `Done: ${parts.join(', ')}.` + (r.haveVideo ? '' : ' (Without both videos, the AI step and lining up to the frame were skipped: notes are within a few frames.)') + (r.note ? ' ' + r.note : '');
            bar.hidden = true;
            refresh.forEach((f) => f());
            await loadState({ filmId: S.filmId, versionId: v.id });
            toast(`Picture match: ${parts.join(', ')}.`);
          }
        } catch (err) {
          bar.hidden = true;
          step.textContent = err.name === 'AbortError' ? 'Stopped. Nothing was changed.' : err.message;
          step.classList.toggle('err', err.name !== 'AbortError');
        } finally {
          go.disabled = false; stop.hidden = true; host.replaceChildren();
        }
      } },
      el('label', { class: 'inline' }, useAi, ' Use the AI model for shots that changed beyond recognition (downloads about 90 MB the first time)'),
      el('label', { class: 'inline' }, redo, ' Read both videos again, even if they were fingerprinted before'),
      host, bar, step,
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost left', text: 'Close', onclick: () => { ctl?.abort(); closeDialog(); } }),
        stop, go)));
  }


  /* ---------- Notes out: markers for the edit suites ---------- */
  function openMarkers(v) {
    const fmt = (val, label, hint, on) => el('label', { class: 'mode' }, el('input', { type: 'radio', name: 'mkfmt', value: val, checked: on }),
      el('span', {}, el('strong', { text: label }), el('small', { text: hint })));
    const scope = el('select', {}, el('option', { value: 'open', text: 'Notes still to do (not finished)' }), el('option', { value: 'all', text: 'All notes' }));
    const placed = el('input', { type: 'checkbox', checked: true });
    const film = S.films.find((f) => f.id === v.film_id);
    const cats = [...(film?.categories || []), { key: '', label: 'Untagged' }];
    const trackBoxes = cats.map((c) => ({ c, box: el('input', { type: 'checkbox', checked: !(v.id === S.versionId && S.hidden.has(c.key)) }) }));
    const go = el('a', { class: 'btn primary', text: 'Download' });
    const update = () => {
      const f = document.querySelector('input[name=mkfmt]:checked')?.value || 'avid';
      const keys = trackBoxes.filter((t) => t.box.checked).map((t) => t.c.key || '_');
      const q = new URLSearchParams({ action: 'export.markers', version_id: v.id, format: f, scope: scope.value, placed: placed.checked ? '1' : '' });
      if (keys.length < trackBoxes.length) q.set('tracks', keys.join(','));
      if (v.id === S.versionId && video.src && isFinite(video.duration)) q.set('dur', Math.round(video.duration * (+v.tc_base || 24)));
      go.href = wsq(String(q));
    };
    showDialog(
      el('h2', { text: `Markers for ${v.label}` }),
      el('p', { text: `Download this version’s notes as markers for the edit, at the timecode the version starts at (${fmtTcFor(v, 0)}). Each marker carries the note’s text and track code, in the track’s colour.` }),
      el('div', { class: 'modes stack', role: 'radiogroup', 'aria-label': 'Format', onchange: update },
        fmt('avid', 'Avid Media Composer', 'Marker list (.txt): Markers window → Import Markers', true),
        fmt('resolve', 'DaVinci Resolve', 'Marker EDL (.edl): Timeline → Import → Timeline Markers from EDL', false),
        fmt('xml', 'Premiere Pro (or Resolve)', 'FCP7 XML (.xml): imports as a sequence carrying the markers', false)),
      el('label', {}, 'Which notes', scope),
      el('label', { class: 'inline' }, placed, ' Leave out carried notes that haven’t been placed in this cut yet'),
      el('fieldset', { class: 'mk-tracks' }, el('legend', { text: 'Tracks' }),
        ...trackBoxes.map((t) => el('label', { class: 'inline' }, t.box, ` ${t.c.key ? t.c.key + ' · ' : ''}${t.c.label}`))),
      el('div', { class: 'dlg-actions' }, el('button', { type: 'button', class: 'btn ghost', text: 'Close', onclick: closeDialog }), go));
    [scope, placed, ...trackBoxes.map((t) => t.box)].forEach((x) => x.addEventListener('change', update));
    update();
  }

  /* ---------- Notes out: comments on the Frame.io file ---------- */
  async function openPush(v) {
    const scope = el('select', {}, el('option', { value: 'open', text: 'Notes still to do (not finished)' }), el('option', { value: 'all', text: 'All notes (finished ones go up marked complete)' }));
    const carried = el('input', { type: 'checkbox', checked: true });
    const placed = el('input', { type: 'checkbox', checked: true });
    const extras = el('input', { type: 'checkbox' });
    const summary = el('p', { class: 'push-sum', role: 'status' });
    const bar = el('progress', { max: 1, value: 0, hidden: true });
    const go = el('button', { type: 'button', class: 'btn primary', text: 'Post', disabled: true });
    let preview = null, stopped = false;
    const ids = () => preview ? [...preview.new, ...(carried.checked ? preview.carried : [])].map((n) => n.id) : [];
    const refresh = async (keep = '') => {
      go.disabled = true; summary.textContent = keep || 'Checking…';
      try { preview = await api('fio.push_preview', { query: { version_id: v.id, scope: scope.value, placed: placed.checked ? '1' : '' } }); } catch (e) { summary.textContent = e.message; return; }
      const n = ids().length;
      const bits = [`${preview.new.length} made in this app`, `${preview.carried.length} carried from earlier versions`];
      const skip = [preview.already ? `${preview.already} already posted` : '', preview.on_file ? `${preview.on_file} that came from this file’s own comments` : ''].filter(Boolean);
      summary.textContent = (keep ? keep + ' ' : '') + (n ? `${bits.join(', ')} still to post.` : 'Everything here is on Frame.io.') + (skip.length ? ` Skipping ${skip.join(' and ')}.` : '');
      go.textContent = n ? `Post ${n} comment${n === 1 ? '' : 's'}` : 'Nothing to post';
      go.disabled = !n;
    };
    go.addEventListener('click', async () => {
      const list = ids();
      if (!list.length) return;
      go.disabled = true; stopped = false; bar.hidden = false; bar.value = 0;
      let posted = 0; const errors = []; let done = '';
      try {
        for (let i = 0; i < list.length && !stopped; i += 10) {
          const r = await api('fio.push', { body: { version_id: v.id, note_ids: list.slice(i, i + 10), extras: extras.checked } });
          posted += r.posted; errors.push(...r.errors);
          bar.value = Math.min(1, (i + 10) / list.length);
          summary.textContent = `Posted ${posted} of ${list.length}…`;
          if (r.errors.length >= 3) break;
        }
        done = `Posted ${posted} comment${posted === 1 ? '' : 's'} to Frame.io.` + (errors.length ? ` ${errors.length} failed: ${errors[0]}` : '');
        if (posted) toast(`Posted ${posted} note${posted === 1 ? '' : 's'} to Frame.io.`);
      } catch (e) { done = e.message; }
      bar.hidden = true;
      await refresh(done);
    });
    showDialog(
      el('h2', { text: `Post notes to Frame.io` }),
      el('p', { text: `Adds this version’s notes as comments on its Frame.io file (${v.fio_name || 'linked file'}), at their timecode, with their track code in front. They’re posted as the Adobe account signed in under Settings. Each note is posted once; syncing later brings back replies and “complete” ticks from Frame.io without duplicating the notes.` }),
      el('label', {}, 'Which notes', scope),
      el('label', { class: 'inline' }, carried, ' Include notes carried from earlier versions'),
      el('label', { class: 'inline' }, placed, ' Leave out carried notes that haven’t been placed in this cut yet'),
      el('label', { class: 'inline' }, extras, ' Add each note’s status and replies to the comment'),
      summary, bar,
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost', text: 'Close', onclick: () => { stopped = true; closeDialog(); } }), go));
    [scope, placed].forEach((x) => x.addEventListener('change', () => refresh()));
    carried.addEventListener('change', () => { if (preview) { const n = ids().length; go.textContent = n ? `Post ${n} comment${n === 1 ? '' : 's'}` : 'Nothing to post'; go.disabled = !n; } });
    refresh();
  }

  /* ---------- your account ---------- */
  const ROLE_HELP = {
    admin: 'Everything an editor can, plus deleting versions and choosing who in the company can open this production.',
    editor: 'Notes, statuses, versions, imports, edits, transcripts, tracks and approving script lines.',
    reviewer: 'Adds notes and replies, and suggests script wording as drafts. Can’t change statuses or approve.',
    viewer: 'Watches and reads. Can’t change anything.',
  };
  const ROLE_NAMES = { admin: 'Admin', editor: 'Editor', reviewer: 'Reviewer', viewer: 'Viewer' };

  async function openAccount() {
    let d;
    try { d = await api('account'); } catch (e) { fail(e); return; }
    const me = d.me;
    const name = el('input', { value: me.name, maxlength: 60, autocomplete: 'name', required: true });
    const email = el('input', { type: 'email', value: me.email, autocomplete: 'username', required: true });
    const emailPw = el('input', { type: 'password', autocomplete: 'current-password' });
    const emailPwRow = el('label', { hidden: true }, 'Current password (needed to change your email)', emailPw);
    email.addEventListener('input', () => { emailPwRow.hidden = email.value.trim().toLowerCase() === me.email; });
    const cur = el('input', { type: 'password', autocomplete: 'current-password', required: true });
    const pw1 = el('input', { type: 'password', autocomplete: 'new-password', minlength: 10, required: true });
    const pw2 = el('input', { type: 'password', autocomplete: 'new-password', minlength: 10, required: true });
    showDialog(
      el('h2', { text: 'Your account' }),
      ROLE ? el('p', {}, 'In this production you’re ', el('strong', { text: ROLE_NAMES[ROLE] }), '. ' + ROLE_HELP[ROLE]) : null,
      companyAdmin() ? el('p', { text: `You’re ${COMPANY_ROLE === 'owner' ? 'an owner' : 'an admin'} of the company too, so you’re an admin in all its productions and can invite people.` }) : null,
      el('p', { class: 'small', text: 'Your name, email and password are the same in every company you’re part of.' }),
      el('form', { onsubmit: async (e) => {
        e.preventDefault();
        try {
          const r = await api('account.update', { body: { name: name.value, email: email.value, password: emailPw.value } });
          document.querySelector('#whoMenu .me-name').textContent = r.me.name;
          S.user = r.me.name;
          toast('Saved. New notes and changes show your new name; older history keeps the name it was made under.');
          closeDialog();
        } catch (err) { fail(err); }
      } },
      el('label', {}, 'Name the team sees', name),
      el('label', {}, 'Email you sign in with', email),
      emailPwRow,
      el('div', { class: 'dlg-actions' }, el('button', { class: 'btn primary', text: 'Save' }))),
      el('h3', { text: 'Change password' }),
      el('form', { onsubmit: async (e) => {
        e.preventDefault();
        try {
          await api('account.password', { body: { current: cur.value, password: pw1.value, password2: pw2.value } });
          closeDialog();
          toast('Password changed. Any other browsers you were signed in on are signed out.');
        } catch (err) { fail(err); }
      } },
      el('label', {}, 'Current password', cur),
      el('label', {}, 'New password (at least 10 characters)', pw1),
      el('label', {}, 'Type it again', pw2),
      el('div', { class: 'dlg-actions' },
        el('button', { type: 'button', class: 'btn ghost', text: 'Close', onclick: closeDialog }),
        el('button', { class: 'btn primary', text: 'Change password' }))));
  }

  /* ---------- people: the company's members, and who can open this production ---------- */
  function linkBox(r, intro) {
    const p = r.person || {};
    const sentNote = r.sent
      ? el('p', { class: 'sent ok', text: `Emailed to ${p.email}${S.conn.mail ? ' from ' + S.conn.mail : ''}. You can also copy the link below.` })
      : r.mail_error ? el('p', { class: 'sent err', text: `Couldn’t email it: ${r.mail_error} Copy the link and send it yourself.` }) : null;
    const app = document.querySelector('.brand')?.textContent || 'Previously Noted';
    const company = r.company || S.ws?.org?.name || app;
    const subject = r.kind === 'reset' ? `Reset your ${app} password` : `You’re invited to ${company} on ${app}`;
    const body = r.kind === 'reset'
      ? `Hi ${p.name || ''},\n\nHere’s a link to choose a new password for ${app}. It works once and expires in ${r.expires}:\n\n${r.link}\n`
      : `Hi ${p.name || ''},\n\nYou’re invited to ${company} on ${app}. Open this link to ${p.has_password ? 'sign in and join' : 'choose your password'}. It works once and expires in ${r.expires}:\n\n${r.link}\n`;
    const mail = `mailto:${encodeURIComponent(p.email || '')}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    return el('div', { class: 'linkbox', role: 'status' },
      sentNote,
      el('p', { text: intro }),
      el('div', { class: 'copyrow' }, el('code', { text: r.link }),
        el('button', { type: 'button', class: 'btn', text: 'Copy link', onclick: () => { navigator.clipboard?.writeText(r.link); toast('Link copied'); } }),
        r.sent ? null : el('a', { class: 'btn ghost', href: mail, text: 'Email it' })),
      el('p', { class: 'small', text: r.kind === 'reset'
        ? 'This link is shown only now. Anyone with it can set the password, so send it straight to them.'
        : 'This link is shown only now, and works only for that email address. You can make a new one at any time; the old one stops working.' }));
  }

  const COMPANY_NAMES = { owner: 'Owner', admin: 'Company admin', member: 'Member' };
  async function openPeople(notice) {
    let d;
    try { d = await api('people'); } catch (e) { fail(e); return; }
    const ws = d.workspace, company = ws.org.name;
    const accessSel = (value, onchange, label = 'Role in this production') => el('select', { 'aria-label': label, onchange },
      el('option', { value: '', text: 'No access', selected: !value }),
      ...['viewer', 'reviewer', 'editor', 'admin'].map((r) => el('option', { value: r, text: ROLE_NAMES[r], selected: r === value })));
    const companySel = (value, onchange) => el('select', { 'aria-label': 'Role in the company', onchange },
      ...['member', 'admin', 'owner'].filter((r) => r !== 'owner' || d.company_role === 'owner' || value === 'owner')
        .map((r) => el('option', { value: r, text: COMPANY_NAMES[r], selected: r === value })));
    const upd = async (p, body, msg) => {
      try { await api('people.update', { body: { id: p.id, ...body } }); toast(msg); openPeople(); } catch (err) { fail(err); openPeople(); }
    };
    const link = async (p) => {
      try {
        const r = await api('people.link', { body: { id: p.id, send: !!d.mail } });
        openPeople(linkBox(r, `New invite link for ${p.name}:`));
      } catch (err) { fail(err); }
    };
    const row = (p) => {
      const self = p.id === d.me;
      const state = p.status === 'invited' ? ['invited', p.invite_pending ? 'Invited' : 'Invite expired'] : null;
      const B = (text, fn, cls = 'btn ghost') => el('button', { type: 'button', class: cls, text, onclick: fn });
      const access = p.org_wide
        ? el('span', { class: 'pstate', text: 'Admin everywhere', title: `${COMPANY_NAMES[p.org_role]}s are admins in every production of ${company}.` })
        : accessSel(p.ws_role, (e) => upd(p, { role: e.target.value },
          e.target.value ? `${p.name} is now ${ROLE_NAMES[e.target.value]} in ${ws.name}.` : `${p.name} can’t open ${ws.name} any more.`));
      return el('li', { class: 'prow' },
        el('div', { class: 'prow-who' },
          el('strong', { text: p.name + (self ? ' (you)' : '') }),
          el('small', { text: p.email + (p.last_login ? ` · last signed in ${fmtDate(p.last_login)}` : p.status === 'active' ? ' · hasn’t signed in yet' : '') })),
        state ? el('span', { class: 'pstate ' + state[0], text: state[1] }) : null,
        access,
        d.can_company ? companySel(p.org_role, (e) => upd(p, { org_role: e.target.value }, `${p.name} is now ${COMPANY_NAMES[e.target.value]} of ${company}.`)) : null,
        el('div', { class: 'prow-acts' },
          d.can_company && p.status === 'invited' ? B(d.mail ? 'Email a new invite' : 'New invite link', () => link(p)) : null,
          d.can_company && !self ? B(p.status === 'invited' ? 'Withdraw' : 'Remove…', async () => {
            if (!confirm(p.status === 'invited' ? `Withdraw ${p.name}’s invite?` : `Remove ${p.name} from ${company}? They lose access to all its productions straight away. Their notes and history stay, under their name.`)) return;
            try { await api('people.delete', { body: { id: p.id } }); toast(`${p.name} removed.`); openPeople(); } catch (err) { fail(err); }
          }, 'btn ghost danger') : null));
    };
    let invite = null;
    if (d.can_company) {
      const iname = el('input', { maxlength: 60, required: true, autocomplete: 'off' });
      const iemail = el('input', { type: 'email', required: true, autocomplete: 'off' });
      const irole = accessSel('reviewer', null);
      const icompany = companySel('member', () => { irole.disabled = icompany.value !== 'member'; });
      const isend = el('input', { type: 'checkbox', checked: !!d.mail });
      invite = [
        el('h3', { text: 'Invite someone' }),
        el('form', { class: 'invite', onsubmit: async (e) => {
          e.preventDefault();
          try {
            const r = await api('people.invite', { body: { name: iname.value, email: iemail.value, role: icompany.value === 'member' ? irole.value : '', org_role: icompany.value, send: !!d.mail && isend.checked } });
            openPeople(linkBox({ ...r, kind: 'invite', expires: `${r.days} days`, company },
              r.existing ? `${r.person.name} already has an account, so the link just asks them to sign in and join:` : `Invite link for ${r.person.name}:`));
          } catch (err) { fail(err); }
        } },
        el('div', { class: 'invite-grid' },
          el('label', {}, 'Name', iname), el('label', {}, 'Email', iemail),
          el('label', {}, `Role in ${ws.name}`, irole), el('label', {}, 'In the company', icompany)),
        d.mail ? el('label', { class: 'inline' }, isend, ` Email the invite from ${d.mail}`) : null,
        el('div', { class: 'dlg-actions' },
          el('button', { type: 'button', class: 'btn ghost left', text: 'Close', onclick: closeDialog }),
          el('button', { class: 'btn primary', text: d.mail ? 'Invite' : 'Create invite link' }))),
      ];
    }
    showDialog(
      el('h2', { text: 'People' }),
      el('p', { class: 'small', text: `Everyone in ${company}, and what they can do in ${ws.name}. People without access can’t see this production at all.` }),
      notice || null,
      el('ul', { class: 'plist' }, ...d.people.map(row)),
      ...(invite || [el('p', { text: `To invite someone new to ${company}, ask a company admin. You can give access to anyone already on this list.` }),
        el('div', { class: 'dlg-actions' }, el('button', { type: 'button', class: 'btn ghost', text: 'Close', onclick: closeDialog }))]),
      el('details', { class: 'roles-help' }, el('summary', { text: 'What each role can do' }),
        el('dl', {}, ...['admin', 'editor', 'reviewer', 'viewer'].flatMap((r) => [el('dt', { text: ROLE_NAMES[r] }), el('dd', { text: ROLE_HELP[r] })]),
          el('dt', { text: 'Company admin' }), el('dd', { text: 'An admin in every production of the company. Invites and removes people, makes and archives productions, and connects Frame.io and ElevenLabs.' }),
          el('dt', { text: 'Owner' }), el('dd', { text: 'Everything a company admin can, and can make other owners.' }))),
      el('p', { class: 'small', text: (d.mail
        ? `Invites are emailed from ${d.mail}, with you as the reply-to. Each link is also shown here to copy.`
        : 'Email isn’t set up on this server, so copy each link, or use Email it to open a message in your own mail app.')
        + ' Someone who forgets their password gets a new one with “Forgot your password?” on the sign-in page.' }));
  }

  /* ---------- company: its productions and the audit trail ---------- */
  async function openCompany() {
    let d;
    try { d = await api('company'); } catch (e) { fail(e); return; }
    const admin = d.company_role === 'owner' || d.company_role === 'admin';
    const nameIn = el('input', { maxlength: 120, required: true, placeholder: 'The film or series' });
    const B = (text, fn, cls = 'btn ghost') => el('button', { type: 'button', class: cls, text, onclick: fn });
    const save = async (w, body, msg) => {
      try { await api('workspace.update', { body: { workspace_id: w.id, ...body } }); toast(msg); openCompany(); } catch (err) { fail(err); }
    };
    const row = (w) => el('li', { class: 'prow' + (w.status === 'archived' ? ' off' : '') },
      el('div', { class: 'prow-who' },
        el('strong', { text: w.name + (w.id === WS ? ' (open now)' : '') }),
        el('small', { text: `${w.films} film${w.films === 1 ? '' : 's'} · ${w.people} ${w.people === 1 ? 'person' : 'people'} with access, plus company admins` })),
      w.status === 'archived' ? el('span', { class: 'pstate off', text: 'Archived' }) : null,
      el('div', { class: 'prow-acts' },
        w.id !== WS ? B('Open', () => { location.href = `./?ws=${w.id}`; }) : null,
        admin ? B('Rename…', () => { const n = prompt('New name for the production:', w.name); if (n && n.trim() !== w.name) save(w, { name: n.trim() }, 'Renamed.'); }) : null,
        admin ? (w.status === 'archived'
          ? B('Reopen', () => save(w, { status: 'active' }, `${w.name} is open again.`))
          : B('Archive…', () => { if (confirm(`Archive ${w.name}? It stays readable, but nobody can change it until it’s reopened.`)) save(w, { status: 'archived' }, `${w.name} is archived.`); }, 'btn ghost danger')) : null));
    showDialog(
      el('h2', { text: d.company.name }),
      el('p', { class: 'small', text: `${d.company.members} ${d.company.members === 1 ? 'member' : 'members'}. Each production has its own films and its own list of who can open it (see People).` }),
      el('h3', { text: 'Productions' }),
      el('ul', { class: 'plist' }, ...d.workspaces.map(row)),
      admin ? el('form', { class: 'invite', onsubmit: async (e) => {
        e.preventDefault();
        try { const r = await api('workspace.create', { body: { name: nameIn.value } }); location.href = `./?ws=${r.id}`; } catch (err) { fail(err); }
      } },
      el('div', { class: 'two' }, el('label', {}, 'New production', nameIn)),
      el('div', { class: 'dlg-actions' }, el('button', { class: 'btn primary', text: 'Make the production' }))) : null,
      el('details', { class: 'roles-help' }, el('summary', { text: `Previously Noted support activity (${d.audit.length})` }),
        d.audit.length ? el('ul', { class: 'audit' }, ...d.audit.map((a) => el('li', {},
          el('small', { text: fmtDate(a.at) }), ' ',
          `${a.actor || 'Someone'}${a.as_name ? ` (as ${a.as_name})` : ''}: ${a.action}${a.detail ? ' · ' + a.detail : ''}`)))
          : el('p', { text: 'Nothing yet. When Previously Noted support looks at your productions or acts for someone, it’s listed here.' })),
      el('div', { class: 'dlg-actions' }, B('Close', closeDialog)));
  }

  /* ---------- platform: Previously Noted staff ---------- */
  async function openPlatform(q = '', notice = null) {
    let d;
    try { d = await api('platform', { query: { q } }); } catch (e) { fail(e); return; }
    const pa = d.role === 'platform_admin';
    const B = (text, fn, cls = 'btn ghost') => el('button', { type: 'button', class: cls, text, onclick: fn });
    const orgRow = (o) => el('li', { class: 'prow' + (o.status === 'suspended' ? ' off' : '') },
      el('div', { class: 'prow-who' }, el('strong', { text: o.name }),
        el('small', { text: `${o.members} members · ${o.workspaces} productions${o.owners ? ' · owner ' + o.owners : ''}` })),
      o.status === 'suspended' ? el('span', { class: 'pstate off', text: 'Suspended' }) : null,
      el('div', { class: 'prow-acts' },
        o.first_ws ? B('Open', () => { location.href = `./?ws=${o.first_ws}`; }) : null,
        pa ? B(o.status === 'suspended' ? 'Reactivate' : 'Suspend…', async () => {
          if (o.status !== 'suspended' && !confirm(`Suspend ${o.name}? Nobody in it can open its productions until it’s reactivated.`)) return;
          try { await api('platform.org.update', { body: { org_id: o.id, status: o.status === 'suspended' ? 'active' : 'suspended' } }); openPlatform(q); } catch (err) { fail(err); }
        }, o.status === 'suspended' ? 'btn ghost' : 'btn ghost danger') : null));
    const personRow = (p) => el('li', { class: 'prow' + (p.status === 'disabled' ? ' off' : '') },
      el('div', { class: 'prow-who' }, el('strong', { text: p.name + (p.id === d.me ? ' (you)' : '') }),
        el('small', { text: `${p.email}${p.companies ? ' · ' + p.companies : ' · no company'}${p.has_password ? '' : ' · hasn’t chosen a password'}` })),
      p.locked ? el('span', { class: 'pstate locked', text: 'Locked out' }) : p.status === 'disabled' ? el('span', { class: 'pstate off', text: 'Turned off' }) : null,
      pa ? el('select', { 'aria-label': 'Staff role', disabled: p.id === d.me, onchange: async (e) => {
        try { await api('platform.user.update', { body: { id: p.id, platform_role: e.target.value } }); toast('Saved.'); } catch (err) { fail(err); openPlatform(q); }
      } }, ...[['', 'Customer'], ['support', 'Support'], ['platform_admin', 'Platform admin']].map(([v, t]) => el('option', { value: v, text: t, selected: (p.platform_role || '') === v })))
        : (p.platform_role ? el('span', { class: 'pstate', text: p.platform_role === 'support' ? 'Support' : 'Platform admin' }) : null),
      el('div', { class: 'prow-acts' },
        p.id !== d.me && p.has_password ? B('See as them', async () => {
          if (!confirm(`See the app as ${p.name}?${pa ? '' : ' (read-only)'} Their companies see this in their activity list.`)) return;
          try { await api('platform.impersonate', { body: { id: p.id } }); location.href = './'; } catch (err) { fail(err); }
        }) : null,
        pa && p.locked ? B('Unlock', async () => { try { await api('platform.user.update', { body: { id: p.id, unlock: 1 } }); openPlatform(q); } catch (err) { fail(err); } }) : null,
        pa && p.status !== 'disabled' && p.has_password ? B(d.mail ? 'Email a reset link' : 'Reset link', async () => {
          try { const r = await api('platform.reset_link', { body: { id: p.id, send: !!d.mail } }); openPlatform(q, linkBox({ ...r, person: p }, `Password reset link for ${p.name}:`)); } catch (err) { fail(err); }
        }) : null,
        pa && p.id !== d.me ? B(p.status === 'disabled' ? 'Turn on' : 'Turn off…', async () => {
          if (p.status !== 'disabled' && !confirm(`Turn off ${p.name}’s account? They’re signed out everywhere and can’t sign in to any company.`)) return;
          try { await api('platform.user.update', { body: { id: p.id, status: p.status === 'disabled' ? 'active' : 'disabled' } }); openPlatform(q); } catch (err) { fail(err); }
        }, p.status === 'disabled' ? 'btn ghost' : 'btn ghost danger') : null));
    const search = el('input', { type: 'search', value: q, placeholder: 'Search people by name or email', 'aria-label': 'Search people' });
    let newCo = null;
    if (pa) {
      const cname = el('input', { maxlength: 120, required: true }), wname = el('input', { maxlength: 120, placeholder: 'First production' });
      const oname = el('input', { maxlength: 60 }), oemail = el('input', { type: 'email', placeholder: 'Leave empty to own it yourself' });
      newCo = [el('h3', { text: 'New company' }),
        el('form', { class: 'invite', onsubmit: async (e) => {
          e.preventDefault();
          try {
            const r = await api('platform.org.create', { body: { name: cname.value, workspace: wname.value, owner_name: oname.value, owner_email: oemail.value, send: !!d.mail } });
            if (r.link) openPlatform(q, linkBox({ ...r, kind: 'invite', expires: '7 days', company: cname.value, person: { name: oname.value, email: oemail.value } }, `Invite link for the owner of ${cname.value}:`));
            else { toast(`${cname.value} is ready.`); openPlatform(q); }
          } catch (err) { fail(err); }
        } },
        el('div', { class: 'invite-grid' }, el('label', {}, 'Company name', cname), el('label', {}, 'First production', wname),
          el('label', {}, 'Owner’s name', oname), el('label', {}, 'Owner’s email', oemail)),
        el('div', { class: 'dlg-actions' }, el('button', { class: 'btn primary', text: 'Make the company' })))];
    }
    showDialog(
      el('h2', { text: 'All companies' }),
      el('p', { class: 'small', text: pa ? 'You’re a platform admin: you can open and change any company’s productions. Changes you make while seeing the app as someone are in their companies’ activity list.'
        : 'You’re support: you can open any company’s productions read-only, and see the app as someone (read-only) to reproduce a problem.' }),
      notice,
      el('ul', { class: 'plist' }, ...d.orgs.map(orgRow)),
      ...(newCo || []),
      el('h3', { text: 'People' }),
      el('form', { class: 'filter-row', onsubmit: (e) => { e.preventDefault(); openPlatform(search.value.trim()); } }, search, el('button', { class: 'btn', text: 'Search' })),
      el('ul', { class: 'plist' }, ...d.people.map(personRow)),
      el('div', { class: 'dlg-actions' }, B('Close', closeDialog)));
  }

  /* ---------- resizable panes, kept per view ---------- */
  // Each view (Review, Script, Playback) remembers its own sizes, in this browser. Double-click a splitter,
  // or press Home on it, to go back to the default.
  const LAYOUT_DEFAULT = { side: 400, sideWide: null, video: null };
  const VIEWS = ['review', 'script', 'playback'];
  let layouts = {};
  try { layouts = JSON.parse(ls.get('layouts') || '{}') || {}; } catch { layouts = {}; }
  if (!layouts.review) { try { layouts.review = JSON.parse(ls.get('layout') || '{}'); } catch { layouts.review = {}; } }   // from before views
  for (const v of VIEWS) layouts[v] = { ...LAYOUT_DEFAULT, ...(layouts[v] || {}) };
  let view = VIEWS.includes(ls.get('view')) ? ls.get('view') : 'review';
  let layout = layouts[view];
  const ws = $('workspace');
  const stage = document.querySelector('.stage');
  const wideNow = () => ws.classList.contains('script-wide');
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  function applyLayout() {
    const W = ws.clientWidth;
    if (layout.side) ws.style.setProperty('--side-w', clamp(layout.side, 280, Math.max(280, W - 420)) + 'px'); else ws.style.removeProperty('--side-w');
    if (layout.sideWide) ws.style.setProperty('--side-w-wide', clamp(layout.sideWide, 420, Math.max(420, W - 360)) + 'px'); else ws.style.removeProperty('--side-w-wide');
    // The picture gets what's left after the transport, caption and timeline, keeping room for the note card.
    const H = stage.clientHeight;
    if (!H || getComputedStyle(stage).display !== 'grid') { stage.style.removeProperty('--video-h'); return; }
    const gap = 8;
    const fixed = 16 + ['splitVideo', 'caption', 'tcFix', 'tl'].reduce((t, id) => t + ($(id).hidden ? 0 : $(id).offsetHeight + gap), 0)
      + document.querySelector('.transport').offsetHeight + gap;
    const avail = H - fixed;
    // Playback has no note card under the picture, so the picture can take all the room.
    const pb = view === 'playback';
    const lo = 120, hi = Math.max(lo, pb ? avail : avail - 120);
    const auto = pb ? Math.min(stage.clientWidth * 9 / 16, avail) : Math.min(stage.clientWidth * 9 / 16, avail - Math.max(180, avail * 0.35));
    stage.style.setProperty('--video-h', Math.round(clamp(layout.video || auto, lo, hi)) + 'px');
  }
  function saveLayout() { ls.set('layouts', JSON.stringify(layouts)); }

  /* ---------- views ---------- */
  // Review: work through the notes (list, note card, statuses). Script: transcript, notes and the new wording side
  // by side. Playback: the picture as big as it goes, with the notes shown on it as they come up.
  function setView(v) {
    if (!VIEWS.includes(v)) return;
    view = v;
    layout = layouts[v];
    ls.set('view', v);
    document.body.dataset.view = v;
    for (const b of document.querySelectorAll('#views button')) b.setAttribute('aria-pressed', String(b.dataset.view === v));
    if (v === 'script') { S.scriptWide = true; setTab('script'); }
    else if (v === 'review') { S.scriptWide = false; setTab('notes'); }
    renderScript();
    applyLayout();
    S.activeKey = '__force'; updateActive();
    setTimeout(() => { applyLayout(); renderTimeline(); }, 30);
  }
  for (const b of document.querySelectorAll('#views button')) b.addEventListener('click', () => setView(b.dataset.view));
  function splitter(elm, axis, onMove, onReset, onKey) {
    elm.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      e.preventDefault();
      elm.setPointerCapture(e.pointerId);
      elm.classList.add('dragging');
      document.body.classList.add('resizing', axis === 'x' ? 'col' : 'row');
      const move = (ev) => { onMove(ev); applyLayout(); };
      const up = () => {
        elm.classList.remove('dragging');
        document.body.classList.remove('resizing', 'col', 'row');
        elm.removeEventListener('pointermove', move);
        elm.removeEventListener('pointerup', up);
        elm.removeEventListener('pointercancel', up);
        saveLayout();
      };
      elm.addEventListener('pointermove', move);
      elm.addEventListener('pointerup', up);
      elm.addEventListener('pointercancel', up);
    });
    elm.addEventListener('dblclick', () => { onReset(); applyLayout(); saveLayout(); });
    elm.addEventListener('keydown', (e) => {
      const step = e.shiftKey ? 80 : 20;
      const d = { ArrowLeft: -step, ArrowUp: -step, ArrowRight: step, ArrowDown: step }[e.key];
      if (e.key === 'Home') { e.preventDefault(); onReset(); }
      else if (d) { e.preventDefault(); onKey(d); }
      else return;
      applyLayout(); saveLayout();
    });
  }
  // Sidebar: drag left to widen it. The 3-column script view keeps its own width.
  splitter($('splitSide'), 'x',
    (e) => { const w = ws.getBoundingClientRect().right - e.clientX; if (wideNow()) layout.sideWide = w; else layout.side = w; },
    () => { if (wideNow()) layout.sideWide = null; else layout.side = LAYOUT_DEFAULT.side; },
    (d) => { const cur = document.querySelector('.list').getBoundingClientRect().width; if (wideNow()) layout.sideWide = cur - d; else layout.side = cur - d; });
  // Picture height: drag down for a bigger picture, up for more room for the note.
  splitter($('splitVideo'), 'y',
    (e) => { layout.video = e.clientY - $('screen').getBoundingClientRect().top; },
    () => { layout.video = null; },
    (d) => { layout.video = $('screen').getBoundingClientRect().height + d; });
  const ro = new ResizeObserver(() => applyLayout());
  [ws, $('tl'), $('caption'), document.querySelector('.transport')].forEach((x) => ro.observe(x));
  document.body.dataset.view = view;
  applyLayout();


  /* ---------- Help ---------- */
  // A condensed guide, shown for what this person's role can do. The README has the full version.
  function openHelp() {
    const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
    const K = (...keys) => el('span', { class: 'keys' }, ...keys.flatMap((k, i) => [i ? el('span', { class: 'or', text: '/' }) : null, el('kbd', { text: k })]).filter(Boolean));
    const keyRow = (keys, what) => [el('dt', {}, keys), el('dd', { text: what })];
    const sec = (title, open, ...body) => el('details', { class: 'help-sec', open }, el('summary', { text: title }), ...body);
    const P = (text) => el('p', { text });
    const L = (...items) => el('ul', {}, ...items.filter(Boolean).map((t) => el('li', { text: t })));
    const cmd = mac ? '⌘' : 'Ctrl';
    const touchOnly = matchMedia('(pointer: coarse)').matches && !matchMedia('(pointer: fine)').matches;
    showDialog(
      el('h2', { text: 'Help' }),
      P('Previously Noted plays each cut with its review notes in sync, keeps every note’s status history, and carries unfinished notes into the next cut.'),
      sec('Keyboard', !touchOnly,
        el('dl', { class: 'keylist' },
          ...keyRow(K('Space'), 'Play or pause'),
          ...keyRow(K('J', 'K', 'L'), 'Shuttle: L forward, faster with each press (1×, 2×, 4×, 8×); J the same backwards; K stops'),
          ...keyRow(el('span', { class: 'keys' }, el('kbd', { text: 'K' }), ' + ', el('kbd', { text: 'J' }), el('span', { class: 'or', text: '/' }), el('kbd', { text: 'L' })), 'Hold K and tap J or L to step one frame'),
          ...keyRow(K('←', '→'), 'One frame back or forward (with Shift: one second)'),
          ...keyRow(K('P', 'N'), 'Previous or next note'),
          ...(can('reviewer') ? keyRow(K('M'), 'Add a note at the playhead') : []),
          ...(can('editor') ? keyRow(K('A', 'D', 'W'), 'Mark the current note Addressed, To discuss or Won’t do (Undo in the message that appears)') : []),
          ...(can('reviewer') ? keyRow(K('R'), 'Reply to the current note (Ctrl+Enter sends)') : []),
          ...keyRow(K('↑', '↓'), 'In the notes list: previous or next note'),
          ...(can('editor') ? keyRow(K('O'), 'End the current note at the playhead') : []),
          ...keyRow(K('+', '−'), `Zoom the timeline in or out (also ${cmd} + and ${cmd} −)`),
          ...keyRow(K('0'), 'Show the whole cut on the timeline'),
          ...keyRow(K('T'), 'Switch between Notes and Transcript'),
          ...keyRow(K('1', '2', '3'), 'Views: Review, Script, Playback (each keeps its own layout)'),
          ...keyRow(K('?'), 'This help'))),
      sec('Watching and reviewing', touchOnly,
        L('Pick the film and version at the top. Open the video: stream it from Frame.io, open a file from your computer (it’s never uploaded), or paste a link.',
          'The note at the playhead shows under the picture; the narration line shows just above it.',
          'Click a note in the list or on the timeline to jump to it. Follow playback keeps the list in step.',
          'The counts at the top (open, addressed, to discuss, done) are filters: click one, click again to clear.',
          can('reviewer') ? 'Reply under any note. Press M, or Add note here, to leave a new note at the playhead.' : null,
          can('editor') ? 'Reword changes a note’s text (the old wording stays in its history).' : can('reviewer') ? 'Reword changes the text of your own notes.' : null,
          can('editor') ? 'Set a status with the buttons at the bottom of the note: Addressed, Discuss, Won’t do, and under More: Approve, Cut from film, Superseded, Reopen. Type in the box first to say why. Each change can be undone from the message that appears.' : null)),
      sec('Timeline', false,
        L('Drag the ruler (or the playhead) to scrub; click anywhere to jump; drag empty space to move along; scroll to zoom.',
          'Each track is a lane. Click a track’s letter to hide or show it; Alt-click to show only that track.',
          'A numbered circle is a group of notes too close to tell apart: click it to zoom in.',
          'A tick is a single moment; a bar is a note with an end point.',
          can('editor') ? 'Drag a note up or down to move it to another track. Drag its right edge to set or change where it ends.' : null,
          'Drag the handle under the picture to resize it, and the line beside the sidebar to resize that. Double-click either to reset.')),
      sec('Carried notes and new cuts', false,
        L('When a new cut is added from an earlier one, every unfinished note comes with it.',
          'With the edit file (OTIO, FCP7 XML or EDL) or a transcript for both cuts, notes are placed for you: picture notes follow their shot, narration notes follow their line.',
          'Notes that need a look are flagged with the reason: shot removed or trimmed, line reworded, and so on. Filter by “to place”.',
          can('editor') ? 'No edit file? Films & versions → Match pictures… compares the two cuts’ pictures instead (the videos stay on your computer). Notes labelled “likely” or “changed shot” are worth a look.' : null,
          can('editor') ? 'On a flagged note, find the moment and press Pin to playhead, or Position is right if it already is.' : null)),
      sec('Transcript and script', false,
        L('The Transcript tab scrolls with playback and marks lines that are new or reworded since the previous cut.',
          'Script view shows each line, its notes, and the new wording side by side.',
          can('reviewer') && !can('editor') ? 'Suggest a rewrite or a new line; it’s saved as a draft for an editor to approve.' : null,
          can('editor') ? 'Rewrite, cut or add lines; approve them when final. Download the recording script for the VO session.' : null)),
      can('editor') ? sec('Versions, imports and tracks', false,
        L('Films & versions: add a film or a version, link it to its Frame.io file, sync comments, import a comment export (CSV), the edit, or a transcript, or transcribe with ElevenLabs.',
          'Manage → Tracks adds, renames, recolours and reorders tracks. Comments that start with a track’s code, like “M:” or “ARCH-001”, land on that track; Find tracks in the notes makes tracks from the codes your notes use.',
          'Notes out, on each version: Markers… downloads a marker file for Avid, Resolve or Premiere at the version’s timecode; Post to Frame.io… adds the notes as comments on the linked Frame.io file.')) : null,
      can('admin') ? sec('Admin', false,
        L('People (in this menu): everyone in the company. Pick each person’s role in this production, or No access.',
          'Roles: Viewer watches; Reviewer adds notes, replies and draft script lines; Editor does everything with notes, versions and tracks; Admin also deletes versions and chooses who can open the production.',
          ...(companyAdmin() ? ['Company admins also invite people, remove them from the company, and make or archive productions (Company and productions).',
            'Settings and connections: Frame.io and ElevenLabs for the whole company.'] : ['Inviting someone new is for company admins.']),
          'The production picker at the top left switches between your productions.')) : null,
      el('p', { class: 'small', text: `In this production you’re ${ROLE_NAMES[ROLE] || 'a viewer'}.${companyAdmin() ? ' As a company admin you also invite people and make productions (your name menu → People, Company and productions).' : ''} The README that came with the app has the full guide.` }),
      el('div', { class: 'dlg-actions' }, el('button', { type: 'button', class: 'btn primary', text: 'Close', onclick: closeDialog })));
  }

  /* ---------- wiring ---------- */
  $('filmSelect').addEventListener('change', async (e) => {
    S.filmId = +e.target.value;
    const vs = filmVersions();
    await selectVersion(vs[vs.length - 1]?.id ?? null);
  });
  $('versionSelect').addEventListener('change', (e) => selectVersion(+e.target.value).catch(fail));
  $('manageBtn').addEventListener('click', openManage);
  $('playBtn').addEventListener('click', playPause);
  $('prevNote').addEventListener('click', () => jumpNote(-1));
  $('nextNote').addEventListener('click', () => jumpNote(1));
  $('addBtn').addEventListener('click', openAddNote);
  $('speed').addEventListener('change', (e) => {
    const r = +e.target.value;
    shuttle.base = r;                                   // space plays at this speed from now on
    if (!video.paused || shuttle.rev) setShuttle(r);    // playing: change speed (or direction) now
  });
  $('settingsBtn')?.addEventListener('click', openSettings);
  $('peopleBtn')?.addEventListener('click', () => openPeople());
  $('companyBtn')?.addEventListener('click', openCompany);
  $('platformBtn')?.addEventListener('click', () => openPlatform());
  $('wsSelect')?.addEventListener('change', (e) => { location.href = `./?ws=${e.target.value}`; });
  $('impStop')?.addEventListener('click', async () => {
    try { await api('platform.impersonate.stop', { body: {} }); location.href = './'; } catch (err) { fail(err); }
  });
  $('accountBtn').addEventListener('click', openAccount);
  $('helpBtn').addEventListener('click', openHelp);
  // Pop-up menus (account, Manage, playback, More): close after a choice, on Escape, and when clicking elsewhere.
  // Menus inside scrolling panels open above their button, placed on screen so nothing clips them.
  const whoMenu = $('whoMenu');
  document.addEventListener('click', (e) => {
    for (const d of document.querySelectorAll('details.pop[open], details.who[open]')) {
      if (!d.contains(e.target) || (e.target.closest('.menu-pop button') && d.contains(e.target))) d.open = false;
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const d = e.target.closest?.('details[open]');
    if (d && (d.classList.contains('pop') || d.classList.contains('who'))) { d.open = false; d.querySelector('summary').focus(); }
  }, true);
  document.addEventListener('toggle', (e) => {
    const d = e.target;
    if (!(d instanceof HTMLDetailsElement) || !d.open || !d.classList.contains('up')) return;
    const pop = d.querySelector('.menu-pop'), r = d.querySelector('summary').getBoundingClientRect();
    pop.style.position = 'fixed';
    pop.style.top = 'auto'; pop.style.bottom = `${innerHeight - r.top + 6}px`;
    pop.style.left = 'auto'; pop.style.right = `${Math.max(8, innerWidth - r.right)}px`;
  }, true);
  if (!can('editor')) {
    $('manageMenu').hidden = true;
    $('scriptFile').closest('label').hidden = true;
  }
  $('tracksBtn').addEventListener('click', () => openTracks());
  if (!can('reviewer')) $('addBtn').hidden = true;
  $('syncBtn').addEventListener('click', () => syncComments(false).catch(fail));
  $('streamBtn').addEventListener('click', () => streamFrameio().catch(fail));
  $('quality').addEventListener('change', () => streamFrameio(S.frame).catch(fail));
  $('transcribeBtn').addEventListener('click', () => S.versionId && startTranscribe(S.versionId).catch(fail));
  $('tabNotes').addEventListener('click', () => setTab('notes'));
  $('tabScript').addEventListener('click', () => setTab('script'));
  $('scriptUpload').addEventListener('change', (e) => loadScript(+e.target.value).catch(fail));
  $('scriptFile').addEventListener('change', async (e) => {
    const f = e.target.files[0]; e.target.value = '';
    if (!f || !S.versionId) return;
    try { await uploadScript(S.versionId, f); } catch (err) { fail(err); }
  });
  $('scriptSearch').addEventListener('input', renderScript);
  S.scriptWide = ls.get('scriptWide') === '1';
  $('scriptWideBtn').addEventListener('click', () => {
    S.scriptWide = !S.scriptWide;
    ls.set('scriptWide', S.scriptWide ? '1' : '0');
    renderScript();
    setTimeout(() => renderTimeline(), 50);
  });
  $('dlTxt').addEventListener('click', (e) => { e.preventDefault(); location.href = wsq(`action=script.export&format=txt&version_id=${S.versionId}`); });
  $('dlCsv').addEventListener('click', (e) => { e.preventDefault(); location.href = wsq(`action=script.export&format=csv&version_id=${S.versionId}`); });
  $('scriptChanges').addEventListener('change', renderScript);
  $('script').addEventListener('wheel', () => { S.scriptScrolledAt = Date.now(); }, { passive: true });
  $('script').addEventListener('touchmove', () => { S.scriptScrolledAt = Date.now(); }, { passive: true });
  if (ls.get('tab') === 'script') setTab('script');
  $('search').addEventListener('input', (e) => { S.filter.q = e.target.value.trim().toLowerCase(); applyFilters(); });
  $('placeQueue').addEventListener('click', () => setStatusFilter(S.filter.status === 'unconfirmed' ? '' : 'unconfirmed'));
  $('authorFilter').addEventListener('change', (e) => { S.filter.author = e.target.value; applyFilters(); });
  $('notes').addEventListener('wheel', () => { S.userScrolledAt = Date.now(); }, { passive: true });
  $('notes').addEventListener('touchmove', () => { S.userScrolledAt = Date.now(); }, { passive: true });

  document.addEventListener('keyup', (e) => { if (e.key === 'k' || e.key === 'K') shuttle.kHeld = false; });
  window.addEventListener('blur', () => { shuttle.kHeld = false; });
  document.addEventListener('keydown', (e) => {
    // Cmd/Ctrl with + and − zooms the timeline instead of the page; Cmd/Ctrl 0 shows the whole cut.
    if ((e.metaKey || e.ctrlKey) && !e.altKey && !dlg.open && S.versionId) {
      const z = e.code === 'Equal' || e.code === 'NumpadAdd' || e.key === '=' || e.key === '+' ? 'in'
        : e.code === 'Minus' || e.code === 'NumpadSubtract' || e.key === '-' || e.key === '_' ? 'out'
        : e.code === 'Digit0' || e.code === 'Numpad0' ? 'fit' : '';
      if (z) {
        e.preventDefault();
        if (z === 'in') zoomBy(1 / 2); else if (z === 'out') zoomBy(2); else zoomFit();
        return;
      }
    }
    if (dlg.open || e.metaKey || e.ctrlKey || e.altKey) return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
    const k = e.key;
    if (k === ' ') { e.preventDefault(); playPause(); }
    else if (k === 'k' || k === 'K') { e.preventDefault(); shuttle.kHeld = true; setShuttle(0); }
    else if (k === 'l' || k === 'L') { e.preventDefault(); if (shuttle.kHeld) stepFrames(1); else if (!e.repeat) shuttleKey(1); }
    else if (k === 'j' || k === 'J') { e.preventDefault(); if (shuttle.kHeld) stepFrames(-1); else if (!e.repeat) shuttleKey(-1); }
    else if (k === 'ArrowRight') { e.preventDefault(); stepFrames(e.shiftKey ? tcBase() : 1); }
    else if (k === 'ArrowLeft') { e.preventDefault(); stepFrames(e.shiftKey ? -tcBase() : -1); }
    else if (k === 'n') jumpNote(1);
    else if (k === 'p') jumpNote(-1);
    else if (k === 'm' && can('reviewer')) openAddNote();
    else if (k === 'o' && can('editor')) {
      const n = activeNotes().notes.filter((x) => x.frame < S.frame).pop();
      if (n) saveOut(n, S.frame, n.end); else toast('Play past a note, then press O to set where it ends.');
    }
    else if (k === '=' || k === '+') zoomBy(1 / 2);
    else if (k === '-' || k === '_') zoomBy(2);
    else if (k === '0') zoomFit();
    else if (k === '?') { e.preventDefault(); openHelp(); }
    else if (k === 't') setTab(S.tab === 'script' ? 'notes' : 'script');
    else if (k === '1' || k === '2' || k === '3') setView(VIEWS[+k - 1]);
    else if ((k === 'a' || k === 'd' || k === 'w') && can('editor')) {
      const n = cardNote();
      if (!n) { toast('Go to a note first (N / P), then press A, D or W.'); return; }
      changeStatus(n, { a: 'addressed', d: 'discuss', w: 'wont_do' }[k]);
    }
    else if (k === 'r' && can('reviewer')) {
      const ta = $('nowCard').querySelector('.actions textarea');
      if (ta) { e.preventDefault(); ta.focus(); }
    }
  });

  if (!WS) {
    // Not in any production yet (or every one is closed to them).
    document.body.classList.add('no-ws');
    if (PLATFORM) openPlatform();
    else showDialog(el('h2', { text: 'No productions yet' }),
      el('p', { text: 'You’re signed in, but you don’t have access to a production yet. Ask the admin of your company to give you access in People.' }),
      el('div', { class: 'dlg-actions' }, el('a', { class: 'btn', href: '?signout=1', text: 'Sign out' })));
    return;
  }
  if ('archived' in document.body.dataset) {
    const b = $('tcFix').cloneNode(false);
    b.id = 'archivedBar'; b.hidden = false; b.className = 'banner archived';
    b.textContent = 'This production is archived, so it’s read-only. A company admin can reopen it in Company and productions.';
    document.querySelector('.stage').prepend(b);
  }
  loadState().then(() => {
    setView(view);
    const q = new URLSearchParams(location.search);
    const fio = q.get('fio');
    if (!fio) return;
    history.replaceState(null, '', location.pathname + `?ws=${WS}` + location.hash.replace('#settings', ''));
    if (fio === 'connected') toast('Signed in with Adobe. Frame.io is connected.');
    if (fio === 'error') toast(q.get('msg') || 'The Adobe sign-in didn’t finish.');
    if (fio !== 'connected' && companyAdmin()) openSettings();
  }).catch(fail);
})();

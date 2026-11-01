"""Documentary-style test edit: cut A, re-edited cut B, and ground truth for notes placed in A."""
import json, math, random, subprocess, sys, zlib
import numpy as np, cv2
from PIL import Image, ImageDraw, ImageFont
from skimage import data as skd

OUT = sys.argv[1] if len(sys.argv) > 1 else '/tmp/claude-0/bench'
W, H, FPS = 960, 540, 24
SEED = int(__import__('os').environ.get('SEED', '7'))
R = random.Random(SEED)

def rgbf(img):
    a = np.asarray(img)
    if a.ndim == 2: a = np.stack([a] * 3, -1)
    a = a[..., :3].astype(np.float32)
    if a.max() <= 1.0: a *= 255
    return a
def cover(a, w, h):
    s = max(w / a.shape[1], h / a.shape[0])
    a = cv2.resize(a, (math.ceil(a.shape[1] * s), math.ceil(a.shape[0] * s)), interpolation=cv2.INTER_CUBIC)
    y, x = (a.shape[0] - h) // 2, (a.shape[1] - w) // 2
    return a[y:y + h, x:x + w].copy()

def landscape(seed):
    rr = np.random.default_rng(seed)
    w, h = 1600, 900
    img = np.zeros((h, w, 3), np.float32)
    top, bot = rr.uniform(120, 230, 3), rr.uniform(150, 255, 3)
    for y in range(h): img[y] = top + (bot - top) * y / h
    for layer in range(rr.integers(3, 6)):
        base = h * rr.uniform(0.35, 0.8)
        xs = np.arange(w)
        ys = base + sum(rr.uniform(10, 60) * np.sin(xs / rr.uniform(40, 300) + rr.uniform(0, 6)) for _ in range(4))
        col = rr.uniform(20, 160, 3)
        mask = np.arange(h)[:, None] > ys[None, :]
        img[mask] = img[mask] * 0.25 + col * 0.75
    tex = cv2.GaussianBlur(rr.normal(0, 25, (h, w)).astype(np.float32), (0, 0), 3)
    img += tex[..., None]
    for _ in range(rr.integers(5, 25)):  # trees / rocks / buildings
        x, y = rr.integers(0, w), rr.integers(int(h * .4), h)
        cv2.ellipse(img, (int(x), int(y)), (int(rr.integers(6, 40)), int(rr.integers(10, 70))), 0, 0, 360, tuple(float(c) for c in rr.uniform(10, 120, 3)), -1)
    return np.clip(img, 0, 255)

PHOTOS = {k: rgbf(getattr(skd, k)()) for k in ['astronaut', 'coffee', 'chelsea', 'rocket', 'camera', 'coins', 'moon', 'page', 'retina', 'brick', 'grass', 'gravel', 'hubble_deep_field', 'immunohistochemistry', 'cell', 'text', 'clock', 'horse', 'colorwheel']}
for i in range(10): PHOTOS[f'land{i}'] = landscape(100 + i)
for k in PHOTOS: PHOTOS[k] = cover(PHOTOS[k], 1600, 900)

def grain(seed, sigma):
    rr = np.random.default_rng(seed)
    return rr.normal(0, sigma, (H // 2, W // 2)).astype(np.float32).repeat(2, 0).repeat(2, 1)[..., None]

def crop_zoom(img, z, cx, cy):
    # view of size W×H at zoom z (1 = whole 1600 wide image shown) centred on (cx, cy) in image fractions
    ih, iw = img.shape[:2]
    vw = iw / z; vh = vw * H / W
    x0 = min(max(cx * iw - vw / 2, 0), iw - vw); y0 = min(max(cy * ih - vh / 2, 0), ih - vh)
    M = np.float32([[W / vw, 0, -x0 * W / vw], [0, W / vw, -y0 * W / vw]])
    return cv2.warpAffine(img, M, (W, H), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)

SMOOTH = {}
def wobble(key, s, amp, periods):
    if key not in SMOOTH:
        rk = random.Random(zlib.crc32(key.encode()))
        SMOOTH[key] = [rk.uniform(0, 6.28) for _ in periods]
    return amp * sum(math.sin(2 * math.pi * s / p + ph) for p, ph in zip(periods, SMOOTH[key])) / len(periods)

# ---------- source clips ----------
class Clip:
    def __init__(self, name, kind, length, **kw): self.name, self.kind, self.length, self.kw = name, kind, length, kw
    def frame(self, s):
        k, kw = self.kind, self.kw
        fi = int(round(s * FPS))
        if k == 'interview':
            bg = kw['bg']
            img = bg.copy()
            hx = kw['x'] + wobble(self.name + 'x', s, 10, [4.1, 1.7, 7.3]) + 6 * math.sin(s / 13)
            hy = kw['y'] + wobble(self.name + 'y', s, 4, [2.9, 5.3])
            cv2.ellipse(img, (int(hx), int(hy + 200)), (150, 120), 0, 180, 360, kw['shirt'], -1)
            cv2.rectangle(img, (int(hx - 150), int(hy + 200)), (int(hx + 150), H), kw['shirt'], -1)
            cv2.ellipse(img, (int(hx), int(hy)), (62, 82), wobble(self.name + 'r', s, 6, [3.3, 6.1]), 0, 360, kw['skin'], -1)
            cv2.ellipse(img, (int(hx), int(hy - 60)), (66, 40), 0, 180, 360, kw['hair'], -1)
            blink = (s % kw['blink']) < 0.15
            for ex in (-22, 22):
                cv2.ellipse(img, (int(hx + ex), int(hy - 12)), (9, 2 if blink else 6), 0, 0, 360, (30, 25, 25), -1)
            talk = abs(math.sin(s * 7.3) * math.sin(s * 2.1 + 1)) * (0.5 + 0.5 * math.sin(s / 1.9))
            cv2.ellipse(img, (int(hx), int(hy + 38)), (18, int(2 + 12 * talk)), 0, 0, 360, (90, 30, 40), -1)
            gest = wobble(self.name + 'g', s, 1, [2.3, 3.7])
            if gest > 0.25:  # hand gesture coming into frame
                cv2.ellipse(img, (int(hx + 170 + 60 * gest), int(H - 40 - 120 * gest)), (35, 45), 20, 0, 360, kw['skin'], -1)
            return np.clip(img + grain(zlib.crc32(f'{self.name}/{fi}'.encode()) & 0xffff, 2.5), 0, 255)
        if k == 'kb':
            t = s / self.length
            img = crop_zoom(kw['img'], kw['z0'] + (kw['z1'] - kw['z0']) * t, kw['x0'] + (kw['x1'] - kw['x0']) * t, kw['y0'] + (kw['y1'] - kw['y0']) * t)
            return img if not kw.get('grain') else np.clip(img + grain(zlib.crc32(f'{self.name}/{fi}'.encode()) & 0xffff, kw['grain']), 0, 255)
        if k == 'still':
            if 'cache' not in kw: kw['cache'] = crop_zoom(kw['img'], kw['z'], 0.5, 0.5)
            return kw['cache']
        if k == 'handheld':
            cx = 0.5 + kw['drift'] * (s / self.length - 0.5) + wobble(self.name + 'hx', s, 0.012, [1.3, 2.7, 0.7])
            cy = 0.5 + wobble(self.name + 'hy', s, 0.01, [1.1, 2.3, 0.9])
            img = crop_zoom(kw['img'], 1.35 + wobble(self.name + 'hz', s, 0.03, [5.0]), cx, cy)
            for b in range(kw.get('birds', 0)):  # things moving through frame
                bx = (b * 397 + s * kw['speed'] * (1 + b * 0.3)) % (W + 200) - 100
                by = 120 + 60 * b + 20 * math.sin(s * 2 + b)
                cv2.ellipse(img, (int(bx), int(by)), (14, 5), 10 * math.sin(s * 9 + b), 0, 360, (20, 20, 20), -1)
            return np.clip(img + grain(zlib.crc32(f'{self.name}/{fi}'.encode()) & 0xffff, 3), 0, 255)
        if k == 'fire':
            tex = kw['tex']
            off = int(s * 140) % (tex.shape[0] - H)
            f = tex[off:off + H].copy()
            f = f * (0.8 + 0.2 * math.sin(s * 5.1)) + kw['base']
            return np.clip(f, 0, 255)
        if k == 'title':
            if 'cache' not in kw:
                im = Image.new('RGB', (W, H), kw['bg'])
                d = ImageDraw.Draw(im)
                f = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf', 54)
                d.text((W // 2, H // 2), kw['text'], fill=(235, 230, 220), font=f, anchor='mm')
                kw['cache'] = np.asarray(im).astype(np.float32)
            a = min(1, s / 1.0)
            return kw['cache'] * a + np.float32(kw['bg']) * (1 - a)
        raise ValueError(k)

clips = {}
def add(c): clips[c.name] = c; return c
def blurbg(k, tint):
    return np.clip(cv2.GaussianBlur(crop_zoom(PHOTOS[k], 1.0, 0.5, 0.5), (0, 0), 9) * 0.7 + np.float32(tint) * 0.3, 0, 255)
add(Clip('int_maya', 'interview', 900, bg=blurbg('coffee', (40, 50, 60)), x=380, y=230, shirt=(40, 70, 120), skin=(200, 160, 130), hair=(40, 30, 25), blink=3.7))
add(Clip('int_joe', 'interview', 900, bg=blurbg('land3', (60, 40, 30)), x=600, y=220, shirt=(110, 100, 90), skin=(150, 105, 80), hair=(20, 20, 20), blink=4.3))
add(Clip('int_ana', 'interview', 600, bg=blurbg('brick', (30, 30, 30)), x=480, y=240, shirt=(150, 40, 40), skin=(220, 180, 150), hair=(120, 80, 40), blink=3.1))
photos = list(PHOTOS)
R.shuffle(photos)
for i, p in enumerate(photos[:16]):
    z0 = R.uniform(1.0, 1.3)
    add(Clip(f'kb_{p}', 'kb', 30, img=PHOTOS[p], z0=z0, z1=z0 * R.uniform(1.08, 1.35), x0=R.uniform(.4, .6), x1=R.uniform(.4, .6), y0=R.uniform(.4, .6), y1=R.uniform(.4, .6), grain=R.choice([0, 0, 3])))
for p in photos[16:22]:
    add(Clip(f'still_{p}', 'still', 60, img=PHOTOS[p], z=R.uniform(1.0, 1.2)))
for i in range(10):
    add(Clip(f'hh_land{i}', 'handheld', 40, img=PHOTOS[f'land{i}'], drift=R.uniform(-0.15, 0.15), birds=R.choice([0, 0, 2, 4]), speed=R.uniform(40, 120)))
for i in range(3):
    rr = np.random.default_rng(500 + i)
    tex = cv2.GaussianBlur(rr.normal(0, 70, (H * 6, W)).astype(np.float32), (0, 0), 7 + i * 3)
    tex = np.stack([tex * 1.0, tex * 0.55, tex * 0.15], -1)
    add(Clip(f'fire{i}', 'fire', 40, tex=tex, base=np.float32([140, 60, 10])))
for i, t in enumerate(['Part One: The Burn', 'Part Two: Rebuilding', 'Part Three: Seeds', 'Ten Years Later', 'Credits']):
    add(Clip(f'title{i}', 'title', 10, text=t, bg=(12, 12, 14)))

# ---------- cut A ----------
broll = [n for n in clips if n.startswith(('kb_', 'hh_', 'fire', 'still_'))]
ints = ['int_maya', 'int_joe', 'int_ana']
pos = {k: R.uniform(10, 200) for k in ints}
used_src = {}
def src_take(name, dur):
    c = clips[name]
    if c.kind == 'interview':
        s = pos[name]; pos[name] += dur + R.uniform(2, 25)
        return s
    lo = used_src.get(name, 2.0)
    s = min(lo, c.length - dur - 3); used_src[name] = s + dur + 1
    return max(1.0, s)
A = []
def put(name, dur):
    A.append({'clip': name, 'src': round(src_take(name, dur) * FPS) / FPS, 'dur': round(dur * FPS) / FPS})
title = 0
for sec in range(int(__import__('os').environ.get('SECTIONS', '4'))):
    put(f'title{min(title, 3)}', 3.5); title += 1
    for blk in range(4):
        who = R.choice(ints)
        put(who, R.uniform(4, 10))
        for _ in range(R.randint(1, 3)): put(R.choice(broll), R.uniform(1.8, 5.5))
        if R.random() < 0.5: put(who, R.uniform(3, 8))
put('title4', 4)
for i, s in enumerate(A): s['id'] = i
print('A shots', len(A), 'length', sum(s['dur'] for s in A))

# ---------- cut B: the re-edit ----------
B = []
ops = {}
def op(i, k): ops.setdefault(i, []).append(k)
GRADES = [dict(c=1.25, g=0.85, sat=1.3, tint=(10, 0, -12), lift=-8), dict(c=0.85, g=1.15, sat=0.7, tint=(-8, 4, 12), lift=12),
          dict(c=1.4, g=1.0, sat=0.0, tint=(0, 0, 0), lift=-15), dict(c=1.1, g=0.75, sat=1.1, tint=(18, 6, -20), lift=0),
          dict(c=0.95, g=1.3, sat=1.4, tint=(-12, 8, 18), lift=6)]
idx = list(range(1, len(A) - 1))
R.shuffle(idx)
take = lambda n: [idx.pop() for _ in range(n)]
for i in take(6): op(i, 'remove')
for i in take(8): op(i, 'trim_head')
for i in take(8): op(i, 'trim_tail')
for i in take(3): op(i, 'trim_both')
for i in take(4): op(i, 'extend_head')
for i in take(8): op(i, ('grade', R.randrange(len(GRADES))))
for i in take(5): op(i, ('reframe', R.uniform(1.12, 1.3), R.uniform(-.06, .06), R.uniform(-.05, .05)))
for i in take(5): op(i, 'vfx')
for i in take(2): op(i, ('retime', R.choice([0.8, 1.25])))
for i in [j for j in idx if A[j]['clip'].startswith('int_')][:4]: op(i, 'lower_third')
order = list(range(len(A)))
# move a block of 4 shots later; swap two interview bites
mv = 20
block = order[mv:mv + 4]; del order[mv:mv + 4]; order[48:48] = block
ib = [i for i in order if A[i]['clip'].startswith('int_') and i not in block][5:7]
a, b = order.index(ib[0]), order.index(ib[1]); order[a], order[b] = order[b], order[a]
inserts = {12: ('int_maya', 6.0), 33: ('kb_new', 4.0), 41: ('int_joe', 5.0), 57: ('hh_land9', 3.0), 64: ('fire2', 3.5), 70: ('int_ana', 4.5)}
add(Clip('kb_new', 'kb', 30, img=PHOTOS['land7'], z0=1.1, z1=1.4, x0=.3, x1=.6, y0=.5, y1=.4))
for k, i in enumerate(order):
    if k in inserts:
        name, dur = inserts[k]
        B.append({'clip': name, 'src': round(src_take(name, dur) * FPS) / FPS, 'dur': dur, 'from': None, 'fx': ['new']})
    s = dict(A[i]); s['from'] = i; s['fx'] = []
    ol = ops.get(i, [])
    if 'remove' in ol: continue
    d = s['dur']
    if 'trim_head' in ol: c = round(min(d * .45, R.uniform(.5, 2.5)) * FPS) / FPS; s['src'] += c; s['dur'] -= c
    if 'trim_tail' in ol: s['dur'] -= round(min(d * .45, R.uniform(.5, 2.5)) * FPS) / FPS
    if 'trim_both' in ol:
        c = round(min(d * .25, R.uniform(.4, 1.2)) * FPS) / FPS; s['src'] += c; s['dur'] -= 2 * c
    if 'extend_head' in ol:
        c = round(R.uniform(.5, 1.5) * FPS) / FPS; s['src'] = max(0.5, s['src'] - c); s['dur'] += c
    for o in ol:
        if isinstance(o, tuple) or o in ('vfx', 'lower_third'): s['fx'].append(o)
    B.append(s)
for k, s in enumerate(B): s['id'] = k
# dissolves in B at three joins
dissolves = sorted(R.sample(range(5, len(B) - 5), 3))
print('B shots', len(B), 'length', sum(s['speed_dur'] if 'speed_dur' in s else s['dur'] for s in B))

def retime(s):
    for o in s.get('fx', []):
        if isinstance(o, tuple) and o[0] == 'retime': return o[1]
    return 1.0

# record times
def layout(cut):
    t = 0.0
    for s in cut:
        s['rec'] = round(t * FPS)
        s['n'] = round(s['dur'] / retime(s) * FPS)
        t = (s['rec'] + s['n']) / FPS
    return round(t * FPS)
nA, nB = layout(A), layout(B)

def apply_fx(img, s, s_t):
    for o in s.get('fx', []):
        if isinstance(o, tuple) and o[0] == 'grade':
            g = GRADES[o[1]]
            x = np.clip(img / 255, 0, 1) ** g['g']
            x = (x - 0.5) * g['c'] + 0.5
            l = x.mean(-1, keepdims=True); x = l + (x - l) * g['sat']
            img = np.clip(x * 255 + np.float32(g['tint']) + g['lift'], 0, 255)
        elif isinstance(o, tuple) and o[0] == 'reframe':
            z, dx, dy = o[1], o[2], o[3]
            M = np.float32([[z, 0, W / 2 - z * (W / 2 + dx * W)], [0, z, H / 2 - z * (H / 2 + dy * H)]])
            img = cv2.warpAffine(img, M, (W, H), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)
        elif o == 'vfx':
            # temp → final: the top third replaced (sky replacement) and a comp element added
            rr = np.random.default_rng(s['from'] or 0)
            sky = np.linspace(np.float32([60, 110, 190]), np.float32([200, 190, 170]), H // 3)[:, None, :]
            img = img.copy(); img[:H // 3] = img[:H // 3] * 0.15 + sky * 0.85
            cx = int(W * (0.3 + 0.4 * rr.random()) + 30 * math.sin(s_t))
            cv2.circle(img, (cx, int(H * 0.62)), 70, (250, 160, 40), -1)
        elif o == 'lower_third':
            img = img.copy(); img[H - 130:H - 60, 40:520] = img[H - 130:H - 60, 40:520] * 0.3 + 10
            u8 = np.clip(img, 0, 255).astype(np.uint8)
            cv2.putText(u8, 'Maya Lindqvist, Fire Ecologist', (60, H - 85), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (240, 240, 240), 2, cv2.LINE_AA)
            img = u8.astype(np.float32)
    return img

def seg_frame(s, k):
    st = s['src'] + k * retime(s) / FPS
    return apply_fx(clips[s['clip']].frame(st), s, st)

def render(cut, n, path, size, crf, joins=()):
    p = subprocess.Popen(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', f'{size[0]}x{size[1]}', '-r', str(FPS), '-i', '-',
                          '-c:v', 'libvpx-vp9', '-crf', str(crf), '-b:v', '0', '-row-mt', '1', '-cpu-used', '5', '-deadline', 'realtime', '-g', '48', '-pix_fmt', 'yuv420p', path], stdin=subprocess.PIPE)
    si = 0
    for f in range(n):
        while si + 1 < len(cut) and f >= cut[si + 1]['rec']: si += 1
        s = cut[si]
        img = seg_frame(s, f - s['rec'])
        if si + 1 < len(cut) and si in joins and f >= cut[si + 1]['rec'] - 6:   # dissolve: 12 frames centred on the cut
            nx = cut[si + 1]; a = (f - (nx['rec'] - 6)) / 12
            img = img * (1 - a) + seg_frame(nx, f - nx['rec']) * a
        elif si in joins or (si - 1) in joins:
            pv = cut[si - 1]
            if si - 1 in joins and f < s['rec'] + 6:
                a = (f - (s['rec'] - 6)) / 12
                img = seg_frame(pv, f - pv['rec']) * (1 - a) + img * a
        if size != (W, H): img = cv2.resize(img, size, interpolation=cv2.INTER_CUBIC)
        p.stdin.write(np.clip(img, 0, 255).astype(np.uint8).tobytes())
        if f % 1000 == 0: print(path, f, '/', n, flush=True)
    p.stdin.close(); p.wait()

# ---------- notes and truth ----------
def src_at(cut, frame):
    for s in cut:
        if s['rec'] <= frame < s['rec'] + s['n']:
            return s, s['src'] + (frame - s['rec']) * retime(s) / FPS
    return None, None
static_kind = lambda name: clips[name].kind in ('still', 'title')
notes = []
nr = random.Random(SEED + 4)
for k in range(220):
    f = nr.randrange(0, nA - 1)
    s, st = src_at(A, f)
    if s is None: continue
    truth, near = [], []
    for b in B:
        if b['clip'] != s['clip']: continue
        lo, hi = b['src'], b['src'] + b['n'] * retime(b) / FPS
        if lo - 1e-6 <= st < hi:
            truth.append(b['rec'] + round((st - b['src']) / retime(b) * FPS))
        elif min(hi, s['src'] + s['dur']) > max(lo, s['src']):   # same take, but this moment trimmed out
            near.append([b['rec'], b['rec'] + b['n'] - 1])
    fx = sorted(set((o[0] if isinstance(o, tuple) else o) for b in B if b['clip'] == s['clip'] and b.get('from') == s['id'] for o in b['fx']))
    kind = 'found' if truth else 'trimmed' if near else 'removed'
    notes.append({'n': len(notes), 'frameA': f, 'clip': s['clip'], 'ckind': clips[s['clip']].kind, 'static': static_kind(s['clip']), 'truth': truth, 'near': near, 'kind': kind, 'fx': fx})
json.dump({'fps': FPS, 'nA': nA, 'nB': nB, 'A': [{k: v for k, v in s.items()} for s in A], 'B': B, 'ops': {str(k): [list(o) if isinstance(o, tuple) else o for o in v] for k, v in ops.items()}, 'dissolves': dissolves, 'notes': notes}, open(f'{OUT}/truth.json', 'w'), default=str, indent=0)
from collections import Counter
print(Counter(n['kind'] for n in notes), Counter(n['ckind'] for n in notes), Counter(tuple(n['fx']) for n in notes))
if 'norender' not in sys.argv:
    if 'onlyB' not in sys.argv: render(A, nA, f'{OUT}/cutA.webm', (W, H), 36)
    render(B, nB, f'{OUT}/cutB.webm', (1280, 720), 33, joins=set(dissolves))

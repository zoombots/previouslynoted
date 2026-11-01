"""Generate two versions of a 44-minute cut with OpenTimelineIO, export them as
.otio, FCP7 XML and CMX3600 EDL, and record what changed between them so the
note-placement can be checked against a known answer."""
import json, random, pathlib, copy
import opentimelineio as otio

OUT = pathlib.Path(__file__).parent
RATE = 24
rng = random.Random(14)

def rt(f): return otio.opentime.RationalTime(f, RATE)
def tr(s, d): return otio.opentime.TimeRange(rt(s), rt(d))

# A library of source clips: archival, interviews, wildlife, drone.
families = ['FIRE_ARCHIVE', 'INTERVIEW_JAMES', 'MOOSE_A', 'BOREAL_DRONE', 'WETLAND', 'BISON', 'SMOKE_TL', 'CREW_BURN', 'SQUIRREL', 'MAP_GFX']
sources = []
for fam in families:
    for i in range(1, 21):
        sources.append({'name': f'{fam}_{i:03d}', 'file': f'{fam}_{i:03d}.mov', 'len': rng.randint(24 * 20, 24 * 120),
                        'tc': rng.randint(0, 20) * 24 * 3600 // 4})

def make_clip(src, s_in, dur):
    ref = otio.schema.ExternalReference(target_url=f'file:///Volumes/RFTA/media/{src["file"]}',
                                        available_range=tr(src['tc'], src['len']))
    return otio.schema.Clip(name=src['name'], media_reference=ref, source_range=tr(src['tc'] + s_in, dur))

# ---- v1: ~63,200 frames of picture ----
shots = []
total = 0
while total < 63200:
    src = rng.choice(sources)
    dur = rng.randint(24 * 2, 24 * 9)
    s_in = rng.randint(0, src['len'] - dur)
    shots.append({'src': src, 's_in': s_in, 'dur': dur, 'id': len(shots)})
    total += dur

# VO lines: ~260 lines with gaps
vo = []
pos = 24 * 8
i = 1
while pos < total - 24 * 10:
    dur = rng.randint(24 * 3, 24 * 9)
    vo.append({'name': f'VO_LINE_{i:03d}', 'file': f'VO_LINE_{i:03d}.wav', 'rec': pos, 'dur': dur, 'take': 1})
    pos += dur + rng.randint(24, 24 * 6)
    i += 1

def build(shots, vo, name, dissolve_after=()):
    tl = otio.schema.Timeline(name=name, global_start_time=rt(24 * 3600))
    v1 = otio.schema.Track(name='V1', kind=otio.schema.TrackKind.Video)
    for k, s in enumerate(shots):
        if k and k % 37 == 0:
            v1.append(otio.schema.Transition(transition_type=otio.schema.TransitionTypes.SMPTE_Dissolve,
                                             in_offset=rt(12), out_offset=rt(12)))
        v1.append(make_clip(s['src'], s['s_in'], s['dur']))
    v2 = otio.schema.Track(name='V2', kind=otio.schema.TrackKind.Video)
    a1 = otio.schema.Track(name='A1', kind=otio.schema.TrackKind.Audio)
    for s in shots:
        a1.append(make_clip(s['src'], s['s_in'], s['dur']))
    a2 = otio.schema.Track(name='A2', kind=otio.schema.TrackKind.Audio)
    cur = 0
    for line in vo:
        if line['rec'] > cur:
            a2.append(otio.schema.Gap(source_range=tr(0, line['rec'] - cur)))
        src = {'name': line['name'], 'file': line['file'], 'len': 24 * 30, 'tc': 0}
        a2.append(make_clip(src, line.get('s_in', 0), line['dur']))
        cur = line['rec'] + line['dur']
    # A few graphics overlays on V2
    cur = 0
    for k, s in enumerate(shots):
        start = sum(x['dur'] for x in shots[:k])
        if s['src']['name'].startswith('MAP_GFX') and k % 2 == 0:
            if start > cur:
                v2.append(otio.schema.Gap(source_range=tr(0, start - cur)))
            title = {'name': f'TITLE_{k:03d}', 'file': f'TITLE_{k:03d}.png', 'len': 24 * 60, 'tc': 0}
            d = min(s['dur'], 24 * 4)
            v2.append(make_clip(title, 0, d))
            cur = start + d
    tl.tracks.extend([v1, v2, a1, a2])
    return tl

v1 = build(shots, vo, 'RFTA Part 1 Oct 14')

# ---- v2: a restructure ----
s2 = [copy.deepcopy(s) for s in shots]
changes = {}
# remove ~6% of shots
for s in rng.sample(s2, len(s2) // 16):
    changes[s['id']] = 'removed'
s2 = [s for s in s2 if changes.get(s['id']) != 'removed']
# trim ~15%: shorten heads/tails
for s in rng.sample(s2, len(s2) // 7):
    cut_head = rng.randint(0, s['dur'] // 3)
    cut_tail = rng.randint(0, s['dur'] // 3)
    s['s_in'] += cut_head
    s['dur'] -= cut_head + cut_tail
    changes.setdefault(s['id'], 'trimmed')
# move a 3-minute block from ~10 min to ~30 min (the "restructure")
a = next(k for k, s in enumerate(s2) if sum(x['dur'] for x in s2[:k]) > 24 * 600)
b = next(k for k, s in enumerate(s2) if sum(x['dur'] for x in s2[:k]) > 24 * 780)
block = s2[a:b]
del s2[a:b]
c = next(k for k, s in enumerate(s2) if sum(x['dur'] for x in s2[:k]) > 24 * 1800)
s2[c:c] = block
for s in block:
    changes.setdefault(s['id'], 'moved')
# insert ~25 new shots
for _ in range(25):
    src = rng.choice(sources)
    dur = rng.randint(48, 200)
    s2.insert(rng.randint(0, len(s2)), {'src': src, 's_in': rng.randint(0, src['len'] - dur), 'dur': dur, 'id': None})
# reuse: duplicate 5 existing shots somewhere else (same source frames twice)
for s in rng.sample([x for x in s2 if x['id'] is not None], 5):
    d = copy.deepcopy(s)
    d['id'] = None
    s2.insert(rng.randint(0, len(s2)), d)
    changes[s['id']] = 'reused'

# VO: recompute positions sequentially with new gaps, rewrite ~10% of lines
vo2 = []
pos = 24 * 8
for line in vo:
    l = dict(line)
    r = rng.random()
    if r < 0.05:
        continue  # line dropped
    if r < 0.12:
        l['name'] = line['name'] + '_R2'  # re-recorded / rewritten line
        l['file'] = line['file'].replace('.wav', '_R2.wav')
    l['rec'] = pos
    vo2.append(l)
    pos += l['dur'] + rng.randint(24, 24 * 7)
v2 = build(s2, vo2, 'RFTA Part 1 Oct 21')

for name, tl in (('v1', v1), ('v2', v2)):
    otio.adapters.write_to_file(tl, str(OUT / f'{name}.otio'))
    otio.adapters.write_to_file(tl, str(OUT / f'{name}.xml'), adapter_name='fcp_xml')
    # EDL: picture track only, as editors usually send.
    pic = otio.schema.Timeline(name=tl.name, global_start_time=tl.global_start_time)
    pic.tracks.append(copy.deepcopy(tl.tracks[0]))
    otio.adapters.write_to_file(pic, str(OUT / f'{name}.edl'), adapter_name='cmx_3600')

# Transcripts as SRT (v1) and Premiere-style timecoded text (v2), driven by the VO lines.
words = 'fire renews the boreal forest clearing space for new growth as animals return to feed on fresh shoots carbon stored in peat for millennia'.split()
def line_text(name):
    r = random.Random(name.replace('_R2', ''))
    t = ' '.join(r.choice(words) for _ in range(r.randint(6, 14))).capitalize() + '.'
    return t if not name.endswith('_R2') else t.replace('.', ', and that changes everything.')

def srt_tc(f):
    s = f / RATE
    return f'{int(s // 3600):02d}:{int(s // 60 % 60):02d}:{int(s % 60):02d},{int(round((s % 1) * 1000)) % 1000:03d}'

with open(OUT / 'v1.srt', 'w') as fh:
    for k, l in enumerate(vo, 1):
        fh.write(f"{k}\n{srt_tc(l['rec'])} --> {srt_tc(l['rec'] + l['dur'])}\n{line_text(l['name'])}\n\n")

def tc(f, start=0):
    f += start
    return f'{f // 86400 % 24:02d}:{f // 1440 % 60:02d}:{f // 24 % 60:02d}:{f % 24:02d}'

with open(OUT / 'v2_transcript.txt', 'w') as fh:
    for l in vo2:
        fh.write(f"{tc(l['rec'], 24 * 3600)} - {tc(l['rec'] + l['dur'], 24 * 3600)}\nNarrator\n{line_text(l['name'])}\n\n")

json.dump({'changes': {str(k): v for k, v in changes.items()}, 'v1_shots': len(shots), 'v2_shots': len(s2),
           'v1_frames': sum(s['dur'] for s in shots), 'v2_frames': sum(s['dur'] for s in s2), 'vo1': len(vo), 'vo2': len(vo2)},
          open(OUT / 'truth_summary.json', 'w'), indent=1)
print(json.load(open(OUT / 'truth_summary.json')) | {'changes': len(changes)})

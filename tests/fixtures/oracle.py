"""Where each carried note should land in v2, worked out independently with OpenTimelineIO.

Picture notes follow the source frame on screen (top video layer that still exists in v2);
narration notes follow the VO clip on A2. Prints a comparison with the app's placements."""
import json, sys
import opentimelineio as otio

v1 = otio.adapters.read_from_file(sys.argv[1])
v2 = otio.adapters.read_from_file(sys.argv[2])
app_v1 = json.load(open(sys.argv[3]))   # notes for v1 from the app (frames)
app_v2 = json.load(open(sys.argv[4]))   # notes for v2 from the app (placements)

def clips(tl, kind):
    out = []
    for ti, track in enumerate([t for t in tl.tracks if t.kind == kind]):
        for c in track:
            if isinstance(c, otio.schema.Clip):
                r = c.range_in_parent()
                out.append((ti, c.name, round(r.start_time.to_frames(24)), round(r.end_time_exclusive().to_frames(24)),
                            round(c.trimmed_range().start_time.value)))
    return out

V1, V2 = clips(v1, 'Video'), clips(v2, 'Video')
A1, A2 = clips(v1, 'Audio'), clips(v2, 'Audio')

def find(f, cl, track=None):
    hits = [c for c in cl if c[2] <= f < c[3] and (track is None or c[0] == track)]
    return sorted(hits, key=lambda c: -c[0])

def locate(name, src, cl, track=None):
    return [c[2] + (src - c[4]) for c in cl if c[1] == name and c[4] <= src < c[4] + (c[3] - c[2]) and (track is None or c[0] == track)]

def expected(f, tag):
    if tag.startswith('N'):
        vo1 = [c for c in A1 if c[0] == 1]   # A2 is the VO track (index 1)
        line = next((c for c in vo1 if c[2] <= f < c[3]), None) \
            or next((c for c in vo1 if 0 < c[2] - f <= 72), None) \
            or next((c for c in reversed(vo1) if 0 <= f - c[3] <= 72), None)
        if line:
            off = min(max(f - line[2], 0), line[3] - line[2] - 1)
            same = [c for c in A2 if c[0] == 1 and c[1] == line[1]]
            if len(same) == 1:
                return same[0][2] + off, 'vo'
            if any(c[0] == 1 and c[1] == line[1] + '_R2' for c in A2):
                return None, 'reworded'
            if not same:
                return None, 'line-removed'
    for c in find(f, V1):
        got = locate(c[1], c[4] + f - c[2], V2)
        if len(got) == 1:
            return got[0], 'picture'
        if len(got) > 1:
            return None, 'ambiguous'
    hit = find(f, V1)
    if not hit:
        return None, 'no-shot'
    return None, 'trimmed' if any(c[1] == hit[-1][1] for c in V2) else 'removed'

pos1 = {n['id']: n for n in app_v1}
agree = differ = 0
rows = {}
for n in app_v2:
    f = pos1[n['id']]['frame']
    exp, kind = expected(f, n['tag'] or '')
    got = n['frame'] if n['confirmed'] else None
    key = (kind, n['reason'] or 'none', 'ok' if (exp is not None and got is not None and abs(got - exp) <= 1) else
           'both-unplaced' if exp is None and got is None else 'MISMATCH')
    rows[key] = rows.get(key, 0) + 1
    if key[2] == 'MISMATCH' and differ < 8:
        print('  mismatch', n['id'], n['tag'], 'v1', f, 'otio', exp, kind, '| app', n['frame'], n['reason'], n['confirmed'])
    if key[2] == 'MISMATCH':
        differ += 1
    else:
        agree += 1
print('OTIO expectation / app reason / outcome:')
for k, v in sorted(rows.items(), key=lambda kv: -kv[1]):
    print(f'  {v:4d}  {k}')
print(f'agree {agree}, differ {differ}')

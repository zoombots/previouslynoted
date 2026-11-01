"""What OpenTimelineIO itself reads from each file: the reference for the PHP readers."""
import json, sys, pathlib, opentimelineio as otio
def dump(path):
    tl = otio.adapters.read_from_file(path)
    out = []
    counts = {'Video': 0, 'Audio': 0}
    for track in tl.tracks:
        counts[track.kind] += 1
        for item in track:
            if not isinstance(item, otio.schema.Clip):
                continue
            r = item.range_in_parent()
            sr = item.trimmed_range()
            url = getattr(item.media_reference, 'target_url', '') or ''
            out.append({'kind': track.kind[0], 'track': counts[track.kind], 'rec_in': round(r.start_time.to_frames(24)),
                        'dur': round(r.duration.to_frames(24)), 'name': item.name, 'file': url.rsplit('/', 1)[-1],
                        'src_in': round(sr.start_time.value)})
    return out
for f in sys.argv[1:]:
    json.dump(dump(f), open(pathlib.Path(f).with_suffix(pathlib.Path(f).suffix + '.truth.json'), 'w'))
    print(f, len(dump(f)))

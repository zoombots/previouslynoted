"""Every API action works on MariaDB, as Francis (owner) on the imported install. Run after scenario.py.
Needs the Frame.io/ElevenLabs mock (port 8099) and the SMTP test server (tests/up.sh)."""
import json, sys, time
from pn import Browser, sql

ROOT = '/home/claude/previouslynoted/tests'
f = Browser()
f.login('francis@example.com', 'recovered-pass1')
fails = []


def call(action, body=None, expect=200, files=None, **q):
    code, d = f.api(action, body, files=files, **q)
    if code != expect or (expect == 200 and isinstance(d, dict) and 'error' in d):
        fails.append(f'{action} {q or ""}: {code} {json.dumps(d)[:200]}')
    return d


st = call('state')
print('films', len(st['films']), 'versions', len(st['versions']), st['connections'])
v = max(st['versions'], key=lambda x: x['note_count'])
vid, film = v['id'], v['film_id']
notes = call('notes', version_id=vid)['notes']
n = notes[0]
call('note.status', {'note_id': n['id'], 'version_id': vid, 'status': 'addressed', 'comment': 'done'})
call('note.reply', {'note_id': n['id'], 'version_id': vid, 'text': 'reply on mariadb'})
call('note.tag', {'note_id': n['id'], 'tag': 'N'})
call('note.position', {'note_id': n['id'], 'version_id': vid, 'frame': 120})
call('note.range', {'note_id': n['id'], 'version_id': vid, 'end': 180})
call('note.edit', {'note_id': n['id'], 'text': n['text'] + ' (edited)'})
new = call('note.create', {'version_id': vid, 'frame': 50, 'text': 'E: a new note'})
after = {x['id']: x for x in call('notes', version_id=vid)['notes']}
assert after[n['id']]['status'] == 'addressed' and after[n['id']]['end'] == 180 and after[n['id']]['frame'] == 120 and after[n['id']]['tag'] == 'N', after[n['id']]
assert after[new['id']]['tag'] == 'E', after[new['id']]
call('version.update', {'version_id': vid, 'label': v['label'] + '!', 'color': 'green', 'rate': 25, 'tc_base': 25})
call('version.update', {'version_id': vid, 'label': v['label'], 'color': v['color'], 'rate': v['rate'], 'tc_base': v['tc_base']})
before = {x['id']: (x['frame'], x['end']) for x in call('notes', version_id=vid)['notes']}
call('version.tc_start', {'version_id': vid, 'start_tc': '00:00:10:00', 'move_notes': 1})
moved = {x['id']: (x['frame'], x['end']) for x in call('notes', version_id=vid)['notes']}
d = 10 * int(v['tc_base'])
for k, (fr, en) in before.items():
    exp = (max(0, fr - d), None if en is None else max(1, en - d))
    if moved[k] != exp:
        fails.append(f'tc_start moved {k}: {before[k]} → {moved[k]}, expected {exp}'); break
call('version.tc_start', {'version_id': vid, 'start_tc': '00:00:00:00'})
fid = call('film.create', {'name': 'Regression film'})['id']
call('film.rename', {'film_id': fid, 'name': 'Regression film 2'})
call('film.update', {'film_id': fid, 'keyterms': 'Alpha, Beta\nGamma'})
v1 = call('version.create', {'film_id': fid, 'label': 'R1'})['id']
for i in range(3):
    call('note.create', {'version_id': v1, 'frame': 100 * i, 'text': f'ARCH-00{i}: archive note {i}'})
call('note.create', {'version_id': v1, 'frame': 400, 'text': 'CG: one'})
call('note.create', {'version_id': v1, 'frame': 500, 'text': 'CG: two'})
det = call('category.detect', film_id=fid)
codes = [c['code'] for c in det['codes']]
assert 'ARCH' in codes and 'CG' in codes, det
ad = call('category.adopt', {'film_id': fid, 'codes': [{'code': 'ARCH'}, {'code': 'CG'}], 'drop_defaults': 1})
assert ad['tagged'] == 5, ad
cats = call('category.save', {'film_id': fid, 'key': 'MX', 'label': 'Music', 'color': 'cat-red'})['categories']
mx = [c for c in cats if c['key'] == 'MX'][0]
call('category.save', {'film_id': fid, 'id': mx['id'], 'key': 'MUS', 'label': 'Music', 'color': 'cat-red'})
cats = call('category.order', {'film_id': fid, 'ids': [c['id'] for c in reversed(cats)]})['categories']
arch = [c for c in cats if c['key'] == 'ARCH'][0]
call('category.delete', {'film_id': fid, 'id': arch['id'], 'move_to': 'CG'})
v2 = call('version.create', {'film_id': fid, 'label': 'R2', 'carry_from': v1})
assert v2['carried'] == 5, v2
with open(f'{ROOT}/fixtures/v1.edl', 'rb') as fh:
    call('version.edit', {'version_id': str(v1)}, files={'edit': ('v1.edl', fh)})
with open(f'{ROOT}/fixtures/v2.edl', 'rb') as fh:
    call('version.edit', {'version_id': str(v2['id'])}, files={'edit': ('v2.edl', fh)})
call('edit.start', {'version_id': v1, 'start_tc': '01:00:00:00'})
call('version.place', {'version_id': v2['id']})
with open(f'{ROOT}/fixtures/v1.srt', 'rb') as fh:
    t1 = call('version.transcript', {'version_id': str(v1)}, files={'transcript': ('v1.srt', fh)})['id']
with open(f'{ROOT}/fixtures/v2_transcript.txt', 'rb') as fh:
    t2 = call('version.transcript', {'version_id': str(v2['id'])}, files={'transcript': ('v2.txt', fh)})['id']
tr = call('transcript', version_id=v2['id'])
assert tr['segments'] and tr['compare'], list(tr)
sp = tr['segments'][0]['speaker']
if sp:
    call('transcript.speaker', {'transcript_id': t2, 'from': sp, 'to': sp + ' X'})
sc = call('script', version_id=v1)
call('script.edit', {'version_id': v1, 'transcript_id': t1, 'seg_idx': 0, 'slot': 0, 'state': 'draft', 'text': 'New wording'})
call('script.edit', {'version_id': v1, 'transcript_id': t1, 'seg_idx': 0, 'slot': 'new', 'state': 'approved', 'text': 'Added line'})
for fmt in ('csv', 'txt'):
    code, _ = f.api('script.export', version_id=v1, format=fmt)
    if code != 200: fails.append(f'script.export {fmt} {code}')
for fmt in ('avid', 'resolve', 'xml'):
    r = f.s.get(f'http://127.0.0.1:8082/api.php', params={'action': 'export.markers', 'ws': f.ws, 'version_id': vid, 'format': fmt, 'scope': 'all'})
    if r.status_code != 200 or len(r.text) < 50: fails.append(f'export.markers {fmt} {r.status_code}')
r = f.s.get('http://127.0.0.1:8082/api.php', params={'action': 'export', 'ws': f.ws, 'film_id': film})
if r.status_code != 200 or r.text.count('\n') < 50: fails.append(f'export csv {r.status_code}')
fp = [x for x in st['versions'] if x['fingerprint']]
if fp:
    r = f.s.get('http://127.0.0.1:8082/api.php', params={'action': 'fingerprint', 'ws': f.ws, 'version_id': fp[0]['id']})
    if r.status_code != 200 or not r.content.startswith(b'PNFP'): fails.append(f'fingerprint {r.status_code}')
    with open('/tmp/claude-0/fp.bin', 'wb') as fh: fh.write(r.content)
    with open('/tmp/claude-0/fp.bin', 'rb') as fh:
        call('fingerprint.save', {'version_id': str(v1), 'source': 'test'}, files={'fp': ('fp.bin', fh)})
vn = call('notes', version_id=v2['id'])['notes']
call('version.place_visual', {'version_id': v2['id'], 'results': [{'note_id': x['id'], 'frame': x['frame'] + 3, 'reason': 'visual', 'confident': True} for x in vn]})
# Frame.io (mock)
linked = [x for x in st['versions'] if x['fio_file_id']]
print('linked versions', [(x['id'], x['label']) for x in linked])
for lv in linked[:2]:
    call('version.sync', {'version_id': lv['id']})
    call('fio.stack', version_id=lv['id'])
    call('version.media', version_id=lv['id'])
    pv = call('fio.push_preview', version_id=lv['id'], scope='all')
    ids = [x['id'] for x in (pv.get('new') or [])][:2]
    if ids:
        call('fio.push', {'version_id': lv['id'], 'note_ids': ids})
# ElevenLabs (mock)
if linked:
    job = call('transcribe.create', {'version_id': linked[0]['id']})
    if job.get('job_id'):
        call('transcribe.run', {'job_id': job['job_id']})
        for _ in range(20):
            s = call('transcribe.status', version_id=linked[0]['id'])['job']
            if s and s['status'] in ('done', 'error'): break
            time.sleep(0.5)
        if not s or s['status'] != 'done': fails.append(f'transcription: {s}')
call('settings')
call('settings.elevenlabs', {'model': 'scribe_v2', 'language': 'en', 'diarize': True})
call('settings.mail.test', {})
call('company')
call('platform')
call('people')
call('account')
call('version.unlink', {'version_id': v1})
call('version.delete', {'version_id': v2['id']})
call('transcript.delete', {'transcript_id': t1})
print(f'{len(fails)} failures')
for x in fails: print(' ', x)
sys.exit(1 if fails else 0)

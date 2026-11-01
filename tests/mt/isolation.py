"""Cross-company isolation: every API action, tried with another company's ids, must be refused (400/403/404),
must not change the database, and must not show anything of the other company.

Run after scenario.py (it reads /tmp/claude-0/pn-scenario.json). Exits non-zero on any failure."""
import json, re, sys
from pn import Browser, sql

SC = json.load(open('/tmp/claude-0/pn-scenario.json'))
B, C = SC['data']['b'], SC['data']['c']
PW = 'test-pass-123'
TABLES = ('organizations users memberships workspaces workspace_access settings films versions notes positions events replies '
          'note_edits edits shots transcripts segments categories script_edits jobs fingerprints fio_pushed').split()
SECRETS = ['Note c one', 'Note c two', 'Film c', 'Secret film', 'Other Co', 'olga@example.com']

actions = re.findall(r"'(GET|POST) ([a-z_.]+)' => \['(\w+)', (?:'(\w+)'|null)\]", open('/home/claude/previouslynoted/app/api.php').read())
assert len(actions) > 70, len(actions)


def checksum():
    rows = sql('CHECKSUM TABLE ' + ', '.join(TABLES))
    users = sql('SELECT id, email, name, platform_role, status, pass_hash, token_hash, epoch FROM users ORDER BY id')
    return rows, users


def bag(v, ws_param):
    """Every kind of id, all pointing at the victim's data."""
    b = {'film_id': v['film'], 'version_id': v['v1'], 'note_id': v['n1'], 'transcript_id': v['transcript'], 'job_id': v['job'],
         'carry_from': v['v1'], 'note_ids': [v['n1'], v['n2']], 'results': [{'note_id': v['n1'], 'frame': 5, 'reason': 'visual', 'confident': True}],
         'id': v.get('user', SC['olga_id']), 'ids': [v['cat']], 'workspace_id': v['ws'], 'org_id': v.get('org', 2),
         'name': 'Hacked', 'label': 'Hacked', 'text': 'Hacked', 'status': 'addressed', 'frame': 1, 'end': 9, 'tag': 'E', 'key': 'ZZ',
         'state': 'draft', 'seg_idx': 0, 'slot': 0, 'from': 'A', 'to': 'B', 'start_tc': '01:00:00:00', 'move_notes': 1, 'color': 'blue',
         'role': 'admin', 'org_role': 'owner', 'link': 'https://f.io/x', 'file_id': '00000000-0000-4000-8000-000000000000',
         'codes': [{'code': 'ZZ'}], 'keyterms': 'x', 'client_id': 'x', 'client_secret': 'y', 'key2': 'z', 'platform_role': 'platform_admin',
         'email': 'hack@example.com', 'q': ''}
    if ws_param:
        b['ws'] = ws_param
    return b


def login(email):
    b = Browser()
    b.login(email, PW)
    return b


failures = []


def attempt(who, label, method, action, body, expect=(400, 403, 404)):
    before = checksum()
    if method == 'GET':
        q = {k: (','.join(map(str, v)) if isinstance(v, list) and not isinstance(v[0], dict) else v) for k, v in body.items() if not isinstance(v, list) or not isinstance(v[0], dict)}
        code, d = who.api(action, ws=False, query=q)
    else:
        code, d = who.api(action, body, ws=False, query={'ws': body['ws']} if 'ws' in body else None)
    after = checksum()
    text = json.dumps(d)
    bad = []
    if code not in expect:
        bad.append(f'status {code}')
    if before != after:
        bad.append('database changed')
    leaked = [s for s in SECRETS if s in text]
    if leaked:
        bad.append('leaked ' + ', '.join(leaked))
    if bad:
        failures.append(f'{label}: {method} {action}: ' + '; '.join(bad) + f' → {text[:160]}')
    return code


def main():
    adam = login('adam@example.com')      # company admin of Rotating Planet
    nora = login('nora@example.com')      # member of Rotating Planet with no production
    mia = login('mia@example.com')        # viewer in Documentaries
    rex = login('rex@example.com')        # reviewer in Documentaries, editor in Doc B
    count = 0
    victim_c = {**C, 'user': SC['olga_id'], 'org': 2}
    for method, action, cls, need in actions:
        if cls == 'self':
            continue
        # 1. A company admin of company 1, with company 2's ids, and with and without their own production.
        for wsp in (None, SC['ws1'], C['ws']):
            attempt(adam, f'adam→Other Co (ws={wsp})', method, action, bag(victim_c, wsp)); count += 1
        # 2. Company 1's ids mixed with one of company 2's: two productions in one request.
        mixed = bag({**B, 'user': SC['rex_id']}, SC['ws2'])
        mixed['note_id'] = C['n1']
        attempt(adam, 'adam mixed', method, action, mixed, expect=(400, 403, 404)); count += 1
        # 3. A member with no access to any production, against their own company's production.
        attempt(nora, 'nora→Doc B', method, action, bag({**B, 'user': SC['rex_id'], 'org': 1}, SC['ws2'])); count += 1
        # 4. Rex (reviewer in Documentaries) with Doc B's ids sent under Documentaries.
        if cls == 'ws':
            attempt(rex, 'rex ws mismatch', method, action, bag({**B, 'user': SC['mia_id'], 'org': 1}, SC['ws1']), expect=(400,)); count += 1
    # 5. Platform actions are unknown to everyone but staff.
    for method, action, cls, need in actions:
        if cls == 'platform':
            for who, name in ((adam, 'adam'), (mia, 'mia')):
                attempt(who, f'{name} platform', method, action, bag(victim_c, None), expect=(404,)); count += 1
    # 6. A viewer can't change anything in their own production.
    own = {'film': 1, 'v1': 1, 'n1': 1, 'n2': 2, 'transcript': 1, 'job': 1, 'cat': 1, 'ws': SC['ws1'], 'user': SC['rex_id'], 'org': 1}
    for method, action, cls, need in actions:
        if cls in ('ws', 'org') and method == 'POST':
            attempt(mia, 'viewer write', method, action, bag(own, SC['ws1']), expect=(403,)); count += 1
    # 7. A missing id and another company's id get the same answer, so ids can't be probed.
    for k, v in (('version_id', C['v1']), ('note_id', C['n1']), ('film_id', C['film']), ('transcript_id', C['transcript'])):
        a = adam.api('notes' if k == 'version_id' else 'export' if k == 'film_id' else 'transcript' if k == 'transcript_id' else 'note.edit',
                     {k: v, 'text': 'x'} if k == 'note_id' else None, ws=False, query=None if k == 'note_id' else {k: v})
        b = adam.api('notes' if k == 'version_id' else 'export' if k == 'film_id' else 'transcript' if k == 'transcript_id' else 'note.edit',
                     {k: 999999, 'text': 'x'} if k == 'note_id' else None, ws=False, query=None if k == 'note_id' else {k: 999999})
        count += 1
        if a != b:
            failures.append(f'probe {k}: {a} vs missing {b}')
    print(f'{count} attempts, {len(failures)} failures')
    for f in failures:
        print(' ', f)
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()

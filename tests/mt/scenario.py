"""Two companies with data, and people with every kind of role. Run after reset.sh legacy.

Company 1 "Rotating Planet" (imported from the 0.7.8 install): production 1 "Documentaries" (the old notes),
production 2 "Doc B" (one film). Company 2 "Other Co": production 3 "Secret film".
People: francis (platform admin, owner of 1), olga (owner of 2), mia (member of 1: viewer in 1),
rex (member of 1: reviewer in 1, editor in 2), nora (member of 1, no access), adam (company admin of 1),
sue (support staff, no company)."""
import re, json, sys
from pn import Browser, sql

PW = 'test-pass-123'
ROOT = '/home/claude/previouslynoted/tests'


def setup():
    b = Browser()
    b.page('', app_name='Previously Noted', db_name='pn_test', db_user='pn', db_pass='pn-pass', db_host='127.0.0.1', db_port='3306')
    r = b.page('', company='Rotating Planet', workspace='Documentaries', email='francis@example.com', password='recovered-pass1')
    assert 'data-csrf' in r.text, 'import failed'
    # The imported Frame.io sign-in has been used up by earlier runs against the mock: sign in with Adobe again.
    r = b.s.get('http://127.0.0.1:8082/oauth.php', params={'start': 1, 'ws': b.ws})
    assert 'fio=connected' in r.url, r.url
    return b


def accept(link, name, pw=PW, existing=False):
    b = Browser()
    path = link.split('/', 3)[3]
    if existing:
        r = b.page(path, password=pw)
    else:
        r = b.page(path, name=name, password=pw, password2=pw)
    assert 'data-csrf' in r.text, 'accept failed: ' + re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', r.text))[:300]
    return b


def ok(res):
    code, d = res
    assert code == 200 and 'error' not in d, (code, d)
    return d


def main():
    f = setup()
    ws1 = f.ws
    d = ok(f.api('workspace.create', {'name': 'Doc B'}))
    ws2 = d['id']
    d = ok(f.api('platform.org.create', {'name': 'Other Co', 'workspace': 'Secret film', 'owner_name': 'Olga', 'owner_email': 'olga@example.com'}))
    ws3 = d['workspace_id']
    olga = accept(d['link'], 'Olga')
    assert olga.ws == ws3, (olga.ws, ws3)
    people = {}
    for name, email, org_role, role in [('Mia', 'mia@example.com', 'member', 'viewer'), ('Rex', 'rex@example.com', 'member', 'reviewer'),
                                        ('Nora', 'nora@example.com', 'member', ''), ('Adam', 'adam@example.com', 'admin', '')]:
        d = ok(f.api('people.invite', {'name': name, 'email': email, 'org_role': org_role, 'role': role}))
        people[name.lower()] = accept(d['link'], name)
    # Rex is an editor in Doc B as well.
    f.ws = ws2
    rex_id = int(sql("select id from users where email='rex@example.com'")[0][0])
    ok(f.api('people.update', {'id': rex_id, 'role': 'editor'}))
    f.ws = ws1
    # Support staff.
    sql("INSERT INTO users (email, name, platform_role, status, pass_hash, created_at) VALUES ('sue@example.com', 'Sue', 'support', 'active', '%s', '2026-11-01T00:00:00Z')"
        % sql("select pass_hash from users where email='mia@example.com'")[0][0].replace('$', '\\$'))
    # Data in Doc B and in Other Co.
    data = {}
    for who, ws, tag in [(f, ws2, 'b'), (olga, ws3, 'c')]:
        who.ws = ws
        film = ok(who.api('film.create', {'name': f'Film {tag}'}))['id']
        v1 = ok(who.api('version.create', {'film_id': film, 'label': f'{tag} v1'}))['id']
        n1 = ok(who.api('note.create', {'version_id': v1, 'frame': 100, 'text': f'Note {tag} one'}))['id']
        n2 = ok(who.api('note.create', {'version_id': v1, 'frame': 200, 'text': f'E: Note {tag} two'}))['id']
        ok(who.api('note.reply', {'note_id': n1, 'version_id': v1, 'text': 'A reply'}))
        v2 = ok(who.api('version.create', {'film_id': film, 'label': f'{tag} v2', 'carry_from': v1}))['id']
        with open(f'{ROOT}/fixtures/v1.srt', 'rb') as fh:
            t = ok(who.api('version.transcript', {'version_id': str(v1)}, files={'transcript': ('v1.srt', fh)}))['id']
        with open(f'{ROOT}/fixtures/v1.edl', 'rb') as fh:
            ok(who.api('version.edit', {'version_id': str(v1)}, files={'edit': ('v1.edl', fh)}))
        cats = ok(who.api('state'))['films'][0]['categories']
        ok(who.api('transcribe.create', {'version_id': v1})) if False else None
        sql(f"INSERT INTO jobs (version_id, kind, status, created_by, created_at, updated_at) VALUES ({v1}, 'transcribe', 'done', 'x', '2026-11-01T00:00:00Z', '2026-11-01T00:00:00Z')")
        job = int(sql(f'select max(id) from jobs where version_id = {v1}')[0][0])
        data[tag] = {'ws': ws, 'film': film, 'v1': v1, 'v2': v2, 'n1': n1, 'n2': n2, 'transcript': t, 'cat': cats[0]['id'], 'job': job}
    olga_id = int(sql("select id from users where email='olga@example.com'")[0][0])
    out = {'ws1': ws1, 'ws2': ws2, 'ws3': ws3, 'data': data, 'olga_id': olga_id, 'rex_id': rex_id,
           'mia_id': int(sql("select id from users where email='mia@example.com'")[0][0])}
    json.dump(out, open('/tmp/claude-0/pn-scenario.json', 'w'))
    print(json.dumps(out))


if __name__ == '__main__':
    main()

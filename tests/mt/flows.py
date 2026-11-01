"""Invites for people who already have an account, forgot password, suspending a company, archiving a production,
the last owner, and support staff. Run after scenario.py."""
import json, re, sys, glob, os, time
from pn import Browser, sql

SC = json.load(open('/tmp/claude-0/pn-scenario.json'))
PW = 'test-pass-123'
fails = []


def check(ok, msg):
    if not ok:
        fails.append(msg)


def login(email, pw=PW):
    b = Browser(); b.login(email, pw); return b


olga = login('olga@example.com'); olga.ws = SC['ws3']
f = login('francis@example.com', 'recovered-pass1')
# 1. Olga invites Rex (who has an account with Rotating Planet) to Other Co as an editor of Secret film.
code, d = olga.api('people.invite', {'name': 'Rex', 'email': 'rex@example.com', 'org_role': 'member', 'role': 'editor'})
check(code == 200 and d.get('existing'), f'invite existing: {code} {d}')
path = d['link'].split('/', 3)[3]
anon = Browser()
r = anon.page(path)
check('sign in to join' in r.text, 'existing-account invite asks to sign in')
r = anon.page(path, password='wrong-password-1')
check('don’t match' in r.text, 'wrong password refused on invite')
r = anon.page(path, password=PW)
check('data-csrf' in r.text, 'Rex joined Other Co')
code, d = anon.api('workspaces')
check(sorted(w['id'] for w in d['workspaces']) == sorted([SC['ws1'], SC['ws2'], SC['ws3']]), f'Rex has three productions: {d}')
anon.ws = SC['ws3']
check(anon.api('state')[1]['workspace']['role'] == 'editor', 'Rex is editor in Secret film')
# The same link doesn't work twice; a link for someone else doesn't work while signed in as Rex.
r = anon.page(path)
check('doesn’t work any more' in r.text, 'used invite link is dead')
code, d = olga.api('people.invite', {'name': 'Zed', 'email': 'zed@example.com', 'org_role': 'member', 'role': 'viewer'})
r = anon.page(d['link'].split('/', 3)[3])
check('Sign out and continue' in r.text, 'invite for someone else while signed in')
# 2. Forgot password: an email goes out with a working link.
inbox = '/home/claude/build/smtp/inbox'
before = set(glob.glob(inbox + '/*.eml'))
b = Browser()
r = b.page('?forgot=1', email='mia@example.com')
check('on its way' in r.text, 'forgot password answer')
r = b.page('?forgot=1', email='nobody@example.com')
check('on its way' in r.text, 'same answer for unknown email')
time.sleep(1)
new = [p for p in set(glob.glob(inbox + '/*.eml')) - before]
import quopri
body = quopri.decodestring(open(sorted(new)[-1], 'rb').read()).decode('utf-8', 'replace') if new else ''
m = re.search(r'\?reset=([0-9a-f]{48})', body)
check(len(new) == 1 and m, f'one reset email ({len(new)})')
if m:
    r = b.page('?reset=' + m.group(1), password='new-pass-4567', password2='new-pass-4567')
    check('data-csrf' in r.text, 'reset link sets password')
    login('mia@example.com', 'new-pass-4567')
# 3. Suspend Other Co: Olga can't open it; staff can still see it listed.
code, d = f.api('platform.org.update', {'org_id': 2, 'status': 'suspended'}, ws=False)
check(code == 200, f'suspend {code} {d}')
code, d = olga.api('state')
check(code == 404, f'suspended company closed: {code}')
r = olga.page()
check('data-ws="0"' in r.text, 'suspended company not offered')
olga.ws = SC['ws3']
f.api('platform.org.update', {'org_id': 2, 'status': 'active'}, ws=False)
check(olga.api('state')[0] == 200, 'reactivated')
# 4. Archive Doc B: read-only for Rex (editor), even for Francis; reopening restores it.
f.ws = SC['ws1']
check(f.api('workspace.update', {'workspace_id': SC['ws2'], 'status': 'archived'})[0] == 200, 'archive')
rex = login('rex@example.com'); rex.ws = SC['ws2']
check(rex.api('note.create', {'version_id': SC['data']['b']['v1'], 'frame': 1, 'text': 'x'})[0] == 403, 'archived is read-only')
check(rex.api('notes', version_id=SC['data']['b']['v1'])[0] == 200, 'archived is readable')
f.api('workspace.update', {'workspace_id': SC['ws2'], 'status': 'active'})
check(rex.api('note.create', {'version_id': SC['data']['b']['v1'], 'frame': 1, 'text': 'x'})[0] == 200, 'reopened')
# 5. The last owner can't be demoted or removed; a company admin can't touch an owner.
olga_id = SC['olga_id']
code, d = olga.api('people.update', {'id': olga_id, 'org_role': 'admin'})
check(code == 400 and 'owner' in d.get('error', ''), f'last owner kept: {code} {d}')
adam = login('adam@example.com'); adam.ws = SC['ws1']
francis_id = 1
code, d = adam.api('people.update', {'id': francis_id, 'org_role': 'member'})
check(code == 403, f'admin can’t demote owner: {code}')
code, d = adam.api('people.update', {'id': SC['mia_id'], 'org_role': 'owner'})
check(code == 403, f'admin can’t make owners: {code}')
# A production admin who isn't a company admin can give access but not invite.
f.ws = SC['ws2']
f.api('people.update', {'id': SC['rex_id'], 'role': 'admin'})
code, d = rex.api('people')
check(code == 200 and not d['can_company'], f'production admin sees people: {code}')
check(rex.api('people.update', {'id': SC['mia_id'], 'role': 'viewer'})[0] == 200, 'production admin gives access')
check(rex.api('people.invite', {'name': 'Q', 'email': 'q@example.com'})[0] == 403, 'production admin can’t invite')
check(rex.api('people.update', {'id': SC['mia_id'], 'org_role': 'admin'})[0] == 403, 'production admin can’t change company roles')
# 6. Support: reads any company, can't write, impersonation is read-only and audited.
sue = login('sue@example.com'); sue.ws = SC['ws3']
check(sue.api('notes', version_id=SC['data']['c']['v1'])[0] == 200, 'support reads')
check(sue.api('note.create', {'version_id': SC['data']['c']['v1'], 'frame': 1, 'text': 'x'})[0] == 403, 'support can’t write')
check(sue.api('platform.org.update', {'org_id': 2, 'status': 'suspended'}, ws=False)[0] == 404, 'support can’t suspend')
check(sue.api('platform.impersonate', {'id': olga_id}, ws=False)[0] == 200, 'support impersonates')
check(sue.api('note.create', {'version_id': SC['data']['c']['v1'], 'frame': 1, 'text': 'x'})[0] == 403, 'support impersonation is read-only')
check(sue.api('account.update', {'name': 'Hacked'}, ws=False)[0] == 403, 'no account changes while impersonating')
check(sue.api('platform', ws=False)[0] == 403, 'no staff tools while impersonating')
sue.api('platform.impersonate.stop', {}, ws=False)
code, d = olga.api('company')
acts = [a['action'] for a in d.get('audit', [])]
check('impersonation started' in acts and 'impersonation ended' in acts, f'audit visible to the company: {acts}')
# Platform admin impersonating can write, and it's audited.
check(f.api('platform.impersonate', {'id': olga_id}, ws=False)[0] == 200, 'admin impersonates')
f.ws = SC['ws3']
check(f.api('note.create', {'version_id': SC['data']['c']['v1'], 'frame': 2, 'text': 'by admin as Olga'})[0] == 200, 'admin impersonation writes')
f.api('platform.impersonate.stop', {}, ws=False)
d = olga.api('company')[1]
check(any(a['action'] == 'impersonated note.create' and a['as_name'] == 'Olga' for a in d['audit']), 'write while impersonating audited')
# 7. Removing someone from a company closes it to them at once.
f.ws = SC['ws1']
check(f.api('people.delete', {'id': SC['mia_id']})[0] == 200, 'remove Mia')
mia = Browser(); mia.login('mia@example.com', 'new-pass-4567') if True else None
mia.ws = SC['ws1']
check(mia.api('state')[0] == 404, 'removed member is out')
print(f'{len(fails)} failures')
for x in fails: print(' ', x)
sys.exit(1 if fails else 0)

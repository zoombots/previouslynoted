"""The installer: a 0.7.8 install upgraded into MariaDB, then upgraded again; and a new install into an empty database.
Uses the test site folder (/var/www/pnmt) and database pn_iso. Run: python3 tests/mt/installer.py"""
import os, re, shutil, subprocess, sys, time
import requests
from pn import sql

SITE, BASE, OUT = '/var/www/pnmt', 'http://127.0.0.1:8082/', '/home/claude/previouslynoted/out/install.php'
fails = []
txt = lambda r: re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', r.text))


def check(ok, msg):
    if not ok:
        fails.append(msg)


def fresh_site(legacy):
    subprocess.run(['mariadb', '-e', 'DROP DATABASE IF EXISTS pn_iso; CREATE DATABASE pn_iso CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'], check=True)
    shutil.rmtree(SITE, ignore_errors=True)
    if legacy:
        shutil.copytree('/var/www/pn', SITE, symlinks=True)
        shutil.rmtree(SITE + '/data')
        shutil.copytree('/home/claude/pn-legacy-data', SITE + '/data')
    else:
        os.makedirs(SITE)
    shutil.copy(OUT, SITE + '/install.php')
    subprocess.run(['chown', '-R', 'www-data:www-data', SITE], check=True)
    time.sleep(3)  # Apache may still remember that install.php was deleted a moment ago


def run_installer(fields):
    s = requests.Session()
    r = s.get(BASE + 'install.php')
    csrf = re.search(r'name="csrf" value="([0-9a-f]+)"', r.text).group(1)
    r = s.post(BASE + 'install.php', data={'csrf': csrf, 'step': 'install', **fields})
    return r


DB = {'db_name': 'pn_iso', 'db_user': 'pn', 'db_pass': 'pn-pass', 'db_host': '127.0.0.1', 'db_port': '3306'}

# 1. Upgrade the 0.7.8 install: wrong password first, then right.
fresh_site(True)
r = requests.get(BASE + 'install.php')
check('bring over' in r.text.lower() or 'brings your notes over' in r.text, 'installer offers to bring the notes over')
r = run_installer({'email': 'francis@example.com', 'password': 'nope-nope-nope', 'company': 'Rotating Planet', 'production': 'Documentaries', **DB})
check('isn’t an admin’s email and password' in r.text, 'wrong admin password refused')
r = run_installer({'email': 'francis@example.com', 'password': 'recovered-pass1', 'company': 'Rotating Planet', 'production': 'Documentaries', **DB, 'db_pass': 'wrong'})
check('Couldn’t connect to the database' in r.text, 'wrong database password refused: ' + txt(r)[:200])
check(not os.path.exists(SITE + '/install.php') is False, 'installer still there after failures')
r = run_installer({'email': 'francis@example.com', 'password': 'recovered-pass1', 'company': 'Rotating Planet', 'production': 'Documentaries', **DB})
check('Brought over 5 films, 8 versions and 380 notes' in r.text, 'import log: ' + txt(r)[:400])
check(not os.path.exists(SITE + '/install.php'), 'installer deleted itself')
counts = {t: sql(f'select count(*) from {t}', 'pn_iso')[0][0] for t in ('films', 'versions', 'notes', 'positions', 'shots', 'segments')}
check(counts == {'films': '5', 'versions': '8', 'notes': '380', 'positions': '858', 'shots': '2566', 'segments': '799'}, f'counts {counts}')
s = requests.Session()
r = s.get(BASE)
csrf = re.search(r'name="csrf" value="([0-9a-f]+)"', r.text).group(1)
r = s.post(BASE, data={'csrf': csrf, 'email': 'francis@example.com', 'password': 'recovered-pass1'})
check('data-ws="1"' in r.text, 'sign in after upgrade')
ws_csrf = re.search(r'data-csrf="([0-9a-f]+)"', r.text).group(1)
d = s.get(BASE + 'api.php', params={'action': 'state', 'ws': 1}).json()
check(len(d.get('versions', [])) == 8 and d['connections']['frameio'], f'state after upgrade {str(d)[:200]}')

# 2. Run the new installer again on the upgraded install: needs a platform admin, keeps everything.
shutil.copy(OUT, SITE + '/install.php'); subprocess.run(['chown', 'www-data', SITE + '/install.php']); time.sleep(3)
r = requests.get(BASE + 'install.php')
check('Platform admin email' in r.text, 'update mode asks for a platform admin')
r = run_installer({'email': 'francis@example.com', 'password': 'recovered-pass1'})
check('brought up to date' in r.text, 'update log: ' + txt(r)[:300])
check(sql('select count(*) from notes', 'pn_iso')[0][0] == '380', 'notes kept on update')

# 3. A new install into an empty database.
fresh_site(False)
r = run_installer({'app_name': 'Notes', 'company': 'Acme Docs', 'production': 'Pilot', 'person': 'Pat', 'email': 'pat@example.com',
                   'password': 'pat-password-1', 'password2': 'pat-password-1', **DB})
check('owner of Acme Docs and platform admin' in r.text, 'new install log: ' + txt(r)[:300])
s = requests.Session()
r = s.get(BASE)
csrf = re.search(r'name="csrf" value="([0-9a-f]+)"', r.text).group(1)
r = s.post(BASE, data={'csrf': csrf, 'email': 'pat@example.com', 'password': 'pat-password-1'})
check('Pilot · Notes' in r.text, 'sign in to new install')
# A second new install into the same database is refused.
os.remove(SITE + '/data/config.php')
shutil.copy(OUT, SITE + '/install.php'); subprocess.run(['chown', '-R', 'www-data', SITE]); time.sleep(3)
r = run_installer({'app_name': 'Notes', 'company': 'X', 'production': 'Y', 'person': 'Z', 'email': 'z@example.com',
                   'password': 'zzzzzzzzzz1', 'password2': 'zzzzzzzzzz1', **DB})
check('already has a Previously Noted install' in r.text, 'refuses a database in use: ' + txt(r)[:200])
print(f'{len(fails)} failures')
for f in fails: print(' ', f)
sys.exit(1 if fails else 0)

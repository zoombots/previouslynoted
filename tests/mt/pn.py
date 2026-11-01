"""Small client for the multi-tenant test site. Used by the tests in this folder."""
import json, re, subprocess
import requests

BASE = 'http://127.0.0.1:8082/'
DB = 'pn_test'


def sql(q, db=DB):
    out = subprocess.run(['mariadb', '-N', '-B', db, '-e', q], capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return [line.split('\t') for line in out.stdout.strip().split('\n') if line]


class Browser:
    def __init__(self):
        self.s = requests.Session()
        self.csrf = None
        self.ws = None

    def page(self, path='', **data):
        r = self.s.post(BASE + path, data={**data, 'csrf': self.form_csrf(path)}) if data else self.s.get(BASE + path)
        m = re.search(r'data-csrf="([0-9a-f]+)"', r.text)
        if m:
            self.csrf = m.group(1)
        m = re.search(r'data-ws="(\d+)"', r.text)
        if m:
            self.ws = int(m.group(1)) or None
        return r

    def form_csrf(self, path):
        r = self.s.get(BASE + path)
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', r.text)
        return m.group(1) if m else ''

    def login(self, email, password):
        r = self.page('', email=email, password=password)
        if 'data-csrf' not in r.text:
            raise RuntimeError('login failed: ' + re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', r.text))[:300])
        return r

    def api(self, action, body=None, ws=True, files=None, query=None, **kw):
        q = {'action': action, **(query or {}), **kw}
        if ws and self.ws and 'ws' not in q:
            q['ws'] = self.ws
        h = {'X-CSRF-Token': self.csrf or ''}
        if files is not None:
            r = self.s.post(BASE + 'api.php', params=q, data=body or {}, files=files, headers=h)
        elif body is not None:
            r = self.s.post(BASE + 'api.php', params=q, json=body, headers=h)
        else:
            r = self.s.get(BASE + 'api.php', params=q, headers=h)
        try:
            data = r.json()
        except ValueError:
            data = {'_raw': r.text[:200]}
        return r.status_code, data

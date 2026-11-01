#!/usr/bin/env python3
"""Pack the Previously Noted app into a one-file install.php and a zip."""
import base64, json, pathlib, re, zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'app'
OUT = ROOT / 'out'
TEMPLATE = pathlib.Path(__file__).with_name('install_template.php')

version = re.search(r"define\('APP_VERSION', '([^']+)'\)", (SRC / 'lib/bootstrap.php').read_text()).group(1)

files = {}
for p in sorted(SRC.rglob('*')):
    rel = p.relative_to(SRC).as_posix()
    if p.is_dir() or rel.startswith('data/'):
        continue
    files[rel] = base64.b64encode(p.read_bytes()).decode()

payload = base64.b64encode(json.dumps(files, separators=(',', ':')).encode()).decode()
# Wrap at 76 columns so FTP clients and editors don't choke on one huge line.
payload = '\n'.join(payload[i:i + 76] for i in range(0, len(payload), 76))

OUT.mkdir(exist_ok=True)
tpl = TEMPLATE.read_text().replace('{{VERSION}}', version)
assert tpl.rstrip().endswith('__halt_compiler();')
(OUT / 'install.php').write_text(tpl.rstrip() + '\n' + payload + '\n')

with zipfile.ZipFile(OUT / 'previouslynoted.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for rel in files:
        z.write(SRC / rel, rel)

print(f'version {version}: {len(files)} files, install.php {(OUT / "install.php").stat().st_size // 1024} KB')

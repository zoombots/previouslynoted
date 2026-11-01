# Previously Noted

A review-notes tool for documentary editors: plays each cut with its review notes in sync, keeps every note's status history, carries unfinished notes into the next cut and places them (by edit file, transcript or picture matching), and gets notes back out to Frame.io and the edit suites.

- `app/`: the web app (PHP 8.1+, vanilla JS, no build step). `app/README.md` is the user guide.
- `build/`: `build.py` packs `app/` into a one-file `install.php` and a zip in `out/`; `install_template.php` is the installer.
- `tests/`: fixtures and scripts used to test it (edit-file parsers, a mock Frame.io/ElevenLabs server, a test SMTP server, the picture-matching benchmark).
- `docs/`: design documents.

## Branches

- `main`: the released single-tenant version (SQLite, one install per team), 0.7.8.
- `multitenant-mariadb`: 0.8, the multi-tenant product on MariaDB: companies (organizations), productions (workspaces), members, and platform / company / production roles, following the same design as Flexboard. See `docs/multitenant-rbac.md`. `tools/import_sqlite.php` brings a 0.7 install over.

## Tests (multi-tenant)

`tests/mt/run.sh` runs everything against a local Apache site and MariaDB: a two-company scenario, the cross-company isolation tests (every action with another company's ids: refused, nothing changed, nothing shown), a functional run of every action, invite/reset/suspend/archive/staff flows, a browser smoke test and the installer (new install, upgrade from 0.7, update). `tests/deploy-mt.sh` copies `app/` to the test site.

## Build

```sh
python3 build/build.py   # writes out/install.php and out/previouslynoted.zip
```

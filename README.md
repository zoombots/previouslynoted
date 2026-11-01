# Previously Noted

A review-notes tool for documentary editors: plays each cut with its review notes in sync, keeps every note's status history, carries unfinished notes into the next cut and places them (by edit file, transcript or picture matching), and gets notes back out to Frame.io and the edit suites.

- `app/`: the web app (PHP 8.1+, vanilla JS, no build step). `app/README.md` is the user guide.
- `build/`: `build.py` packs `app/` into a one-file `install.php` and a zip in `out/`; `install_template.php` is the installer.
- `tests/`: fixtures and scripts used to test it (edit-file parsers, a mock Frame.io/ElevenLabs server, a test SMTP server, the picture-matching benchmark).
- `docs/`: design documents.

## Branches

- `main`: the released single-tenant version (SQLite, one install per team), 0.7.8.
- `multitenant-mariadb`: the move to a multi-tenant product on MariaDB (organizations, workspaces, members, platform/org/workspace roles), following the same design as Flexboard. See `docs/multitenant-rbac.md`.

## Build

```sh
python3 build/build.py   # writes out/install.php and out/previouslynoted.zip
```

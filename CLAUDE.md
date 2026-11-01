# Working on Previously Noted

- The app is in `app/`: `index.php` (pages), `api.php` (JSON API, one `switch` on `METHOD action`), `lib/*.php` (helpers), `assets/app.js` (the whole UI, one IIFE, built with the `el()` helper), `assets/app.css`, `assets/match.js` (picture matching).
- No framework and no build step for the app; keep it that way. PHP 8.1+, plain PDO.
- Copy in the UI is plain English for film editors, no jargon; keep the existing voice (see `app/README.md`).
- User-facing changes get a line in `app/README.md` and, if relevant, the in-app help (`openHelp` in `app.js`).
- Bump `APP_VERSION` in `app/lib/bootstrap.php` for each release (it also busts asset caches), then `python3 build/build.py`.
- Multi-tenant work (branch `multitenant-mariadb`) follows `docs/multitenant-rbac.md`. Every query on tenant data goes through the scoped data layer; never add a query on films/versions/notes that isn't scoped to a workspace.
- Every new API action goes in the `ACTIONS` table in `api.php` with its class and role, and gets covered by `tests/mt/isolation.py` (it reads that table). Run `tests/mt/run.sh` before committing multi-tenant changes.
- Schema changes: bump `SCHEMA_VERSION` in `app/lib/schema.php` and add the `ALTER`s in `migrate()`; MariaDB 10.6+ only (no 11.x-only features).

# Previously Noted: multi-tenant data model and access control

**Status:** the source of truth for branch `multitenant-mariadb`.
**Based on:** Flexboard's *Multi-Tenant Data Model & RBAC Scoping* (kapcal repo, `flexboard-repo-starter/docs/flexboard-multitenant-rbac-scoping.md`). Same three tiers, same check order, same invite and migration approach. This file lists only where Previously Noted applies it, and where it differs.

---

## 1. What changes

Today each install is one team: one SQLite file, one `users` table with `viewer` / `reviewer` / `editor` / `admin`, and connection settings in files under `data/`. The isolation is physical.

After this change one deployment serves many companies. The isolation is logical: one MariaDB database, and every query on production data is scoped to one workspace in the PHP data layer (§6). Getting that boundary right is the point of this work.

## 2. Decisions

| # | Decision |
|---|---|
| 2.1 | **Database: MariaDB** (Namecheap Stellar Plus ships MariaDB 11.4; its PostgreSQL is 10.23, end of life since 2022). The code avoids 11.x-only features so it also runs on 10.6+ (the test server runs 10.11). InnoDB, `utf8mb4`. |
| 2.2 | **No row-level security** (MariaDB has none), so isolation is enforced by one authorization gate in `api.php` and by the scoped lookups in `lib/tenancy.php`, and proved by cross-company tests on every action (`tests/isolation.php`). |
| 2.3 | **Integer ids** (`INT UNSIGNED AUTO_INCREMENT`), not uuids as in Flexboard. Ids never cross the tenant boundary on their own: every id the client sends is resolved to its workspace and checked. |
| 2.4 | **An organisation holds many workspaces; a workspace is a production** and holds one or more films (each with its versions, notes, tracks, transcripts, edits and script). |
| 2.5 | **People are platform-wide.** One email, one password, many companies. Membership and roles live per company and per production. |
| 2.6 | **The single-tenant SQLite line ends at 0.7.8.** Francis's install is imported once (§8); nothing else is carried over. |
| 2.7 | Product name and look stay the same: it is its own product, not part of Flexboard. |

## 3. Vocabulary

| Term in this doc / code | Word in the app | Meaning |
|---|---|---|
| Organization (`organizations`) | **Company** | The tenant. Owns productions and their Frame.io and ElevenLabs connections; has members. |
| Workspace (`workspaces`) | **Production** | A set of films a team works on together. Belongs to one company. |
| Membership (`memberships`) | — | A person's place in a company, with a company role. |
| Workspace access (`workspace_access`) | Role in this production | Today's viewer / reviewer / editor / admin, now per production. |
| Platform role (`users.platform_role`) | — | Previously Noted's own staff: `support`, `platform_admin`. |
| Film, version, note, track, transcript, edit, script line | unchanged | Each belongs to exactly one production. |

## 4. Data model

### 4.1 New tables

**`organizations`**: `id`, `name`, `slug` (unique), `status` (`active` / `suspended`), `plan` (nullable, placeholder), `created_at`.

**`users`** (platform-wide): `id`, `email` (unique across the platform), `name`, `platform_role` (null / `support` / `platform_admin`), `status` (`active` / `disabled`; disabled is a platform action), `pass_hash` (null until they choose one), `token_hash` / `token_kind` / `token_expires` (password reset only), `failed`, `locked_until`, `epoch` (bumped to end every session), `email_verified_at`, `created_at`, `last_login`, `last_ws` (the production they last opened).

**`memberships`**: `id`, `org_id`, `user_id`, `org_role` (`owner` / `admin` / `member`), `status` (`invited` / `active` / `removed`), `invited_by`, `invited_at`, `joined_at`, `token_hash`, `token_expires` (the invite link). Unique `(org_id, user_id)`.

**`workspaces`**: `id`, `org_id`, `name`, `status` (`active` / `archived`), `created_at`.

**`workspace_access`**: `id`, `workspace_id`, `user_id`, `role` (`viewer` / `reviewer` / `editor` / `admin`). Unique `(workspace_id, user_id)`. Only needed for company `member`s (§5.3).

**`settings`**: `scope`, `name`, `value` (JSON). Primary key `(scope, name)`. Scope is `org:<id>` for a company's Frame.io and ElevenLabs connection and Frame.io sign-in tokens, or `platform` for outgoing email and the service base URLs. Replaces `data/connections.php` and `data/frameio-token.json`.

**`audit_log`**: `id`, `org_id` (nullable), `actor_id` (the real person), `as_user_id` (the person being impersonated, if any), `action`, `detail`, `at`. A company's admins can read the rows for their company.

### 4.2 Existing tables

`films`, `versions` and `notes` get `workspace_id` (foreign key to `workspaces`, `ON DELETE CASCADE`). On `versions` and `notes` it is denormalised from the film, so that every id the client can send resolves to its workspace in one indexed lookup. It is set on insert and never changes (films don't move between productions).

Every other table hangs off one of those three, unchanged in shape: `positions`, `events`, `replies`, `note_edits`, `fio_pushed` (by note and version); `edits` → `shots`, `transcripts` → `segments`, `script_edits`, `jobs`, `fingerprints` (by version); `categories` (by film). Fingerprint files stay in `data/fingerprints/<version id>.bin`; version ids are unique across the platform.

Porting notes for the SQL: sessions run with `sql_mode = ANSI_QUOTES, PIPES_AS_CONCAT, STRICT_TRANS_TABLES, …` so the existing SQLite-style quoting keeps working; `categories."key"` is quoted (reserved word); `INSERT OR IGNORE` → `INSERT IGNORE`; `INSERT OR REPLACE` → `REPLACE`; scalar `MAX(a, b)` → `GREATEST`; `COLLATE NOCASE` dropped (the default collation is case-insensitive); columns compared for exact text (tags, track codes, speakers, reply text, Frame.io ids, token hashes) use `utf8mb4_bin`.

## 5. Roles

### 5.1 Platform (Previously Noted staff), `users.platform_role`

| Role | Can do |
|---|---|
| none | Only what their company memberships give them. |
| `support` | Read everything in every company (as a production **viewer**). Impersonate a person to reproduce a problem; while impersonating, support stays read-only. Every impersonation is in the audit log, visible to the companies it touches. |
| `platform_admin` | Everything `support` can, with write access (as a production **admin**); create companies; suspend and reactivate them; set people's platform roles; turn accounts off; issue password reset links; set up outgoing email. Writes made while impersonating are audited. |

### 5.2 Company, `memberships.org_role`

| Role | Can do |
|---|---|
| `owner` | Everything an admin can, plus make or remove other owners. A company always keeps at least one active owner. |
| `admin` | Invite people and remove them from the company; change company roles (not owners'); create, rename and archive productions; give or take away anyone's access to any production; connect Frame.io and ElevenLabs for the company. Implicit **production admin** everywhere in the company. |
| `member` | Nothing at company level. What they can see comes only from §5.3. |

Company admins can't reset a person's password or turn their account off: the account is shared with other companies. People reset their own password from the sign-in page (by email); platform admins can issue a reset link.

### 5.3 Production, `workspace_access.role` (today's roles, unchanged in meaning)

| Role | Can do |
|---|---|
| `admin` | Everything an editor can, plus delete versions and give or take away production access for people already in the company. |
| `editor` | Notes, versions, imports, edits, transcripts, tracks, placing notes, Frame.io sync and push, approving script lines. |
| `reviewer` | Add notes, reply, reword their own notes, suggest script lines as drafts. |
| `viewer` | Read only. |

Company owners and admins don't need a row: they are production admins everywhere in their company, so they can't lock themselves out. An archived production is read-only for everyone (at most viewer) until it is reactivated.

### 5.4 How a check composes (`ws_role()` in `lib/tenancy.php`)

For any action on production data:

1. The company is suspended → no access (whatever the role).
2. The person is a `platform_admin` → production admin.
3. The person is an active `owner` or `admin` of the company → production admin.
4. An active member with a `workspace_access` row → that role. A member without a row has **no access, not even to see that the production exists** (the API answers 404).
5. The person is `support` → viewer.
6. Otherwise no access (404).

Then: an archived production caps the role at viewer; impersonation by `support` caps it at viewer. The action's required role (`ACTION_ROLES` in `api.php`, as today) is compared with the result: below it → 403.

## 6. Enforcement: the scoped data layer

All requests to `api.php` pass one gate before the `switch`:

1. **Classify the action**: `self` (your own account, your list of productions), `ws` (production data: almost everything), `org` (company management), or `platform` (staff). An action that isn't listed is refused.
2. **Resolve the workspace** for a `ws` action from every id in the request: `film_id` → `films`; `version_id`, `carry_from` → `versions`; `note_id`, `note_ids[]`, `results[].note_id` → `notes`; `transcript_id` → `transcripts` → `versions`; `job_id` → `jobs` → `versions`. An action with no such id (the production's state, a new film, resolving a Frame.io link) uses the `ws` parameter the app sends on every request. An id that doesn't exist, or that the person can't reach → **404**, worded as "no longer exists", the same as a missing id, so ids in other companies can't be probed. Ids from **two different productions** in one request → 400. A `ws` parameter that disagrees with the ids → 400.
3. **Compute the role** with §5.4 and compare it with the action's need → 403.
4. **Set the context** (`Ctx::$user`, `$org`, `$ws`, `$role`). Everything after this runs inside one production.

Inside the actions, the lookups that load a film, version or note (`scoped_film()`, `scoped_version()`, `scoped_note()`) add `AND workspace_id = <context>` themselves, so a missed id in the gate still can't leak. Lists (`state`, the people list, exports) select by the context's workspace or company. Secondary ids that aren't film/version/note ids (a track's `id`, the ids of a track reorder) are always matched together with the scoped film (`WHERE id = ? AND film_id = ?`).

Rules for new code (also in `CLAUDE.md`): never query `films`, `versions` or `notes` without the workspace; never accept an id the gate doesn't resolve; add every new action to the gate's table with its class and role; add it to `tests/isolation.php`.

Connections follow the company: `conn_reload()` merges the `platform` scope and the current company's scope, and the Frame.io OAuth state carries the company it was started for.

## 7. Invites

Following Flexboard §6, and today's link-based invites:

- A company admin invites by name and email, with a company role and (optionally) a role in the current production. This creates the `memberships` row with `status = 'invited'` and a one-time token (only its SHA-256 is stored, 7 days), plus the `workspace_access` row. The link is emailed when outgoing email is set up, and always shown to copy.
- If the email has no account yet, a `users` row without a password is created. Opening the link asks for their name and a password, then signs them in.
- If the email already has an account, opening the link asks them to sign in (or uses the session if they already are, as that person), then activates the membership. Nobody can join a company with someone else's link: the signed-in email must match.
- A pending invite can be sent again (new token) or withdrawn (the membership is removed).

## 8. Migration: Francis's install

A one-time CLI import, `tools/import_sqlite.php` (the installer runs the same code when it finds a 0.7.x `data/notes.sqlite`):

1. Reads `notes.sqlite` (and `connections.php`, `frameio-token.json`).
2. Creates **one company** and **one production** in it, named by the person running it.
3. Carries over every film, version, note and everything under them unchanged in shape, re-keyed under the new `workspace_id`. Ids are kept when the tables are empty, otherwise renumbered (fingerprint files are copied to match).
4. The admin whose email is given becomes the first `users` row (keeping their password), company `owner` and `platform_admin`. **No other people are carried over**; their names stay on the notes and history they wrote, and they are invited again.
5. Frame.io and ElevenLabs settings go to the company scope; outgoing email to the platform scope.
6. It runs in one transaction and refuses to run twice into the same company.

## 9. Out of scope

Billing (`organizations.plan` is a placeholder), self-service company sign-up (platform admins create companies), deleting a company, and moving a film between productions.

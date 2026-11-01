<?php
declare(strict_types=1);

/*
 * The MariaDB schema (docs/multitenant-rbac.md §4). Runs on MariaDB 10.6+ and 11.x.
 *
 * Every table of production data hangs off films, versions or notes, which carry workspace_id.
 * Text compared for exact matches uses utf8mb4_bin; the rest uses the database's case-insensitive collation.
 */

const SCHEMA_VERSION = 1;

/** The CREATE TABLE statements, in dependency order. */
function schema_tables(): array
{
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $bin = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
    $id = 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    return [
        // ---------- tenancy ----------
        "CREATE TABLE IF NOT EXISTS organizations (
            $id,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(80) $bin NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            plan VARCHAR(40) NULL,
            created_at VARCHAR(40) NOT NULL,
            UNIQUE KEY organizations_slug (slug)
        )$t",
        "CREATE TABLE IF NOT EXISTS users (
            $id,
            email VARCHAR(200) NOT NULL,
            name VARCHAR(60) NOT NULL,
            platform_role VARCHAR(40) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            pass_hash VARCHAR(255) NULL,
            token_hash CHAR(64) $bin NULL,
            token_kind VARCHAR(12) NULL,
            token_expires INT NULL,
            failed INT NOT NULL DEFAULT 0,
            locked_until INT NOT NULL DEFAULT 0,
            epoch INT NOT NULL DEFAULT 1,
            email_verified_at VARCHAR(40) NULL,
            created_at VARCHAR(40) NOT NULL,
            last_login VARCHAR(40) NULL,
            last_ws INT UNSIGNED NULL,
            UNIQUE KEY users_email (email),
            KEY users_token (token_hash)
        )$t",
        "CREATE TABLE IF NOT EXISTS memberships (
            $id,
            org_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            org_role VARCHAR(12) NOT NULL DEFAULT 'member',
            status VARCHAR(12) NOT NULL DEFAULT 'invited',
            invited_by INT UNSIGNED NULL,
            invited_at VARCHAR(40) NULL,
            joined_at VARCHAR(40) NULL,
            token_hash CHAR(64) $bin NULL,
            token_expires INT NULL,
            UNIQUE KEY memberships_one (org_id, user_id),
            KEY memberships_user (user_id),
            KEY memberships_token (token_hash),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
        )$t",
        "CREATE TABLE IF NOT EXISTS workspaces (
            $id,
            org_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            created_at VARCHAR(40) NOT NULL,
            KEY workspaces_org (org_id),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS workspace_access (
            $id,
            workspace_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(12) NOT NULL,
            UNIQUE KEY workspace_access_one (workspace_id, user_id),
            KEY workspace_access_user (user_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS settings (
            scope VARCHAR(40) $bin NOT NULL,
            name VARCHAR(60) $bin NOT NULL,
            value MEDIUMTEXT NULL,
            PRIMARY KEY (scope, name)
        )$t",
        "CREATE TABLE IF NOT EXISTS audit_log (
            $id,
            org_id INT UNSIGNED NULL,
            actor_id INT UNSIGNED NULL,
            as_user_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            detail TEXT NULL,
            at VARCHAR(40) NOT NULL,
            KEY audit_org (org_id, id),
            FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
        )$t",

        // ---------- production data ----------
        "CREATE TABLE IF NOT EXISTS films (
            $id,
            workspace_id INT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            keyterms MEDIUMTEXT NULL,
            KEY films_ws (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS versions (
            $id,
            workspace_id INT UNSIGNED NOT NULL,
            film_id INT UNSIGNED NOT NULL,
            label VARCHAR(200) NOT NULL,
            tc_base INT NOT NULL DEFAULT 24,
            rate DOUBLE NOT NULL DEFAULT 24,
            frameio_url TEXT NULL,
            video_url TEXT NULL,
            carried_from INT UNSIGNED NULL,
            created_at VARCHAR(40) NOT NULL,
            fio_file_id VARCHAR(64) $bin NULL,
            fio_stack_id VARCHAR(64) $bin NULL,
            fio_name VARCHAR(300) NULL,
            fio_synced_at VARCHAR(40) NULL,
            fio_account_id VARCHAR(80) $bin NULL,
            tc_start INT NOT NULL DEFAULT 0,
            color VARCHAR(40) NULL,
            KEY versions_ws (workspace_id),
            KEY versions_film (film_id, id),
            KEY versions_carried (carried_from),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE CASCADE,
            FOREIGN KEY (carried_from) REFERENCES versions(id) ON DELETE SET NULL
        )$t",
        "CREATE TABLE IF NOT EXISTS notes (
            $id,
            workspace_id INT UNSIGNED NOT NULL,
            film_id INT UNSIGNED NOT NULL,
            origin_version_id INT UNSIGNED NULL,
            fio_comment_id VARCHAR(64) $bin NULL,
            author VARCHAR(200) NOT NULL,
            tag VARCHAR(100) $bin NOT NULL DEFAULT '',
            text TEXT NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            KEY notes_ws (workspace_id),
            KEY notes_film (film_id),
            KEY notes_origin (origin_version_id),
            UNIQUE KEY notes_fio (film_id, fio_comment_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE CASCADE,
            FOREIGN KEY (origin_version_id) REFERENCES versions(id) ON DELETE SET NULL
        )$t",
        "CREATE TABLE IF NOT EXISTS positions (
            note_id INT UNSIGNED NOT NULL,
            version_id INT UNSIGNED NOT NULL,
            frame INT NOT NULL,
            confirmed TINYINT NOT NULL DEFAULT 1,
            fio_complete TINYINT NULL,
            method VARCHAR(40) NOT NULL DEFAULT 'manual',
            reason VARCHAR(40) NULL,
            end_frame INT NULL,
            PRIMARY KEY (note_id, version_id),
            KEY positions_version (version_id, frame),
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS events (
            $id,
            note_id INT UNSIGNED NOT NULL,
            version_id INT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL,
            comment TEXT NULL,
            by_name VARCHAR(200) NOT NULL,
            at VARCHAR(40) NOT NULL,
            KEY events_note (note_id, id),
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE SET NULL
        )$t",
        "CREATE TABLE IF NOT EXISTS replies (
            $id,
            note_id INT UNSIGNED NOT NULL,
            version_id INT UNSIGNED NULL,
            author VARCHAR(200) $bin NOT NULL,
            text TEXT $bin NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'app',
            KEY replies_note (note_id, id),
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE SET NULL
        )$t",
        "CREATE TABLE IF NOT EXISTS note_edits (
            $id,
            note_id INT UNSIGNED NOT NULL,
            old_text TEXT NOT NULL,
            new_text TEXT NOT NULL,
            by_name VARCHAR(200) NOT NULL,
            at VARCHAR(40) NOT NULL,
            KEY note_edits_note (note_id, id),
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS edits (
            $id,
            version_id INT UNSIGNED NOT NULL,
            filename VARCHAR(200) NOT NULL,
            format VARCHAR(40) NOT NULL,
            seq_name VARCHAR(200) NULL,
            rate DOUBLE NOT NULL,
            start_frame INT NOT NULL DEFAULT 0,
            shot_count INT NOT NULL DEFAULT 0,
            warnings MEDIUMTEXT NULL,
            uploaded_by VARCHAR(200) NOT NULL,
            uploaded_at VARCHAR(40) NOT NULL,
            KEY edits_version (version_id, id),
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS shots (
            edit_id INT UNSIGNED NOT NULL,
            kind VARCHAR(4) NOT NULL,
            track INT NOT NULL,
            rec_in INT NOT NULL,
            rec_out INT NOT NULL,
            name VARCHAR(300) NOT NULL,
            file VARCHAR(300) NOT NULL,
            src_in DOUBLE NOT NULL,
            k DOUBLE NOT NULL,
            KEY shots_edit (edit_id, kind, rec_in),
            FOREIGN KEY (edit_id) REFERENCES edits(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS transcripts (
            $id,
            version_id INT UNSIGNED NOT NULL,
            filename VARCHAR(200) NOT NULL,
            format VARCHAR(40) NOT NULL,
            offset_frames INT NOT NULL DEFAULT 0,
            segment_count INT NOT NULL DEFAULT 0,
            uploaded_by VARCHAR(200) NOT NULL,
            uploaded_at VARCHAR(40) NOT NULL,
            KEY transcripts_version (version_id, id),
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS segments (
            transcript_id INT UNSIGNED NOT NULL,
            idx INT NOT NULL,
            start_frame INT NOT NULL,
            end_frame INT NOT NULL,
            speaker VARCHAR(60) $bin NOT NULL DEFAULT '',
            text TEXT NOT NULL,
            norm TEXT NOT NULL,
            KEY segments_t (transcript_id, idx),
            FOREIGN KEY (transcript_id) REFERENCES transcripts(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS categories (
            $id,
            film_id INT UNSIGNED NOT NULL,
            \"key\" VARCHAR(8) $bin NOT NULL,
            label VARCHAR(40) NOT NULL,
            color VARCHAR(40) NOT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'picture',
            sort INT NOT NULL DEFAULT 0,
            UNIQUE KEY categories_key (film_id, \"key\"),
            FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS script_edits (
            $id,
            version_id INT UNSIGNED NOT NULL,
            transcript_id INT UNSIGNED NULL,
            seg_idx INT NOT NULL,
            seg_norm TEXT NOT NULL,
            slot INT NOT NULL DEFAULT 0,
            state VARCHAR(12) NOT NULL,
            text TEXT NOT NULL,
            by_name VARCHAR(200) NOT NULL,
            at VARCHAR(40) NOT NULL,
            KEY script_edits_v (version_id, id),
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS jobs (
            $id,
            version_id INT UNSIGNED NOT NULL,
            kind VARCHAR(40) NOT NULL,
            status VARCHAR(12) NOT NULL,
            message TEXT NULL,
            result MEDIUMTEXT NULL,
            created_by VARCHAR(200) NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY jobs_version (version_id, kind, id),
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS fingerprints (
            version_id INT UNSIGNED NOT NULL PRIMARY KEY,
            bytes INT NOT NULL,
            duration DOUBLE NOT NULL,
            samples INT NOT NULL,
            source VARCHAR(200) NULL,
            created_by VARCHAR(200) NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
        "CREATE TABLE IF NOT EXISTS fio_pushed (
            note_id INT UNSIGNED NOT NULL,
            version_id INT UNSIGNED NOT NULL,
            fio_comment_id VARCHAR(64) $bin NOT NULL,
            pushed_by VARCHAR(200) NOT NULL,
            pushed_at VARCHAR(40) NOT NULL,
            PRIMARY KEY (note_id, version_id),
            KEY fio_pushed_v (version_id, fio_comment_id),
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
            FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE
        )$t",
    ];
}

/** Create the tables if they're missing, and run later migrations. Cheap when nothing is to do. */
function migrate(PDO $pdo): void
{
    $have = 0;
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE scope = 'platform' AND name = 'schema'")->fetchColumn();
        $have = $v === false ? 0 : (int)json_decode((string)$v);
    } catch (PDOException $e) {
        $have = 0; // no settings table yet
    }
    if ($have >= SCHEMA_VERSION) {
        return;
    }
    foreach (schema_tables() as $sql) {
        $pdo->exec($sql);
    }
    // Later versions add their ALTERs here, guarded by $have < N.
    $pdo->prepare("REPLACE INTO settings (scope, name, value) VALUES ('platform', 'schema', ?)")->execute([json_encode(SCHEMA_VERSION)]);
}

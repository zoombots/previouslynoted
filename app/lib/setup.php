<?php
declare(strict_types=1);

/*
 * First run, and the one-time import of a 0.7.x single-team install (docs/multitenant-rbac.md §8).
 */

/** What stops setup on this server, as plain sentences. */
function setup_problems(): array
{
    $p = [];
    if (PHP_VERSION_ID < 80100) {
        $p[] = 'PHP 8.1 or newer is needed (this server runs ' . PHP_VERSION . '). Change it in cPanel → Select PHP Version.';
    }
    if (!extension_loaded('pdo_mysql')) {
        $p[] = 'The pdo_mysql extension is off. Turn it on in cPanel → Select PHP Version → Extensions.';
    }
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0750, true) || !is_writable(DATA_DIR)) {
        $p[] = 'The data folder isn’t writable. In File Manager, set the "data" folder permissions to 755.';
    }
    return $p;
}

/** The first person on a new server: platform admin and owner of the first company. Returns their id. */
function setup_first_account(string $company, string $workspace, string $name, string $email, string $password): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $uid = create_user($name, $email, $password, 'platform_admin');
        $orgId = create_org($company);
        $wsId = create_workspace($orgId, $workspace);
        add_member($orgId, $uid, 'owner', $uid);
        $pdo->prepare('UPDATE users SET last_ws = ? WHERE id = ?')->execute([$wsId, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $uid;
}

/* ---------------- importing a 0.7.x install ---------------- */

/** Production tables in the order they're copied, with the columns that point at other copied rows. */
const IMPORT_TABLES = [
    'films' => ['id' => true, 'refs' => []],
    'versions' => ['id' => true, 'refs' => ['film_id' => 'films', 'carried_from' => 'versions']],
    'notes' => ['id' => true, 'refs' => ['film_id' => 'films', 'origin_version_id' => 'versions']],
    'positions' => ['id' => false, 'refs' => ['note_id' => 'notes', 'version_id' => 'versions']],
    'events' => ['id' => true, 'refs' => ['note_id' => 'notes', 'version_id' => 'versions']],
    'replies' => ['id' => true, 'refs' => ['note_id' => 'notes', 'version_id' => 'versions']],
    'note_edits' => ['id' => true, 'refs' => ['note_id' => 'notes']],
    'edits' => ['id' => true, 'refs' => ['version_id' => 'versions']],
    'shots' => ['id' => false, 'refs' => ['edit_id' => 'edits']],
    'transcripts' => ['id' => true, 'refs' => ['version_id' => 'versions']],
    'segments' => ['id' => false, 'refs' => ['transcript_id' => 'transcripts']],
    'categories' => ['id' => true, 'refs' => ['film_id' => 'films']],
    'script_edits' => ['id' => true, 'refs' => ['version_id' => 'versions', 'transcript_id' => 'transcripts']],
    'jobs' => ['id' => true, 'refs' => ['version_id' => 'versions']],
    'fingerprints' => ['id' => false, 'refs' => ['version_id' => 'versions']],
    'fio_pushed' => ['id' => false, 'refs' => ['note_id' => 'notes', 'version_id' => 'versions']],
];

/**
 * Copy a 0.7.x install into the database as one company with one production.
 * The admin signs in with their current email and password (checked against the old install) and becomes the owner
 * and a platform admin. Nobody else is carried over. Runs in one transaction; refuses to run twice for the same file.
 * Returns ['user_id', 'org_id', 'workspace_id', 'counts' => [table => rows]].
 */
function import_sqlite_install(string $sqlite, string $dataDir, string $company, string $workspace, string $email, ?string $password, bool $checkPassword = true): array
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Bringing over the old notes needs the pdo_sqlite extension. Turn it on in cPanel → Select PHP Version → Extensions.');
    }
    if (!is_file($sqlite)) {
        throw new RuntimeException('There’s no notes.sqlite to import.');
    }
    $src = new PDO('sqlite:' . $sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $have = array_flip($src->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));

    // The admin from the old install.
    $email = norm_email($email);
    $old = null;
    if (isset($have['users'])) {
        $st = $src->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin' AND status = 'active' AND pass_hash IS NOT NULL");
        $st->execute([$email]);
        $old = $st->fetch() ?: null;
    }
    if (!$old || ($checkPassword && !password_verify((string)$password, (string)$old['pass_hash']))) {
        sleep(1);
        throw new RuntimeException('That email and password don’t match an admin of the current install.');
    }

    $pdo = db();
    $fingerprint = substr(hash_file('sha256', $sqlite), 0, 40);
    $done = $pdo->prepare("SELECT 1 FROM settings WHERE scope = 'platform' AND name = ?");
    $done->execute(['imported:' . $fingerprint]);
    if ($done->fetchColumn()) {
        throw new RuntimeException('These notes have already been brought over.');
    }

    // Keep the old ids when the database has no production data yet (then fingerprint files stay where they are).
    $keepIds = true;
    foreach (['films', 'versions', 'notes', 'edits', 'transcripts'] as $t) {
        if ($pdo->query("SELECT 1 FROM $t LIMIT 1")->fetchColumn()) {
            $keepIds = false;
        }
    }

    $counts = [];
    $pdo->beginTransaction();
    try {
        $orgId = create_org($company);
        $wsId = create_workspace($orgId, $workspace);
        $u = user_by_email($email);
        if ($u) {
            $uid = (int)$u['id'];
            $pdo->prepare("UPDATE users SET platform_role = 'platform_admin' WHERE id = ?")->execute([$uid]);
        } else {
            $pdo->prepare("INSERT INTO users (email, name, platform_role, status, pass_hash, created_at, last_login) VALUES (?,?,'platform_admin','active',?,?,?)")
                ->execute([$email, mb_substr((string)$old['name'], 0, 60), $old['pass_hash'], $old['created_at'] ?: now_iso(), $old['last_login']]);
            $uid = (int)$pdo->lastInsertId();
        }
        add_member($orgId, $uid, 'owner', $uid);
        $pdo->prepare('UPDATE users SET last_ws = ? WHERE id = ?')->execute([$wsId, $uid]);
        Ctx::$user = Ctx::$real = user_by_id($uid);
        Ctx::set(org_row($orgId), ws_row($wsId), 'admin');

        $map = [];
        $carried = [];
        foreach (IMPORT_TABLES as $table => $spec) {
            $map[$table] = [];
            $counts[$table] = 0;
            if (!isset($have[$table])) {
                continue;
            }
            $srcCols = array_column($src->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            $dstCols = array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
            $cols = array_values(array_intersect($srcCols, $dstCols));
            if (!$keepIds || !$spec['id']) {
                $cols = array_values(array_diff($cols, ['id']));
            }
            $insCols = in_array('workspace_id', $dstCols, true) ? array_merge(['workspace_id'], $cols) : $cols;
            $quoted = implode(', ', array_map(fn($c) => '"' . $c . '"', $insCols));
            $ins = $pdo->prepare("INSERT INTO $table ($quoted) VALUES (" . implode(',', array_fill(0, count($insCols), '?')) . ')');
            $order = in_array('id', $srcCols, true) ? 'id' : 'rowid';
            foreach ($src->query("SELECT * FROM $table ORDER BY $order") as $row) {
                $vals = [];
                $skip = false;
                foreach ($cols as $c) {
                    $v = $row[$c];
                    if (isset($spec['refs'][$c]) && $v !== null) {
                        $ref = $spec['refs'][$c];
                        if ($table === 'versions' && $c === 'carried_from') {
                            $carried[(int)$row['id']] = (int)$v; // set once every version exists
                            $v = null;
                        } elseif (isset($map[$ref][(int)$v])) {
                            $v = $map[$ref][(int)$v];
                        } elseif (in_array($c, ['origin_version_id', 'version_id', 'transcript_id'], true) && in_array($table, ['notes', 'events', 'replies', 'script_edits'], true)) {
                            $v = null; // pointed at something deleted long ago
                        } else {
                            $skip = true; // an orphan row
                            break;
                        }
                    }
                    $vals[] = $v;
                }
                if ($skip) {
                    continue;
                }
                $ins->execute(in_array('workspace_id', $dstCols, true) ? array_merge([$wsId], $vals) : $vals);
                if ($spec['id']) {
                    $map[$table][(int)$row['id']] = $keepIds ? (int)$row['id'] : (int)$pdo->lastInsertId();
                }
                $counts[$table]++;
            }
        }
        $setCarried = $pdo->prepare('UPDATE versions SET carried_from = ? WHERE id = ? AND workspace_id = ?');
        foreach ($carried as $oldId => $oldFrom) {
            if (isset($map['versions'][$oldId], $map['versions'][$oldFrom])) {
                $setCarried->execute([$map['versions'][$oldFrom], $map['versions'][$oldId], $wsId]);
            }
        }
        // Versions from before revision colours get the sequence, per film.
        $seq = [];
        $setColor = $pdo->prepare('UPDATE versions SET color = ? WHERE id = ?');
        $st = $pdo->prepare('SELECT id, film_id, color FROM versions WHERE workspace_id = ? ORDER BY film_id, id');
        $st->execute([$wsId]);
        foreach ($st->fetchAll() as $v) {
            $i = $seq[$v['film_id']] = ($seq[$v['film_id']] ?? -1) + 1;
            if (!$v['color']) {
                $setColor->execute([REVISION_COLORS[$i % count(REVISION_COLORS)], $v['id']]);
            }
        }

        // Connections: Frame.io and ElevenLabs to the company, email to the platform.
        $conn = is_file("$dataDir/connections.php") ? (static fn($f) => require $f)("$dataDir/connections.php") : [];
        if (is_array($conn) && $conn) {
            conn_save($conn);
        }
        $tok = is_file("$dataDir/frameio-token.json") ? json_decode((string)file_get_contents("$dataDir/frameio-token.json"), true) : null;
        if (is_array($tok)) {
            settings_put('org:' . $orgId, ['fio_token' => $tok]);
        }

        $pdo->prepare("INSERT INTO settings (scope, name, value) VALUES ('platform', ?, ?)")
            ->execute(['imported:' . $fingerprint, json_encode(['at' => now_iso(), 'org_id' => $orgId, 'workspace_id' => $wsId, 'counts' => $counts])]);
        Ctx::$real = Ctx::$user;
        audit('imported from 0.7.x', $orgId, json_encode($counts));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Fingerprint files follow their version's id.
    if (!$keepIds) {
        @mkdir(DATA_DIR . '/fingerprints', 0750, true);
        foreach ($map['versions'] as $oldId => $newId) {
            $from = "$dataDir/fingerprints/$oldId.bin";
            if (is_file($from)) {
                @copy($from, DATA_DIR . "/fingerprints/$newId.bin.tmp") && @rename(DATA_DIR . "/fingerprints/$newId.bin.tmp", DATA_DIR . "/fingerprints/$newId.bin");
            }
        }
    } elseif (realpath($dataDir) !== realpath(DATA_DIR) && is_dir("$dataDir/fingerprints")) {
        @mkdir(DATA_DIR . '/fingerprints', 0750, true);
        foreach (glob("$dataDir/fingerprints/*.bin") ?: [] as $f) {
            @copy($f, DATA_DIR . '/fingerprints/' . basename($f));
        }
    }
    return ['user_id' => $uid, 'org_id' => $orgId, 'workspace_id' => $wsId, 'counts' => $counts, 'kept_ids' => $keepIds];
}

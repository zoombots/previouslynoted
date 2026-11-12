<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/import.php';
require __DIR__ . '/lib/edit.php';
require __DIR__ . '/lib/transcript.php';
require __DIR__ . '/lib/place.php';
require __DIR__ . '/lib/connect.php';
require __DIR__ . '/lib/script.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/export.php';
require __DIR__ . '/lib/vo.php';
require __DIR__ . '/lib/media.php';

security_headers();

set_exception_handler(function (Throwable $e) {
    error_log('[previouslynoted] ' . $e->getMessage());
    $msg = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Something went wrong on the server. Details are in the PHP error log.';
    json_out(['error' => $msg], 500);
});

if (!app_config()) {
    fail('Setup has not been completed.', 503);
}
start_session();
// Someone outside the team on a share link (lib/share.php): they act as a reviewer (or viewer) under the name they gave.
$share = null;
if (isset($_GET['share'])) {
    $share = share_by_token((string)$_GET['share']);
    $guestName = $share ? share_guest_name($share) : null;
    if (!$share || $guestName === null) {
        fail('This share link doesn’t work any more, or your name is needed. Reload the page.', 401);
    }
    $me = guest_account($share, $guestName);
} else {
    $me = current_account();
}
if (!$me) {
    fail('Your session has ended. Sign in again.', 401);
}
$user = $me['name'];

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        fail('Security token mismatch. Reload the page.', 403);
    }
    $isJson = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $in = $isJson ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []) : $_POST;
} else {
    $in = $_GET;
}
// Release the session lock so long requests don't block other tabs.
session_write_close();

function int_in(array $in, string $k): int
{
    if (!isset($in[$k]) || !is_numeric($in[$k])) {
        fail("Missing $k.");
    }
    return (int)$in[$k];
}

function str_in(array $in, string $k, int $max = 5000, bool $required = true): string
{
    $v = trim((string)($in[$k] ?? ''));
    if ($required && $v === '') {
        fail("Missing $k.");
    }
    if (mb_strlen($v) > $max) {
        fail("$k is too long.");
    }
    return $v;
}

function version_row(int $id): array
{
    $st = db()->prepare('SELECT * FROM versions WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: fail('That version no longer exists.', 404);
}

function note_row(int $id): array
{
    $st = db()->prepare('SELECT * FROM notes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: fail('That note no longer exists.', 404);
}

/*
 * Who may do what. Anything not listed here needs an editor.
 * Reading is open to everyone signed in.
 */
const ACTION_ROLES = [
    // reviewers
    'note.create' => 'reviewer', 'note.reply' => 'reviewer', 'note.edit' => 'reviewer', 'script.edit' => 'reviewer', 'script.time' => 'reviewer',
    // admins
    'version.delete' => 'admin', 'settings' => 'admin', 'settings.frameio' => 'admin', 'settings.frameio.clear' => 'admin',
    'settings.elevenlabs' => 'admin', 'settings.elevenlabs.clear' => 'admin',
    'settings.mail' => 'admin', 'settings.mail.clear' => 'admin', 'settings.mail.test' => 'admin',
    'people' => 'admin', 'people.invite' => 'admin', 'people.update' => 'admin', 'people.link' => 'admin', 'people.delete' => 'admin',
    // everyone
    'account' => 'viewer', 'account.update' => 'viewer', 'account.password' => 'viewer',
];
const READ_ACTIONS = ['export.markers', 'fingerprint', 'state', 'notes', 'transcript', 'script', 'script.export', 'version.media', 'version.audio', 'transcribe.status', 'export'];

/** Email an invite or reset link when asked and email is set up. Returns [sent, error message]. */
function send_account_link(array $person, string $kind, string $link, string $expires, array $by, bool $wanted): array
{
    if (!$wanted || !mail_configured()) {
        return [false, null];
    }
    try {
        mail_account_link($person, $kind, $link, $expires, $by);
        return [true, null];
    } catch (MailError $e) {
        error_log('[previouslynoted] mail: ' . $e->getMessage());
        return [false, $e->getMessage()];
    }
}

if ($share) {
    // A guest sees only the shared version: every id in the request must be inside it.
    if (!in_array("$method $action", GUEST_ACTIONS, true)) {
        fail('That isn’t available from a share link.', 403);
    }
    if (!share_allows($share, $action)) {
        fail($action === 'script.edit' ? 'This link doesn’t let you suggest script changes.' : 'This link is for watching; it doesn’t let you add notes.', 403);
    }
    $shared = share_version_ids($share);
    $inList = implode(',', $shared);
    $gone = 'That isn’t part of what was shared with you.';
    if ((isset($in['version_id']) && !in_array((int)$in['version_id'], $shared, true)) || (isset($in['film_id']) && (int)$in['film_id'] !== (int)$share['film_id'])) {
        fail($gone, 404);
    }
    if (isset($in['note_id'])) {
        // The note must be on the version asked about (or, without one, on a version they can see).
        $chk = db()->prepare('SELECT 1 FROM positions WHERE note_id = ? AND version_id ' . (isset($in['version_id']) ? '= ' . (int)$in['version_id'] : "IN ($inList)"));
        $chk->execute([(int)$in['note_id']]);
        if (!$chk->fetchColumn()) {
            fail($gone, 404);
        }
    }
    if (isset($in['transcript_id']) && $in['transcript_id'] !== '') {
        $chk = db()->prepare("SELECT 1 FROM transcripts WHERE id = ? AND version_id IN ($inList)");
        $chk->execute([(int)$in['transcript_id']]);
        if (!$chk->fetchColumn()) {
            fail($gone, 404);
        }
    }
}

$need = ACTION_ROLES[$action] ?? ($method === 'GET' && in_array($action, READ_ACTIONS, true) ? 'viewer' : 'editor');
if (!role_at_least($me, $need)) {
    $what = ['reviewer' => 'Viewers can look but not change anything.', 'editor' => 'Only editors and admins can do that.', 'admin' => 'Only admins can do that.'][$need];
    fail($what . ' Ask an admin if you need more access.', 403);
}

$pdo = db();

switch ("$method $action") {


    /* ---------- your account ---------- */
    case 'GET account':
        json_out(['me' => user_public($me), 'roles' => ROLE_LABELS]);

    case 'POST account.update':
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            fail('Enter a name (up to 60 characters).');
        }
        $email = norm_email((string)($in['email'] ?? $me['email']));
        if ($email !== $me['email']) {
            if (!valid_email($email)) {
                fail('That email address doesn’t look right.');
            }
            if (!password_verify((string)($in['password'] ?? ''), (string)$me['pass_hash'])) {
                fail('Enter your current password to change your email.', 403);
            }
            if (user_by_email($email)) {
                fail('Someone else already uses that email address.');
            }
        }
        $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $me['id']]);
        json_out(['ok' => true, 'me' => user_public(user_by_id((int)$me['id']))]);

    case 'POST account.password':
        if (!password_verify((string)($in['current'] ?? ''), (string)$me['pass_hash'])) {
            sleep(1);
            fail('Your current password isn’t right.', 403);
        }
        if ($p = password_problem((string)($in['password'] ?? ''), (string)($in['password2'] ?? ''))) {
            fail($p);
        }
        set_password((int)$me['id'], (string)$in['password']);
        // Keep this browser signed in; every other session ends.
        session_start();
        $_SESSION['epoch'] = (int)user_by_id((int)$me['id'])['epoch'];
        session_write_close();
        json_out(['ok' => true]);

    /* ---------- people (admins) ---------- */
    case 'GET people':
        $rows = $pdo->query("SELECT * FROM users ORDER BY CASE status WHEN 'disabled' THEN 1 ELSE 0 END, name COLLATE NOCASE")->fetchAll();
        json_out(['people' => array_map('user_public', $rows), 'roles' => ROLE_LABELS, 'me' => (int)$me['id'], 'mail' => mail_configured() ? mail_settings()['from'] : null]);

    case 'POST people.invite':
        $role = (string)($in['role'] ?? 'reviewer');
        $id = create_user((string)($in['name'] ?? ''), (string)($in['email'] ?? ''), $role, null);
        $token = issue_token($id, 'invite');
        $link = app_base_url() . '?invite=' . $token;
        $person = user_by_id($id);
        [$sent, $mailError] = send_account_link($person, 'invite', $link, INVITE_DAYS . ' days', $me, !empty($in['send']));
        json_out(['ok' => true, 'person' => user_public($person), 'link' => $link, 'days' => INVITE_DAYS, 'sent' => $sent, 'mail_error' => $mailError]);

    case 'POST people.link':
        $u = user_by_id(int_in($in, 'id')) ?? fail('That person is no longer on the list.', 404);
        if ($u['status'] === 'disabled') {
            fail('Turn this account back on first.');
        }
        $kind = $u['status'] === 'invited' ? 'invite' : 'reset';
        $token = issue_token((int)$u['id'], $kind);
        $link = app_base_url() . '?' . $kind . '=' . $token;
        $expires = $kind === 'invite' ? INVITE_DAYS . ' days' : RESET_HOURS . ' hours';
        [$sent, $mailError] = send_account_link($u, $kind, $link, $expires, $me, !empty($in['send']));
        json_out(['ok' => true, 'kind' => $kind, 'link' => $link, 'expires' => $expires, 'person' => user_public(user_by_id((int)$u['id'])),
            'sent' => $sent, 'mail_error' => $mailError]);

    case 'POST people.update':
        $u = user_by_id(int_in($in, 'id')) ?? fail('That person is no longer on the list.', 404);
        $role = (string)($in['role'] ?? $u['role']);
        $status = (string)($in['status'] ?? $u['status']);
        if (!isset(ROLES[$role])) {
            fail('Unknown role.');
        }
        if ($status === 'active' && $u['status'] === 'invited') {
            $status = 'invited'; // they still need to choose a password
        }
        if (!in_array($status, ['invited', 'active', 'disabled'], true)) {
            fail('Unknown account state.');
        }
        if ($u['status'] === 'disabled' && $status !== 'disabled') {
            $status = $u['pass_hash'] ? 'active' : 'invited';
        }
        $losesAdmin = $u['role'] === 'admin' && $u['status'] === 'active' && ($role !== 'admin' || $status !== 'active');
        if ($losesAdmin && active_admins() <= 1) {
            fail('Keep at least one active admin. Make someone else an admin first.');
        }
        $name = isset($in['name']) ? trim((string)$in['name']) : $u['name'];
        if ($name === '' || mb_strlen($name) > 60) {
            fail('Enter a name (up to 60 characters).');
        }
        $pdo->prepare('UPDATE users SET role = ?, status = ?, name = ?, epoch = epoch + ?, locked_until = CASE WHEN ? THEN 0 ELSE locked_until END,
                failed = CASE WHEN ? THEN 0 ELSE failed END WHERE id = ?')
            ->execute([$role, $status, $name, $status === 'disabled' ? 1 : 0, !empty($in['unlock']) ? 1 : 0, !empty($in['unlock']) ? 1 : 0, $u['id']]);
        json_out(['ok' => true, 'person' => user_public(user_by_id((int)$u['id']))]);

    case 'POST people.delete':
        $u = user_by_id(int_in($in, 'id')) ?? fail('That person is no longer on the list.', 404);
        if ((int)$u['id'] === (int)$me['id']) {
            fail('You can’t remove your own account.');
        }
        if ($u['role'] === 'admin' && $u['status'] === 'active' && active_admins() <= 1) {
            fail('Keep at least one active admin.');
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
        json_out(['ok' => true]);

    case 'GET state':
        // A guest gets only the shared film and version.
        $only = $share ? ' WHERE v.id IN (' . implode(',', share_version_ids($share)) . ')' : '';
        $versions = $pdo->query(
            'SELECT v.id, v.film_id, v.label, v.color, v.tc_base, v.tc_start, v.rate, v.frameio_url, v.video_url, v.carried_from,
                    v.fio_file_id, v.fio_stack_id, v.fio_name, v.fio_synced_at, v.fio_account_id, v.audio_name, v.audio_size,
                    (SELECT COUNT(*) FROM positions p WHERE p.version_id = v.id) AS note_count,
                    (SELECT COUNT(*) FROM transcripts t WHERE t.version_id = v.id) AS transcript_count
             FROM versions v' . $only . ' ORDER BY v.film_id, v.id'
        )->fetchAll();
        $fps = [];
        foreach ($pdo->query('SELECT version_id, created_at, source FROM fingerprints') as $f) {
            $fps[(int)$f['version_id']] = ['at' => $f['created_at'], 'source' => $f['source']];
        }
        foreach ($versions as &$vv) {
            $vv['fingerprint'] = $fps[(int)$vv['id']] ?? null;
            $e = active_edit((int)$vv['id']);
            $vv['edit'] = $e ? ['filename' => $e['filename'], 'format' => $e['format'], 'shots' => (int)$e['shot_count'],
                'start_tc' => frames_to_tc((int)$e['start_frame'], max(1, (int)round((float)$e['rate']))), 'uploaded_at' => $e['uploaded_at']] : null;
        }
        unset($vv);
        $films = $pdo->query('SELECT id, name, keyterms FROM films' . ($share ? ' WHERE id = ' . (int)$share['film_id'] : '') . ' ORDER BY id')->fetchAll();
        foreach ($films as &$fm) {
            $fm['categories'] = film_categories((int)$fm['id']);
        }
        unset($fm);
        if ($share) {
            foreach ($films as &$fm) {
                $fm['keyterms'] = null;
            }
            unset($fm);
            foreach ($versions as &$vv) {
                $vv['fingerprint'] = null;
                $vv['frameio_url'] = null;
            }
            unset($vv);
        }
        json_out(['user' => $user, 'me' => user_public($me), 'films' => $films, 'versions' => $versions, 'statuses' => STATUSES,
            'guest' => $share ? ['name' => $user, 'view' => $share['view'], 'can_comment' => (bool)$share['can_comment'], 'can_script' => (bool)$share['can_script']] : null,
            'connections' => ['frameio' => fio_configured() && !empty(conn_reload()['fio_account_id']), 'elevenlabs' => !$share && el_configured(),
                'mail' => !$share && mail_configured() ? conn_reload()['smtp_from'] : null],
            'upload_chunk' => upload_chunk_bytes()]);

    /* ---------- share links for people outside the team (editors) ---------- */
    case 'GET shares':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $st = $pdo->prepare('SELECT * FROM shares WHERE version_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) ORDER BY id DESC');
        $st->execute([$vid, time()]);
        $guests = $pdo->prepare('SELECT DISTINCT author FROM notes WHERE share_id = ? UNION SELECT DISTINCT author FROM replies WHERE share_id = ?');
        $list = [];
        foreach ($st->fetchAll() as $row) {
            $guests->execute([$row['id'], $row['id']]);
            $list[] = share_public($row) + ['guests' => $guests->fetchAll(PDO::FETCH_COLUMN)];
        }
        json_out(['shares' => $list, 'views' => SHARE_VIEWS]);

    case 'POST share.create':
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $view = isset(SHARE_VIEWS[$in['view'] ?? '']) ? $in['view'] : 'review';
        $days = (int)($in['days'] ?? 0);
        $token = bin2hex(random_bytes(20));
        $pdo->prepare('INSERT INTO shares (token, film_id, version_id, view, can_comment, can_script, show_older, label, created_by, created_at, expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$token, $v['film_id'], $vid, $view, !empty($in['can_comment']) ? 1 : 0, !empty($in['can_script']) ? 1 : 0, !empty($in['show_older']) ? 1 : 0, mb_substr(trim((string)($in['label'] ?? '')), 0, 80),
                $user, now_iso(), $days > 0 ? time() + min($days, 365) * 86400 : null]);
        $st = $pdo->prepare('SELECT * FROM shares WHERE id = ?');
        $st->execute([(int)$pdo->lastInsertId()]);
        json_out(['ok' => true, 'share' => share_public($st->fetch()) + ['guests' => []]]);

    case 'POST share.revoke':
        $pdo->prepare('UPDATE shares SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([now_iso(), int_in($in, 'id')]);
        json_out(['ok' => true]);

    case 'POST film.create':
        $name = str_in($in, 'name', 200);
        $pdo->prepare('INSERT INTO films (name, created_at) VALUES (?, ?)')->execute([$name, now_iso()]);
        json_out(['id' => (int)$pdo->lastInsertId()]);

    case 'POST film.rename':
        $pdo->prepare('UPDATE films SET name = ? WHERE id = ?')->execute([str_in($in, 'name', 200), int_in($in, 'film_id')]);
        json_out(['ok' => true]);

    case 'POST version.create':
        $filmId = int_in($in, 'film_id');
        $label = str_in($in, 'label', 200);
        $from = isset($in['carry_from']) && $in['carry_from'] !== '' && $in['carry_from'] !== null ? (int)$in['carry_from'] : null;
        $tcBase = 24;
        $rate = 24.0;
        if ($from) {
            $src = version_row($from);
            if ((int)$src['film_id'] !== $filmId) {
                fail('You can only carry notes from a version of the same film.');
            }
            $tcBase = (int)$src['tc_base'];
            $rate = (float)$src['rate'];
        }
        $pdo->beginTransaction();
        $fioFile = preg_match('/^[0-9a-f-]{36}$/i', (string)($in['fio_file_id'] ?? '')) ? strtolower($in['fio_file_id']) : null;
        $parentAcc = null;
        if ($from && $fioFile) {
            $pa = $pdo->prepare('SELECT fio_account_id FROM versions WHERE id = ?');
            $pa->execute([$from]);
            $parentAcc = $pa->fetchColumn() ?: null;
        }
        $color = in_array($in['color'] ?? '', REVISION_COLORS, true) ? $in['color'] : next_revision_color($pdo, $filmId);
        $pdo->prepare('INSERT INTO versions (film_id, label, tc_base, rate, carried_from, created_at, fio_file_id, fio_stack_id, fio_name, fio_account_id, color) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$filmId, $label, $tcBase, $rate, $from, now_iso(), $fioFile,
                $fioFile && preg_match('/^[0-9a-f-]{36}$/i', (string)($in['fio_stack_id'] ?? '')) ? strtolower($in['fio_stack_id']) : null,
                $fioFile ? mb_substr((string)($in['fio_name'] ?? ''), 0, 300) : null, $parentAcc, $color]);
        $vid = (int)$pdo->lastInsertId();
        $carried = 0;
        if ($from) {
            // Carry every note that isn't finished. Positions start unconfirmed at the old frame.
            $closed = "'" . implode("','", CLOSED_STATUSES) . "'";
            $st = $pdo->prepare(
                "SELECT p.note_id, p.frame, p.end_frame FROM positions p
                 WHERE p.version_id = ?
                   AND COALESCE((SELECT e.status FROM events e WHERE e.note_id = p.note_id ORDER BY e.id DESC LIMIT 1), 'open') NOT IN ($closed)"
            );
            $st->execute([$from]);
            $ins = $pdo->prepare("INSERT INTO positions (note_id, version_id, frame, end_frame, confirmed, method) VALUES (?,?,?,?,0,'carried')");
            foreach ($st->fetchAll() as $p) {
                $ins->execute([$p['note_id'], $vid, $p['frame'], $p['end_frame']]);
                $carried++;
            }
        }
        $pdo->commit();
        json_out(['id' => $vid, 'carried' => $carried, 'placement' => $from ? place_carried_notes($vid) : null]);

    case 'POST version.update':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $fields = [];
        $vals = [];
        if (isset($in['label'])) {
            $fields[] = 'label = ?';
            $vals[] = str_in($in, 'label', 200);
        }
        if (isset($in['color'])) {
            if (!in_array($in['color'], REVISION_COLORS, true)) {
                fail('Pick one of the revision colours.');
            }
            $fields[] = 'color = ?';
            $vals[] = $in['color'];
        }
        if (isset($in['rate'])) {
            $rate = (float)$in['rate'];
            if ($rate < 1 || $rate > 120) {
                fail('Frame rate must be between 1 and 120.');
            }
            $fields[] = 'rate = ?';
            $vals[] = $rate;
        }
        if (isset($in['tc_base'])) {
            $b = (int)$in['tc_base'];
            if (!in_array($b, [24, 25, 30, 48, 50, 60], true)) {
                fail('Timecode base must be 24, 25, 30, 48, 50 or 60.');
            }
            $fields[] = 'tc_base = ?';
            $vals[] = $b;
        }
        if (array_key_exists('video_url', $in)) {
            $url = str_in($in, 'video_url', 4000, false);
            if ($url !== '' && !preg_match('#^https://#i', $url)) {
                fail('Video links must start with https://');
            }
            $fields[] = 'video_url = ?';
            $vals[] = $url !== '' ? $url : null;
        }
        if (!$fields) {
            fail('Nothing to change.');
        }
        $vals[] = $vid;
        $pdo->prepare('UPDATE versions SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
        json_out(['ok' => true]);

    case 'POST version.delete':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $pdo->beginTransaction();
        // Notes that were first raised on this version and appear nowhere else go with it.
        $pdo->prepare(
            'DELETE FROM notes WHERE origin_version_id = ?
               AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.note_id = notes.id AND p.version_id <> ?)'
        )->execute([$vid, $vid]);
        $pdo->prepare('DELETE FROM versions WHERE id = ?')->execute([$vid]);
        $pdo->commit();
        @unlink(DATA_DIR . "/fingerprints/$vid.bin");
        audio_remove_files($vid);
        json_out(['ok' => true]);

    case 'POST version.import':
        $vid = int_in($in, 'version_id');
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            fail('Choose a Frame.io comment export (.csv) to import.');
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            fail('That file is over 10 MB, which is too large for a comment export.');
        }
        json_out(import_csv_into_version($vid, $f['tmp_name'], $user));

    case 'GET notes':
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $st = $pdo->prepare(
            'SELECT n.id, n.author, n.tag, n.text, n.created_at, n.fio_comment_id, n.origin_version_id, n.share_id,
                    ov.label AS origin_label, p.frame, p.end_frame, p.confirmed, p.method, p.reason
             FROM positions p
             JOIN notes n ON n.id = p.note_id
             LEFT JOIN versions ov ON ov.id = n.origin_version_id
             WHERE p.version_id = ?
             ORDER BY p.frame, n.created_at'
        );
        $st->execute([$vid]);
        $notes = $st->fetchAll();
        $ids = array_column($notes, 'id');
        $events = $replies = $history = $edits = [];
        if ($ids) {
            $in_ = implode(',', array_map('intval', $ids));
            foreach ($pdo->query(
                "SELECT e.note_id, e.status, e.comment, e.by_name, e.at, v.label AS version_label
                 FROM events e LEFT JOIN versions v ON v.id = e.version_id
                 WHERE e.note_id IN ($in_) ORDER BY e.id"
            ) as $e) {
                $events[$e['note_id']][] = $e;
            }
            foreach ($pdo->query("SELECT note_id, old_text, by_name, at FROM note_edits WHERE note_id IN ($in_) ORDER BY id") as $x) {
                $edits[$x['note_id']][] = $x;
            }
            foreach ($pdo->query(
                "SELECT r.note_id, r.author, r.text, r.created_at, r.source, r.share_id, v.label AS version_label
                 FROM replies r LEFT JOIN versions v ON v.id = r.version_id
                 WHERE r.note_id IN ($in_) ORDER BY r.created_at, r.id"
            ) as $r) {
                $replies[$r['note_id']][] = $r;
            }
            foreach ($pdo->query(
                "SELECT p.note_id, p.frame, p.confirmed, v.id AS version_id, v.label, v.tc_base, v.tc_start
                 FROM positions p JOIN versions v ON v.id = p.version_id
                 WHERE p.note_id IN ($in_) ORDER BY v.id"
            ) as $h) {
                $history[$h['note_id']][] = [
                    'version_id' => (int)$h['version_id'],
                    'label' => $h['label'],
                    'tc' => frames_to_tc((int)$h['frame'] + (int)$h['tc_start'], (int)$h['tc_base']),
                    'confirmed' => (bool)$h['confirmed'],
                ];
            }
        }
        foreach ($notes as &$n) {
            $ev = $events[$n['id']] ?? [];
            $n['id'] = (int)$n['id'];
            $n['frame'] = (int)$n['frame'];
            $n['end'] = $n['end_frame'] !== null ? (int)$n['end_frame'] : null;
            unset($n['end_frame']);
            $n['confirmed'] = (bool)$n['confirmed'];
            $n['carried'] = (int)$n['origin_version_id'] !== $vid;
            $n['status'] = $ev ? end($ev)['status'] : 'open';
            $n['events'] = $ev;
            $n['replies'] = $replies[$n['id']] ?? [];
            $n['edits'] = $edits[$n['id']] ?? [];
            $n['history'] = $history[$n['id']] ?? [];
        }
        unset($n);
        $edit = active_edit($vid);
        $shots = [];
        if ($edit) {
            $sc = 1 / edit_scale($edit, (int)$version['tc_base']);
            $ss = $pdo->prepare("SELECT track, rec_in, rec_out, name FROM shots WHERE edit_id = ? AND kind = 'V' ORDER BY rec_in, track");
            $ss->execute([(int)$edit['id']]);
            foreach ($ss as $r) {
                $shots[] = [(int)round($r['rec_in'] * $sc), (int)round($r['rec_out'] * $sc), (int)$r['track'], $r['name']];
            }
        }
        json_out(['version' => $version, 'notes' => $notes, 'shots' => $shots,
            'edit' => $edit ? ['filename' => $edit['filename'], 'format' => $edit['format'], 'shots' => (int)$edit['shot_count']] : null]);

    case 'POST note.create':
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $frame = max(0, int_in($in, 'frame'));
        $keys = film_keys((int)$version['film_id']);
        [$tag, $text] = split_tag(str_in($in, 'text', 5000), $keys);
        // A track code typed at the start of the note ("M: …") wins over the menu.
        if ($tag === '' && isset($in['tag'])) {
            $tag = clean_tag((string)$in['tag'], $keys);
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO notes (film_id, origin_version_id, author, tag, text, created_at, share_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([(int)$version['film_id'], $vid, $user, $tag, $text, now_iso(), $share ? (int)$share['id'] : null]);
        $nid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO positions (note_id, version_id, frame, confirmed, method) VALUES (?,?,?,1,'manual')")->execute([$nid, $vid, $frame]);
        add_event($nid, $vid, 'open', null, $user);
        $pdo->commit();
        json_out(['id' => $nid]);

    case 'POST note.status':
        $nid = int_in($in, 'note_id');
        note_row($nid);
        $vid = int_in($in, 'version_id');
        add_event($nid, $vid, str_in($in, 'status', 20), str_in($in, 'comment', 5000, false), $user);
        json_out(['ok' => true]);

    case 'POST note.reply':
        $nid = int_in($in, 'note_id');
        note_row($nid);
        $vid = int_in($in, 'version_id');
        $pdo->prepare("INSERT INTO replies (note_id, version_id, author, text, created_at, source, share_id) VALUES (?,?,?,?,?,'app',?)")
            ->execute([$nid, $vid, $user, str_in($in, 'text', 5000), now_iso(), $share ? (int)$share['id'] : null]);
        json_out(['ok' => true]);

    case 'POST note.tag':
        $nid = int_in($in, 'note_id');
        $note = note_row($nid);
        $tag = clean_tag((string)($in['tag'] ?? ''), film_keys((int)$note['film_id']));
        $pdo->prepare('UPDATE notes SET tag = ? WHERE id = ?')->execute([$tag, $nid]);
        json_out(['ok' => true]);

    case 'POST note.position':
        $nid = int_in($in, 'note_id');
        $vid = int_in($in, 'version_id');
        $frame = max(0, int_in($in, 'frame'));
        $upd = $pdo->prepare("UPDATE positions SET end_frame = CASE WHEN end_frame IS NULL THEN NULL ELSE end_frame - frame + ? END,
            frame = ?, confirmed = 1, method = 'manual' WHERE note_id = ? AND version_id = ?");
        $upd->execute([$frame, $frame, $nid, $vid]);
        if ($upd->rowCount() === 0) {
            fail('That note isn’t part of this version.', 404);
        }
        json_out(['ok' => true]);

    /* ---------- placing by picture ---------- */
    case 'POST fingerprint.save':
        // Fingerprints are made in the browser from the video; only these small files reach the server.
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $f = $_FILES['fp'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            fail('The fingerprint didn’t upload. Try again.');
        }
        if ($f['size'] > 60 * 1024 * 1024) {
            fail('That fingerprint is too large.');
        }
        $raw = (string)file_get_contents($f['tmp_name']);
        if (substr($raw, 0, 2) === "\x1f\x8b") {
            // Sent gzipped by the browser.
            $raw = (string)@gzdecode($raw, 60 * 1024 * 1024);
        }
        if (substr($raw, 0, 4) !== 'PNFP') {
            fail('That isn’t a fingerprint file.');
        }
        $hl = unpack('V', substr($raw, 4, 4))[1];
        $head = json_decode(substr($raw, 8, $hl), true);
        if (!is_array($head) || !isset($head['n'], $head['dur'])) {
            fail('That fingerprint file is damaged.');
        }
        $dir = DATA_DIR . '/fingerprints';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            fail('Couldn’t create the data/fingerprints folder. Check folder permissions.', 500);
        }
        if (file_put_contents("$dir/$vid.bin", gzencode($raw, 6), LOCK_EX) === false) {
            fail('Couldn’t save the fingerprint. Check that the data folder is writable.', 500);
        }
        $pdo->prepare('INSERT OR REPLACE INTO fingerprints (version_id, bytes, duration, samples, source, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$vid, strlen($raw), (float)$head['dur'], (int)$head['n'], mb_substr((string)($in['source'] ?? ''), 0, 200), $user, now_iso()]);
        json_out(['ok' => true, 'at' => now_iso()]);

    case 'GET fingerprint':
        $vid = int_in($in, 'version_id');
        $file = DATA_DIR . "/fingerprints/$vid.bin";
        if (!is_file($file)) {
            fail('This version hasn’t been fingerprinted yet.', 404);
        }
        header('Content-Type: application/octet-stream');
        header('Cache-Control: private, max-age=0');
        echo gzdecode((string)file_get_contents($file));
        exit;

    case 'POST version.place_visual':
        // Results worked out in the browser. Only carried notes (and ones placed automatically) move:
        // notes someone pinned, or that came from this cut's own comments, stay where they are.
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $rate = max(1.0, (float)$version['rate']);
        $results = is_array($in['results'] ?? null) ? $in['results'] : [];
        $byTranscript = ['transcript', 'narration-clip', 'line-reworded', 'line-removed'];
        $allowed = ['visual', 'visual-ai', 'ambiguous', 'trimmed', 'removed', 'likely', 'changed'];
        $get = $pdo->prepare('SELECT frame, method, reason, confirmed FROM positions WHERE note_id = ? AND version_id = ?');
        $move = $pdo->prepare('UPDATE positions SET end_frame = CASE WHEN end_frame IS NULL THEN NULL ELSE end_frame - frame + ? END,
            frame = ?, confirmed = ?, method = ?, reason = ? WHERE note_id = ? AND version_id = ?');
        $flag = $pdo->prepare("UPDATE positions SET confirmed = 0, reason = 'visual-disagrees' WHERE note_id = ? AND version_id = ?");
        $stats = ['placed' => 0, 'review' => 0, 'unplaced' => 0, 'kept' => 0, 'already' => 0, 'disagree' => 0, 'total' => 0, 'reasons' => []];
        $pdo->beginTransaction();
        foreach ($results as $r) {
            $nid = (int)($r['note_id'] ?? 0);
            $reason = (string)($r['reason'] ?? '');
            if (!$nid || !in_array($reason, $allowed, true)) {
                continue;
            }
            $get->execute([$nid, $vid]);
            $p = $get->fetch();
            if (!$p || in_array($p['method'], ['manual', 'import'], true)) {
                continue;
            }
            $stats['total']++;
            if (!empty($in['coarse']) && $p['method'] === 'auto' && in_array($p['reason'], ['visual', 'visual-ai'], true) && (int)$p['confirmed'] === 1) {
                $stats['already']++; // an earlier run with the videos placed it to the frame; a run without them is rougher
                continue;
            }
            if ($p['method'] === 'auto' && in_array($p['reason'], $byTranscript, true)) {
                $stats['kept']++; // narration notes placed by the transcript stay
                continue;
            }
            $frame = max(0, (int)($r['frame'] ?? 0));
            $sure = !empty($r['confident']) && in_array($reason, ['visual', 'visual-ai'], true);
            if ($p['method'] === 'auto' && $p['reason'] === 'picture' && (int)$p['confirmed'] === 1) {
                // Already placed from the edit file. The edit is exact; flag only when the pictures say otherwise.
                if ($sure && abs($frame - (int)$p['frame']) > $rate * 0.5) {
                    $flag->execute([$nid, $vid]);
                    $stats['disagree']++;
                } else {
                    $stats['kept']++;
                }
                continue;
            }
            $move->execute([$frame, $frame, $sure ? 1 : 0, 'auto', $reason, $nid, $vid]);
            $stats[$sure ? 'placed' : ($reason === 'removed' ? 'unplaced' : 'review')]++;
            $stats['reasons'][$reason] = ($stats['reasons'][$reason] ?? 0) + 1;
        }
        $pdo->commit();
        json_out(['ok' => true, 'stats' => $stats]);

    case 'POST version.tc_start':
        // Set the timecode of the video's first frame. With move_notes, also move this version's notes back by the
        // change: for notes that were imported as if the video started at the old timecode.
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $base = (int)$version['tc_base'];
        $new = tc_to_frames(str_in($in, 'start_tc', 20), $base);
        if ($new === null) {
            fail('Enter the start timecode like 10:00:00:00.');
        }
        $old = (int)$version['tc_start'];
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE versions SET tc_start = ? WHERE id = ?')->execute([$new, $vid]);
        $moved = 0;
        if (!empty($in['move_notes']) && $new !== $old) {
            $d = $new - $old;
            $st = $pdo->prepare('UPDATE positions SET frame = MAX(0, frame - ?), end_frame = CASE WHEN end_frame IS NULL THEN NULL ELSE MAX(1, end_frame - ?) END WHERE version_id = ?');
            $st->execute([$d, $d, $vid]);
            $moved = $st->rowCount();
        }
        $pdo->commit();
        json_out(['ok' => true, 'tc_start' => $new, 'moved' => $moved]);

    case 'POST note.edit':
        // Reword a note. Editors and admins can reword any note; reviewers only their own.
        $nid = int_in($in, 'note_id');
        $note = note_row($nid);
        if ($share && ((int)$note['share_id'] !== (int)$share['id'] || $note['author'] !== $me['name'])) {
            fail('You can reword only the notes you added from this link.', 403);
        }
        if (!role_at_least($me, 'editor') && $note['author'] !== $me['name']) {
            fail('Reviewers can reword only their own notes. Reply to this one instead, or ask an editor.', 403);
        }
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') {
            fail('A note can’t be empty.');
        }
        if (mb_strlen($text) > 5000) {
            fail('That note is too long.');
        }
        if ($text !== $note['text']) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO note_edits (note_id, old_text, new_text, by_name, at) VALUES (?,?,?,?,?)')
                ->execute([$nid, $note['text'], $text, $user, now_iso()]);
            $pdo->prepare('UPDATE notes SET text = ? WHERE id = ?')->execute([$text, $nid]);
            $pdo->commit();
        }
        json_out(['ok' => true, 'text' => $text]);

    /* ---------- notes out: markers and Frame.io ---------- */
    case 'GET export.markers':
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $format = (string)($in['format'] ?? 'avid');
        $tracks = isset($in['tracks']) && $in['tracks'] !== '' ? explode(',', (string)$in['tracks']) : null;
        if ($tracks !== null) {
            $tracks = array_map(fn($t) => $t === '_' ? '' : $t, $tracks);
        }
        $notes = export_notes($vid, ($in['scope'] ?? 'open') === 'all' ? 'all' : 'open', $tracks, !empty($in['placed']));
        $colors = array_column(film_categories((int)$v['film_id']), 'color', 'key');
        $name = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $v['label']) . '_notes';
        if ($format === 'resolve') {
            $body = markers_resolve($notes, $v, $colors);
            $file = "$name.edl"; $type = 'text/plain';
        } elseif ($format === 'xml') {
            $last = $notes ? max(array_map(fn($n) => (int)($n['end_frame'] ?? $n['frame']), $notes)) : 0;
            $body = markers_xml($notes, $v, $colors, max($last + 10 * (int)$v['tc_base'], (float)($in['dur'] ?? 0)));
            $file = "$name.xml"; $type = 'application/xml';
        } else {
            $body = markers_avid($notes, $v, $colors);
            $file = "$name.txt"; $type = 'text/plain';
        }
        header("Content-Type: $type; charset=utf-8");
        header('Content-Disposition: attachment; filename="' . $file . '"');
        echo $body;
        exit;

    case 'GET fio.push_preview':
        // Notes in this version that aren't on its Frame.io file yet.
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        if (!$v['fio_file_id']) {
            fail('Link this version to its Frame.io file first.');
        }
        $notes = export_notes($vid, ($in['scope'] ?? 'open') === 'all' ? 'all' : 'open', null, !empty($in['placed']));
        $pushed = $pdo->prepare('SELECT note_id FROM fio_pushed WHERE version_id = ?');
        $pushed->execute([$vid]);
        $done = array_flip($pushed->fetchAll(PDO::FETCH_COLUMN));
        $out = ['new' => [], 'carried' => [], 'already' => 0, 'on_file' => 0];
        foreach ($notes as $n) {
            if (isset($done[$n['id']])) { $out['already']++; continue; }
            if ($n['fio_comment_id'] && (int)$n['origin_version_id'] === $vid) { $out['on_file']++; continue; } // came from this file's comments
            $out[(int)$n['origin_version_id'] === $vid ? 'new' : 'carried'][] = ['id' => (int)$n['id'], 'frame' => (int)$n['frame'], 'text' => mb_substr(export_text($n), 0, 80)];
        }
        json_out($out);

    case 'POST fio.push':
        // Post notes as comments on this version's Frame.io file, a few at a time (Frame.io allows about 100 a minute).
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        if (!$v['fio_file_id']) {
            fail('Link this version to its Frame.io file first.');
        }
        $ids = array_slice(array_map('intval', is_array($in['note_ids'] ?? null) ? $in['note_ids'] : []), 0, 25);
        $extras = !empty($in['extras']);
        $base = (int)$v['tc_base'];
        $acc = $v['fio_account_id'] ?: null;
        @set_time_limit(120);
        $all = [];
        foreach (export_notes($vid, 'all') as $n) {
            $all[(int)$n['id']] = $n;
        }
        $first = !(int)$pdo->query('SELECT COUNT(*) FROM fio_pushed WHERE version_id = ' . $vid)->fetchColumn();
        $ins = $pdo->prepare('INSERT OR REPLACE INTO fio_pushed (note_id, version_id, fio_comment_id, pushed_by, pushed_at) VALUES (?,?,?,?,?)');
        $posted = 0;
        $errors = [];
        foreach ($ids as $id) {
            $n = $all[$id] ?? null;
            if (!$n) {
                continue;
            }
            $tcode = frames_to_tc((int)$n['frame'] + (int)$v['tc_start'], $base);
            $dur = $n['end_frame'] !== null ? ((int)$n['end_frame'] - (int)$n['frame']) / max(1, $base) : null;
            try {
                $c = fio_create_comment($v['fio_file_id'], push_text($n, $user, $extras), $tcode,
                    $dur, in_array($n['status'], ['addressed', 'approved'], true), $acc);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                if (count($errors) >= 3) {
                    break;
                }
                continue;
            }
            $cid = (string)($c['id'] ?? '');
            if ($cid === '') {
                $errors[] = 'Frame.io didn’t return the new comment.';
                continue;
            }
            if ($first) {
                // First comment on this file: read it back and make sure it landed on the right frame.
                $first = false;
                $back = null;
                foreach (fio_get_all("/v4/accounts/" . fio_account($acc) . "/files/{$v['fio_file_id']}/comments", ['page_size' => 100, 'timestamp_as_timecode' => 'true']) as $row) {
                    if (($row['id'] ?? '') === $cid) { $back = $row; break; }
                }
                $got = is_string($back['timestamp'] ?? null) ? tc_to_frames($back['timestamp'], $base) : null;
                $want = (int)$n['frame'] + (int)$v['tc_start'];
                if ($back !== null && $got !== null && abs($got - $want) > 1) {
                    try { fio_delete_comment($cid, $acc); $gone = true; } catch (Throwable $e) { $gone = false; }
                    fail('The test comment landed at ' . $back['timestamp'] . ' in Frame.io instead of ' . frames_to_tc($want, $base)
                        . ', so nothing else was posted.' . ($gone ? ' The test comment was removed.' : ' Please delete the test comment in Frame.io.')
                        . ' Check the version’s start timecode (Films & versions → Timecode).', 409);
                }
            }
            $ins->execute([$id, $vid, $cid, $user, now_iso()]);
            $posted++;
        }
        json_out(['ok' => true, 'posted' => $posted, 'errors' => $errors]);

    case 'POST note.range':
        // Set or clear a note's out-point in one version. end = null (or not after the in-point) clears it.
        $nid = int_in($in, 'note_id');
        $vid = int_in($in, 'version_id');
        $st = $pdo->prepare('SELECT frame FROM positions WHERE note_id = ? AND version_id = ?');
        $st->execute([$nid, $vid]);
        $at = $st->fetchColumn();
        if ($at === false) {
            fail('That note isn’t part of this version.', 404);
        }
        $end = isset($in['end']) && $in['end'] !== null && $in['end'] !== '' ? (int)$in['end'] : null;
        if ($end !== null && $end <= (int)$at) {
            $end = null;
        }
        $pdo->prepare('UPDATE positions SET end_frame = ? WHERE note_id = ? AND version_id = ?')->execute([$end, $nid, $vid]);
        json_out(['ok' => true, 'end' => $end]);

    case 'POST version.edit':
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $f = $_FILES['edit'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            fail($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is larger than the server accepts. Raise upload_max_filesize in cPanel → Select PHP Version → Options.'
                : 'Choose an edit file: .otio, FCP7 .xml or .edl.');
        }
        @set_time_limit(120);
        $parsed = read_edit_file($f['tmp_name'], (string)$f['name'], (int)$version['tc_base']);
        store_edit($vid, $parsed, (string)$f['name'], $user);
        $v = count(array_filter($parsed['events'], fn($e) => $e['kind'] === 'V'));
        json_out([
            'format' => $parsed['format'], 'shots' => $v, 'events' => count($parsed['events']),
            'start_tc' => frames_to_tc((int)$parsed['start'], max(1, (int)round($parsed['rate']))),
            'rate' => $parsed['rate'], 'warnings' => $parsed['warnings'], 'placement' => place_around($vid),
        ]);

    case 'POST edit.start':
        // The record timecode the uploaded video starts at (EDLs usually begin at 01:00:00:00).
        $vid = int_in($in, 'version_id');
        $edit = active_edit($vid) ?? fail('This version has no edit yet.', 404);
        $base = max(1, (int)round((float)$edit['rate']));
        $new = tc_to_frames(str_in($in, 'start_tc', 20), $base);
        if ($new === null) {
            fail('Use a timecode like 01:00:00:00.');
        }
        $delta = $new - (int)$edit['start_frame'];
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE shots SET rec_in = rec_in - ?, rec_out = rec_out - ? WHERE edit_id = ?')->execute([$delta, $delta, (int)$edit['id']]);
        $pdo->prepare('UPDATE edits SET start_frame = ? WHERE id = ?')->execute([$new, (int)$edit['id']]);
        $pdo->commit();
        json_out(['ok' => true, 'placement' => place_around($vid)]);

    case 'POST version.place':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        json_out(['placement' => place_carried_notes($vid)]);

    case 'POST version.transcript':
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $f = $_FILES['transcript'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            fail('Choose a transcript: .srt, .vtt or timecoded .txt.');
        }
        if ($f['size'] > 8 * 1024 * 1024) {
            fail('That transcript is over 8 MB, which is too large.');
        }
        $parsed = parse_transcript((string)file_get_contents($f['tmp_name']), (int)$version['tc_base'], (float)$version['rate']);
        $tid = store_transcript($vid, $parsed, (string)$f['name'], $user);
        json_out(['id' => $tid, 'segments' => count($parsed['segments']), 'format' => $parsed['format'],
            'offset_tc' => $parsed['offset'] ? frames_to_tc($parsed['offset'], (int)$version['tc_base']) : null,
            'placement' => place_around($vid)]);

    case 'POST transcript.delete':
        $tid = int_in($in, 'transcript_id');
        $st = $pdo->prepare('SELECT version_id FROM transcripts WHERE id = ?');
        $st->execute([$tid]);
        $vid = $st->fetchColumn() ?: fail('That transcript no longer exists.', 404);
        $pdo->prepare('DELETE FROM transcripts WHERE id = ?')->execute([$tid]);
        json_out(['ok' => true, 'placement' => place_around((int)$vid)]);

    case 'GET transcript':
        $vid = int_in($in, 'version_id');
        $version = version_row($vid);
        $list = $pdo->prepare('SELECT id, filename, format, segment_count, uploaded_by, uploaded_at FROM transcripts WHERE version_id = ? ORDER BY id DESC');
        $list->execute([$vid]);
        $uploads = $list->fetchAll();
        if (!$uploads) {
            json_out(['uploads' => [], 'segments' => [], 'gone' => [], 'compare' => null]);
        }
        $tid = isset($in['transcript_id']) && is_numeric($in['transcript_id']) ? (int)$in['transcript_id'] : (int)$uploads[0]['id'];
        if (!in_array($tid, array_map(fn($u) => (int)$u['id'], $uploads), true)) {
            fail('That transcript isn’t part of this version.', 404);
        }
        $segs = transcript_segments($tid);
        // Compare with the cut these notes were carried from, or else the previous upload for this cut.
        $compare = null;
        $prevSegs = null;
        if ($version['carried_from'] && ($pt = active_transcript((int)$version['carried_from']))) {
            $pv = version_row((int)$version['carried_from']);
            $compare = $pv['label'];
            $prevSegs = transcript_segments((int)$pt['id']);
        } else {
            foreach ($uploads as $u) {
                if ((int)$u['id'] < $tid) {
                    $compare = 'the previous upload (' . $u['filename'] . ')';
                    $prevSegs = transcript_segments((int)$u['id']);
                    break;
                }
            }
        }
        $cmp = $prevSegs !== null ? transcript_compare($segs, $prevSegs) : null;
        $out = [];
        foreach ($segs as $i => $s) {
            $out[] = ['start' => $s['start'], 'end' => $s['end'], 'speaker' => $s['speaker'], 'text' => $s['text'],
                'change' => $cmp['segments'][$i]['change'] ?? null, 'prev' => $cmp['segments'][$i]['prev'] ?? null];
        }
        json_out(['uploads' => $uploads, 'active_id' => $tid, 'latest_id' => (int)$uploads[0]['id'], 'segments' => $out,
            'gone' => array_map(fn($g) => ['start' => $g['start'], 'text' => $g['text']], $cmp['gone'] ?? []), 'compare' => $compare]);

    /* ---------------- Script view ---------------- */

    case 'GET script':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $t = active_transcript($vid);
        if (!$t) {
            json_out(['transcript_id' => null, 'lines' => []]);
        }
        $segs = transcript_segments((int)$t['id']);
        $lines = script_recorded($vid, script_lines($vid, (int)$t['id'], $segs));
        json_out(['transcript_id' => (int)$t['id'], 'lines' => array_values($lines),
            'times' => script_times($vid, (int)$t['id'], $segs, $lines, (float)version_row($vid)['tc_base'])]);

    case 'POST script.time':
        // Move a line of the new script, or change how long it lasts, from the timeline's Script track.
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $tid = int_in($in, 'transcript_id');
        $t = active_transcript($vid);
        if (!$t || (int)$t['id'] !== $tid) {
            fail('The transcript has changed since you opened it. Reload the page and try again.', 409);
        }
        $segs = transcript_segments($tid);
        $i = int_in($in, 'seg_idx');
        $slot = int_in($in, 'slot');
        $lines = script_lines($vid, $tid, $segs);
        if (!isset($segs[$i]) || ($slot > 0 && (!isset($lines["$i:$slot"]) || $lines["$i:$slot"]['state'] === 'removed'))) {
            fail('That line no longer exists.', 404);
        }
        $start = max(0, int_in($in, 'start'));
        $end = int_in($in, 'end');
        if ($end <= $start) {
            fail('A line needs to end after it starts.');
        }
        $pdo->prepare('INSERT INTO script_times (version_id, transcript_id, seg_idx, seg_norm, slot, start_frame, end_frame, by_name, at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$vid, $tid, $i, $segs[$i]['norm'], $slot, $start, $end, $user, now_iso()]);
        json_out(['ok' => true, 'times' => script_times($vid, $tid, $segs, $lines, (float)$v['tc_base'])]);

    case 'POST script.edit':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $tid = int_in($in, 'transcript_id');
        $t = active_transcript($vid);
        if (!$t || (int)$t['id'] !== $tid) {
            fail('The transcript has changed since you opened it. Reload the page and try again.', 409);
        }
        $segs = transcript_segments($tid);
        $i = int_in($in, 'seg_idx');
        if (!isset($segs[$i])) {
            fail('That line no longer exists.', 404);
        }
        $state = (string)($in['state'] ?? '');
        if (!in_array($state, SCRIPT_STATES, true)) {
            fail('Unknown line state.');
        }
        $text = trim((string)($in['text'] ?? ''));
        if (mb_strlen($text) > 4000) {
            fail('That line is too long.');
        }
        if (in_array($state, ['draft', 'approved'], true) && $text === '') {
            fail('Write the new line first, or mark the line as cut.');
        }
        $slot = (string)($in['slot'] ?? '0');
        if ($slot === 'new') {
            // A new line after this one: the next free slot.
            $lines = script_lines($vid, $tid, $segs);
            $used = array_map(fn($l) => $l['slot'], array_filter($lines, fn($l) => $l['i'] === $i));
            $slot = $used ? max($used) + 1 : 1;
            $slot = max(1, $slot);
        }
        $slot = (int)$slot;
        if (!role_at_least($me, 'editor')) {
            $curLine = script_lines($vid, $tid, $segs)[$i . ':' . $slot] ?? null;
            $cur = $curLine['state'] ?? 'same';
            // Reviewers suggest drafts, and can take back a draft of their own (back to the original, or delete an added line).
            $withdraw = $cur === 'draft' && ($curLine['by'] ?? '') === $user && ($state === ($slot > 0 ? 'removed' : 'same'));
            // Guests whose link allows script changes can also cross out a line (not an approved one), and restore
            // lines they crossed out themselves.
            $guestCross = $share && $slot === 0 && (($state === 'cut' && in_array($cur, ['same', 'draft'], true))
                || ($state === 'same' && $cur === 'cut' && ($curLine['by'] ?? '') === $user));
            if (!$withdraw && !$guestCross && ($state !== 'draft' || in_array($cur, ['approved', 'cut'], true))) {
                fail('Reviewers can suggest new wording as a draft, and take back their own drafts. Approving, crossing out or changing an approved line is for editors.', 403);
            }
        }
        $pdo->prepare('INSERT INTO script_edits (version_id, transcript_id, seg_idx, seg_norm, slot, state, text, by_name, at, share_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$vid, $tid, $i, $segs[$i]['norm'], $slot, $state, $text, $user, now_iso(), $share ? (int)$share['id'] : null]);
        $lines = script_recorded($vid, script_lines($vid, $tid, $segs));
        json_out(['ok' => true, 'line' => $lines[$i . ':' . $slot] ?? null, 'lines' => array_values($lines),
            'times' => script_times($vid, $tid, $segs, $lines, (float)version_row($vid)['tc_base'])]);

    case 'GET script.export':
        /*
         * content: original  the transcript as it is
         *          merged    the whole new script: new wording in place of the old, added lines where they go,
         *                    crossed-out lines left out (or marked, with show_cut)
         *          changes   only what changed (the recording script)
         * format: pdf (changed lines highlighted), csv (a Change column first) or txt. drafts=0 leaves draft wording out (merged keeps the original line instead).
         */
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $t = active_transcript($vid) ?? fail('This version has no transcript.', 404);
        $segs = transcript_segments((int)$t['id']);
        $lines = script_lines($vid, (int)$t['id'], $segs);
        $base = (int)$v['tc_base'];
        $times = script_times($vid, (int)$t['id'], $segs, $lines, (float)$base);
        $tcf = fn(int $f) => frames_to_tc($f + (int)$v['tc_start'], $base);
        $content = in_array($in['content'] ?? '', ['original', 'merged', 'changes'], true) ? $in['content'] : 'changes';
        $format = in_array($in['format'] ?? '', ['pdf', 'csv', 'txt'], true) ? $in['format'] : 'pdf';
        $drafts = ($in['drafts'] ?? '1') !== '0';
        $showCut = !empty($in['show_cut']);
        $film = $pdo->query('SELECT name FROM films WHERE id = ' . (int)$v['film_id'])->fetchColumn();
        $fname = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $film . '_' . $v['label']);
        $useNew = fn(?array $l) => $l && ($l['state'] === 'approved' || ($drafts && $l['state'] === 'draft'));

        // Every row: tc, end, speaker, line, kind (same / rewritten / new / crossed out), state, was, by, at.
        $rows = [];
        foreach ($segs as $i => $s) {
            $l = $lines["$i:0"] ?? null;
            [$in0, $out0] = $content === 'original' ? [(int)$s['start'], (int)$s['end']] : $times["$i:0"];
            $row = ['tc' => $tcf($in0), 'end' => $tcf($out0), 'speaker' => $s['speaker'], 'line' => $s['text'], 'kind' => 'same',
                'state' => '', 'was' => '', 'by' => '', 'at' => ''];
            if ($content !== 'original' && $l) {
                if ($l['state'] === 'cut') {
                    $row = ['kind' => 'crossed out', 'state' => 'crossed out', 'was' => $s['text'], 'by' => $l['by'], 'at' => $l['at']] + $row;
                } elseif ($useNew($l)) {
                    $row = ['line' => $l['text'], 'kind' => 'rewritten', 'state' => $l['state'], 'was' => $s['text'], 'by' => $l['by'], 'at' => $l['at']] + $row;
                }
            }
            $rows[] = $row;
            if ($content === 'original') {
                continue;
            }
            $extra = array_filter($lines, fn($x) => $x['i'] === $i && $x['slot'] > 0 && $useNew($x));
            usort($extra, fn($a, $b) => $a['slot'] <=> $b['slot']);
            foreach ($extra as $x) {
                $k = "$i:{$x['slot']}";
                $rows[] = ['tc' => $tcf($times[$k][0]), 'end' => $tcf($times[$k][1]), 'speaker' => $s['speaker'], 'line' => $x['text'], 'kind' => 'new',
                    'state' => $x['state'], 'was' => '', 'by' => $x['by'], 'at' => $x['at']];
            }
        }
        if ($content === 'changes') {
            $rows = array_values(array_filter($rows, fn($r) => $r['kind'] !== 'same'));
        } elseif ($content === 'merged' && !$showCut) {
            $rows = array_values(array_filter($rows, fn($r) => $r['kind'] !== 'crossed out'));
        }
        // New and rewritten lines can move on the timeline: keep the file in time order.
        if ($content !== 'original') {
            usort($rows, fn($a, $b) => strcmp($a['tc'], $b['tc']));
        }

        $title = ['original' => 'Original script', 'merged' => 'New script', 'changes' => 'Script changes'][$content];
        $suffix = ['original' => '_original_script', 'merged' => '_new_script', 'changes' => '_script_changes'][$content];
        $stamp = gmdate('Y-m-d H:i') . ' UTC';
        if ($format === 'pdf') {
            require_once __DIR__ . '/lib/pdf.php';
            $body = script_pdf("$film · {$v['label']}", $title . ($content !== 'original' && !$drafts ? ' (approved changes)' : ''), $stamp, $content, $rows);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $fname . $suffix . '.pdf"');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit;
        }
        if ($format === 'txt') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . $suffix . '.txt"');
            echo "$film · {$v['label']}\n$title — exported $stamp\n";
            if ($content === 'merged') {
                echo "Changed lines are marked * (new lines +)" . ($drafts ? ', drafts included' : ', approved changes only') . ".\n";
            }
            echo "\n";
            foreach ($rows as $r) {
                $who = $r['speaker'] !== '' ? "  ({$r['speaker']})" : '';
                if ($content === 'changes') {
                    if ($r['kind'] === 'crossed out') {
                        echo "[{$r['tc']}]  CROSSED OUT$who\n    was: {$r['was']}\n\n";
                        continue;
                    }
                    $mark = $r['state'] === 'approved' ? 'APPROVED' : 'DRAFT';
                    echo "[{$r['tc']} – {$r['end']}]  $mark$who\n    {$r['line']}\n" . ($r['was'] !== '' ? "    was: {$r['was']}\n" : "    (new line)\n") . "\n";
                    continue;
                }
                $mark = ['same' => ' ', 'rewritten' => '*', 'new' => '+', 'crossed out' => 'x'][$r['kind']];
                $line = $r['kind'] === 'crossed out' ? "[crossed out] {$r['was']}" : $r['line'];
                echo ($content === 'merged' ? "$mark " : '') . "[{$r['tc']}]" . ($r['speaker'] !== '' ? " {$r['speaker']}:" : '') . " $line\n";
            }
            if (!$rows) {
                echo $content === 'changes' ? "No lines have been changed yet.\n" : "The transcript is empty.\n";
            }
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . $suffix . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        // Spreadsheets can't carry highlighting, so the change is the first column, in capitals, and the line itself is
        // marked: ★ rewritten, + new, ✕ crossed out (with the old wording).
        $head = $content === 'original' ? ['Timecode', 'End', 'Speaker', 'Line'] : ['Change', 'Timecode', 'End', 'Speaker', 'Line', 'Was', 'Status', 'Changed by', 'Changed at'];
        fputcsv($out, $head, ',', '"', '');
        $mark = ['same' => '', 'rewritten' => '★ ', 'new' => '+ ', 'crossed out' => '✕ '];
        foreach ($rows as $r) {
            if ($content === 'original') {
                fputcsv($out, [$r['tc'], $r['end'], $r['speaker'], $r['line']], ',', '"', '');
                continue;
            }
            $line = $r['kind'] === 'crossed out' ? '✕ (crossed out) ' . $r['was'] : $mark[$r['kind']] . $r['line'];
            fputcsv($out, [$r['kind'] === 'same' ? '' : strtoupper($r['kind']), $r['tc'], $r['end'], $r['speaker'], $line,
                $r['kind'] === 'rewritten' ? $r['was'] : '', $r['state'], $r['by'], $r['at']], ',', '"', '');
        }
        fclose($out);
        exit;

    /* ---------------- New voice-over with ElevenLabs (lib/vo.php) ---------------- */
    case 'GET vo.voices':
        json_out(el_voices());

    case 'GET vo.plan':
        // The lines that would be voiced, so the dialog can show what it'll cost before anything is made.
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $t = active_transcript($vid) ?? fail('This version has no transcript yet.', 404);
        $plan = vo_lines($vid, $t, ($in['scope'] ?? 'changes') === 'all' ? 'all' : 'changes', ($in['drafts'] ?? '1') !== '0');
        $speakers = array_values(array_unique(array_map(fn($l) => $l['speaker'], $plan)));
        json_out(['lines' => $plan, 'speakers' => $speakers, 'characters' => array_sum(array_map(fn($l) => mb_strlen($l['text']), $plan)),
            'transcript_id' => (int)$t['id'], 'formats' => array_map(fn($f) => $f['label'], VO_FORMATS)]);

    case 'POST vo.line':
        // Voice one line (or reuse the take if this exact line was already made with these settings).
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $t = active_transcript($vid) ?? fail('This version has no transcript yet.', 404);
        $plan = vo_lines($vid, $t, ($in['scope'] ?? 'changes') === 'all' ? 'all' : 'changes', ($in['drafts'] ?? '1') !== '0');
        $key = (string)($in['key'] ?? '');
        $at = null;
        foreach ($plan as $n => $l) {
            if ($l['key'] === $key) {
                $at = $n;
            }
        }
        if ($at === null) {
            fail('That line has changed since the list was made. Close the dialog and open it again.', 409);
        }
        $line = $plan[$at];
        $set = vo_settings($in);
        $voices = is_array($in['voices'] ?? null) ? $in['voices'] : [];
        $take = vo_make_take($vid, $line, vo_voice_for($voices, $line['speaker']), $set,
            ['previous' => $plan[$at - 1]['text'] ?? '', 'next' => $plan[$at + 1]['text'] ?? ''], $user);
        json_out(['ok' => true, 'key' => $key] + $take);

    case 'GET vo.take':
        // Listen to a take.
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $hash = (string)($in['hash'] ?? '');
        $format = isset(VO_FORMATS[$in['format'] ?? '']) ? $in['format'] : 'wav';
        $file = preg_match('/^[0-9a-f]{32}$/', $hash) ? vo_file($vid, $hash, $format) : '';
        if ($file === '' || !is_file($file)) {
            fail('That take isn’t on the server any more.', 404);
        }
        header('Content-Type: ' . ($format === 'wav' ? 'audio/wav' : 'audio/mpeg'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        readfile($file);
        exit;

    case 'GET vo.export':
        // A zip of every take, named in script order, and the FCP7 XML that places them.
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $t = active_transcript($vid) ?? fail('This version has no transcript yet.', 404);
        $scope = ($in['scope'] ?? 'changes') === 'all' ? 'all' : 'changes';
        $plan = vo_lines($vid, $t, $scope, ($in['drafts'] ?? '1') !== '0');
        $set = vo_settings($in);
        $voices = json_decode((string)($in['voices'] ?? '{}'), true) ?: [];
        if (!class_exists('ZipArchive')) {
            fail('The PHP zip extension is off. Turn it on in cPanel → Select PHP Version → Extensions.', 500);
        }
        $ext = VO_FORMATS[$set['format']]['ext'];
        $clips = [];
        $missing = 0;
        foreach ($plan as $line) {
            $file = vo_file($vid, vo_hash($line['text'], vo_voice_for($voices, $line['speaker']), $set), $set['format']);
            if (!is_file($file)) {
                $missing++;
                continue;
            }
            $clips[] = [$line, vo_clip_name($line, frames_to_tc($line['start'] + (int)$v['tc_start'], (int)$v['tc_base']), $ext), vo_duration($file, $set['format']), $file];
        }
        if ($missing) {
            fail("$missing line" . ($missing === 1 ? ' hasn’t' : 's haven’t') . ' been voiced with these settings yet. Press Make the voice-over first.', 409);
        }
        if (!$clips) {
            fail('There are no lines to voice. Approve some script changes first, or choose the whole script.');
        }
        $film = $pdo->query('SELECT name FROM films WHERE id = ' . (int)$v['film_id'])->fetchColumn();
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $film . '_' . $v['label']) . '_VO' . ($scope === 'changes' ? '_changes' : '');
        $xml = vo_fcp7_xml("$film · {$v['label']} · new VO" . ($scope === 'changes' ? ' (changes)' : ''), $v, array_map(fn($c) => array_slice($c, 0, 3), $clips), $set['format'], (string)($in['folder'] ?? ''));
        $list = "$film · {$v['label']} · new voice-over\nMade " . gmdate('Y-m-d H:i') . " UTC with ElevenLabs ({$set['model']}).\n\n";
        foreach ($clips as [$line, $name, $secs]) {
            $list .= sprintf("%s  %s  %5.1fs  %s%s\n    %s\n\n", $name, frames_to_tc($line['start'] + (int)$v['tc_start'], (int)$v['tc_base']), $secs,
                $line['speaker'] !== '' ? $line['speaker'] : '', $line['kind'] === 'new' ? ' (new line)' : ($line['kind'] === 'rewritten' ? ' (rewritten)' : ''), $line['text']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pnvo');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString("$base/$base.xml", $xml);
        $zip->addFromString("$base/lines.txt", $list);
        foreach ($clips as [$line, $name, $secs, $file]) {
            $zip->addFile($file, "$base/clips/$name");
            $zip->setCompressionName("$base/clips/$name", ZipArchive::CM_STORE);   // audio doesn't compress; store it
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $base . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;

    /* ---------------- Tracks (note categories) ---------------- */

    case 'POST category.save':
        $filmId = int_in($in, 'film_id');
        $cats = film_categories($filmId);
        $key = strtoupper(trim((string)($in['key'] ?? '')));
        if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/', $key)) {
            fail('Use 1 to 8 letters or digits for the short code, starting with a letter (like M, SFX or CHART).');
        }
        $label = str_in($in, 'label', 40);
        $color = in_array($in['color'] ?? '', CATEGORY_COLORS, true) ? $in['color'] : 'cat-gray';
        $kind = ($in['kind'] ?? '') === 'narration' ? 'narration' : 'picture';
        $id = isset($in['id']) && is_numeric($in['id']) ? (int)$in['id'] : null;
        foreach ($cats as $c) {
            if ($c['key'] === $key && $c['id'] !== $id) {
                fail("The film already has a track with the code $key.");
            }
        }
        $pdo->beginTransaction();
        if ($id) {
            $old = array_values(array_filter($cats, fn($c) => $c['id'] === $id))[0] ?? fail('That track no longer exists.', 404);
            $pdo->prepare('UPDATE categories SET key = ?, label = ?, color = ?, kind = ? WHERE id = ? AND film_id = ?')->execute([$key, $label, $color, $kind, $id, $filmId]);
            if ($old['key'] !== $key) {
                // Notes keep their track when its code changes.
                $notes = $pdo->prepare("SELECT id, tag FROM notes WHERE film_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ?)");
                $notes->execute([$filmId, $old['key'], $old['key'] . '-%', '%-' . $old['key']]);
                $upd = $pdo->prepare('UPDATE notes SET tag = ? WHERE id = ?');
                foreach ($notes as $n) {
                    $parts = array_map(fn($p) => $p === $old['key'] ? $key : $p, explode('-', $n['tag']));
                    $upd->execute([implode('-', $parts), $n['id']]);
                }
            }
        } else {
            $sort = $cats ? max(array_column($cats, 'sort')) + 1 : 0;
            $pdo->prepare('INSERT INTO categories (film_id, key, label, color, kind, sort) VALUES (?,?,?,?,?,?)')->execute([$filmId, $key, $label, $color, $kind, $sort]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        json_out(['id' => $id, 'categories' => film_categories($filmId)]);

    case 'GET category.detect':
        // Codes the film's untagged notes start with.
        $filmId = int_in($in, 'film_id');
        $st = $pdo->prepare("SELECT text FROM notes WHERE film_id = ? AND (tag IS NULL OR tag = '')");
        $st->execute([$filmId]);
        $codes = detect_codes($st->fetchAll(PDO::FETCH_COLUMN), film_keys($filmId));
        $used = [];
        foreach (film_categories($filmId) as $c) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE film_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ? OR tag LIKE ?)");
            $n->execute([$filmId, $c['key'], $c['key'] . '-%', '%-' . $c['key'], '%-' . $c['key'] . '-%']);
            $used[$c['key']] = (int)$n->fetchColumn();
        }
        json_out(['codes' => array_map(fn($c, $n) => ['code' => (string)$c, 'count' => $n, 'label' => code_label((string)$c)], array_keys($codes), $codes),
            'track_notes' => $used]);

    case 'POST category.adopt':
        // Create a track for each chosen code, put the matching untagged notes on it, and (optionally) remove the
        // starter tracks E, N and A when no note uses them.
        $filmId = int_in($in, 'film_id');
        $want = is_array($in['codes'] ?? null) ? $in['codes'] : [];
        $cats = film_categories($filmId);
        $have = array_column($cats, 'key');
        $usedColors = array_column($cats, 'color');
        $pdo->beginTransaction();
        $sort = $cats ? max(array_column($cats, 'sort')) + 1 : 0;
        $created = [];
        foreach ($want as $w) {
            $code = strtoupper(trim((string)($w['code'] ?? '')));
            if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/', $code) || in_array($code, $have, true)) {
                continue;
            }
            $free = array_values(array_diff(CATEGORY_COLORS, $usedColors));
            $color = $free[0] ?? CATEGORY_COLORS[count($created) % count(CATEGORY_COLORS)];
            $usedColors[] = $color;
            $label = mb_substr(trim((string)($w['label'] ?? '')) ?: code_label($code), 0, 40);
            $pdo->prepare('INSERT INTO categories (film_id, key, label, color, kind, sort) VALUES (?,?,?,?,?,?)')->execute([$filmId, $code, $label, $color, 'picture', $sort++]);
            $created[] = $code;
            $have[] = $code;
        }
        $tagged = 0;
        if ($created) {
            $st = $pdo->prepare("SELECT id, text FROM notes WHERE film_id = ? AND (tag IS NULL OR tag = '')");
            $st->execute([$filmId]);
            $upd = $pdo->prepare('UPDATE notes SET tag = ?, text = ? WHERE id = ?');
            foreach ($st->fetchAll() as $n) {
                [$tag, $text] = split_tag((string)$n['text'], $created);
                if ($tag !== '') {
                    $upd->execute([$tag, $text, $n['id']]);
                    $tagged++;
                }
            }
        }
        $dropped = [];
        if (!empty($in['drop_defaults'])) {
            foreach (DEFAULT_CATEGORIES as [$k]) {
                $n = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE film_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ? OR tag LIKE ?)");
                $n->execute([$filmId, $k, $k . '-%', '%-' . $k, '%-' . $k . '-%']);
                if ((int)$n->fetchColumn() === 0 && count($have) > 1) {
                    $pdo->prepare('DELETE FROM categories WHERE film_id = ? AND key = ?')->execute([$filmId, $k]);
                    $dropped[] = $k;
                }
            }
        }
        $pdo->commit();
        json_out(['ok' => true, 'created' => $created, 'tagged' => $tagged, 'dropped' => $dropped, 'categories' => film_categories($filmId)]);

    case 'POST category.delete':
        $filmId = int_in($in, 'film_id');
        $id = int_in($in, 'id');
        $cats = film_categories($filmId);
        $cat = array_values(array_filter($cats, fn($c) => $c['id'] === $id))[0] ?? fail('That track no longer exists.', 404);
        $moveTo = clean_tag((string)($in['move_to'] ?? ''), array_column($cats, 'key'));
        if ($moveTo === $cat['key']) {
            fail('Choose a different track to move the notes to.');
        }
        $pdo->beginTransaction();
        $notes = $pdo->prepare("SELECT id, tag FROM notes WHERE film_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ?)");
        $notes->execute([$filmId, $cat['key'], $cat['key'] . '-%', '%-' . $cat['key']]);
        $upd = $pdo->prepare('UPDATE notes SET tag = ? WHERE id = ?');
        $moved = 0;
        foreach ($notes as $n) {
            $parts = explode('-', $n['tag']);
            $first = $parts[0] === $cat['key'];
            $parts = array_values(array_filter($parts, fn($p) => $p !== $cat['key']));
            if ($first) {
                array_unshift($parts, $moveTo);
            }
            $upd->execute([implode('-', array_values(array_unique(array_filter($parts, fn($p) => $p !== '')))), $n['id']]);
            $moved++;
        }
        $pdo->prepare('DELETE FROM categories WHERE id = ? AND film_id = ?')->execute([$id, $filmId]);
        $pdo->commit();
        json_out(['ok' => true, 'moved' => $moved, 'categories' => film_categories($filmId)]);

    case 'POST category.order':
        $filmId = int_in($in, 'film_id');
        $ids = array_map('intval', (array)($in['ids'] ?? []));
        $upd = $pdo->prepare('UPDATE categories SET sort = ? WHERE id = ? AND film_id = ?');
        foreach ($ids as $i => $cid) {
            $upd->execute([$i, $cid, $filmId]);
        }
        json_out(['ok' => true, 'categories' => film_categories($filmId)]);

    /* ---------------- Settings: connections ---------------- */

    case 'GET settings':
        $c = conn_reload();
        $tok = fio_tokens();
        json_out([
            'frameio' => [
                'mode' => fio_mode(), 'redirect_uri' => fio_redirect_uri(),
                'redirect_pattern' => str_replace('.', '\\.', fio_redirect_uri()),
                'signed_in' => fio_mode() === 'user' && !empty($tok['refresh_token']) && empty($tok['expired']),
                'expired' => !empty($tok['expired']),
                'connected_by' => $tok['connected_by'] ?? null, 'adobe_email' => $tok['adobe_email'] ?? null,
                'connected_at' => $tok['connected_at'] ?? null, 'refreshed_at' => $tok['refreshed_at'] ?? null,
                'client_id' => $c['fio_client_id'] ?? '', 'secret' => mask_secret($c['fio_client_secret'] ?? null),
                'account_id' => $c['fio_account_id'] ?? '', 'account_name' => $c['fio_account_name'] ?? '',
                'accounts' => $c['fio_accounts'] ?? [], 'checked_at' => $c['fio_checked_at'] ?? null,
            ],
            'elevenlabs' => [
                'key' => mask_secret($c['el_key'] ?? null), 'tts_key' => mask_secret($c['el_tts_key'] ?? null), 'model' => $c['el_model'] ?? 'scribe_v2',
                'language' => $c['el_language'] ?? '', 'diarize' => $c['el_diarize'] ?? true,
            ],
            'mail' => [
                'host' => $c['smtp_host'] ?? '', 'port' => (int)($c['smtp_port'] ?? 465), 'security' => $c['smtp_security'] ?? 'ssl',
                'user' => $c['smtp_user'] ?? '', 'pass' => !empty($c['smtp_pass']), 'from' => $c['smtp_from'] ?? '',
                'from_name' => $c['smtp_from_name'] ?? '', 'configured' => mail_configured(), 'checked_at' => $c['smtp_checked_at'] ?? null,
                'domain' => preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''), 'me' => $me['email'],
            ],
        ]);

    case 'POST settings.mail':
        $from = norm_email((string)($in['from'] ?? ''));
        $host = strtolower(trim((string)($in['host'] ?? '')));
        $security = ($in['security'] ?? 'ssl') === 'starttls' ? 'starttls' : 'ssl';
        $port = (int)($in['port'] ?? 0) ?: ($security === 'ssl' ? 465 : 587);
        $userName = trim((string)($in['user'] ?? '')) ?: $from;
        if (!valid_email($from)) {
            fail('Enter the address to send from, like hello@previouslynoted.com.');
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
            fail('Enter the mail server’s name, like mail.previouslynoted.com.');
        }
        if ($port < 1 || $port > 65535) {
            fail('That port number isn’t right. It’s usually 465 (SSL) or 587 (STARTTLS).');
        }
        $old = conn_reload();
        $pass = (string)($in['pass'] ?? '');
        if ($pass === '') {
            $pass = (string)($old['smtp_pass'] ?? '');
        }
        if ($pass === '') {
            fail('Enter the mailbox’s password.');
        }
        $new = ['host' => $host, 'port' => $port, 'security' => $security, 'user' => $userName, 'pass' => $pass, 'from' => $from,
            'from_name' => mb_substr(trim((string)($in['from_name'] ?? '')), 0, 60), 'allow_plain' => !empty($old['smtp_allow_plain']),
            'ca_file' => (string)($old['smtp_ca_file'] ?? '')];
        // Send a test message to the admin before saving, so a wrong setting never replaces a working one.
        try {
            mail_send([$me['email'], $me['name']], 'Email from ' . (app_config()['app_name'] ?? 'Previously Noted') . ' is working',
                "This is a test from Previously Noted. Invites and password resets will now be sent from $from.\n\nIf this landed in spam, see the README’s Email section to set up SPF, DKIM and DMARC for the domain.\n",
                mail_html('Email is working', 'Invites and password resets will now be sent from <b>' . h($from) . '</b>.', 'Open the app', app_base_url(),
                    'If this landed in spam, mark it as not spam, and check the domain’s email authentication (see the README’s Email section).'),
                null, $new);
        } catch (MailError $e) {
            fail($e->getMessage() . ' Nothing was saved.');
        }
        conn_save(['smtp_host' => $host, 'smtp_port' => $port, 'smtp_security' => $security, 'smtp_user' => $userName, 'smtp_pass' => $pass,
            'smtp_from' => $from, 'smtp_from_name' => $new['from_name'] ?: null, 'smtp_checked_at' => now_iso()]);
        json_out(['ok' => true, 'sent_to' => $me['email']]);

    case 'POST settings.mail.test':
        try {
            mail_send([$me['email'], $me['name']], 'Test from ' . (app_config()['app_name'] ?? 'Previously Noted'),
                "This is a test from Previously Noted. Email is working.\n", null);
        } catch (MailError $e) {
            fail($e->getMessage());
        }
        conn_save(['smtp_checked_at' => now_iso()]);
        json_out(['ok' => true, 'sent_to' => $me['email']]);

    case 'POST settings.mail.clear':
        conn_save(['smtp_host' => null, 'smtp_port' => null, 'smtp_security' => null, 'smtp_user' => null, 'smtp_pass' => null,
            'smtp_from' => null, 'smtp_from_name' => null, 'smtp_checked_at' => null]);
        json_out(['ok' => true]);

    case 'POST settings.frameio':
        $c = conn_reload();
        $id = trim((string)($in['client_id'] ?? ''));
        $secret = trim((string)($in['client_secret'] ?? ''));
        if ($id === '') {
            fail('Paste the client ID from the Adobe Developer Console.');
        }
        if ($secret === '' && empty($c['fio_client_secret'])) {
            fail('Paste the client secret from the Adobe Developer Console.');
        }
        $mode = ($in['mode'] ?? 'user') === 's2s' ? 's2s' : 'user';
        $changes = ['fio_client_id' => $id, 'fio_mode' => $mode];
        if ($secret !== '') {
            $changes['fio_client_secret'] = $secret;
        }
        if (($c['fio_client_id'] ?? '') !== $id || $secret !== '' || ($c['fio_mode'] ?? 'user') !== $mode) {
            @unlink(FIO_TOKEN_FILE);
        }
        conn_save($changes);
        if ($mode === 'user' && (empty(fio_tokens()['refresh_token']) || !empty(fio_tokens()['expired']))) {
            // Next step happens in the browser: oauth.php sends them to Adobe.
            json_out(['ok' => true, 'needs_signin' => true]);
        }
        fio_token($mode === 's2s');
        $accounts = fio_accounts();
        if (!$accounts) {
            fail('Connected to Adobe, but the service account can’t see any Frame.io account. Check the product profile assigned to the credential in the Admin Console.');
        }
        $want = (string)($in['account_id'] ?? ($c['fio_account_id'] ?? ''));
        $pick = null;
        foreach ($accounts as $a) {
            if ($a['id'] === $want) {
                $pick = $a;
            }
        }
        if (!$pick && count($accounts) === 1) {
            $pick = $accounts[0];
        }
        conn_save(['fio_accounts' => $accounts, 'fio_account_id' => $pick['id'] ?? null, 'fio_account_name' => $pick['name'] ?? null, 'fio_checked_at' => now_iso()]);
        json_out(['ok' => true, 'accounts' => $accounts, 'account_id' => $pick['id'] ?? null]);

    case 'POST settings.frameio.clear':
        conn_save(['fio_client_id' => null, 'fio_client_secret' => null, 'fio_account_id' => null, 'fio_account_name' => null, 'fio_accounts' => null, 'fio_checked_at' => null]);
        @unlink(FIO_TOKEN_FILE);
        json_out(['ok' => true]);

    case 'POST settings.elevenlabs':
        $key = trim((string)($in['key'] ?? ''));
        $model = (string)($in['model'] ?? 'scribe_v2');
        if (!preg_match('/^[a-z0-9_.-]{2,40}$/i', $model)) {
            fail('That model name doesn’t look right.');
        }
        $lang = strtolower(trim((string)($in['language'] ?? '')));
        if ($lang !== '' && !preg_match('/^[a-z]{2,3}$/', $lang)) {
            fail('Use a two- or three-letter language code like en or fr, or leave it empty to detect the language.');
        }
        if ($key === '' && !el_configured()) {
            fail('Paste the ElevenLabs API key.');
        }
        // An optional second key just for voice-over ('-' removes it).
        $tts = trim((string)($in['tts_key'] ?? ''));
        $ttsChange = $tts === '-' ? ['el_tts_key' => null] : ($tts !== '' ? ['el_tts_key' => $tts] : []);
        conn_save(array_filter(['el_key' => $key !== '' ? $key : null], fn($v) => $v !== null) + $ttsChange + [
            'el_model' => $model, 'el_language' => $lang !== '' ? $lang : null, 'el_diarize' => !empty($in['diarize']),
        ]);
        json_out(['ok' => true]);

    case 'POST settings.elevenlabs.clear':
        conn_save(['el_key' => null, 'el_tts_key' => null, 'el_model' => null, 'el_language' => null, 'el_diarize' => null]);
        json_out(['ok' => true]);

    case 'POST film.update':
        $fid = int_in($in, 'film_id');
        $terms = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 49), preg_split('/[\r\n,]+/', (string)($in['keyterms'] ?? ''))))));
        $pdo->prepare('UPDATE films SET keyterms = ? WHERE id = ?')->execute([implode("\n", array_slice($terms, 0, 1000)), $fid]);
        json_out(['ok' => true, 'count' => count($terms)]);

    /* ---------------- Frame.io ---------------- */

    case 'POST fio.resolve':
        json_out(fio_resolve(str_in($in, 'link', 2000)));

    case 'GET fio.stack':
        $v = version_row(int_in($in, 'version_id'));
        if (!$v['fio_stack_id']) {
            json_out(['stack' => null]);
        }
        $stack = fio_stack($v['fio_stack_id'], $v['fio_account_id'] ?: null);
        $linked = $pdo->prepare('SELECT fio_file_id, label FROM versions WHERE film_id = ? AND fio_file_id IS NOT NULL');
        $linked->execute([(int)$v['film_id']]);
        $map = array_column($linked->fetchAll(), 'label', 'fio_file_id');
        foreach ($stack['files'] as &$f) {
            $f['linked_to'] = $map[$f['id']] ?? null;
        }
        unset($f);
        json_out(['stack' => $stack]);

    case 'POST version.link':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $fileId = strtolower((string)($in['file_id'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/', $fileId)) {
            fail('Pick a Frame.io file to link.');
        }
        $stackId = preg_match('/^[0-9a-f-]{36}$/i', (string)($in['stack_id'] ?? '')) ? strtolower($in['stack_id']) : null;
        $accId = preg_match('/^[A-Za-z0-9:@._-]{6,80}$/', (string)($in['account_id'] ?? '')) ? (string)$in['account_id'] : null;
        $pdo->prepare('UPDATE versions SET fio_file_id = ?, fio_stack_id = ?, fio_name = ?, fio_account_id = ? WHERE id = ?')
            ->execute([$fileId, $stackId, mb_substr((string)($in['name'] ?? ''), 0, 300), $accId, $vid]);
        json_out(['ok' => true]);

    case 'POST version.unlink':
        $vid = int_in($in, 'version_id');
        $pdo->prepare('UPDATE versions SET fio_file_id = NULL, fio_stack_id = NULL, fio_name = NULL, fio_synced_at = NULL, fio_account_id = NULL WHERE id = ?')->execute([$vid]);
        json_out(['ok' => true]);

    case 'POST version.sync':
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        if (!$v['fio_file_id']) {
            fail('Link this version to its Frame.io file first.');
        }
        @set_time_limit(120);
        $got = fio_comments($v['fio_file_id'], (int)$v['tc_base'], $v['fio_account_id'] ?: null);
        $stats = import_comments($vid, $got['comments'], $got['tc_base'], '', 'Frame.io sync');
        $pdo->prepare('UPDATE versions SET fio_synced_at = ? WHERE id = ?')->execute([now_iso(), $vid]);
        json_out($stats + ['total' => count($got['comments']), 'sample_timestamp' => $got['sample']]);

    /* ---------------- Sound-only versions (lib/media.php) ---------------- */

    case 'POST version.audio':
        // One chunk of a sound file, in order. The last one puts the file in place.
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $total = (int)($in['total'] ?? 0);
        $offset = (int)($in['offset'] ?? -1);
        $name = mb_substr(trim(basename(str_replace('\\', '/', (string)($in['name'] ?? '')))), 0, 200);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(AUDIO_TYPES[$ext])) {
            fail('Choose a sound file: WAV, MP3, M4A, AAC, AIFF, FLAC or OGG.');
        }
        if ($total <= 0 || $total > AUDIO_MAX_BYTES) {
            fail('That sound file is too large (2 GB at most). Export it as MP3 or a smaller WAV.');
        }
        $f = $_FILES['chunk'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            fail($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The server refused a piece of the upload as too large. Reload the page and try again.'
                : 'Part of the upload didn’t arrive. Try again.');
        }
        $part = media_dir() . "/$vid.part";
        $have = $offset === 0 ? 0 : (is_file($part) ? filesize($part) : 0);
        if ($offset !== $have) {
            fail('The upload got out of step. Start it again.', 409);
        }
        if ($have + (int)$f['size'] > $total) {
            fail('The upload is larger than the file. Start it again.', 409);
        }
        if ($offset === 0 && is_file($part)) {
            @unlink($part);
        }
        if (file_put_contents($part, fopen($f['tmp_name'], 'rb'), FILE_APPEND) === false) {
            fail('The server couldn’t save the upload. Check there is space left on the hosting account.', 500);
        }
        clearstatcache();
        $have = filesize($part);
        if ($have < $total) {
            json_out(['received' => $have]);
        }
        audio_remove_files_except($vid, $part);
        rename($part, media_dir() . "/$vid.$ext");
        $pdo->prepare('UPDATE versions SET audio_name = ?, audio_ext = ?, audio_size = ? WHERE id = ?')->execute([$name, $ext, $total, $vid]);
        json_out(['received' => $have, 'done' => true, 'audio_name' => $name, 'audio_size' => $total]);

    case 'POST version.audio.remove':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        audio_remove_files($vid);
        $pdo->prepare('UPDATE versions SET audio_name = NULL, audio_ext = NULL, audio_size = NULL WHERE id = ?')->execute([$vid]);
        json_out(['ok' => true]);

    case 'GET version.audio':
        $v = version_row(int_in($in, 'version_id'));
        $path = audio_path($v) ?? fail('This version has no sound file on the server.', 404);
        send_file_ranged($path, AUDIO_TYPES[$v['audio_ext']] ?? 'application/octet-stream');

    case 'GET version.media':
        $v = version_row(int_in($in, 'version_id'));
        if (!$v['fio_file_id']) {
            fail('This version isn’t linked to a Frame.io file.');
        }
        $q = in_array($in['quality'] ?? '', ['efficient', 'high', 'original'], true) ? $in['quality'] : 'efficient';
        json_out(fio_media($v['fio_file_id'], $q, $v['fio_account_id'] ?: null));

    /* ---------------- ElevenLabs transcription ---------------- */

    case 'POST transcribe.create':
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        if (!el_configured()) {
            fail('Add the ElevenLabs API key in Settings first.');
        }
        if (!$v['fio_file_id'] && !$v['video_url'] && !audio_path($v)) {
            fail('Transcription needs the cut on the server: link this version to its Frame.io file, give it a video link, or upload its sound.');
        }
        $busy = $pdo->prepare("SELECT id FROM jobs WHERE version_id = ? AND kind = 'transcribe' AND status IN ('queued','running') AND updated_at > ?");
        $busy->execute([$vid, gmdate('Y-m-d\TH:i:s\Z', time() - 45 * 60)]);
        if ($id = $busy->fetchColumn()) {
            json_out(['job_id' => (int)$id, 'already' => true]);
        }
        $pdo->prepare("INSERT INTO jobs (version_id, kind, status, created_by, created_at, updated_at) VALUES (?, 'transcribe', 'queued', ?, ?, ?)")
            ->execute([$vid, $user, now_iso(), now_iso()]);
        json_out(['job_id' => (int)$pdo->lastInsertId()]);

    case 'POST transcribe.run':
        $jid = int_in($in, 'job_id');
        $claim = $pdo->prepare("UPDATE jobs SET status = 'running', message = ?, updated_at = ? WHERE id = ? AND status = 'queued'");
        $claim->execute(['Getting the video link…', now_iso(), $jid]);
        if ($claim->rowCount() !== 1) {
            json_out(['ok' => true, 'already' => true]);
        }
        respond_then_continue(['ok' => true]);
        run_transcription($jid);
        exit;

    case 'GET transcribe.status':
        $vid = int_in($in, 'version_id');
        $st = $pdo->prepare("SELECT * FROM jobs WHERE version_id = ? AND kind = 'transcribe' ORDER BY id DESC LIMIT 1");
        $st->execute([$vid]);
        $job = $st->fetch();
        if ($job && in_array($job['status'], ['queued', 'running'], true) && strtotime($job['updated_at']) < time() - 45 * 60) {
            $pdo->prepare("UPDATE jobs SET status = 'error', message = ? WHERE id = ?")
                ->execute(['The server stopped the transcription before it finished (shared hosting may limit long requests). Try again, or upload a transcript file instead.', $job['id']]);
            $st->execute([$vid]);
            $job = $st->fetch();
        }
        json_out(['job' => $job ? ['id' => (int)$job['id'], 'status' => $job['status'], 'message' => $job['message'],
            'result' => $job['result'] ? json_decode($job['result'], true) : null, 'created_at' => $job['created_at'], 'created_by' => $job['created_by']] : null]);

    case 'POST transcript.speaker':
        $tid = int_in($in, 'transcript_id');
        $from = str_in($in, 'from', 60);
        $to = str_in($in, 'to', 60);
        $u = $pdo->prepare('UPDATE segments SET speaker = ? WHERE transcript_id = ? AND speaker = ?');
        $u->execute([$to, $tid, $from]);
        json_out(['ok' => true, 'changed' => $u->rowCount()]);

    case 'GET export':
        $filmId = int_in($in, 'film_id');
        $film = $pdo->prepare('SELECT name FROM films WHERE id = ?');
        $film->execute([$filmId]);
        $filmName = $film->fetchColumn() ?: fail('That film no longer exists.', 404);
        $vs = $pdo->prepare('SELECT id, label, tc_base, tc_start FROM versions WHERE film_id = ? ORDER BY id');
        $vs->execute([$filmId]);
        $versions = $vs->fetchAll();
        $ns = $pdo->prepare(
            'SELECT n.*, ov.label AS origin_label FROM notes n LEFT JOIN versions ov ON ov.id = n.origin_version_id
             WHERE n.film_id = ? ORDER BY n.id'
        );
        $ns->execute([$filmId]);
        $pos = [];
        $ps = $pdo->prepare('SELECT p.* FROM positions p JOIN versions v ON v.id = p.version_id WHERE v.film_id = ?');
        $ps->execute([$filmId]);
        foreach ($ps as $p) {
            $pos[$p['note_id']][$p['version_id']] = $p;
        }
        $last = $pdo->prepare('SELECT e.status, e.comment, e.by_name, e.at, v.label FROM events e LEFT JOIN versions v ON v.id = e.version_id WHERE e.note_id = ? ORDER BY e.id DESC LIMIT 1');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $filmName) . '_notes.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        $head = ['Note ID', 'Raised in', 'Author', 'Tag', 'Note', 'Status', 'Status set in', 'Status by', 'Status comment', 'Status date'];
        foreach ($versions as $v) {
            $head[] = 'TC in ' . $v['label'];
        }
        fputcsv($out, $head, ',', '"', '');
        foreach ($ns as $n) {
            $last->execute([$n['id']]);
            $e = $last->fetch() ?: ['status' => 'open', 'comment' => '', 'by_name' => '', 'at' => '', 'label' => ''];
            $row = [$n['id'], $n['origin_label'], $n['author'], $n['tag'], $n['text'], $e['status'], $e['label'], $e['by_name'], $e['comment'], $e['at']];
            foreach ($versions as $v) {
                $p = $pos[$n['id']][$v['id']] ?? null;
                $row[] = $p ? frames_to_tc((int)$p['frame'] + (int)$v['tc_start'], (int)$v['tc_base'])
                    . ($p['end_frame'] !== null ? ' to ' . frames_to_tc((int)$p['end_frame'] + (int)$v['tc_start'], (int)$v['tc_base']) : '')
                    . ($p['confirmed'] ? '' : ' (unconfirmed)') : '';
            }
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);
        exit;

    default:
        fail('Unknown request.', 404);
}

/** Send the answer now and keep working: the transcription can take minutes. */
function respond_then_continue(array $data): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    $body = json_encode($data);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}

function run_transcription(int $jobId): void
{
    $pdo = db();
    $set = function (string $status, string $message, ?array $result = null) use ($pdo, $jobId) {
        $pdo->prepare('UPDATE jobs SET status = ?, message = ?, result = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $message, $result ? json_encode($result) : null, now_iso(), $jobId]);
    };
    try {
        $job = $pdo->query('SELECT * FROM jobs WHERE id = ' . $jobId)->fetch();
        $v = version_row((int)$job['version_id']);
        $film = $pdo->query('SELECT keyterms FROM films WHERE id = ' . (int)$v['film_id'])->fetch();
        $terms = array_values(array_filter(explode("\n", (string)($film['keyterms'] ?? ''))));
        // An uploaded sound file goes to ElevenLabs itself; otherwise ElevenLabs fetches the video from its link.
        $audio = audio_path($v);
        $source = $audio ? '' : ($v['fio_file_id'] ? fio_media($v['fio_file_id'], 'efficient', $v['fio_account_id'] ?: null)['url'] : $v['video_url']);
        $set('running', $audio ? 'Sending the sound to ElevenLabs…' : 'ElevenLabs is transcribing the cut…');
        $res = el_transcribe($source, $terms, $audio);
        if (!$res['words']) {
            throw new ConnectError('ElevenLabs returned no words. Check the file has audio.');
        }
        $segs = el_words_to_segments($res['words'], (float)$v['rate']);
        $tid = store_transcript((int)$v['id'], ['format' => 'elevenlabs', 'offset' => 0, 'segments' => $segs],
            'ElevenLabs ' . ($res['language'] ? strtoupper((string)$res['language']) . ' ' : '') . 'transcript' . ($terms ? ' (' . count($terms) . ' key terms)' : ''), (string)$job['created_by']);
        $placement = place_around((int)$v['id']);
        $set('done', count($segs) . ' lines transcribed.', ['transcript_id' => $tid, 'segments' => count($segs), 'placement' => $placement]);
    } catch (Throwable $e) {
        error_log('[previouslynoted] transcription: ' . $e->getMessage());
        $set('error', $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'The transcription failed on the server. Details are in the PHP error log.');
    }
}

<?php
declare(strict_types=1);

/*
 * People and roles.
 *
 *   admin     everything, including people, Settings and connections, deleting versions
 *   editor    notes, versions, imports, edits, transcripts, tracks, script approval
 *   reviewer  add notes, reply, suggest script lines as drafts
 *   viewer    read only
 *
 * Invites and password resets are one-time links an admin copies and sends.
 * Only a hash of each link's token is stored.
 */

const ROLES = ['viewer' => 1, 'reviewer' => 2, 'editor' => 3, 'admin' => 4];
const ROLE_LABELS = ['viewer' => 'Viewer', 'reviewer' => 'Reviewer', 'editor' => 'Editor', 'admin' => 'Admin'];
const INVITE_DAYS = 7;
const RESET_HOURS = 48;
const LOCK_AFTER = 5;
const LOCK_MINUTES = 10;

function users_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'reviewer',
    status TEXT NOT NULL DEFAULT 'invited',
    pass_hash TEXT,
    token_hash TEXT,
    token_kind TEXT,
    token_expires INTEGER,
    failed INTEGER NOT NULL DEFAULT 0,
    locked_until INTEGER NOT NULL DEFAULT 0,
    epoch INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    last_login TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email ON users(email);
SQL);
}

function norm_email(string $e): string
{
    return strtolower(trim($e));
}

function valid_email(string $e): bool
{
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 200;
}

function password_problem(string $pw, string $again): ?string
{
    if (strlen($pw) < 10) {
        return 'Use at least 10 characters for the password.';
    }
    if ($pw !== $again) {
        return 'The two passwords don’t match.';
    }
    return null;
}

function user_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
}

function user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([norm_email($email)]);
    return $st->fetch() ?: null;
}

function create_user(string $name, string $email, string $role, ?string $password): int
{
    if (!isset(ROLES[$role])) {
        throw new RuntimeException('Unknown role.');
    }
    if (!valid_email($email)) {
        throw new RuntimeException('That email address doesn’t look right.');
    }
    if (user_by_email($email)) {
        throw new RuntimeException('Someone with that email address is already on the list.');
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 60) {
        throw new RuntimeException('Enter a name (up to 60 characters).');
    }
    db()->prepare('INSERT INTO users (email, name, role, status, pass_hash, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([norm_email($email), $name, $role, $password ? 'active' : 'invited', $password ? password_hash($password, PASSWORD_DEFAULT) : null, now_iso()]);
    return (int)db()->lastInsertId();
}

/** A one-time link for an invite or a password reset. Returns the raw token (shown once). */
function issue_token(int $userId, string $kind): string
{
    $token = bin2hex(random_bytes(24));
    $ttl = $kind === 'invite' ? INVITE_DAYS * 86400 : RESET_HOURS * 3600;
    db()->prepare('UPDATE users SET token_hash = ?, token_kind = ?, token_expires = ? WHERE id = ?')
        ->execute([hash('sha256', $token), $kind, time() + $ttl, $userId]);
    return $token;
}

function user_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE token_hash = ? AND token_expires > ? AND status <> ?');
    $st->execute([hash('sha256', $token), time(), 'disabled']);
    return $st->fetch() ?: null;
}

/** Set a password and sign out every other session of this person. */
function set_password(int $userId, string $password): void
{
    db()->prepare("UPDATE users SET pass_hash = ?, status = CASE WHEN status = 'invited' THEN 'active' ELSE status END,
        token_hash = NULL, token_kind = NULL, token_expires = NULL, failed = 0, locked_until = 0, epoch = epoch + 1 WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
}

/** Check an email and password. Returns the user, or a message saying why not. */
function attempt_login(string $email, string $password): array|string
{
    $u = user_by_email($email);
    if (!$u || !$u['pass_hash']) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // even out timing
        return 'That email and password don’t match an account.';
    }
    if ($u['status'] === 'disabled') {
        return 'This account has been turned off. Ask an admin.';
    }
    if ((int)$u['locked_until'] > time()) {
        $min = (int)ceil(((int)$u['locked_until'] - time()) / 60);
        return "Too many wrong passwords. Try again in $min minute" . ($min === 1 ? '' : 's') . ', or ask an admin for a reset link.';
    }
    if (!password_verify($password, $u['pass_hash'])) {
        $failed = (int)$u['failed'] + 1;
        $lock = $failed >= LOCK_AFTER ? time() + LOCK_MINUTES * 60 : 0;
        db()->prepare('UPDATE users SET failed = ?, locked_until = ? WHERE id = ?')->execute([$lock ? 0 : $failed, $lock, $u['id']]);
        return 'That email and password don’t match an account.';
    }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET failed = 0, locked_until = 0, last_login = ? WHERE id = ?')->execute([now_iso(), $u['id']]);
    return $u;
}

function sign_in_session(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['epoch'] = (int)$u['epoch'];
    unset($_SESSION['user']);
}

/** The signed-in person, checked against the database on every request. */
function current_account(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $u = user_by_id((int)$_SESSION['uid']);
    if (!$u || $u['status'] !== 'active' || (int)$u['epoch'] !== (int)($_SESSION['epoch'] ?? 0)) {
        unset($_SESSION['uid'], $_SESSION['epoch']);
        return null;
    }
    return $cache = $u;
}

function role_at_least(?array $u, string $role): bool
{
    return $u && (ROLES[$u['role']] ?? 0) >= ROLES[$role];
}

function active_admins(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
}

function app_base_url(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function user_public(array $u): array
{
    return [
        'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status'],
        'last_login' => $u['last_login'], 'created_at' => $u['created_at'],
        'link_pending' => $u['token_hash'] && (int)$u['token_expires'] > time() ? $u['token_kind'] : null,
        'locked' => (int)$u['locked_until'] > time(),
    ];
}

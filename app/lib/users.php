<?php
declare(strict_types=1);

/*
 * People and roles.
 *
 * People are platform-wide: one email and password, and a place in one or more companies (lib/tenancy.php).
 * Roles in a production:
 *
 *   admin     everything an editor can, plus deleting versions and giving people access to the production
 *   editor    notes, versions, imports, edits, transcripts, tracks, script approval
 *   reviewer  add notes, reply, suggest script lines as drafts
 *   viewer    read only
 *
 * Invites are one-time links on the membership; password resets are one-time links on the person.
 * Only a hash of each link's token is stored.
 */

const ROLES = ['viewer' => 1, 'reviewer' => 2, 'editor' => 3, 'admin' => 4];
const ROLE_LABELS = ['viewer' => 'Viewer', 'reviewer' => 'Reviewer', 'editor' => 'Editor', 'admin' => 'Admin'];
const INVITE_DAYS = 7;
const RESET_HOURS = 48;
const LOCK_AFTER = 5;
const LOCK_MINUTES = 10;

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

/** A new person. Without a password they can't sign in until they accept an invite. */
function create_user(string $name, string $email, ?string $password, ?string $platformRole = null): int
{
    if (!valid_email(norm_email($email))) {
        throw new RuntimeException('That email address doesn’t look right.');
    }
    if (user_by_email($email)) {
        throw new RuntimeException('Someone with that email address already has an account.');
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 60) {
        throw new RuntimeException('Enter a name (up to 60 characters).');
    }
    if ($platformRole !== null && !in_array($platformRole, PLATFORM_ROLES, true)) {
        throw new RuntimeException('Unknown platform role.');
    }
    db()->prepare("INSERT INTO users (email, name, platform_role, status, pass_hash, created_at) VALUES (?,?,?,'active',?,?)")
        ->execute([norm_email($email), $name, $platformRole, $password ? password_hash($password, PASSWORD_DEFAULT) : null, now_iso()]);
    return (int)db()->lastInsertId();
}

/** A one-time password reset link. Returns the raw token (shown once). */
function issue_token(int $userId, string $kind = 'reset'): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE users SET token_hash = ?, token_kind = ?, token_expires = ? WHERE id = ?')
        ->execute([hash('sha256', $token), 'reset', time() + RESET_HOURS * 3600, $userId]);
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
    db()->prepare("UPDATE users SET pass_hash = ?,
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
        return 'This account has been turned off. Ask Previously Noted support.';
    }
    if ((int)$u['locked_until'] > time()) {
        $min = (int)ceil(((int)$u['locked_until'] - time()) / 60);
        return "Too many wrong passwords. Try again in $min minute" . ($min === 1 ? '' : 's') . ', or use Forgot your password.';
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
    unset($_SESSION['user'], $_SESSION['as']);
}

/**
 * The person actually signed in, checked against the database on every request.
 */
function real_account(): ?array
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
    if (!$u || $u['status'] !== 'active' || !$u['pass_hash'] || (int)$u['epoch'] !== (int)($_SESSION['epoch'] ?? 0)) {
        unset($_SESSION['uid'], $_SESSION['epoch'], $_SESSION['as']);
        return null;
    }
    return $cache = $u;
}

/** The person this request acts as: the one signed in, or the one staff are impersonating. */
function current_account(): ?array
{
    $real = real_account();
    if (!$real) {
        return null;
    }
    if (!empty($_SESSION['as']) && is_platform($real)) {
        $as = user_by_id((int)$_SESSION['as']);
        if ($as) {
            return $as;
        }
    }
    return $real;
}

/** Does a production role reach the one needed? */
function role_at_least(?string $have, string $need): bool
{
    return $have !== null && (ROLES[$have] ?? 0) >= ROLES[$need];
}

function app_base_url(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function user_public(array $u): array
{
    return [
        'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'status' => $u['status'],
        'platform_role' => $u['platform_role'] ?? null, 'has_password' => !empty($u['pass_hash']),
        'last_login' => $u['last_login'], 'created_at' => $u['created_at'],
        'locked' => (int)$u['locked_until'] > time(),
    ];
}

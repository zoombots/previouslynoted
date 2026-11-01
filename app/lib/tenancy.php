<?php
declare(strict_types=1);

/*
 * Companies, productions and who may do what in them (docs/multitenant-rbac.md §5–§7).
 *
 * The request context (Ctx) is set once by the gate in api.php (or by index.php / oauth.php) and every lookup of
 * production data below is scoped to Ctx::$ws. Never query films, versions or notes without it.
 */

const ORG_ROLES = ['member' => 1, 'admin' => 2, 'owner' => 3];
const ORG_ROLE_LABELS = ['owner' => 'Owner', 'admin' => 'Company admin', 'member' => 'Member'];
const PLATFORM_ROLES = ['support', 'platform_admin'];

final class Ctx
{
    /** The person the request acts as (the impersonated person while impersonating). */
    public static ?array $user = null;
    /** The person actually signed in. Same as $user unless impersonating. */
    public static ?array $real = null;
    public static ?array $org = null;
    public static ?array $ws = null;
    /** Role in the production: viewer / reviewer / editor / admin. */
    public static ?string $role = null;

    public static function wsId(): int
    {
        if (!self::$ws) {
            throw new LogicException('No production in this request.');
        }
        return (int)self::$ws['id'];
    }

    public static function orgId(): int
    {
        if (!self::$org) {
            throw new LogicException('No company in this request.');
        }
        return (int)self::$org['id'];
    }

    public static function impersonating(): bool
    {
        return self::$real && self::$user && (int)self::$real['id'] !== (int)self::$user['id'];
    }

    public static function set(array $org, ?array $ws, ?string $role): void
    {
        self::$org = $org;
        self::$ws = $ws;
        self::$role = $role;
    }
}

/* ---------------- lookups ---------------- */

function org_row(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM organizations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function ws_row(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM workspaces WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function membership_of(int $orgId, int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM memberships WHERE org_id = ? AND user_id = ?');
    $st->execute([$orgId, $userId]);
    return $st->fetch() ?: null;
}

function is_platform(?array $u, string $role = 'support'): bool
{
    if (!$u || !in_array($u['platform_role'] ?? null, PLATFORM_ROLES, true)) {
        return false;
    }
    return $role === 'support' || $u['platform_role'] === 'platform_admin';
}

/** The person's company role when their membership is active, else null. */
function active_org_role(array $u, int $orgId): ?string
{
    $m = membership_of($orgId, (int)$u['id']);
    return $m && $m['status'] === 'active' ? $m['org_role'] : null;
}

/**
 * The role in a production, composed as in §5.4. Null means no access at all, not even to see it.
 * $real is the person actually signed in, when they're impersonating $u.
 */
function ws_role(array $u, array $ws, array $org, ?array $real = null): ?string
{
    if ($org['status'] !== 'active' || (int)$ws['org_id'] !== (int)$org['id']) {
        return null;                                                       // 1. suspended company
    }
    $role = null;
    if (is_platform($u, 'platform_admin')) {
        $role = 'admin';                                                   // 2. platform admin
    } else {
        $m = membership_of((int)$org['id'], (int)$u['id']);
        if ($m && $m['status'] === 'active') {
            if (in_array($m['org_role'], ['owner', 'admin'], true)) {
                $role = 'admin';                                           // 3. company owner or admin
            } else {
                $st = db()->prepare('SELECT role FROM workspace_access WHERE workspace_id = ? AND user_id = ?');
                $st->execute([(int)$ws['id'], (int)$u['id']]);
                $r = $st->fetchColumn();
                $role = $r !== false && isset(ROLES[$r]) ? $r : null;      // 4. explicit access, or none
            }
        }
        if ($role === null && is_platform($u)) {
            $role = 'viewer';                                              // 5. support reads everything
        }
    }
    if ($role === null) {
        return null;
    }
    // An archived production, or support impersonating someone, is read-only.
    if ($ws['status'] === 'archived' || ($real && (int)$real['id'] !== (int)$u['id'] && !is_platform($real, 'platform_admin'))) {
        $role = 'viewer';
    }
    return $role;
}

/**
 * What the person may do at company level: owner, admin, member, or 'support' (staff, read-only), or null.
 * Platform admins count as owners. A suspended company is closed to everyone but staff.
 */
function org_power(array $u, array $org, ?array $real = null): ?string
{
    $imp = $real && (int)$real['id'] !== (int)$u['id'];
    if (is_platform($u, 'platform_admin')) {
        return $imp && !is_platform($real, 'platform_admin') ? 'support' : 'owner';
    }
    $r = $org['status'] === 'active' ? active_org_role($u, (int)$org['id']) : null;
    if ($r !== null && $imp && !is_platform($real, 'platform_admin')) {
        return 'support';
    }
    return $r ?? (is_platform($u) ? 'support' : null);
}

function org_power_at_least(?string $power, string $need): bool
{
    if ($power === 'support') {
        return false;
    }
    return $power !== null && ORG_ROLES[$power] >= ORG_ROLES[$need];
}

/** The productions this person can open (through their memberships), with their role in each. */
function user_workspaces(array $u, ?array $real = null): array
{
    $st = db()->prepare(
        "SELECT w.*, o.name AS org_name, o.status AS org_status, o.id AS oid
         FROM memberships m
         JOIN organizations o ON o.id = m.org_id
         JOIN workspaces w ON w.org_id = o.id
         WHERE m.user_id = ? AND m.status = 'active'
         ORDER BY o.name, w.status, w.name, w.id"
    );
    $st->execute([(int)$u['id']]);
    $out = [];
    foreach ($st->fetchAll() as $w) {
        $org = ['id' => $w['oid'], 'status' => $w['org_status']];
        $role = ws_role($u, $w, $org, $real);
        if ($role !== null) {
            $out[] = ['id' => (int)$w['id'], 'name' => $w['name'], 'status' => $w['status'], 'org_id' => (int)$w['org_id'],
                'org_name' => $w['org_name'], 'role' => $role];
        }
    }
    return $out;
}

/* ---------------- scoped production data ---------------- */

function scoped_film(int $id): array
{
    $st = db()->prepare('SELECT * FROM films WHERE id = ? AND workspace_id = ?');
    $st->execute([$id, Ctx::wsId()]);
    return $st->fetch() ?: fail('That film no longer exists.', 404);
}

function scoped_version(int $id): array
{
    $st = db()->prepare('SELECT * FROM versions WHERE id = ? AND workspace_id = ?');
    $st->execute([$id, Ctx::wsId()]);
    return $st->fetch() ?: fail('That version no longer exists.', 404);
}

function scoped_note(int $id): array
{
    $st = db()->prepare('SELECT * FROM notes WHERE id = ? AND workspace_id = ?');
    $st->execute([$id, Ctx::wsId()]);
    return $st->fetch() ?: fail('That note no longer exists.', 404);
}

/**
 * The production every film, version, note, transcript and job id in a request belongs to.
 * Returns null when the request names none. Any id that doesn't exist → 404; ids from two productions → 400.
 */
function resolve_request_workspace(array $in): ?int
{
    $pdo = db();
    $q = [
        'film' => $pdo->prepare('SELECT workspace_id FROM films WHERE id = ?'),
        'version' => $pdo->prepare('SELECT workspace_id FROM versions WHERE id = ?'),
        'note' => $pdo->prepare('SELECT workspace_id FROM notes WHERE id = ?'),
        'transcript' => $pdo->prepare('SELECT v.workspace_id FROM transcripts t JOIN versions v ON v.id = t.version_id WHERE t.id = ?'),
        'job' => $pdo->prepare('SELECT v.workspace_id FROM jobs j JOIN versions v ON v.id = j.version_id WHERE j.id = ?'),
    ];
    $ids = [];
    $one = function (string $kind, mixed $v) use (&$ids) {
        if (is_numeric($v) && (int)$v > 0) {
            $ids[] = [$kind, (int)$v];
        }
    };
    $one('film', $in['film_id'] ?? null);
    $one('version', $in['version_id'] ?? null);
    $one('version', $in['carry_from'] ?? null);
    $one('note', $in['note_id'] ?? null);
    $one('transcript', $in['transcript_id'] ?? null);
    $one('job', $in['job_id'] ?? null);
    foreach (is_array($in['note_ids'] ?? null) ? $in['note_ids'] : [] as $v) {
        $one('note', is_scalar($v) ? intval($v) : null);
    }
    foreach (is_array($in['results'] ?? null) ? $in['results'] : [] as $r) {
        $one('note', is_array($r) && is_scalar($r['note_id'] ?? null) ? (int)$r['note_id'] : null);
    }
    $found = [];
    foreach ($ids as [$kind, $id]) {
        $q[$kind]->execute([$id]);
        $w = $q[$kind]->fetchColumn();
        if ($w === false) {
            fail("That $kind no longer exists.", 404);
        }
        $found[(int)$w] = true;
    }
    if (count($found) > 1) {
        fail('That request mixes two productions. Reload the page and try again.', 400);
    }
    return $found ? (int)array_key_first($found) : null;
}

/* ---------------- companies, productions, invites ---------------- */

function org_slug(string $name): string
{
    $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-') ?: 'company';
    $base = substr($base, 0, 60);
    $slug = $base;
    $st = db()->prepare('SELECT 1 FROM organizations WHERE slug = ?');
    for ($i = 2; ; $i++) {
        $st->execute([$slug]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = "$base-$i";
    }
}

function create_org(string $name): int
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new RuntimeException('Enter the company’s name (up to 120 characters).');
    }
    db()->prepare("INSERT INTO organizations (name, slug, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$name, org_slug($name), now_iso()]);
    return (int)db()->lastInsertId();
}

function create_workspace(int $orgId, string $name): int
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new RuntimeException('Enter the production’s name (up to 120 characters).');
    }
    db()->prepare("INSERT INTO workspaces (org_id, name, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$orgId, $name, now_iso()]);
    return (int)db()->lastInsertId();
}

/** Add someone to a company straight away (no invite), e.g. the owner of a new company. */
function add_member(int $orgId, int $userId, string $orgRole, ?int $by = null): void
{
    if (!isset(ORG_ROLES[$orgRole])) {
        throw new RuntimeException('Unknown company role.');
    }
    $m = membership_of($orgId, $userId);
    if ($m) {
        db()->prepare("UPDATE memberships SET org_role = ?, status = 'active', joined_at = COALESCE(joined_at, ?), token_hash = NULL, token_expires = NULL WHERE id = ?")
            ->execute([$orgRole, now_iso(), $m['id']]);
    } else {
        db()->prepare("INSERT INTO memberships (org_id, user_id, org_role, status, invited_by, invited_at, joined_at) VALUES (?,?,?,'active',?,?,?)")
            ->execute([$orgId, $userId, $orgRole, $by, now_iso(), now_iso()]);
    }
}

function set_ws_access(int $wsId, int $userId, ?string $role): void
{
    if ($role === null || $role === '') {
        db()->prepare('DELETE FROM workspace_access WHERE workspace_id = ? AND user_id = ?')->execute([$wsId, $userId]);
        return;
    }
    if (!isset(ROLES[$role])) {
        throw new RuntimeException('Unknown role.');
    }
    db()->prepare('INSERT INTO workspace_access (workspace_id, user_id, role) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role)')
        ->execute([$wsId, $userId, $role]);
}

/**
 * Invite someone to a company (§7). Creates the account (without a password) if the email is new.
 * Returns [user id, raw token]. The token is shown once; only its hash is stored.
 */
function invite_member(int $orgId, string $name, string $email, string $orgRole, ?int $wsId, ?string $wsRole, int $by): array
{
    if (!isset(ORG_ROLES[$orgRole])) {
        throw new RuntimeException('Unknown company role.');
    }
    $email = norm_email($email);
    if (!valid_email($email)) {
        throw new RuntimeException('That email address doesn’t look right.');
    }
    $u = user_by_email($email);
    if (!$u) {
        $uid = create_user($name, $email, null);
    } else {
        $uid = (int)$u['id'];
        if ($u['status'] === 'disabled') {
            throw new RuntimeException('That person’s account is turned off. Ask Previously Noted support.');
        }
    }
    $m = membership_of($orgId, $uid);
    if ($m && $m['status'] === 'active') {
        throw new RuntimeException('That person is already in the company. Give them access to this production from the list.');
    }
    $token = bin2hex(random_bytes(24));
    $exp = time() + INVITE_DAYS * 86400;
    if ($m) {
        db()->prepare("UPDATE memberships SET org_role = ?, status = 'invited', invited_by = ?, invited_at = ?, joined_at = NULL, token_hash = ?, token_expires = ? WHERE id = ?")
            ->execute([$orgRole, $by, now_iso(), hash('sha256', $token), $exp, $m['id']]);
    } else {
        db()->prepare("INSERT INTO memberships (org_id, user_id, org_role, status, invited_by, invited_at, token_hash, token_expires) VALUES (?,?,?,'invited',?,?,?,?)")
            ->execute([$orgId, $uid, $orgRole, $by, now_iso(), hash('sha256', $token), $exp]);
    }
    if ($wsId && $wsRole) {
        set_ws_access($wsId, $uid, $wsRole);
    }
    return [$uid, $token];
}

/** A new link for a pending invite. */
function reissue_invite(int $membershipId): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare("UPDATE memberships SET token_hash = ?, token_expires = ?, invited_at = ? WHERE id = ? AND status = 'invited'")
        ->execute([hash('sha256', $token), time() + INVITE_DAYS * 86400, now_iso(), $membershipId]);
    return $token;
}

/** The pending invite for a link, with the person and company. */
function invite_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    $st = db()->prepare(
        "SELECT m.id AS membership_id, m.org_role, m.org_id, o.name AS org_name, o.status AS org_status, u.*
         FROM memberships m JOIN users u ON u.id = m.user_id JOIN organizations o ON o.id = m.org_id
         WHERE m.token_hash = ? AND m.token_expires > ? AND m.status = 'invited' AND u.status = 'active'"
    );
    $st->execute([hash('sha256', $token), time()]);
    $r = $st->fetch();
    return $r && $r['org_status'] === 'active' ? $r : null;
}

function accept_invite(int $membershipId): void
{
    db()->prepare("UPDATE memberships SET status = 'active', joined_at = ?, token_hash = NULL, token_expires = NULL WHERE id = ? AND status = 'invited'")
        ->execute([now_iso(), $membershipId]);
}

function active_owners(int $orgId): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id
        WHERE m.org_id = ? AND m.org_role = 'owner' AND m.status = 'active' AND u.status = 'active'");
    $st->execute([$orgId]);
    return (int)$st->fetchColumn();
}

/* ---------------- audit ---------------- */

/** Record a staff action, or anything done while impersonating. Company admins see the rows for their company. */
function audit(string $action, ?int $orgId, string $detail = ''): void
{
    db()->prepare('INSERT INTO audit_log (org_id, actor_id, as_user_id, action, detail, at) VALUES (?,?,?,?,?,?)')
        ->execute([$orgId, Ctx::$real['id'] ?? null, Ctx::impersonating() ? Ctx::$user['id'] : null, mb_substr($action, 0, 60), mb_substr($detail, 0, 2000), now_iso()]);
}

/** The companies a person belongs to (for the audit trail of an impersonation). */
function user_org_ids(int $userId): array
{
    $st = db()->prepare("SELECT org_id FROM memberships WHERE user_id = ? AND status <> 'removed'");
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

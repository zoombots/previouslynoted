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
$real = real_account();
$me = current_account();
if (!$real || !$me) {
    fail('Your session has ended. Sign in again.', 401);
}
Ctx::$real = $real;
Ctx::$user = $me;
$user = $me['name'];

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        fail('Security token mismatch. Reload the page.', 403);
    }
    $isJson = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $in = $isJson ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []) : $_POST;
    // The production the page is on travels in the query string.
    if (!isset($in['ws']) && isset($_GET['ws'])) {
        $in['ws'] = $_GET['ws'];
    }
} else {
    $in = $_GET;
}
// Release the session lock so long requests don't block other tabs (starting and stopping impersonation write to it).
if (!in_array($action, ['platform.impersonate', 'platform.impersonate.stop'], true)) {
    session_write_close();
}

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

/** A version or note of this production (the helpers in lib/ use these names). */
function version_row(int $id): array
{
    return scoped_version($id);
}

function note_row(int $id): array
{
    return scoped_note($id);
}

/*
 * Every action, with what it works on and the role it needs (docs/multitenant-rbac.md §6):
 *   self      your own account and the list of your productions
 *   ws        production data; needs this role in the production (viewer < reviewer < editor < admin)
 *   org       company management; needs this company role (member < admin < owner), platform admins count as owners
 *   platform  Previously Noted staff: support or platform_admin
 * Anything not listed is refused.
 */
const ACTIONS = [
    'GET account' => ['self', null], 'POST account.update' => ['self', null], 'POST account.password' => ['self', null],
    'GET workspaces' => ['self', null], 'POST platform.impersonate.stop' => ['self', null],

    'GET state' => ['ws', 'viewer'], 'GET notes' => ['ws', 'viewer'], 'GET fingerprint' => ['ws', 'viewer'],
    'GET export.markers' => ['ws', 'viewer'], 'GET transcript' => ['ws', 'viewer'], 'GET script' => ['ws', 'viewer'],
    'GET script.export' => ['ws', 'viewer'], 'GET version.media' => ['ws', 'viewer'], 'GET transcribe.status' => ['ws', 'viewer'],
    'GET export' => ['ws', 'viewer'],
    'POST note.create' => ['ws', 'reviewer'], 'POST note.reply' => ['ws', 'reviewer'], 'POST note.edit' => ['ws', 'reviewer'],
    'POST script.edit' => ['ws', 'reviewer'],
    'POST film.create' => ['ws', 'editor'], 'POST film.rename' => ['ws', 'editor'], 'POST film.update' => ['ws', 'editor'],
    'POST version.create' => ['ws', 'editor'], 'POST version.update' => ['ws', 'editor'], 'POST version.import' => ['ws', 'editor'],
    'POST note.status' => ['ws', 'editor'], 'POST note.tag' => ['ws', 'editor'], 'POST note.position' => ['ws', 'editor'],
    'POST note.range' => ['ws', 'editor'], 'POST fingerprint.save' => ['ws', 'editor'], 'POST version.place_visual' => ['ws', 'editor'],
    'POST version.tc_start' => ['ws', 'editor'], 'GET fio.push_preview' => ['ws', 'editor'], 'POST fio.push' => ['ws', 'editor'],
    'POST version.edit' => ['ws', 'editor'], 'POST edit.start' => ['ws', 'editor'], 'POST version.place' => ['ws', 'editor'],
    'POST version.transcript' => ['ws', 'editor'], 'POST transcript.delete' => ['ws', 'editor'], 'POST transcript.speaker' => ['ws', 'editor'],
    'POST category.save' => ['ws', 'editor'], 'GET category.detect' => ['ws', 'editor'], 'POST category.adopt' => ['ws', 'editor'],
    'POST category.delete' => ['ws', 'editor'], 'POST category.order' => ['ws', 'editor'],
    'POST fio.resolve' => ['ws', 'editor'], 'GET fio.stack' => ['ws', 'editor'], 'POST version.link' => ['ws', 'editor'],
    'POST version.unlink' => ['ws', 'editor'], 'POST version.sync' => ['ws', 'editor'],
    'POST transcribe.create' => ['ws', 'editor'], 'POST transcribe.run' => ['ws', 'editor'],
    'POST version.delete' => ['ws', 'admin'],
    // People: production admins give access to people already in the company; inviting and company roles are checked
    // again inside for company admins.
    // Editors can invite someone new to the production as a viewer or reviewer (production admins up to admin).
    'GET people' => ['ws', 'admin'], 'POST people.update' => ['ws', 'admin'], 'POST people.invite' => ['ws', 'editor'],
    'POST people.link' => ['ws', 'admin'], 'POST people.delete' => ['ws', 'admin'],

    'GET settings' => ['org', 'admin'], 'POST settings.frameio' => ['org', 'admin'], 'POST settings.frameio.clear' => ['org', 'admin'],
    'POST settings.elevenlabs' => ['org', 'admin'], 'POST settings.elevenlabs.clear' => ['org', 'admin'],
    'GET company' => ['org', 'admin'], 'POST workspace.create' => ['org', 'admin'], 'POST workspace.update' => ['org', 'admin'],

    'POST settings.mail' => ['platform', 'platform_admin'], 'POST settings.mail.test' => ['platform', 'platform_admin'],
    'POST settings.mail.clear' => ['platform', 'platform_admin'],
    'GET platform' => ['platform', 'support'], 'POST platform.impersonate' => ['platform', 'support'],
    'POST platform.org.create' => ['platform', 'platform_admin'], 'POST platform.org.update' => ['platform', 'platform_admin'],
    'POST platform.user.update' => ['platform', 'platform_admin'], 'POST platform.reset_link' => ['platform', 'platform_admin'],
];

/* ---------------- the gate ---------------- */
[$class, $need] = ACTIONS["$method $action"] ?? fail('Unknown request.', 404);
$wsParam = isset($in['ws']) && is_numeric($in['ws']) ? (int)$in['ws'] : null;
if ($class === 'ws') {
    $wsId = resolve_request_workspace($in);
    if ($wsId === null) {
        $wsId = $wsParam ?? fail('Choose a production first.');
    } elseif ($wsParam !== null && $wsParam !== $wsId) {
        fail('That belongs to another production. Reload the page and try again.');
    }
    $ws = ws_row($wsId);
    $org = $ws ? org_row((int)$ws['org_id']) : null;
    $role = $ws && $org ? ws_role($me, $ws, $org, $real) : null;
    if ($role === null) {
        fail(NO_ACCESS, 404);
    }
    Ctx::set($org, $ws, $role);
    if (!role_at_least($role, $need)) {
        $what = ['reviewer' => 'Viewers can look but not change anything.', 'editor' => 'Only editors and admins can do that.', 'admin' => 'Only admins can do that.'][$need];
        fail($what . ($ws['status'] === 'archived' ? ' This production is archived, so it’s read-only.' : ' Ask an admin if you need more access.'), 403);
    }
} elseif ($class === 'org') {
    $ws = null;
    if (isset($in['workspace_id']) && is_numeric($in['workspace_id'])) {
        $ws = ws_row((int)$in['workspace_id']);
    } elseif (!isset($in['org_id']) && $wsParam !== null) {
        $ws = ws_row($wsParam);
    }
    $orgId = $ws ? (int)$ws['org_id'] : (isset($in['org_id']) && is_numeric($in['org_id']) ? (int)$in['org_id'] : null);
    $org = $orgId ? org_row($orgId) : null;
    if (isset($in['org_id']) && $ws && (int)$in['org_id'] !== (int)$ws['org_id']) {
        fail('That production belongs to another company.');
    }
    $power = $org ? org_power($me, $org, $real) : null;
    if ($power === null || ($org['status'] !== 'active' && !is_platform($me))) {
        fail(NO_ACCESS, 404);
    }
    Ctx::set($org, $ws, $ws ? ws_role($me, $ws, $org, $real) : null);
    if (!org_power_at_least($power, $need) && !($method === 'GET' && $power === 'support')) {
        fail('Only company admins can do that.', 403);
    }
} elseif ($class === 'platform') {
    if (Ctx::impersonating()) {
        fail('Stop impersonating first.', 403);
    }
    if (!is_platform($real, $need)) {
        fail('Unknown request.', 404);
    }
}
if (Ctx::impersonating() && $method === 'POST') {
    if ($class === 'self' && $action !== 'platform.impersonate.stop') {
        fail('You can’t change someone else’s account while impersonating them.', 403);
    }
    if ($action !== 'platform.impersonate.stop') {
        audit('impersonated ' . $action, Ctx::$org ? Ctx::orgId() : null,
            json_encode(array_intersect_key($in, array_flip(['ws', 'film_id', 'version_id', 'note_id', 'transcript_id', 'job_id', 'id'])), JSON_UNESCAPED_SLASHES));
    }
}

/** Email an invite or reset link when asked and email is set up. Returns [sent, error message]. */
function send_account_link(array $person, string $kind, string $link, string $expires, ?array $by, bool $wanted): array
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

/** A company member as the People list shows them, with their role in this production. */
function member_public(array $r): array
{
    $orgWide = in_array($r['org_role'], ['owner', 'admin'], true);
    return [
        'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'org_role' => $r['org_role'],
        'status' => $r['mstatus'], 'last_login' => $r['last_login'], 'joined_at' => $r['joined_at'],
        'invite_pending' => $r['mstatus'] === 'invited' && (int)$r['token_expires'] > time(),
        'ws_role' => $r['ws_role'], 'role' => $orgWide ? 'admin' : $r['ws_role'], 'org_wide' => $orgWide,
        'has_password' => !empty($r['pass_hash']),
    ];
}

function member_row(int $userId): ?array
{
    $st = db()->prepare(
        "SELECT u.*, m.id AS mid, m.org_role, m.status AS mstatus, m.token_expires, m.joined_at, wa.role AS ws_role
         FROM memberships m JOIN users u ON u.id = m.user_id
         LEFT JOIN workspace_access wa ON wa.workspace_id = ? AND wa.user_id = u.id
         WHERE m.org_id = ? AND m.user_id = ? AND m.status <> 'removed'"
    );
    $st->execute([Ctx::wsId(), Ctx::orgId(), $userId]);
    return $st->fetch() ?: null;
}

/** The production as the page shows it in the header. */
function ws_public(): array
{
    return ['id' => Ctx::wsId(), 'name' => Ctx::$ws['name'], 'status' => Ctx::$ws['status'], 'role' => Ctx::$role,
        'org' => ['id' => Ctx::orgId(), 'name' => Ctx::$org['name']], 'company_role' => org_power(Ctx::$user, Ctx::$org, Ctx::$real)];
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

    case 'GET workspaces':
        json_out(['workspaces' => user_workspaces($me, $real), 'platform' => Ctx::impersonating() ? null : ($real['platform_role'] ?? null),
            'impersonating' => Ctx::impersonating() ? user_public($me) : null]);

    /* ---------- people: the company's members and their access to this production ---------- */
    case 'GET people':
        $power = org_power($me, Ctx::$org, $real);
        $st = $pdo->prepare(
            "SELECT u.*, m.id AS mid, m.org_role, m.status AS mstatus, m.token_expires, m.joined_at, wa.role AS ws_role
             FROM memberships m JOIN users u ON u.id = m.user_id
             LEFT JOIN workspace_access wa ON wa.workspace_id = ? AND wa.user_id = u.id
             WHERE m.org_id = ? AND m.status <> 'removed'
             ORDER BY CASE m.status WHEN 'invited' THEN 1 ELSE 0 END, u.name"
        );
        $st->execute([Ctx::wsId(), Ctx::orgId()]);
        json_out(['people' => array_map('member_public', $st->fetchAll()), 'roles' => ROLE_LABELS, 'org_roles' => ORG_ROLE_LABELS,
            'me' => (int)$me['id'], 'company_role' => $power, 'can_company' => org_power_at_least($power, 'admin'), 'can_invite' => true,
            'workspace' => ws_public(), 'mail' => mail_configured() ? mail_settings()['from'] : null]);

    case 'POST people.invite':
        $power = org_power($me, Ctx::$org, $real);
        $companyAdmin = org_power_at_least($power, 'admin');
        $orgRole = (string)($in['org_role'] ?? 'member');
        if (!isset(ORG_ROLES[$orgRole])) {
            fail('Unknown company role.');
        }
        if ($orgRole === 'owner' && $power !== 'owner') {
            fail('Only owners can make someone an owner.', 403);
        }
        $wsRole = (string)($in['role'] ?? '');
        if ($wsRole !== '' && !isset(ROLES[$wsRole])) {
            fail('Unknown role.');
        }
        if (!$companyAdmin) {
            // Editors and production admins invite people to this production only, as company members, up to a role
            // below their own (editors: viewer or reviewer; production admins: up to admin).
            if ($orgRole !== 'member') {
                fail('Only company admins can make someone a company admin or owner.', 403);
            }
            $max = Ctx::$role === 'admin' ? 'admin' : 'reviewer';
            if ($wsRole === '') {
                fail('Choose their role in this production.');
            }
            if (ROLES[$wsRole] > ROLES[$max]) {
                fail('Editors can invite viewers and reviewers. Ask an admin to invite an editor or admin.', 403);
            }
            $known = user_by_email((string)($in['email'] ?? ''));
            $m = $known ? membership_of(Ctx::orgId(), (int)$known['id']) : null;
            if ($m && $m['status'] === 'invited' && $m['org_role'] !== 'member') {
                fail('That person already has an invite from a company admin.', 403);
            }
            if ($m && $m['status'] === 'active') {
                fail(Ctx::$role === 'admin' ? 'That person is already in the company. Give them access from People.'
                    : 'That person is already in the company. Ask an admin of this production to give them access.');
            }
        }
        $name = trim((string)($in['name'] ?? ''));
        [$uid, $token] = invite_member(Ctx::orgId(), $name, (string)($in['email'] ?? ''), $orgRole, Ctx::wsId(), $wsRole ?: null, (int)$me['id']);
        $link = app_base_url() . '?invite=' . $token;
        $row = member_row($uid);
        $person = ['email' => $row['email'], 'name' => $row['name'], 'org_name' => Ctx::$org['name'], 'has_password' => !empty($row['pass_hash']),
            'role_label' => $orgRole !== 'member' ? ORG_ROLE_LABELS[$orgRole] : ($wsRole ? ROLE_LABELS[$wsRole] : '')];
        [$sent, $mailError] = send_account_link($person, 'invite', $link, INVITE_DAYS . ' days', $me, !empty($in['send']));
        json_out(['ok' => true, 'person' => member_public($row), 'link' => $link, 'days' => INVITE_DAYS, 'sent' => $sent, 'mail_error' => $mailError,
            'existing' => !empty($row['pass_hash'])]);

    case 'POST people.link':
        $row = member_row(int_in($in, 'id')) ?? fail('That person is no longer on the list.', 404);
        // Production admins can resend invites of members they can give access to here.
        if (!org_power_at_least(org_power($me, Ctx::$org, $real), 'admin') && ($row['org_role'] !== 'member' || !$row['ws_role'])) {
            fail('Only company admins can send that invite.', 403);
        }
        if ($row['mstatus'] !== 'invited') {
            fail('They’ve already joined. If they’ve forgotten their password, they can get a new one with “Forgot your password?” on the sign-in page.');
        }
        $token = reissue_invite((int)$row['mid']);
        $link = app_base_url() . '?invite=' . $token;
        $person = ['email' => $row['email'], 'name' => $row['name'], 'org_name' => Ctx::$org['name'], 'has_password' => !empty($row['pass_hash']),
            'role_label' => $row['org_role'] !== 'member' ? ORG_ROLE_LABELS[$row['org_role']] : ($row['ws_role'] ? ROLE_LABELS[$row['ws_role']] : '')];
        [$sent, $mailError] = send_account_link($person, 'invite', $link, INVITE_DAYS . ' days', $me, !empty($in['send']));
        json_out(['ok' => true, 'kind' => 'invite', 'link' => $link, 'expires' => INVITE_DAYS . ' days',
            'person' => member_public(member_row((int)$row['id'])), 'sent' => $sent, 'mail_error' => $mailError]);

    case 'POST people.update':
        $row = member_row(int_in($in, 'id')) ?? fail('That person is no longer on the list.', 404);
        $power = org_power($me, Ctx::$org, $real);
        $pdo->beginTransaction();
        if (array_key_exists('role', $in)) {
            // Access to this production: production admins can change it for anyone in the company.
            $r = (string)($in['role'] ?? '');
            if ($r !== '' && !isset(ROLES[$r])) {
                fail('Unknown role.');
            }
            set_ws_access(Ctx::wsId(), (int)$row['id'], $r ?: null);
        }
        if (isset($in['org_role']) && $in['org_role'] !== $row['org_role']) {
            $new = (string)$in['org_role'];
            if (!isset(ORG_ROLES[$new])) {
                fail('Unknown company role.');
            }
            if (!org_power_at_least($power, 'admin')) {
                fail('Only company admins can change company roles.', 403);
            }
            if (($new === 'owner' || $row['org_role'] === 'owner') && $power !== 'owner') {
                fail('Only owners can make someone an owner, or change an owner’s role.', 403);
            }
            if ($row['org_role'] === 'owner' && $row['mstatus'] === 'active' && active_owners(Ctx::orgId()) <= 1) {
                fail('Keep at least one owner. Make someone else an owner first.');
            }
            $pdo->prepare('UPDATE memberships SET org_role = ? WHERE id = ?')->execute([$new, $row['mid']]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'person' => member_public(member_row((int)$row['id']))]);

    case 'POST people.delete':
        // Take someone out of the company (their account and their other companies stay). Their notes stay too.
        $power = org_power($me, Ctx::$org, $real);
        $row = member_row(int_in($in, 'id')) ?? fail('That person is no longer on the list.', 404);
        // Production admins can withdraw a pending invite to this production; removing members is for company admins.
        $withdraw = $row['mstatus'] === 'invited' && $row['org_role'] === 'member' && $row['ws_role'];
        if (!org_power_at_least($power, 'admin') && !$withdraw) {
            fail('Only company admins can remove people from the company. You can take away their access to this production instead.', 403);
        }
        if ((int)$row['id'] === (int)$me['id']) {
            fail('You can’t remove yourself.');
        }
        if ($row['org_role'] === 'owner' && $power !== 'owner') {
            fail('Only owners can remove an owner.', 403);
        }
        if ($row['org_role'] === 'owner' && $row['mstatus'] === 'active' && active_owners(Ctx::orgId()) <= 1) {
            fail('Keep at least one owner.');
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE memberships SET status = 'removed', token_hash = NULL, token_expires = NULL WHERE id = ?")->execute([$row['mid']]);
        $pdo->prepare('DELETE wa FROM workspace_access wa JOIN workspaces w ON w.id = wa.workspace_id WHERE w.org_id = ? AND wa.user_id = ?')
            ->execute([Ctx::orgId(), $row['id']]);
        $pdo->commit();
        json_out(['ok' => true]);

    case 'GET state':
        $ws = Ctx::wsId();
        $st = $pdo->prepare(
            'SELECT v.id, v.film_id, v.label, v.color, v.tc_base, v.tc_start, v.rate, v.frameio_url, v.video_url, v.carried_from,
                    v.fio_file_id, v.fio_stack_id, v.fio_name, v.fio_synced_at, v.fio_account_id,
                    (SELECT COUNT(*) FROM positions p WHERE p.version_id = v.id) AS note_count,
                    (SELECT COUNT(*) FROM transcripts t WHERE t.version_id = v.id) AS transcript_count
             FROM versions v WHERE v.workspace_id = ? ORDER BY v.film_id, v.id'
        );
        $st->execute([$ws]);
        $versions = $st->fetchAll();
        $fps = [];
        $st = $pdo->prepare('SELECT f.version_id, f.created_at, f.source FROM fingerprints f JOIN versions v ON v.id = f.version_id WHERE v.workspace_id = ?');
        $st->execute([$ws]);
        foreach ($st as $f) {
            $fps[(int)$f['version_id']] = ['at' => $f['created_at'], 'source' => $f['source']];
        }
        foreach ($versions as &$vv) {
            $vv['fingerprint'] = $fps[(int)$vv['id']] ?? null;
            $e = active_edit((int)$vv['id']);
            $vv['edit'] = $e ? ['filename' => $e['filename'], 'format' => $e['format'], 'shots' => (int)$e['shot_count'],
                'start_tc' => frames_to_tc((int)$e['start_frame'], max(1, (int)round((float)$e['rate']))), 'uploaded_at' => $e['uploaded_at']] : null;
        }
        unset($vv);
        $st = $pdo->prepare('SELECT id, name, keyterms FROM films WHERE workspace_id = ? ORDER BY id');
        $st->execute([$ws]);
        $films = $st->fetchAll();
        foreach ($films as &$fm) {
            $fm['categories'] = film_categories((int)$fm['id']);
        }
        unset($fm);
        json_out(['user' => $user, 'me' => user_public($me), 'workspace' => ws_public(), 'films' => $films, 'versions' => $versions, 'statuses' => STATUSES,
            'connections' => ['frameio' => fio_configured() && !empty(conn_reload()['fio_account_id']), 'elevenlabs' => el_configured(),
                'mail' => mail_configured() ? conn_reload()['smtp_from'] : null]]);

    case 'POST film.create':
        $name = str_in($in, 'name', 200);
        $pdo->prepare('INSERT INTO films (workspace_id, name, created_at) VALUES (?, ?, ?)')->execute([Ctx::wsId(), $name, now_iso()]);
        json_out(['id' => (int)$pdo->lastInsertId()]);

    case 'POST film.rename':
        $film = scoped_film(int_in($in, 'film_id'));
        $pdo->prepare('UPDATE films SET name = ? WHERE id = ? AND workspace_id = ?')->execute([str_in($in, 'name', 200), $film['id'], Ctx::wsId()]);
        json_out(['ok' => true]);

    case 'POST version.create':
        $filmId = (int)scoped_film(int_in($in, 'film_id'))['id'];
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
            $parentAcc = $src['fio_account_id'] ?: null;
        }
        $color = in_array($in['color'] ?? '', REVISION_COLORS, true) ? $in['color'] : next_revision_color($pdo, $filmId);
        $pdo->prepare('INSERT INTO versions (workspace_id, film_id, label, tc_base, rate, carried_from, created_at, fio_file_id, fio_stack_id, fio_name, fio_account_id, color) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([Ctx::wsId(), $filmId, $label, $tcBase, $rate, $from, now_iso(), $fioFile,
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
        $vals[] = Ctx::wsId();
        $pdo->prepare('UPDATE versions SET ' . implode(', ', $fields) . ' WHERE id = ? AND workspace_id = ?')->execute($vals);
        json_out(['ok' => true]);

    case 'POST version.delete':
        $vid = int_in($in, 'version_id');
        version_row($vid);
        $pdo->beginTransaction();
        // Notes that were first raised on this version and appear nowhere else go with it.
        $pdo->prepare(
            'DELETE FROM notes WHERE origin_version_id = ? AND workspace_id = ?
               AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.note_id = notes.id AND p.version_id <> ?)'
        )->execute([$vid, Ctx::wsId(), $vid]);
        $pdo->prepare('DELETE FROM versions WHERE id = ? AND workspace_id = ?')->execute([$vid, Ctx::wsId()]);
        $pdo->commit();
        @unlink(DATA_DIR . "/fingerprints/$vid.bin");
        json_out(['ok' => true]);

    case 'POST version.import':
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
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
            'SELECT n.id, n.author, n.tag, n.text, n.created_at, n.fio_comment_id, n.origin_version_id,
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
                "SELECT r.note_id, r.author, r.text, r.created_at, r.source, v.label AS version_label
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
        $pdo->prepare('INSERT INTO notes (workspace_id, film_id, origin_version_id, author, tag, text, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([Ctx::wsId(), (int)$version['film_id'], $vid, $user, $tag, $text, now_iso()]);
        $nid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO positions (note_id, version_id, frame, confirmed, method) VALUES (?,?,?,1,'manual')")->execute([$nid, $vid, $frame]);
        add_event($nid, $vid, 'open', null, $user);
        $pdo->commit();
        json_out(['id' => $nid]);

    case 'POST note.status':
        $nid = int_in($in, 'note_id');
        note_row($nid);
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
        add_event($nid, $vid, str_in($in, 'status', 20), str_in($in, 'comment', 5000, false), $user);
        json_out(['ok' => true]);

    case 'POST note.reply':
        $nid = int_in($in, 'note_id');
        note_row($nid);
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
        $pdo->prepare("INSERT INTO replies (note_id, version_id, author, text, created_at, source) VALUES (?,?,?,?,?,'app')")
            ->execute([$nid, $vid, $user, str_in($in, 'text', 5000), now_iso()]);
        json_out(['ok' => true]);

    case 'POST note.tag':
        $nid = int_in($in, 'note_id');
        $note = note_row($nid);
        $tag = clean_tag((string)($in['tag'] ?? ''), film_keys((int)$note['film_id']));
        $pdo->prepare('UPDATE notes SET tag = ? WHERE id = ? AND workspace_id = ?')->execute([$tag, $nid, Ctx::wsId()]);
        json_out(['ok' => true]);

    case 'POST note.position':
        $nid = (int)note_row(int_in($in, 'note_id'))['id'];
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
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
        $pdo->prepare('REPLACE INTO fingerprints (version_id, bytes, duration, samples, source, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$vid, strlen($raw), (float)$head['dur'], (int)$head['n'], mb_substr((string)($in['source'] ?? ''), 0, 200), $user, now_iso()]);
        json_out(['ok' => true, 'at' => now_iso()]);

    case 'GET fingerprint':
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
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
        $pdo->prepare('UPDATE versions SET tc_start = ? WHERE id = ? AND workspace_id = ?')->execute([$new, $vid, Ctx::wsId()]);
        $moved = 0;
        if (!empty($in['move_notes']) && $new !== $old) {
            $d = $new - $old;
            // end_frame first: MariaDB applies the assignments in order, so it must still see the old frame.
            $st = $pdo->prepare('UPDATE positions SET end_frame = CASE WHEN end_frame IS NULL THEN NULL ELSE GREATEST(1, end_frame - ?) END, frame = GREATEST(0, frame - ?) WHERE version_id = ?');
            $st->execute([$d, $d, $vid]);
            $moved = $st->rowCount();
        }
        $pdo->commit();
        json_out(['ok' => true, 'tc_start' => $new, 'moved' => $moved]);

    case 'POST note.edit':
        // Reword a note. Editors and admins can reword any note; reviewers only their own.
        $nid = int_in($in, 'note_id');
        $note = note_row($nid);
        if (!role_at_least(Ctx::$role, 'editor') && $note['author'] !== $me['name']) {
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
            $pdo->prepare('UPDATE notes SET text = ? WHERE id = ? AND workspace_id = ?')->execute([$text, $nid, Ctx::wsId()]);
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
        $ins = $pdo->prepare('REPLACE INTO fio_pushed (note_id, version_id, fio_comment_id, pushed_by, pushed_at) VALUES (?,?,?,?,?)');
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
        $nid = (int)note_row(int_in($in, 'note_id'))['id'];
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
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
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
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
        $st = $pdo->prepare('SELECT t.version_id FROM transcripts t JOIN versions v ON v.id = t.version_id WHERE t.id = ? AND v.workspace_id = ?');
        $st->execute([$tid, Ctx::wsId()]);
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
        json_out(['transcript_id' => (int)$t['id'], 'lines' => array_values($lines)]);

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
        if (!role_at_least(Ctx::$role, 'editor')) {
            $cur = script_lines($vid, $tid, $segs)[$i . ':' . $slot]['state'] ?? 'same';
            if ($state !== 'draft' || in_array($cur, ['approved', 'cut'], true)) {
                fail('Reviewers can suggest new wording as a draft. Approving, cutting or changing an approved line is for editors.', 403);
            }
        }
        $pdo->prepare('INSERT INTO script_edits (version_id, transcript_id, seg_idx, seg_norm, slot, state, text, by_name, at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$vid, $tid, $i, $segs[$i]['norm'], $slot, $state, $text, $user, now_iso()]);
        $lines = script_recorded($vid, script_lines($vid, $tid, $segs));
        json_out(['ok' => true, 'line' => $lines[$i . ':' . $slot] ?? null, 'lines' => array_values($lines)]);

    case 'GET script.export':
        $vid = int_in($in, 'version_id');
        $v = version_row($vid);
        $t = active_transcript($vid) ?? fail('This version has no transcript.', 404);
        $segs = transcript_segments((int)$t['id']);
        $lines = script_lines($vid, (int)$t['id'], $segs);
        $base = (int)$v['tc_base'];
        $film = scoped_film((int)$v['film_id'])['name'];
        $fname = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $film . '_' . $v['label']);
        $rows = [];
        foreach ($segs as $i => $s) {
            $l = $lines["$i:0"] ?? null;
            $rows[] = ['tc' => frames_to_tc($s['start'] + (int)$v['tc_start'], $base), 'speaker' => $s['speaker'], 'current' => $s['text'],
                'state' => $l['state'] ?? 'same', 'new' => $l && in_array($l['state'], ['draft', 'approved'], true) ? $l['text'] : '', 'by' => $l['by'] ?? '', 'at' => $l['at'] ?? ''];
            $extra = array_filter($lines, fn($x) => $x['i'] === $i && $x['slot'] > 0 && $x['state'] !== 'removed');
            usort($extra, fn($a, $b) => $a['slot'] <=> $b['slot']);
            foreach ($extra as $x) {
                $rows[] = ['tc' => frames_to_tc($s['start'] + (int)$v['tc_start'], $base) . ' +' . $x['slot'], 'speaker' => $s['speaker'], 'current' => '',
                    'state' => $x['state'] === 'approved' ? 'approved (new line)' : 'draft (new line)', 'new' => $x['text'], 'by' => $x['by'], 'at' => $x['at']];
            }
        }
        if (($in['format'] ?? 'csv') === 'txt') {
            // A recording script: only the lines to record, with where they go.
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '_new_script.txt"');
            echo "$film · {$v['label']}\nNew lines to record — exported " . gmdate('Y-m-d H:i') . " UTC\n\n";
            $n = 0;
            foreach ($rows as $r) {
                if ($r['new'] === '' && !str_starts_with($r['state'], 'cut')) {
                    continue;
                }
                $n++;
                if ($r['state'] === 'cut') {
                    echo "[{$r['tc']}]  CUT THIS LINE\n    was: {$r['current']}\n\n";
                    continue;
                }
                $mark = str_starts_with($r['state'], 'approved') ? 'APPROVED' : 'DRAFT';
                echo "[{$r['tc']}]  $mark" . ($r['speaker'] ? "  ({$r['speaker']})" : '') . "\n    {$r['new']}\n" . ($r['current'] !== '' ? "    was: {$r['current']}\n" : "    (new line)\n") . "\n";
            }
            if (!$n) {
                echo "No lines have been changed yet.\n";
            }
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '_script.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Timecode', 'Speaker', 'Current line', 'Status', 'New line', 'Changed by', 'Changed at'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [$r['tc'], $r['speaker'], $r['current'], $r['state'], $r['new'], $r['by'], $r['at']], ',', '"', '');
        }
        fclose($out);
        exit;

    /* ---------------- Tracks (note categories) ---------------- */

    case 'POST category.save':
        $filmId = (int)scoped_film(int_in($in, 'film_id'))['id'];
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
            $pdo->prepare('UPDATE categories SET "key" = ?, label = ?, color = ?, kind = ? WHERE id = ? AND film_id = ?')->execute([$key, $label, $color, $kind, $id, $filmId]);
            if ($old['key'] !== $key) {
                // Notes keep their track when its code changes.
                $notes = $pdo->prepare("SELECT id, tag FROM notes WHERE film_id = ? AND workspace_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ?)");
                $notes->execute([$filmId, Ctx::wsId(), $old['key'], $old['key'] . '-%', '%-' . $old['key']]);
                $upd = $pdo->prepare('UPDATE notes SET tag = ? WHERE id = ? AND workspace_id = ?');
                foreach ($notes as $n) {
                    $parts = array_map(fn($p) => $p === $old['key'] ? $key : $p, explode('-', $n['tag']));
                    $upd->execute([implode('-', $parts), $n['id'], Ctx::wsId()]);
                }
            }
        } else {
            $sort = $cats ? max(array_column($cats, 'sort')) + 1 : 0;
            $pdo->prepare('INSERT INTO categories (film_id, "key", label, color, kind, sort) VALUES (?,?,?,?,?,?)')->execute([$filmId, $key, $label, $color, $kind, $sort]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        json_out(['id' => $id, 'categories' => film_categories($filmId)]);

    case 'GET category.detect':
        // Codes the film's untagged notes start with.
        $filmId = (int)scoped_film(int_in($in, 'film_id'))['id'];
        $st = $pdo->prepare("SELECT text FROM notes WHERE film_id = ? AND workspace_id = ? AND (tag IS NULL OR tag = '')");
        $st->execute([$filmId, Ctx::wsId()]);
        $codes = detect_codes($st->fetchAll(PDO::FETCH_COLUMN), film_keys($filmId));
        $used = [];
        foreach (film_categories($filmId) as $c) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE film_id = ? AND workspace_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ? OR tag LIKE ?)");
            $n->execute([$filmId, Ctx::wsId(), $c['key'], $c['key'] . '-%', '%-' . $c['key'], '%-' . $c['key'] . '-%']);
            $used[$c['key']] = (int)$n->fetchColumn();
        }
        json_out(['codes' => array_map(fn($c, $n) => ['code' => (string)$c, 'count' => $n, 'label' => code_label((string)$c)], array_keys($codes), $codes),
            'track_notes' => $used]);

    case 'POST category.adopt':
        // Create a track for each chosen code, put the matching untagged notes on it, and (optionally) remove the
        // starter tracks E, N and A when no note uses them.
        $filmId = (int)scoped_film(int_in($in, 'film_id'))['id'];
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
            $pdo->prepare('INSERT INTO categories (film_id, "key", label, color, kind, sort) VALUES (?,?,?,?,?,?)')->execute([$filmId, $code, $label, $color, 'picture', $sort++]);
            $created[] = $code;
            $have[] = $code;
        }
        $tagged = 0;
        if ($created) {
            $st = $pdo->prepare("SELECT id, text FROM notes WHERE film_id = ? AND workspace_id = ? AND (tag IS NULL OR tag = '')");
            $st->execute([$filmId, Ctx::wsId()]);
            $upd = $pdo->prepare('UPDATE notes SET tag = ?, text = ? WHERE id = ? AND workspace_id = ?');
            foreach ($st->fetchAll() as $n) {
                [$tag, $text] = split_tag((string)$n['text'], $created);
                if ($tag !== '') {
                    $upd->execute([$tag, $text, $n['id'], Ctx::wsId()]);
                    $tagged++;
                }
            }
        }
        $dropped = [];
        if (!empty($in['drop_defaults'])) {
            foreach (DEFAULT_CATEGORIES as [$k]) {
                $n = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE film_id = ? AND workspace_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ? OR tag LIKE ?)");
                $n->execute([$filmId, Ctx::wsId(), $k, $k . '-%', '%-' . $k, '%-' . $k . '-%']);
                if ((int)$n->fetchColumn() === 0 && count($have) > 1) {
                    $pdo->prepare('DELETE FROM categories WHERE film_id = ? AND "key" = ?')->execute([$filmId, $k]);
                    $dropped[] = $k;
                }
            }
        }
        $pdo->commit();
        json_out(['ok' => true, 'created' => $created, 'tagged' => $tagged, 'dropped' => $dropped, 'categories' => film_categories($filmId)]);

    case 'POST category.delete':
        $filmId = (int)scoped_film(int_in($in, 'film_id'))['id'];
        $id = int_in($in, 'id');
        $cats = film_categories($filmId);
        $cat = array_values(array_filter($cats, fn($c) => $c['id'] === $id))[0] ?? fail('That track no longer exists.', 404);
        $moveTo = clean_tag((string)($in['move_to'] ?? ''), array_column($cats, 'key'));
        if ($moveTo === $cat['key']) {
            fail('Choose a different track to move the notes to.');
        }
        $pdo->beginTransaction();
        $notes = $pdo->prepare("SELECT id, tag FROM notes WHERE film_id = ? AND workspace_id = ? AND (tag = ? OR tag LIKE ? OR tag LIKE ?)");
        $notes->execute([$filmId, Ctx::wsId(), $cat['key'], $cat['key'] . '-%', '%-' . $cat['key']]);
        $upd = $pdo->prepare('UPDATE notes SET tag = ? WHERE id = ? AND workspace_id = ?');
        $moved = 0;
        foreach ($notes as $n) {
            $parts = explode('-', $n['tag']);
            $first = $parts[0] === $cat['key'];
            $parts = array_values(array_filter($parts, fn($p) => $p !== $cat['key']));
            if ($first) {
                array_unshift($parts, $moveTo);
            }
            $upd->execute([implode('-', array_values(array_unique(array_filter($parts, fn($p) => $p !== '')))), $n['id'], Ctx::wsId()]);
            $moved++;
        }
        $pdo->prepare('DELETE FROM categories WHERE id = ? AND film_id = ?')->execute([$id, $filmId]);
        $pdo->commit();
        json_out(['ok' => true, 'moved' => $moved, 'categories' => film_categories($filmId)]);

    case 'POST category.order':
        $filmId = (int)scoped_film(int_in($in, 'film_id'))['id'];
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
        $staff = is_platform($me, 'platform_admin') && !Ctx::impersonating();
        json_out([
            'company' => ['id' => Ctx::orgId(), 'name' => Ctx::$org['name']],
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
                'key' => mask_secret($c['el_key'] ?? null), 'model' => $c['el_model'] ?? 'scribe_v2',
                'language' => $c['el_language'] ?? '', 'diarize' => $c['el_diarize'] ?? true,
            ],
            'mail' => !$staff ? ['configured' => mail_configured(), 'from' => mail_configured() ? ($c['smtp_from'] ?? '') : '', 'staff' => false] : [
                'staff' => true,
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
            fio_save_tokens(null);
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
        conn_save(['fio_client_id' => null, 'fio_client_secret' => null, 'fio_account_id' => null, 'fio_account_name' => null, 'fio_accounts' => null, 'fio_checked_at' => null, 'fio_mode' => null]);
        fio_save_tokens(null);
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
        conn_save(array_filter(['el_key' => $key !== '' ? $key : null], fn($v) => $v !== null) + [
            'el_model' => $model, 'el_language' => $lang !== '' ? $lang : null, 'el_diarize' => !empty($in['diarize']),
        ]);
        json_out(['ok' => true]);

    case 'POST settings.elevenlabs.clear':
        conn_save(['el_key' => null, 'el_model' => null, 'el_language' => null, 'el_diarize' => null]);
        json_out(['ok' => true]);

    case 'POST film.update':
        $fid = (int)scoped_film(int_in($in, 'film_id'))['id'];
        $terms = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 49), preg_split('/[\r\n,]+/', (string)($in['keyterms'] ?? ''))))));
        $pdo->prepare('UPDATE films SET keyterms = ? WHERE id = ? AND workspace_id = ?')->execute([implode("\n", array_slice($terms, 0, 1000)), $fid, Ctx::wsId()]);
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
        $linked = $pdo->prepare('SELECT fio_file_id, label FROM versions WHERE film_id = ? AND workspace_id = ? AND fio_file_id IS NOT NULL');
        $linked->execute([(int)$v['film_id'], Ctx::wsId()]);
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
        $pdo->prepare('UPDATE versions SET fio_file_id = ?, fio_stack_id = ?, fio_name = ?, fio_account_id = ? WHERE id = ? AND workspace_id = ?')
            ->execute([$fileId, $stackId, mb_substr((string)($in['name'] ?? ''), 0, 300), $accId, $vid, Ctx::wsId()]);
        json_out(['ok' => true]);

    case 'POST version.unlink':
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
        $pdo->prepare('UPDATE versions SET fio_file_id = NULL, fio_stack_id = NULL, fio_name = NULL, fio_synced_at = NULL, fio_account_id = NULL WHERE id = ? AND workspace_id = ?')->execute([$vid, Ctx::wsId()]);
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
        $pdo->prepare('UPDATE versions SET fio_synced_at = ? WHERE id = ? AND workspace_id = ?')->execute([now_iso(), $vid, Ctx::wsId()]);
        json_out($stats + ['total' => count($got['comments']), 'sample_timestamp' => $got['sample']]);

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
        if (!$v['fio_file_id'] && !$v['video_url']) {
            fail('Transcription needs the video online: link this version to its Frame.io file (or give it a video link) first.');
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
        $jid = int_in($in, 'job_id'); // the gate checked the job's version is in this production
        $claim = $pdo->prepare("UPDATE jobs SET status = 'running', message = ?, updated_at = ? WHERE id = ? AND status = 'queued'");
        $claim->execute(['Getting the video link…', now_iso(), $jid]);
        if ($claim->rowCount() !== 1) {
            json_out(['ok' => true, 'already' => true]);
        }
        respond_then_continue(['ok' => true]);
        run_transcription($jid);
        exit;

    case 'GET transcribe.status':
        $vid = (int)version_row(int_in($in, 'version_id'))['id'];
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
        $tid = int_in($in, 'transcript_id'); // the gate checked the transcript is in this production
        $from = str_in($in, 'from', 60);
        $to = str_in($in, 'to', 60);
        $u = $pdo->prepare('UPDATE segments SET speaker = ? WHERE transcript_id = ? AND speaker = ?');
        $u->execute([$to, $tid, $from]);
        json_out(['ok' => true, 'changed' => $u->rowCount()]);

    case 'GET export':
        $film = scoped_film(int_in($in, 'film_id'));
        $filmId = (int)$film['id'];
        $filmName = $film['name'];
        $vs = $pdo->prepare('SELECT id, label, tc_base, tc_start FROM versions WHERE film_id = ? AND workspace_id = ? ORDER BY id');
        $vs->execute([$filmId, Ctx::wsId()]);
        $versions = $vs->fetchAll();
        $ns = $pdo->prepare(
            'SELECT n.*, ov.label AS origin_label FROM notes n LEFT JOIN versions ov ON ov.id = n.origin_version_id
             WHERE n.film_id = ? AND n.workspace_id = ? ORDER BY n.id'
        );
        $ns->execute([$filmId, Ctx::wsId()]);
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

    /* ---------------- Company: productions and the audit trail ---------------- */

    case 'GET company':
        $st = $pdo->prepare(
            "SELECT w.id, w.name, w.status, w.created_at,
                    (SELECT COUNT(*) FROM films f WHERE f.workspace_id = w.id) AS films,
                    (SELECT COUNT(*) FROM workspace_access a JOIN memberships m ON m.user_id = a.user_id AND m.org_id = w.org_id AND m.status = 'active'
                      WHERE a.workspace_id = w.id) AS people
             FROM workspaces w WHERE w.org_id = ? ORDER BY w.status, w.name, w.id"
        );
        $st->execute([Ctx::orgId()]);
        $log = $pdo->prepare(
            'SELECT a.action, a.detail, a.at, ru.name AS actor, au.name AS as_name
             FROM audit_log a LEFT JOIN users ru ON ru.id = a.actor_id LEFT JOIN users au ON au.id = a.as_user_id
             WHERE a.org_id = ? ORDER BY a.id DESC LIMIT 200'
        );
        $log->execute([Ctx::orgId()]);
        $m = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE org_id = ? AND status = 'active'");
        $m->execute([Ctx::orgId()]);
        json_out(['company' => ['id' => Ctx::orgId(), 'name' => Ctx::$org['name'], 'status' => Ctx::$org['status'], 'members' => (int)$m->fetchColumn()],
            'workspaces' => $st->fetchAll(), 'audit' => $log->fetchAll(), 'company_role' => org_power($me, Ctx::$org, $real)]);

    case 'POST workspace.create':
        $id = create_workspace(Ctx::orgId(), (string)($in['name'] ?? ''));
        // The person who made it can open it straight away, even if they're a production admin only by company role.
        json_out(['ok' => true, 'id' => $id]);

    case 'POST workspace.update':
        $ws = Ctx::$ws ?? fail(NO_ACCESS, 404);
        $name = isset($in['name']) ? trim((string)$in['name']) : $ws['name'];
        if ($name === '' || mb_strlen($name) > 120) {
            fail('Enter the production’s name (up to 120 characters).');
        }
        $status = in_array($in['status'] ?? '', ['active', 'archived'], true) ? $in['status'] : $ws['status'];
        if ($status === 'archived' && $ws['status'] !== 'archived') {
            $n = $pdo->prepare("SELECT COUNT(*) FROM workspaces WHERE org_id = ? AND status = 'active'");
            $n->execute([Ctx::orgId()]);
            if ((int)$n->fetchColumn() <= 1) {
                fail('Keep at least one production open. Make a new one first.');
            }
        }
        $pdo->prepare('UPDATE workspaces SET name = ?, status = ? WHERE id = ? AND org_id = ?')->execute([$name, $status, $ws['id'], Ctx::orgId()]);
        json_out(['ok' => true]);

    /* ---------------- Platform: Previously Noted staff ---------------- */

    case 'GET platform':
        $orgs = $pdo->query(
            "SELECT o.id, o.name, o.slug, o.status, o.created_at,
                    (SELECT COUNT(*) FROM memberships m WHERE m.org_id = o.id AND m.status = 'active') AS members,
                    (SELECT COUNT(*) FROM workspaces w WHERE w.org_id = o.id) AS workspaces,
                    (SELECT MIN(w.id) FROM workspaces w WHERE w.org_id = o.id AND w.status = 'active') AS first_ws,
                    (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') FROM memberships m JOIN users u ON u.id = m.user_id
                      WHERE m.org_id = o.id AND m.org_role = 'owner' AND m.status = 'active') AS owners
             FROM organizations o ORDER BY o.name"
        )->fetchAll();
        $q = trim((string)($in['q'] ?? ''));
        $st = $pdo->prepare(
            "SELECT u.*, (SELECT GROUP_CONCAT(o.name ORDER BY o.name SEPARATOR ', ') FROM memberships m JOIN organizations o ON o.id = m.org_id
                          WHERE m.user_id = u.id AND m.status <> 'removed') AS companies
             FROM users u WHERE (? = '' OR u.email LIKE ? OR u.name LIKE ?) OR u.platform_role IS NOT NULL
             ORDER BY u.platform_role IS NULL, u.name LIMIT 100"
        );
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st->execute([$q, $like, $like]);
        $people = array_map(fn($u) => user_public($u) + ['companies' => $u['companies']], $st->fetchAll());
        json_out(['orgs' => $orgs, 'people' => $people, 'me' => (int)$real['id'], 'role' => $real['platform_role'],
            'mail' => mail_configured() ? mail_settings()['from'] : null]);

    case 'POST platform.org.create':
        // A new company with its first production; its owner is invited (or added, if they're you).
        $email = norm_email((string)($in['owner_email'] ?? ''));
        $pdo->beginTransaction();
        $orgId = create_org((string)($in['name'] ?? ''));
        $wsId = create_workspace($orgId, trim((string)($in['workspace'] ?? '')) ?: 'First production');
        Ctx::set(org_row($orgId), ws_row($wsId), 'admin');
        $link = null;
        if ($email === '' || $email === $real['email']) {
            add_member($orgId, (int)$real['id'], 'owner', (int)$real['id']);
        } else {
            [$uid, $token] = invite_member($orgId, (string)($in['owner_name'] ?? ''), $email, 'owner', null, null, (int)$real['id']);
            $link = app_base_url() . '?invite=' . $token;
        }
        audit('company created', $orgId, (string)($in['name'] ?? ''));
        $pdo->commit();
        $sent = false;
        $mailError = null;
        if ($link) {
            $u = user_by_email($email);
            [$sent, $mailError] = send_account_link(['email' => $u['email'], 'name' => $u['name'], 'org_name' => Ctx::$org['name'], 'has_password' => !empty($u['pass_hash']),
                'role_label' => ORG_ROLE_LABELS['owner']], 'invite', $link, INVITE_DAYS . ' days', $real, !empty($in['send']));
        }
        json_out(['ok' => true, 'org_id' => $orgId, 'workspace_id' => $wsId, 'link' => $link, 'sent' => $sent, 'mail_error' => $mailError]);

    case 'POST platform.org.update':
        $org = org_row(int_in($in, 'org_id')) ?? fail('That company no longer exists.', 404);
        $status = in_array($in['status'] ?? '', ['active', 'suspended'], true) ? $in['status'] : $org['status'];
        $name = isset($in['name']) ? trim((string)$in['name']) : $org['name'];
        if ($name === '' || mb_strlen($name) > 120) {
            fail('Enter the company’s name (up to 120 characters).');
        }
        $pdo->prepare('UPDATE organizations SET name = ?, status = ? WHERE id = ?')->execute([$name, $status, $org['id']]);
        if ($status !== $org['status']) {
            audit($status === 'suspended' ? 'company suspended' : 'company reactivated', (int)$org['id']);
        }
        json_out(['ok' => true]);

    case 'POST platform.user.update':
        $u = user_by_id(int_in($in, 'id')) ?? fail('That person no longer exists.', 404);
        $pr = array_key_exists('platform_role', $in) ? ($in['platform_role'] ?: null) : $u['platform_role'];
        if ($pr !== null && !in_array($pr, PLATFORM_ROLES, true)) {
            fail('Unknown platform role.');
        }
        $status = in_array($in['status'] ?? '', ['active', 'disabled'], true) ? $in['status'] : $u['status'];
        if ((int)$u['id'] === (int)$real['id'] && ($pr !== 'platform_admin' || $status !== 'active')) {
            fail('You can’t take away your own platform admin role or turn yourself off.');
        }
        $pdo->prepare('UPDATE users SET platform_role = ?, status = ?, epoch = epoch + ?, locked_until = CASE WHEN ? THEN 0 ELSE locked_until END,
                failed = CASE WHEN ? THEN 0 ELSE failed END WHERE id = ?')
            ->execute([$pr, $status, $status !== $u['status'] ? 1 : 0, !empty($in['unlock']) ? 1 : 0, !empty($in['unlock']) ? 1 : 0, $u['id']]);
        foreach (user_org_ids((int)$u['id']) ?: [null] as $oid) {
            audit('person changed', $oid, $u['email'] . ': platform role ' . ($pr ?? 'none') . ', ' . $status . (!empty($in['unlock']) ? ', unlocked' : ''));
        }
        json_out(['ok' => true, 'person' => user_public(user_by_id((int)$u['id']))]);

    case 'POST platform.reset_link':
        $u = user_by_id(int_in($in, 'id')) ?? fail('That person no longer exists.', 404);
        if ($u['status'] === 'disabled') {
            fail('Turn this account back on first.');
        }
        $token = issue_token((int)$u['id']);
        $link = app_base_url() . '?reset=' . $token;
        foreach (user_org_ids((int)$u['id']) ?: [null] as $oid) {
            audit('password reset link', $oid, $u['email']);
        }
        [$sent, $mailError] = send_account_link($u, 'reset', $link, RESET_HOURS . ' hours', $real, !empty($in['send']));
        json_out(['ok' => true, 'kind' => 'reset', 'link' => $link, 'expires' => RESET_HOURS . ' hours', 'sent' => $sent, 'mail_error' => $mailError]);

    case 'POST platform.impersonate':
        // See the app as someone else, to reproduce a problem. Support stays read-only; every company they're in sees it.
        $u = user_by_id(int_in($in, 'id')) ?? fail('That person no longer exists.', 404);
        if ((int)$u['id'] === (int)$real['id']) {
            fail('That’s you.');
        }
        if (is_platform($u, 'platform_admin') && !is_platform($real, 'platform_admin')) {
            fail('Support can’t impersonate a platform admin.', 403);
        }
        $_SESSION['as'] = (int)$u['id'];
        session_write_close();
        Ctx::$user = $u;
        foreach (user_org_ids((int)$u['id']) ?: [null] as $oid) {
            audit('impersonation started', $oid, $u['email']);
        }
        json_out(['ok' => true]);

    case 'POST platform.impersonate.stop':
        if (Ctx::impersonating()) {
            foreach (user_org_ids((int)$me['id']) ?: [null] as $oid) {
                audit('impersonation ended', $oid, $me['email']);
            }
        }
        unset($_SESSION['as']);
        session_write_close();
        json_out(['ok' => true]);

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
        $film = scoped_film((int)$v['film_id']);
        $terms = array_values(array_filter(explode("\n", (string)($film['keyterms'] ?? ''))));
        $source = $v['fio_file_id'] ? fio_media($v['fio_file_id'], 'efficient', $v['fio_account_id'] ?: null)['url'] : $v['video_url'];
        $set('running', 'ElevenLabs is transcribing the cut…');
        $res = el_transcribe($source, $terms);
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

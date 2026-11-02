<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/connect.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/setup.php';
security_headers();

$cfg = app_config();
$error = '';

function page_head(string $title): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Mono:wght@400;600&family=Atkinson+Hyperlegible+Next:ital,wght@0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
<link rel="icon" href="assets/icons/favicon.ico" sizes="any">
<link rel="icon" href="assets/icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="mask-icon" href="assets/icons/mask-icon.svg" color="#1C2025">
<link rel="manifest" href="assets/icons/site.webmanifest">
<meta name="theme-color" content="#1C2025">
</head>
<?php
}

/** The Previously Noted mark, inline so its tile can follow the theme. */
function brand_mark(int $px, string $class = 'mark'): string
{
    if ($px <= 32) {
        return '<svg class="' . $class . '" width="' . $px . '" height="' . $px . '" viewBox="0 0 32 32" aria-hidden="true">'
            . '<rect width="32" height="32" rx="7" class="mark-tile"/><rect x="10" y="5" width="18" height="13" rx="2.5" fill="#6c737c"/>'
            . '<rect x="4" y="10" width="20" height="16" rx="3" fill="#ebe6dc"/>'
            . '<path d="M8 13h12a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 1-1.5 1.5h-4l-2 3-2-3H8a1.5 1.5 0 0 1-1.5-1.5v-4A1.5 1.5 0 0 1 8 13z" fill="#e6a94f"/></svg>';
    }
    return '<svg class="' . $class . '" width="' . $px . '" height="' . $px . '" viewBox="0 0 512 512" aria-hidden="true">'
        . '<rect width="512" height="512" rx="112" class="mark-tile"/><rect x="150" y="90" width="270" height="198" rx="32" fill="none" stroke="#7b828b" stroke-width="26"/>'
        . '<rect x="92" y="164" width="300" height="222" rx="36" fill="#ebe6dc"/><rect x="134" y="322" width="216" height="20" rx="10" fill="#1c2025" opacity=".2"/>'
        . '<path d="M186 204h112a28 28 0 0 1 28 28v38a28 28 0 0 1-28 28h-38l-18 34-18-34h-38a28 28 0 0 1-28-28v-38a28 28 0 0 1 28-28z" fill="#e6a94f"/></svg>';
}

function gate_page(string $title, string $heading, string $lede, string $error, callable $form, string $foot = ''): never
{
    page_head($title); ?>
<body class="gate">
<main class="gate-card">
  <?php $appName = app_config()['app_name'] ?? 'Previously Noted'; ?>
  <?php if ($heading === $appName): ?>
  <div class="gate-brand big"><?= brand_mark(64) ?><h1><?= h($heading) ?></h1></div>
  <?php else: ?>
  <div class="gate-brand"><?= brand_mark(32) ?><span><?= h($appName) ?></span></div>
  <h1><?= h($heading) ?></h1>
  <?php endif; ?>
  <?php if ($lede): ?><p class="lede"><?= $lede ?></p><?php endif; ?>
  <?php if ($error): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php $form(); ?>
  <?php if ($foot): ?><p class="gate-foot"><?= $foot ?></p><?php endif; ?>
</main>
</body>
</html>
<?php
    exit;
}

function post(string $k): string
{
    return trim((string)($_POST[$k] ?? ''));
}

function csrf_ok(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''));
}

function csrf_field(): void
{
    ?><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><?php
}

function slow_down(): void
{
    $_SESSION['attempts'] = ($_SESSION['attempts'] ?? 0) + 1;
    if ($_SESSION['attempts'] > 5) {
        sleep(min(10, $_SESSION['attempts'] - 5));
    }
}

/* ---------- First run: connect the database, then create the first account ---------- */
// Setup state lives in this browser's session, so nobody else can finish a setup someone else started.
start_session();
if (!$cfg || empty($cfg['db'])) {
    $problems = setup_problems();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$problems) {
        $dbc = ['host' => post('db_host') ?: 'localhost', 'port' => (int)(post('db_port') ?: 3306), 'name' => post('db_name'), 'user' => post('db_user'),
            'pass' => (string)($_POST['db_pass'] ?? '')];
        try {
            if (!csrf_ok()) {
                throw new RuntimeException('The form expired. Try again.');
            }
            $pdo = db_connect($dbc);
            migrate($pdo);
            write_config(['app_name' => mb_substr(post('app_name') ?: ($cfg['app_name'] ?? 'Previously Noted'), 0, 60), 'db' => $dbc,
                'created_at' => $cfg['created_at'] ?? now_iso()]);
            $_SESSION['setup_ok'] = true;
            header('Location: ./');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
    gate_page('Set up Previously Noted', 'Set up Previously Noted',
        'First, connect the MariaDB database. In cPanel → <b>MySQL Databases</b>, create a database and a user, and add the user to the database with <b>All privileges</b>.',
        $error . implode(' ', $problems), function () use ($problems, $cfg) { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <label>Name shown in the header<input name="app_name" value="<?= h($_POST['app_name'] ?? $cfg['app_name'] ?? 'Previously Noted') ?>" maxlength="60"></label>
    <label>Database name<input name="db_name" required value="<?= h($_POST['db_name'] ?? '') ?>" placeholder="cpaneluser_notes"></label>
    <label>Database user<input name="db_user" required value="<?= h($_POST['db_user'] ?? '') ?>" placeholder="cpaneluser_notes"></label>
    <label>Database user’s password<input type="password" name="db_pass" autocomplete="off"></label>
    <details><summary>Server (usually leave as it is)</summary>
      <label>Host<input name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>"></label>
      <label>Port<input name="db_port" inputmode="numeric" value="<?= h($_POST['db_port'] ?? '3306') ?>"></label>
    </details>
    <button class="btn primary" <?= $problems ? 'disabled' : '' ?>>Connect</button>
  </form>
<?php });
}
db(); // run migrations

if (user_count() === 0 || !empty($cfg['recover'])) {
    if (empty($_SESSION['setup_ok'])) {
        gate_page('Setup didn’t finish', 'Setup didn’t finish',
            'Setup was started in another browser, or this browser’s session ended. To start again, delete <b>data/config.php</b> in File Manager and open this page.', '', function () {});
    }
    $old = is_file(DATA_DIR . '/notes.sqlite') && user_count() === 0 ? DATA_DIR . '/notes.sqlite' : null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw = (string)($_POST['password'] ?? '');
        try {
            if (!csrf_ok()) {
                throw new RuntimeException('The form expired. Try again.');
            }
            if (user_count() > 0) {
                // Recovery: whoever can delete data/config.php controls the server, so the account with this
                // email gets the new password and becomes a platform admin.
                if ($p = password_problem($pw, (string)($_POST['password2'] ?? ''))) {
                    throw new RuntimeException($p);
                }
                $u = user_by_email(post('email')) ?? throw new RuntimeException('There’s no account with that email address in this database.');
                set_password((int)$u['id'], $pw);
                db()->prepare("UPDATE users SET platform_role = 'platform_admin', status = 'active' WHERE id = ?")->execute([$u['id']]);
                $id = (int)$u['id'];
            } elseif ($old) {
                $r = import_sqlite_install($old, DATA_DIR, post('company'), post('workspace'), post('email'), $pw);
                $id = $r['user_id'];
            } else {
                if ($p = password_problem($pw, (string)($_POST['password2'] ?? ''))) {
                    throw new RuntimeException($p);
                }
                $id = setup_first_account(post('company'), post('workspace'), post('name'), post('email'), $pw);
            }
            unset($cfg['recover']);
            write_config($cfg);
            unset($_SESSION['setup_ok']);
            sign_in_session(user_by_id($id));
            header('Location: ./');
            exit;
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                error_log('[previouslynoted] setup: ' . $e->getMessage());
            }
            $error = $e instanceof PDOException ? 'Something went wrong in the database. Details are in the PHP error log.' : $e->getMessage();
        }
    }
    if (user_count() > 0) {
        gate_page('Recover access', 'Recover access',
            'This database already has accounts. Enter the email of yours and choose a new password; you’ll become a platform admin.', $error, function () { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <label>Your email<input type="email" name="email" required autocomplete="username" value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary">Save and sign in</button>
  </form>
<?php });
    }
    if ($old) {
        gate_page('Bring over your notes', 'Bring over your notes',
            'This folder has the notes from your current install. They move into the database as one company with one production, and you become its owner. Sign in with your current admin email and password. Other people aren’t carried over: invite them again from <b>People</b> afterwards. Their names stay on the notes they wrote.',
            $error, function () { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <label>Company name<input name="company" required maxlength="120" value="<?= h($_POST['company'] ?? '') ?>" placeholder="Your production company"></label>
    <label>Production name<input name="workspace" required maxlength="120" value="<?= h($_POST['workspace'] ?? '') ?>" placeholder="The film or series"></label>
    <label>Your admin email<input type="email" name="email" required autocomplete="username" value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label>Your password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary">Bring over the notes</button>
  </form>
<?php });
    }
    gate_page('Set up Previously Noted', 'Create the first account',
        'You’ll own the first company and be the platform admin, so you can add other companies later. Invite your team from <b>People</b> once you’re in.',
        $error, function () { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <label>Company name<input name="company" required maxlength="120" value="<?= h($_POST['company'] ?? '') ?>" placeholder="Your production company"></label>
    <label>First production<input name="workspace" required maxlength="120" value="<?= h($_POST['workspace'] ?? '') ?>" placeholder="The film or series"></label>
    <label>Your name<input name="name" required maxlength="60" value="<?= h($_POST['name'] ?? '') ?>" autocomplete="name"></label>
    <label>Your email<input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" autocomplete="email"></label>
    <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary">Finish setup</button>
  </form>
<?php });
}

/* ---------- Sign out ---------- */
if (isset($_GET['signout'])) {
    $next = (string)($_GET['next'] ?? '');
    $_SESSION = [];
    session_destroy();
    header('Location: ./' . (preg_match('/^invite=[0-9a-f]{48}$/', $next) ? '?' . $next : ''));
    exit;
}

/* ---------- Invite links: join a company ---------- */
if (isset($_GET['invite'])) {
    $token = (string)$_GET['invite'];
    $inv = invite_by_token($token);
    if (!$inv) {
        gate_page('Link expired · ' . $cfg['app_name'], 'This link doesn’t work any more',
            'It may have been used already, or it expired. Ask the person who invited you for a new one.', '', function () { ?>
  <p><a class="btn" href="./">Go to sign in</a></p>
<?php });
    }
    $signed = real_account();
    $finish = function () use ($inv) {
        accept_invite((int)$inv['membership_id']);
        // Open a production of the company they just joined.
        $u = user_by_id((int)$inv['id']);
        foreach (user_workspaces($u) as $w) {
            if ($w['org_id'] === (int)$inv['org_id']) {
                db()->prepare('UPDATE users SET last_ws = ? WHERE id = ?')->execute([$w['id'], $u['id']]);
                break;
            }
        }
        header('Location: ./');
        exit;
    };
    if ($signed && (int)$signed['id'] !== (int)$inv['id']) {
        gate_page('Join ' . $inv['org_name'], 'Join ' . $inv['org_name'],
            'This invite is for <b>' . h($inv['email']) . '</b>, but you’re signed in as <b>' . h($signed['email']) . '</b>.', '', function () use ($token) { ?>
  <p><a class="btn primary" href="?signout=1&amp;next=invite=<?= h($token) ?>">Sign out and continue</a></p>
<?php });
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            $error = 'The form expired. Try again.';
        } elseif ($signed) {
            $finish();
        } elseif ($inv['pass_hash']) {
            slow_down();
            $r = attempt_login($inv['email'], (string)($_POST['password'] ?? ''));
            if (is_string($r)) {
                sleep(1);
                $error = $r;
            } else {
                sign_in_session($r);
                $finish();
            }
        } elseif ($p = password_problem((string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''))) {
            $error = $p;
        } else {
            $name = post('name');
            if ($name !== '' && mb_strlen($name) <= 60) {
                db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $inv['id']]);
            }
            set_password((int)$inv['id'], (string)$_POST['password']);
            $u = user_by_id((int)$inv['id']);
            sign_in_session($u);
            db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now_iso(), $u['id']]);
            $finish();
        }
    }
    $role = ORG_ROLE_LABELS[$inv['org_role']] ?? '';
    $lede = 'You’ve been invited to <b>' . h($inv['org_name']) . '</b>' . ($inv['org_role'] !== 'member' ? ' as <b>' . h($role) . '</b>' : '') . ' with <b>' . h($inv['email']) . '</b>. ';
    gate_page('Join ' . $inv['org_name'], 'Join ' . $inv['org_name'],
        $lede . ($signed ? 'Join to start.' : ($inv['pass_hash'] ? 'You already have an account: sign in to join.' : 'Choose a password to finish.')),
        $error, function () use ($inv, $signed) { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <?php if ($signed): ?>
    <button class="btn primary">Join <?= h($inv['org_name']) ?></button>
    <?php elseif ($inv['pass_hash']): ?>
    <input type="email" name="username" value="<?= h($inv['email']) ?>" autocomplete="username" hidden>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary">Sign in and join</button>
    <?php else: ?>
    <label>Your name, as the team will see it<input name="name" required maxlength="60" value="<?= h($_POST['name'] ?? $inv['name']) ?>" autocomplete="name"></label>
    <input type="email" name="username" value="<?= h($inv['email']) ?>" autocomplete="username" hidden>
    <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary">Join</button>
    <?php endif; ?>
  </form>
<?php }, $inv['pass_hash'] && !$signed ? '<a href="?forgot=1">Forgot your password?</a>' : '');
}

/* ---------- Password reset links ---------- */
if (isset($_GET['reset'])) {
    $u = user_by_token((string)$_GET['reset']);
    if (!$u) {
        gate_page('Link expired · ' . $cfg['app_name'], 'This link doesn’t work any more',
            'It may have been used already, or it expired. Ask for a new one with “Forgot your password?”.', '', function () { ?>
  <p><a class="btn" href="?forgot=1">Get a new link</a> <a class="btn ghost" href="./">Sign in</a></p>
<?php });
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw = (string)($_POST['password'] ?? '');
        if (!csrf_ok()) {
            $error = 'The form expired. Try again.';
        } elseif ($p = password_problem($pw, (string)($_POST['password2'] ?? ''))) {
            $error = $p;
        } else {
            set_password((int)$u['id'], $pw);
            sign_in_session(user_by_id((int)$u['id']));
            db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now_iso(), $u['id']]);
            header('Location: ./');
            exit;
        }
    }
    gate_page('New password · ' . $cfg['app_name'], 'Choose a new password',
        'For <b>' . h($u['email']) . '</b>. This signs you out everywhere else.', $error, function () use ($u) { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <input type="email" name="username" value="<?= h($u['email']) ?>" autocomplete="username" hidden>
    <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary">Save password</button>
  </form>
<?php });
}

/* ---------- Forgot your password ---------- */
if (isset($_GET['forgot'])) {
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        slow_down();
        if (!csrf_ok()) {
            $error = 'The form expired. Try again.';
        } else {
            // The same answer whether or not there's an account, so the form can't be used to find out who has one.
            $u = user_by_email(post('email'));
            if ($u && $u['status'] === 'active' && $u['pass_hash'] && mail_configured()) {
                try {
                    mail_account_link($u, 'reset', app_base_url() . '?reset=' . issue_token((int)$u['id']), RESET_HOURS . ' hours', null);
                } catch (MailError $e) {
                    error_log('[previouslynoted] mail: ' . $e->getMessage());
                }
            }
            $sent = true;
        }
    }
    gate_page('Forgot your password · ' . $cfg['app_name'], 'Forgot your password?',
        $sent ? 'If there’s an account with that email, a link to choose a new password is on its way. It works for ' . RESET_HOURS . ' hours. Check your spam folder too.'
            : (mail_configured() ? 'Enter your email and we’ll send you a link to choose a new password.'
                : 'This server can’t send email yet, so ask Previously Noted support for a reset link.'),
        $error, function () use ($sent) {
            if ($sent || !mail_configured()) { ?>
  <p><a class="btn" href="./">Back to sign in</a></p>
<?php return; } ?>
  <form method="post">
    <?php csrf_field(); ?>
    <label>Email<input type="email" name="email" required value="<?= h($_POST['email'] ?? $_COOKIE['prevnoted_email'] ?? '') ?>" autocomplete="username"></label>
    <button class="btn primary">Send the link</button>
  </form>
<?php }, '<a href="./">Back to sign in</a>');
}

/* ---------- Sign in ---------- */
if (!current_account()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        slow_down();
        if (!csrf_ok()) {
            $error = 'The sign-in form expired. Try again.';
        } else {
            $r = attempt_login(post('email'), (string)($_POST['password'] ?? ''));
            if (is_string($r)) {
                sleep(1);
                $error = $r;
            } else {
                $_SESSION['attempts'] = 0;
                sign_in_session($r);
                setcookie('prevnoted_email', $r['email'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => is_https(), 'httponly' => true]);
                header('Location: ./');
                exit;
            }
        }
    }
    gate_page('Sign in · ' . $cfg['app_name'], $cfg['app_name'], '', $error, function () { ?>
  <form method="post">
    <?php csrf_field(); ?>
    <label>Email<input type="email" name="email" required value="<?= h($_POST['email'] ?? $_COOKIE['prevnoted_email'] ?? '') ?>" autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary">Sign in</button>
  </form>
<?php }, '<a href="?forgot=1">Forgot your password?</a> No account? Ask your company admin for an invite.');
}

/* ---------- The app ---------- */
$me = current_account();
$real = real_account();
Ctx::$user = $me;
Ctx::$real = $real;
// The production to open: the one asked for, else the last one, else the first this person can open.
$mine = user_workspaces($me, $real);
$cur = null;
foreach ([$_GET['ws'] ?? null, $me['last_ws']] as $want) {
    if (!$cur && is_numeric($want) && ($w = ws_row((int)$want)) && ($o = org_row((int)$w['org_id'])) && ($r = ws_role($me, $w, $o, $real))) {
        $cur = ['id' => (int)$w['id'], 'name' => $w['name'], 'status' => $w['status'], 'org_id' => (int)$o['id'], 'org_name' => $o['name'], 'role' => $r];
    }
}
$cur ??= $mine[0] ?? null;
if ($cur && !in_array($cur['id'], array_column($mine, 'id'), true)) {
    $mine[] = $cur; // staff looking at someone else's production
}
if ($cur && (int)$me['last_ws'] !== $cur['id'] && (int)$me['id'] === (int)$real['id']) {
    db()->prepare('UPDATE users SET last_ws = ? WHERE id = ?')->execute([$cur['id'], $me['id']]);
}
$power = $cur ? org_power($me, org_row($cur['org_id']), $real) : null;
$staff = (int)$me['id'] === (int)$real['id'] ? ($real['platform_role'] ?? '') : '';
$roleLabel = $cur ? (in_array($power, ['owner', 'admin'], true) ? ORG_ROLE_LABELS[$power] : ROLE_LABELS[$cur['role']]) : '';
page_head(($cur ? $cur['name'] . ' · ' : '') . $cfg['app_name']); ?>
<body class="app" data-csrf="<?= h($_SESSION['csrf']) ?>" data-role="<?= h($cur['role'] ?? '') ?>" data-uid="<?= (int)$me['id'] ?>"
  data-ws="<?= (int)($cur['id'] ?? 0) ?>" data-company-role="<?= h((string)$power) ?>" data-platform="<?= h($staff) ?>"<?= $cur && $cur['status'] === 'archived' ? ' data-archived=""' : '' ?>>
<?php if ((int)$me['id'] !== (int)$real['id']): ?>
<div class="imp-bar" role="status">You’re seeing the app as <b><?= h($me['name']) ?></b> (<?= h($me['email']) ?>)<?= is_platform($real, 'platform_admin') ? '' : ', read-only' ?>. Everything you do is in the company’s audit log.
  <button type="button" class="btn" id="impStop">Stop</button></div>
<?php endif; ?>
<a class="skip" href="#notes">Skip to the notes list</a>
<header class="top">
  <h1 class="sr-only"><?= h($cfg['app_name']) ?></h1>
  <a class="brand" href="./" title="<?= h($cfg['app_name']) ?>"><?= brand_mark(28) ?><span><?= h($cfg['app_name']) ?></span></a>
  <div class="pickers">
    <?php if (count($mine) > 1 || in_array($power, ['owner', 'admin'], true)): ?>
    <select id="wsSelect" aria-label="Production" title="Production">
      <?php foreach (array_unique(array_column($mine, 'org_name')) as $orgName): ?>
      <optgroup label="<?= h($orgName) ?>">
        <?php foreach ($mine as $w): if ($w['org_name'] !== $orgName) { continue; } ?>
        <option value="<?= $w['id'] ?>"<?= $cur && $w['id'] === $cur['id'] ? ' selected' : '' ?>><?= h($w['name'] . ($w['status'] === 'archived' ? ' (archived)' : '')) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select id="filmSelect" aria-label="Film"></select>
    <span class="ver-pick"><span class="ver-swatch" id="verSwatch" aria-hidden="true"></span><select id="versionSelect" aria-label="Version"></select></span>
    <details class="pop" id="manageMenu">
      <summary class="btn ghost" title="Films, versions, tracks and Frame.io">Manage</summary>
      <div class="menu-pop" role="menu">
        <button type="button" role="menuitem" id="syncBtn" hidden>Sync comments from Frame.io</button>
        <p class="menu-note" id="syncNote" hidden></p>
        <button type="button" role="menuitem" id="manageBtn">Films &amp; versions…</button>
        <button type="button" role="menuitem" id="tracksBtn">Tracks…</button>
      </div>
    </details>
  </div>
  <div class="views" id="views" role="group" aria-label="View">
    <button type="button" data-view="review" title="Review: work through the notes (1)">Review</button>
    <button type="button" data-view="script" title="Script: transcript, notes and new wording side by side (2)">Script</button>
    <button type="button" data-view="playback" title="Playback: big picture with the notes shown as they come up (3)">Playback</button>
  </div>
  <div class="tally" id="tally" role="group" aria-label="Show notes by status"></div>
  <details class="who" id="whoMenu">
    <summary title="Your account" data-initial="<?= h(mb_strtoupper(mb_substr($me['name'], 0, 1))) ?>"><span class="me-name"><?= h($me['name']) ?></span> <?php if ($roleLabel): ?><span class="role-tag"><?= h($roleLabel) ?></span><?php endif; ?></summary>
    <div class="menu-pop" role="menu">
      <?php if ($cur): ?><p class="menu-note"><?= h($cur['org_name']) ?> · <?= h($cur['name']) ?></p><?php endif; ?>
      <button type="button" role="menuitem" id="accountBtn">Your account and password</button>
      <?php if ($cur && $cur['role'] === 'admin'): ?>
      <button type="button" role="menuitem" id="peopleBtn">People</button>
      <?php elseif ($cur && $cur['role'] === 'editor'): ?>
      <button type="button" role="menuitem" id="inviteBtn">Invite someone</button>
      <?php endif; ?>
      <?php if ($cur && ($power === 'support' || org_power_at_least($power, 'admin'))): ?>
      <button type="button" role="menuitem" id="companyBtn">Company and productions</button>
      <button type="button" role="menuitem" id="settingsBtn">Settings and connections</button>
      <?php endif; ?>
      <?php if ($staff): ?>
      <button type="button" role="menuitem" id="platformBtn">All companies (staff)</button>
      <?php endif; ?>
      <button type="button" role="menuitem" id="helpBtn">Help and shortcuts <kbd>?</kbd></button>
      <a role="menuitem" href="?signout=1">Sign out</a>
    </div>
  </details>
</header>

<main class="workspace" id="workspace">
  <section class="stage">
    <div class="screen" id="screen">
      <video id="video" playsinline preload="auto"></video>
      <div class="screen-empty" id="screenEmpty">
        <p>Open the video for this version to play it with the notes.</p>
        <div class="screen-actions">
          <button class="btn primary" type="button" id="streamBtn" hidden>Stream from Frame.io</button>
          <label class="btn primary" id="openFileBtn">Open a video file<input type="file" id="fileInput" accept="video/*,.mp4,.mov,.m4v" hidden></label>
          <button class="btn" type="button" id="urlBtn">Use a video link</button>
        </div>
        <p class="screen-why" id="screenWhy" role="alert" hidden></p>
        <p class="hint">The file plays from your computer and is never uploaded. Download the version from Frame.io first.</p>
        <div class="screen-actions">
          <button class="btn ghost" type="button" id="removeLinkBtn" hidden>Remove the saved link</button>
          <button class="btn ghost" type="button" id="keepVideoBtn" hidden>Keep the current video</button>
        </div>
      </div>
      <div class="screen-notes" id="screenNotes" aria-hidden="true"></div>
      <button class="btn change-video" type="button" id="changeVideoBtn" title="Open a different video for this version">Change video</button>
    </div>
    <div class="split split-h" id="splitVideo" role="separator" aria-orientation="horizontal" aria-label="Resize the picture. Double-click to reset." title="Drag to resize the picture · double-click to reset" tabindex="0"></div>

    <div class="transport">
      <button class="btn icon play" id="playBtn" type="button" aria-label="Play (space)" title="Play or pause (space). J / K / L shuttle backwards, stop, forwards."><svg class="i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path class="i-play" d="M8 5.5v13l10.5-6.5z"/><g class="i-pause"><rect x="6.5" y="5.5" width="4" height="13" rx="1"/><rect x="13.5" y="5.5" width="4" height="13" rx="1"/></g></svg></button>
      <span class="tc" id="tcNow">00:00:00:00</span>
      <button class="btn icon" id="prevNote" type="button" aria-label="Previous note (P)" title="Previous note (P)"><svg class="i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 5.5v13M18 6.5 9.5 12l8.5 5.5z"/></svg></button>
      <button class="btn icon" id="nextNote" type="button" aria-label="Next note (N)" title="Next note (N)"><svg class="i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 5.5v13M6 6.5 14.5 12 6 17.5z"/></svg></button>
      <span class="spacer"></span>
      <details class="pop up" id="playMenu">
        <summary class="btn ghost" title="Playback speed and quality"><span id="speedLbl">1×</span></summary>
        <div class="menu-pop form">
          <label>Speed <small>(J / K / L shuttle too)</small>
            <select id="speed" aria-label="Playback speed">
              <option value="-8">8× back</option><option value="-4">4× back</option><option value="-2">2× back</option><option value="-1">1× back</option>
              <option value="0.5">0.5×</option><option value="1" selected>1×</option><option value="1.5">1.5×</option><option value="2">2×</option><option value="4">4×</option><option value="8">8×</option>
            </select></label>
          <label id="qualityRow" hidden>Frame.io quality
            <select id="quality" aria-label="Frame.io playback quality">
              <option value="efficient">Efficient</option><option value="high">High quality</option><option value="original">Original</option>
            </select></label>
        </div>
      </details>
      <button class="btn" id="addBtn" type="button" title="Add a note at the playhead (M)"><svg class="i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 6v12M6 12h12"/></svg><span class="lbl">Add note here</span></button>
    </div>

    <p class="caption" id="caption" aria-live="off" hidden></p>
    <div class="banner tc-fix" id="tcFix" role="status" hidden></div>

    <section class="tl" id="tl" aria-label="Notes along the cut">
      <div class="tl-bar">
        <span class="tl-range" id="tlRange"></span>
        <span class="spacer"></span>
        <button class="btn icon" id="zoomOut" type="button" aria-label="Zoom out" title="Zoom out (− or ⌘/Ctrl −)"><svg class="i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 12h12"/></svg></button>
        <input type="range" id="zoom" min="0" max="1000" value="0" aria-label="Zoom">
        <button class="btn icon" id="zoomIn" type="button" aria-label="Zoom in" title="Zoom in (+ or ⌘/Ctrl +)"><svg class="i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 6v12M6 12h12"/></svg></button>
        <button class="btn" id="zoomFit" type="button" title="Show the whole cut (0)">Fit</button>
      </div>
      <div class="tl-body">
        <div class="tl-gutter" id="tlGutter" role="group" aria-label="Tracks: show or hide"></div>
        <div class="tl-view" id="tlView" title="Drag the ruler to scrub · scroll to zoom · drag to move">
          <div class="tl-scale" id="tlScale"></div>
          <div class="tl-shots" id="tlShots"></div>
          <div class="tl-lanes" id="tlLanes"></div>
          <div class="tl-head" id="tlHead"></div>
        </div>
      </div>
      <div class="tl-over" id="tlOver" title="The whole cut. Drag to move the zoomed view.">
        <div class="tl-over-marks" id="tlOverMarks"></div>
        <div class="tl-win" id="tlWin"></div>
        <div class="tl-over-head" id="tlOverHead"></div>
      </div>
    </section>

    <article class="now" id="nowCard" aria-live="polite"></article>
  </section>

  <div class="split split-v" id="splitSide" role="separator" aria-orientation="vertical" aria-label="Resize the sidebar. Double-click to reset." title="Drag to resize the sidebar · double-click to reset" tabindex="0"></div>

  <aside class="list">
    <div class="tabs" role="tablist" aria-label="Sidebar">
      <button type="button" role="tab" id="tabNotes" aria-controls="panelNotes" aria-selected="true">Notes <span class="n" id="tabNotesN"></span></button>
      <button type="button" role="tab" id="tabScript" aria-controls="panelScript" aria-selected="false">Transcript <span class="n" id="tabScriptN"></span></button>
    </div>
    <div class="panel" id="panelNotes" role="tabpanel" aria-labelledby="tabNotes">
    <div class="filters">
      <input type="search" id="search" placeholder="Search notes" aria-label="Search notes">
      <div class="banner codes-fix" id="codesFix" role="status" hidden></div>
        <div class="chips" id="tagChips" role="group" aria-label="Filter by type"></div>
      <div class="filter-row">
        <select id="authorFilter" aria-label="Filter by author"><option value="">Everyone</option></select>
        <label class="follow"><input type="checkbox" id="follow" checked> Follow playback</label>
      </div>
      <button type="button" class="place-queue" id="placeQueue" hidden></button>
    </div>
    <ol class="notes" id="notes" tabindex="-1" aria-label="Notes"></ol>
    </div>
    <div class="panel" id="panelScript" role="tabpanel" aria-labelledby="tabScript" hidden>
      <div class="filters">
        <input type="search" id="scriptSearch" placeholder="Search the transcript" aria-label="Search the transcript">
        <details class="script-setup" id="scriptSetup">
          <summary id="scriptSetupSum">Transcript files</summary>
          <div class="script-setup-body">
            <div class="filter-row">
              <select id="scriptUpload" aria-label="Transcript upload"></select>
              <label class="btn">Upload…<input type="file" id="scriptFile" accept=".srt,.vtt,.txt,text/plain,text/vtt" hidden></label>
            </div>
            <button class="btn" type="button" id="transcribeBtn" hidden>Transcribe this cut with ElevenLabs</button>
          </div>
        </details>
        <p class="job" id="jobLine" hidden></p>
        <div class="script-modes">
          <button class="btn" type="button" id="scriptWideBtn" aria-pressed="false" title="Current transcript, notes and the new script side by side">Script view</button>
          <span class="script-dl" id="scriptDl" hidden>Download <a id="dlTxt" href="#">recording script</a> · <a id="dlCsv" href="#">full script (CSV)</a></span>
        </div>
        <div class="filter-row checks">
          <label class="follow"><input type="checkbox" id="scriptFollow" checked> Follow playback</label>
          <label class="follow" id="scriptChangesWrap"><input type="checkbox" id="scriptChanges" checked> <span id="scriptChangesLabel">Show changes</span></label>
        </div>
        <p class="script-note" id="scriptNote" hidden></p>
      </div>
      <details class="script-gone" id="scriptGone" hidden><summary></summary><ul></ul></details>
      <ol class="script" id="script"></ol>
    </div>
  </aside>
</main>

<dialog id="dlg"></dialog>
<div class="toast" id="toast" role="status"></div>

<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>

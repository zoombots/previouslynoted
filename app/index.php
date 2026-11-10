<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
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

/* ---------- First run: create the admin account ---------- */
// Also when setup stopped part-way: settings saved but no account and no old team password to upgrade from.
if ($cfg && empty($cfg['password_hash']) && extension_loaded('pdo_sqlite') && user_count() === 0) {
    $cfg = null;
}
if (!$cfg) {
    $problems = [];
    if (PHP_VERSION_ID < 80100) {
        $problems[] = 'PHP 8.1 or newer is needed (this server runs ' . PHP_VERSION . '). Change it in cPanel → Select PHP Version.';
    }
    if (!extension_loaded('pdo_sqlite')) {
        $problems[] = 'The pdo_sqlite extension is off. Turn it on in cPanel → Select PHP Version → Extensions.';
    }
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0750, true) || !is_writable(DATA_DIR)) {
        $problems[] = 'The data folder isn’t writable. In File Manager, set the "data" folder permissions to 755.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$problems) {
        $pw = (string)($_POST['password'] ?? '');
        $error = password_problem($pw, (string)($_POST['password2'] ?? '')) ?? '';
        if (!$error) {
            try {
                write_config(['app_name' => mb_substr(post('app_name') ?: 'Previously Noted', 0, 60), 'created_at' => now_iso()]);
                // Recovery: whoever can delete data/config.php controls the server, so an existing
                // account with this email gets the new password and becomes an admin.
                $old = user_by_email(post('email'));
                if ($old) {
                    set_password((int)$old['id'], $pw);
                    db()->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE id = ?")->execute([$old['id']]);
                    $id = (int)$old['id'];
                } else {
                    $id = create_user(post('name'), post('email'), 'admin', $pw);
                }
                start_session();
                sign_in_session(user_by_id($id));
                header('Location: ./');
                exit;
            } catch (RuntimeException $e) {
                @unlink(CONFIG_FILE);
                $error = $e->getMessage();
            }
        }
    }
    gate_page('Set up Previously Noted', 'Set up Previously Noted',
        'Create the first account. You’ll be the admin, and you can invite the rest of the team from <b>People</b> once you’re in.',
        $error . implode(' ', $problems), function () use ($problems) { ?>
  <form method="post" autocomplete="off">
    <label>Name shown in the header<input name="app_name" value="<?= h($_POST['app_name'] ?? 'Previously Noted') ?>" maxlength="60"></label>
    <label>Your name<input name="name" required maxlength="60" value="<?= h($_POST['name'] ?? '') ?>" autocomplete="name"></label>
    <label>Your email<input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" autocomplete="email"></label>
    <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary" <?= $problems ? 'disabled' : '' ?>>Finish setup</button>
  </form>
<?php });
}

start_session();
db(); // run migrations, including the people table

/* ---------- Sign out ---------- */
if (isset($_GET['signout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

/* ---------- Invite and reset links ---------- */
$token = (string)($_GET['invite'] ?? $_GET['reset'] ?? '');
if ($token !== '') {
    $u = user_by_token($token);
    if (!$u) {
        gate_page('Link expired · ' . $cfg['app_name'], 'This link doesn’t work any more',
            'It may have been used already, or it expired. Ask an admin for a new one.', '', function () { ?>
  <p><a class="btn" href="./">Go to sign in</a></p>
<?php });
    }
    $isInvite = $u['token_kind'] === 'invite';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw = (string)($_POST['password'] ?? '');
        if (!csrf_ok()) {
            $error = 'The form expired. Try again.';
        } elseif ($p = password_problem($pw, (string)($_POST['password2'] ?? ''))) {
            $error = $p;
        } else {
            $name = post('name');
            if ($isInvite && $name !== '' && mb_strlen($name) <= 60) {
                db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $u['id']]);
            }
            set_password((int)$u['id'], $pw);
            sign_in_session(user_by_id((int)$u['id']));
            db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now_iso(), $u['id']]);
            header('Location: ./');
            exit;
        }
    }
    gate_page(($isInvite ? 'Join ' : 'New password · ') . $cfg['app_name'],
        $isInvite ? 'Join ' . $cfg['app_name'] : 'Choose a new password',
        $isInvite
            ? 'You’ve been invited as <b>' . h(ROLE_LABELS[$u['role']] ?? $u['role']) . '</b> with <b>' . h($u['email']) . '</b>. Choose a password to finish.'
            : 'For <b>' . h($u['email']) . '</b>. This signs you out everywhere else.',
        $error, function () use ($u, $isInvite) { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <?php if ($isInvite): ?><label>Your name, as the team will see it<input name="name" required maxlength="60" value="<?= h($_POST['name'] ?? $u['name']) ?>" autocomplete="name"></label><?php endif; ?>
    <input type="email" name="username" value="<?= h($u['email']) ?>" autocomplete="username" hidden>
    <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary"><?= $isInvite ? 'Join' : 'Save password' ?></button>
  </form>
<?php });
}

/* ---------- Upgrading from the shared team password ---------- */
if (user_count() === 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        slow_down();
        $pw = (string)($_POST['password'] ?? '');
        if (!csrf_ok()) {
            $error = 'The form expired. Try again.';
        } elseif (empty($cfg['password_hash']) || !password_verify((string)($_POST['team_password'] ?? ''), $cfg['password_hash'])) {
            sleep(1);
            $error = 'That isn’t the current team password.';
        } elseif ($p = password_problem($pw, (string)($_POST['password2'] ?? ''))) {
            $error = $p;
        } else {
            try {
                $id = create_user(post('name'), post('email'), 'admin', $pw);
                unset($cfg['password_hash']);
                $cfg['accounts_since'] = now_iso();
                write_config($cfg);
                $_SESSION['attempts'] = 0;
                sign_in_session(user_by_id($id));
                header('Location: ./');
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    }
    gate_page('Create the admin account · ' . $cfg['app_name'], 'Everyone gets their own sign-in now',
        'Create the admin account first. Enter the team password one last time to prove it’s you; after this it stops working, and you invite everyone else from <b>People</b>. Names already in the notes history stay as they are.',
        $error, function () { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <label>Current team password<input type="password" name="team_password" required autocomplete="off"></label>
    <label>Your name<input name="name" required maxlength="60" value="<?= h($_POST['name'] ?? $_COOKIE['prevnoted_name'] ?? '') ?>" autocomplete="name"></label>
    <label>Your email<input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" autocomplete="username"></label>
    <label>New password for you<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn primary">Create admin account</button>
  </form>
<?php });
}

/* ---------- Share links: someone outside the team ---------- */
$share = null;
if (isset($_GET['share'])) {
    $share = share_by_token((string)$_GET['share']);
    if (!$share) {
        gate_page('Link expired · ' . $cfg['app_name'], 'This link doesn’t work any more',
            'It may have been turned off, or it expired. Ask whoever sent it for a new one.', '', function () {});
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = clean_guest_name(post('name'));
        if (!csrf_ok()) {
            $error = 'The form expired. Try again.';
        } elseif ($name === '') {
            $error = 'Enter your name, so the team knows who wrote your notes.';
        } else {
            share_set_guest_name($share, $name);
            setcookie('prevnoted_guest', $name, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => is_https(), 'httponly' => true]);
            header('Location: ?share=' . $share['token']);
            exit;
        }
    }
    if (share_guest_name($share) === null || isset($_GET['rename'])) {
        $what = SHARE_VIEWS[$share['view']] ?? 'Review';
        gate_page($share['film_name'] . ' · ' . $cfg['app_name'], $share['film_name'] . ' · ' . $share['version_label'],
            'You’ve been sent this cut to ' . ((int)$share['can_comment'] ? 'watch and leave notes on' : 'watch with the team’s notes')
            . ((int)$share['can_script'] ? ', and to suggest changes to the script' : '') . '. '
            . ((int)$share['can_comment'] || (int)$share['can_script'] ? 'What’s your name? It goes on everything you add, so the team knows who wrote it.' : 'What’s your name?'),
            $error, function () use ($share) { ?>
  <form method="post" autocomplete="off">
    <?php csrf_field(); ?>
    <label>Your name<input name="name" required maxlength="60" autofocus value="<?= h($_POST['name'] ?? share_guest_name($share) ?? $_COOKIE['prevnoted_guest'] ?? '') ?>" autocomplete="name"></label>
    <button class="btn primary">Open the cut</button>
  </form>
<?php }, 'No account needed. The link works only for this cut.');
    }
}

/* ---------- Sign in ---------- */
if (!$share && !current_account()) {
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
<?php }, 'Forgot your password, or don’t have an account? Ask your admin for a link.');
}

/* ---------- The app ---------- */
$me = $share ? guest_account($share, share_guest_name($share)) : current_account();
page_head($share ? $share['film_name'] . ' · ' . $share['version_label'] : $cfg['app_name']); ?>
<body class="app<?= $share ? ' guest' : '' ?>" data-csrf="<?= h($_SESSION['csrf']) ?>" data-role="<?= h($me['role']) ?>" data-uid="<?= (int)$me['id'] ?>"<?php if ($share): ?>
  data-share="<?= h($share['token']) ?>" data-share-view="<?= h($share['view']) ?>"<?= (int)$share['can_comment'] ? ' data-share-comment=""' : '' ?><?= (int)$share['can_script'] ? ' data-share-script=""' : '' ?><?php endif; ?>>
<a class="skip" href="#notes">Skip to the notes list</a>
<header class="top">
  <h1 class="sr-only"><?= h($cfg['app_name']) ?></h1>
  <a class="brand" href="./" title="<?= h($cfg['app_name']) ?>"><?= brand_mark(28) ?><span><?= h($cfg['app_name']) ?></span></a>
  <div class="pickers">
    <label class="pick"><span class="pick-lbl">Film</span><select id="filmSelect"></select></label>
    <label class="pick"><span class="pick-lbl">Version</span><span class="ver-pick"><span class="ver-swatch" id="verSwatch" aria-hidden="true"></span><select id="versionSelect"></select></span></label>
  </div>
  <div class="views" id="views" role="group" aria-label="View"<?= $share ? ' hidden' : '' ?>>
    <button type="button" data-view="review" title="Review: work through the notes (1)">Review</button>
    <button type="button" data-view="script" title="Script: transcript, notes and new wording side by side (2)">Script</button>
    <button type="button" data-view="playback" title="Playback: big picture with the notes shown as they come up (3)">Playback</button>
  </div>
  <?php if (!$share && role_at_least($me, 'editor')): ?>
  <button type="button" class="btn ghost" id="shareBtn" title="Send this cut to someone outside the team">Share</button>
  <?php endif; ?>
  <div class="tally" id="tally" role="group" aria-label="Show notes by status"></div>
  <?php if ($share): ?>
  <details class="who" id="whoMenu">
    <summary title="You" data-initial="<?= h(mb_strtoupper(mb_substr($me['name'], 0, 1))) ?>"><span class="me-name"><?= h($me['name']) ?></span> <span class="role-tag">Guest</span></summary>
    <div class="menu-pop" role="menu">
      <p class="menu-note">You’re a guest on <?= h($share['film_name'] . ' · ' . $share['version_label']) ?>. <?= (int)$share['can_comment'] ? 'Your notes and replies show your name.' : 'You can watch and read the notes.' ?><?= (int)$share['can_script'] ? ' In the script you can suggest new wording (a draft for the team), cross lines out, and delete lines you added.' : '' ?></p>
      <a role="menuitem" href="?share=<?= h($share['token']) ?>&amp;rename=1">Change your name</a>
      <button type="button" role="menuitem" id="helpBtn">Help and shortcuts <kbd>?</kbd></button>
    </div>
  </details>
  <?php else: ?>
  <details class="who" id="whoMenu">
    <summary title="Your account" data-initial="<?= h(mb_strtoupper(mb_substr($me['name'], 0, 1))) ?>"><span class="me-name"><?= h($me['name']) ?></span> <span class="role-tag"><?= h(ROLE_LABELS[$me['role']]) ?></span></summary>
    <div class="menu-pop" role="menu">
      <button type="button" role="menuitem" id="accountBtn">Your account and password</button>
      <?php if ($me['role'] === 'admin'): ?>
      <button type="button" role="menuitem" id="peopleBtn">People</button>
      <button type="button" role="menuitem" id="settingsBtn">Settings and connections</button>
      <?php endif; ?>
      <button type="button" role="menuitem" id="helpBtn">Help and shortcuts <kbd>?</kbd></button>
      <a role="menuitem" href="?signout=1">Sign out</a>
    </div>
  </details>
  <?php endif; ?>
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
      <button class="btn ghost" id="tracksBtn" type="button" title="Add, rename, recolour and reorder tracks"><span class="lbl">Tracks…</span></button>
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
          <div class="tl-script" id="tlScript" hidden></div>
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
          <button type="button" class="btn ghost" id="scriptDl" hidden>Download script…</button>
        </div>
        <div class="filter-row checks">
          <label class="follow"><input type="checkbox" id="scriptFollow" checked> Follow playback</label>
          <label class="follow" id="scriptChangesWrap"><input type="checkbox" id="scriptChanges" checked> <span id="scriptChangesLabel">Show changes</span></label>
          <label class="follow" id="scriptCutWrap" hidden><input type="checkbox" id="scriptShowCut" checked> <span id="scriptCutLabel">Show crossed-out lines</span></label>
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

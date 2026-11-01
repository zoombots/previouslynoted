<?php
/*
 * Previously Noted — one-file installer.
 *
 * Upload this file to the folder your domain points to (usually public_html),
 * then open https://yourdomain/install.php in a browser.
 * It installs the app, creates the first admin account, checks that the data
 * folder is private, and deletes itself when it's done.
 *
 * Running it again on an existing install upgrades the app files and keeps
 * every note. That needs an admin's email and password (or, for installs
 * from before 0.6, the old team password).
 */
declare(strict_types=1);

const INST_APP = 'Previously Noted';
const INST_VERSION = '{{VERSION}}';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

session_name('prevnoted_install');
session_start();
if (empty($_SESSION['inst_csrf'])) {
    $_SESSION['inst_csrf'] = bin2hex(random_bytes(32));
}

function inst_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inst_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function inst_payload(): array
{
    $raw = file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__);
    $files = json_decode((string)base64_decode((string)preg_replace('/\s+/', '', (string)$raw), true), true);
    if (!is_array($files) || !isset($files['index.php'])) {
        throw new RuntimeException('This installer file is damaged. Download it again and upload it in binary mode.');
    }
    return $files;
}

/** Install into this folder, or a subfolder of it. */
function inst_target(string $sub): string
{
    return $sub === '' ? __DIR__ : __DIR__ . '/' . $sub;
}

function inst_web_path(string $sub): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $base . '/' . ($sub === '' ? '' : $sub . '/');
}

/** Admin accounts in an existing install, or null if it predates accounts. */
function inst_admins(string $target): ?array
{
    $db = $target . '/data/notes.sqlite';
    if (!is_file($db) || !extension_loaded('pdo_sqlite')) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn()) {
            return null;
        }
        $rows = $pdo->query("SELECT email, pass_hash FROM users WHERE role = 'admin' AND status = 'active' AND pass_hash IS NOT NULL")->fetchAll();
        return $rows ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function inst_existing_config(string $target): ?array
{
    $f = $target . '/data/config.php';
    if (!is_file($f)) {
        return null;
    }
    $cfg = (static fn() => require $f)();
    return is_array($cfg) ? $cfg : null;
}

function inst_checks(string $target): array
{
    $c = [];
    $c[] = PHP_VERSION_ID >= 80100
        ? ['ok', 'PHP ' . PHP_VERSION]
        : ['fail', 'PHP ' . PHP_VERSION . ' is too old. In cPanel → Select PHP Version, choose 8.2 or newer and click "Set as current".'];
    $c[] = extension_loaded('pdo_sqlite')
        ? ['ok', 'SQLite database support (pdo_sqlite)']
        : ['fail', 'SQLite support is off. In cPanel → Select PHP Version → Extensions, tick pdo_sqlite and sqlite3.'];
    $c[] = extension_loaded('json') || function_exists('json_encode')
        ? ['ok', 'JSON support']
        : ['fail', 'JSON support is off. Tick json in cPanel → Select PHP Version → Extensions.'];
    $c[] = extension_loaded('curl')
        ? ['ok', 'Outgoing connections (curl), for Frame.io and ElevenLabs']
        : ['warn', 'The curl extension is off, so Frame.io and ElevenLabs can’t be connected. Tick curl in cPanel → Select PHP Version → Extensions.'];
    $c[] = extension_loaded('mbstring')
        ? ['ok', 'Multibyte text support (mbstring)']
        : ['warn', 'mbstring is off. The app works without it, but turning it on in Select PHP Version → Extensions is better for accented names.'];

    $probeDir = is_dir($target) ? $target : dirname($target);
    $c[] = is_writable($probeDir)
        ? ['ok', 'The install folder is writable']
        : ['fail', 'PHP can’t write to ' . $probeDir . '. In File Manager, set that folder’s permissions to 755.'];

    $c[] = inst_https()
        ? ['ok', 'Secure connection (HTTPS)']
        : ['warn', 'This page isn’t on HTTPS, so passwords would travel unencrypted. In cPanel → SSL/TLS Status, run AutoSSL for this domain, then open this installer with https://.'];

    if (!inst_existing_config($target)) {
        $clash = [];
        foreach (['index.php', 'index.html', 'default.html'] as $f) {
            if (is_file($target . '/' . $f)) {
                $clash[] = $f;
            }
        }
        if ($clash) {
            $c[] = ['warn', 'This folder already has ' . implode(', ', $clash) . '. The installer replaces index.php; any index.html or default.html is renamed so it doesn’t show instead of the app.'];
        }
        if (is_file($target . '/.htaccess')) {
            $c[] = ['warn', 'There’s already an .htaccess file here. It will be saved as .htaccess.before-previously-noted and replaced. If another site lives in this folder, install into a subfolder instead.'];
        }
    }
    return $c;
}

function inst_write_files(string $target, array $files, bool $upgrade): array
{
    $log = [];
    if (!is_dir($target) && !mkdir($target, 0755, true)) {
        throw new RuntimeException('Couldn’t create the folder ' . $target . '.');
    }
    if (!$upgrade) {
        $stamp = date('Ymd-His');
        if (is_file($target . '/.htaccess')) {
            rename($target . '/.htaccess', $target . '/.htaccess.before-previously-noted-' . $stamp);
            $log[] = 'Saved the existing .htaccess as .htaccess.before-previously-noted-' . $stamp;
        }
        foreach (['index.html', 'default.html'] as $f) {
            if (is_file($target . '/' . $f)) {
                rename($target . '/' . $f, $target . '/' . $f . '.before-previously-noted');
                $log[] = 'Renamed ' . $f . ' so it doesn’t show instead of the app';
            }
        }
    }
    $n = 0;
    foreach ($files as $rel => $b64) {
        if (!preg_match('#^[A-Za-z0-9_.\-/]+$#', $rel) || str_contains($rel, '..')) {
            continue;
        }
        // Never overwrite the team's data on an upgrade.
        if ($upgrade && str_starts_with($rel, 'data/') && $rel !== 'data/.htaccess') {
            continue;
        }
        $path = $target . '/' . $rel;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Couldn’t create the folder ' . $dir . '.');
        }
        if (file_put_contents($path, base64_decode($b64, true), LOCK_EX) === false) {
            throw new RuntimeException('Couldn’t write ' . $rel . '. Check the folder permissions (755) and free disk space.');
        }
        @chmod($path, 0644);
        $n++;
    }
    if (!is_dir($target . '/data')) {
        mkdir($target . '/data', 0755, true);
    }
    $log[] = ($upgrade ? 'Updated ' : 'Installed ') . $n . ' app files';
    return $log;
}

/** Ask the web server for a file inside data/. It must refuse. */
function inst_probe_private(string $target, string $sub): string
{
    $probe = $target . '/data/probe-' . bin2hex(random_bytes(6)) . '.txt';
    if (file_put_contents($probe, 'previously-noted-probe') === false) {
        return 'unknown';
    }
    $url = (inst_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . inst_web_path($sub) . 'data/' . basename($probe);
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "User-Agent: previously-noted-installer\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    @unlink($probe);
    if ($body === false) {
        return 'unknown';
    }
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($status === 200 && str_contains($body, 'previously-noted-probe')) {
        return 'exposed';
    }
    return $status >= 400 ? 'private' : 'unknown';
}

/* ---------------------------------------------------------------- */

$sub = trim((string)($_POST['folder'] ?? $_GET['folder'] ?? ''), "/ \t");
$errors = [];
$done = null;
if ($sub !== '' && !preg_match('/^[a-z0-9][a-z0-9_\-]{0,40}$/i', $sub)) {
    $errors[] = 'Use only letters, numbers, dashes and underscores for the folder name.';
    $sub = '';
}
$target = inst_target($sub);
$existing = inst_existing_config($target);
$upgrade = $existing !== null;
$admins = $upgrade ? inst_admins($target) : null;
$checks = inst_checks($target);
$blocked = (bool)array_filter($checks, fn($c) => $c[0] === 'fail');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'install') {
    if (!hash_equals($_SESSION['inst_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'The form expired. Reload the page and try again.';
    } elseif ($blocked) {
        $errors[] = 'Fix the items marked in red first, then reload this page.';
    } else {
        $_SESSION['inst_attempts'] = ($_SESSION['inst_attempts'] ?? 0) + 1;
        if ($_SESSION['inst_attempts'] > 5) {
            sleep(min(10, $_SESSION['inst_attempts'] - 5));
        }
        $pw = (string)($_POST['password'] ?? '');
        $name = trim((string)($_POST['app_name'] ?? '')) ?: INST_APP;
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $person = trim((string)($_POST['person'] ?? ''));
        if ($upgrade && $admins) {
            $ok = false;
            foreach ($admins as $a) {
                if (hash_equals($a['email'], $email) && password_verify($pw, (string)$a['pass_hash'])) {
                    $ok = true;
                }
            }
            if (!$ok) {
                sleep(1);
                $errors[] = 'That isn’t an admin’s email and password, so the upgrade didn’t run.';
            }
        } elseif ($upgrade) {
            if (empty($existing['password_hash']) || !password_verify($pw, (string)$existing['password_hash'])) {
                sleep(1);
                $errors[] = 'That isn’t the current team password, so the upgrade didn’t run.';
            }
        } elseif ($person === '' || strlen($person) > 120) {
            $errors[] = 'Enter your name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address doesn’t look right.';
        } elseif (strlen($pw) < 10) {
            $errors[] = 'Use at least 10 characters for the password.';
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords don’t match.';
        }

        if (!$errors) {
            try {
                $log = inst_write_files($target, inst_payload(), $upgrade);
                if (!$upgrade) {
                    $cfg = [
                        'app_name' => function_exists('mb_substr') ? mb_substr($name, 0, 60) : substr($name, 0, 60),
                        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ];
                    $php = "<?php\n// Generated by the Previously Noted installer. Keep this file private.\nreturn " . var_export($cfg, true) . ";\n";
                    if (file_put_contents($target . '/data/config.php', $php, LOCK_EX) === false) {
                        throw new RuntimeException('Couldn’t save data/config.php.');
                    }
                    @chmod($target . '/data/config.php', 0640);
                    $log[] = 'Saved the settings file';
                }
                // Create or migrate the database through the app's own code.
                require $target . '/lib/bootstrap.php';
                db();
                $log[] = $upgrade ? 'Database checked and brought up to date; all notes kept' : 'Created the notes database';
                if (!$upgrade) {
                    create_user($person, $email, 'admin', $pw);
                    $log[] = 'Created your admin account (' . $email . '). The password is stored only as a secure hash';
                } elseif (!$admins) {
                    $log[] = 'This update adds individual accounts. When you open the app, enter the team password once more to create your admin account';
                }

                $privacy = inst_probe_private($target, $sub);
                $deleted = @unlink(__FILE__);
                $done = ['log' => $log, 'privacy' => $privacy, 'deleted' => $deleted, 'url' => inst_web_path($sub)];
                $_SESSION = [];
                session_destroy();
            } catch (Throwable $e) {
                if (!$upgrade) {
                    @unlink($target . '/data/config.php'); // so the next try starts fresh
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$scheme = inst_https() ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Install Previously Noted</title>
<link rel="icon" href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+CiAgPHJlY3Qgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIiByeD0iNyIgZmlsbD0iIzFjMjAyNSIvPgogIDxyZWN0IHg9IjEwIiB5PSI1IiB3aWR0aD0iMTgiIGhlaWdodD0iMTMiIHJ4PSIyLjUiIGZpbGw9IiM2YzczN2MiLz4KICA8cmVjdCB4PSI0IiB5PSIxMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjE2IiByeD0iMyIgZmlsbD0iI2ViZTZkYyIvPgogIDxwYXRoIGQ9Ik04IDEzIGgxMiBhMS41IDEuNSAwIDAgMSAxLjUgMS41IHY0IGExLjUgMS41IDAgMCAxIC0xLjUgMS41IGgtNCBsLTIgMyBsLTIgLTMgaC00IGExLjUgMS41IDAgMCAxIC0xLjUgLTEuNSB2LTQgYTEuNSAxLjUgMCAwIDEgMS41IC0xLjUgeiIgZmlsbD0iI2U2YTk0ZiIvPgo8L3N2Zz4K" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Mono:wght@400;600&family=Atkinson+Hyperlegible+Next:wght@400;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #1c2025; --panel: #242930; --panel-2: #2c323a; --line: #3a414a;
  --text: #ebe6dc; --muted: #9ba2aa; --ok: #74c7b4; --warn: #e6a94f; --fail: #ec8f7d; --focus: #f2d38a;
  color-scheme: dark;
}
@media (prefers-color-scheme: light) {
  :root { --bg: #eef0f2; --panel: #fff; --panel-2: #f4f5f7; --line: #d5d9de; --text: #1d2228; --muted: #59616b;
          --ok: #137c69; --warn: #a8650a; --fail: #b4402b; --focus: #9a6b12; color-scheme: light; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 "Atkinson Hyperlegible Next", system-ui, sans-serif; }
main { width: min(600px, 100%); margin: 0 auto; padding: 48px 16px 64px; }
.mark { display: block; margin: 0 0 20px; } .mark-tile { fill: #2c323a; }
@media (prefers-color-scheme: light) { .mark-tile { fill: #1c2025; } }
h1 { font-size: 32px; line-height: 1.15; margin: 0 0 8px; letter-spacing: -.015em; }
h2 { font-size: 17px; margin: 36px 0 12px; }
.lede { color: var(--muted); margin: 0 0 8px; }
.ver { font-family: "Atkinson Hyperlegible Mono", monospace; font-size: 13px; color: var(--muted); }
h1 + ul.checks { margin-top: 24px; }
ul.checks { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line); }
ul.checks li { display: grid; grid-template-columns: 22px 1fr; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.dot { width: 12px; height: 12px; border-radius: 50%; margin-top: 6px; background: var(--c); }
.ok { --c: var(--ok); } .warn { --c: var(--warn); } .fail { --c: var(--fail); }
.fail span:last-child { color: var(--fail); }
form { display: grid; gap: 16px; }
label { display: grid; gap: 6px; color: var(--muted); font-size: 14px; }
input { font: inherit; color: var(--text); background: var(--panel-2); border: 1px solid var(--line); border-radius: 4px; min-height: 44px; padding: 6px 12px; width: 100%; }
.prefix { display: flex; align-items: center; gap: 0; }
.prefix span { font-family: "Atkinson Hyperlegible Mono", monospace; font-size: 14px; color: var(--muted); padding: 0 10px; min-height: 44px; display: flex; align-items: center; background: var(--panel); border: 1px solid var(--line); border-right: 0; border-radius: 4px 0 0 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 55%; }
.prefix input { border-radius: 0 4px 4px 0; }
.hint { font-size: 13px; color: var(--muted); margin: -8px 0 0; }
button, .btn { font: inherit; font-weight: 700; min-height: 46px; border-radius: 4px; border: 1px solid var(--text); background: var(--text); color: var(--bg); cursor: pointer; padding: 0 20px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
button[disabled] { opacity: .4; cursor: not-allowed; }
:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.err { border-left: 4px solid var(--fail); background: var(--panel); padding: 10px 14px; margin: 0 0 16px; border-radius: 0 4px 4px 0; }
.note { border-left: 4px solid var(--c, var(--line)); background: var(--panel); padding: 12px 16px; border-radius: 0 4px 4px 0; margin: 16px 0; }
.note p { margin: 0 0 8px; } .note p:last-child { margin: 0; }
.mode { display: inline-block; border: 1px solid var(--line); border-radius: 4px; padding: 2px 8px; font-size: 13px; color: var(--muted); }
details summary { cursor: pointer; color: var(--muted); font-size: 14px; }
a { color: inherit; }
</style>
</head>
<body>
<main>
  <svg class="mark" width="56" height="56" viewBox="0 0 512 512" aria-hidden="true"><rect width="512" height="512" rx="112" class="mark-tile"/><rect x="150" y="90" width="270" height="198" rx="32" fill="none" stroke="#7b828b" stroke-width="26"/><rect x="92" y="164" width="300" height="222" rx="36" fill="#ebe6dc"/><rect x="134" y="322" width="216" height="20" rx="10" fill="#1c2025" opacity=".2"/><path d="M186 204h112a28 28 0 0 1 28 28v38a28 28 0 0 1-28 28h-38l-18 34-18-34h-38a28 28 0 0 1-28-28v-38a28 28 0 0 1 28-28z" fill="#e6a94f"/></svg>
<?php if ($done): ?>
  <h1><?= $done['privacy'] === 'exposed' ? 'Installed, with one problem' : 'Previously Noted is ready' ?></h1>
  <ul class="checks">
    <?php foreach ($done['log'] as $line): ?><li class="ok"><span class="dot"></span><span><?= inst_h($line) ?></span></li><?php endforeach; ?>
    <?php if ($done['privacy'] === 'private'): ?>
      <li class="ok"><span class="dot"></span><span>Checked that the data folder is private: the server refused to hand out a test file</span></li>
    <?php elseif ($done['privacy'] === 'exposed'): ?>
      <li class="fail"><span class="dot"></span><span>The data folder is reachable from the web. The server ignored the .htaccess rules, so the notes database could be downloaded by anyone who guesses its address.</span></li>
    <?php else: ?>
      <li class="warn"><span class="dot"></span><span>The installer couldn’t check from the server that the data folder is private. Check it yourself below.</span></li>
    <?php endif; ?>
    <?php if ($done['deleted']): ?>
      <li class="ok"><span class="dot"></span><span>Deleted this installer from the server</span></li>
    <?php else: ?>
      <li class="warn"><span class="dot"></span><span>Couldn’t delete install.php. Delete it in File Manager now; it asks for an admin password, but there’s no reason to leave it.</span></li>
    <?php endif; ?>
  </ul>
  <?php if ($done['privacy'] === 'exposed'): ?>
    <div class="note fail"><p><strong>Don’t import any notes yet.</strong> Ask Namecheap support to enable .htaccess (AllowOverride) for this folder, then open <a href="<?= inst_h($done['url']) ?>data/notes.sqlite" target="_blank" rel="noopener"><?= inst_h($done['url']) ?>data/notes.sqlite</a>. It should say 403 Forbidden.</p></div>
  <?php elseif ($done['privacy'] !== 'private'): ?>
    <div class="note warn"><p>Open <a href="<?= inst_h($done['url']) ?>data/notes.sqlite" target="_blank" rel="noopener"><?= inst_h($scheme . '://' . $host . $done['url']) ?>data/notes.sqlite</a>. It should say 403 Forbidden. If a file downloads instead, don’t import notes until Namecheap support has enabled .htaccess for this folder.</p></div>
  <?php endif; ?>
  <h2>Next</h2>
  <p class="lede">Open the app and sign in with your email and password. Invite the team from <strong>People</strong>, then use <strong>Films &amp; versions</strong> to add your film and import the first Frame.io comment export.</p>
  <p><a class="btn" href="<?= inst_h($done['url']) ?>">Open Previously Noted</a></p>

<?php else: ?>
  <h1><?= $upgrade ? 'Upgrade Previously Noted' : 'Install Previously Noted' ?></h1>
  <p class="lede"><?= $upgrade
    ? 'An existing install was found here. Upgrading replaces the app’s files and keeps every film, version, note and status.'
    : 'This puts the app on your server, creates your admin account, and deletes this installer when it’s done.' ?></p>
  <p class="ver">Version <?= inst_h(INST_VERSION) ?> · installing into <?= inst_h($target) ?></p>

  <h2>Server check</h2>
  <ul class="checks">
    <?php foreach ($checks as [$level, $msg]): ?>
      <li class="<?= $level ?>"><span class="dot" aria-hidden="true"></span><span><?= inst_h($msg) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($blocked): ?>
    <div class="note fail"><p>Fix the items in red in cPanel, then reload this page.</p></div>
  <?php endif; ?>

  <h2><?= $upgrade ? 'Confirm the upgrade' : 'Set up' ?></h2>
  <?php foreach ($errors as $e): ?><p class="err"><?= inst_h($e) ?></p><?php endforeach; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= inst_h($_SESSION['inst_csrf']) ?>">
    <input type="hidden" name="step" value="install">
    <?php if ($upgrade): ?>
      <input type="hidden" name="folder" value="<?= inst_h($sub) ?>">
      <?php if ($admins): ?>
      <label>Admin email<input type="email" name="email" required autocomplete="username" value="<?= inst_h($_POST['email'] ?? '') ?>"></label>
      <label>Admin password<input type="password" name="password" required autocomplete="current-password"></label>
      <p class="hint">Any active admin’s account works.</p>
      <?php else: ?>
      <label>Current team password<input type="password" name="password" required autocomplete="current-password"></label>
      <p class="hint">This update replaces the shared team password with individual accounts. You’ll enter it once more in the app to create your admin account.</p>
      <?php endif; ?>
      <button <?= $blocked ? 'disabled' : '' ?>>Upgrade to <?= inst_h(INST_VERSION) ?></button>
    <?php else: ?>
      <p class="hint" style="margin:0">Upgrading an install that lives in a subfolder? Open this page as install.php?folder=<em>name</em>.</p>
      <label>Name shown in the app’s header<input name="app_name" maxlength="60" value="<?= inst_h($_POST['app_name'] ?? INST_APP) ?>"></label>
      <label>Your name<input name="person" maxlength="60" required autocomplete="name" value="<?= inst_h($_POST['person'] ?? '') ?>"></label>
      <label>Your email<input type="email" name="email" required autocomplete="username" value="<?= inst_h($_POST['email'] ?? '') ?>"></label>
      <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
      <label>Type it again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
      <p class="hint">This is your admin account. You invite everyone else from People once you’re in, and each person gets their own sign-in. At least 10 characters.</p>
      <details <?= $sub !== '' ? 'open' : '' ?>>
        <summary>Install in a subfolder instead</summary>
        <label style="margin-top:12px">Folder
          <span class="prefix"><span><?= inst_h($host . inst_web_path('')) ?></span><input name="folder" value="<?= inst_h($sub) ?>" placeholder="leave empty for the main address" pattern="[A-Za-z0-9][A-Za-z0-9_\-]{0,40}"></span>
        </label>
      </details>
      <button <?= $blocked ? 'disabled' : '' ?>>Install</button>
    <?php endif; ?>
  </form>
<?php endif; ?>
</main>
</body>
</html>
<?php
__halt_compiler();

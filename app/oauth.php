<?php
declare(strict_types=1);

/*
 * "Sign in with Adobe" for Frame.io.
 *   oauth.php?start=1&ws=N   sends a company admin to Adobe, to connect Frame.io for the company that production is in
 *   oauth.php?code=…    Adobe sends them back here; the code is swapped for tokens
 * Register this page's address as the redirect URI in the Adobe Developer Console.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/connect.php';

security_headers();
header('Cache-Control: no-store');

if (!app_config()) {
    header('Location: ./');
    exit;
}
start_session();
$me = current_account();
$real = real_account();

function back(string $query, ?int $ws = null): never
{
    header('Location: ./?' . ($ws ? "ws=$ws&" : '') . $query . '#settings');
    exit;
}

if (!$me) {
    // The app's own sign-in comes first; Adobe's sign-in is for connecting Frame.io.
    header('Location: ./');
    exit;
}
Ctx::$user = $me;
Ctx::$real = $real;
$user = $me['name'];

/** The company this sign-in is for, checked again on the way back: company admins only. */
function oauth_org(int $wsId): array
{
    $ws = ws_row($wsId);
    $org = $ws ? org_row((int)$ws['org_id']) : null;
    if (!$org || !org_power_at_least(org_power(Ctx::$user, $org, Ctx::$real), 'admin')) {
        back('fio=error&msg=' . rawurlencode('Only company admins can connect Frame.io.'), $ws ? $wsId : null);
    }
    Ctx::set($org, $ws, null);
    return $org;
}

if (isset($_GET['start'])) {
    $wsId = (int)($_GET['ws'] ?? 0);
    oauth_org($wsId);
    $c = conn_reload();
    if (empty($c['fio_client_id']) || empty($c['fio_client_secret'])) {
        back('fio=error&msg=' . rawurlencode('Save the client ID and secret first.'), $wsId);
    }
    $_SESSION['fio_state'] = bin2hex(random_bytes(16));
    $_SESSION['fio_ws'] = $wsId;
    header('Location: ' . fio_authorize_url($_SESSION['fio_state']));
    exit;
}

$wsId = (int)($_SESSION['fio_ws'] ?? 0);
if (isset($_GET['error'])) {
    back('fio=error&msg=' . rawurlencode('Adobe said: ' . ($_GET['error_description'] ?? $_GET['error'])), $wsId ?: null);
}

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
if ($code === '' || empty($_SESSION['fio_state']) || !hash_equals($_SESSION['fio_state'], $state) || !$wsId) {
    back('fio=error&msg=' . rawurlencode('The sign-in didn’t match this browser session. Start it again from Settings.'), $wsId ?: null);
}
unset($_SESSION['fio_state'], $_SESSION['fio_ws']);
oauth_org($wsId);

try {
    fio_exchange_code($code, $user);
    $accounts = fio_accounts();
    $c = conn_reload();
    $pick = null;
    foreach ($accounts as $a) {
        if ($a['id'] === ($c['fio_account_id'] ?? '')) {
            $pick = $a;
        }
    }
    if (!$pick && count($accounts) === 1) {
        $pick = $accounts[0];
    }
    conn_save(['fio_accounts' => $accounts, 'fio_account_id' => $pick['id'] ?? null, 'fio_account_name' => $pick['name'] ?? null, 'fio_checked_at' => now_iso()]);
    back($pick ? 'fio=connected' : 'fio=choose', $wsId);
} catch (Throwable $e) {
    error_log('[previouslynoted] oauth: ' . $e->getMessage());
    back('fio=error&msg=' . rawurlencode($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'The sign-in failed on the server.'), $wsId);
}

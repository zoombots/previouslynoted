<?php
declare(strict_types=1);

/*
 * "Sign in with Adobe" for Frame.io.
 *   oauth.php?start=1   sends a signed-in team member to Adobe
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
$user = current_user();

function back(string $query): never
{
    header('Location: ./?' . $query . '#settings');
    exit;
}

if (!$user) {
    // The app's own sign-in comes first; Adobe's sign-in is for connecting Frame.io.
    header('Location: ./');
    exit;
}
if (!role_at_least(current_account(), 'admin')) {
    back('fio=error&msg=' . rawurlencode('Only admins can connect Frame.io.'));
}

if (isset($_GET['start'])) {
    $c = conn_reload();
    if (empty($c['fio_client_id']) || empty($c['fio_client_secret'])) {
        back('fio=error&msg=' . rawurlencode('Save the client ID and secret first.'));
    }
    $_SESSION['fio_state'] = bin2hex(random_bytes(16));
    header('Location: ' . fio_authorize_url($_SESSION['fio_state']));
    exit;
}

if (isset($_GET['error'])) {
    back('fio=error&msg=' . rawurlencode('Adobe said: ' . ($_GET['error_description'] ?? $_GET['error'])));
}

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
if ($code === '' || empty($_SESSION['fio_state']) || !hash_equals($_SESSION['fio_state'], $state)) {
    back('fio=error&msg=' . rawurlencode('The sign-in didn’t match this browser session. Start it again from Settings.'));
}
unset($_SESSION['fio_state']);

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
    back($pick ? 'fio=connected' : 'fio=choose');
} catch (Throwable $e) {
    error_log('[previouslynoted] oauth: ' . $e->getMessage());
    back('fio=error&msg=' . rawurlencode($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'The sign-in failed on the server.'));
}

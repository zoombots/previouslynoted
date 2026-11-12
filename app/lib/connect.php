<?php
declare(strict_types=1);

/*
 * Connections to Frame.io (through Adobe server-to-server credentials) and ElevenLabs.
 *
 * Secrets live in data/connections.php, which the web server never serves.
 * They are never sent to the browser.
 */

class ConnectError extends RuntimeException {}
/** A Frame.io answer that wasn't a success, with its status code. */
final class FioHttpError extends ConnectError
{
    public function __construct(string $message, public readonly int $status, public readonly string $detail = '')
    {
        parent::__construct($message);
    }
}

const CONN_FILE = DATA_DIR . '/connections.php';
const FIO_TOKEN_FILE = DATA_DIR . '/frameio-token.json';

function conn_reload(): array
{
    $c = is_file(CONN_FILE) ? (require CONN_FILE) : [];
    return is_array($c) ? $c : [];
}

function conn_save(array $changes): array
{
    $c = array_merge(conn_reload(), $changes);
    foreach ($c as $k => $v) {
        if ($v === null) {
            unset($c[$k]);
        }
    }
    $php = "<?php\n// Previously Noted connection settings. Keep this file private.\nreturn " . var_export($c, true) . ";\n";
    if (file_put_contents(CONN_FILE, $php, LOCK_EX) === false) {
        throw new ConnectError('Couldn’t save the connection settings. Check that the data folder is writable.');
    }
    @chmod(CONN_FILE, 0640);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(CONN_FILE, true);
    }
    return $c;
}

function fio_base(): string { return rtrim(conn_reload()['fio_base'] ?? 'https://api.frame.io', '/'); }
function ims_base(): string { return rtrim(conn_reload()['ims_base'] ?? 'https://ims-na1.adobelogin.com', '/'); }
function el_base(): string { return rtrim(conn_reload()['el_base'] ?? 'https://api.elevenlabs.io', '/'); }

function mask_secret(?string $s): ?string
{
    return $s ? '•••• ' . substr($s, -4) : null;
}

/**
 * Minimal HTTP client. $body: array → form fields, string → raw. Returns [status, decoded JSON or null, raw body, headers].
 */
function http_call(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 30): array
{
    if (!function_exists('curl_init')) {
        throw new ConnectError('The PHP curl extension is off. Turn it on in cPanel → Select PHP Version → Extensions.');
    }
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'PreviouslyNoted/' . APP_VERSION,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        $host = parse_url($url, PHP_URL_HOST);
        throw new ConnectError("Couldn’t reach $host ($err). Check the server’s internet access and try again.");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : null, (string)$raw, $respHeaders];
}

/** POST a body made of a text head, a file read from disk, and a text tail, without loading the file into memory. */
/** With $outFile, the response body goes to that file instead of memory (for large answers such as a zip). */
function http_post_stream(string $url, array $headers, string $head, string $file, string $tail, int $timeout = 30, ?string $outFile = null): array
{
    if (!function_exists('curl_init')) {
        throw new ConnectError('The PHP curl extension is off. Turn it on in cPanel → Select PHP Version → Extensions.');
    }
    $fh = fopen($file, 'rb');
    if (!$fh) {
        throw new ConnectError('The sound file couldn’t be read on the server.');
    }
    $parts = [$head, $fh, $tail];
    $size = strlen($head) + filesize($file) + strlen($tail);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_UPLOAD => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_INFILESIZE => $size,
        CURLOPT_READFUNCTION => function ($ch, $in, $len) use (&$parts) {
            while ($parts) {
                if (is_string($parts[0])) {
                    if ($parts[0] === '') {
                        array_shift($parts);
                        continue;
                    }
                    $out = substr($parts[0], 0, $len);
                    $parts[0] = (string)substr($parts[0], strlen($out));
                    return $out;
                }
                $out = fread($parts[0], $len);
                if ($out !== false && $out !== '') {
                    return $out;
                }
                fclose(array_shift($parts));
            }
            return '';
        },
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:', 'Content-Length: ' . $size]),
        CURLOPT_USERAGENT => 'PreviouslyNoted/' . APP_VERSION,
    ]);
    $out = $outFile !== null ? fopen($outFile, 'wb') : null;
    if ($out) {
        curl_setopt($ch, CURLOPT_FILE, $out);
    }
    $raw = curl_exec($ch);
    if ($out) {
        fclose($out);
    }
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new ConnectError('Couldn’t reach ' . parse_url($url, PHP_URL_HOST) . " ($err). Check the server’s internet access and try again.");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($out) {
        // Only an error answer is worth reading back.
        $raw = $status === 200 ? '' : (string)file_get_contents($outFile, false, null, 0, 20000);
    }
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : null, (string)$raw, []];
}

function api_error_text(?array $json, string $raw): string
{
    if ($json) {
        foreach ([['errors', 0, 'detail'], ['errors', 0, 'title'], ['detail', 'message'], ['detail'], ['error_description'], ['message'], ['error']] as $path) {
            $v = $json;
            foreach ($path as $k) {
                $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
            }
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
    }
    return mb_substr(trim(strip_tags($raw)), 0, 200);
}

/* ------------------------------------------------------------------ */
/* Frame.io (V4 API, Adobe server-to-server)                           */
/* ------------------------------------------------------------------ */

/*
 * Two ways to sign in to Frame.io through Adobe:
 *   user  "Sign in with Adobe" (OAuth Web App). Someone signs in once; the app renews the
 *         sign-in with a refresh token. Works on every Frame.io V4 plan.
 *   s2s   Server-to-server (client credentials). No sign-in, but Adobe only offers it on
 *         Enterprise plans.
 */
const FIO_USER_SCOPE = 'openid email profile offline_access additional_info.roles';

function fio_mode(): string
{
    return (conn_reload()['fio_mode'] ?? 'user') === 's2s' ? 's2s' : 'user';
}

function fio_tokens(): array
{
    $t = is_file(FIO_TOKEN_FILE) ? json_decode((string)file_get_contents(FIO_TOKEN_FILE), true) : null;
    return is_array($t) && ($t['mode'] ?? 's2s') === fio_mode() ? $t : [];
}

function fio_save_tokens(array $t): void
{
    $t['mode'] = fio_mode();
    file_put_contents(FIO_TOKEN_FILE, json_encode($t), LOCK_EX);
    @chmod(FIO_TOKEN_FILE, 0640);
}

function fio_configured(): bool
{
    $c = conn_reload();
    if (empty($c['fio_client_id']) || empty($c['fio_client_secret'])) {
        return false;
    }
    return fio_mode() === 's2s' || !empty(fio_tokens()['refresh_token']) || !empty(fio_tokens()['access_token']);
}

/** The address Adobe sends people back to after signing in. Register it in the Developer Console. */
function fio_redirect_uri(): string
{
    $c = conn_reload();
    if (!empty($c['fio_redirect_uri'])) {
        return $c['fio_redirect_uri'];
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/oauth.php';
}

function fio_authorize_url(string $state): string
{
    $c = conn_reload();
    return ims_base() . '/ims/authorize/v2?' . http_build_query([
        'client_id' => $c['fio_client_id'] ?? '',
        'scope' => FIO_USER_SCOPE,
        'response_type' => 'code',
        'redirect_uri' => fio_redirect_uri(),
        'state' => $state,
    ]);
}

/** Token request to Adobe IMS as a confidential (Web App) client. */
function ims_token_request(array $fields): array
{
    $c = conn_reload();
    $basic = base64_encode(($c['fio_client_id'] ?? '') . ':' . ($c['fio_client_secret'] ?? ''));
    return http_call('POST', ims_base() . '/ims/token/v3', [
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $basic,
    ], $fields + ['client_id' => $c['fio_client_id'] ?? '']);
}

/** Finish "Sign in with Adobe": swap the code for tokens. */
function fio_exchange_code(string $code, string $byUser): array
{
    [$status, $json, $raw] = ims_token_request(['grant_type' => 'authorization_code', 'code' => $code]);
    if ($status !== 200 || empty($json['access_token'])) {
        throw new ConnectError('Adobe didn’t complete the sign-in (' . $status . ': ' . api_error_text($json, $raw) . '). Check the client ID and secret, and that this credential is an OAuth Web App.');
    }
    $email = '';
    if (!empty($json['id_token']) && count($parts = explode('.', $json['id_token'])) === 3) {
        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
        $email = (string)($claims['email'] ?? '');
    }
    $t = [
        'access_token' => $json['access_token'],
        'expires_at' => time() + (int)($json['expires_in'] ?? 3600),
        'refresh_token' => $json['refresh_token'] ?? null,
        'connected_by' => $byUser,
        'adobe_email' => $email,
        'connected_at' => now_iso(),
        'refreshed_at' => now_iso(),
    ];
    fio_save_tokens($t);
    return $t;
}

function fio_token(bool $fresh = false): string
{
    $c = conn_reload();
    if (empty($c['fio_client_id']) || empty($c['fio_client_secret'])) {
        throw new ConnectError('Frame.io isn’t connected yet. Add the client ID and secret in Settings.');
    }
    $t = fio_tokens();
    if (!$fresh && ($t['expires_at'] ?? 0) > time() + 300 && !empty($t['access_token'])) {
        return $t['access_token'];
    }

    if (fio_mode() === 'user') {
        if (empty($t['refresh_token'])) {
            throw new ConnectError('Frame.io needs someone to sign in with Adobe. Open Settings and choose “Sign in with Adobe”.');
        }
        // One refresh at a time, so two requests don't race to swap the refresh token.
        $lock = fopen(FIO_TOKEN_FILE . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $t = fio_tokens();
            if (!$fresh && ($t['expires_at'] ?? 0) > time() + 300 && !empty($t['access_token'])) {
                return $t['access_token'];
            }
            [$status, $json, $raw] = ims_token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string)$t['refresh_token']]);
            if ($status !== 200 || empty($json['access_token'])) {
                $t['access_token'] = null;
                $t['expired'] = true;
                fio_save_tokens($t);
                throw new ConnectError('The Adobe sign-in for Frame.io has expired (' . api_error_text($json, $raw) . '). Open Settings and choose “Sign in with Adobe” again.');
            }
            $t['access_token'] = $json['access_token'];
            $t['expires_at'] = time() + (int)($json['expires_in'] ?? 3600);
            if (!empty($json['refresh_token'])) {
                $t['refresh_token'] = $json['refresh_token'];
            }
            $t['refreshed_at'] = now_iso();
            unset($t['expired']);
            fio_save_tokens($t);
            return $t['access_token'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    [$status, $json, $raw] = http_call('POST', ims_base() . '/ims/token/v3', ['Content-Type: application/x-www-form-urlencoded'], [
        'grant_type' => 'client_credentials',
        'client_id' => $c['fio_client_id'],
        'client_secret' => $c['fio_client_secret'],
        'scope' => $c['fio_scope'] ?? 'openid AdobeID frame.s2s.all',
    ]);
    if ($status !== 200 || empty($json['access_token'])) {
        $why = api_error_text($json, $raw);
        $hint = str_contains(strtolower($why), 'scope')
            ? ' Check that the Frame.io API with Server-to-Server authentication is added to the project in the Adobe Developer Console.'
            : ' Check the client ID and client secret from the Adobe Developer Console.';
        throw new ConnectError("Adobe didn’t issue a token ($status: $why).$hint");
    }
    fio_save_tokens(['access_token' => $json['access_token'], 'expires_at' => time() + (int)($json['expires_in'] ?? 3600)]);
    return $json['access_token'];
}

/** GET a Frame.io API path (starting with /v4/…). Retries once on an expired token and on rate limits. */
function fio_get(string $path, array $query = []): array
{
    $url = fio_base() . $path . ($query ? (str_contains($path, '?') ? '&' : '?') . http_build_query($query) : '');
    $fresh = false;
    for ($attempt = 0; $attempt < 4; $attempt++) {
        [$status, $json, $raw, $h] = http_call('GET', $url, ['Authorization: Bearer ' . fio_token($fresh), 'Accept: application/json']);
        if ($status === 401 && !$fresh) {
            $fresh = true;
            continue;
        }
        if ($status === 429) {
            sleep(min(10, 2 + $attempt * 2));
            continue;
        }
        if ($status >= 200 && $status < 300 && is_array($json)) {
            return $json;
        }
        $why = api_error_text($json, $raw);
        error_log("[previouslynoted] Frame.io GET $path -> $status $why");
        throw new FioHttpError(match (true) {
            $status === 403 => "Frame.io refused access ($why). The signed-in user may not be a member of this project, or download is restricted.",
            $status === 404 => 'Frame.io couldn’t find that item in the connected account.',
            default => "Frame.io answered $status: $why",
        }, $status, $why);
    }
    throw new ConnectError('Frame.io kept rate-limiting the request. Wait a minute and try again.');
}

/** POST / PATCH / DELETE to Frame.io with a JSON body. Retries on an expired token and on rate limiting. */
function fio_send(string $method, string $path, array $query = [], ?array $body = null): ?array
{
    $url = fio_base() . $path . ($query ? '?' . http_build_query($query) : '');
    $fresh = false;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        [$status, $json, $raw] = http_call($method, $url, ['Authorization: Bearer ' . fio_token($fresh), 'Accept: application/json', 'Content-Type: application/json'],
            $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($status === 401 && !$fresh) {
            $fresh = true;
            continue;
        }
        if ($status === 429) {
            sleep(min(15, 3 + $attempt * 3));
            continue;
        }
        if ($status >= 200 && $status < 300) {
            return $json;
        }
        $why = api_error_text($json, $raw);
        error_log("[previouslynoted] Frame.io $method $path -> $status $why");
        throw new FioHttpError(match (true) {
            $status === 403 => "Frame.io refused ($why). The signed-in Adobe user needs permission to comment on this file.",
            $status === 404 => 'Frame.io couldn’t find that file in the connected account.',
            default => "Frame.io answered $status: $why",
        }, $status, $why);
    }
    throw new ConnectError('Frame.io kept rate-limiting the request. Wait a minute and try again.');
}

/** Post one comment on a file. $timecode as Frame.io shows it; $duration in seconds. Returns the new comment. */
function fio_create_comment(string $fileId, string $text, string $timecode, ?float $duration, bool $completed, ?string $account = null): array
{
    $acc = fio_account($account);
    $data = ['text' => $text, 'timestamp' => $timecode, 'completed' => $completed];
    if ($duration !== null && $duration > 0) {
        $data['duration'] = round($duration, 3);
    }
    $r = fio_send('POST', "/v4/accounts/$acc/files/$fileId/comments", ['timestamp_as_timecode' => 'true'], ['data' => $data]);
    return $r['data'] ?? [];
}

function fio_show_comment(string $commentId, ?string $account = null): array
{
    $acc = fio_account($account);
    return fio_get("/v4/accounts/$acc/comments/$commentId", ['timestamp_as_timecode' => 'true'])['data'] ?? [];
}

function fio_delete_comment(string $commentId, ?string $account = null): void
{
    $acc = fio_account($account);
    fio_send('DELETE', "/v4/accounts/$acc/comments/$commentId");
}

/** Follow links.next across pages; repeats the query on each page as the API requires. */
function fio_get_all(string $path, array $query, int $maxPages = 50): array
{
    $out = [];
    $next = $path;
    $after = null;
    for ($i = 0; $i < $maxPages && $next; $i++) {
        $q = $query + ($after ? ['after' => $after] : []);
        $page = fio_get($path, $q);
        foreach ($page['data'] ?? [] as $item) {
            $out[] = $item;
        }
        $next = $page['links']['next'] ?? null;
        $after = null;
        if ($next) {
            parse_str((string)parse_url($next, PHP_URL_QUERY), $nq);
            $after = $nq['after'] ?? null;
            if (!$after) {
                break;
            }
        }
    }
    return $out;
}

function fio_accounts(): array
{
    return array_map(fn($a) => ['id' => $a['id'], 'name' => $a['display_name'] ?? $a['id']], fio_get_all('/v4/accounts', ['page_size' => 50]));
}

function fio_account(?string $override = null): string
{
    if ($override) {
        return $override;
    }
    $a = conn_reload()['fio_account_id'] ?? '';
    if ($a === '') {
        throw new ConnectError('Choose the Frame.io account in Settings first.');
    }
    return $a;
}

/**
 * The IDs in a Frame.io web address, e.g. https://next.frame.io/project/<project>/view/<file>.
 * Returns the file (after /view/), the project (after /project/), and every other UUID in order.
 */
function fio_parse_link(string $link): array
{
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    $out = ['view' => null, 'project' => null, 'share' => false, 'all' => []];
    if (preg_match("~/view/($uuid)~i", $link, $m)) {
        $out['view'] = strtolower($m[1]);
    }
    if (preg_match("~/project/($uuid)~i", $link, $m)) {
        $out['project'] = strtolower($m[1]);
    }
    $out['share'] = (bool)preg_match('~(^|//)(f\.io/|[^/]*frame\.io/(share|reviews?|presentations?)/)~i', $link);
    if (preg_match_all("~$uuid~i", $link, $m)) {
        $out['all'] = array_values(array_unique(array_map('strtolower', $m[0])));
    }
    return $out;
}

function fio_file_summary(array $f): array
{
    return [
        'id' => $f['id'], 'name' => $f['name'] ?? '', 'type' => $f['type'] ?? 'file', 'status' => $f['status'] ?? null,
        'media_type' => $f['media_type'] ?? null, 'parent_id' => $f['parent_id'] ?? null,
        'view_url' => $f['view_url'] ?? null, 'created_at' => $f['created_at'] ?? null,
    ];
}

/**
 * Work out what a link points at: a file, or a version stack (with its files).
 * Looks in the chosen account first, then every other account the sign-in can see,
 * and explains what it tried if nothing matches.
 */
function fio_resolve(string $link): array
{
    $ids = fio_parse_link($link);
    if (!$ids['all']) {
        throw new ConnectError($ids['share']
            ? 'That’s a share link. Open the file in your Frame.io workspace instead and copy the address from the browser (it contains /project/…/view/…).'
            : 'That doesn’t look like a Frame.io link. Open the file in Frame.io and copy the address from the browser.');
    }
    // Most likely the file after /view/, then any other id except the project's.
    $cands = array_values(array_unique(array_filter(array_merge([$ids['view']], array_diff($ids['all'], [$ids['project']])))));
    $c = conn_reload();
    $accounts = $c['fio_accounts'] ?? [];
    if (!$accounts) {
        $accounts = fio_accounts();
    }
    $chosen = $c['fio_account_id'] ?? '';
    usort($accounts, fn($a, $b) => ($b['id'] === $chosen) <=> ($a['id'] === $chosen));

    $tried = [];
    $projectSeen = null;
    foreach ($accounts as $acc) {
        foreach ($cands as $id) {
            try {
                $f = fio_get("/v4/accounts/{$acc['id']}/files/$id")['data'] ?? null;
                if ($f) {
                    $file = fio_file_summary($f);
                    $stack = null;
                    if (!empty($file['parent_id'])) {
                        try {
                            $stack = fio_stack($file['parent_id'], $acc['id']);
                        } catch (ConnectError) {
                            $stack = null; // the parent is a folder, not a version stack
                        }
                    }
                    return ['file' => $file, 'stack' => $stack, 'account_id' => $acc['id'], 'account_name' => $acc['name']];
                }
            } catch (FioHttpError $e) {
                $tried[] = "file " . substr($id, 0, 8) . " in {$acc['name']}: {$e->status}";
                if ($e->status !== 404 && $e->status !== 403 && $e->status !== 422) {
                    throw $e;
                }
            }
            try {
                $stack = fio_stack($id, $acc['id']);
                return ['file' => null, 'stack' => $stack, 'account_id' => $acc['id'], 'account_name' => $acc['name']];
            } catch (FioHttpError $e) {
                $tried[] = "version stack " . substr($id, 0, 8) . " in {$acc['name']}: {$e->status}";
            }
        }
        if ($ids['project'] && !$projectSeen) {
            try {
                $p = fio_get("/v4/accounts/{$acc['id']}/projects/{$ids['project']}")['data'] ?? null;
                if ($p) {
                    $projectSeen = ($p['name'] ?? 'the project') . ' (' . $acc['name'] . ')';
                }
            } catch (FioHttpError) {
                // not in this account
            }
        }
    }
    error_log('[previouslynoted] Frame.io link not resolved: ' . implode('; ', $tried));
    $who = fio_mode() === 'user' && ($e = fio_tokens()['adobe_email'] ?? '') ? " ($e)" : '';
    $names = implode(', ', array_map(fn($a) => '“' . $a['name'] . '”', $accounts));
    if ($projectSeen) {
        throw new ConnectError("Found the project $projectSeen, but not the file in that link. Open the video itself in Frame.io (not the folder or a comment) and copy the address again.");
    }
    throw new ConnectError("Frame.io didn’t find that file in any account the signed-in user$who can see ($names). "
        . 'Check that this Adobe user is a member of the project in Frame.io, or sign in as someone who is. '
        . 'Tried: ' . implode('; ', array_slice($tried, 0, 6)) . '.');
}

function fio_stack(string $stackId, ?string $account = null): array
{
    $acc = fio_account($account);
    $s = fio_get("/v4/accounts/$acc/version_stacks/$stackId")['data'] ?? [];
    $children = fio_get_all("/v4/accounts/$acc/version_stacks/$stackId/children", ['page_size' => 50, 'sort' => 'created_at_asc']);
    $files = array_values(array_filter(array_map('fio_file_summary', $children), fn($c) => $c['type'] === 'file'));
    return ['id' => $stackId, 'name' => $s['name'] ?? '', 'files' => $files];
}

/** Comments with replies, in the shape import_comments() takes. */
function fio_comments(string $fileId, int $tcBase, ?string $account = null): array
{
    $acc = fio_account($account);
    $rows = fio_get_all("/v4/accounts/$acc/files/$fileId/comments", [
        'include' => 'owner,replies', 'page_size' => 100, 'timestamp_as_timecode' => 'true',
    ]);
    $maxFf = 0;
    foreach ($rows as $r) {
        if (is_string($r['timestamp'] ?? null) && preg_match('/[:;](\d{2})$/', $r['timestamp'], $m)) {
            $maxFf = max($maxFf, (int)$m[1]);
        }
    }
    // Timecode frames above the base mean the file runs at a higher rate (25, 30, …).
    if ($maxFf >= $tcBase) {
        foreach ([25, 30, 48, 50, 60] as $b) {
            if ($maxFf < $b) {
                $tcBase = $b;
                break;
            }
        }
    }
    $who = fn($o) => is_array($o) ? (trim((string)($o['name'] ?? '')) ?: (string)($o['email'] ?? '')) : '';
    $out = [];
    foreach ($rows as $r) {
        $ts = $r['timestamp'] ?? null;
        $frame = 0;
        if (is_string($ts) && ($f = tc_to_frames($ts, $tcBase)) !== null) {
            $frame = $f;
        } elseif (is_int($ts)) {
            $frame = $ts;
        } elseif (is_float($ts) || (is_string($ts) && is_numeric($ts))) {
            $frame = (int)round((float)$ts * $tcBase);
        }
        // Range comments carry a duration (seconds) in the V4 API.
        $dur = $r['duration'] ?? null;
        $end = is_numeric($dur) && (float)$dur > 0 ? $frame + (int)round((float)$dur * $tcBase) : null;
        $out[] = [
            'end' => $end,
            'id' => (string)$r['id'],
            'author' => $who($r['owner'] ?? null) ?: 'Frame.io user',
            'text' => (string)($r['text'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'frame' => $frame,
            'complete' => !empty($r['completed_at']),
            'replies' => array_map(fn($x) => [
                'author' => $who($x['owner'] ?? null) ?: 'Frame.io user',
                'text' => (string)($x['text'] ?? ''),
                'created_at' => (string)($x['created_at'] ?? ''),
            ], is_array($r['replies'] ?? null) ? $r['replies'] : []),
        ];
    }
    return ['comments' => $out, 'tc_base' => $tcBase, 'sample' => $rows[0]['timestamp'] ?? null];
}

/** A fresh, playable link for a file. Signed links expire, so never store them. */
function fio_media(string $fileId, string $quality = 'efficient', ?string $account = null): array
{
    $acc = fio_account($account);
    $order = match ($quality) {
        'high' => ['high_quality', 'efficient', 'original'],
        'original' => ['original', 'high_quality', 'efficient'],
        default => ['efficient', 'high_quality', 'original'],
    };
    $includes = [];
    foreach ($order as $o) {
        $includes[] = 'media_links.' . $o;
    }
    try {
        $f = fio_get("/v4/accounts/$acc/files/$fileId", ['include' => implode(',', $includes)])['data'] ?? [];
    } catch (FioHttpError $e) {
        // Asking for the original without download permission is refused outright; try without it.
        if ($e->status !== 403) {
            throw $e;
        }
        $order = array_values(array_diff($order, ['original']));
        $f = fio_get("/v4/accounts/$acc/files/$fileId", ['include' => implode(',', array_map(fn($o) => 'media_links.' . $o, $order))])['data'] ?? [];
    }
    $links = $f['media_links'] ?? [];
    foreach ($order as $o) {
        $l = $links[$o] ?? null;
        $url = is_array($l) ? ($l['inline_url'] ?? $l['url'] ?? $l['download_url'] ?? null) : null;
        if ($url) {
            return ['url' => $url, 'quality' => $o, 'name' => $f['name'] ?? '', 'status' => $f['status'] ?? null];
        }
    }
    $status = $f['status'] ?? 'unknown';
    throw new ConnectError($status !== 'transcoded'
        ? 'Frame.io is still processing this file. Try again in a few minutes.'
        : 'Frame.io didn’t provide a playable link for this file. Check that the service account may download it, and that watermarking isn’t required.');
}

/* ------------------------------------------------------------------ */
/* ElevenLabs speech to text                                            */
/* ------------------------------------------------------------------ */

function el_configured(): bool
{
    return !empty(conn_reload()['el_key']);
}

/**
 * Transcribe a hosted media file. Waits for the result (can take minutes for a long cut).
 * Fields are sent as multipart/form-data, with list values repeated as the API expects.
 */
function el_transcribe(string $sourceUrl, array $keyterms = [], ?string $file = null): array
{
    $c = conn_reload();
    if (empty($c['el_key'])) {
        throw new ConnectError('ElevenLabs isn’t connected yet. Add the API key in Settings.');
    }
    $fields = [
        ['model_id', $c['el_model'] ?? 'scribe_v2'],
        ['timestamps_granularity', 'word'],
        ['diarize', ($c['el_diarize'] ?? true) ? 'true' : 'false'],
        ['tag_audio_events', 'false'],
    ];
    if (!empty($c['el_language'])) {
        $fields[] = ['language_code', $c['el_language']];
    }
    foreach (array_slice($keyterms, 0, 1000) as $k) {
        $fields[] = ['keyterms', $k];
    }
    if ($file === null) {
        $fields[] = ['source_url', $sourceUrl];
    }
    $boundary = '----PreviouslyNoted' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as [$name, $value]) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }
    $headers = ['xi-api-key: ' . $c['el_key'], 'Content-Type: multipart/form-data; boundary=' . $boundary, 'Accept: application/json'];
    if ($file === null) {
        $body .= "--$boundary--\r\n";
        [$status, $json, $raw] = http_call('POST', el_base() . '/v1/speech-to-text', $headers, $body, 3600);
    } else {
        // The sound file itself: the request is streamed from disk, so a long WAV doesn't have to fit in memory.
        $name = str_replace(['"', "\r", "\n"], '', basename($file));
        $head = $body . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n";
        [$status, $json, $raw] = http_post_stream(el_base() . '/v1/speech-to-text', $headers, $head, $file, "\r\n--$boundary--\r\n", 3600);
    }
    if ($status !== 200 || !is_array($json)) {
        $why = api_error_text($json, $raw);
        throw new ConnectError(match (true) {
            $status === 401 => "ElevenLabs didn’t accept the API key ($why).",
            $status === 402 || str_contains(strtolower($why), 'quota') => "ElevenLabs says the account is out of credit ($why).",
            default => "ElevenLabs answered $status: $why",
        });
    }
    // Multichannel responses carry several transcripts; merge them.
    if (isset($json['transcripts']) && is_array($json['transcripts'])) {
        $words = [];
        foreach ($json['transcripts'] as $t) {
            foreach ($t['words'] ?? [] as $w) {
                $words[] = $w;
            }
        }
        usort($words, fn($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));
        return ['words' => $words, 'language' => $json['transcripts'][0]['language_code'] ?? null];
    }
    return ['words' => $json['words'] ?? [], 'language' => $json['language_code'] ?? null];
}

/**
 * Group words into readable lines: break on a pause, a change of speaker,
 * or the end of a sentence once a line is long enough.
 */
function el_words_to_segments(array $words, float $rate): array
{
    $segs = [];
    $cur = null;
    $speakers = [];
    $speakerName = function (?string $id) use (&$speakers): string {
        if ($id === null || $id === '') {
            return '';
        }
        if (!isset($speakers[$id])) {
            $speakers[$id] = 'Speaker ' . (count($speakers) + 1);
        }
        return $speakers[$id];
    };
    $close = function () use (&$cur, &$segs) {
        if ($cur && trim($cur['text']) !== '') {
            $cur['text'] = trim(preg_replace('/\s+/u', ' ', $cur['text']));
            $segs[] = $cur;
        }
        $cur = null;
    };
    $lastEnd = null;
    foreach ($words as $w) {
        $type = $w['type'] ?? 'word';
        $text = (string)($w['text'] ?? '');
        if ($type === 'audio_event') {
            continue;
        }
        if ($type === 'spacing') {
            if ($cur) {
                $cur['text'] .= ' ';
            }
            continue;
        }
        $start = (float)($w['start'] ?? 0);
        $end = max($start, (float)($w['end'] ?? $start));
        $speaker = $speakerName(isset($w['speaker_id']) ? (string)$w['speaker_id'] : null);
        if ($cur) {
            $gap = $lastEnd !== null ? $start - $lastEnd : 0;
            $len = $lastEnd - $cur['t0'];
            $sentenceEnd = (bool)preg_match('/[.!?…]["”’)]?$/u', rtrim($cur['text']));
            if ($speaker !== $cur['speaker'] || $gap > 1.2 || ($sentenceEnd && ($len > 3.5 || $gap > 0.5)) || $len > 15) {
                $close();
            }
        }
        if (!$cur) {
            $cur = ['t0' => $start, 't1' => $end, 'speaker' => $speaker, 'text' => ''];
        }
        $cur['text'] .= $text;
        $cur['t1'] = $end;
        $lastEnd = $end;
    }
    $close();
    $out = [];
    foreach ($segs as $s) {
        $out[] = [
            'start' => (int)floor($s['t0'] * $rate),
            'end' => max((int)floor($s['t0'] * $rate) + 1, (int)ceil($s['t1'] * $rate)),
            'speaker' => $s['speaker'],
            'text' => $s['text'],
            'norm' => transcript_norm($s['text']),
        ];
    }
    return $out;
}

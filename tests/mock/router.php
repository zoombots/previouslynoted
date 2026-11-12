<?php
/*
 * Stand-in for Adobe IMS, the Frame.io V4 API and ElevenLabs speech-to-text,
 * built from their documented request and response shapes. For testing only.
 *   php -S 127.0.0.1:8099 router.php
 */
declare(strict_types=1);

const CSV = '/mnt/user-data/uploads/RFTA_Restructure_Oct_14_-_Part_1.csv';
const MEDIA = 'http://127.0.0.1:8081/media/long24.webm';
const ACC = '6f1c2d3e-0000-4000-8000-00000000acc1';
const ACC2 = '6f1c2d3e-0000-4000-8000-00000000acc2';
const STACK = '9a8b7c6d-0000-4000-8000-0000000057ac';
const F1 = 'f6426b23-cb21-47af-8b53-c0c42a3cb678';   // the file the CSV came from
const F2 = 'b2c3d4e5-0000-4000-8000-0000000000f2';
const PROJECT = '79dae07b-5126-4a7d-be3a-942a3dadf9c4';

function out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('x-ratelimit-limit: 100');
    echo json_encode($data);
    exit;
}
function logline(string $s): void { file_put_contents(__DIR__ . '/mock.log', date('H:i:s ') . $s . "\n", FILE_APPEND); }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
logline("$method {$_SERVER['REQUEST_URI']}");

/* ---------- Adobe IMS ---------- */
if ($path === '/ims/authorize/v2') {
    // Stand-in for Adobe's sign-in page: approve straight away and send the person back.
    if (($_GET['client_id'] ?? '') !== 'web-client') { http_response_code(400); echo 'invalid client_id'; exit; }
    if (!str_contains($_GET['scope'] ?? '', 'offline_access')) { http_response_code(400); echo 'missing offline_access'; exit; }
    logline('  authorize redirect_uri=' . ($_GET['redirect_uri'] ?? ''));
    header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => 'code-' . bin2hex(random_bytes(4)), 'state' => $_GET['state'] ?? '']));
    exit;
}
if ($path === '/ims/token/v3' && $method === 'POST' && in_array($_POST['grant_type'] ?? '', ['authorization_code', 'refresh_token'], true)) {
    $basic = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 6));
    if ($basic !== 'web-client:web-secret') out(['error' => 'invalid_client', 'error_description' => 'bad client credentials'], 401);
    $state = json_decode((string)@file_get_contents(__DIR__ . '/user.json'), true) ?: [];
    if ($_POST['grant_type'] === 'authorization_code') {
        if (!str_starts_with($_POST['code'] ?? '', 'code-')) out(['error' => 'invalid_grant'], 400);
    } elseif (($_POST['refresh_token'] ?? '') !== ($state['refresh'] ?? '') || !empty($state['revoked'])) {
        out(['error' => 'invalid_grant', 'error_description' => 'refresh token is invalid or expired'], 400);
    }
    $n = (int)@file_get_contents(__DIR__ . '/tokens.txt') + 1;
    file_put_contents(__DIR__ . '/tokens.txt', (string)$n);
    $state['refresh'] = 'refresh-' . $n;
    file_put_contents(__DIR__ . '/user.json', json_encode($state));
    $idt = 'x.' . rtrim(strtr(base64_encode(json_encode(['email' => 'francis@example.com'])), '+/', '-_'), '=') . '.y';
    logline('  ' . $_POST['grant_type'] . ' -> mocktoken-' . $n);
    out(['access_token' => "mocktoken-$n", 'refresh_token' => $state['refresh'], 'id_token' => $idt, 'token_type' => 'bearer', 'expires_in' => 86399]);
}
if ($path === '/ims/token/v3' && $method === 'POST') {
    if (($_POST['grant_type'] ?? '') !== 'client_credentials') out(['error' => 'unsupported_grant_type'], 400);
    if (($_POST['client_id'] ?? '') !== 'test-client' || ($_POST['client_secret'] ?? '') !== 'test-secret') {
        out(['error' => 'invalid_client', 'error_description' => 'invalid client_id parameter'], 400);
    }
    if (!str_contains($_POST['scope'] ?? '', 'frame.s2s.all')) out(['error' => 'invalid_scope', 'error_description' => 'invalid scope'], 400);
    $n = (int)@file_get_contents(__DIR__ . '/tokens.txt') + 1;
    file_put_contents(__DIR__ . '/tokens.txt', (string)$n);
    out(['access_token' => "mocktoken-$n", 'token_type' => 'bearer', 'expires_in' => 86399]);
}

/* ---------- ElevenLabs text to speech ---------- */
// 'el-stt-only' is a key restricted to Speech to Text, as ElevenLabs keys are by default.
function el_tts_key_check(string $perm): void
{
    $k = $_SERVER['HTTP_XI_API_KEY'] ?? '';
    if ($k === 'el-stt-only') out(['detail' => ['status' => 'missing_permissions', 'message' => "The API key you used is missing the permission $perm to execute this operation."]], 401);
    if ($k !== 'el-test-key') out(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 401);
}
if (preg_match('#^/v1/(voices|models)$#', $path, $m) && $method === 'GET') {
    el_tts_key_check($m[1] === 'voices' ? 'voices_read' : 'models_read');
    if ($m[1] === 'voices') {
        out(['voices' => [
            ['voice_id' => 'voiceNarr01', 'name' => 'Rachel', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'female'], 'preview_url' => ''],
            ['voice_id' => 'voiceDeep02', 'name' => 'Adam', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'male'], 'preview_url' => ''],
        ]]);
    }
    out([['model_id' => 'eleven_multilingual_v2', 'name' => 'Eleven Multilingual v2', 'can_do_text_to_speech' => true],
        ['model_id' => 'eleven_v3', 'name' => 'Eleven v3', 'can_do_text_to_speech' => true],
        ['model_id' => 'scribe_v2', 'name' => 'Scribe v2', 'can_do_text_to_speech' => false]]);
}
/* ---------- ElevenLabs stem separation: a zip with the vocals and the rest ---------- */
if ($path === '/v1/music/stem-separation' && $method === 'POST') {
    if (!in_array($_SERVER['HTTP_XI_API_KEY'] ?? '', ['el-test-key'], true)) out(['detail' => ['status' => 'missing_permissions', 'message' => 'The API key you used is missing the permission music_generation to execute this operation.']], 401);
    logline('  stems variation=' . ($_POST['stem_variation_id'] ?? '?') . ' file=' . ($_FILES['file']['name'] ?? '-') . ' bytes=' . ($_FILES['file']['size'] ?? 0) . ' fmt=' . ($_GET['output_format'] ?? '?'));
    file_put_contents(__DIR__ . '/stems_req.txt', ($_FILES['file']['name'] ?? '-') . ' ' . ($_FILES['file']['size'] ?? 0) . ' ' . ($_POST['stem_variation_id'] ?? '?'));
    if (empty($_FILES['file']['size'])) out(['detail' => [['loc' => ['body', 'file'], 'msg' => 'Field required', 'type' => 'missing']]], 422);
    $tmp = tempnam(sys_get_temp_dir(), 'mz');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFile(__DIR__ . '/tone.mp3', 'stems/vocals.mp3');
    $z->addFile(__DIR__ . '/rest.mp3', 'stems/instrumental.mp3');
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="stems.zip"');
    readfile($tmp); unlink($tmp); exit;
}

if (preg_match('#^/v1/text-to-speech/([^/]+)/with-timestamps$#', $path, $m) && $method === 'POST') {
    el_tts_key_check('text_to_speech');
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $fmt = $_GET['output_format'] ?? 'mp3_44100_128';
    $n = (int)@file_get_contents(__DIR__ . '/tts.txt') + 1;
    file_put_contents(__DIR__ . '/tts.txt', (string)$n);
    logline("  tts voice={$m[1]} model=" . ($b['model_id'] ?? '?') . " format=$fmt prev=" . (isset($b['previous_text']) ? 'yes' : 'no') . ' text=' . mb_substr($b['text'] ?? '', 0, 40));
    if (($b['text'] ?? '') === '') out(['detail' => ['message' => 'text is required']], 422);
    if ($m[1] === 'voiceFreeTier' && str_starts_with($fmt, 'wav')) out(['detail' => ['status' => 'output_format_not_allowed', 'message' => 'Your subscription does not allow this output_format']], 403);
    $secs = max(1.0, str_word_count($b['text']) / 2.5);
    if (str_starts_with($fmt, 'wav')) {
        $rate = (int)substr($fmt, 4);
        $pcm = '';
        for ($i = 0, $k = (int)($secs * $rate); $i < $k; $i++) $pcm .= pack('v', (int)(3000 * sin($i * 2 * M_PI * 220 / $rate)) & 0xFFFF);
        $audio = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    } else {
        $audio = file_get_contents(__DIR__ . '/tone.mp3');
        $secs = 1.5;
    }
    $chars = preg_split('//u', $b['text'], -1, PREG_SPLIT_NO_EMPTY);
    $step = $secs / max(1, count($chars));
    out(['audio_base64' => base64_encode($audio), 'alignment' => ['characters' => $chars,
        'character_start_times_seconds' => array_map(fn($i) => $i * $step, array_keys($chars)),
        'character_end_times_seconds' => array_map(fn($i) => ($i + 1) * $step, array_keys($chars))]]);
}

/* ---------- ElevenLabs ---------- */
if ($path === '/v1/speech-to-text' && $method === 'POST') {
    if (!in_array($_SERVER['HTTP_XI_API_KEY'] ?? '', ['el-test-key', 'el-stt-only'], true)) out(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 401);
    $raw = file_get_contents('php://input');
    preg_match_all('/name="keyterms"\r\n\r\n([^\r]*)/', $raw, $kt);
    logline('  model=' . ($_POST['model_id'] ?? '?') . ' source_url=' . ($_POST['source_url'] ?? '?') . ' diarize=' . ($_POST['diarize'] ?? '?') . ' keyterms=' . implode('|', $kt[1]));
    if (isset($_FILES['file'])) {
        logline('  file=' . $_FILES['file']['name'] . ' bytes=' . $_FILES['file']['size'] . ' err=' . $_FILES['file']['error']);
        file_put_contents(__DIR__ . '/stt_file.txt', $_FILES['file']['name'] . ' ' . $_FILES['file']['size']);
    }
    if (($_POST['source_url'] ?? '') === '' && empty($_FILES['file']['size'])) out(['detail' => ['message' => 'Provide file or source_url']], 422);
    sleep(3);
    // Words from the v2 test transcript, spread evenly over each line.
    $words = [];
    $txt = file_get_contents('/home/claude/build/fixtures/v2_transcript.txt');
    preg_match_all('/(\d\d:\d\d:\d\d:\d\d) - (\d\d:\d\d:\d\d:\d\d)\nNarrator\n(.+)\n/', $txt, $m, PREG_SET_ORDER);
    $tc = function ($s) { [$h, $mi, $se, $f] = array_map('intval', explode(':', $s)); return (($h * 60 + $mi) * 60 + $se) * 24 + $f - 86400; };
    foreach ($m as $i => $seg) {
        $a = $tc($seg[1]) / 24; $b = $tc($seg[2]) / 24;
        $ws = explode(' ', $seg[3]);
        $step = ($b - $a) / max(1, count($ws));
        $spk = $i % 9 === 4 ? 'speaker_1' : 'speaker_0';
        foreach ($ws as $j => $w) {
            if ($j) $words[] = ['text' => ' ', 'start' => $a + $j * $step, 'end' => $a + $j * $step, 'type' => 'spacing', 'speaker_id' => $spk];
            $words[] = ['text' => $w, 'start' => round($a + $j * $step, 3), 'end' => round($a + ($j + 0.8) * $step, 3), 'type' => 'word', 'speaker_id' => $spk, 'logprob' => -0.1];
        }
    }
    out(['language_code' => 'en', 'language_probability' => 0.99, 'text' => '…', 'words' => $words]);
}

/* ---------- Frame.io V4 ---------- */
if (!str_starts_with($path, '/v4/')) out(['errors' => [['detail' => 'Not found']]], 404);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer mocktoken-\d+$/', $auth)) out(['errors' => [['detail' => 'Unauthorized']]], 401);

$file = function (string $id, string $name, string $created, array $include) {
    $f = ['id' => $id, 'name' => $name, 'type' => 'file', 'status' => 'transcoded', 'media_type' => 'video/mp4', 'file_size' => 1234567890,
        'parent_id' => STACK, 'project_id' => PROJECT, 'created_at' => $created, 'updated_at' => $created,
        'view_url' => 'https://next.frame.io/project/' . PROJECT . '/view/' . $id];
    if ($include) {
        $f['media_links'] = [
            'efficient' => in_array('media_links.efficient', $include) ? ['download_url' => MEDIA] : null,
            'high_quality' => in_array('media_links.high_quality', $include) ? ['download_url' => MEDIA] : null,
        ];
        if (in_array('media_links.original', $include)) out(['errors' => [['detail' => 'You do not have permission to download this file']]], 403);
    }
    return $f;
};
$include = array_filter(explode(',', $_GET['include'] ?? ''));
$files = [F1 => ['RFTA Restructure Oct 14 - Part 1.mp4', '2026-10-14T21:30:00Z'], F2 => ['RFTA Restructure Oct 21 - Part 1.mp4', '2026-10-21T18:00:00Z']];

if ($path === '/v4/accounts') {
    out(['data' => [['id' => ACC, 'display_name' => 'Rotating Planet', 'roles' => ['admin']], ['id' => ACC2, 'display_name' => 'Rotating Planet Archive', 'roles' => ['member']]], 'links' => ['next' => null]]);
}
// The film lives in the second account; the first can't see it.
if (preg_match('#^/v4/accounts/([^/]+)/projects/([^/]+)$#', $path, $m)) {
    if ($m[1] !== ACC2 || $m[2] !== PROJECT) out(['errors' => [['detail' => 'Not Found']]], 404);
    out(['data' => ['id' => PROJECT, 'name' => '103_RFTA_Rise from the Ashes']]);
}
if (preg_match('#^/v4/accounts/([^/]+)/#', $path, $m) && $m[1] !== ACC2) out(['errors' => [['detail' => 'Not Found']]], 404);
if (preg_match('#^/v4/accounts/([^/]+)/files/([^/]+)$#', $path, $m)) {
    if (!isset($files[$m[2]])) out(['errors' => [['detail' => 'Not Found']]], 404);
    out(['data' => $file($m[2], $files[$m[2]][0], $files[$m[2]][1], $include)]);
}
if (preg_match('#^/v4/accounts/([^/]+)/version_stacks/([^/]+)(/children)?$#', $path, $m)) {
    if ($m[2] !== STACK) out(['errors' => [['detail' => 'Not Found']]], 404);
    if (empty($m[3])) out(['data' => ['id' => STACK, 'name' => 'RFTA Restructure - Part 1', 'type' => 'version_stack', 'project_id' => PROJECT]]);
    $kids = [];
    foreach ($files as $id => [$n, $c]) $kids[] = $file($id, $n, $c, []);
    out(['data' => $kids, 'links' => ['next' => null]]);
}
// Comments posted by the app (kept in a file so they show up in later lists).
const POSTED = '/tmp/claude-0/mock_posted.json';
$posted = is_file(POSTED) ? json_decode(file_get_contents(POSTED), true) : [];
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/v4/accounts/([^/]+)/comments/([^/]+)$#', $path, $m)) {
    $posted = array_values(array_filter($posted, fn($c) => $c['id'] !== $m[2]));
    file_put_contents(POSTED, json_encode($posted));
    http_response_code(204); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/v4/accounts/([^/]+)/files/([^/]+)/comments$#', $path, $m)) {
    $d = json_decode(file_get_contents('php://input'), true)['data'] ?? [];
    if (!isset($d['text'], $d['timestamp'])) out(['errors' => [['detail' => 'text and timestamp are required']]], 422);
    $ts = $d['timestamp'];
    if (is_file('/tmp/claude-0/mock_shift')) { // pretend Frame.io reads the timecode an hour later
        [$h, $mi, $se, $fr] = array_map('intval', explode(':', $ts)); $ts = sprintf('%02d:%02d:%02d:%02d', $h + 1, $mi, $se, $fr);
    }
    $c = ['id' => sprintf('%08x-0000-4000-8000-%012x', crc32($d['text'] . microtime()), count($posted)), 'file_id' => $m[2], 'text' => $d['text'], 'timestamp' => $ts,
        'duration' => $d['duration'] ?? null, 'created_at' => gmdate('c'), 'completed_at' => !empty($d['completed']) ? gmdate('c') : null,
        'owner' => ['name' => 'Francis (Adobe)'], 'replies' => []];
    $posted[] = $c;
    file_put_contents(POSTED, json_encode($posted));
    out(['data' => $c], 201);
}
if (preg_match('#^/v4/accounts/([^/]+)/files/([^/]+)/comments$#', $path, $m)) {
    $asTc = ($_GET['timestamp_as_timecode'] ?? '') === 'true';
    $withOwner = str_contains($_GET['include'] ?? '', 'owner');
    $withReplies = str_contains($_GET['include'] ?? '', 'replies');
    $tc = fn(int $f) => sprintf('%02d:%02d:%02d:%02d', intdiv($f, 86400), intdiv($f, 1440) % 60, intdiv($f, 24) % 60, $f % 24);
    $all = [];
    if ($m[2] === F1) {
        $fh = fopen(CSV, 'r');
        $head = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), fgetcsv($fh, 0, ',', '"', ''));
        $byId = [];
        $i = 0;
        while ($r = fgetcsv($fh, 0, ',', '"', '')) {
            $r = array_combine($head, array_pad($r, count($head), ''));
            if ($r['reply'] === 'yes') {
                $byId[$r['comment id']]['replies'][] = $r;
                continue;
            }
            $byId[$r['comment id']] = ['row' => $r, 'replies' => $byId[$r['comment id']]['replies'] ?? [], 'i' => $i++];
        }
        foreach ($byId as $cid => $c) {
            if (!isset($c['row'])) continue;
            $r = $c['row'];
            [$h, $mi, $se, $fr] = array_map('intval', explode(':', $r['timecode']));
            $frame = (($h * 60 + $mi) * 60 + $se) * 24 + $fr;
            // Half the comments come back under different IDs than the CSV shows.
            $id = $c['i'] % 2 ? md5('api' . $cid) : $cid;
            $id = strlen($id) === 32 ? substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-4' . substr($id, 13, 3) . '-8' . substr($id, 17, 3) . '-' . substr($id, 20, 12) : $id;
            $all[] = [
                'id' => $id, 'file_id' => F1, 'text' => $r['comment'], 'timestamp' => $asTc ? $tc($frame) : $frame, 'duration' => null,
                'created_at' => '2026-10-15T02:00:00.000000Z', 'completed_at' => $r['complete'] === 'yes' ? '2026-10-16T12:00:00Z' : null,
                'owner' => $withOwner ? ['id' => 'u-' . md5($r['commenter']), 'name' => $r['commenter'], 'email' => 'x@example.com'] : null,
                'replies' => $withReplies ? array_map(fn($x, $k) => ['id' => md5($x['comment']), 'text' => $x['comment'], 'created_at' => '2026-10-15T15:00:00Z',
                    'owner' => $k % 2 ? null : ['name' => $x['commenter']]], $c['replies'], array_keys($c['replies'])) : [],
            ];
        }
        // Two comments added in Frame.io after the CSV was exported.
        $all[] = ['id' => '11111111-2222-4333-8444-555555555551', 'text' => 'E: new note after export, tighten this', 'timestamp' => $asTc ? $tc(30000) : 30000,
            'created_at' => '2026-10-17T14:00:00Z', 'completed_at' => null, 'owner' => ['name' => 'Ari Cohen'], 'replies' => []];
        $all[] = ['id' => '11111111-2222-4333-8444-555555555552', 'text' => 'N: also say what year this was', 'timestamp' => $asTc ? $tc(41000) : 41000,
            'created_at' => '2026-10-17T14:05:00Z', 'completed_at' => null, 'owner' => ['name' => 'Ari Cohen'], 'replies' => []];
    } elseif ($m[2] === F2) {
        foreach ([[1200, 'E: better, but still long'], [9700, 'N: line works now'], [15000, 'A: map colour still distracting'], [33000, 'E: love this new section']] as $k => [$f, $t]) {
            $all[] = ['id' => '22222222-3333-4444-8555-66666666666' . $k, 'text' => $t, 'timestamp' => $asTc ? $tc($f) : $f,
                'created_at' => '2026-10-22T10:0' . $k . ':00Z', 'completed_at' => null, 'owner' => ['name' => 'Ari Cohen'], 'replies' => []];
        }
    } else {
        out(['errors' => [['detail' => 'Not Found']]], 404);
    }
    foreach ($posted as $c) if ($c['file_id'] === $m[2]) $all[] = $c;
    $size = min(100, max(1, (int)($_GET['page_size'] ?? 50)));
    $off = isset($_GET['after']) ? (int)base64_decode($_GET['after']) : 0;
    $page = array_slice($all, $off, $size);
    $next = $off + $size < count($all) ? "/v4/accounts/{$m[1]}/files/{$m[2]}/comments?after=" . rawurlencode(base64_encode((string)($off + $size))) : null;
    out(['data' => $page, 'links' => ['next' => $next]]);
}
out(['errors' => [['detail' => 'Not Found']]], 404);

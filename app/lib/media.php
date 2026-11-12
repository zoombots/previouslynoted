<?php
declare(strict_types=1);

/*
 * Sound-only versions: an audio file uploaded to the server, for a cut that has no picture yet (a radio edit, a
 * narration record, a temp mix). It plays in the app like a video, ElevenLabs transcribes it into a script, and the
 * voice-over tools work on that script as on any other.
 *
 * The file arrives in chunks (shared hosting often accepts only a few MB per request) and is kept as
 * data/media/<version id>.<ext>. While an upload is under way it is data/media/<version id>.part.
 */

/** The sound files we take, with the type browsers are told. */
const AUDIO_TYPES = [
    'wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac',
    'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'aif' => 'audio/aiff', 'aiff' => 'audio/aiff', 'webm' => 'audio/webm',
];
const AUDIO_MAX_BYTES = 2 * 1024 * 1024 * 1024;

function media_dir(): string
{
    $d = DATA_DIR . '/media';
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

/** The version's sound file on disk, or null. */
function audio_path(array $v): ?string
{
    if (empty($v['audio_ext'])) {
        return null;
    }
    $p = media_dir() . '/' . (int)$v['id'] . '.' . $v['audio_ext'];
    return is_file($p) ? $p : null;
}

function audio_remove_files(int $versionId): void
{
    foreach (glob(media_dir() . "/$versionId.*") ?: [] as $f) {
        @unlink($f);
    }
}

/** Clear out an older sound file (perhaps of another type) before the new one goes in. */
function audio_remove_files_except(int $versionId, string $keep): void
{
    foreach (glob(media_dir() . "/$versionId.*") ?: [] as $f) {
        if ($f !== $keep) {
            @unlink($f);
        }
    }
}

/** Bytes as "PHP's 8M" style setting → bytes. */
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return PHP_INT_MAX;
    }
    $n = (float)$v;
    return (int)match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** How big each upload chunk may be on this server (a little under its limits, at most 8 MB). */
function upload_chunk_bytes(): int
{
    $lim = min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size')));
    return max(256 * 1024, min(8 * 1024 * 1024, $lim - 64 * 1024));
}

/** Send a file, honouring Range requests so the player can seek. Ends the request. */
function send_file_ranged(string $path, string $type): never
{
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $type);
    header('Cache-Control: private, max-age=3600');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min($end, (int)$m[2]);
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $len = $end - $start + 1;
    header('Content-Length: ' . $len);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    while ($len > 0 && !feof($fh) && !connection_aborted()) {
        $buf = fread($fh, (int)min(1048576, $len));
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        flush();
        $len -= strlen($buf);
    }
    fclose($fh);
    exit;
}

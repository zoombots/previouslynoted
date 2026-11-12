<?php
declare(strict_types=1);

/*
 * Voice and the rest: ElevenLabs splits a cut's sound into two tracks, the voice (narration and dialogue) and
 * everything else (music, effects, atmos). With them the app can play the cut with the old voice taken out where the
 * new voice-over goes in, so the new VO can be heard in place before anything is sent to the edit.
 *
 * ElevenLabs' stem separation (POST /v1/music/stem-separation, two stems) answers with a zip of two audio files.
 * They are kept as data/media/stems/<version id>-voice.mp3 and <version id>-rest.mp3.
 *
 * What gets split: the version's uploaded sound if it has one, otherwise the cut itself, fetched from Frame.io (its
 * smallest proxy) or the video link, and sent to ElevenLabs as it is.
 */

const STEM_KINDS = ['voice' => 'Voice', 'rest' => 'Rest of the sound'];
const STEM_SOURCE_MAX = 2 * 1024 * 1024 * 1024;

function stems_dir(): string
{
    $d = media_dir() . '/stems';
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

function stem_path(int $versionId, string $kind): string
{
    return stems_dir() . "/$versionId-$kind.mp3";
}

function stems_ready(int $versionId): bool
{
    return is_file(stem_path($versionId, 'voice')) && is_file(stem_path($versionId, 'rest'));
}

function stems_remove(int $versionId): void
{
    foreach (array_keys(STEM_KINDS) as $k) {
        @unlink(stem_path($versionId, $k));
    }
    db()->prepare('UPDATE versions SET stems_at = NULL, stems_source = NULL WHERE id = ?')->execute([$versionId]);
}

/** Where the cut's sound can come from, in words, or null when there is nothing on the server. */
function stems_source_label(array $v): ?string
{
    if (audio_path($v)) {
        return 'the uploaded sound (' . $v['audio_name'] . ')';
    }
    if ($v['fio_file_id']) {
        return 'the Frame.io file' . ($v['fio_name'] ? ' (' . $v['fio_name'] . ')' : '');
    }
    if ($v['video_url']) {
        return 'the video link';
    }
    return null;
}

/** Download a URL to a file, up to $max bytes. */
function http_download(string $url, string $to, int $max, int $timeout = 1800): void
{
    $fh = fopen($to, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => 'PreviouslyNoted/' . APP_VERSION,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($ch, $dlTotal, $dl) => $dl > $max ? 1 : 0,
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (filesize($to) > $max) {
        throw new ConnectError('The cut is too large to send for splitting (2 GB at most). Upload its sound (a WAV or MP3 mix) to the version and split that.');
    }
    if (!$ok || $status !== 200) {
        throw new ConnectError('Couldn’t fetch the cut' . ($err ? " ($err)" : " (it answered $status)") . '. Upload its sound to the version and split that instead.');
    }
}

/** Pick the voice and the rest out of the files in ElevenLabs' zip, by name. Returns [voice name, rest name]. */
function stems_pick(array $names): array
{
    $audio = array_values(array_filter($names, fn($n) => preg_match('/\.(mp3|wav|pcm|opus|ogg|m4a|flac)$/i', $n) && !str_starts_with(basename($n), '.')));
    $isRest = fn($n) => (bool)preg_match('/no[_ -]?vocal|instrument|accomp|backing|music|other|rest|karaoke/i', basename($n));
    $isVoice = fn($n) => !$isRest($n) && (bool)preg_match('/vocal|voice|speech|dialog/i', basename($n));
    $voice = array_values(array_filter($audio, $isVoice));
    $rest = array_values(array_filter($audio, fn($n) => !$isVoice($n)));
    if (count($voice) === 1 && count($rest) >= 1) {
        // Two stems: the other one. More (six stems): there is no single "rest", so this needs two stems.
        if (count($rest) === 1) {
            return [$voice[0], $rest[0]];
        }
    }
    throw new ConnectError('ElevenLabs sent stems this app can’t tell apart (' . implode(', ', array_map('basename', $audio)) . ').');
}

/** Split one version's sound. Called by the stems job; updates the job's message as it goes. */
function stems_make(array $v, callable $say, string $by): array
{
    $vid = (int)$v['id'];
    $key = el_tts_key();
    $tmpIn = null;
    $file = audio_path($v);
    $source = stems_source_label($v) ?? throw new ConnectError('This version has no sound on the server. Upload its sound, or link it to Frame.io, first.');
    if (!$file) {
        $say('Fetching the cut to send to ElevenLabs…');
        $url = $v['fio_file_id'] ? fio_media($v['fio_file_id'], 'efficient', $v['fio_account_id'] ?: null)['url'] : (string)$v['video_url'];
        $tmpIn = tempnam(sys_get_temp_dir(), 'pnsrc');
        http_download($url, $tmpIn, STEM_SOURCE_MAX);
        $file = $tmpIn;
    }
    $zipFile = tempnam(sys_get_temp_dir(), 'pnstem');
    try {
        $say('ElevenLabs is separating the voice from the rest…');
        $boundary = '----PreviouslyNoted' . bin2hex(random_bytes(8));
        $head = "--$boundary\r\nContent-Disposition: form-data; name=\"stem_variation_id\"\r\n\r\ntwo_stems_v1\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"cut-$vid" . ($tmpIn ? '.mp4' : '.' . $v['audio_ext']) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n";
        [$status, $json, $raw] = http_post_stream(el_base() . '/v1/music/stem-separation?output_format=mp3_44100_128',
            ['xi-api-key: ' . $key, 'Content-Type: multipart/form-data; boundary=' . $boundary, 'Accept: application/zip'],
            $head, $file, "\r\n--$boundary--\r\n", 3600, $zipFile);
        if ($status !== 200) {
            $why = el_key_problem($status, $json, $raw) ?? ('ElevenLabs answered ' . $status . ': ' . api_error_text($json, $raw));
            if ($tmpIn && $status === 422) {
                $why .= ' If it doesn’t take the video, upload the cut’s sound (a WAV or MP3 mix) to the version and split that.';
            }
            throw new ConnectError($why);
        }
        if (!class_exists('ZipArchive')) {
            throw new ConnectError('The PHP zip extension is off. Turn it on in cPanel → Select PHP Version → Extensions.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new ConnectError('ElevenLabs’ answer wasn’t the zip of stems it should be.');
        }
        $names = [];
        for ($n = 0; $n < $zip->numFiles; $n++) {
            $names[] = (string)$zip->getNameIndex($n);
        }
        [$voiceName, $restName] = stems_pick($names);
        foreach (['voice' => $voiceName, 'rest' => $restName] as $kind => $name) {
            $in = $zip->getStream($name);
            $to = stem_path($vid, $kind) . '.part';
            $out = fopen($to, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
        }
        $zip->close();
        foreach (array_keys(STEM_KINDS) as $kind) {
            rename(stem_path($vid, $kind) . '.part', stem_path($vid, $kind));
        }
        db()->prepare('UPDATE versions SET stems_at = ?, stems_source = ? WHERE id = ?')->execute([now_iso(), $source, $vid]);
        return ['source' => $source, 'seconds' => round(mp3_duration((string)file_get_contents(stem_path($vid, 'rest'))), 1)];
    } finally {
        @unlink($zipFile);
        if ($tmpIn) {
            @unlink($tmpIn);
        }
    }
}

/*
 * What "Hear the new VO" plays: every line of the new script with its latest take (any voice), and the stretches of
 * the original voice to take out (lines that have a take, and crossed-out lines). Frames count from the start of
 * the video, like everything else on the timeline.
 */
function vo_preview(int $versionId, array $t, bool $drafts): array
{
    $plan = vo_lines($versionId, $t, 'all', $drafts);
    $segs = transcript_segments((int)$t['id']);
    $lines = script_lines($versionId, (int)$t['id'], $segs);
    $st = db()->prepare('SELECT line_key, text, hash, format, duration_ms FROM vo_takes WHERE version_id = ? ORDER BY id DESC');
    $st->execute([$versionId]);
    $latest = [];
    foreach ($st->fetchAll() as $r) {
        $k = $r['line_key'] . "\n" . $r['text'];
        if (!isset($latest[$k]) && is_file(vo_file($versionId, $r['hash'], $r['format']))) {
            $latest[$k] = $r;
        }
    }
    $out = [];
    $mute = [];
    foreach ($plan as $l) {
        $take = $latest[$l['key'] . "\n" . $l['text']] ?? null;
        $seg = $segs[$l['i']] ?? null;
        $out[] = ['key' => $l['key'], 'kind' => $l['kind'], 'start' => $l['start'], 'end' => $l['end'], 'text' => $l['text'],
            'take' => $take ? ['hash' => $take['hash'], 'format' => $take['format'], 'duration' => round($take['duration_ms'] / 1000, 3)] : null];
        if ($take && (int)$l['slot'] === 0 && $seg) {
            $mute[] = [(int)$seg['start'], (int)$seg['end'], 'take'];
        }
    }
    foreach ($lines as $l) {
        if ((int)$l['slot'] === 0 && $l['state'] === 'cut' && isset($segs[$l['i']])) {
            $mute[] = [(int)$segs[$l['i']]['start'], (int)$segs[$l['i']]['end'], 'cut'];
        }
    }
    usort($mute, fn($a, $b) => $a[0] <=> $b[0]);
    return ['lines' => $out, 'mute' => $mute, 'takes' => count(array_filter($out, fn($o) => $o['take'])),
        'changes' => count(array_filter($out, fn($o) => $o['kind'] !== 'same'))];
}

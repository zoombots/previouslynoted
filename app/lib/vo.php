<?php
declare(strict_types=1);

/*
 * New voice-over from the script, with ElevenLabs text to speech.
 *
 * Each line of the script becomes one audio clip ("take"). A take is identified by a hash of everything that makes
 * it sound the way it does (wording, voice, model, settings, file type), so asking again for the same line with the
 * same settings reuses the file instead of paying ElevenLabs twice. Files live in data/vo/<version id>/<hash>.<ext>.
 *
 * The export is a zip: one audio file per line, named in script order with its timecode and speaker, and an FCP7
 * XML sequence (Premiere Pro, DaVinci Resolve, Final Cut Pro 7) that lays every clip at its line's time on the
 * TX track, so the editor imports one file and gets the whole new VO in place.
 */

const VO_FORMATS = [
    'wav' => ['el' => 'wav_48000', 'ext' => 'wav', 'rate' => 48000, 'label' => 'WAV 48 kHz'],
    'mp3' => ['el' => 'mp3_44100_128', 'ext' => 'mp3', 'rate' => 44100, 'label' => 'MP3 44.1 kHz'],
];

/** The key for voice-over: its own, if one is set in Settings, else the transcription key. */
function el_tts_key(): string
{
    $c = conn_reload();
    $k = (string)($c['el_tts_key'] ?? '') ?: (string)($c['el_key'] ?? '');
    if ($k === '') {
        throw new ConnectError('ElevenLabs isn’t connected yet. Add the API key in Settings.');
    }
    return $k;
}

/**
 * A plain explanation when ElevenLabs refuses the key. Keys are restricted by default, and a key made for
 * transcription usually has only Speech to Text turned on.
 */
function el_key_problem(int $status, ?array $json, string $raw): ?string
{
    $why = api_error_text($json, $raw);
    $code = is_array($json['detail'] ?? null) ? (string)($json['detail']['status'] ?? '') : '';
    $perm = null;
    if (preg_match('/missing the permission (\w+)/i', $why, $m)) {
        $perm = $m[1];
    }
    if ($code === 'missing_permissions' || $perm) {
        $names = ['text_to_speech' => 'Text to Speech', 'voices_read' => 'Voices (Read)', 'models_read' => 'Models (Read)', 'speech_to_text' => 'Speech to Text', 'user_read' => 'User'];
        $what = $perm ? ($names[$perm] ?? $perm) : 'Text to Speech and Voices (Read)';
        return "The ElevenLabs API key doesn’t have $what turned on. In ElevenLabs → Developers → API keys, edit the key (⋯ → Edit) and turn on Text to Speech and Voices (Read), then save; the key itself stays the same. Or add a separate voice-over key in Settings.";
    }
    if ($status === 401) {
        return "ElevenLabs didn’t accept the API key ($why). Check it in Settings.";
    }
    return null;
}

function vo_dir(int $versionId): string
{
    $d = DATA_DIR . '/vo/' . $versionId;
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

/** The voices and speech models on the ElevenLabs account. */
function el_voices(): array
{
    $h = ['xi-api-key: ' . el_tts_key(), 'Accept: application/json'];
    [$st, $json, $raw] = http_call('GET', el_base() . '/v1/voices', $h, null, 30);
    if ($st !== 200 || !is_array($json)) {
        throw new ConnectError(el_key_problem($st, $json, $raw) ?? 'ElevenLabs answered ' . $st . ': ' . api_error_text($json, $raw));
    }
    $voices = [];
    foreach ($json['voices'] ?? [] as $v) {
        $labels = array_values(array_filter(array_map('strval', (array)($v['labels'] ?? []))));
        $voices[] = ['id' => (string)$v['voice_id'], 'name' => (string)$v['name'], 'category' => (string)($v['category'] ?? ''),
            'about' => implode(', ', array_slice($labels, 0, 4)), 'preview' => (string)($v['preview_url'] ?? '')];
    }
    usort($voices, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    $models = [];
    [$st, $json] = http_call('GET', el_base() . '/v1/models', $h, null, 30);
    if ($st === 200 && is_array($json)) {
        foreach ($json as $m) {
            if (!empty($m['can_do_text_to_speech'])) {
                $models[] = ['id' => (string)$m['model_id'], 'name' => (string)($m['name'] ?? $m['model_id'])];
            }
        }
    }
    if (!$models) {
        $models = [['id' => 'eleven_multilingual_v2', 'name' => 'Eleven Multilingual v2']];
    }
    return ['voices' => $voices, 'models' => $models];
}

/**
 * The lines to voice, in script order, with their wording and time:
 *   scope 'changes'  only rewritten and added lines
 *   scope 'all'      every line of the new script (new wording in place, crossed-out lines left out)
 * $drafts: use draft wording too (otherwise only approved changes count; in 'all' a draft line keeps its old wording).
 */
function vo_lines(int $versionId, array $t, string $scope, bool $drafts): array
{
    $segs = transcript_segments((int)$t['id']);
    $lines = script_lines($versionId, (int)$t['id'], $segs);
    $v = version_row($versionId);
    $times = script_times($versionId, (int)$t['id'], $segs, $lines, (float)$v['tc_base']);
    $useNew = fn(?array $l) => $l && ($l['state'] === 'approved' || ($drafts && $l['state'] === 'draft'));
    $out = [];
    foreach ($segs as $i => $s) {
        $l = $lines["$i:0"] ?? null;
        if (!($l && $l['state'] === 'cut')) {
            $changed = $useNew($l);
            if ($scope === 'all' || $changed) {
                $out[] = ['key' => "$i:0", 'i' => $i, 'slot' => 0, 'speaker' => (string)$s['speaker'], 'text' => $changed ? $l['text'] : $s['text'],
                    'kind' => $changed ? 'rewritten' : 'same', 'start' => $times["$i:0"][0], 'end' => $times["$i:0"][1]];
            }
        }
        $extra = array_filter($lines, fn($x) => $x['i'] === $i && $x['slot'] > 0 && $useNew($x));
        usort($extra, fn($a, $b) => $a['slot'] <=> $b['slot']);
        foreach ($extra as $x) {
            $k = "$i:{$x['slot']}";
            $out[] = ['key' => $k, 'i' => $i, 'slot' => $x['slot'], 'speaker' => (string)$s['speaker'], 'text' => $x['text'], 'kind' => 'new',
                'start' => $times[$k][0], 'end' => $times[$k][1]];
        }
    }
    foreach ($out as $n => &$o) {
        $o['text'] = trim((string)preg_replace('/\s+/u', ' ', $o['text']));
        $o['n'] = $n + 1;
    }
    unset($o);
    return array_values(array_filter($out, fn($o) => $o['text'] !== ''));
}

/** Which voice a speaker gets: their own pick, else the one for everyone ('*'). */
function vo_voice_for(array $voices, string $speaker): string
{
    $v = (string)($voices[$speaker] ?? $voices['*'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $v)) {
        throw new RuntimeException('Choose a voice' . ($speaker !== '' ? " for $speaker" : '') . '.');
    }
    return $v;
}

/** Everything that decides how a take sounds, cleaned up. */
function vo_settings(array $in): array
{
    $model = preg_match('/^[a-z0-9_.-]{3,60}$/', (string)($in['model'] ?? '')) ? (string)$in['model'] : 'eleven_multilingual_v2';
    $format = isset(VO_FORMATS[$in['format'] ?? '']) ? (string)$in['format'] : 'wav';
    $speed = max(0.7, min(1.2, (float)($in['speed'] ?? 1)));
    $stability = max(0.0, min(1.0, (float)($in['stability'] ?? 0.5)));
    return ['model' => $model, 'format' => $format, 'speed' => round($speed, 2), 'stability' => round($stability, 2)];
}

function vo_hash(string $text, string $voice, array $set): string
{
    return substr(hash('sha256', json_encode([$text, $voice, $set['model'], $set['format'], $set['speed'], $set['stability']])), 0, 32);
}

function vo_file(int $versionId, string $hash, string $format): string
{
    return vo_dir($versionId) . '/' . $hash . '.' . VO_FORMATS[$format]['ext'];
}

/**
 * Make (or reuse) the take for one line. $context: the lines before and after, so the delivery flows.
 * Returns ['hash', 'duration' (seconds), 'cached' (bool)].
 */
function vo_make_take(int $versionId, array $line, string $voice, array $set, array $context, string $by): array
{
    $hash = vo_hash($line['text'], $voice, $set);
    $file = vo_file($versionId, $hash, $set['format']);
    if (is_file($file) && filesize($file) > 0) {
        return ['hash' => $hash, 'duration' => vo_duration($file, $set['format']), 'cached' => true];
    }
    $key = el_tts_key();
    $body = ['text' => $line['text'], 'model_id' => $set['model'],
        'voice_settings' => ['stability' => $set['stability'], 'similarity_boost' => 0.75, 'speed' => $set['speed']]];
    if (($context['previous'] ?? '') !== '') {
        $body['previous_text'] = mb_substr($context['previous'], -600);
    }
    if (($context['next'] ?? '') !== '') {
        $body['next_text'] = mb_substr($context['next'], 0, 600);
    }
    $url = el_base() . '/v1/text-to-speech/' . rawurlencode($voice) . '/with-timestamps?output_format=' . VO_FORMATS[$set['format']]['el'];
    [$st, $json, $raw] = http_call('POST', $url, ['xi-api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        json_encode($body, JSON_UNESCAPED_UNICODE), 120);
    if ($st !== 200 || !is_array($json) || empty($json['audio_base64'])) {
        $why = api_error_text($json, $raw);
        $lower = strtolower($why);
        throw new ConnectError(el_key_problem($st, $json, $raw) ?? match (true) {
            $st === 402 || str_contains($lower, 'quota') || str_contains($lower, 'credit') => "ElevenLabs says the account is out of credit ($why).",
            $set['format'] === 'wav' && (str_contains($lower, 'output_format') || str_contains($lower, 'tier') || str_contains($lower, 'subscription'))
                => "ElevenLabs won’t make WAV on this plan ($why). Choose MP3, or upgrade the ElevenLabs plan.",
            $st === 429 => "ElevenLabs is busy or the account’s limit was reached ($why). Wait a moment and try again.",
            default => "ElevenLabs answered $st: $why",
        });
    }
    $audio = base64_decode((string)$json['audio_base64'], true);
    if ($audio === false || $audio === '') {
        throw new ConnectError('ElevenLabs sent back no audio for this line.');
    }
    $tmp = $file . '.tmp';
    file_put_contents($tmp, $audio, LOCK_EX);
    rename($tmp, $file);
    $dur = vo_duration($file, $set['format']);
    if ($dur <= 0) {
        // Fall back to the timing of the last character.
        $ends = $json['alignment']['character_end_times_seconds'] ?? [];
        $dur = $ends ? (float)end($ends) : 0.0;
    }
    db()->prepare('INSERT INTO vo_takes (version_id, line_key, text, voice_id, model_id, format, hash, bytes, duration_ms, by_name, at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$versionId, $line['key'], $line['text'], $voice, $set['model'], $set['format'], $hash, strlen($audio), (int)round($dur * 1000), $by, now_iso()]);
    return ['hash' => $hash, 'duration' => $dur, 'cached' => false];
}

/** Length of an audio file in seconds (WAV from its header, MP3 by counting frames). */
function vo_duration(string $file, string $format): float
{
    $data = (string)@file_get_contents($file);
    if ($format === 'wav' || str_starts_with($data, 'RIFF')) {
        return wav_duration($data);
    }
    return mp3_duration($data);
}

function wav_duration(string $d): float
{
    if (strlen($d) < 44 || substr($d, 0, 4) !== 'RIFF') {
        return 0.0;
    }
    $pos = 12;
    $byteRate = 0;
    while ($pos + 8 <= strlen($d)) {
        $id = substr($d, $pos, 4);
        $size = unpack('V', substr($d, $pos + 4, 4))[1];
        if ($id === 'fmt ') {
            $byteRate = unpack('V', substr($d, $pos + 16, 4))[1];
        } elseif ($id === 'data') {
            // Streams sometimes leave the size at 0 or -1: use what's actually there.
            $avail = strlen($d) - $pos - 8;
            $n = ($size === 0 || $size === 0xFFFFFFFF || $size > $avail) ? $avail : $size;
            return $byteRate ? $n / $byteRate : 0.0;
        }
        $pos += 8 + $size + ($size & 1);
    }
    return 0.0;
}

function mp3_duration(string $d): float
{
    $len = strlen($d);
    $pos = 0;
    if (substr($d, 0, 3) === 'ID3' && $len > 10) {
        $b = array_map('ord', str_split(substr($d, 6, 4)));
        $pos = 10 + (($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3]);
    }
    $rates = [[11025, 12000, 8000], [0, 0, 0], [22050, 24000, 16000], [44100, 48000, 32000]];   // by version bits
    $br1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];                    // MPEG-1 layer III
    $br2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];                         // MPEG-2/2.5 layer III
    $secs = 0.0;
    while ($pos + 4 <= $len) {
        if (ord($d[$pos]) !== 0xFF || (ord($d[$pos + 1]) & 0xE0) !== 0xE0) {
            $pos++;
            continue;
        }
        $h1 = ord($d[$pos + 1]);
        $h2 = ord($d[$pos + 2]);
        $ver = ($h1 >> 3) & 3;
        $layer = ($h1 >> 1) & 3;
        $bri = $h2 >> 4;
        $sri = ($h2 >> 2) & 3;
        $pad = ($h2 >> 1) & 1;
        if ($ver === 1 || $layer !== 1 || $bri === 0 || $bri === 15 || $sri === 3) {
            $pos++;
            continue;
        }
        $sr = $rates[$ver][$sri];
        $kbps = $ver === 3 ? $br1[$bri] : $br2[$bri];
        $samples = $ver === 3 ? 1152 : 576;
        $size = intdiv(($samples / 8) * $kbps * 1000, $sr) + $pad;
        if ($size < 4) {
            $pos++;
            continue;
        }
        // The first frame may only carry the encoder's info (a "Xing" or "Info" header), not sound.
        $info = $secs === 0.0 && (str_contains(substr($d, $pos + 4, 40), 'Xing') || str_contains(substr($d, $pos + 4, 40), 'Info'));
        $secs += $info ? 0 : $samples / $sr;
        $pos += $size;
    }
    return $secs;
}

/** A clip's file name: script order, timecode and speaker, safe for every editing system. */
function vo_clip_name(array $line, string $tc, string $ext): string
{
    $sp = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line['speaker']) ?: ''), '_');
    return sprintf('%04d_%s%s%s.%s', $line['n'], str_replace(':', '-', $tc), $sp !== '' ? '_' : '', substr($sp, 0, 24), $line['kind'] === 'new' ? 'new.' . $ext : $ext);
}

/**
 * The FCP7 XML (xmeml version 5) sequence: every clip at its line's time, on as many audio tracks as needed so
 * that no two clips overlap on one track. $clips: [line, file name, duration seconds]. $folder: where the clips will
 * be on the editing computer ('' leaves the paths relative, and the editing app asks to find the first one).
 */
function vo_fcp7_xml(string $seqName, array $v, array $clips, string $format, string $folder): string
{
    $rate = (float)$v['rate'] ?: (float)$v['tc_base'];
    $base = (int)$v['tc_base'] ?: (int)round($rate);
    $ntsc = abs($rate - round($rate)) > 0.001 ? 'TRUE' : 'FALSE';
    $sr = VO_FORMATS[$format]['rate'];
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $rateXml = "<rate><timebase>$base</timebase><ntsc>$ntsc</ntsc></rate>";

    // Lay clips on tracks: each goes on the first track that's free at its start.
    usort($clips, fn($a, $b) => $a[0]['start'] <=> $b[0]['start']);
    $tracks = [];
    $end = 0;
    foreach ($clips as $k => $c) {
        $len = max(1, (int)ceil($c[2] * $rate));
        $start = (int)$c[0]['start'];
        $clips[$k][3] = $start;
        $clips[$k][4] = $len;
        $t = 0;
        while (isset($tracks[$t]) && end($tracks[$t])[3] + end($tracks[$t])[4] > $start) {
            $t++;
        }
        $tracks[$t][] = $clips[$k];
        $end = max($end, $start + $len);
    }
    $folder = rtrim(str_replace('\\', '/', trim($folder)), '/');
    $path = function (string $name) use ($folder) {
        if ($folder === '') {
            return 'clips/' . rawurlencode($name);
        }
        $p = preg_match('#^[A-Za-z]:/#', $folder) ? '/' . $folder : $folder;   // Windows drive letters
        return 'file://localhost' . implode('/', array_map('rawurlencode', explode('/', $p))) . '/clips/' . rawurlencode($name);
    };

    $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE xmeml>\n<xmeml version=\"5\">\n";
    $out .= "<sequence id=\"sequence-1\">\n<name>{$x($seqName)}</name>\n<duration>$end</duration>\n$rateXml\n";
    $out .= "<timecode>$rateXml<string>" . frames_to_tc((int)$v['tc_start'], $base) . "</string><frame>" . (int)$v['tc_start'] . "</frame><displayformat>NDF</displayformat></timecode>\n";
    $out .= "<media>\n<video><format><samplecharacteristics>$rateXml<width>1920</width><height>1080</height></samplecharacteristics></format><track/></video>\n<audio>\n";
    $out .= "<format><samplecharacteristics><depth>16</depth><samplerate>$sr</samplerate></samplecharacteristics></format>\n";
    $fileN = 0;
    $clipN = 0;
    foreach ($tracks ?: [[]] as $t) {
        $out .= "<track>\n";
        foreach ($t as [$line, $name, $secs, $start, $len]) {
            $clipN++;
            $fileN++;
            $out .= "<clipitem id=\"clipitem-$clipN\">\n<name>{$x($name)}</name>\n<enabled>TRUE</enabled>\n<duration>$len</duration>\n$rateXml\n"
                . "<start>$start</start>\n<end>" . ($start + $len) . "</end>\n<in>0</in>\n<out>$len</out>\n"
                . "<file id=\"file-$fileN\">\n<name>{$x($name)}</name>\n<pathurl>{$x($path($name))}</pathurl>\n$rateXml\n<duration>$len</duration>\n"
                . "<media><audio><samplecharacteristics><depth>16</depth><samplerate>$sr</samplerate></samplecharacteristics><channelcount>1</channelcount></audio></media>\n</file>\n"
                . "<sourcetrack><mediatype>audio</mediatype><trackindex>1</trackindex></sourcetrack>\n"
                . "<comments><mastercomment1>{$x($line['text'])}</mastercomment1></comments>\n</clipitem>\n";
        }
        $out .= "<enabled>TRUE</enabled>\n<locked>FALSE</locked>\n</track>\n";
    }
    $out .= "</audio>\n</media>\n</sequence>\n</xmeml>\n";
    return $out;
}

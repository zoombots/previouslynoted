<?php
declare(strict_types=1);

/*
 * Notes out of the app: marker files for the edit suites, and comments posted back to Frame.io.
 * Timecodes are the version's own (its start timecode plus the note's frame), as Frame.io and the edit show them.
 */

/** This version's notes with what an export needs. $scope: 'open' (not finished) or 'all'; $tracks: keys to keep (null = all). */
function export_notes(int $versionId, string $scope = 'open', ?array $tracks = null, bool $placedOnly = false): array
{
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT n.id, n.author, n.tag, n.text, n.fio_comment_id, n.origin_version_id, p.frame, p.end_frame, p.confirmed,
                (SELECT e.status FROM events e WHERE e.note_id = n.id ORDER BY e.id DESC LIMIT 1) AS status,
                (SELECT e.by_name FROM events e WHERE e.note_id = n.id ORDER BY e.id DESC LIMIT 1) AS status_by
         FROM positions p JOIN notes n ON n.id = p.note_id WHERE p.version_id = ? ORDER BY p.frame, n.id'
    );
    $st->execute([$versionId]);
    $out = [];
    $reps = $pdo->prepare('SELECT author, text FROM replies WHERE note_id = ? ORDER BY created_at, id');
    foreach ($st->fetchAll() as $n) {
        $n['status'] = $n['status'] ?: 'open';
        if ($scope === 'open' && in_array($n['status'], CLOSED_STATUSES, true)) {
            continue;
        }
        if ($placedOnly && !(int)$n['confirmed']) {
            continue;
        }
        $key = explode('-', (string)$n['tag'])[0];
        if ($tracks !== null && !in_array($key, $tracks, true)) {
            continue;
        }
        $reps->execute([$n['id']]);
        $n['replies'] = $reps->fetchAll();
        $n['key'] = $key;
        $out[] = $n;
    }
    return $out;
}

/** The note's text with its track code in front (unless the text already starts with it, like "ARCH-001 : …"). */
function export_text(array $n): string
{
    $t = trim((string)$n['text']);
    $key = (string)$n['key'];
    if ($key !== '' && !preg_match('/^\s*' . preg_quote($key, '/') . '\b/', $t)) {
        $t = $n['tag'] . ': ' . $t;
    }
    return $t;
}

/** One line, for formats that can't hold line breaks. */
function export_flat(string $s, int $max = 1000): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/** Track colour → each edit suite's nearest marker colour. */
function export_color(string $color, string $suite): string
{
    $map = [
        'tag-e' => ['yellow', 'ResolveColorYellow', 'Yellow'], 'tag-n' => ['blue', 'ResolveColorBlue', 'Blue'],
        'tag-a' => ['magenta', 'ResolveColorPurple', 'Purple'], 'cat-green' => ['green', 'ResolveColorGreen', 'Green'],
        'cat-red' => ['red', 'ResolveColorRed', 'Red'], 'cat-teal' => ['cyan', 'ResolveColorCyan', 'Cyan'],
        'cat-pink' => ['magenta', 'ResolveColorPink', 'Rose'], 'cat-olive' => ['yellow', 'ResolveColorLemon', 'Mango'],
        'cat-sand' => ['white', 'ResolveColorSand', 'Tan'], 'cat-gray' => ['white', 'ResolveColorCream', 'Lavender'],
    ];
    $i = ['avid' => 0, 'resolve' => 1, 'xml' => 2][$suite] ?? 0;
    return ($map[$color] ?? ['red', 'ResolveColorRed', 'Red'])[$i];
}

/** Avid Media Composer marker list (Tools → Markers → Import Markers): tab-separated, one marker per line. */
function markers_avid(array $notes, array $version, array $colors): string
{
    $base = (int)$version['tc_base'];
    $start = (int)$version['tc_start'];
    $lines = [];
    foreach ($notes as $n) {
        $dur = $n['end_frame'] !== null ? max(1, (int)$n['end_frame'] - (int)$n['frame']) : 1;
        $lines[] = implode("\t", [
            export_flat((string)$n['author'], 60) ?: 'Note', frames_to_tc((int)$n['frame'] + $start, $base), 'V1',
            export_color($colors[$n['key']] ?? '', 'avid'), str_replace("\t", ' ', export_flat(export_text($n))), (string)$dur,
        ]);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/** DaVinci Resolve marker EDL (Timelines → Import → Timeline Markers from EDL). */
function markers_resolve(array $notes, array $version, array $colors): string
{
    $base = (int)$version['tc_base'];
    $start = (int)$version['tc_start'];
    $out = 'TITLE: ' . export_flat((string)$version['label'], 60) . "\r\nFCM: NON-DROP FRAME\r\n\r\n";
    $i = 1;
    foreach ($notes as $n) {
        $in = (int)$n['frame'] + $start;
        $dur = $n['end_frame'] !== null ? max(1, (int)$n['end_frame'] - (int)$n['frame']) : 1;
        $tin = frames_to_tc($in, $base);
        $tout = frames_to_tc($in + 1, $base);
        $out .= sprintf("%03d  001      V     C        %s %s %s %s  \r\n", $i++, $tin, $tout, $tin, $tout);
        $out .= ' |C:' . export_color($colors[$n['key']] ?? '', 'resolve') . ' |M:' . str_replace('|', '/', export_flat(export_text($n), 400)) . ' |D:' . $dur . "\r\n\r\n";
    }
    return $out;
}

/** Final Cut Pro 7 XML: a sequence holding the notes as markers. Premiere (and Resolve) import it as a new sequence. */
function markers_xml(array $notes, array $version, array $colors, float $durationFrames): string
{
    $base = (int)$version['tc_base'];
    $start = (int)$version['tc_start'];
    $ntsc = abs((float)$version['rate'] - $base) > 0.01 ? 'TRUE' : 'FALSE';
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $rate = "<rate><timebase>$base</timebase><ntsc>$ntsc</ntsc></rate>";
    $len = max(1, (int)ceil($durationFrames));
    $m = '';
    foreach ($notes as $n) {
        $in = (int)$n['frame'];
        $outF = $n['end_frame'] !== null ? (int)$n['end_frame'] : -1;
        $m .= '<marker><name>' . $x(export_flat((string)$n['author'] . ($n['tag'] ? ' · ' . $n['tag'] : ''), 80)) . '</name>'
            . '<comment>' . $x(export_text($n)) . '</comment><in>' . $in . '</in><out>' . $outF . '</out>'
            . '<color>' . export_color($colors[$n['key']] ?? '', 'xml') . '</color></marker>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<!DOCTYPE xmeml>\n<xmeml version=\"5\"><sequence id=\"previouslynoted-markers\">"
        . '<name>' . $x($version['label'] . ' · notes') . "</name><duration>$len</duration>$rate"
        . "<timecode>$rate<string>" . frames_to_tc($start, $base) . "</string><frame>$start</frame><displayformat>NDF</displayformat></timecode>"
        . "<media><video><format><samplecharacteristics>$rate<width>1920</width><height>1080</height></samplecharacteristics></format><track/></video></media>"
        . $m . "</sequence></xmeml>\n";
}

/** The comment text posted to Frame.io. */
function push_text(array $n, string $me, bool $extras): string
{
    $t = export_text($n);
    if ($n['author'] !== '' && $n['author'] !== $me && $n['author'] !== 'Frame.io user') {
        $t .= "\n— " . $n['author'];
    }
    if ($extras) {
        $label = ['open' => 'Open', 'addressed' => 'Addressed', 'approved' => 'Approved', 'discuss' => 'Needs discussion', 'wont_do' => 'Won’t do',
            'removed' => 'Cut from film', 'superseded' => 'Superseded'][$n['status']] ?? $n['status'];
        if ($n['status'] !== 'open') {
            $t .= "\nStatus: $label" . ($n['status_by'] ? ' (' . $n['status_by'] . ')' : '');
        }
        foreach ($n['replies'] as $r) {
            $t .= "\n↳ " . $r['author'] . ': ' . trim((string)$r['text']);
        }
    }
    return mb_substr($t, 0, 4900);
}

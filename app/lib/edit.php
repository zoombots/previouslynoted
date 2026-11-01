<?php
declare(strict_types=1);

/*
 * Edit readers: turn an exported cut into a flat shot list.
 *
 *   .otio   OpenTimelineIO's own JSON (Resolve exports it; Avid AAF converts to it with otioconvert)
 *   .xml    FCP7 XML (Premiere Pro, DaVinci Resolve)
 *   .edl    CMX3600 EDL (Avid Media Composer, and every other editor)
 *
 * The readers follow OpenTimelineIO's adapters (otio_json, fcp_xml, cmx_3600)
 * and are tested against OTIO's reading of the same files.
 *
 * Every shot comes back as:
 *   kind     'V' or 'A'
 *   track    1-based track number within its kind
 *   rec_in   first frame in the cut, counted from the start of the sequence
 *   rec_out  frame after the last
 *   name     clip name
 *   file     media file name (no path), when the format carries it
 *   src_in   source frame shown at rec_in (absolute source timecode, in source frames)
 *   k        source frames per record frame (speed changes and rate differences)
 */

final class EditReadError extends RuntimeException {}

function read_edit_file(string $path, string $filename, int $tcBase): array
{
    $head = (string)file_get_contents($path, false, null, 0, 4096);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (str_starts_with($head, "\xD0\xCF\x11\xE0") || $ext === 'aaf') {
        throw new EditReadError('AAF files can’t be read on the server. Convert it to .otio first: otioconvert -i cut.aaf -o cut.otio (see the README), or export an EDL from Avid instead.');
    }
    if ($ext === 'otioz' || str_starts_with($head, "PK\x03\x04")) {
        throw new EditReadError('That’s a zipped OTIO bundle (.otioz). Export a plain .otio file instead.');
    }
    $trim = ltrim($head, "\xEF\xBB\xBF \t\r\n");
    if ($ext === 'otio' || (str_starts_with($trim, '{') && str_contains($head, 'OTIO_SCHEMA'))) {
        $r = read_otio_json((string)file_get_contents($path));
    } elseif (stripos($head, '<xmeml') !== false || ($ext === 'xml' && str_starts_with($trim, '<'))) {
        if (stripos($head, '<fcpxml') !== false) {
            throw new EditReadError('That’s a Final Cut Pro X XML (.fcpxml). Export "Final Cut Pro 7 XML" from Premiere or Resolve instead.');
        }
        $r = read_fcp7_xml($path);
    } elseif ($ext === 'edl' || preg_match('/^\s*(TITLE:|FCM:|\d{3,6}\s)/mi', $head)) {
        $r = read_cmx_edl((string)file_get_contents($path), $tcBase);
    } else {
        throw new EditReadError('This isn’t an edit file the app can read. Use an .otio file, an FCP7 XML (.xml) or a CMX3600 EDL (.edl).');
    }
    $video = array_filter($r['events'], fn($e) => $e['kind'] === 'V');
    if (!$video) {
        throw new EditReadError('No picture shots were found in that file. Make sure the export includes the video tracks.');
    }
    usort($r['events'], fn($a, $b) => [$a['kind'], $a['track'], $a['rec_in']] <=> [$b['kind'], $b['track'], $b['rec_in']]);
    return $r;
}

/* ------------------------------------------------------------------ */
/* OpenTimelineIO JSON                                                 */
/* ------------------------------------------------------------------ */

function otio_schema(array $o): string
{
    return strtok((string)($o['OTIO_SCHEMA'] ?? ''), '.') ?: '';
}

function otio_rt(?array $rt): array
{
    return [(float)($rt['value'] ?? 0), (float)($rt['rate'] ?? 24) ?: 24.0];
}

function read_otio_json(string $json): array
{
    $root = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($root)) {
        throw new EditReadError('That .otio file isn’t valid JSON.');
    }
    if (otio_schema($root) === 'SerializableCollection') {
        foreach ($root['children'] ?? [] as $child) {
            if (is_array($child) && otio_schema($child) === 'Timeline') {
                $root = $child;
                break;
            }
        }
    }
    if (otio_schema($root) !== 'Timeline') {
        throw new EditReadError('That .otio file doesn’t contain a timeline.');
    }

    // The sequence rate: from the global start time, else the first clip we meet.
    [$gStart, $rate] = otio_rt($root['global_start_time'] ?? null);
    if (empty($root['global_start_time'])) {
        $rate = otio_find_rate($root['tracks'] ?? []) ?? 24.0;
    }
    $events = [];
    $counts = ['V' => 0, 'A' => 0];
    foreach (($root['tracks']['children'] ?? []) as $track) {
        if (!is_array($track) || otio_schema($track) !== 'Track') {
            continue;
        }
        $kind = strtolower((string)($track['kind'] ?? 'Video')) === 'audio' ? 'A' : 'V';
        if (($track['enabled'] ?? true) === false) {
            $counts[$kind]++;
            continue;
        }
        $n = ++$counts[$kind];
        otio_walk_track($track, $kind, $n, 0.0, $rate, $events);
    }
    return [
        'format' => 'otio',
        'name' => (string)($root['name'] ?? ''),
        'rate' => $rate,
        'start' => (int)round($gStart * ($rate / max(1e-9, otio_rt($root['global_start_time'] ?? null)[1]))),
        'events' => $events,
        'warnings' => [],
    ];
}

function otio_find_rate(array $node): ?float
{
    foreach (['source_range'] as $k) {
        if (isset($node[$k]['duration']['rate'])) {
            return (float)$node[$k]['duration']['rate'];
        }
    }
    foreach ($node['children'] ?? [] as $c) {
        if (is_array($c) && ($r = otio_find_rate($c)) !== null) {
            return $r;
        }
    }
    return null;
}

/** Duration of an item in sequence frames. */
function otio_item_frames(array $item, float $seqRate): float
{
    $range = $item['source_range'] ?? null;
    if (!$range) {
        $ref = otio_active_ref($item);
        $range = $ref['available_range'] ?? null;
    }
    if (!$range && in_array(otio_schema($item), ['Stack', 'Track'], true)) {
        // An untrimmed nested composition lasts as long as its longest/summed children.
        $sum = 0.0;
        $max = 0.0;
        foreach ($item['children'] ?? [] as $c) {
            if (!is_array($c) || otio_schema($c) === 'Transition') {
                continue;
            }
            $d = otio_item_frames($c, $seqRate);
            $sum += $d;
            $max = max($max, $d);
        }
        return otio_schema($item) === 'Stack' ? $max : $sum;
    }
    [$d, $r] = otio_rt($range['duration'] ?? null);
    return $d * $seqRate / $r;
}

function otio_active_ref(array $clip): ?array
{
    if (isset($clip['media_references']) && is_array($clip['media_references'])) {
        $key = $clip['active_media_reference_key'] ?? 'DEFAULT_MEDIA';
        return $clip['media_references'][$key] ?? (array_values($clip['media_references'])[0] ?? null);
    }
    return $clip['media_reference'] ?? null;
}

function otio_walk_track(array $track, string $kind, int $n, float $offset, float $seqRate, array &$events): void
{
    $pos = $offset;
    foreach ($track['children'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $schema = otio_schema($item);
        if ($schema === 'Transition') {
            continue; // transitions overlap their neighbours; they don't take time
        }
        $dur = otio_item_frames($item, $seqRate);
        if (($item['enabled'] ?? true) !== false && in_array($schema, ['Clip', 'Stack', 'Track'], true)) {
            $speed = 1.0;
            foreach ($item['effects'] ?? [] as $fx) {
                $fs = otio_schema((array)$fx);
                if ($fs === 'LinearTimeWarp') {
                    $speed *= (float)($fx['time_scalar'] ?? 1);
                } elseif ($fs === 'FreezeFrame') {
                    $speed = 0.0;
                }
            }
            [$sIn, $sRate] = otio_rt($item['source_range']['start_time'] ?? null);
            $file = '';
            if ($schema === 'Clip') {
                $ref = otio_active_ref($item) ?? [];
                $url = (string)($ref['target_url'] ?? $ref['target_url_base'] ?? '');
                $file = $url !== '' ? rawurldecode(basename(str_replace('\\', '/', parse_url($url, PHP_URL_PATH) ?: $url))) : '';
                if (otio_schema($ref) === 'GeneratorReference') {
                    $file = '';
                }
            } else {
                $file = 'seq:' . ($item['name'] ?? '');
            }
            if ($dur >= 0.5) {
                $events[] = [
                    'kind' => $kind,
                    'track' => $n,
                    'rec_in' => (int)round($pos),
                    'rec_out' => (int)round($pos + $dur),
                    'name' => (string)($item['name'] ?? ''),
                    'file' => $file,
                    'src_in' => $sIn,
                    'k' => $speed * $sRate / $seqRate,
                ];
            }
        }
        $pos += $dur;
    }
}

/* ------------------------------------------------------------------ */
/* FCP7 XML (xmeml)                                                    */
/* ------------------------------------------------------------------ */

function xml_int(?DOMNode $n, string $q, DOMXPath $xp, ?int $default = null): ?int
{
    $v = $n ? $xp->evaluate("string($q)", $n) : '';
    return is_numeric($v) ? (int)$v : $default;
}

function xml_str(?DOMNode $n, string $q, DOMXPath $xp): string
{
    return $n ? trim((string)$xp->evaluate("string($q)", $n)) : '';
}

function xml_rate(?DOMNode $n, DOMXPath $xp): array
{
    $tb = xml_int($n, 'rate/timebase', $xp) ?? 0;
    $ntsc = strtoupper(xml_str($n, 'rate/ntsc', $xp)) === 'TRUE';
    return [$tb, $ntsc];
}

function read_fcp7_xml(string $path): array
{
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->load($path, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_use_internal_errors($prev);
    if (!$ok) {
        throw new EditReadError('That XML file couldn’t be read. Export it again as "Final Cut Pro 7 XML".');
    }
    $xp = new DOMXPath($dom);
    // The main sequence: a top-level one (not nested inside a clip) with the most clips.
    $best = null;
    $bestCount = -1;
    foreach ($xp->query('//sequence[not(ancestor::clipitem)]') as $seq) {
        $c = $xp->evaluate('count(.//clipitem)', $seq);
        if ($c > $bestCount) {
            $best = $seq;
            $bestCount = $c;
        }
    }
    if (!$best) {
        throw new EditReadError('No sequence was found in that XML file.');
    }
    [$seqTb, $ntsc] = xml_rate($best, $xp);
    $seqTb = $seqTb ?: 24;

    // Files may be described once and referenced by id afterwards.
    $files = [];
    foreach ($xp->query('//file[@id]') as $f) {
        $id = $f->getAttribute('id');
        if (isset($files[$id]) && $files[$id]['name'] !== '') {
            continue;
        }
        $url = xml_str($f, 'pathurl', $xp);
        [$ftb] = xml_rate($f, $xp);
        $files[$id] = [
            'name' => $url !== '' ? rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: $url)) : xml_str($f, 'name', $xp),
            'tc' => xml_int($f, 'timecode/frame', $xp) ?? 0,
            'tb' => $ftb ?: $seqTb,
        ];
    }

    // Clip items can also be written once and referenced by id (linked audio, repeated clips).
    $clipDefs = [];
    foreach ($xp->query('//clipitem[@id][*]') as $c) {
        $clipDefs[$c->getAttribute('id')] ??= $c;
    }

    $events = [];
    $warnings = [];
    foreach (['video' => 'V', 'audio' => 'A'] as $tag => $kind) {
        $n = 0;
        foreach ($xp->query("media/$tag/track", $best) as $track) {
            $n++;
            if (strtoupper(xml_str($track, 'enabled', $xp)) === 'FALSE') {
                continue;
            }
            $items = [];
            foreach ($track->childNodes as $c) {
                if ($c instanceof DOMElement && in_array($c->nodeName, ['clipitem', 'transitionitem', 'generatoritem'], true)) {
                    $items[] = $c;
                }
            }
            $cursor = 0;
            foreach ($items as $i => $it) {
                if ($it->nodeName === 'transitionitem') {
                    continue;
                }
                $isRef = false;
                if (!$xp->query('*', $it)->length && $it->hasAttribute('id')) {
                    $def = $clipDefs[$it->getAttribute('id')] ?? null;
                    if (!$def) {
                        continue;
                    }
                    $it = $def;
                    $isRef = true;
                }
                if (strtoupper(xml_str($it, 'enabled', $xp)) === 'FALSE') {
                    continue;
                }
                $start = xml_int($it, 'start', $xp, 0);
                $end = xml_int($it, 'end', $xp, 0);
                $in = xml_int($it, 'in', $xp, 0);
                $out = xml_int($it, 'out', $xp, 0);
                [$ctb] = xml_rate($it, $xp);
                $ctb = $ctb ?: $seqTb;
                $lead = 0; // frames of the clip that sit under an incoming dissolve
                if ($start < 0) {
                    $tr = fcp_adjacent($it, 'previousSibling');
                    if (!$tr) {
                        $warnings[] = 'A clip that starts in a transition has no transition before it; it was skipped.';
                        continue;
                    }
                    $start = fcp_cut_point($tr, $xp);
                    $lead = $start - xml_int($tr, 'start', $xp, $start);
                }
                if ($end < 0) {
                    $tr = fcp_adjacent($it, 'nextSibling');
                    if (!$tr) {
                        $warnings[] = 'A clip that ends in a transition has no transition after it; it was skipped.';
                        continue;
                    }
                    $end = fcp_cut_point($tr, $xp);
                }
                if ($isRef && $start < $cursor) {
                    // A repeat of a clip used earlier: it sits where the track has got to.
                    $end = $cursor + ($end - $start);
                    $start = $cursor;
                }
                if ($end <= $start) {
                    continue;
                }
                $cursor = $end;
                $speed = 1.0;
                foreach ($xp->query('filter/effect[effectid="timeremap"]/parameter[parameterid="speed"]/value', $it) as $v) {
                    if (is_numeric($v->textContent)) {
                        $speed = abs((float)$v->textContent) / 100;
                    }
                }
                $name = xml_str($it, 'name', $xp);
                $file = '';
                $tcOffset = 0;
                $nested = $xp->query('sequence', $it)->item(0);
                if ($nested) {
                    $file = 'seq:' . xml_str($nested, 'name', $xp);
                } elseif ($it->nodeName === 'generatoritem') {
                    $file = '';
                } else {
                    $fEl = $xp->query('file', $it)->item(0);
                    $fid = $fEl instanceof DOMElement ? $fEl->getAttribute('id') : '';
                    if ($fid !== '' && isset($files[$fid])) {
                        $file = $files[$fid]['name'];
                        $tcOffset = (int)round($files[$fid]['tc'] * $ctb / max(1, $files[$fid]['tb']));
                    }
                }
                $k = $speed * $ctb / $seqTb;
                $events[] = [
                    'kind' => $kind,
                    'track' => $n,
                    'rec_in' => $start,
                    'rec_out' => $end,
                    'name' => $name !== '' ? $name : $file,
                    'file' => $file,
                    'src_in' => $tcOffset + $in + $lead * $k,
                    'k' => $k,
                ];
            }
        }
    }
    $rate = $ntsc ? round($seqTb * 1000 / 1001, 3) : (float)$seqTb;
    return [
        'format' => 'fcp7',
        'name' => xml_str($best, 'name', $xp),
        'rate' => $rate,
        'start' => xml_int($best, 'timecode/frame', $xp, 0),
        'events' => $events,
        'warnings' => array_values(array_unique($warnings)),
    ];
}

/** The transition right before or after a clip in its own track (for linked clips, the track it's defined in). */
function fcp_adjacent(DOMElement $it, string $dir): ?DOMElement
{
    $n = $it->$dir;
    while ($n && !($n instanceof DOMElement)) {
        $n = $n->$dir;
    }
    return $n instanceof DOMElement && $n->nodeName === 'transitionitem' ? $n : null;
}

/** Where a transition counts as the cut, as OpenTimelineIO's fcp_xml adapter does. */
function fcp_cut_point(DOMElement $tr, DOMXPath $xp): int
{
    $a = strtolower(xml_str($tr, 'alignment', $xp));
    $s = xml_int($tr, 'start', $xp, 0);
    $e = xml_int($tr, 'end', $xp, 0);
    if (in_array($a, ['end', 'end-black'], true)) {
        return $e;
    }
    if (in_array($a, ['start', 'start-black'], true)) {
        return $s;
    }
    return intdiv($s + $e, 2);
}

/* ------------------------------------------------------------------ */
/* CMX3600 EDL                                                          */
/* ------------------------------------------------------------------ */

function edl_tc(string $tc, int $base, bool $drop): ?int
{
    if (!preg_match('/^(\d{1,2})[:;.](\d{2})[:;.](\d{2})[:;.](\d{2})$/', $tc, $m)) {
        return null;
    }
    [$h, $mi, $s, $f] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
    $frames = (($h * 60 + $mi) * 60 + $s) * $base + $f;
    if ($drop && in_array($base, [30, 60], true)) {
        $d = $base === 30 ? 2 : 4;
        $totalMin = $h * 60 + $mi;
        $frames -= $d * ($totalMin - intdiv($totalMin, 10));
    }
    return $frames;
}

function edl_channels(string $ch): array
{
    $ch = strtoupper($ch);
    $out = ['V' => false, 'A' => []];
    if ($ch === 'B' || str_contains($ch, 'V')) {
        $out['V'] = true;
    }
    if ($ch === 'B') {
        $out['A'] = [1];
    }
    if (preg_match_all('/A(\d*)/', $ch, $m)) {
        foreach ($m[1] as $i => $num) {
            if ($num === '' && str_contains($ch, 'AA')) {
                $out['A'] = [1, 2];
                break;
            }
            $out['A'][] = $num === '' ? 1 : (int)$num;
        }
    }
    $out['A'] = array_values(array_unique($out['A']));
    return $out;
}

function read_cmx_edl(string $text, int $tcBase): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $title = '';
    $drop = false;
    $tc = '(\d{1,2}[:;.]\d{2}[:;.]\d{2}[:;.]\d{2})';
    $re = '/^\s*(\d{1,6})\s+(\S+)\s+(\S+)\s+(C|D|W\d{3}|K[BO]?|KB|KO)\s+(?:(\d{1,4})\s+)?' . $tc . '\s+' . $tc . '\s+' . $tc . '\s+' . $tc . '\s*$/i';
    $raw = []; // one entry per edit line
    $lastEvent = null;
    foreach ($lines as $line) {
        if (preg_match('/^\s*TITLE:\s*(.*)$/i', $line, $m)) {
            $title = trim($m[1]);
            continue;
        }
        if (preg_match('/^\s*FCM:\s*(.*)$/i', $line, $m)) {
            $drop = stripos($m[1], 'NON') === false && stripos($m[1], 'DROP') !== false;
            continue;
        }
        if (preg_match($re, $line, $m)) {
            $isDrop = $drop || str_contains($m[6] . $m[8], ';');
            $raw[] = [
                'event' => $m[1], 'reel' => $m[2], 'ch' => $m[3], 'type' => strtoupper($m[4]),
                'src_in' => edl_tc($m[6], $tcBase, $isDrop), 'src_out' => edl_tc($m[7], $tcBase, $isDrop),
                'rec_in' => edl_tc($m[8], $tcBase, $isDrop), 'rec_out' => edl_tc($m[9], $tcBase, $isDrop),
                'name' => '', 'file' => '', 'speed' => null,
            ];
            $lastEvent = $m[1];
            continue;
        }
        if ($lastEvent === null) {
            continue;
        }
        // Comments and motion lines belong to the event just above them.
        $idx = array_keys(array_filter($raw, fn($r) => $r['event'] === $lastEvent));
        $first = $idx[0];
        $second = $idx[1] ?? $idx[0];
        if (preg_match('/^\s*\*\s*FROM CLIP NAME:\s*(.*)$/i', $line, $m)) {
            $raw[$first]['name'] = trim($m[1]);
        } elseif (preg_match('/^\s*\*\s*TO CLIP NAME:\s*(.*)$/i', $line, $m)) {
            $raw[$second]['name'] = trim($m[1]);
        } elseif (preg_match('/^\s*\*\s*(?:SOURCE FILE|FROM FILE|FROM CLIP)\s*:\s*(.*)$/i', $line, $m)) {
            $raw[$first]['file'] = edl_file_name(trim($m[1]));
        } elseif (preg_match('/^\s*\*\s*(?:TO FILE|TO CLIP)\s*:\s*(.*)$/i', $line, $m)) {
            $raw[$second]['file'] = edl_file_name(trim($m[1]));
        } elseif (preg_match('/^\s*M2\s+(\S+)\s+(-?\d+(?:\.\d+)?)\s+' . $tc . '/i', $line, $m)) {
            foreach ($idx as $i) {
                if ($raw[$i]['reel'] === $m[1]) {
                    $raw[$i]['speed'] = abs((float)$m[2]) / $tcBase;
                }
            }
        }
    }
    $events = [];
    $minRec = PHP_INT_MAX;
    foreach ($raw as $r) {
        if ($r['rec_in'] === null || $r['rec_out'] === null || $r['rec_out'] <= $r['rec_in']) {
            continue;
        }
        if (strtoupper($r['reel']) === 'BL' || strtoupper($r['reel']) === 'BLACK') {
            continue; // black filler
        }
        $minRec = min($minRec, $r['rec_in']);
        $ch = edl_channels($r['ch']);
        $speed = $r['speed'] ?? 1.0;
        $base = [
            'rec_in' => $r['rec_in'], 'rec_out' => $r['rec_out'],
            'name' => $r['name'] !== '' ? $r['name'] : $r['reel'],
            'file' => $r['file'], 'src_in' => (float)$r['src_in'], 'k' => $speed,
            'reel' => $r['reel'],
        ];
        if ($ch['V']) {
            $events[] = ['kind' => 'V', 'track' => 1] + $base;
        }
        foreach ($ch['A'] as $a) {
            $events[] = ['kind' => 'A', 'track' => $a] + $base;
        }
    }
    if (!$events) {
        throw new EditReadError('No edit events were found in that EDL.');
    }
    // Record timecode usually starts at 01:00:00:00; the uploaded video starts at the cut's first frame.
    $hour = 3600 * $tcBase;
    $rem = $minRec % $hour;
    $start = $rem < 10 * 60 * $tcBase ? $minRec - $rem : $minRec;
    foreach ($events as &$e) {
        $e['rec_in'] -= $start;
        $e['rec_out'] -= $start;
        unset($e['reel']);
    }
    unset($e);
    return ['format' => 'edl', 'name' => $title, 'rate' => (float)$tcBase, 'start' => $start, 'events' => $events, 'warnings' => []];
}

function edl_file_name(string $s): string
{
    $path = parse_url($s, PHP_URL_PATH) ?: $s;
    return rawurldecode(basename(str_replace('\\', '/', $path)));
}

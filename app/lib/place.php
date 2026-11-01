<?php
declare(strict_types=1);

/*
 * Placing carried notes in a new cut.
 *
 * For each note carried from version A into version B, find the same moment in B:
 *   Narration notes (N): by the transcript line, then by the VO clip on an audio track, then by picture.
 *   Everything else:     by picture. The source frame the note sat on in A is looked up in B's shots.
 *
 * A result is either confident (the note is placed and marked so) or needs a look,
 * with a reason the editor can act on: the shot was removed, trimmed, used twice,
 * or the narration line was reworded or dropped.
 */

const PLACE_CONFIDENT = ['picture', 'transcript', 'narration-clip'];

function active_edit(int $versionId): ?array
{
    $st = db()->prepare('SELECT * FROM edits WHERE version_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$versionId]);
    return $st->fetch() ?: null;
}

function active_transcript(int $versionId): ?array
{
    $st = db()->prepare('SELECT * FROM transcripts WHERE version_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$versionId]);
    return $st->fetch() ?: null;
}

function transcript_segments(int $transcriptId): array
{
    $st = db()->prepare('SELECT idx, start_frame AS start, end_frame AS "end", speaker, text, norm FROM segments WHERE transcript_id = ? ORDER BY idx');
    $st->execute([$transcriptId]);
    return array_map(fn($r) => ['start' => (int)$r['start'], 'end' => (int)$r['end']] + $r, $st->fetchAll());
}

/** Frames in an edit's sequence per frame of the version's timecode. */
function edit_scale(array $edit, int $tcBase): float
{
    return max(1, (int)round((float)$edit['rate'])) / max(1, $tcBase);
}

function source_keys(string $name, string $file): array
{
    $keys = [];
    foreach ([$file, $name] as $x) {
        $x = mb_strtolower(trim($x));
        if ($x === '') {
            continue;
        }
        $x = preg_replace('/\s*\([^)]*\)$/', '', $x);
        $x = preg_replace('/\.[a-z0-9]{2,5}$/', '', $x);
        if ($x !== '') {
            $keys[$x] = true;
        }
    }
    return array_keys($keys);
}

/** Shots for an edit, grouped by kind, each with its matching keys, plus an index from key to shots. */
function load_shots(int $editId): array
{
    $st = db()->prepare('SELECT kind, track, rec_in, rec_out, name, file, src_in, k FROM shots WHERE edit_id = ? ORDER BY kind, track, rec_in');
    $st->execute([$editId]);
    $out = ['V' => [], 'A' => [], 'index' => ['V' => [], 'A' => []], 'end' => 0];
    foreach ($st as $r) {
        $s = [
            'kind' => $r['kind'], 'track' => (int)$r['track'], 'rec_in' => (int)$r['rec_in'], 'rec_out' => (int)$r['rec_out'],
            'name' => $r['name'], 'file' => $r['file'], 'src_in' => (float)$r['src_in'], 'k' => (float)$r['k'],
        ];
        $s['keys'] = source_keys($s['name'], $s['file']);
        $i = count($out[$s['kind']]);
        $out[$s['kind']][] = $s;
        foreach ($s['keys'] as $key) {
            $out['index'][$s['kind']][$key][] = $i;
        }
        $out['end'] = max($out['end'], $s['rec_out']);
    }
    return $out;
}

function shots_at(array $shots, string $kind, float $f): array
{
    $hit = array_values(array_filter($shots[$kind], fn($s) => $s['rec_in'] <= $f && $f < $s['rec_out']));
    usort($hit, fn($a, $b) => $b['track'] <=> $a['track']); // the top layer is what's on screen
    return $hit;
}

function shot_src_len(array $s): float
{
    return ($s['rec_out'] - $s['rec_in']) * $s['k'];
}

/**
 * Find source frame $src of shot $a (from cut A) in cut B.
 * Returns ['frame' => B frame, 'reason' => picture|ambiguous|trimmed] or ['reason' => 'removed'].
 */
function map_source(array $a, float $src, array $B, string $kind, float $hint): array
{
    $cands = [];
    foreach ($a['keys'] as $key) {
        foreach ($B['index'][$kind][$key] ?? [] as $i) {
            $cands[$i] = $B[$kind][$i];
        }
    }
    if (!$cands) {
        return ['reason' => 'removed'];
    }
    $inside = [];
    foreach ($cands as $b) {
        $len = shot_src_len($b);
        if ($b['k'] == 0.0 ? abs($src - $b['src_in']) < 0.5 : ($src >= $b['src_in'] - 0.5 && $src < $b['src_in'] + $len + 0.5)) {
            $off = $b['k'] == 0.0 ? 0 : ($src - $b['src_in']) / $b['k'];
            $inside[] = (int)round(min(max($b['rec_in'] + $off, $b['rec_in']), $b['rec_out'] - 1));
        }
    }
    $inside = array_values(array_unique($inside));
    if (count($inside) === 1) {
        return ['frame' => $inside[0], 'reason' => 'picture'];
    }
    if (count($inside) > 1) {
        usort($inside, fn($x, $y) => abs($x - $hint) <=> abs($y - $hint));
        return ['frame' => $inside[0], 'reason' => 'ambiguous'];
    }
    // Same source, but not this frame: it was trimmed out. Go to the nearest surviving edge.
    $best = null;
    $bestD = INF;
    foreach ($cands as $b) {
        $lo = $b['src_in'];
        $hi = $b['src_in'] + shot_src_len($b);
        $d = $src < $lo ? $lo - $src : $src - $hi;
        if ($d < $bestD) {
            $bestD = $d;
            $best = $src < $lo ? $b['rec_in'] : $b['rec_out'] - 1;
        }
    }
    return ['frame' => (int)$best, 'reason' => 'trimmed'];
}

/** Picture placement: try each layer at the frame, top first. */
function place_by_picture(float $fa, array $A, array $B, float $hint): ?array
{
    $layers = shots_at($A, 'V', $fa);
    if (!$layers) {
        return null;
    }
    $fallback = null;
    foreach ($layers as $a) {
        $src = $a['src_in'] + ($fa - $a['rec_in']) * $a['k'];
        $r = map_source($a, $src, $B, 'V', $hint);
        if ($r['reason'] === 'picture') {
            return $r + ['shot' => $a['name']];
        }
        if ($r['reason'] !== 'removed' && !$fallback) {
            $fallback = $r + ['shot' => $a['name']];
        }
    }
    if ($fallback) {
        return $fallback;
    }
    // Every layer's shot is gone. Land just after the nearest earlier shot that survived.
    $base = end($layers);
    $track = array_values(array_filter($A['V'], fn($s) => $s['track'] === $base['track'] && $s['rec_out'] <= $base['rec_in']));
    for ($i = count($track) - 1, $tries = 0; $i >= 0 && $tries < 12; $i--, $tries++) {
        $prev = $track[$i];
        $src = $prev['src_in'] + ($prev['rec_out'] - 1 - $prev['rec_in']) * $prev['k'];
        $r = map_source($prev, $src, $B, 'V', $hint);
        if ($r['reason'] === 'picture') {
            return ['frame' => $r['frame'] + 1, 'reason' => 'removed', 'shot' => $base['name']];
        }
    }
    return ['frame' => (int)round($hint), 'reason' => 'removed', 'shot' => $base['name']];
}

/** VO placement: an audio clip at the frame that isn't the picture's own sync sound. */
function place_by_vo(float $fa, array $A, array $B, float $hint): ?array
{
    $sync = [];
    foreach (shots_at($A, 'V', $fa) as $v) {
        foreach ($v['keys'] as $k) {
            $sync[$k] = true;
        }
    }
    foreach (shots_at($A, 'A', $fa) as $a) {
        if (array_intersect_key(array_flip($a['keys']), $sync)) {
            continue;
        }
        $src = $a['src_in'] + ($fa - $a['rec_in']) * $a['k'];
        $r = map_source($a, $src, $B, 'A', $hint);
        if ($r['reason'] === 'picture') {
            return ['frame' => $r['frame'], 'reason' => 'narration-clip', 'shot' => $a['name']];
        }
    }
    return null;
}

/** Transcript placement for narration notes. */
function place_by_transcript(int $f, array $segA, array $segB, float $hint, int $tcBase): ?array
{
    $seg = null;
    foreach ($segA as $s) {
        if ($s['start'] <= $f && $f < $s['end']) {
            $seg = $s;
            break;
        }
    }
    if (!$seg) {
        // A note made just before or after a line belongs to it.
        foreach ($segA as $s) {
            if ($s['start'] > $f && $s['start'] - $f <= 3 * $tcBase) {
                $seg = $s;
                break;
            }
        }
        if (!$seg) {
            foreach (array_reverse($segA) as $s) {
                if ($s['end'] <= $f && $f - $s['end'] <= 3 * $tcBase) {
                    $seg = $s;
                    break;
                }
            }
        }
    }
    if (!$seg || $seg['norm'] === '') {
        return null;
    }
    $offset = max(0, min($f - $seg['start'], max(0, $seg['end'] - $seg['start'] - 1)));
    $exact = array_values(array_filter($segB, fn($s) => $s['norm'] === $seg['norm']));
    if ($exact) {
        usort($exact, fn($x, $y) => abs($x['start'] - $hint) <=> abs($y['start'] - $hint));
        $b = $exact[0];
        return ['frame' => $b['start'] + min($offset, max(0, $b['end'] - $b['start'] - 1)), 'reason' => count($exact) > 1 ? 'ambiguous' : 'transcript', 'line' => $seg['text']];
    }
    $wa = transcript_words($seg['norm']);
    $best = null;
    $bestSim = 0.0;
    foreach ($segB as $s) {
        $sim = transcript_similarity($wa, transcript_words($s['norm']));
        if ($sim > $bestSim) {
            $bestSim = $sim;
            $best = $s;
        }
    }
    if ($best && $bestSim >= 0.6) {
        return ['frame' => $best['start'] + min($offset, max(0, $best['end'] - $best['start'] - 1)), 'reason' => 'line-reworded', 'line' => $seg['text']];
    }
    return ['reason' => 'line-removed', 'line' => $seg['text']];
}

/**
 * Place the carried notes of one version. Notes someone has already placed by hand are left alone.
 * Returns counts by outcome, or null when there's nothing to place from.
 */
function place_carried_notes(int $versionId): ?array
{
    $pdo = db();
    $v = $pdo->prepare('SELECT * FROM versions WHERE id = ?');
    $v->execute([$versionId]);
    $B = $v->fetch();
    if (!$B || !$B['carried_from']) {
        return null;
    }
    $v->execute([(int)$B['carried_from']]);
    $A = $v->fetch();
    if (!$A) {
        return null;
    }
    $eA = active_edit((int)$A['id']);
    $eB = active_edit((int)$B['id']);
    $tA = active_transcript((int)$A['id']);
    $tB = active_transcript((int)$B['id']);
    $haveEdits = $eA && $eB;
    $haveScripts = $tA && $tB;
    if (!$haveEdits && !$haveScripts) {
        return null;
    }
    $shotsA = $haveEdits ? load_shots((int)$eA['id']) : null;
    $shotsB = $haveEdits ? load_shots((int)$eB['id']) : null;
    $segA = $haveScripts ? transcript_segments((int)$tA['id']) : [];
    $segB = $haveScripts ? transcript_segments((int)$tB['id']) : [];
    $baseA = (int)$A['tc_base'];
    $baseB = (int)$B['tc_base'];
    $scA = $haveEdits ? edit_scale($eA, $baseA) : 1.0;
    $scB = $haveEdits ? edit_scale($eB, $baseB) : 1.0;
    $lenRatio = $haveEdits && $shotsA['end'] > 0 ? $shotsB['end'] / $shotsA['end'] : 1.0;

    $st = $pdo->prepare(
        "SELECT pb.note_id, pa.frame AS frame_a, pa.confirmed AS confirmed_a, n.tag
         FROM positions pb
         JOIN positions pa ON pa.note_id = pb.note_id AND pa.version_id = ?
         JOIN notes n ON n.id = pb.note_id
         WHERE pb.version_id = ? AND pb.method IN ('carried', 'auto')"
    );
    $st->execute([(int)$A['id'], $versionId]);
    // The out-point keeps its length when the note moves.
    $upd = $pdo->prepare('UPDATE positions SET end_frame = CASE WHEN end_frame IS NULL THEN NULL ELSE end_frame - frame + ? END,
        frame = ?, confirmed = ?, method = ?, reason = ? WHERE note_id = ? AND version_id = ?');
    // Tracks whose notes follow the narration (transcript and VO clips) rather than the picture.
    $narration = array_column(array_filter(film_categories((int)$B['film_id']), fn($c) => $c['kind'] === 'narration'), 'key');

    $stats = ['total' => 0, 'placed' => 0, 'review' => 0, 'unplaced' => 0, 'reasons' => []];
    $pdo->beginTransaction();
    foreach ($st->fetchAll() as $row) {
        $stats['total']++;
        $f = (int)$row['frame_a'];
        $hintB = $f * $lenRatio; // a rough guess of where things land, for tie-breaks
        $isN = in_array(explode('-', (string)$row['tag'])[0], $narration, true);
        $result = null;
        $lineFlag = null;

        if ($isN && $haveScripts) {
            $r = place_by_transcript($f, $segA, $segB, $hintB, $baseA);
            if ($r && isset($r['frame'])) {
                $result = $r;
            } elseif ($r) {
                $lineFlag = $r['reason']; // line-removed: still find where the moment went
            }
        }
        if (!$result && $haveEdits) {
            $fa = $f * $scA;
            if ($fa < $shotsA['end']) {
                $hint = $hintB * $scB;
                $r = ($isN ? place_by_vo($fa, $shotsA, $shotsB, $hint) : null) ?? place_by_picture($fa, $shotsA, $shotsB, $hint);
                if ($r) {
                    $r['frame'] = (int)round($r['frame'] / $scB);
                    $result = $r;
                }
            } else {
                $result = ['reason' => 'beyond-edit'];
            }
        }
        if ($lineFlag && $result && isset($result['frame'])) {
            $result['reason'] = $lineFlag;
        } elseif ($lineFlag && !$result) {
            $result = ['reason' => $lineFlag];
        }

        if (!$result || !isset($result['frame'])) {
            $reason = $result['reason'] ?? null;
            $upd->execute([$f, $f, 0, 'carried', $reason, $row['note_id'], $versionId]);
            $stats['unplaced']++;
            if ($reason) {
                $stats['reasons'][$reason] = ($stats['reasons'][$reason] ?? 0) + 1;
            }
            continue;
        }
        $confident = in_array($result['reason'], PLACE_CONFIDENT, true) && (int)$row['confirmed_a'] === 1;
        $nf = max(0, (int)$result['frame']);
        $upd->execute([$nf, $nf, $confident ? 1 : 0, 'auto', $result['reason'], $row['note_id'], $versionId]);
        $stats[$confident ? 'placed' : 'review']++;
        $stats['reasons'][$result['reason']] = ($stats['reasons'][$result['reason']] ?? 0) + 1;
    }
    $pdo->commit();
    return $stats;
}

/** After new edit or transcript data arrives for a version, re-place it and anything carried from it. */
function place_around(int $versionId): array
{
    $results = [];
    if ($r = place_carried_notes($versionId)) {
        $results[$versionId] = $r;
    }
    $st = db()->prepare('SELECT id FROM versions WHERE carried_from = ?');
    $st->execute([$versionId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $child) {
        if ($r = place_carried_notes((int)$child)) {
            $results[(int)$child] = $r;
        }
    }
    return $results;
}

function store_edit(int $versionId, array $parsed, string $filename, string $by): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO edits (version_id, filename, format, seq_name, rate, start_frame, shot_count, warnings, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$versionId, mb_substr($filename, 0, 200), $parsed['format'], mb_substr((string)$parsed['name'], 0, 200), $parsed['rate'], $parsed['start'], count($parsed['events']), $parsed['warnings'] ? implode("\n", $parsed['warnings']) : null, $by, now_iso()]);
    $editId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO shots (edit_id, kind, track, rec_in, rec_out, name, file, src_in, k) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($parsed['events'] as $e) {
        $ins->execute([$editId, $e['kind'], $e['track'], $e['rec_in'], $e['rec_out'], mb_substr($e['name'], 0, 300), mb_substr($e['file'], 0, 300), $e['src_in'], $e['k']]);
    }
    // Only the latest edit per version is used; drop older shot lists to keep the database small.
    $pdo->prepare('DELETE FROM shots WHERE edit_id IN (SELECT id FROM edits WHERE version_id = ? AND id <> ?)')->execute([$versionId, $editId]);
    $pdo->commit();
    return ['id' => $editId];
}

function store_transcript(int $versionId, array $parsed, string $filename, string $by): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO transcripts (version_id, filename, format, offset_frames, segment_count, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$versionId, mb_substr($filename, 0, 200), $parsed['format'], $parsed['offset'], count($parsed['segments']), $by, now_iso()]);
    $tid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO segments (transcript_id, idx, start_frame, end_frame, speaker, text, norm) VALUES (?,?,?,?,?,?,?)');
    foreach ($parsed['segments'] as $i => $s) {
        $ins->execute([$tid, $i, $s['start'], $s['end'], $s['speaker'], $s['text'], $s['norm']]);
    }
    $pdo->commit();
    return $tid;
}

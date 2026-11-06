<?php
declare(strict_types=1);

/*
 * The new script, line by line.
 *
 * Every change to a line is a new row in script_edits, so the full history stays.
 * A line is identified by the transcript segment it belongs to (and, if the transcript is
 * re-uploaded, by its wording), plus a slot: 0 is the line itself, 1, 2… are new lines
 * written after it.
 *
 * States: same (no change), draft, approved, cut (line to be dropped), removed (an added line taken out).
 */

const SCRIPT_STATES = ['same', 'draft', 'approved', 'cut', 'removed'];

/** Which segment of the current transcript a saved row (edit or timing) is about, or null if it's gone. */
function script_row_segment(array $r, int $transcriptId, array $segs, array $byNorm): ?int
{
    if ((int)$r['transcript_id'] === $transcriptId && isset($segs[(int)$r['seg_idx']])) {
        return (int)$r['seg_idx'];
    }
    return $byNorm[$r['seg_norm']][0] ?? null;
}

function script_by_norm(array $segs): array
{
    $byNorm = [];
    foreach ($segs as $i => $s) {
        if ($s['norm'] !== '') {
            $byNorm[$s['norm']][] = $i;
        }
    }
    return $byNorm;
}

function script_lines(int $versionId, int $transcriptId, array $segs): array
{
    $st = db()->prepare('SELECT * FROM script_edits WHERE version_id = ? ORDER BY id');
    $st->execute([$versionId]);
    $rows = $st->fetchAll();
    $byNorm = script_by_norm($segs);
    $lines = [];
    foreach ($rows as $r) {
        $i = script_row_segment($r, $transcriptId, $segs, $byNorm);
        if ($i === null) {
            continue; // the line isn't in this transcript any more
        }
        $key = $i . ':' . (int)$r['slot'];
        $entry = ['state' => $r['state'], 'text' => $r['text'], 'by' => $r['by_name'], 'at' => $r['at']];
        $lines[$key] ??= ['i' => $i, 'slot' => (int)$r['slot'], 'history' => []];
        $lines[$key]['history'][] = $entry;
        $lines[$key] = array_merge($lines[$key], $entry);
    }
    return $lines;
}

/** Lines whose new wording turns up in a later cut's transcript have been recorded. */
function script_recorded(int $versionId, array $lines): array
{
    $st = db()->prepare('SELECT id, label FROM versions WHERE carried_from = ? ORDER BY id');
    $st->execute([$versionId]);
    $later = [];
    foreach ($st->fetchAll() as $v) {
        if ($t = active_transcript((int)$v['id'])) {
            $later[] = ['label' => $v['label'], 'segs' => transcript_segments((int)$t['id'])];
        }
    }
    if (!$later) {
        return $lines;
    }
    foreach ($lines as &$l) {
        if (!in_array($l['state'], ['draft', 'approved'], true) || trim($l['text']) === '') {
            continue;
        }
        $w = transcript_words(transcript_norm($l['text']));
        foreach ($later as $v) {
            foreach ($v['segs'] as $s) {
                if (transcript_similarity($w, transcript_words($s['norm'])) >= 0.8) {
                    $l['recorded'] = ['label' => $v['label'], 'start' => $s['start']];
                    break 2;
                }
            }
        }
    }
    unset($l);
    return $lines;
}

/**
 * When each line of the new script plays: [key "i:slot" => [start, end]] in frames.
 * A line keeps the transcript's timing unless someone moved it on the timeline. A new line goes after the line it
 * follows (or the new line before it), lasting as long as its words take to say (about 2.5 words a second, at least
 * a second).
 */
function script_times(int $versionId, int $transcriptId, array $segs, array $lines, float $fps): array
{
    $st = db()->prepare('SELECT * FROM script_times WHERE version_id = ? ORDER BY id');
    $st->execute([$versionId]);
    $byNorm = script_by_norm($segs);
    $set = [];
    foreach ($st->fetchAll() as $r) {
        $i = script_row_segment($r, $transcriptId, $segs, $byNorm);
        if ($i !== null) {
            $set[$i . ':' . (int)$r['slot']] = [(int)$r['start_frame'], (int)$r['end_frame']];
        }
    }
    $times = [];
    foreach ($segs as $i => $s) {
        $times["$i:0"] = $set["$i:0"] ?? [(int)$s['start'], (int)$s['end']];
        $prevEnd = $times["$i:0"][1];
        $extra = array_filter($lines, fn($x) => $x['i'] === $i && $x['slot'] > 0 && $x['state'] !== 'removed');
        usort($extra, fn($a, $b) => $a['slot'] <=> $b['slot']);
        foreach ($extra as $x) {
            $key = "$i:{$x['slot']}";
            $words = max(1, count(preg_split('/\s+/u', trim((string)$x['text']), -1, PREG_SPLIT_NO_EMPTY)));
            $times[$key] = $set[$key] ?? [$prevEnd, $prevEnd + (int)round(max(1.0, $words / 2.5) * $fps)];
            $prevEnd = $times[$key][1];
        }
    }
    return $times;
}

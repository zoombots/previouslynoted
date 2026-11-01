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

function script_lines(int $versionId, int $transcriptId, array $segs): array
{
    $st = db()->prepare('SELECT * FROM script_edits WHERE version_id = ? ORDER BY id');
    $st->execute([$versionId]);
    $rows = $st->fetchAll();
    $byNorm = [];
    foreach ($segs as $i => $s) {
        if ($s['norm'] !== '') {
            $byNorm[$s['norm']][] = $i;
        }
    }
    $lines = [];
    foreach ($rows as $r) {
        // Which segment of the current transcript is this edit about?
        if ((int)$r['transcript_id'] === $transcriptId && isset($segs[(int)$r['seg_idx']])) {
            $i = (int)$r['seg_idx'];
        } elseif (isset($byNorm[$r['seg_norm']])) {
            $i = $byNorm[$r['seg_norm']][0];
        } else {
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

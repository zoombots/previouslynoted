<?php
declare(strict_types=1);

/*
 * Transcript readers. Accepted:
 *   SRT and WebVTT captions (Premiere, Resolve, Frame.io, most transcription services)
 *   Timecoded text, one line or block per segment, e.g. Premiere's transcript export:
 *       00:01:22:06 - 00:01:27:10
 *       Narrator
 *       Fire renews the boreal forest.
 *   "Speaker  0:05" blocks (Otter, Descript)
 *
 * Times become frames counted from the start of the cut, in the version's timecode base.
 */

final class TranscriptReadError extends RuntimeException {}

function transcript_norm(string $s): string
{
    $s = mb_strtolower(strip_tags($s));
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Turn "01:22:06" style strings into frames. Frame timecode uses the timecode base; clock time uses the real rate. */
function transcript_time(string $t, int $tcBase, float $rate): ?int
{
    $t = trim($t, "[]() \t");
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})[:;](\d{2})$/', $t, $m)) {
        return (((int)$m[1] * 60 + (int)$m[2]) * 60 + (int)$m[3]) * $tcBase + (int)$m[4];
    }
    if (preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?$/', $t, $m)) {
        $sec = ((int)$m[1] * 60 + (int)$m[2]) * 60 + (int)$m[3] + (isset($m[4]) && $m[4] !== '' ? (float)('0.' . str_pad($m[4], 3, '0')) : 0);
        return (int)round($sec * $rate);
    }
    return null;
}

function parse_transcript(string $text, int $tcBase, float $rate): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $segs = [];
    $time = '(\d{1,2}:\d{2}(?::\d{2})?(?:[.,:;]\d{1,3})?)';
    $format = 'text';

    if (preg_match('/-->/', $text)) {
        // SRT / WebVTT
        $format = str_starts_with(ltrim($text), 'WEBVTT') ? 'vtt' : 'srt';
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match('/^\s*' . $time . '\s*-->\s*' . $time . '/', $line, $m)) {
                if ($cur && $cur['text'] !== '') {
                    $segs[] = $cur;
                }
                $cur = ['start' => transcript_time(str_replace(',', '.', $m[1]), $tcBase, $rate), 'end' => transcript_time(str_replace(',', '.', $m[2]), $tcBase, $rate), 'speaker' => '', 'text' => ''];
                continue;
            }
            if (!$cur) {
                continue;
            }
            if (trim($line) === '') {
                if ($cur['text'] !== '') {
                    $segs[] = $cur;
                }
                $cur = null;
                continue;
            }
            if (preg_match('/^\s*\d+\s*$/', $line) && $cur['text'] === '') {
                continue;
            }
            if (preg_match('/<v(?:\.[^ >]+)?\s+([^>]+)>/', $line, $m)) {
                $cur['speaker'] = trim($m[1]);
            }
            $cur['text'] = trim($cur['text'] . ' ' . trim(strip_tags($line)));
        }
        if ($cur && $cur['text'] !== '') {
            $segs[] = $cur;
        }
    } else {
        // Timecoded text
        $cur = null;
        $pending = [];
        $flush = function () use (&$cur, &$pending, &$segs) {
            if (!$cur) {
                return;
            }
            $body = array_values(array_filter(array_map('trim', $pending), fn($l) => $l !== ''));
            if ($cur['speaker'] === '' && count($body) > 1 && mb_strlen($body[0]) <= 40 && !preg_match('/[.!?,;…]$/u', $body[0])) {
                $cur['speaker'] = array_shift($body);
            }
            $cur['text'] = trim($cur['text'] . ' ' . implode(' ', $body));
            if ($cur['text'] !== '') {
                $segs[] = $cur;
            }
            $cur = null;
            $pending = [];
        };
        foreach ($lines as $line) {
            $l = trim($line);
            // "00:01:22:06 - 00:01:27:10  optional text"  or  "[00:01:22] Speaker: text"
            if (preg_match('/^\[?' . $time . '\]?(?:\s*(?:-|–|to)\s*\[?' . $time . '\]?)?\s*(.*)$/u', $l, $m) && transcript_time($m[1], $tcBase, $rate) !== null) {
                $flush();
                $rest = trim($m[3]);
                $speaker = '';
                if (preg_match('/^([^:]{1,30}):\s+(.+)$/u', $rest, $sm) && !preg_match('/\d/', $sm[1])) {
                    $speaker = trim($sm[1]);
                    $rest = $sm[2];
                }
                $cur = ['start' => transcript_time($m[1], $tcBase, $rate), 'end' => $m[2] !== '' ? transcript_time($m[2], $tcBase, $rate) : null, 'speaker' => $speaker, 'text' => $rest];
                continue;
            }
            // "Speaker Name  0:05" (Otter / Descript)
            if (preg_match('/^(.{1,40}?)\s{2,}' . $time . '$/u', $l, $m) && transcript_time($m[2], $tcBase, $rate) !== null) {
                $flush();
                $cur = ['start' => transcript_time($m[2], $tcBase, $rate), 'end' => null, 'speaker' => trim($m[1]), 'text' => ''];
                continue;
            }
            if ($cur) {
                $pending[] = $l;
            }
        }
        $flush();
    }

    $segs = array_values(array_filter($segs, fn($s) => $s['start'] !== null));
    if (count($segs) < 1) {
        throw new TranscriptReadError('No timed lines were found. Upload an .srt or .vtt file, or a text transcript with a timecode at the start of each line or paragraph.');
    }
    usort($segs, fn($a, $b) => $a['start'] <=> $b['start']);

    // Transcripts made from a sequence often start at 01:00:00:00; the video starts at the cut's first frame.
    $hour = 3600 * $tcBase;
    $offset = 0;
    if ($segs[0]['start'] >= $hour) {
        $offset = $segs[0]['start'] - ($segs[0]['start'] % $hour);
    }
    $n = count($segs);
    foreach ($segs as $i => &$s) {
        $s['start'] -= $offset;
        $next = $i + 1 < $n ? $segs[$i + 1]['start'] - $offset : null;
        if ($s['end'] === null || $s['end'] - $offset <= $s['start']) {
            $s['end'] = $next !== null && $next > $s['start'] ? min($next, $s['start'] + 10 * $tcBase) : $s['start'] + 5 * $tcBase;
        } else {
            $s['end'] -= $offset;
        }
        $s['speaker'] = mb_substr($s['speaker'], 0, 60);
        $s['text'] = mb_substr($s['text'], 0, 4000);
        $s['norm'] = transcript_norm($s['text']);
    }
    unset($s);
    return ['format' => $format, 'offset' => $offset, 'segments' => $segs];
}

/** How alike two lines are, 0–1, by shared words. */
function transcript_similarity(array $wa, array $wb): float
{
    $na = array_sum($wa);
    $nb = array_sum($wb);
    if (!$na || !$nb) {
        return 0.0;
    }
    $common = 0;
    foreach ($wa as $w => $c) {
        if (isset($wb[$w])) {
            $common += min($c, $wb[$w]);
        }
    }
    return 2 * $common / ($na + $nb);
}

function transcript_words(string $norm): array
{
    return $norm === '' ? [] : array_count_values(explode(' ', $norm));
}

/**
 * Compare a transcript with the previous version's.
 * Returns for each new segment: ['change' => 'same'|'reworded'|'new', 'prev' => old text], plus the old lines that are gone.
 */
function transcript_compare(array $new, array $old): array
{
    $oldWords = [];
    $byNorm = [];
    $index = [];
    foreach ($old as $j => $o) {
        $oldWords[$j] = transcript_words($o['norm']);
        $byNorm[$o['norm']][] = $j;
        foreach ($oldWords[$j] as $w => $_) {
            if (mb_strlen($w) > 3) {
                $index[$w][] = $j;
            }
        }
    }
    $used = [];
    $out = [];
    foreach ($new as $i => $s) {
        if (isset($byNorm[$s['norm']])) {
            foreach ($byNorm[$s['norm']] as $j) {
                $used[$j] = true;
            }
            $out[$i] = ['change' => 'same', 'prev' => null];
            continue;
        }
        $w = transcript_words($s['norm']);
        $cands = [];
        foreach ($w as $word => $_) {
            foreach ($index[$word] ?? [] as $j) {
                $cands[$j] = true;
            }
        }
        $best = 0.0;
        $bestJ = null;
        foreach (array_keys($cands) as $j) {
            $sim = transcript_similarity($w, $oldWords[$j]);
            if ($sim > $best) {
                $best = $sim;
                $bestJ = $j;
            }
        }
        if ($bestJ !== null && $best >= 0.6) {
            $used[$bestJ] = true;
            $out[$i] = ['change' => 'reworded', 'prev' => $old[$bestJ]['text']];
        } else {
            $out[$i] = ['change' => 'new', 'prev' => null];
        }
    }
    $gone = [];
    foreach ($old as $j => $o) {
        if (!isset($used[$j])) {
            $gone[] = $o;
        }
    }
    return ['segments' => $out, 'gone' => $gone];
}

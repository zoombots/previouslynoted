<?php
declare(strict_types=1);

/*
 * Import a Frame.io comment export (CSV) into one version.
 * Re-importing the same or a newer export of the same file is safe:
 * notes are matched by Frame.io Comment ID, replies are de-duplicated,
 * and a change of Frame.io's "Complete" flag is recorded in the status history.
 */

function read_frameio_csv(string $path): array
{
    $fh = fopen($path, 'rb');
    if (!$fh) {
        fail('Could not read the uploaded file.');
    }
    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!$header) {
        fail('The file is empty.');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $keys = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    foreach (['comment id', 'comment', 'commenter'] as $need) {
        if (!in_array($need, $keys, true)) {
            fail('This doesn’t look like a Frame.io comment export: the "' . ucwords($need) . '" column is missing.');
        }
    }
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === [null] || count(array_filter($r, fn($v) => $v !== null && $v !== '')) === 0) {
            continue;
        }
        $r = array_pad($r, count($keys), '');
        $rows[] = array_combine($keys, array_slice($r, 0, count($keys)));
    }
    fclose($fh);
    return $rows;
}

/**
 * Work out the timecode base (frames per second of the timecode) and the timecode of the video's first frame
 * from Frame + Timecode pairs. Frame counts from the start of the video; Timecode includes the file's start
 * timecode (often 01:00:00:00 or 10:00:00:00). Returns [base, start] or null.
 */
function detect_tc_base_start(array $rows): ?array
{
    $pairs = [];
    foreach ($rows as $r) {
        if (isset($r['frame'], $r['timecode']) && $r['frame'] !== '' && $r['timecode'] !== '' && is_numeric($r['frame'])) {
            $pairs[] = [(int)$r['frame'], (string)$r['timecode']];
        }
    }
    if (!$pairs) {
        return null;
    }
    $span = max(array_column($pairs, 0)) - min(array_column($pairs, 0));
    foreach ([24, 25, 30, 48, 50, 60] as $base) {
        $offs = [];
        foreach (array_slice($pairs, 0, 200) as [$f, $t]) {
            $tcf = tc_to_frames($t, $base);
            if ($tcf === null) {
                continue 2;
            }
            $offs[] = $tcf - $f;
        }
        sort($offs);
        $med = $offs[intdiv(count($offs), 2)];
        // Frame.io rounds the frame number but floors the timecode, so allow one frame either way.
        $ok = !array_filter($offs, fn($o) => abs($o - $med) > 1);
        // Small frame counts fit several bases; only trust a base once the comments span a couple of seconds.
        if ($ok && ($span >= $base * 2 || $med !== 0)) {
            return [$base, max(0, $med)];
        }
    }
    return null;
}

function detect_tc_base(array $rows): ?int
{
    return detect_tc_base_start($rows)[0] ?? null;
}

/** A start timecode guessed from comment times alone: the whole hour below the earliest comment, if it's at least an hour in. */
function guess_tc_start(array $frames, int $base): int
{
    if (!$frames) {
        return 0;
    }
    $hour = 3600 * $base;
    $min = min($frames);
    return $min >= $hour ? intdiv($min, $hour) * $hour : 0;
}

function parse_commented_at(string $s): string
{
    $s = trim($s);
    foreach (['Y-m-d h:i:s A', 'Y-m-d H:i:s', DATE_ATOM, 'Y-m-d\TH:i:s.u\Z', 'Y-m-d\TH:i:s\Z'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d) {
            return $d->format('Y-m-d\TH:i:s');
        }
    }
    return $s !== '' ? $s : now_iso();
}

/**
 * One comment, from either the CSV export or the Frame.io API:
 *   id, author, text, created_at, frame (in $base timecode frames), complete (bool),
 *   replies: [ [author, text, created_at] ]
 */
function import_csv_into_version(int $versionId, string $path, string $by): array
{
    $rows = read_frameio_csv($path);
    if (!$rows) {
        fail('The export has no comments in it.');
    }
    $v = db()->prepare('SELECT tc_base FROM versions WHERE id = ?');
    $v->execute([$versionId]);
    $found = detect_tc_base_start($rows);
    $base = $found[0] ?? (int)($v->fetchColumn() ?: 24);
    $start = $found ? $found[1] : null;

    $comments = [];
    $fileUrl = '';
    $skipped = 0;
    foreach ($rows as $r) {
        $cid = trim($r['comment id'] ?? '');
        $isReply = strtolower(trim($r['reply'] ?? 'no')) === 'yes';
        $text = (string)($r['comment'] ?? '');
        if ($cid === '' || trim($text) === '') {
            $skipped++;
            continue;
        }
        $author = trim($r['commenter'] ?? '') ?: 'Unknown';
        $at = parse_commented_at($r['commented at'] ?? '');
        if ($isReply) {
            $comments[$cid]['replies'][] = ['author' => $author, 'text' => trim($text), 'created_at' => $at];
            continue;
        }
        $fileUrl = $fileUrl ?: trim($r['file url'] ?? '');
        // Use the timecode Frame.io displays when present, so positions match its player exactly.
        $frame = tc_to_frames($r['timecode'] ?? '', $base) ?? (($r['frame'] ?? '') !== '' ? (int)$r['frame'] : 0);
        // Range comments: Frame.io exports an out timecode (and a duration in frames).
        $end = null;
        $in_ = tc_to_frames($r['timecode in'] ?? '', $base);
        $out = tc_to_frames($r['timecode out'] ?? '', $base);
        if ($in_ !== null && $out !== null && $out > $in_) {
            $end = $frame + ($out - $in_);
        } elseif ((int)($r['duration'] ?? 0) > 0) {
            $end = $frame + (int)$r['duration'];
        }
        $comments[$cid] = [
            'id' => $cid, 'author' => $author, 'text' => $text, 'created_at' => $at, 'frame' => $frame, 'end' => $end,
            'complete' => strtolower(trim($r['complete'] ?? 'no')) === 'yes',
            'replies' => $comments[$cid]['replies'] ?? [],
        ];
    }
    // Replies whose parent row came later have been gathered above; drop any orphans.
    $comments = array_filter($comments, fn($c) => isset($c['text']));
    $stats = import_comments($versionId, array_values($comments), $base, $fileUrl, 'Frame.io import', $start);
    $stats['skipped'] += $skipped;
    return $stats;
}

/**
 * $comments carry frames as Frame.io shows them (timecode frames, including the file's start timecode).
 * They're stored counted from the start of the video: the version's start timecode is taken off.
 * $start: the start timecode in frames when known (CSV exports); otherwise it's guessed from the comments.
 */
function import_comments(int $versionId, array $comments, int $base, string $fileUrl, string $sourceName, ?int $start = null): array
{
    $pdo = db();
    $v = $pdo->prepare('SELECT * FROM versions WHERE id = ?');
    $v->execute([$versionId]);
    $version = $v->fetch();
    if (!$version) {
        fail('That version no longer exists.', 404);
    }
    // Positions are counted from the start of the video, so changing the start only changes the timecode shown.
    $tcStart = $start ?? ((int)($version['tc_start'] ?? 0) ?: guess_tc_start(array_map(fn($c) => (int)$c['frame'], $comments), $base));
    if ($tcStart !== (int)($version['tc_start'] ?? 0)) {
        $pdo->prepare('UPDATE versions SET tc_start = ? WHERE id = ?')->execute([$tcStart, $versionId]);
    }
    foreach ($comments as &$c) {
        $c['frame'] = max(0, (int)$c['frame'] - $tcStart);
        if (isset($c['end']) && $c['end'] !== null) {
            $c['end'] = (int)$c['end'] - $tcStart;
        }
    }
    unset($c);
    $filmId = (int)$version['film_id'];
    $stats = ['new' => 0, 'updated' => 0, 'replies' => 0, 'completed' => 0, 'tc_base' => $base, 'tc_start' => $tcStart, 'skipped' => 0, 'matched' => 0];
    // Codes the comments use that the film has no track for yet (the app offers to create them).
    $codes = detect_codes(array_map(fn($c) => (string)$c['text'], $comments), film_keys($filmId));
    $stats['new_codes'] = array_map(fn($c, $n) => ['code' => (string)$c, 'count' => $n, 'label' => code_label((string)$c)], array_keys($codes), $codes);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE versions SET tc_base = ? WHERE id = ?')->execute([$base, $versionId]);

        $findNote = $pdo->prepare('SELECT id, text, tag FROM notes WHERE film_id = ? AND fio_comment_id = ?');
        $findPushed = $pdo->prepare('SELECT note_id FROM fio_pushed WHERE version_id = ? AND fio_comment_id = ?');
        $setComplete = $pdo->prepare('UPDATE positions SET fio_complete = ? WHERE note_id = ? AND version_id = ?');
        // A note imported earlier under another ID (CSV vs API): same author and text, same place in this version.
        $findSame = $pdo->prepare(
            'SELECT n.id, n.text, n.tag, p.frame FROM notes n JOIN positions p ON p.note_id = n.id AND p.version_id = ?
             WHERE n.film_id = ? AND n.author = ? AND n.origin_version_id = ?'
        );
        $adopt = $pdo->prepare('UPDATE notes SET fio_comment_id = ? WHERE id = ?');
        $insNote = $pdo->prepare('INSERT INTO notes (film_id, origin_version_id, fio_comment_id, author, tag, text, created_at) VALUES (?,?,?,?,?,?,?)');
        $updNote = $pdo->prepare('UPDATE notes SET text = ? WHERE id = ?');
        $getPos = $pdo->prepare('SELECT * FROM positions WHERE note_id = ? AND version_id = ?');
        $insPos = $pdo->prepare("INSERT INTO positions (note_id, version_id, frame, end_frame, confirmed, fio_complete, method) VALUES (?,?,?,?,1,?,'import')");
        // A range from Frame.io wins; without one, an out-point set in the app moves with the note.
        $updPos = $pdo->prepare("UPDATE positions SET end_frame = CASE WHEN ? IS NOT NULL THEN ? WHEN end_frame IS NULL THEN NULL ELSE end_frame - frame + ? END,
            frame = ?, confirmed = 1, fio_complete = ?, method = 'import', reason = NULL WHERE note_id = ? AND version_id = ?");
        $hasReply = $pdo->prepare('SELECT 1 FROM replies WHERE note_id = ? AND author = ? AND text = ?');
        $hasReplyText = $pdo->prepare('SELECT 1 FROM replies WHERE note_id = ? AND text = ?');
        $insReply = $pdo->prepare("INSERT INTO replies (note_id, version_id, author, text, created_at, source) VALUES (?,?,?,?,?,'frameio')");
        $seenIds = [];
        $keys = film_keys($filmId);

        foreach ($comments as $c) {
            $cid = (string)$c['id'];
            $raw = (string)$c['text'];
            if ($cid === '' || trim($raw) === '') {
                $stats['skipped']++;
                continue;
            }
            $seenIds[$cid] = true;
            [$tag, $text] = split_tag($raw, $keys);
            $frame = max(0, (int)$c['frame']);
            $end = isset($c['end']) && (int)$c['end'] > $frame ? (int)$c['end'] : null;
            $complete = !empty($c['complete']) ? 1 : 0;
            $author = trim((string)$c['author']) ?: 'Unknown';

            // A comment this app posted: it's already a note here. Pick up its replies and completion only.
            $findPushed->execute([$versionId, $cid]);
            $pushedId = $findPushed->fetchColumn();
            if ($pushedId) {
                $noteId = (int)$pushedId;
                $getPos->execute([$noteId, $versionId]);
                $pos = $getPos->fetch();
                if ($pos && (int)($pos['fio_complete'] ?? 0) !== $complete) {
                    $setComplete->execute([$complete, $noteId, $versionId]);
                    $now = current_status($noteId);
                    if ($complete && !in_array($now, ['addressed', 'approved'], true) && !in_array($now, CLOSED_STATUSES, true)) {
                        add_event($noteId, $versionId, 'addressed', 'Marked complete in Frame.io', $sourceName);
                        $stats['completed']++;
                    }
                }
                goto replies;
            }
            $findNote->execute([$filmId, $cid]);
            $note = $findNote->fetch();
            if (!$note) {
                $findSame->execute([$versionId, $filmId, $author, $versionId]);
                foreach ($findSame->fetchAll() as $cand) {
                    if (transcript_norm($cand['text']) === transcript_norm($text) && abs((int)$cand['frame'] - $frame) <= 1) {
                        $adopt->execute([$cid, $cand['id']]);
                        $note = $cand;
                        $stats['matched']++;
                        break;
                    }
                }
            }
            if (!$note) {
                $insNote->execute([$filmId, $versionId, $cid, $author, $tag, $text, $c['created_at'] ?: now_iso()]);
                $noteId = (int)$pdo->lastInsertId();
                $insPos->execute([$noteId, $versionId, $frame, $end, $complete]);
                add_event($noteId, $versionId, $complete ? 'addressed' : 'open', $complete ? 'Marked complete in Frame.io' : null, $sourceName);
                $stats['new']++;
                $stats['completed'] += $complete;
            } else {
                $noteId = (int)$note['id'];
                // A note reworded in the app keeps its wording when Frame.io is synced again.
                $edited = $pdo->prepare('SELECT 1 FROM note_edits WHERE note_id = ? LIMIT 1');
                $edited->execute([$noteId]);
                if ($note['text'] !== $text && !$edited->fetchColumn()) {
                    $updNote->execute([$text, $noteId]);
                }
                $getPos->execute([$noteId, $versionId]);
                $pos = $getPos->fetch();
                if ($pos) {
                    $updPos->execute([$end, $end, $frame, $frame, $complete, $noteId, $versionId]);
                    $wasComplete = (int)($pos['fio_complete'] ?? 0);
                } else {
                    $insPos->execute([$noteId, $versionId, $frame, $end, $complete]);
                    $wasComplete = 0;
                }
                if ($complete !== $wasComplete) {
                    $now = current_status($noteId);
                    if ($complete && !in_array($now, ['addressed', 'approved'], true)) {
                        add_event($noteId, $versionId, 'addressed', 'Marked complete in Frame.io', $sourceName);
                        $stats['completed']++;
                    } elseif (!$complete && $now === 'addressed') {
                        add_event($noteId, $versionId, 'open', 'Unmarked in Frame.io', $sourceName);
                    }
                }
                $stats['updated']++;
            }
            replies:
            foreach ($c['replies'] ?? [] as $r) {
                $rt = trim((string)$r['text']);
                if ($rt === '') {
                    continue;
                }
                $ra = trim((string)$r['author']) ?: 'Unknown';
                // When the API doesn't say who replied, match on the text alone so a CSV-imported reply isn't repeated.
                if ($ra === 'Frame.io user') {
                    $hasReplyText->execute([$noteId, $rt]);
                    $known = $hasReplyText->fetchColumn();
                } else {
                    $hasReply->execute([$noteId, $ra, $rt]);
                    $known = $hasReply->fetchColumn();
                }
                if (!$known) {
                    $insReply->execute([$noteId, $versionId, $ra, $rt, $r['created_at'] ?: now_iso()]);
                    $stats['replies']++;
                }
            }
        }

        if ($fileUrl !== '' && empty($version['frameio_url'])) {
            $pdo->prepare('UPDATE versions SET frameio_url = ? WHERE id = ?')->execute([$fileUrl, $versionId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $stats;
}

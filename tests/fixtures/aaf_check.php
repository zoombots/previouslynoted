<?php
require '/home/claude/previouslynoted/lib/edit.php';
$r = read_edit_file('v1_from_aaf.otio', 'v1_from_aaf.otio', 24);
$truth = array_values(array_filter(json_decode(file_get_contents('v1.otio.truth.json'), true), fn($t) => $t['kind'] === 'V' && $t['track'] === 1));
$v = array_values(array_filter($r['events'], fn($e) => $e['kind'] === 'V'));
$same = 0; $ex = [];
foreach ($truth as $i => $t) {
    $e = $v[$i] ?? null;
    if ($e && $e['rec_in'] === $t['rec_in'] && $e['rec_out'] - $e['rec_in'] === $t['dur'] && $e['name'] === $t['name'] && abs($e['src_in'] - $t['src_in']) < 1) $same++;
    elseif (count($ex) < 3) $ex[] = json_encode([$t, $e]);
}
echo "AAF -> otioconvert -> app: " . count($v) . " shots read, $same of " . count($truth) . " identical to the original cut\n" . implode("\n", $ex) . "\n";
try { read_edit_file('v1_avid.aaf', 'v1_avid.aaf', 24); } catch (EditReadError $e) { echo "Uploading the .aaf directly says: " . $e->getMessage() . "\n"; }

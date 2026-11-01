<?php
// Compare the PHP readers with OpenTimelineIO's reading of the same cut.
require '/home/claude/previouslynoted/lib/edit.php';
function load_truth($f) { return json_decode(file_get_contents($f), true); }
function cmp($label, $mine, $truth, $fields) {
    $bad = 0; $n = count($truth);
    if (count($mine) !== $n) { echo "$label: COUNT mine=" . count($mine) . " otio=$n\n"; }
    $byKey = [];
    foreach ($mine as $m) $byKey[$m['kind'].$m['track'].':'.$m['rec_in']][] = $m;
    $ex = [];
    foreach ($truth as $t) {
        $k = $t['kind'].$t['track'].':'.$t['rec_in'];
        $m = $byKey[$k][0] ?? null;
        $diff = [];
        if (!$m) { $diff[] = 'missing'; }
        else foreach ($fields as $f) {
            $mv = $f === 'dur' ? $m['rec_out'] - $m['rec_in'] : $m[$f];
            if (is_float($mv) || is_float($t[$f])) { if (abs($mv - $t[$f]) > 0.5) $diff[] = "$f {$mv}≠{$t[$f]}"; }
            elseif ($mv != $t[$f]) $diff[] = "$f '{$mv}'≠'{$t[$f]}'";
        }
        if ($diff) { $bad++; if (count($ex) < 4) $ex[] = "$k " . implode(', ', $diff); }
    }
    printf("%-22s %4d shots  %s\n", $label, $n, $bad ? "$bad DIFFER: " . implode(' | ', $ex) : 'all match OTIO');
}
$d = '/home/claude/build/fixtures/';
foreach (['v1', 'v2'] as $v) {
    $otioTruth = load_truth("$d$v.otio.truth.json");
    $r = read_edit_file("$d$v.otio", "$v.otio", 24);
    cmp("$v.otio (otio_json)", $r['events'], $otioTruth, ['dur', 'name', 'file', 'src_in']);
    $r = read_edit_file("$d$v.xml", "$v.xml", 24);
    $t = array_map(function ($x) { $x['name'] = $x['name']; return $x; }, $otioTruth);
    cmp("$v.xml (fcp7)", $r['events'], $t, ['dur', 'file', 'src_in']);
    if ($r['warnings']) echo "   warnings: " . implode('; ', $r['warnings']) . "\n";
    $r = read_edit_file("$d$v.edl", "$v.edl", 24);
    cmp("$v.edl (cmx3600)", $r['events'], load_truth("$d$v.edl.truth.json"), ['dur', 'name', 'src_in']);
    echo "   edl start offset: {$r['start']}\n";
}

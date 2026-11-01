<?php
require "/home/claude/previouslynoted/lib/edit.php";
foreach (["avid_style.edl" => 24, "df.edl" => 30] as $f => $b) {
    $r = read_edit_file($f, $f, $b);
    echo "$f start={$r['start']} title={$r['name']}\n";
    foreach ($r["events"] as $e) {
        printf("  %s%d %6d-%6d %-22s src=%.0f k=%.2f file=%s\n", $e["kind"], $e["track"], $e["rec_in"], $e["rec_out"], $e["name"], $e["src_in"], $e["k"], $e["file"]);
    }
}

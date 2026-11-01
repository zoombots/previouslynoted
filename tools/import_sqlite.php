<?php
declare(strict_types=1);

/*
 * One-time import of a Previously Noted 0.7.x install (SQLite) into the MariaDB database, as one company with one
 * production (docs/multitenant-rbac.md §8). The installer runs the same code; this is for doing it by hand.
 *
 *   php tools/import_sqlite.php --from /path/to/old/data --company "Name" --production "Name" --email you@example.com
 *
 * It asks for the old admin password (or reads PN_PASSWORD). The app's data/config.php must already have the
 * database settings. --app sets the app folder (default: app/ next to this file).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$opt = getopt('', ['from:', 'company:', 'production:', 'email:', 'app:', 'no-password-check']);
foreach (['from', 'company', 'production', 'email'] as $k) {
    if (empty($opt[$k])) {
        fwrite(STDERR, "Missing --$k. See the comment at the top of this file.\n");
        exit(2);
    }
}
$app = rtrim($opt['app'] ?? dirname(__DIR__) . '/app', '/');
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $app . '/lib/bootstrap.php';
require $app . '/lib/connect.php';
require $app . '/lib/setup.php';

$from = rtrim($opt['from'], '/');
$pw = getenv('PN_PASSWORD');
if ($pw === false && !isset($opt['no-password-check'])) {
    fwrite(STDOUT, 'Password of ' . $opt['email'] . ' in the old install: ');
    system('stty -echo 2>/dev/null');
    $pw = trim((string)fgets(STDIN));
    system('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
}
try {
    $r = import_sqlite_install("$from/notes.sqlite", $from, $opt['company'], $opt['production'], $opt['email'], $pw === false ? null : $pw, !isset($opt['no-password-check']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Not imported: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Imported into company {$r['org_id']}, production {$r['workspace_id']}" . ($r['kept_ids'] ? ' (ids kept)' : ' (renumbered)') . ":\n";
foreach ($r['counts'] as $t => $n) {
    printf("  %-13s %d\n", $t, $n);
}

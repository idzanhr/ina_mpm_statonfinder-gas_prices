<?php
// Refreshes stations from ina.hr. Each run first reads the finder (adds new stations, updates
// details, flags stations that disappeared), then reads station pages (hours, fuels and prices,
// services, closures). Spread over an hour: each minute handles 1/60 of the stations.
//
//   cron:  * 0,4 * * *  php /path/to/bin/sync-ina.php        (minute = current minute)
//   once:  php bin/sync-ina.php --all [--verbose]            every station page now
//          php bin/sync-ina.php --directory                  only the finder (fast)
//          php bin/sync-ina.php --minute=7
//   one:   php bin/sync-ina.php --station="Zagreb Radnička" --verbose
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use InaPostaje\InaSync;

$station = opt('station');
$directoryOnly = (bool) opt('directory');
$minute = opt('all') || $directoryOnly || $station !== null ? null : (int) (opt('minute') ?? date('i'));
$log = opt('verbose') || $station !== null ? fn(string $line) => print("  $line\n") : null;
$result = InaSync::sync($minute, $station, $log, pages: !$directoryOnly);
if ($station !== null && $result['checked'] === 0) { fwrite(STDERR, "Postaja \"$station\" ne postoji.\n"); exit(1); }
echo json_encode(['minute' => $minute] + $result) . "\n";
$nothingWorked = $directoryOnly ? !$result['directoryAvailable'] : ($result['checked'] > 0 && $result['updated'] + $result['unchanged'] === 0);
exit($nothingWorked ? 1 : 0);

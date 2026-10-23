<?php
// Clears Bunny CDN's cache now – run once after deploying, or whenever an old page still shows.
//   php bin/bunny-purge.php          every file of the site (https://domain/*)
//   php bin/bunny-purge.php --zone   the whole pull zone (needs bunny.pullzone_id)
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use InaPostaje\Bunny;

if (!Bunny::enabled()) {
    fwrite(STDERR, "Bunny nije postavljen: upišite 'bunny' => ['api_key' => …, 'site_url' => …] u app/config.php,\n"
        . "ili očistite cache ručno: Bunny → Pull zone → Purge cache.\n");
    exit(1);
}
$error = Bunny::purge((bool) opt('zone'));
if ($error !== null) { fwrite(STDERR, $error . "\n"); exit(1); }
echo "Bunny cache je očišćen.\n";

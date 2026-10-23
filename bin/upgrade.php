<?php
// Run after every `git pull`: adds new database columns (if any) and re-generates the public pages.
//   php bin/upgrade.php
declare(strict_types=1);
require __DIR__ . '/_cli.php';

InaPostaje\Db::migrate();
InaPostaje\Stations::publish();
echo "Baza je ažurirana i stranica ponovno objavljena.\n";

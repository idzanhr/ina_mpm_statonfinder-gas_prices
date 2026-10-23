<?php
// Regenerates index.html, privatnost/kolacici/404.html and data/stations.json (+ .gz) from the database.
declare(strict_types=1);
require __DIR__ . '/_cli.php';

InaPostaje\Stations::publish();
echo "Objavljeno.\n";

<?php
// Shows what the parsers read from the live ina.hr pages, without writing anything.
// Run it once after deploying (and whenever the sync suddenly finds nothing): ina.hr can change
// its markup at any time.
//
//   php bin/ina-probe.php                          the finder: entries, keys of the first entry, parsed sample
//   php bin/ina-probe.php https://www.ina.hr/station/murter/   one station page: hours, fuels, services, details
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use InaPostaje\InaSource;

$url = $argv[1] ?? null;
$print = fn(string $title, mixed $v) => print("== $title\n" . json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

try {
    if ($url === null) {
        $html = (InaSource::$fetch)(InaSource::DIRECTORY_URL);
        $raw = InaSource::directoryRaw($html);
        $print('Ključevi prvog zapisa u tražilici (sirovo)', $raw && is_array($raw[0]) ? $raw[0] : $raw);
        $entries = InaSource::parseDirectory($html);
        $print('Pročitano postaja', count($entries));
        $print('Prve 3 (kako ih čita sinkronizacija)', array_slice($entries, 0, 3));
        $empty = [];
        foreach (['name', 'address', 'city', 'postal_code', 'county', 'lat', 'lng', 'phone'] as $f) {
            $empty[$f] = count(array_filter($entries, fn($e) => $e[$f] === ''));
        }
        $print('Broj zapisa bez vrijednosti, po polju', $empty);
        if ($entries) echo "\nZa jednu postaju: php bin/ina-probe.php {$entries[0]['url']}\n";
    } else {
        $safe = InaSource::safeUrl($url) ?? throw new RuntimeException('Adresa mora biti https://www.ina.hr/station/…/');
        $print('Stranica postaje', InaSource::parseStation((InaSource::$fetch)($safe)));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Greška: ' . $e->getMessage() . "\n");
    exit(1);
}

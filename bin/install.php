<?php
// Creates the tables, THE admin account, loads the station list from the ina.hr finder
// and generates the static public site.
//
//   php bin/install.php                      (interactive)
//   php bin/install.php --email=you@x.hr     (password from prompt or INA_POSTAJE_ADMIN_PASSWORD)
//   php bin/install.php --no-sync            (do not contact ina.hr now)
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use InaPostaje\{Auth, Db, InaSync, Stations};

InaPostaje\Config::appKey(); // fail early on a missing/invalid key
Db::migrate();
echo "Tablice su spremne (" . Db::driver() . ").\n";

if (Auth::admin()) {
    echo "Administrator već postoji (" . Auth::admin()['email'] . "). Za promjenu: php bin/set-admin.php\n";
} else {
    $email = opt('email') ?: ask('E-mail administratora: ');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Neispravan e-mail.\n"); exit(1); }
    Auth::setAdmin($email, askPassword(), mustChange: false);
    echo "Administrator kreiran: $email\nPri prvoj prijavi na admin stranici postavit ćete obaveznu 2FA (QR kod).\n";
}

if (!opt('no-sync')) {
    echo "Preuzimam popis postaja s tražilice ina.hr…\n";
    $r = InaSync::sync(null, null, null, pages: false);
    if (!$r['directoryAvailable']) {
        fwrite(STDERR, "Tražilica ina.hr nije dostupna s ovog poslužitelja. Pokušajte kasnije: php bin/sync-ina.php --directory\n");
    } else {
        echo "Na ina.hr: {$r['directory']} postaja · dodano: {$r['added']} · ažurirano: {$r['details']}\n";
        $n = (int) Db::one('SELECT COUNT(*) AS n FROM stations')['n'];
        echo "Radno vrijeme, goriva, cijene i usluge: php bin/sync-ina.php --all (oko " . max(1, (int) ceil($n * 0.8 / 60)) . " min), zatim cron (README).\n";
    }
}

Stations::publish();
echo "Javna stranica generirana (index.html, data/stations.json).\n";

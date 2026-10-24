<?php
// Dependency-free test runner:  php tests/run.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('INA_POSTAJE_CONFIG=' . __DIR__ . '/config.test.php');
require dirname(__DIR__) . '/app/bootstrap.php';

use InaPostaje\{Config, Crypto, Db, Fuels, HttpError, InaSource, InaSync, Render, Stations, Totp};

$failures = 0;
$count = 0;
function test(string $name, callable $fn): void
{
    global $failures, $count;
    $count++;
    try { $fn(); echo "  ok  $name\n"; }
    catch (\Throwable $e) { $failures++; echo "  FAIL $name\n       " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
}
function eq(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) throw new \RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function has(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) throw new \RuntimeException("missing: $needle");
}
function throws(callable $fn): void
{
    try { $fn(); } catch (\Throwable) { return; }
    throw new \RuntimeException('expected an exception');
}

// ---- fixtures: markup shaped like the ina.hr pages ---------------------------------------
$nav = '<header><nav><ul><li><span>Goriva</span><ul><li>Eurosuper 95 Class Plus</li><li>Eurodiesel Class Plus</li></ul></li>'
    . '<li><span>Usluge</span><ul><li>INA Kartice</li><li>Program vjernosti</li></ul></li></ul></nav></header>';
$hoursTable = fn(string $week = '06-22', string $sat = '07-21', string $sun = '07-14', string $hol = '25.12.2026. NE RADI<br>26.12.2026. 08-14')
    => '<table class="station__info__tablebox__table"><tr><th>Radno vrijeme</th><th>Radni dan</th><th>Subota</th><th>Nedjelja</th><th>Blagdani</th></tr>'
    . "<tr><td>Redovno</td><td>$week</td><td>$sat</td><td>$sun</td><td>$hol</td></tr></table>";
$fuelList = fn(array $fuels) => '<h3>Goriva u ponudi</h3><ul>' . implode('', array_map(fn($f) => "<li>$f</li>", $fuels)) . '</ul>';
$defaultFuels = ['Eurosuper 95 Class Plus - 1,73 €', 'Eurosuper 100 Class Plus Premium - 1,89 €', 'Eurodiesel Class Plus - 1,67 €',
    'Eurodiesel Class Plus Premium - 1,85 €', 'Autoplin - 0,89 €', 'AdBlue'];
$services = '<h3>Usluge</h3><ul><li>Caffe bar</li><li>Autopraonica</li><li><img src="x.svg" alt="Bankomat"></li><li>Wi-Fi</li></ul>';
$page = fn(string $hours, string $fuels, string $extra = '') => "<html><body>$nav<main><h1>Murter</h1>"
    . '<p><a href="tel:+385914971474">091 4971 474</a></p>'
    . '<a href="https://www.google.com/maps/dir/?api=1&destination=43.822051,15.593712">Upute</a>'
    . "$hours$fuels$extra</main><footer><p>© INA d.d.</p></footer></body></html>";
$murterPage = fn(array $fuels = null, ?string $hours = null) => $page($hours ?? $hoursTable(), $fuelList($fuels ?? $defaultFuels), $services);
$directory = fn(array $entries) => '<div id="mdata" stations=\'' . htmlspecialchars(json_encode($entries, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '\'></div>';
$entry = fn(string $slug, string $name, string $phone = '', array $more = []) => ['url' => "https://www.ina.hr/station/$slug/", 'title' => $name, 'contact_phone' => $phone] + $more;

echo "TOTP\n";
$rfcSecret = Totp::base32Encode('12345678901234567890');
test('RFC 6238 SHA-1 test vectors (6-digit truncation)', function () use ($rfcSecret) {
    foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $t => $code) {
        eq($code, Totp::code($rfcSecret, Totp::step($t)));
    }
});
test('accepts ±1 step of clock drift, rejects ±2; a used code cannot be replayed', function () use ($rfcSecret) {
    $t = 1111111109;
    $s = Totp::step($t);
    eq($s - 1, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s - 1), 0, $t));
    eq($s + 1, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s + 1), 0, $t));
    eq(null, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s - 2), 0, $t));
    eq(null, Totp::verify($rfcSecret, Totp::code($rfcSecret, $s), $s, $t));
    eq(null, Totp::verify($rfcSecret, 'abcdef', 0));
});
test('otpauth URI is what authenticator apps expect', function () {
    eq('otpauth://totp/INA%20postaje:a%40b.hr?secret=ABC&issuer=INA%20postaje&algorithm=SHA1&digits=6&period=30', Totp::uri('ABC', 'a@b.hr', 'INA postaje'));
});
test('AES-256-GCM round trip and tamper detection', function () {
    $key = random_bytes(32);
    $c = Crypto::encrypt('JBSWY3DPEHPK3PXP', $key);
    eq('JBSWY3DPEHPK3PXP', Crypto::decrypt($c, $key));
    throws(fn() => Crypto::decrypt($c, random_bytes(32)));
    $raw = base64_decode(substr($c, 3));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    throws(fn() => Crypto::decrypt('v1:' . base64_encode($raw), $key));
});

echo "Fuel names → categories\n";
test('INA fuel names map to the site\'s categories', function () {
    // INA's own product names map to themselves …
    foreach (Fuels::CATEGORIES as $cat => $label) if ($cat !== 'other') eq([$label, $cat], [$label, Fuels::category($label)]);
    // … and spellings that differ a little still find the right product.
    $cases = ['  EUROSUPER 95 class plus ' => 'es95cp', 'Eurosuper 95 Class Plus Premium' => 'es95cpp', 'Eurosuper 100 Class Plus' => 'es100cpp',
        'Eurosuper 95,' => 'es95', 'Eurodiesel Class Plus Expert' => 'edcpe', 'Eurodiesel Class Plus Premium' => 'edcpp', 'Eurodiesel' => 'ed',
        'Plavi dizel' => 'plavi', 'UNP (autoplin)' => 'lpg', 'AdBlue' => 'adblue', 'Ad Blue (kanister 10 l)' => 'adblue', 'Petrolej' => 'other'];
    foreach ($cases as $name => $cat) eq([$name, $cat], [$name, Fuels::category($name)]);
});
test('fuels stored with an older product key are re-classified (and ordered) when loaded', function () {
    $s = Stations::hydrate(['id' => '1', 'fuels' => json_encode([['name' => 'Autoplin', 'cat' => 'lpg', 'price' => '0.89'],
        ['name' => 'Eurosuper 95 Class Plus Premium', 'cat' => 'es100', 'price' => '1.95'], 'junk'])]);
    eq(['Eurosuper 95 Class Plus Premium|es95cpp', 'Autoplin|lpg'], array_map(fn($f) => "{$f['name']}|{$f['cat']}", $s['fuels']));
});
test('prices: comma or dot, 2–3 decimals; unbelievable values are refused', function () {
    eq('1.43', Fuels::price('1,43 €'));
    eq('1.43', Fuels::price('1.430'));
    eq(null, Fuels::price('143 €'));
    eq(null, Fuels::price('12,50 €'));
});

echo "Parsing the ina.hr finder\n";
test('only INA station pages are accepted as links (normalised with a trailing slash)', function () {
    eq('https://www.ina.hr/station/babina-greda-2/', InaSource::safeUrl('https://www.ina.hr/station/babina-greda-2'));
    eq(null, InaSource::safeUrl('https://www.ina.hr@localhost/station/test/'));
    eq(null, InaSource::safeUrl('http://www.ina.hr/station/test/'));
    eq(null, InaSource::safeUrl('https://www.ina.hr/station/test/?x=1'));
    eq(null, InaSource::safeUrl('https://www.ina.hr/station/../admin/'));
});
test('finder entries: flat and nested keys, entities, county names, coordinates only inside Croatia', function () use ($directory) {
    $html = $directory([
        ['url' => 'https://www.ina.hr/station/babina-greda-2/', 'contact_phone' => '+385 91 4971 292', 'title' => 'Babina Greda &amp; okolica',
            'location' => ['address' => 'Josipa Blažekovića 2/a', 'city' => 'Babina Greda', 'zip' => '32276', 'lat' => '45.1167', 'lng' => 18.5383],
            'county' => 'VUKOVARSKO-SRIJEMSKA'],
        ['url' => 'https://www.ina.hr/station/babina-greda-2/', 'title' => 'duplicate'],
        ['url' => 'https://evil.example/station/x/', 'title' => 'not INA'],
        ['link' => 'https://www.ina.hr/station/pula-max/', 'name' => 'Pula', 'latitude' => 51.5, 'longitude' => -0.1],
    ]);
    $d = InaSource::parseDirectory($html);
    eq(2, count($d));
    eq(['https://www.ina.hr/station/babina-greda-2/', ['0914971292'], 'Babina Greda & okolica', 'Josipa Blažekovića 2/a', 'Babina Greda', '32276',
        'Vukovarsko-srijemska županija', '45.116700', '18.538300'],
        [$d[0]['url'], $d[0]['phones'], $d[0]['name'], $d[0]['address'], $d[0]['city'], $d[0]['postal_code'], $d[0]['county'], $d[0]['lat'], $d[0]['lng']]);
    eq(['Pula', '', ''], [$d[1]['name'], $d[1]['lat'], $d[1]['lng']]); // London is not in Croatia
    throws(fn() => InaSource::parseDirectory('<html>maintenance</html>'));
});

echo "Parsing a station page\n";
test('working hours table (with <br> in the holiday cell)', function () use ($murterPage) {
    eq([['label' => 'Redovno', 'week' => '06-22', 'sat' => '07-21', 'sun' => '07-14', 'hol' => '25.12.2026. NE RADI 26.12.2026. 08-14']],
        InaSource::parseHours($murterPage()));
    throws(fn() => InaSource::parseHours('<table class="station__info__tablebox__table"><tr><td>x</td></tr></table>'));
});
test('"Goriva u ponudi": every item with its price and category; the site menu "Goriva" is ignored', function () use ($murterPage) {
    $f = InaSource::parseFuels($murterPage());
    eq(['Eurosuper 95 Class Plus|es95cp|1.73', 'Eurosuper 100 Class Plus Premium|es100cpp|1.89', 'Eurodiesel Class Plus|edcp|1.67',
        'Eurodiesel Class Plus Premium|edcpp|1.85', 'Autoplin|lpg|0.89', 'AdBlue|adblue|'],
        array_map(fn($x) => "{$x['name']}|{$x['cat']}|{$x['price']}", $f));
});
test('without the heading only "<fuel> – <price> €" lines count', function () {
    $f = InaSource::parseFuels('<ul><li>INA Kartice</li><li>Plava nafta – 1.45 EUR</li><li>Kava - 1,60 €</li><li>Eurodiesel: 1,670 €</li></ul>');
    eq(['Eurodiesel|1.67', 'Plava nafta|1.45'], array_map(fn($x) => "{$x['name']}|{$x['price']}", $f));
});
test('services: list after "Usluge" in the page (icons by alt text), not the menu', function () use ($murterPage) {
    eq(['Caffe bar', 'Autopraonica', 'Bankomat', 'Wi-Fi'], InaSource::parseServices($murterPage()));
    eq([], InaSource::parseServices('<p>Nema usluga</p>'));
});
test('details: <h1>, tel: link and coordinates from a map link or data attributes', function () use ($murterPage) {
    eq(['name' => 'Murter', 'phone' => '091 4971 474', 'lat' => '43.822051', 'lng' => '15.593712', 'address' => '', 'ina_region' => ''], InaSource::parseDetails($murterPage()));
    eq(['45.815011', '15.981919'], [InaSource::parseDetails('<div data-lat="45.815011" data-lng="15.981919"></div>')['lat'], InaSource::parseDetails('<div data-lat="45.815011" data-lng="15.981919"></div>')['lng']]);
});
test('closure wording is found and quoted whole; scripts are ignored', function () {
    eq('Postaja je privremeno zatvorena zbog radova do 15.11.2026', InaSource::closureNotice('<p>Postaja je privremeno zatvorena zbog radova do 15.11.2026.</p>'));
    eq('', InaSource::closureNotice('<script>var t = "privremeno zatvoreno";</script><p>Radimo.</p>'));
});

// Laid out like https://www.ina.hr/station/zagreb-zagrebacka-cesta-istok/ (contact box, Usluge, Ostalo with "Ad Blue")
$inaLayout = '<html><body><h1>Zagreb-Zagrebačka cesta-istok</h1><div class="station__contact"><h3>Kontakt</h3><ul>'
    . '<li><strong>Mob:</strong> 091 4971 366</li><li><strong>Adresa:</strong> Zagrebačka cesta bb</li><li><strong>Regija:</strong> ZAPAD</li></ul>'
    . '<a href="#">Prikažite lokaciju na karti</a></div>'
    . '<table class="station__info__tablebox__table"><tr><th>Radno vrijeme</th><th>Radni dan</th><th>Subota</th><th>Nedjelja</th><th>Blagdani</th></tr>'
    . '<tr><td>Redovno</td><td>06 - 22</td><td>06 - 22</td><td>06 - 22</td><td>06 - 22</td></tr></table>'
    . '<h3>Goriva u ponudi</h3><ul><li>Eurosuper 95 Class Plus Premium - 1,89 €</li><li>Eurosuper 95 Class Plus - 1,73 €</li>'
    . '<li>Eurodiesel Class Plus - 1,87 €</li><li>Eurodiesel Class Plus Premium - 2,12 €</li></ul>'
    . '<h3>Usluge</h3><ul><li>Plin u bocama</li><li>Fresh Corner</li></ul>'
    . '<h3>Ostalo</h3><ul><li>Maziva</li><li>Roba široke potrošnje</li><li>Ad Blue</li></ul></body></html>';
test('ina.hr layout: "Ostalo" (with Ad Blue) is read; Mob/Adresa/Regija from the contact box', function () use ($inaLayout) {
    eq(['Plin u bocama', 'Fresh Corner', 'Maziva', 'Roba široke potrošnje', 'Ad Blue'], InaSource::parseServices($inaLayout));
    eq(['Zagreb-Zagrebačka cesta-istok', '091 4971 366', 'Zagrebačka cesta bb', 'ZAPAD'],
        (fn($d) => [$d['name'], $d['phone'], $d['address'], $d['ina_region']])(InaSource::parseDetails($inaLayout)));
    eq(['es95cp|1.73', 'es95cpp|1.89', 'edcp|1.87', 'edcpp|2.12'], array_map(fn($f) => "{$f['cat']}|{$f['price']}", InaSource::parseFuels($inaLayout)));
    // "Ad Blue" under Ostalo → the card lists AdBlue (no price) and the filter finds it
    [$fuels, $tokens] = Render::cardFuels(['fuels' => InaSource::parseFuels($inaLayout), 'services' => InaSource::parseServices($inaLayout)]);
    eq(true, in_array('adblue', $tokens, true));
});

echo "Stations: counties, validation, hiding\n";
Db::migrate();
test('migrate() is re-runnable', function () { Db::migrate(); eq(true, true); });
test('county names are normalised; a missing county is guessed from the postcode', function () {
    eq('Splitsko-dalmatinska županija', Stations::county('splitsko-dalmatinska'));
    eq('Grad Zagreb', Stations::county('ZAGREB'));
    eq('', Stations::county('Bavaria'));
    eq('Grad Zagreb', Stations::countyFromPostal('10000', 'Zagreb'));
    eq('Grad Zagreb', Stations::countyFromPostal('10360', 'Sesvete'));
    eq('Zagrebačka županija', Stations::countyFromPostal('10410', 'Velika Gorica'));
    eq('Istarska županija', Stations::countyFromPostal('52100', 'Pula'));
    eq('', Stations::countyFromPostal('1234', ''));
});
test('validation: name, county, INA link, coordinates; reopening clears the closure', function () {
    [$row, $err] = Stations::validate(['name' => ' Test ', 'ina_url' => 'https://www.ina.hr/station/test', 'lat' => '45,1', 'lng' => '15.2',
        'aliases' => 'A / B / A', 'status' => 'open', 'status_note' => 'x', 'services' => ['Wi-Fi', ' wi-fi ', 'Caffe bar']]);
    eq([], $err);
    eq(['Test', 'https://www.ina.hr/station/test/', '45.100000', '15.200000', 'A / B', '', ['Wi-Fi', 'Caffe bar']],
        [$row['name'], $row['ina_url'], $row['lat'], $row['lng'], $row['aliases'], $row['status_note'], $row['services']]);
    [, $err] = Stations::validate(['name' => '', 'county' => 'Nepostojeća', 'ina_url' => 'https://example.com/', 'lat' => '1', 'lng' => '2',
        'status' => 'closed', 'closed_from' => '2026-12-05', 'closed_until' => '2026-12-01']);
    eq(5, count($err));
});
test('save fills the county from the postcode; the same INA link cannot be used twice', function () {
    $s = Stations::save(null, ['name' => 'Ručna postaja', 'postal_code' => '21000', 'city' => 'Split', 'ina_url' => 'https://www.ina.hr/station/rucna/']);
    eq('Splitsko-dalmatinska županija', $s['county']);
    try { Stations::save(null, ['name' => 'Druga', 'ina_url' => 'https://www.ina.hr/station/rucna/']); throw new RuntimeException('no error'); }
    catch (HttpError $e) { eq(422, $e->status); }
    Db::exec('DELETE FROM stations');
});

echo "Sync with ina.hr\n";
$dirEntries = [];
for ($i = 1; $i <= 24; $i++) $dirEntries[] = $entry("postaja-$i", "Postaja $i", '01 600 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), ['address' => "Ulica $i", 'city' => 'Zagreb', 'zip' => '10000']);
$dirEntries[] = $entry('murter', 'Murter', '091 4971 474', ['address' => 'Put Gradine 1', 'city' => 'Murter', 'zip' => '22243']);
$fetchLog = [];
$serve = function (array $pages) use (&$fetchLog) {
    $fetchLog = [];
    InaSource::$fetch = function (string $u) use ($pages, &$fetchLog) {
        $fetchLog[] = $u;
        return array_key_exists($u, $pages) ? (is_callable($pages[$u]) ? $pages[$u]() : $pages[$u]) : throw new RuntimeException('HTTP 404');
    };
};
$murter = fn() => Stations::hydrate(Db::one("SELECT * FROM stations WHERE ina_url = 'https://www.ina.hr/station/murter/'"));

test('the finder adds every station, with county from the postcode; a second run changes nothing', function () use ($serve, $directory, $dirEntries) {
    $serve([InaSource::DIRECTORY_URL => $directory($dirEntries)]);
    $r = InaSync::sync(null, null, null, pages: false);
    eq([25, 25, 0], [$r['directory'], $r['added'], $r['details']]);
    eq(['Murter', 'Put Gradine 1', '22243', 'Šibensko-kninska županija'], (fn($m) => [$m['name'], $m['address'], $m['postal_code'], $m['county']])(Stations::find((int) Db::one("SELECT id FROM stations WHERE name = 'Murter'")['id'])));
    $r = InaSync::sync(null, null, null, pages: false);
    eq([0, 0], [$r['added'], $r['details']]);
    is_file(Config::get('web_root') . '/index.html') || throw new RuntimeException('not published');
});
test('station page: hours, fuels with prices, services and coordinates are stored and published', function () use ($serve, $directory, $dirEntries, $murterPage, $murter) {
    $serve([InaSource::DIRECTORY_URL => $directory($dirEntries), 'https://www.ina.hr/station/murter/' => $murterPage()]);
    $r = InaSync::sync(null, 'Murter');
    eq([1, 1], [$r['checked'], $r['updated']]);
    $m = $murter();
    eq('06-22', $m['working_hours'][0]['week']);
    eq(6, count($m['fuels']));
    eq(['Caffe bar', 'Autopraonica', 'Bankomat', 'Wi-Fi'], $m['services']);
    eq(['43.822051', '15.593712'], [$m['lat'], $m['lng']]);
    eq(true, $m['ina_synced_at'] !== '');
    $html = (string) file_get_contents(Config::get('web_root') . '/index.html');
    has('<span class="fuel-name">Eurodiesel Class Plus</span> <b class="fuel-price">1,67 €</b>', $html);
    has('data-fuels="es95cp es100cpp edcp edcpp lpg adblue"', $html);
});
test('price change: previous price + date on the fuel, price_history row and Dnevnik; the first pick-up is not a change', function () use ($serve, $murterPage, $murter) {
    eq(0, (int) Db::one('SELECT COUNT(*) AS n FROM price_history')['n']);
    $serve(['https://www.ina.hr/station/murter/' => $murterPage(['Eurosuper 95 Class Plus - 1,69 €', 'Eurodiesel Class Plus - 1,67 €', 'Autoplin', 'Eurodiesel plavi - 1,10 €'])]);
    $r = InaSync::sync(null, 'Murter');
    eq(1, $r['price_changes']);
    $f = array_column($murter()['fuels'], null, 'name');
    eq(['1.69', '1.73'], [$f['Eurosuper 95 Class Plus']['price'], $f['Eurosuper 95 Class Plus']['prev']]);
    eq(['1.67', ''], [$f['Eurodiesel Class Plus']['price'], $f['Eurodiesel Class Plus']['prev']]);
    eq('0.89', $f['Autoplin']['price']); // listed without a price → last known price stays
    eq(false, isset($f['AdBlue']));     // no longer listed → dropped
    eq('1.10', $f['Eurodiesel plavi']['price']);
    $h = Db::one('SELECT * FROM price_history');
    eq(['Eurosuper 95 Class Plus', 'es95cp', '1.73', '1.69'], [$h['fuel_name'], $h['category'], $h['old_price'], $h['new_price']]);
    eq(1, (int) Db::one("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'price'")['n']);
});
test('a page that cannot be read keeps everything; the log says what was tried', function () use ($serve, $murter) {
    $before = $murter();
    $serve([]);
    $lines = [];
    $r = InaSync::sync(null, 'Murter', function ($l) use (&$lines) { $lines[] = $l; });
    eq(1, $r['failed']);
    $after = $murter();
    eq([$before['working_hours'], $before['fuels'], $before['services']], [$after['working_hours'], $after['fuels'], $after['services']]);
    has('spremljena poveznica https://www.ina.hr/station/murter/ → HTTP 404', implode("\n", $lines));
});
test('locked station keeps edited name, hours and services; fuels still update', function () use ($serve, $directory, $dirEntries, $murterPage, $hoursTable, $murter) {
    $m = $murter();
    Stations::save($m['id'], array_merge($m, ['name' => 'Murter (ručno)', 'locked' => 1, 'services' => ['Samo moja usluga'],
        'working_hours' => [['label' => 'Redovno', 'week' => '05-23', 'sat' => '05-23', 'sun' => '05-23', 'hol' => '']]]));
    $serve([InaSource::DIRECTORY_URL => $directory($dirEntries), 'https://www.ina.hr/station/murter/' => $murterPage(['Eurosuper 95 Class Plus - 1,65 €'], $hoursTable('07-20'))]);
    InaSync::sync(null, null, null, pages: false);
    InaSync::sync(null, (string) $m['id']);
    $m = $murter();
    eq(['Murter (ručno)', '05-23', ['Samo moja usluga'], '1.65'], [$m['name'], $m['working_hours'][0]['week'], $m['services'], $m['fuels'][0]['price']]);
    Stations::save($m['id'], array_merge($m, ['name' => 'Murter', 'locked' => 0]));
});
test('ina.hr hours "PRIVREMENO ZATVORENO" every day closes the station automatically and reopens it later', function () use ($serve, $murterPage, $hoursTable, $murter) {
    $closed = $hoursTable('PRIVREMENO ZATVORENO', 'PRIVREMENO ZATVORENO', 'PRIVREMENO ZATVORENO', 'PRIVREMENO ZATVORENO');
    $serve(['https://www.ina.hr/station/murter/' => $murterPage(null, $closed)]);
    eq(1, InaSync::sync(null, 'Murter')['closed']);
    eq(['closed', 'ina', InaSync::INA_CLOSED_NOTE], (fn($m) => [$m['status'], $m['status_source'], $m['status_note']])($murter()));
    $card = Render::card(Stations::publicRows()[array_search('Murter', array_column(Stations::publicRows(), 'name'))]);
    has('Privremeno zatvoreno', $card);
    eq(false, str_contains($card, 'Pon–Pet: <b>PRIVREMENO ZATVORENO'));
    $serve(['https://www.ina.hr/station/murter/' => $murterPage()]);
    eq(1, InaSync::sync(null, 'Murter')['reopened']);
    eq(['open', ''], (fn($m) => [$m['status'], $m['status_source']])($murter()));
});
test('a closure set by the admin is never reopened by the sync', function () use ($serve, $murterPage, $murter) {
    $m = $murter();
    Stations::save($m['id'], array_merge($m, ['status' => 'closed', 'status_note' => 'Radovi']));
    $serve(['https://www.ina.hr/station/murter/' => $murterPage()]);
    InaSync::sync(null, 'Murter');
    eq(['closed', 'admin'], (fn($m) => [$m['status'], $m['status_source']])($murter()));
    Stations::save($m['id'], array_merge($murter(), ['status' => 'open']));
});
test('a station that leaves the finder is flagged (not deleted) and unflagged when it returns', function () use ($serve, $directory, $dirEntries) {
    $serve([InaSource::DIRECTORY_URL => $directory(array_slice($dirEntries, 1))]);
    eq(1, InaSync::sync(null, null, null, pages: false)['missing']);
    eq(1, (int) Db::one("SELECT ina_missing FROM stations WHERE name = 'Postaja 1'")['ina_missing']);
    $serve([InaSource::DIRECTORY_URL => $directory(array_slice($dirEntries, 5, 3))]); // a broken, short list never flags anything
    eq(0, InaSync::sync(null, null, null, pages: false)['missing']);
    $serve([InaSource::DIRECTORY_URL => $directory($dirEntries)]);
    eq(1, InaSync::sync(null, null, null, pages: false)['returned']);
});
test('stations listed on ina.hr can only be hidden; hidden ones are not published', function () {
    $id = (int) Db::one("SELECT id FROM stations WHERE name = 'Postaja 2'")['id'];
    try { Stations::delete($id); throw new RuntimeException('deleted'); } catch (HttpError $e) { eq(422, $e->status); }
    Stations::save($id, array_merge(Stations::find($id), ['hidden' => 1]));
    eq(false, in_array('Postaja 2', array_column(Stations::publicRows(), 'name'), true));
    Stations::save($id, array_merge(Stations::find($id), ['hidden' => 0]));
});
test('a station added by hand is linked to its finder entry by phone instead of being duplicated', function () use ($serve, $directory, $dirEntries, $entry) {
    Stations::save(null, ['name' => 'Moja Čakovec', 'phone' => '040 123 456']);
    $serve([InaSource::DIRECTORY_URL => $directory([...$dirEntries, $entry('cakovec-istok', 'Čakovec istok', '+385 40 123 456', ['zip' => '40000'])])]);
    $r = InaSync::sync(null, null, null, pages: false);
    eq(0, $r['added']);
    $s = Db::one("SELECT * FROM stations WHERE ina_url = 'https://www.ina.hr/station/cakovec-istok/'");
    eq(['Čakovec istok', 'Međimurska županija'], [$s['name'], $s['county']]);
});
test('cron batches: every station once per hour', function () {
    $rows = range(0, 130);
    $seen = [];
    for ($m = 0; $m < 60; $m++) foreach (InaSync::dailyBatch($rows, $m) as $r) $seen[] = $r;
    sort($seen);
    eq($rows, $seen);
    throws(fn() => InaSync::dailyBatch($rows, 60));
});

echo "Public page\n";
$fuel = fn(string $name, string $price, string $prev = '', string $at = '') => ['name' => $name, 'cat' => Fuels::category($name), 'price' => $price, 'prev' => $prev, 'changed_at' => $at];
$row = fn(array $fuels, array $more = []) => $more + ['name' => 'Test', 'address' => 'Ulica 1', 'postal_code' => '10000', 'city' => 'Zagreb', 'county' => 'Grad Zagreb',
    'phone' => '01 234 5678 ; 091 111 2222', 'lat' => '', 'lng' => '', 'aliases' => '', 'note' => '', 'ina_url' => '', 'working_hours' => [], 'fuels' => $fuels,
    'services' => [], 'ina_synced_at' => '2026-10-20 04:12:00', 'status' => 'open', 'status_note' => '', 'closed_from' => '', 'closed_until' => ''];
test('card escapes station data', function () use ($row) {
    $c = Render::card($row([], ['name' => '<script>x</script>', 'note' => '"><img src=x>', 'services' => ['<b>']]));
    eq(false, str_contains($c, '<script>x'));
    eq(false, str_contains($c, '<img src=x'));
    has('&lt;script&gt;x&lt;/script&gt;', $c);
});
test('card: one call button per number, cheapest price per type for sorting, coordinates for the map link', function () use ($row, $fuel) {
    $c = Render::card($row([$fuel('Eurodiesel Class Plus', '1.67'), $fuel('Eurodiesel Class Plus Premium', '1.85'), $fuel('Eurosuper 95', '1.73')], ['lat' => '45.800000', 'lng' => '15.900000']));
    has('href="tel:012345678"', $c);
    has('href="tel:0911112222"', $c);
    has('data-prices="{&quot;edcp&quot;:1.67,&quot;edcpp&quot;:1.85,&quot;es95&quot;:1.73}"', $c);
    has('query=45.800000%2C15.900000', $c);
    has('Cijene s ina.hr, 20. 10. 2026.', $c);
});
test('more than six services: the rest behind "+ još N"', function () use ($row) {
    $c = Render::card($row([], ['services' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']]));
    has('<summary>+ još 2</summary>', $c);
});
test('price overview: most common price per type, range, station count, "Nova cijena" for a week', function () use ($row, $fuel) {
    $rows = [$row([$fuel('Eurosuper 95 Class Plus', '1.69', '1.73', '2026-10-21 00:20:00'), $fuel('Autoplin', '0.89')]),
        $row([$fuel('Eurosuper 95 Class Plus', '1.69', '1.73', '2026-10-21 00:40:00')]), $row([$fuel('Eurosuper 95 Class Plus', '1.79')])];
    $o = Render::priceOverview($rows, '2026-10-23 10:00:00');
    has('<h3 class="price-name">Eurosuper 95 Class Plus</h3><p class="price-value"><span class="nw">1,69 € <span class="price-unit">/ l</span></span></p>', $o);
    has('najčešća cijena · raspon 1,69 € – 1,79 € · postaja: 3', $o);
    has('<span class="price-flag">Nova cijena</span> od 21. 10. 2026., prije 1,73 € (pojeftinjenje −0,04 €)', $o);
    has('ista na svim postajama · postaja: 1', $o);
    eq(false, str_contains(Render::priceOverview($rows, '2026-11-05 10:00:00'), 'price-flag'));
    eq('', Render::priceOverview([$row([])]));
});
test('map link without coordinates searches INA + name + address', function () {
    eq('https://www.google.com/maps/search/?api=1&query=INA%20%C4%8Cazma%2C%20Hrvatska', Render::mapUrl(['name' => 'Čazma', 'address' => '', 'postal_code' => '', 'city' => '', 'lat' => '', 'lng' => '']));
});
test('published index.html has every placeholder filled, filters with counts, and the colour-scheme meta first', function () {
    Stations::publish();
    $html = (string) file_get_contents(Config::get('web_root') . '/index.html');
    eq(false, (bool) preg_match('/\{\{[A-Z_]+\}\}/', $html));
    has('<option value="es95cp">Eurosuper 95 Class Plus (', $html);
    has('<option value="caffe bar">Caffe bar (1)</option>', $html);
    has('<option value="blizina">Najbliže meni</option>', $html);
    eq(true, strpos($html, 'name="color-scheme"') < strpos($html, 'theme-init.js'));
    $json = json_decode((string) file_get_contents(Config::get('web_root') . '/data/stations.json'), true);
    eq(true, count($json['stations']) > 20);
    eq(false, array_key_exists('locked', $json['stations'][0])); // internal fields stay internal
});

echo "AdBlue\n";
$adRow = fn(array $more) => $more + ['name' => 'Ad', 'address' => '', 'postal_code' => '', 'city' => '', 'county' => '', 'phone' => '', 'lat' => '', 'lng' => '',
    'aliases' => '', 'note' => '', 'ina_url' => '', 'working_hours' => [], 'fuels' => [], 'services' => [], 'adblue_pump' => 0, 'ina_synced_at' => '2026-10-24 04:00:00',
    'status' => 'open', 'status_note' => '', 'closed_from' => '', 'closed_until' => ''];
test('AdBlue is listed and filterable but never priced (card, sort data, overview, stations.json)', function () use ($adRow) {
    $s = $adRow(['fuels' => [['name' => 'Eurodiesel', 'cat' => 'ed', 'price' => '1.60', 'prev' => '', 'changed_at' => ''],
        ['name' => 'AdBlue', 'cat' => 'adblue', 'price' => '0.99', 'prev' => '1.05', 'changed_at' => '2026-10-23 00:00:00']]]);
    $c = Render::card($s, '2026-10-24');
    has('<li class="fuel fuel-adblue" data-cat="adblue"><span class="fuel-name">AdBlue</span></li>', $c);
    has('data-fuels="ed adblue"', $c);
    has('data-prices="{&quot;ed&quot;:1.6}"', $c);
    eq(false, str_contains($c, '0,99'));
    eq(false, str_contains(Render::priceOverview([$s]), 'AdBlue'));
    $m = Stations::find((int) Db::one("SELECT id FROM stations WHERE name = 'Murter'")['id']);
    Db::exec('UPDATE stations SET fuels = ? WHERE id = ?', [json_encode([['name' => 'AdBlue', 'cat' => 'adblue', 'price' => '0.99', 'prev' => '', 'changed_at' => '']]), $m['id']]);
    $pub = array_column(Stations::publicRows(), null, 'name')['Murter'];
    eq('', $pub['fuels'][0]['price']);
});
test('"moguće istakanje iz pištolja": shown next to AdBlue, adds AdBlue if ina.hr did not list it, own filter token', function () use ($adRow) {
    $c = Render::card($adRow(['adblue_pump' => 1]), '2026-10-24');
    has('<span class="fuel-name">AdBlue</span> <span class="fuel-note">moguće istakanje iz pištolja</span>', $c);
    has('data-fuels="adblue adblue-pump"', $c);
    eq(false, str_contains($c, 'Cijene s ina.hr')); // nothing priced on this card
    // ina.hr lists AdBlue under services instead of fuels: still an (unpriced) AdBlue row, no pump token
    [$fuels, $tokens] = Render::cardFuels($adRow(['services' => ['Caffe bar', 'AdBlue']]));
    eq([['AdBlue'], [''], ['adblue']], [array_column($fuels, 'name'), array_column($fuels, 'price'), $tokens]);
    eq([[], []], Render::cardFuels($adRow([])));
});
test('the flag is saved by the admin, kept by the sync, and gets its own filter option', function () use ($serve, $murterPage) {
    $m = Stations::find((int) Db::one("SELECT id FROM stations WHERE name = 'Murter'")['id']);
    Stations::save($m['id'], array_merge($m, ['adblue_pump' => 1]));
    $serve(['https://www.ina.hr/station/murter/' => $murterPage()]);
    InaSync::sync(null, 'Murter');
    eq(1, Stations::find($m['id'])['adblue_pump']);
    $html = (string) file_get_contents(Config::get('web_root') . '/index.html');
    has('<option value="adblue">AdBlue (', $html);
    has('<option value="adblue-pump">AdBlue – istakanje iz pištolja (1)</option>', $html);
    eq(true, strpos($html, 'value="adblue"') < strpos($html, 'value="adblue-pump"'));
});
test('migrate() adds adblue_pump to a database created before it existed', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE stations (id INTEGER PRIMARY KEY, name TEXT)");
    $cols = fn() => array_column($pdo->query('PRAGMA table_info(stations)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    eq(false, in_array('adblue_pump', $cols(), true));
    $ref = new ReflectionProperty(Db::class, 'pdo');
    $old = $ref->getValue();
    $ref->setValue(null, $pdo);
    try { Db::addColumnIfMissing('stations', 'adblue_pump', 'TINYINT(1) NOT NULL DEFAULT 0'); } finally { $ref->setValue(null, $old); }
    eq(true, in_array('adblue_pump', $cols(), true));
});

echo "Import / export\n";
test('CSV export → import round trip (semicolon, BOM); matched by INA link, nothing duplicated', function () {
    $before = (int) Db::one('SELECT COUNT(*) AS n FROM stations')['n'];
    $csv = Stations::toCsv(Stations::all());
    $csv = str_replace('Put Gradine 1', 'Put Gradine 2', $csv);
    [$created, $updated, $errors] = Stations::import($csv, 'postaje.csv');
    eq([0, $before, []], [$created, $updated, $errors]);
    eq('Put Gradine 2', Db::one("SELECT address FROM stations WHERE name = 'Murter'")['address']);
});

echo "Checked list from Excel (names, addresses, counties, AdBlue)\n";
test('import by name only: case/č/spaces around "-" ignored, county without "županija", nothing new added', function () {
    Stations::save(null, ['name' => 'Đakovo-Nazorova', 'city' => 'Đakovo']);
    $before = (int) Db::one('SELECT COUNT(*) AS n FROM stations')['n'];
    $csv = "name;address;city;county;adblue_have\n"
        . "MURTER;Put Gradine 9;Murter;Šibensko-kninska;1\n"
        . "postaja 3;Ulica 3a;Zagreb;Zagrebačka;0\n"
        . "đakovo - NAZOROVA;Vladimira Nazora 70;Đakovo;Osječko-baranjska;1\n"
        . "Nepostojeća;Nigdje 1;Nigdje;Istarska;1\n"
        . "Murter;Put Gradine 9;Murter;Mars;1\n";
    [$created, $updated, $errors] = Stations::import($csv, 'provjereno.csv');
    eq([0, 3], [$created, $updated]);
    eq((int) $before, (int) Db::one('SELECT COUNT(*) AS n FROM stations')['n']);
    eq(['Redak 5 (Nepostojeća): postaja nije pronađena (nije dodana)', 'Redak 6 (Murter): ista postaja već je u retku 2'], $errors);
    $m = Db::one("SELECT * FROM stations WHERE name = 'Murter'");
    eq(['Put Gradine 9', 'Šibensko-kninska županija', '1'], [$m['address'], $m['county'], (string) $m['adblue_have']]);
    eq('Zagrebačka županija', Db::one("SELECT county FROM stations WHERE name = 'Postaja 3'")['county']);
    eq(['Vladimira Nazora 70', 'Osječko-baranjska županija', '1'], array_values(array_map('strval', Db::one("SELECT address, county, adblue_have FROM stations WHERE name = 'Đakovo-Nazorova'"))));
});
test('two stations with the same name: the row is reported, not guessed', function () {
    Stations::save(null, ['name' => 'Dvojnik', 'city' => 'A']);
    Stations::save(null, ['name' => 'Dvojnik', 'city' => 'B']);
    [, $updated, $errors] = Stations::import("name;address\nDvojnik;X 1\nDvojnik;Y 2\n", 'x.csv');
    eq(0, $updated);
    eq(true, str_contains($errors[0], 'više postaja s tim nazivom'));
    [, $updated] = Stations::import("name;city;address\nDvojnik;B;Y 2\n", 'x.csv'); // with the city it is clear
    eq(1, $updated);
    eq('Y 2', Db::one("SELECT address FROM stations WHERE name = 'Dvojnik' AND city = 'B'")['address']);
});
test('the sync keeps imported addresses and cities, and stores the INA region separately from the county', function () use ($serve, $directory, $dirEntries, $murterPage) {
    $entries = $dirEntries;
    $entries[count($entries) - 1]['address'] = 'Put Gradine 1'; // ina.hr still has the old address
    $page = str_replace('<h1>Murter</h1>', '<h1>Murter</h1><ul><li><b>Regija:</b> JUG</li><li><b>Adresa:</b> Put Gradine 1</li></ul>', $murterPage());
    $serve([InaSource::DIRECTORY_URL => $directory($entries), 'https://www.ina.hr/station/murter/' => $page]);
    InaSync::sync(null, null, null, pages: false);
    InaSync::sync(null, 'Murter');
    $m = Db::one("SELECT * FROM stations WHERE name = 'Murter'");
    eq(['Put Gradine 9', 'Murter', 'Šibensko-kninska županija', 'JUG'], [$m['address'], $m['city'], $m['county'], $m['ina_region']]);
});
test('"Ima AdBlue" (from the list) shows AdBlue on the card even when ina.hr does not list it', function () {
    $pub = array_column(Stations::publicRows(), null, 'name')['Murter'];
    eq(1, $pub['adblue_have']);
    has('<span class="fuel-name">AdBlue</span>', Render::card($pub));
    eq(false, array_key_exists('ina_region', $pub)); // internal, not published
});

echo "\n$count tests, $failures failed\n";
exit($failures ? 1 : 0);

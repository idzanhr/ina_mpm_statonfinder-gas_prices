<?php
declare(strict_types=1);

namespace InaPostaje;

final class Stations
{
    public const COUNTIES = [
        'Bjelovarsko-bilogorska županija', 'Brodsko-posavska županija', 'Dubrovačko-neretvanska županija', 'Grad Zagreb',
        'Istarska županija', 'Karlovačka županija', 'Koprivničko-križevačka županija', 'Krapinsko-zagorska županija',
        'Ličko-senjska županija', 'Međimurska županija', 'Osječko-baranjska županija', 'Požeško-slavonska županija',
        'Primorsko-goranska županija', 'Sisačko-moslavačka županija', 'Splitsko-dalmatinska županija',
        'Šibensko-kninska županija', 'Varaždinska županija', 'Virovitičko-podravska županija',
        'Vukovarsko-srijemska županija', 'Zadarska županija', 'Zagrebačka županija',
    ];

    /** First two digits of the Croatian postcode → county. 10xxx is decided by the place (Grad Zagreb or Zagrebačka). */
    private const POSTAL_COUNTY = [
        '20' => 'Dubrovačko-neretvanska županija', '21' => 'Splitsko-dalmatinska županija', '22' => 'Šibensko-kninska županija',
        '23' => 'Zadarska županija', '31' => 'Osječko-baranjska županija', '32' => 'Vukovarsko-srijemska županija',
        '33' => 'Virovitičko-podravska županija', '34' => 'Požeško-slavonska županija', '35' => 'Brodsko-posavska županija',
        '40' => 'Međimurska županija', '42' => 'Varaždinska županija', '43' => 'Bjelovarsko-bilogorska županija',
        '44' => 'Sisačko-moslavačka županija', '47' => 'Karlovačka županija', '48' => 'Koprivničko-križevačka županija',
        '49' => 'Krapinsko-zagorska županija', '51' => 'Primorsko-goranska županija', '52' => 'Istarska županija',
        '53' => 'Ličko-senjska županija',
    ];

    /** Fields the admin edits. Fuels and prices are never edited by hand: they only come from ina.hr. */
    public const TEXT_FIELDS = ['name', 'address', 'city', 'postal_code', 'county', 'ina_region', 'phone', 'lat', 'lng', 'aliases', 'note', 'ina_url',
        'status', 'status_note', 'closed_from', 'closed_until'];
    private const MAX = ['name' => 190, 'address' => 255, 'city' => 120, 'postal_code' => 16, 'county' => 120, 'ina_region' => 60, 'phone' => 64,
        'lat' => 16, 'lng' => 16, 'aliases' => 2000, 'note' => 500, 'ina_url' => 255,
        'status' => 16, 'status_note' => 255, 'closed_from' => 10, 'closed_until' => 10];
    /**
     * hidden = not published, locked = sync keeps manual edits, adblue_have = has AdBlue (confirmed by the admin),
     * adblue_pump = AdBlue from a pump nozzle (INA Loyalty app). The sync never changes any of them.
     */
    public const FLAGS = ['hidden', 'locked', 'adblue_have', 'adblue_pump'];
    public const STATUSES = ['open', 'closed'];
    private const HOURS_KEYS = ['label', 'week', 'sat', 'sun', 'hol'];

    // ---- queries -------------------------------------------------------

    public static function all(): array
    {
        $rows = array_map([self::class, 'hydrate'], Db::all('SELECT * FROM stations'));
        self::sortByName($rows);
        return $rows;
    }

    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT * FROM stations WHERE id = ?', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    public static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        foreach (['working_hours', 'fuels', 'services'] as $f) {
            $v = json_decode((string) ($row[$f] ?? ''), true);
            $row[$f] = is_array($v) ? $v : [];
        }
        foreach ([...self::FLAGS, 'ina_missing'] as $f) $row[$f] = (int) ($row[$f] ?? 0);
        // The product is derived from the name every time, so a change to Fuels::CATEGORIES
        // applies to stored fuels at once, without waiting for the next sync.
        $row['fuels'] = array_values(array_filter($row['fuels'], fn($f) => is_array($f) && isset($f['name'])));
        foreach ($row['fuels'] as &$fuel) $fuel['cat'] = Fuels::category((string) $fuel['name']);
        unset($fuel);
        Fuels::sort($row['fuels']);
        return $row;
    }

    public static function sortByName(array &$rows): void
    {
        $collator = class_exists(\Collator::class) ? new \Collator('hr_HR') : null;
        usort($rows, fn($a, $b) => ($collator ? $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name'])) ?: $a['id'] <=> $b['id']);
    }

    // ---- counties ------------------------------------------------------

    /** "Splitsko-dalmatinska", "SPLITSKO-DALMATINSKA ŽUPANIJA", "Zagreb" → the official name, or ''. */
    public static function county(string $value): string
    {
        $v = self::fold($value);
        if ($v === '') return '';
        foreach (self::COUNTIES as $c) {
            $f = self::fold($c);
            if ($v === $f || $v . ' zupanija' === $f || ($v === 'zagreb' && $c === 'Grad Zagreb')) return $c;
        }
        return '';
    }

    /** County guessed from the postcode, used only to fill an empty county (the admin can correct it). */
    public static function countyFromPostal(string $postal, string $city = ''): string
    {
        if (!preg_match('/^\d{5}$/', $postal)) return '';
        $prefix = substr($postal, 0, 2);
        if ($prefix === '10') {
            $inCity = (int) $postal < 10200 || preg_match('/^(zagreb|sesvete|lucko|brezovica)\b/', self::fold($city));
            return $inCity ? 'Grad Zagreb' : 'Zagrebačka županija';
        }
        return self::POSTAL_COUNTY[$prefix] ?? '';
    }

    private static function fold(string $s): string
    {
        $s = strtr(mb_strtolower(trim($s), 'UTF-8'), ['č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z']);
        return trim(preg_replace(['/\s+/u', '/ ?[-–] ?/u'], [' ', '-'], $s));
    }

    // ---- validation ----------------------------------------------------

    /** @return array{0: array, 1: string[]} [clean row, errors] */
    public static function validate(array $in): array
    {
        $row = [];
        $errors = [];
        foreach (self::TEXT_FIELDS as $f) {
            $v = $in[$f] ?? '';
            if (!is_string($v) && !is_int($v) && !is_float($v)) { $errors[] = "Neispravno polje: $f"; $v = ''; }
            $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v));
            if (mb_strlen($v) > self::MAX[$f]) $errors[] = "Polje $f je predugo (najviše " . self::MAX[$f] . ' znakova).';
            $row[$f] = $v;
        }
        foreach (self::FLAGS as $f) $row[$f] = !empty($in[$f]) ? 1 : 0;
        $row['aliases'] = implode(' / ', self::splitAliases($row['aliases']));
        if ($row['name'] === '') $errors[] = 'Naziv je obavezan.';
        if ($row['county'] !== '' && !in_array($row['county'], self::COUNTIES, true)) {
            // "Vukovarsko-srijemska", "GRAD ZAGREB" … → the official name
            $row['county'] = self::county($row['county']) ?: $row['county'];
            if (!in_array($row['county'], self::COUNTIES, true)) $errors[] = 'Nepoznata županija: ' . $row['county'] . '.';
        }
        if ($row['ina_url'] !== '') {
            $url = InaSource::safeUrl($row['ina_url']);
            if ($url === null) $errors[] = 'INA poveznica mora biti oblika https://www.ina.hr/station/…/';
            else $row['ina_url'] = $url;
        }
        if ($row['lat'] !== '' || $row['lng'] !== '') {
            $c = InaSource::coords(str_replace(',', '.', $row['lat']), str_replace(',', '.', $row['lng']));
            if ($c === null) $errors[] = 'Koordinate moraju biti u Hrvatskoj (npr. 45.815011 i 15.981919).';
            else [$row['lat'], $row['lng']] = $c;
        }

        // Temporary closure (works, renovation…): optional note and from/until dates.
        if ($row['status'] === '') $row['status'] = 'open';
        if (!in_array($row['status'], self::STATUSES, true)) $errors[] = 'Nepoznat status postaje.';
        foreach (['closed_from' => 'od', 'closed_until' => 'do'] as $f => $label) {
            if ($row[$f] !== '' && !self::isDate($row[$f])) $errors[] = "Neispravan datum zatvaranja ($label).";
        }
        if ($row['closed_from'] !== '' && $row['closed_until'] !== '' && $row['closed_until'] < $row['closed_from']) {
            $errors[] = 'Datum "do" je prije datuma "od".';
        }
        if ($row['status'] === 'open') $row['status_note'] = $row['closed_from'] = $row['closed_until'] = '';

        $row['working_hours'] = self::cleanHours($in['working_hours'] ?? [], $errors);
        $row['services'] = self::cleanServices($in['services'] ?? [], $errors);
        return [$row, $errors];
    }

    private static function cleanHours(mixed $hours, array &$errors): array
    {
        if (is_string($hours)) $hours = json_decode($hours, true) ?? [];
        if (!is_array($hours) || count($hours) > 6) { $errors[] = 'Neispravno radno vrijeme.'; return []; }
        $out = [];
        foreach ($hours as $h) {
            if (!is_array($h)) { $errors[] = 'Neispravno radno vrijeme.'; return []; }
            $clean = [];
            foreach (self::HOURS_KEYS as $k) $clean[$k] = mb_substr(trim((string) ($h[$k] ?? '')), 0, 300);
            if (implode('', $clean) !== '') $out[] = $clean;
        }
        return $out;
    }

    private static function cleanServices(mixed $services, array &$errors): array
    {
        if (is_string($services)) $services = json_decode($services, true) ?? array_map('trim', explode(',', $services));
        if (!is_array($services) || count($services) > 40) { $errors[] = 'Neispravan popis usluga (najviše 40).'; return []; }
        $out = [];
        foreach ($services as $s) {
            if (!is_scalar($s)) continue;
            $s = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s)), 0, 80);
            if ($s !== '') $out[mb_strtolower($s)] ??= $s;
        }
        return array_values($out);
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * What a visitor should see on $today (YYYY-MM-DD): 'open', 'closed' or 'upcoming'
     * (closure announced for a later date). An expired closure counts as open again.
     * Mirrored in assets/js/site.js and customer-gas-updater/assets/admin.js.
     */
    public static function closureState(array $s, string $today): string
    {
        if (($s['status'] ?? 'open') !== 'closed') return 'open';
        if (($s['closed_until'] ?? '') !== '' && $today > $s['closed_until']) return 'open';
        if (($s['closed_from'] ?? '') !== '' && $today < $s['closed_from']) return 'upcoming';
        return 'closed';
    }

    public static function splitAliases(string $aliases): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode('/', $aliases)), 'strlen')));
    }

    // ---- writes --------------------------------------------------------

    public static function save(?int $id, array $input): array
    {
        [$row, $errors] = self::validate($input);
        if ($errors) throw new HttpError(422, implode(' ', $errors));
        if ($row['ina_url'] !== '') {
            $dupe = Db::one('SELECT name FROM stations WHERE ina_url = ? AND id <> ?', [$row['ina_url'], $id ?? 0]);
            if ($dupe) throw new HttpError(422, 'Ta INA poveznica već pripada postaji „' . $dupe['name'] . '”.');
        }
        $before = $id ? self::find($id) : null;
        if ($id && !$before) throw new HttpError(404, 'Postaja ne postoji.');
        if ($row['county'] === '') $row['county'] = self::countyFromPostal($row['postal_code'], $row['city']);

        $cols = [...self::TEXT_FIELDS, ...self::FLAGS, 'working_hours', 'services', 'status_source', 'updated_at'];
        $params = [...array_map(fn($f) => $row[$f], [...self::TEXT_FIELDS, ...self::FLAGS]),
            json_encode($row['working_hours'], JSON_UNESCAPED_UNICODE), json_encode($row['services'], JSON_UNESCAPED_UNICODE),
            self::statusSource($before, $row), Db::now()];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = ?", $cols));
            Db::exec("UPDATE stations SET $set WHERE id = ?", [...$params, $id]);
        } else {
            $cols[] = 'created_at'; $params[] = Db::now();
            $cols[] = 'fuels'; $params[] = '[]';
            Db::exec('INSERT INTO stations (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($params), '?')) . ')', $params);
            $id = (int) Db::pdo()->lastInsertId();
        }
        Audit::log($before ? 'update' : 'create', $row['name'], $before ? self::diff($before, $row) : '');
        return self::find($id);
    }

    /** Inserts a station found in the ina.hr finder (sync only; no audit per field). */
    public static function insertFromIna(array $d): int
    {
        $county = $d['county'] ?: self::countyFromPostal($d['postal_code'], $d['city']);
        Db::exec('INSERT INTO stations (name, address, city, postal_code, county, phone, lat, lng, aliases, ina_url, working_hours, fuels, services, created_at, updated_at)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?, '[]', '[]', '[]', ?, ?)",
            [mb_substr($d['name'], 0, 190), mb_substr($d['address'], 0, 255), mb_substr($d['city'], 0, 120), mb_substr($d['postal_code'], 0, 16),
                $county, mb_substr($d['phone'], 0, 64), $d['lat'], $d['lng'], $d['url'], Db::now(), Db::now()]);
        return (int) Db::pdo()->lastInsertId();
    }

    /**
     * Who owns a closure. 'ina' = closed automatically from the ina.hr hours table, so the
     * sync may also reopen it. Setting dates (or closing an open station) makes it the admin's.
     */
    private static function statusSource(?array $before, array $row): string
    {
        if ($row['status'] !== 'closed') return '';
        $keepIna = $before && ($before['status_source'] ?? '') === 'ina' && $before['status'] === 'closed'
            && $row['closed_from'] === '' && $row['closed_until'] === '';
        return $keepIna ? 'ina' : 'admin';
    }

    /**
     * A station still listed on ina.hr would be re-added by the next sync, so it can only be
     * hidden. Stations added by hand, or no longer on ina.hr, can be deleted.
     */
    public static function delete(int $id): void
    {
        $s = self::find($id);
        if (!$s) throw new HttpError(404, 'Postaja ne postoji.');
        if ($s['ina_url'] !== '' && !$s['ina_missing'] && Config::get('ina_auto_add')) {
            throw new HttpError(422, 'Postaja je na popisu ina.hr i sinkronizacija bi je ponovno dodala. Umjesto brisanja označite „Sakrij s javne stranice”.');
        }
        Db::exec('DELETE FROM stations WHERE id = ?', [$id]);
        Db::exec('DELETE FROM price_history WHERE station_id = ?', [$id]);
        Audit::log('delete', $s['name'], json_encode(array_intersect_key($s, array_flip(['address', 'city', 'phone', 'ina_url'])), JSON_UNESCAPED_UNICODE));
    }

    private static function diff(array $before, array $after): string
    {
        $changes = [];
        foreach ([...self::TEXT_FIELDS, ...self::FLAGS, 'working_hours', 'services'] as $f) {
            if ($before[$f] != $after[$f]) $changes[] = $f;
        }
        return $changes ? 'Promijenjeno: ' . implode(', ', $changes) : 'Bez promjena';
    }

    // ---- import / export -----------------------------------------------

    /**
     * Updates stations from this CMS's JSON export or a CSV with a header row (e.g. a checked list from Excel:
     * name;address;city;county;adblue_have). A row finds its station by INA link, then by name + city, then by
     * name alone when exactly one station has it (case, spaces and č/ć/đ/š/ž ignored). Fields absent from the
     * file keep their current values. Stations come from ina.hr, so by default a row that matches nothing is
     * reported, not added ($addNew = true adds it). Returns [created, updated, errors].
     */
    public static function import(string $content, string $filename, bool $addNew = false): array
    {
        $isCsv = str_ends_with(strtolower($filename), '.csv');
        $rows = $isCsv ? self::parseCsv($content) : json_decode($content, true);
        if (is_array($rows) && isset($rows['stations'])) $rows = $rows['stations'];
        if (!is_array($rows) || !array_is_list($rows)) throw new HttpError(422, 'Datoteka nije ispravan JSON/CSV popis postaja.');

        $byName = [];
        foreach (Db::all('SELECT id, name FROM stations') as $r) $byName[self::fold($r['name'])][] = (int) $r['id'];
        $created = $updated = 0;
        $errors = [];
        $seen = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $i => $in) {
                $line = 'Redak ' . ($i + ($isCsv ? 2 : 1)); // CSV: row 1 is the header, as in Excel
                if (!is_array($in)) { $errors[] = "$line: neispravan zapis"; continue; }
                $name = trim((string) ($in['name'] ?? ''));
                $label = "$line (" . ($name !== '' ? $name : '?') . ')';
                $url = InaSource::safeUrl((string) ($in['ina_url'] ?? ''));
                $existing = $url ? Db::one('SELECT * FROM stations WHERE ina_url = ?', [$url]) : null;
                $existing ??= $name !== '' ? Db::one('SELECT * FROM stations WHERE name = ? AND city = ?', [$name, trim((string) ($in['city'] ?? ''))]) : null;
                if (!$existing && $name !== '') {
                    $ids = $byName[self::fold($name)] ?? [];
                    if (count($ids) > 1) { $errors[] = "$label: više postaja s tim nazivom – dodajte stupac city ili ina_url"; continue; }
                    if ($ids) $existing = Db::one('SELECT * FROM stations WHERE id = ?', [$ids[0]]);
                }
                if (!$existing && !$addNew) { $errors[] = "$label: postaja nije pronađena (nije dodana)"; continue; }
                if ($existing && isset($seen[$existing['id']])) { $errors[] = "$label: ista postaja već je u retku " . $seen[$existing['id']]; continue; }
                // Matched by name: keep the station's own spelling ("MURTER" in a list does not rename "Murter").
                if ($existing && $name !== '' && self::fold($name) === self::fold($existing['name'])) $in['name'] = $existing['name'];
                $merged = $existing ? array_merge(self::hydrate($existing), array_filter($in, fn($v) => $v !== null)) : $in;
                try {
                    self::save($existing ? (int) $existing['id'] : null, $merged);
                    if ($existing) { $seen[$existing['id']] = $i + ($isCsv ? 2 : 1); $updated++; } else { $created++; }
                } catch (HttpError $e) {
                    $errors[] = "$label: " . $e->getMessage();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [$created, $updated, $errors];
    }

    private static function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_filter(preg_split('/\r\n|\n|\r/', $content), 'strlen');
        $first = reset($lines) ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = null;
        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, $sep, '"', '');
            if ($header === null) { $header = array_map(fn($h) => strtolower(trim($h)), $cells); continue; }
            $row = [];
            foreach ($header as $j => $h) if ($h !== '') $row[$h] = $cells[$j] ?? '';
            $rows[] = $row;
        }
        return $rows;
    }

    public static function toCsv(array $stations): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        $json = ['working_hours', 'services', 'fuels'];
        fputcsv($fh, [...self::TEXT_FIELDS, ...self::FLAGS, ...$json], ';', '"', '');
        foreach ($stations as $s) {
            fputcsv($fh, [...array_map(fn($f) => $s[$f], [...self::TEXT_FIELDS, ...self::FLAGS]),
                ...array_map(fn($f) => json_encode($s[$f], JSON_UNESCAPED_UNICODE), $json)], ';', '"', '');
        }
        rewind($fh);
        return stream_get_contents($fh);
    }

    // ---- static site ---------------------------------------------------

    /** Public projection: visible stations, only what the page needs. */
    public static function publicRows(): array
    {
        $rows = array_filter(self::all(), fn($s) => !$s['hidden']);
        return array_values(array_map(fn($s) => [
            'name' => $s['name'], 'address' => $s['address'], 'postal_code' => $s['postal_code'], 'city' => $s['city'],
            'county' => $s['county'], 'phone' => $s['phone'], 'lat' => $s['lat'], 'lng' => $s['lng'],
            'aliases' => $s['aliases'], 'note' => $s['note'],
            'ina_url' => InaSource::safeUrl($s['ina_url']) ?? '', 'working_hours' => $s['working_hours'],
            // Unpriced products (AdBlue) are published without a price, whatever ina.hr says.
            'fuels' => array_map(fn($f) => Fuels::priced($f['cat']) ? $f : ['price' => '', 'prev' => '', 'changed_at' => ''] + $f, $s['fuels']),
            'adblue_have' => $s['adblue_have'], 'adblue_pump' => $s['adblue_pump'], 'services' => $s['services'], 'ina_synced_at' => $s['ina_synced_at'],
            'status' => $s['status'], 'status_note' => $s['status_note'],
            'closed_from' => $s['closed_from'], 'closed_until' => $s['closed_until'],
        ], $rows));
    }

    /**
     * Regenerates every public file from the database and app/templates/:
     * index.html, privatnost.html, kolacici.html, 404.html, data/stations.json,
     * plus pre-compressed .gz copies that nginx serves directly (gzip_static).
     */
    public static function publish(): void
    {
        $dir = rtrim(Config::get('web_root'), '/');
        $templates = dirname(__DIR__) . '/templates';
        $rows = self::publicRows();
        $generatedAt = date('c');

        // Assets first, so their .gz exist before pages reference the new hashes.
        foreach (['/assets/css/site.css', '/assets/js/site.js', '/assets/js/hours.js', '/assets/js/theme-init.js', '/favicon.svg'] as $asset) {
            if (is_file($dir . $asset)) self::writeGzip($dir . $asset, (string) file_get_contents($dir . $asset));
        }
        self::atomicWrite("$dir/data/stations.json", json_encode(['generated_at' => $generatedAt, 'stations' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", true);
        $index = Render::page((string) file_get_contents("$templates/index.html"), $dir);
        self::atomicWrite("$dir/index.html", Render::index($index, $rows, $generatedAt), true);
        foreach (['privatnost', 'kolacici', '404'] as $page) {
            self::atomicWrite("$dir/$page.html", Render::page((string) file_get_contents("$templates/$page.html"), $dir), true);
        }
        Bunny::purgeAfterPublish(); // no-op unless a Bunny API key is configured
    }

    private static function writeGzip(string $path, string $content): void
    {
        $gz = $path . '.gz';
        if (is_file($gz) && filemtime($gz) >= filemtime($path)) return;
        self::atomicWrite($gz, gzencode($content, 9));
        touch($gz, filemtime($path)); // gzip_static expects matching mtimes for If-Modified-Since
    }

    private static function atomicWrite(string $path, string $content, bool $withGzip = false): void
    {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) throw new \RuntimeException("Cannot write $path");
        chmod($tmp, 0644);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException("Cannot replace $path"); }
        if ($withGzip) {
            clearstatcache(true, $path);
            self::atomicWrite("$path.gz", gzencode($content, 9));
            touch("$path.gz", filemtime($path));
        }
    }
}

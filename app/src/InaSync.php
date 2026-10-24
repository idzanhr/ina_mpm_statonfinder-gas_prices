<?php
declare(strict_types=1);

namespace InaPostaje;

/**
 * Keeps the database in step with ina.hr:
 *  1. the finder (one request) adds new stations, fills/updates their details and flags stations
 *     that are no longer listed;
 *  2. each station's page gives working hours, fuels with prices, services and closure wording.
 * Admin data (aliases, notes, closures set by hand, hidden/locked flags) is never overwritten, a
 * page that cannot be read never erases what is stored, and a locked station keeps its edited
 * name/address/phone/hours/services (only fuels and prices still come from ina.hr).
 */
final class InaSync
{
    public const DAILY_BATCHES = 60;
    public const INA_CLOSED_NOTE = 'Privremeno zatvoreno (prema ina.hr)';
    /** Fewer entries than this = a broken finder page; nothing is flagged as missing then. */
    public const MIN_DIRECTORY = 20;
    private const DETAIL_FIELDS = ['name', 'address', 'city', 'postal_code', 'phone', 'lat', 'lng'];
    /** Filled from ina.hr only while empty: the admin keeps these checked (Uvoz / izvoz), the sync never overwrites them. */
    private const FILL_ONLY = ['address', 'city', 'postal_code'];

    public static function dailyBatch(array $rows, int $minute): array
    {
        if ($minute < 0 || $minute >= self::DAILY_BATCHES) throw new \InvalidArgumentException('Invalid batch minute');
        return array_values(array_filter($rows, fn($i) => $i % self::DAILY_BATCHES === $minute, ARRAY_FILTER_USE_KEY));
    }

    /**
     * Station pages to try, best first: the saved link, a unique phone match in the finder,
     * a unique name match in the finder (allowing INA's "-2" suffixes), and the address INA
     * would build from the name.
     * @return array<array{url: string, why: string}>
     */
    public static function candidates(array $station, array $directory): array
    {
        $out = [];
        if ($u = InaSource::safeUrl((string) $station['ina_url'])) $out[] = ['url' => $u, 'why' => 'spremljena poveznica'];
        $keys = InaSource::phoneKeys((string) $station['phone']);
        $byPhone = array_values(array_unique(array_column(array_filter($directory, fn($d) => array_intersect($d['phones'], $keys)), 'url')));
        if (count($byPhone) === 1) $out[] = ['url' => $byPhone[0], 'why' => 'telefon u tražilici ina.hr'];
        $slug = InaSource::slug((string) $station['name']);
        if ($slug !== '') {
            $bySlug = array_values(array_filter(array_column($directory, 'url'), fn($u) => preg_match('#/station/' . preg_quote($slug, '#') . '(?:-\d+)?/$#', $u)));
            if (count($bySlug) === 1) $out[] = ['url' => $bySlug[0], 'why' => 'naziv u tražilici ina.hr'];
            $out[] = ['url' => 'https://www.ina.hr/station/' . $slug . '/', 'why' => 'adresa iz naziva'];
        }
        $seen = [];
        return array_values(array_filter($out, function ($c) use (&$seen) { if (isset($seen[$c['url']])) return false; return $seen[$c['url']] = true; }));
    }

    /**
     * @param int|null $minute batch 0-59 (cron), or null for every station
     * @param string|null $only station id or name (CLI --station / editor button); ignores the batch
     * @param callable(string): void|null $log one line per station
     * @param bool $pages false = only the finder (fast: adds stations and details, no hours/prices)
     */
    public static function sync(?int $minute, ?string $only = null, ?callable $log = null, bool $pages = true): array
    {
        $log ??= fn(string $line) => null;
        $result = ['directory' => 0, 'added' => 0, 'details' => 0, 'missing' => 0, 'returned' => 0,
            'checked' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'price_changes' => 0,
            'notices' => 0, 'closed' => 0, 'reopened' => 0, 'directoryAvailable' => true];
        $directory = [];
        try {
            $directory = InaSource::parseDirectory((InaSource::$fetch)(InaSource::DIRECTORY_URL));
            $result['directory'] = count($directory);
        } catch (\Throwable $e) {
            $result['directoryAvailable'] = false;
            $log('Tražilica ina.hr nije dostupna (' . $e->getMessage() . ') – pokušavam spremljene poveznice.');
        }
        if ($directory && $only === null) self::applyDirectory($directory, $result, $log);

        if ($pages) {
            $rows = array_map([Stations::class, 'hydrate'], Db::all('SELECT * FROM stations ORDER BY id'));
            if ($only !== null) {
                $rows = array_values(array_filter($rows, fn($r) => (string) $r['id'] === $only || mb_strtolower($r['name']) === mb_strtolower(trim($only))));
            }
            $batch = $minute === null || $only !== null ? $rows : self::dailyBatch($rows, $minute);
            $result['checked'] = count($batch);
            $delay = max(0, (int) Config::get('ina_delay_ms')) * 1000;
            foreach ($batch as $i => $station) {
                if ($i > 0 && $delay) usleep($delay); // be polite to ina.hr
                try { self::syncStation($station, $directory, $result, $log); }
                catch (\Throwable $e) { $result['failed']++; $log($station['name'] . ': ' . $e->getMessage()); }
            }
        }

        $changed = $result['added'] + $result['details'] + $result['missing'] + $result['returned'] + $result['updated']
            + $result['notices'] + $result['closed'] + $result['reopened'];
        // The last batch of a round (and every full run) re-publishes anyway, so "preuzeto s ina.hr" stays current.
        if ($changed > 0 || $minute === null || $minute === self::DAILY_BATCHES - 1) {
            if ($changed > 0) Audit::log('ina-sync', $only ?? '', json_encode($result));
            Stations::publish();
        }
        return $result;
    }

    /** Adds stations new on ina.hr, updates finder details and flags stations ina.hr no longer lists. */
    private static function applyDirectory(array $directory, array &$result, callable $log): void
    {
        $stations = array_map([Stations::class, 'hydrate'], Db::all('SELECT * FROM stations ORDER BY id'));
        $byUrl = [];
        foreach ($stations as $s) if ($s['ina_url'] !== '') $byUrl[$s['ina_url']] = $s;
        $unlinked = array_values(array_filter($stations, fn($s) => $s['ina_url'] === ''));

        foreach ($directory as $d) {
            $s = $byUrl[$d['url']] ?? self::matchUnlinked($d, $unlinked);
            if ($s === null) {
                if (!Config::get('ina_auto_add')) continue;
                $d['name'] = $d['name'] !== '' ? $d['name'] : InaSource::nameFromUrl($d['url']);
                $id = Stations::insertFromIna($d);
                $byUrl[$d['url']] = Stations::find($id);
                Audit::log('ina-add', $d['name'], $d['url']);
                $log("{$d['name']}: nova postaja s ina.hr ({$d['url']})");
                $result['added']++;
                continue;
            }
            $set = [];
            if ($s['ina_url'] !== $d['url']) $set['ina_url'] = $d['url'];
            foreach (self::DETAIL_FIELDS as $f) {
                // Locked stations keep what the admin wrote; empty fields are always filled; addresses only ever filled.
                $keep = $s['locked'] || in_array($f, self::FILL_ONLY, true);
                if ($d[$f] !== '' && $d[$f] !== $s[$f] && (!$keep || $s[$f] === '')) $set[$f] = $d[$f];
            }
            if ($s['county'] === '') {
                $county = $d['county'] ?: Stations::countyFromPostal($set['postal_code'] ?? $s['postal_code'], $set['city'] ?? $s['city']);
                if ($county !== '') $set['county'] = $county;
            }
            if ($s['ina_missing']) { $set['ina_missing'] = 0; $result['returned']++; Audit::log('ina-returned', $s['name'], $d['url']); }
            if ($set) {
                self::update((int) $s['id'], $set);
                $byUrl[$d['url']] = array_merge($s, $set);
                $result['details']++;
                $log("{$s['name']}: s tražilice ina.hr – " . implode(', ', array_keys($set)));
            }
            $unlinked = array_values(array_filter($unlinked, fn($u) => $u['id'] !== $s['id']));
        }

        if (count($directory) < self::MIN_DIRECTORY) return;
        $listed = array_flip(array_column($directory, 'url'));
        foreach ($byUrl as $url => $s) {
            if (isset($listed[$url]) || $s['ina_missing']) continue;
            self::update((int) $s['id'], ['ina_missing' => 1]);
            Audit::log('ina-missing', $s['name'], $url);
            $log("{$s['name']}: više nije u tražilici ina.hr ($url)");
            $result['missing']++;
        }
    }

    /** A station added by hand (no INA link yet) that is clearly this finder entry: unique phone or name. */
    private static function matchUnlinked(array $d, array $unlinked): ?array
    {
        $byPhone = array_values(array_filter($unlinked, fn($s) => $d['phones'] && array_intersect($d['phones'], InaSource::phoneKeys($s['phone']))));
        if (count($byPhone) === 1) return $byPhone[0];
        $slug = preg_replace('/-\d+$/', '', basename(rtrim((string) parse_url($d['url'], PHP_URL_PATH), '/')));
        $byName = array_values(array_filter($unlinked, fn($s) => InaSource::slug($s['name']) === $slug));
        return count($byName) === 1 ? $byName[0] : null;
    }

    private static function syncStation(array $station, array $directory, array &$result, callable $log): void
    {
        $tried = [];
        $page = $url = null;
        $fallback = null; // a page that loaded but had no hours table (e.g. closed) – still read for fuels/notices
        foreach (self::candidates($station, $directory) as $c) {
            try { $html = (InaSource::$fetch)($c['url']); }
            catch (\Throwable $e) { $tried[] = "{$c['why']} {$c['url']} → {$e->getMessage()}"; continue; }
            $parsed = InaSource::parseStation($html);
            if ($parsed['hours'] !== null) { $page = $parsed; $url = $c['url']; break; }
            $fallback ??= ['page' => $parsed, 'url' => $c['url']];
            $tried[] = "{$c['why']} {$c['url']} → nema tablice radnog vremena";
        }
        if ($page === null && $fallback && ($fallback['page']['fuels'] || $fallback['url'] === $station['ina_url'])) {
            ['page' => $page, 'url' => $url] = $fallback;
        }
        if ($page === null) throw new \RuntimeException('stranica na ina.hr nije pronađena (' . ($tried ? implode('; ', $tried) : 'nema kandidata') . ')');

        $name = $station['name'];
        $hours = $page['hours'];
        $closedByIna = $hours !== null && InaSource::hoursClosed($hours);
        $set = ['ina_synced_at' => Db::now()];
        $what = [];

        // 1. Fuels and prices. A changed price keeps the previous one and the date of the change
        //    (card: "nova cijena, prije …") and goes to price_history + the Dnevnik.
        if ($page['fuels']) {
            $fuels = self::mergeFuels((int) $station['id'], $name, $station['fuels'], $page['fuels'], $result, $log);
            if ($fuels !== $station['fuels']) { $set['fuels'] = json_encode($fuels, JSON_UNESCAPED_UNICODE); $what[] = 'goriva'; }
        }

        // 2. Services, and details the finder did not give (name stays as the finder/admin has it).
        if ($page['services'] && !$station['locked'] && $page['services'] !== $station['services']) {
            $set['services'] = json_encode($page['services'], JSON_UNESCAPED_UNICODE);
            $what[] = 'usluge';
        }
        foreach (['phone', 'lat', 'lng', 'address'] as $f) {
            if ($station[$f] === '' && $page['details'][$f] !== '') { $set[$f] = $page['details'][$f]; $what[] = $f; }
        }
        // INA's organisational region ("Regija: ZAPAD") – kept apart from the county.
        $region = $page['details']['ina_region'];
        if ($region !== '' && $region !== $station['ina_region'] && (!$station['locked'] || $station['ina_region'] === '')) {
            $set['ina_region'] = $region;
            $what[] = 'regija';
        }

        // 3. Notice for the admin. The hours table saying "PRIVREMENO ZATVORENO" every day is INA's
        //    own signal; any other closure wording is only flagged (could be a site-wide banner).
        $notice = $closedByIna ? 'Radno vrijeme na ina.hr: PRIVREMENO ZATVORENO (svi dani)' : $page['notice'];
        if ($notice !== (string) $station['ina_notice']) {
            $set['ina_notice'] = $notice;
            if ($notice !== '') { $result['notices']++; Audit::log('ina-notice', $name, $notice); }
        }

        // 4. Status. Only closures the sync made itself (status_source = ina) are reopened.
        if (Config::get('ina_auto_close') && $closedByIna && $station['status'] === 'open') {
            $set += ['status' => 'closed', 'status_source' => 'ina', 'status_note' => self::INA_CLOSED_NOTE, 'closed_from' => '', 'closed_until' => ''];
            Audit::log('ina-close', $name, $notice);
            $result['closed']++;
        } elseif ($hours !== null && !$closedByIna && $station['status'] === 'closed' && $station['status_source'] === 'ina') {
            $set += ['status' => 'open', 'status_source' => '', 'status_note' => '', 'closed_from' => '', 'closed_until' => ''];
            Audit::log('ina-reopen', $name, 'ina.hr ponovno prikazuje radno vrijeme');
            $result['reopened']++;
        }

        // 5. Working hours (kept even when they say closed, so a reopening is noticed) and the link.
        if ($url !== $station['ina_url'] && ($station['ina_url'] === '' || !$station['locked'])) { $set['ina_url'] = $url; $what[] = 'poveznica'; }
        if ($hours !== null && !$station['locked'] && !self::sameHours($station['working_hours'], $hours)) {
            $set['working_hours'] = json_encode($hours, JSON_UNESCAPED_UNICODE);
            $what[] = 'radno vrijeme';
        }

        if ($what || isset($set['status'])) $set['updated_at'] = Db::now();
        self::update((int) $station['id'], $set);
        if ($what) {
            $result['updated']++;
            $log("$name: ažurirano s $url – " . implode(', ', $what)
                . ($hours ? ' | ' . implode(' | ', array_map(fn($h) => "{$h['label']}: {$h['week']} / {$h['sat']} / {$h['sun']}", $hours)) : ''));
        } else {
            $result['unchanged']++;
            $log("$name: bez promjene ($url)");
        }
        if ($hours === null) $log("$name: stranica $url nema tablice radnog vremena");
    }

    /**
     * New fuel list from ina.hr, merged with the stored one: unchanged prices keep their history,
     * a fuel listed without a price keeps its last known price, fuels no longer listed are dropped.
     */
    private static function mergeFuels(int $stationId, string $station, array $old, array $new, array &$result, callable $log): array
    {
        $byName = [];
        foreach ($old as $f) if (is_array($f) && isset($f['name'])) $byName[mb_strtolower($f['name'])] = $f;
        $out = [];
        foreach ($new as $f) {
            $prev = $byName[mb_strtolower($f['name'])] ?? null;
            $row = ['name' => $f['name'], 'cat' => $f['cat'], 'price' => $f['price'],
                'prev' => $prev['prev'] ?? '', 'changed_at' => $prev['changed_at'] ?? ''];
            if ($prev && $f['price'] === '') $row['price'] = $prev['price'] ?? '';
            if ($prev && ($prev['price'] ?? '') !== '' && $f['price'] !== '' && $f['price'] !== $prev['price']) {
                $row['prev'] = $prev['price'];
                $row['changed_at'] = Db::now();
                Db::exec('INSERT INTO price_history (station_id, fuel_name, category, old_price, new_price, changed_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$stationId, $f['name'], $f['cat'], $prev['price'], $f['price'], Db::now()]);
                $detail = str_replace('.', ',', "{$f['name']}: {$prev['price']} € → {$f['price']} €");
                Audit::log('price', $station, $detail);
                $log("$station: $detail");
                $result['price_changes']++;
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function sameHours(array $saved, array $hours): bool
    {
        if (count($saved) !== count($hours)) return false;
        foreach ($hours as $i => $h) {
            if (!is_array($saved[$i] ?? null)) return false;
            foreach ($h as $k => $v) if (($saved[$i][$k] ?? null) !== $v) return false;
        }
        return true;
    }

    private static function update(int $id, array $set): void
    {
        if (!$set) return;
        Db::exec('UPDATE stations SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
    }
}

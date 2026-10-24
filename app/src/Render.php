<?php
declare(strict_types=1);

namespace InaPostaje;

/** Renders the public station list into static HTML (works without JavaScript; JS only filters and sorts). */
final class Render
{
    public const NEW_PRICE_DAYS = 7;
    /** Extra fuel-filter option: stations where AdBlue comes from a pump nozzle (admin flag). */
    public const ADBLUE_PUMP = 'adblue-pump';
    public const ADBLUE_PUMP_LABEL = 'AdBlue – istakanje iz pištolja';
    public const ADBLUE_PUMP_NOTE = 'moguće istakanje iz pištolja';

    /** Services shown on a card before "+ još N". */
    private const SERVICES_SHOWN = 6;

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Asset URLs carry a content hash so nginx can cache them for a year (immutable). */
    public static function assetUrl(string $webRoot, string $path): string
    {
        $file = $webRoot . $path;
        return $path . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 10) : '');
    }

    /** Goes right after <meta name="color-scheme">: theme-init.js must run before the first paint. */
    public static function headAssets(string $webRoot): string
    {
        $u = fn(string $p) => self::e(self::assetUrl($webRoot, $p));
        return '  <script src="' . $u('/assets/js/theme-init.js') . '"></script>' . "\n"
            . '  <link rel="stylesheet" href="' . $u('/assets/css/site.css') . '">' . "\n"
            . '  <script src="' . $u('/assets/js/hours.js') . '" defer></script>' . "\n"
            . '  <script src="' . $u('/assets/js/site.js') . '" defer></script>';
    }

    public static function page(string $template, string $webRoot): string
    {
        return strtr($template, ['{{HEAD_ASSETS}}' => self::headAssets($webRoot)]);
    }

    public static function index(string $template, array $rows, string $generatedAt): string
    {
        $collator = class_exists(\Collator::class) ? new \Collator('hr_HR') : null;
        $cmp = fn($a, $b) => $collator ? $collator->compare($a, $b) : strcmp($a, $b);

        $counties = [];
        $fuelCats = [];
        $services = [];
        foreach ($rows as $s) {
            if ($s['county'] !== '') $counties[$s['county']] = ($counties[$s['county']] ?? 0) + 1;
            foreach (self::cardFuels($s)[1] as $cat) $fuelCats[$cat] = ($fuelCats[$cat] ?? 0) + 1;
            foreach ($s['services'] as $sv) {
                $key = mb_strtolower($sv);
                $services[$key] ??= ['label' => $sv, 'n' => 0];
                $services[$key]['n']++;
            }
        }
        uksort($counties, $cmp);
        uasort($services, fn($a, $b) => $b['n'] <=> $a['n'] ?: $cmp($a['label'], $b['label']));

        $options = fn(array $items) => implode('', array_map(fn($v, $label) => '<option value="' . self::e((string) $v) . '">' . self::e($label) . "</option>\n", array_keys($items), $items));
        $countyOptions = $options(array_combine(array_keys($counties), array_map(fn($c, $n) => "$c ($n)", array_keys($counties), $counties)) ?: []);
        $fuelOptions = '';
        foreach (Fuels::CATEGORIES as $cat => $label) {
            if (isset($fuelCats[$cat])) $fuelOptions .= '<option value="' . $cat . '">' . self::e("$label ({$fuelCats[$cat]})") . "</option>\n";
            if ($cat === 'adblue' && isset($fuelCats[self::ADBLUE_PUMP])) {
                $fuelOptions .= '<option value="' . self::ADBLUE_PUMP . '">' . self::e(self::ADBLUE_PUMP_LABEL . ' (' . $fuelCats[self::ADBLUE_PUMP] . ')') . "</option>\n";
            }
        }
        $serviceOptions = $options(array_map(fn($x) => "{$x['label']} ({$x['n']})", $services));

        $today = date('Y-m-d', strtotime($generatedAt));
        $closedNow = count(array_filter($rows, fn($s) => Stations::closureState($s, $today) === 'closed'));
        // The closed count is re-counted in the browser (site.js), so it stays right between publishes.
        $summary = self::e(count($rows) . ' postaja · ' . count($counties) . ' županija')
            . '<span id="closed-summary"' . ($closedNow ? '' : ' hidden') . '> · privremeno zatvoreno: <span id="closed-count">' . $closedNow . '</span></span>';
        $hasCoords = (bool) array_filter($rows, fn($s) => $s['lat'] !== '' && $s['lng'] !== '');

        return strtr($template, [
            '{{SUMMARY}}' => $summary,
            '{{PRICE_OVERVIEW}}' => self::priceOverview($rows, date('Y-m-d H:i:s', strtotime($generatedAt))),
            '{{CARDS}}' => implode("\n", array_map(fn($s) => self::card($s, $today), $rows)),
            '{{TOTAL}}' => (string) count($rows),
            '{{COUNTY_OPTIONS}}' => $countyOptions,
            '{{FUEL_OPTIONS}}' => $fuelOptions,
            '{{SERVICE_OPTIONS}}' => $serviceOptions,
            '{{SERVICE_FILTER_HIDDEN}}' => $services ? '' : ' hidden',
            '{{NEAREST_OPTION}}' => $hasCoords ? '<option value="blizina">Najbliže meni</option>' : '',
            '{{GENERATED_AT}}' => self::e($generatedAt),
            '{{GENERATED_HUMAN}}' => self::e(date('j. n. Y. H:i', strtotime($generatedAt))),
        ]);
    }

    // ---- prices --------------------------------------------------------

    /** "1.43" → "1,43 €" */
    public static function euro(string $price): string
    {
        return number_format((float) $price, 2, ',', '.') . ' €';
    }

    /** "−0,04 €" / "+0,04 €" */
    private static function signed(float $d): string
    {
        return ($d < 0 ? '−' : '+') . number_format(abs($d), 2, ',', '.') . ' €';
    }

    /** Most common value (ties: the lower price). */
    private static function mode(array $values): string
    {
        $counts = array_count_values($values);
        uksort($counts, fn($a, $b) => $counts[$b] <=> $counts[$a] ?: (float) $a <=> (float) $b);
        return (string) array_key_first($counts);
    }

    private static function isNew(string $changedAt, string $now): bool
    {
        return $changedAt !== '' && strtotime($now) - strtotime($changedAt) <= self::NEW_PRICE_DAYS * 86400;
    }

    /** A motorway price needs at least this many stations, so a single odd price (a marina, an error) is not one. */
    public const MOTORWAY_MIN_STATIONS = 2;

    /**
     * The two price levels of one fuel. Stations off the motorways all charge the same price, so the most
     * common price is the regular one ('base'); motorway / transit stations charge more, so the most common
     * price above it is the motorway one ('motorway', null when there is none). One-off prices are ignored.
     * Each level: price, how many stations charge it, and its last change (changed_at, prev) if known.
     * @param array<array{price: string, prev?: string, changed_at?: string}> $list one entry per station
     * @return array{base: array, motorway: ?array}
     */
    public static function priceTiers(array $list): array
    {
        $level = function (string $price) use ($list): array {
            $at = array_values(array_filter($list, fn($f) => $f['price'] === $price));
            $changed = array_values(array_filter($at, fn($f) => ($f['prev'] ?? '') !== '' && ($f['changed_at'] ?? '') !== ''));
            $when = $changed ? max(array_column($changed, 'changed_at')) : '';
            $prev = $changed ? self::mode(array_column(array_filter($changed, fn($f) => $f['changed_at'] === $when), 'prev')) : '';
            return ['price' => $price, 'count' => count($at), 'changed_at' => $when, 'prev' => $prev];
        };
        $base = self::mode(array_column($list, 'price'));
        $higher = array_values(array_filter(array_column($list, 'price'), fn($p) => (float) $p > (float) $base));
        $motorway = $higher ? self::mode($higher) : null;
        if ($motorway !== null && count(array_keys($higher, $motorway, true)) < self::MOTORWAY_MIN_STATIONS) $motorway = null;
        return ['base' => $level($base), 'motorway' => $motorway === null ? null : $level($motorway)];
    }

    /** "od 21. 10. 2026." / "Nova cijena od …, prije … (pojeftinjenje −0,04 €)" for a week after the change. */
    private static function since(array $level, string $now): string
    {
        if ($level['changed_at'] === '') return '';
        $date = self::e(self::date(substr($level['changed_at'], 0, 10)));
        if (!self::isNew($level['changed_at'], $now)) return 'od ' . $date;
        $d = (float) $level['price'] - (float) $level['prev'];
        return '<span class="price-flag">Nova cijena</span> od ' . $date . ', prije ' . self::e(self::euro($level['prev']))
            . ' (' . ($d < 0 ? 'pojeftinjenje ' : 'poskupljenje ') . self::e(self::signed($d)) . ')';
    }

    /**
     * One tile per fuel: the regular price (izvan autocesta) and the motorway price (autoceste), each with the
     * date it took effect; "Nova cijena" with the previous price for a week after a change. Only prices
     * published on ina.hr; AdBlue is never priced.
     */
    public static function priceOverview(array $rows, ?string $now = null): string
    {
        $now ??= date('Y-m-d H:i:s');
        $byCat = [];
        $latest = '';
        foreach ($rows as $s) {
            $seen = [];
            foreach ($s['fuels'] as $f) {
                if (($f['price'] ?? '') === '' || !Fuels::priced($f['cat'])) continue;
                // One price per station and type (the cheapest, if a station lists two of a kind).
                if (isset($seen[$f['cat']]) && (float) $seen[$f['cat']]['price'] <= (float) $f['price']) continue;
                $seen[$f['cat']] = $f;
            }
            foreach ($seen as $cat => $f) $byCat[$cat][] = $f;
            if ($seen && ($s['ina_synced_at'] ?? '') > $latest) $latest = $s['ina_synced_at'];
        }
        if (!$byCat) return '';

        $unit = ' <span class="price-unit">/ l</span>';
        $tiles = '';
        foreach (Fuels::CATEGORIES as $cat => $label) {
            if (empty($byCat[$cat])) continue;
            ['base' => $base, 'motorway' => $motorway] = self::priceTiers($byCat[$cat]);
            $baseSince = self::since($base, $now);
            $tiles .= '<article class="price-tile price-' . $cat . '">'
                . '<h3 class="price-name">' . self::e($label) . '</h3>'
                . '<p class="price-value"><span class="nw">' . self::e(self::euro($base['price'])) . $unit . '</span></p>'
                . '<p class="price-meta">' . ($motorway ? 'izvan autocesta' : 'sve postaje') . ($baseSince !== '' ? ' · ' . $baseSince : '') . '</p>';
            if ($motorway) {
                $since = self::since($motorway, $now);
                $tiles .= '<p class="price-motorway">Autoceste: <b class="nw">' . self::e(self::euro($motorway['price'])) . $unit . '</b>'
                    . ($since !== '' ? ' <span class="price-meta">· ' . $since . '</span>' : '') . '</p>';
            }
            $tiles .= '</article>';
        }
        return '<section class="prices" aria-labelledby="prices-title">'
            . '<h2 id="prices-title" class="section-title">Cijene goriva</h2>'
            . '<div class="price-grid">' . $tiles . '</div>'
            . ($latest !== '' ? '<p class="price-meta">Preuzeto s ina.hr ' . self::e(self::date(substr($latest, 0, 10)) . ' u ' . substr($latest, 11, 5))
                . ' · cijena pojedine postaje je na njezinoj kartici</p>' : '')
            . '</section>';
    }

    /** 15. 11. 2026. */
    public static function date(string $ymd): string
    {
        return $ymd === '' ? '' : date('j. n. Y.', strtotime($ymd));
    }

    /**
     * Google Maps link: the coordinates when known, otherwise a search for
     * "INA <name>, <address>, <postal> <city>, Hrvatska". A plain link: no map script on the page.
     */
    public static function mapUrl(array $s): string
    {
        if (($s['lat'] ?? '') !== '' && ($s['lng'] ?? '') !== '') {
            return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($s['lat'] . ',' . $s['lng']);
        }
        $place = trim(($s['postal_code'] ?? '') . ' ' . ($s['city'] ?? ''));
        $query = implode(', ', array_filter(['INA ' . $s['name'], $s['address'] ?? '', $place, 'Hrvatska'], fn($p) => trim($p) !== ''));
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    // ---- card ----------------------------------------------------------

    /**
     * The fuels a card lists, and its filter tokens (data-fuels). AdBlue is listed without a price
     * when ina.hr has it as a fuel or under Usluge/Ostalo, when the admin ticked "Ima AdBlue", or
     * "moguće istakanje iz pištolja" (seen only in the INA Loyalty app); that one also adds 'adblue-pump'.
     * @return array{0: array, 1: string[]}
     */
    public static function cardFuels(array $s): array
    {
        $fuels = [];
        foreach ($s['fuels'] ?? [] as $f) {
            if (!is_array($f) || ($f['name'] ?? '') === '') continue;
            $f += ['cat' => Fuels::category($f['name']), 'price' => '', 'prev' => '', 'changed_at' => ''];
            if (!Fuels::priced($f['cat'])) $f = ['price' => '', 'prev' => '', 'changed_at' => ''] + $f;
            $fuels[] = $f;
        }
        $pump = !empty($s['adblue_pump']);
        $listed = in_array('adblue', array_column($fuels, 'cat'), true);
        $asService = (bool) preg_grep('/ad\s*blue/iu', $s['services'] ?? []);
        if (!$listed && ($pump || $asService || !empty($s['adblue_have']))) {
            $fuels[] = ['name' => 'AdBlue', 'cat' => 'adblue', 'price' => '', 'prev' => '', 'changed_at' => ''];
            Fuels::sort($fuels);
        }
        $tokens = array_values(array_unique(array_column($fuels, 'cat')));
        if ($pump) $tokens[] = self::ADBLUE_PUMP;
        return [$fuels, $tokens];
    }

    public static function card(array $s, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        $status = $s['status'] ?? 'open';
        $state = Stations::closureState($s, $today);
        [$fuels, $tokens] = self::cardFuels($s);
        $pump = !empty($s['adblue_pump']);
        $cheapest = [];
        foreach ($fuels as $f) {
            if (($f['price'] ?? '') !== '' && (!isset($cheapest[$f['cat']]) || (float) $f['price'] < $cheapest[$f['cat']])) $cheapest[$f['cat']] = (float) $f['price'];
        }
        $search = implode(' ', [$s['name'], $s['city'], $s['postal_code'], $s['address'], $s['phone'], $s['aliases'], $s['county'],
            implode(' ', array_column($fuels, 'name')), implode(' ', array_map(fn($c) => Fuels::label($c), array_unique(array_column($fuels, 'cat')))),
            implode(' ', $s['services']), $pump ? self::ADBLUE_PUMP_NOTE : '', $status === 'closed' ? 'privremeno zatvoreno ' . ($s['status_note'] ?? '') : '']);
        $place = trim($s['address'] . ', ' . trim($s['postal_code'] . ' ' . $s['city']), ', ');

        $h = '<article class="card' . ($state === 'closed' ? ' card-closed' : '') . '" data-county="' . self::e($s['county']) . '" data-search="' . self::e($search) . '"'
            . ' data-fuels="' . self::e(implode(' ', $tokens)) . '"';
        if ($cheapest) $h .= ' data-prices="' . self::e(json_encode($cheapest)) . '"';
        if ($s['services']) $h .= ' data-services="' . self::e(implode('|', array_map('mb_strtolower', $s['services']))) . '"';
        if ($s['lat'] !== '' && $s['lng'] !== '') $h .= ' data-lat="' . self::e($s['lat']) . '" data-lng="' . self::e($s['lng']) . '"';
        if ($status === 'closed') {
            // The browser re-evaluates these dates on load (site.js), so closures start/end on time.
            $h .= ' data-status="closed" data-from="' . self::e($s['closed_from']) . '" data-until="' . self::e($s['closed_until']) . '"';
        }
        if ($s['working_hours']) $h .= ' data-hours="' . self::e(json_encode($s['working_hours'], JSON_UNESCAPED_UNICODE)) . '"';
        $h .= '>';

        $h .= '<p class="card-county">' . self::e($s['county'] ?: 'Hrvatska') . '</p>';
        if ($status === 'closed') {
            $until = $s['closed_until'] !== '' ? ' do ' . self::date($s['closed_until']) : '';
            $h .= '<p class="card-badge badge-closed" data-badge="closed"' . ($state === 'closed' ? '' : ' hidden') . '>Privremeno zatvoreno' . self::e($until) . '</p>';
            $h .= '<p class="card-badge badge-upcoming" data-badge="upcoming"' . ($state === 'upcoming' ? '' : ' hidden') . '>Najavljeno zatvaranje od '
                . self::e(self::date($s['closed_from']) . $until) . '</p>';
            if ($s['status_note'] !== '') {
                $h .= '<p class="card-status-note" data-badge="note"' . ($state === 'open' ? ' hidden' : '') . '>' . self::e($s['status_note']) . '</p>';
            }
        }
        $h .= '<h2 class="card-title">' . self::e($s['name']) . '</h2>';
        $h .= '<p class="now" data-now hidden></p>';
        $h .= '<p class="card-address">' . self::e($place) . ' <span class="distance" data-distance hidden></span></p>';
        if (($s['note'] ?? '') !== '') $h .= '<p class="card-note">' . self::e($s['note']) . '</p>';
        if ($s['aliases'] !== '') $h .= '<p class="card-aliases"><span>Zovu je: </span>' . self::e($s['aliases']) . '</p>';

        if ($fuels) {
            $h .= '<div class="card-fuels"><p class="block-label">Goriva</p><ul class="fuels">';
            foreach ($fuels as $f) {
                $isNew = ($f['prev'] ?? '') !== '' && self::isNew($f['changed_at'] ?? '', $today . ' 23:59:59');
                $h .= '<li class="fuel fuel-' . self::e($f['cat']) . '" data-cat="' . self::e($f['cat']) . '"><span class="fuel-name">' . self::e($f['name']) . '</span>'
                    . (($f['price'] ?? '') !== '' ? ' <b class="fuel-price">' . self::e(self::euro($f['price'])) . '</b>' : '')
                    . ($isNew ? ' <span class="fuel-new">nova cijena, prije ' . self::e(self::euro($f['prev'])) . '</span>' : '')
                    . ($f['cat'] === 'adblue' && $pump ? ' <span class="fuel-note">' . self::ADBLUE_PUMP_NOTE . '</span>' : '')
                    . '</li>';
            }
            $h .= '</ul>';
            if ($cheapest && ($s['ina_synced_at'] ?? '') !== '') $h .= '<p class="fuel-date">Cijene s ina.hr, ' . self::e(self::date(substr($s['ina_synced_at'], 0, 10))) . '</p>';
            $h .= '</div>';
        }

        if ($s['services']) {
            $chips = array_map(fn($sv) => '<li>' . self::e($sv) . '</li>', $s['services']);
            $h .= '<div class="card-services"><p class="block-label">Usluge</p><ul class="services">' . implode('', array_slice($chips, 0, self::SERVICES_SHOWN)) . '</ul>';
            if (count($chips) > self::SERVICES_SHOWN) {
                $h .= '<details class="more"><summary>+ još ' . (count($chips) - self::SERVICES_SHOWN) . '</summary><ul class="services">'
                    . implode('', array_slice($chips, self::SERVICES_SHOWN)) . '</ul></details>';
            }
            $h .= '</div>';
        }

        // Rows that only say "PRIVREMENO ZATVORENO" (how ina.hr marks a closed station) are not
        // repeated as hours; the closure badge already says it.
        $hoursRows = array_values(array_filter($s['working_hours'], fn($w) => !InaSource::rowClosed($w)));
        if ($hoursRows) {
            $multi = count($hoursRows) > 1;
            $h .= '<div class="card-hours"><p class="block-label">Radno vrijeme</p>';
            foreach ($hoursRows as $w) {
                $h .= '<div>';
                if ($multi) $h .= '<p class="hours-label">' . self::e($w['label']) . '</p>';
                $h .= '<p>Pon–Pet: <b>' . self::e($w['week'] ?: '—') . '</b> · Sub: <b>' . self::e($w['sat'] ?: '—') . '</b> · Ned: <b>' . self::e($w['sun'] ?: '—') . '</b></p>';
                if (($w['hol'] ?? '') !== '') $h .= '<p class="hours-hol">Blagdani: ' . self::e($w['hol']) . '</p>';
                $h .= '</div>';
            }
            $h .= '</div>';
        }

        $h .= '<div class="card-actions">';
        // Several numbers in one field ("091 497 1263 ; 091 497 1474") get a call button each.
        foreach (InaSource::phoneNumbers($s['phone']) as $number) {
            $h .= '<a class="btn-phone" href="tel:' . self::e(preg_replace('/[^\d+]/', '', $number)) . '">☎ ' . self::e($number) . '</a>';
        }
        if ($s['ina_url'] !== '') {
            $h .= '<a class="btn-link" href="' . self::e($s['ina_url']) . '" target="_blank" rel="noopener noreferrer"'
                . ' aria-label="' . self::e($s['name']) . ' na ina.hr (nova kartica)">INA.hr ↗</a>';
        }
        $h .= '<a class="btn-link" href="' . self::e(self::mapUrl($s)) . '" target="_blank" rel="noopener noreferrer"'
            . ' aria-label="Karta: ' . self::e($s['name']) . ' (Google Karte, nova kartica)">Karta ↗</a>';
        $h .= '</div></article>';
        return $h;
    }
}

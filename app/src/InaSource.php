<?php
declare(strict_types=1);

namespace InaPostaje;

/**
 * Reads the public ina.hr pages: the station finder (list of all stations) and each
 * station's own page (working hours, fuels with prices, services, closure wording).
 * Pure parsing, no database: InaSync decides what to store.
 *
 * Nothing here can be fully trusted to stay the same – ina.hr may change its markup at
 * any time – so every parser is defensive and returns "nothing found" rather than guessing.
 * `php bin/ina-probe.php` shows what each parser reads from the live pages.
 */
final class InaSource
{
    public const DIRECTORY_URL = 'https://www.ina.hr/trazilica-benzinskih-postaja/';

    /** @var callable(string): string  (tests replace it) */
    public static $fetch = [self::class, 'httpGet'];

    // ---- addresses and identities ---------------------------------------

    public static function safeUrl(string $value): ?string
    {
        $u = parse_url(trim($value));
        if (!$u || ($u['scheme'] ?? '') !== 'https' || ($u['host'] ?? '') !== 'www.ina.hr' || isset($u['port'])
            || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])
            || !preg_match('#^/station/[a-z0-9-]+/?$#', $u['path'] ?? '')) {
            return null;
        }
        return 'https://www.ina.hr' . rtrim($u['path'], '/') . '/';
    }

    public static function phoneKey(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        return preg_replace(['/^00385/', '/^385/'], ['0', '0'], $digits, 1);
    }

    /** A phone field may hold several numbers ("091 497 1263 ; 091 497 1474"). Not split on "/": "01/234-567". */
    public static function phoneNumbers(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s*[;,]\s*|\s+ili\s+/iu', $value)), 'strlen'));
    }

    public static function phoneKeys(string $value): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'phoneKey'], self::phoneNumbers($value)), fn($k) => strlen($k) >= 6)));
    }

    /** ina.hr builds station addresses from the name: "Zadar I-istok" → zadar-i-istok, "Čađavica" → cadavica. */
    public static function slug(string $name): string
    {
        $s = strtr(mb_strtolower($name, 'UTF-8'), ['dž' => 'dz', 'č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z']);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    /** "zadar-i-istok-2" → "Zadar I Istok" (only used when ina.hr gives no name at all). */
    public static function nameFromUrl(string $url): string
    {
        $slug = preg_replace('/-\d+$/', '', basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/')));
        return mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE, 'UTF-8');
    }

    /** Coordinates only count inside a box around Croatia. */
    public static function coords(mixed $lat, mixed $lng): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float) $lat; $lng = (float) $lng;
        if ($lat < 42.2 || $lat > 46.6 || $lng < 13.3 || $lng > 19.5) return null;
        return [number_format($lat, 6, '.', ''), number_format($lng, 6, '.', '')];
    }

    // ---- the station finder ----------------------------------------------

    /**
     * The finder page carries every station as JSON in `<div id="mdata" stations='[…]'>`.
     * Only `url` and `contact_phone` are known for certain; the other keys are looked up
     * under several likely names, and nested objects (e.g. location.lat) are flattened.
     * @return array<array{url: string, phones: string[], phone: string, name: string, address: string, city: string,
     *   postal_code: string, county: string, lat: string, lng: string}>
     */
    public static function parseDirectory(string $html): array
    {
        $entries = self::directoryRaw($html);
        $out = [];
        $seen = [];
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $f = self::flatten($e);
            $url = self::safeUrl((string) self::pick($f, ['url', 'link', 'permalink', 'href']));
            if (!$url || isset($seen[$url])) continue;
            $seen[$url] = true;
            $phone = self::pick($f, ['contact_phone', 'phone', 'telephone', 'tel', 'telefon']);
            $coords = self::coords(self::pick($f, ['lat', 'latitude', 'geo_lat', 'location.lat', 'position.lat', 'coordinates.lat']),
                self::pick($f, ['lng', 'lon', 'long', 'longitude', 'geo_lng', 'location.lng', 'location.lon', 'position.lng', 'coordinates.lng']));
            $out[] = [
                'url' => $url,
                'phones' => self::phoneKeys($phone),
                'phone' => $phone,
                'name' => self::pick($f, ['title', 'name', 'station_name', 'naziv', 'post_title']),
                'address' => self::pick($f, ['address', 'street', 'adresa', 'location.address']),
                'city' => self::pick($f, ['city', 'place', 'town', 'grad', 'mjesto', 'location.city']),
                'postal_code' => preg_replace('/\D/', '', self::pick($f, ['zip', 'postal_code', 'postcode', 'post_code', 'postal', 'postanski_broj', 'location.zip'])),
                'county' => Stations::county(self::pick($f, ['county', 'zupanija', 'region', 'location.county'])),
                'lat' => $coords[0] ?? '',
                'lng' => $coords[1] ?? '',
            ];
        }
        return $out;
    }

    /** The finder's JSON as it is (bin/ina-probe.php shows its keys). */
    public static function directoryRaw(string $html): array
    {
        $raw = self::xpath($html)->evaluate('string(//*[@id="mdata"]/@stations)');
        if ($raw === '') throw new \RuntimeException('INA directory missing (div#mdata[stations])');
        $entries = json_decode($raw, true);
        if (!is_array($entries)) throw new \RuntimeException('Invalid INA directory');
        return $entries;
    }

    /** ['location' => ['lat' => 1]] → ['location.lat' => 1, 'lat' => 1] (the short key only if not already there). */
    private static function flatten(array $e, string $prefix = ''): array
    {
        $out = [];
        foreach ($e as $k => $v) {
            $key = $prefix . strtolower((string) $k);
            if (is_array($v)) {
                foreach (self::flatten($v, $key . '.') as $k2 => $v2) $out[$k2] = $v2;
            } else {
                $out[$key] = $v;
            }
        }
        if ($prefix === '') {
            foreach ($out as $k => $v) {
                $short = substr((string) strrchr($k, '.'), 1);
                if ($short !== '' && !array_key_exists($short, $out)) $out[$short] = $v;
            }
        }
        return $out;
    }

    private static function pick(array $f, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($f[$k]) && is_scalar($f[$k]) && trim((string) $f[$k]) !== '') {
                return self::text(html_entity_decode(strip_tags((string) $f[$k]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return '';
    }

    // ---- a station page ------------------------------------------------

    /**
     * Everything a station page tells us. 'hours' is null when the page has no readable hours table.
     * @return array{hours: ?array, fuels: array, services: string[], details: array, notice: string}
     */
    public static function parseStation(string $html): array
    {
        try { $hours = self::parseHours($html); } catch (\Throwable) { $hours = null; }
        return ['hours' => $hours, 'fuels' => self::parseFuels($html), 'services' => self::parseServices($html),
            'details' => self::parseDetails($html), 'notice' => self::closureNotice($html)];
    }

    /** @return array<array{label: string, week: string, sat: string, sun: string, hol: string}> */
    public static function parseHours(string $html): array
    {
        $xp = self::xpath($html);
        $table = null;
        foreach ($xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " station__info__tablebox__table ")]') as $t) {
            $firstRow = $xp->query('.//tr', $t)->item(0);
            if ($firstRow && str_contains(self::text($firstRow->textContent), 'Radno vrijeme')) { $table = $t; break; }
        }
        if (!$table) throw new \RuntimeException('INA working hours table missing');
        $hours = [];
        foreach ($xp->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($xp->query('./td', $tr) as $td) {
                foreach (iterator_to_array($xp->query('.//br', $td)) as $br) $br->parentNode->replaceChild($td->ownerDocument->createTextNode(' '), $br);
                $cells[] = self::text($td->textContent);
            }
            if (!$cells) continue;
            if (count($cells) !== 5 || in_array('', array_slice($cells, 0, 4), true)) throw new \RuntimeException('Incomplete INA working hours');
            $hours[] = ['label' => $cells[0], 'week' => $cells[1], 'sat' => $cells[2], 'sun' => $cells[3], 'hol' => $cells[4]];
        }
        if (!$hours) throw new \RuntimeException('Empty INA working hours');
        return $hours;
    }

    /** A fuel line: "Eurodiesel plavi - 1,43 €", "Autoplin: 0,89 EUR", "Eurosuper 95 Class Plus 1,73 €/l". */
    private const PRICE_LINE = '/^(.{2,70}?)\s*(?:[-–—:]\s*)?(\d{1,2}[.,]\d{2,3})\s*(?:€|EUR\b)/iu';
    private const FUEL_WORDS = '/super|diesel|dizel|plav|autoplin|\bunp\b|\blpg\b|ad\s*blue|\bhvo\b|benzin|\bbmb\b/iu';

    /**
     * The "Goriva u ponudi" list. Inside that list every item counts (a fuel may be listed
     * without a price); without the heading, only lines that look like "<fuel> - <price> €".
     * @return array<array{name: string, cat: string, price: string}>
     */
    public static function parseFuels(string $html): array
    {
        $xp = self::xpath($html);
        $items = [];
        $scoped = false;
        foreach (self::headings($xp, '/^goriva\s+u\s+ponudi\s*:?$/iu') as $heading) {
            $list = $xp->query('(following::*[self::ul or self::ol or self::table])[1]', $heading)->item(0);
            if (!$list) continue;
            foreach ($xp->query('.//li | .//tr', $list) as $node) $items[] = self::text($node->textContent);
            $scoped = true;
            break;
        }
        if (!$scoped) {
            foreach ($xp->query('//li | //tr | //p') as $node) $items[] = self::text($node->textContent);
        }
        $out = [];
        foreach ($items as $text) {
            if ($text === '' || mb_strlen($text) > 120) continue; // a paragraph of text, not a fuel line
            if (preg_match(self::PRICE_LINE, $text, $m)) {
                $name = trim($m[1], " \t-–—:");
                $price = Fuels::price($m[2]) ?? '';
            } elseif ($scoped && mb_strlen($text) <= 70) {
                $name = trim($text, " \t-–—:");
                $price = '';
            } else {
                continue;
            }
            if ($name === '' || (!$scoped && !preg_match(self::FUEL_WORDS, $name))) continue;
            if ($price === '' && !$scoped) continue;
            $key = mb_strtolower($name);
            if (isset($out[$key])) continue;
            $out[$key] = ['name' => mb_substr($name, 0, 120), 'cat' => Fuels::category($name), 'price' => $price];
        }
        $out = array_values($out);
        Fuels::sort($out);
        return $out;
    }

    /**
     * Services/amenities of the station ("Usluge", "Sadržaji", "Ponuda na postaji" …): the items
     * of the list that follows such a heading. Icons without text count by their alt/title.
     * @return string[]
     */
    public static function parseServices(string $html): array
    {
        $xp = self::xpath($html);
        $out = [];
        // "Ostalo" is where ina.hr lists Maziva, Roba široke potrošnje and "Ad Blue" (e.g. Zagreb-Zagrebačka cesta-istok).
        foreach (self::headings($xp, '/^(usluge|sadržaj[i]?|ponuda|ostalo|dodatna\s+ponuda|ostala\s+ponuda|dodatne\s+usluge)(\s+(na|postaje|na\s+postaji))?\s*:?$/iu') as $heading) {
            $list = $xp->query('(following::*[self::ul or self::ol])[1]', $heading)->item(0);
            if (!$list) continue;
            foreach ($xp->query('./li', $list) as $li) {
                $text = self::text($li->textContent);
                if ($text === '') {
                    $icon = $xp->query('.//*[@alt or @title]', $li)->item(0);
                    if ($icon instanceof \DOMElement) $text = self::text($icon->getAttribute('title') ?: $icon->getAttribute('alt'));
                }
                if ($text === '' || mb_strlen($text) > 80 || preg_match(self::PRICE_LINE, $text)) continue;
                $out[mb_strtolower($text)] ??= $text;
            }
        }
        return array_slice(array_values($out), 0, 40);
    }

    /**
     * Station name (the page's <h1>), the contact box ("Mob: 091 4971 366", "Adresa: Zagrebačka cesta bb",
     * "Regija: ZAPAD" – INA's own organisational region, not a county), the first tel: number and coordinates
     * (data-lat/data-lng attributes, a JSON "lat"/"lng" pair or a Google Maps link). Used only to fill gaps
     * the finder left; empty strings when not found.
     */
    public static function parseDetails(string $html): array
    {
        $xp = self::xpath($html);
        $name = self::text((string) $xp->evaluate('string((//h1)[1])'));
        $phone = '';
        $tel = $xp->query('//a[starts-with(@href, "tel:")]')->item(0);
        if ($tel instanceof \DOMElement) $phone = self::text($tel->textContent) ?: substr($tel->getAttribute('href'), 4);
        $labelled = [];
        foreach ($xp->query('//li | //p | //div[not(*[self::div or self::p or self::li])] | //span | //dd | //tr') as $node) {
            $t = self::text($node->textContent);
            if (mb_strlen($t) > 120 || !preg_match('/^(Mob|Tel|Telefon|Adresa|Regija)\s*:\s*(.+)$/iu', $t, $m)) continue;
            $labelled[mb_strtolower($m[1])] ??= trim($m[2]);
        }
        if ($phone === '') $phone = $labelled['mob'] ?? $labelled['tel'] ?? $labelled['telefon'] ?? '';
        $coords = null;
        $patterns = [
            '/data-lat(?:itude)?=["\'](-?\d+\.\d+)["\'][^>]*?data-(?:lng|lon|long|longitude)=["\'](-?\d+\.\d+)/i',
            '/"lat(?:itude)?"\s*:\s*"?(-?\d+\.\d+)"?\s*,\s*"(?:lng|lon|long|longitude)"\s*:\s*"?(-?\d+\.\d+)/i',
            '#google\.[a-z.]+/maps[^"\'\s]*?(?:[?&](?:q|query|destination|ll)=|/@)(-?\d+\.\d+)(?:,|%2C)\s*(-?\d+\.\d+)#i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m) && ($coords = self::coords($m[1], $m[2]))) break;
        }
        return ['name' => mb_substr($name, 0, 190), 'phone' => mb_substr($phone, 0, 64), 'lat' => $coords[0] ?? '', 'lng' => $coords[1] ?? '',
            'address' => mb_substr($labelled['adresa'] ?? '', 0, 255), 'ina_region' => mb_substr($labelled['regija'] ?? '', 0, 60)];
    }

    /**
     * Sentence on an INA station page that says the station is temporarily closed
     * ("privremeno zatvoreno", "zatvoreno zbog radova", "ne radi zbog …"), or ''.
     */
    public static function closureNotice(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\b[^>]*>#i', '$0. ', $html);
        $text = self::text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // A sentence ends at . ! ? – except dots inside dates such as "15.11.2026".
        $chunk = '(?:[^.!?]|\.(?=\d))';
        $pattern = '/' . $chunk . '{0,120}(?<!\pL)(privremeno\s+(zatvoren\pL*|ne\s+radi)|zatvoren\pL*\s+zbog|ne\s+radi\s+zbog)(?!\pL)' . $chunk . '{0,160}(?:\.(?!\d))?/iu';
        if (!preg_match($pattern, $text, $m)) return '';
        return mb_substr(trim($m[0], " .\t"), 0, 300);
    }

    /** True when every row of the ina.hr hours table says "PRIVREMENO ZATVORENO" for Mon–Fri, Sat and Sun. */
    public static function hoursClosed(array $hours): bool
    {
        if (!$hours) return false;
        foreach ($hours as $h) if (!self::rowClosed($h)) return false;
        return true;
    }

    public static function rowClosed(array $h): bool
    {
        foreach (['week', 'sat', 'sun'] as $k) {
            if (!preg_match('/privremeno\s+zatvoren/iu', (string) ($h[$k] ?? ''))) return false;
        }
        return true;
    }

    // ---- helpers -------------------------------------------------------

    /** Short elements whose whole text matches $pattern (headings, but also <strong>/<p>/<span> used as one). */
    private static function headings(\DOMXPath $xp, string $pattern): array
    {
        $out = [];
        $outside = '[not(ancestor::nav or ancestor::header or ancestor::footer or ancestor::*[@role="navigation"])]';
        $tags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'p', 'span', 'dt', 'div[not(*)]'];
        foreach ($xp->query(implode(' | ', array_map(fn($t) => "//$t$outside", $tags))) as $n) {
            $t = self::text($n->textContent);
            if ($t !== '' && mb_strlen($t) <= 40 && preg_match($pattern, $t)) $out[] = $n;
        }
        return $out;
    }

    public static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: hr'], CURLOPT_USERAGENT => (string) Config::get('ina_user_agent'),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status === 0) throw new \RuntimeException('ina.hr nije dostupan s poslužitelja (veza nije uspjela)');
        if ($status !== 200) throw new \RuntimeException("ina.hr je vratio HTTP $status");
        return (string) $body;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        return new \DOMXPath($doc);
    }

    public static function text(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}

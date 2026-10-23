<?php
declare(strict_types=1);

namespace InaPostaje;

/**
 * Fuel names as INA writes them ("Eurosuper 95 Class Plus", "Eurodiesel plavi", "Autoplin") →
 * a small set of categories the public site can filter, compare and sort by.
 * Mirrored by nothing in JS: the category is computed here and published with each fuel.
 */
final class Fuels
{
    /** Display order on the site. */
    public const CATEGORIES = [
        'es95'   => 'Benzin 95',
        'es100'  => 'Benzin 100',
        'ed'     => 'Dizel',
        'edp'    => 'Premium dizel',
        'plavi'  => 'Plavi dizel',
        'lpg'    => 'Autoplin',
        'hvo'    => 'HVO',
        'adblue' => 'AdBlue',
        'other'  => 'Ostalo',
    ];

    public static function category(string $name): string
    {
        $n = mb_strtolower($name, 'UTF-8');
        if (preg_match('/plav/u', $n)) return 'plavi';
        if (preg_match('/ad\s*blue/u', $n)) return 'adblue';
        if (preg_match('/autoplin|\bunp\b|\blpg\b/u', $n)) return 'lpg';
        if (preg_match('/\bhvo\b/u', $n)) return 'hvo';
        if (preg_match('/diesel|dizel/u', $n)) return preg_match('/premium|\b100\b|v-?power|ultimate/u', $n) ? 'edp' : 'ed';
        if (preg_match('/super|benzin|\bbmb\b/u', $n)) return preg_match('/\b(100|98)\b|premium|v-?power/u', $n) ? 'es100' : 'es95';
        return 'other';
    }

    public static function label(string $cat): string
    {
        return self::CATEGORIES[$cat] ?? self::CATEGORIES['other'];
    }

    /** "1,43", "1.430", "1,43 €" → "1.43"; anything that is not a believable €/l price → null. */
    public static function price(string $value): ?string
    {
        if (!preg_match('/(\d{1,2})[.,](\d{2,3})/', $value, $m)) return null;
        $p = (float) ($m[1] . '.' . $m[2]);
        return $p >= 0.3 && $p <= 5 ? number_format($p, 2, '.', '') : null;
    }

    /** Sorts fuels in category order, then by name. */
    public static function sort(array &$fuels): void
    {
        $order = array_flip(array_keys(self::CATEGORIES));
        usort($fuels, fn($a, $b) => ($order[$a['cat']] ?? 99) <=> ($order[$b['cat']] ?? 99) ?: strcmp($a['name'], $b['name']));
    }
}

<?php
declare(strict_types=1);

namespace InaPostaje;

/**
 * INA's fuel products. Each fuel name read from ina.hr ("Eurosuper 95 Class Plus", "Eurodiesel plavi" …)
 * is mapped to one of these, so the public site can filter, compare and sort by product.
 * Anything else a station lists (AdBlue, …) stays on its card under 'other'.
 */
final class Fuels
{
    /** The official product names, in display order. */
    public const CATEGORIES = [
        'es95cp'   => 'Eurosuper 95 Class Plus',
        'es95'     => 'Eurosuper 95',
        'es95cpp'  => 'Eurosuper 95 Class Plus Premium',
        'es100cpp' => 'Eurosuper 100 Class Plus Premium',
        'edcp'     => 'Eurodiesel Class Plus',
        'ed'       => 'Eurodiesel',
        'edcpp'    => 'Eurodiesel Class Plus Premium',
        'lpg'      => 'Autoplin',
        'plavi'    => 'Eurodiesel plavi',
        'edcpe'    => 'Eurodiesel Class Plus Expert',
        'other'    => 'Ostalo',
    ];

    /**
     * Exact product name first (case and spacing ignored); otherwise the closest product by its parts,
     * so small differences in how ina.hr writes a name ("EUROSUPER 95 CLASS PLUS", "Eurosuper 100 Class Plus",
     * "Plavi dizel", "UNP") still land on the right product.
     */
    public static function category(string $name): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', mb_strtolower($name, 'UTF-8')), " \t.,;:-");
        foreach (self::CATEGORIES as $cat => $label) {
            if ($n === mb_strtolower($label, 'UTF-8')) return $cat;
        }
        if (preg_match('/plav/u', $n)) return 'plavi';
        if (preg_match('/autoplin|\bunp\b|\blpg\b/u', $n)) return 'lpg';
        $classPlus = (bool) preg_match('/class\s*plus/u', $n);
        $premium = str_contains($n, 'premium');
        if (preg_match('/diesel|dizel/u', $n)) {
            if (str_contains($n, 'expert')) return 'edcpe';
            return $premium ? 'edcpp' : ($classPlus ? 'edcp' : 'ed');
        }
        if (preg_match('/super|benzin|\bbmb\b/u', $n)) {
            if (preg_match('/\b100\b/u', $n)) return 'es100cpp';
            return $premium ? 'es95cpp' : ($classPlus ? 'es95cp' : 'es95');
        }
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

    /** Sorts fuels in product order, then by name. */
    public static function sort(array &$fuels): void
    {
        $order = array_flip(array_keys(self::CATEGORIES));
        usort($fuels, fn($a, $b) => ($order[$a['cat']] ?? 99) <=> ($order[$b['cat']] ?? 99) ?: strcmp($a['name'], $b['name']));
    }
}

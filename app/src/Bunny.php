<?php
declare(strict_types=1);

namespace InaPostaje;

/**
 * Clears Bunny CDN's cache for the site, so visitors never get an old copy after a change.
 * Optional: does nothing until 'bunny' => ['api_key' => …] is set in app/config.php.
 * A failed purge never blocks a save; it is written to the Dnevnik (audit log).
 */
final class Bunny
{
    /** @var callable(string $url, array $headers): int  HTTP status (tests replace it) */
    public static $http = [self::class, 'post'];

    private static function settings(): array
    {
        return (array) Config::get('bunny') + ['api_key' => '', 'pullzone_id' => '', 'site_url' => ''];
    }

    public static function enabled(): bool
    {
        $c = self::settings();
        return trim((string) $c['api_key']) !== '' && trim((string) $c['site_url']) !== '';
    }

    /**
     * Purge every cached file of the site ("https://domain/*"), or with $wholeZone the entire pull zone.
     * @return string|null error message, or null on success / when not configured
     */
    public static function purge(bool $wholeZone = false): ?string
    {
        if (!self::enabled()) return null;
        $c = self::settings();
        $url = $wholeZone && $c['pullzone_id'] !== ''
            ? 'https://api.bunny.net/pullzone/' . rawurlencode((string) $c['pullzone_id']) . '/purgeCache'
            : 'https://api.bunny.net/purge?url=' . rawurlencode(rtrim((string) $c['site_url'], '/') . '/*') . '&async=false';
        try {
            $status = (self::$http)($url, ['AccessKey: ' . $c['api_key'], 'Accept: application/json', 'Content-Length: 0']);
        } catch (\Throwable $e) {
            return 'Bunny: ' . $e->getMessage();
        }
        return $status >= 200 && $status < 300 ? null : "Bunny je vratio HTTP $status (provjerite api_key / pullzone_id)";
    }

    /** Called after every publish; logs instead of failing. */
    public static function purgeAfterPublish(): void
    {
        $error = self::purge();
        if ($error === null) return;
        error_log('[ina-postaje] ' . $error);
        try { Audit::log('bunny-purge', '', $error); } catch (\Throwable) { /* never break a save */ }
    }

    private static function post(string $url, array $headers): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($status === 0) throw new \RuntimeException('api.bunny.net nije dostupan (' . ($err ?: 'veza nije uspjela') . ')');
        return $status;
    }
}

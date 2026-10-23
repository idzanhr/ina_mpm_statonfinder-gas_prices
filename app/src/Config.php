<?php
declare(strict_types=1);

namespace InaPostaje;

final class Config
{
    private static array $values = [];

    private const DEFAULTS = [
        'admin_path' => '/customer-gas-updater/',
        'totp_issuer' => 'INA postaje CMS',
        'allow_web_enrollment' => true,
        'session_idle_seconds' => 1800,
        'session_absolute_seconds' => 28800,
        'max_failed_per_ip' => 3,
        'max_failed_global' => 20,
        'lockout_minutes' => 15,
        'lockout_redirect' => '',
        'trust_proxy' => false,
        'ina_auto_close' => true,
        'ina_auto_add' => true,
        'ina_user_agent' => 'ina-postaje-cms/1.0',
        'ina_delay_ms' => 300,
        'bunny' => ['api_key' => '', 'pullzone_id' => '', 'site_url' => ''],
    ];

    public static function load(array $values): void
    {
        // The repo root is the web root unless the config says otherwise.
        self::$values = $values + ['web_root' => dirname(__DIR__, 2)] + self::DEFAULTS;
    }

    public static function get(string $key): mixed
    {
        if (!array_key_exists($key, self::$values)) {
            throw new \RuntimeException("Missing config key: $key");
        }
        return self::$values[$key];
    }

    /** Raw 32-byte key used to encrypt the TOTP secret. */
    public static function appKey(): string
    {
        $key = base64_decode((string) self::get('app_key'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('app_key must be 32 random bytes, base64-encoded');
        }
        return $key;
    }
}

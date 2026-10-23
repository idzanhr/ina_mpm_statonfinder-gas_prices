<?php
declare(strict_types=1);

namespace InaPostaje;

/** RFC 6238 TOTP (SHA-1, 30 s, 6 digits) - what Google Authenticator, 1Password, Aegis etc. expect. */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** Accept the previous and next 30 s step to absorb clock drift. */
    public const WINDOW = 1;

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $bin = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Returns the matched time step, or null. Steps at or before $lastUsedStep are
     * rejected so an intercepted code cannot be replayed.
     */
    public static function verify(string $secret, string $code, int $lastUsedStep = 0, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) return null;
        $now = self::step($time);
        $match = null;
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            // Compare every candidate so timing does not reveal which step matched.
            if (hash_equals(self::code($secret, $step), $code) && $step > $lastUsedStep) $match = $step;
        }
        return $match;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos(self::B32, $c);
            if ($v === false) throw new \InvalidArgumentException('Invalid base32');
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }
}

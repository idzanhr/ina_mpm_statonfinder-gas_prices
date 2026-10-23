<?php
declare(strict_types=1);

namespace InaPostaje;

/** Authenticated encryption for the TOTP secret at rest (AES-256-GCM). */
final class Crypto
{
    public static function encrypt(string $plain, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new \RuntimeException('Encryption failed');
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload, string $key): string
    {
        if (!str_starts_with($payload, 'v1:')) throw new \RuntimeException('Unknown ciphertext version');
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 29) throw new \RuntimeException('Corrupt ciphertext');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new \RuntimeException('Cannot decrypt (wrong app_key?)');
        return $plain;
    }
}

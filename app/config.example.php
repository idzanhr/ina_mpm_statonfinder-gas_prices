<?php
// Copy to app/config.php and fill in. app/config.php is git-ignored (and chmod 600 it).
// The repo root is the web root. app/, bin/, sql/, tests/ are never served: the vhost in
// deploy/nginx-vhost.conf only whitelists the site's own files.

return [
    // MySQL / MariaDB (production). For local dev you can use:
    //   'dsn' => 'sqlite:' . __DIR__ . '/../var/ina-postaje.sqlite'
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=ina_postaje;charset=utf8mb4',
        'user' => 'ina_postaje',
        'pass' => 'change-me',
    ],

    // 32 random bytes, base64. Encrypts the TOTP secret at rest.
    // Generate with:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Changing it invalidates the stored 2FA secret (run bin/reset-2fa.php).
    'app_key' => '',

    // Folder nginx serves (the repo root). The CMS regenerates index.html, the legal
    // pages and data/stations.json (+ .gz copies) here on every save and sync.
    'web_root' => dirname(__DIR__),

    // URL path of the hidden admin (must match the urednik/ folder name and the vhost).
    'admin_path' => '/urednik/',

    // Name shown in the authenticator app.
    'totp_issuer' => 'INA postaje CMS',

    // Allow the admin to scan the first TOTP QR code in the browser right after the first
    // password login. Set to false once 2FA is enrolled if re-enrolment should be CLI-only.
    'allow_web_enrollment' => true,

    // Session lifetime.
    'session_idle_seconds'     => 1800,   // 30 min without activity
    'session_absolute_seconds' => 28800,  // 8 h max

    // Brute-force protection (counts password, 2FA and recovery-code failures).
    'max_failed_per_ip'       => 3,       // then the IP is locked out ...
    'max_failed_global'       => 20,      // ... and the whole login, across all IPs
    'lockout_minutes'         => 15,
    'lockout_redirect'        => '',      // optional URL to send a locked-out visitor to

    // ina.hr sync (bin/sync-ina.php):
    // add stations that appear in the ina.hr finder automatically (false = only update known ones)
    'ina_auto_add' => true,
    // hours table says "PRIVREMENO ZATVORENO" every day → mark the station temporarily closed
    // (and reopen it when real hours return). Closures you set by hand are never changed.
    'ina_auto_close' => true,
    // pause between two ina.hr page requests, in milliseconds
    'ina_delay_ms' => 300,
    'ina_user_agent' => 'ina-postaje-cms/1.0',

    // Bunny CDN (optional): with an API key every publish clears Bunny's cache for the site.
    'bunny' => [
        'api_key'     => '',
        'pullzone_id' => '',
        'site_url'    => '',
    ],

    // true only behind a proxy that does NOT put the real visitor IP in REMOTE_ADDR itself.
    'trust_proxy' => false,
];

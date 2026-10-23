<?php
// Removes the 2FA secret and recovery codes (e.g. lost phone without recovery codes).
// The next login will ask to scan a new QR code. With --print the new secret is set
// right here and printed, so web enrolment is never needed.
//   php bin/reset-2fa.php [--print]
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use InaPostaje\{Audit, Auth, Config, Crypto, Db, Totp};

$admin = Auth::admin();
if (!$admin) { fwrite(STDERR, "Administrator ne postoji. Pokrenite bin/install.php.\n"); exit(1); }
if (strtolower(ask("Resetirati 2FA za {$admin['email']}? Upišite DA: ")) !== 'da') exit(1);
Auth::reset2fa();
Audit::log('totp-reset', '', 'CLI');

if (opt('print')) {
    $secret = Totp::generateSecret();
    Db::exec('UPDATE admin SET totp_secret_enc = ? WHERE id = 1', [Crypto::encrypt($secret, Config::appKey())]);
    $codes = Auth::regenerateRecoveryCodes();
    echo "\nTajni ključ (unesite ručno u aplikaciju, TOTP, 6 znamenki, 30 s):\n  " . trim(chunk_split($secret, 4, ' ')) . "\n";
    echo "otpauth URI:\n  " . Totp::uri($secret, $admin['email'], Config::get('totp_issuer')) . "\n";
    echo "Rezervni kodovi:\n  " . implode("\n  ", $codes) . "\n";
} else {
    echo "2FA je uklonjena. Pri sljedećoj prijavi skenirajte novi QR kod.\n";
}

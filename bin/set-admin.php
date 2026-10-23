<?php
// Replaces the e-mail and/or password of THE single admin (there is never a second one).
// Use --temporary to force a password change at next login. Ends all open sessions.
//   php bin/set-admin.php --email=new@x.hr [--temporary]
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use InaPostaje\{Audit, Auth};

$admin = Auth::admin();
$email = opt('email') ?: ($admin ? ($admin['email']) : ask('E-mail administratora: '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Neispravan e-mail.\n"); exit(1); }
Auth::setAdmin($email, askPassword(), mustChange: (bool) opt('temporary'));
Audit::log('password', '', 'CLI: set-admin');
echo "Administrator: $email. Sve sesije su odjavljene. 2FA je ostala nepromijenjena.\n";

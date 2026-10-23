<?php
declare(strict_types=1);

namespace InaPostaje;

/**
 * Single-admin authentication. The editor is reachable only after BOTH the
 * password and a TOTP (or one-time recovery) code were verified in this session.
 *
 * Session stages: (none) -> "password" (password ok, 2FA pending) -> "full".
 */
final class Auth
{
    public const RECOVERY_CODES = 8;

    // ---- session -------------------------------------------------------

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (Config::get('trust_proxy') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name($secure ? '__Host-inacms' : 'inacms');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        $now = time();
        $s = &$_SESSION;
        $expired = isset($s['started'])
            && ($now - ($s['last_seen'] ?? 0) > Config::get('session_idle_seconds')
                || $now - $s['started'] > Config::get('session_absolute_seconds'));
        if ($expired) self::logout(false);
        $s['started'] ??= $now;
        $s['last_seen'] = $now;
    }

    public static function stage(): string
    {
        $admin = self::admin();
        if (!$admin) return 'no-admin';
        $stage = $_SESSION['stage'] ?? 'login';
        // A password change or 2FA reset bumps session_version and ends every other session.
        if ($stage !== 'login' && ($_SESSION['session_version'] ?? 0) !== (int) $admin['session_version']) {
            self::logout(false);
            return 'login';
        }
        if ($stage === 'password') return empty($admin['totp_secret_enc']) ? 'enroll' : 'totp';
        if ($stage === 'full') return (int) $admin['must_change_password'] === 1 ? 'change-password' : 'editor';
        return 'login';
    }

    public static function requireFull(): void
    {
        if (!in_array(self::stage(), ['editor', 'change-password'], true)) {
            throw new HttpError(401, 'Prijava je istekla. Osvježite stranicu.');
        }
    }

    public static function logout(bool $destroy = true): void
    {
        $_SESSION = [];
        if ($destroy && session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($p, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
            session_destroy();
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function promote(string $stage): void
    {
        session_regenerate_id(true);
        $_SESSION['stage'] = $stage;
        $_SESSION['session_version'] = (int) self::admin()['session_version'];
        $_SESSION['started'] = $_SESSION['last_seen'] = time();
    }

    // ---- admin record --------------------------------------------------

    public static function admin(): ?array
    {
        return Db::one('SELECT * FROM admin WHERE id = 1');
    }

    /** CLI only: creates or replaces THE admin (there is never more than one row). */
    public static function setAdmin(string $email, string $password, bool $mustChange = true): void
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $exists = self::admin() !== null;
        if ($exists) {
            Db::exec('UPDATE admin SET email = ?, password_hash = ?, must_change_password = ?, session_version = session_version + 1, updated_at = ? WHERE id = 1',
                [$email, $hash, $mustChange ? 1 : 0, Db::now()]);
        } else {
            Db::exec('INSERT INTO admin (id, email, password_hash, must_change_password, updated_at) VALUES (1, ?, ?, ?, ?)',
                [$email, $hash, $mustChange ? 1 : 0, Db::now()]);
        }
    }

    /** CLI only: removes 2FA so it must be enrolled again on next login. */
    public static function reset2fa(): void
    {
        Db::exec('UPDATE admin SET totp_secret_enc = NULL, totp_last_step = 0, session_version = session_version + 1, updated_at = ? WHERE id = 1', [Db::now()]);
        Db::exec('DELETE FROM recovery_codes');
    }

    // ---- login steps ---------------------------------------------------

    public static function attemptPassword(string $email, string $password): bool
    {
        self::assertNotLocked();
        $admin = self::admin();
        if ($admin === null) return false;
        // Hash check first so a wrong e-mail costs the same time as a wrong password.
        $ok = password_verify($password, $admin['password_hash'])
            && hash_equals(mb_strtolower($admin['email']), mb_strtolower(trim($email)));
        self::recordAttempt('password', $ok);
        if (!$ok) return false;
        if (password_needs_rehash($admin['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            Db::exec('UPDATE admin SET password_hash = ? WHERE id = 1', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]);
        }
        self::promote('password');
        return true;
    }

    public static function attemptTotp(string $code): bool
    {
        self::assertNotLocked();
        if (self::stage() !== 'totp') return false;
        $admin = self::admin();
        $secret = Crypto::decrypt($admin['totp_secret_enc'], Config::appKey());
        $step = Totp::verify($secret, $code, (int) $admin['totp_last_step']);
        self::recordAttempt('totp', $step !== null);
        if ($step === null) return false;
        Db::exec('UPDATE admin SET totp_last_step = ? WHERE id = 1', [$step]);
        self::promote('full');
        return true;
    }

    public static function attemptRecoveryCode(string $code): bool
    {
        self::assertNotLocked();
        if (self::stage() !== 'totp') return false;
        $normalized = self::normalizeRecovery($code);
        $match = null;
        foreach (Db::all('SELECT id, code_hash FROM recovery_codes WHERE used_at IS NULL') as $row) {
            if (password_verify($normalized, $row['code_hash'])) $match = $row['id'];
        }
        self::recordAttempt('recovery', $match !== null);
        if ($match === null) return false;
        Db::exec('UPDATE recovery_codes SET used_at = ? WHERE id = ?', [Db::now(), $match]);
        self::promote('full');
        return true;
    }

    /** First-time enrolment: pending secret lives only in the session until a code confirms it. */
    public static function pendingSecret(): string
    {
        return $_SESSION['pending_totp'] ??= Totp::generateSecret();
    }

    /** @return string[]|null recovery codes on success */
    public static function confirmEnrollment(string $code, bool $initial): ?array
    {
        self::assertNotLocked();
        if ($initial && (self::stage() !== 'enroll' || !Config::get('allow_web_enrollment'))) return null;
        if (!$initial) self::requireFull();
        $secret = $_SESSION['pending_totp'] ?? null;
        if (!$secret) return null;
        $step = Totp::verify($secret, $code);
        self::recordAttempt('enroll', $step !== null);
        if ($step === null) return null;
        Db::exec('UPDATE admin SET totp_secret_enc = ?, totp_last_step = ?, session_version = session_version + 1, updated_at = ? WHERE id = 1',
            [Crypto::encrypt($secret, Config::appKey()), $step, Db::now()]);
        unset($_SESSION['pending_totp']);
        self::promote('full');
        return self::regenerateRecoveryCodes();
    }

    /** For actions inside the editor that must be re-confirmed with a fresh 2FA code. */
    public static function verifyCurrentTotp(string $code): bool
    {
        self::assertNotLocked();
        $admin = self::admin();
        if (empty($admin['totp_secret_enc'])) return false;
        $step = Totp::verify(Crypto::decrypt($admin['totp_secret_enc'], Config::appKey()), $code, (int) $admin['totp_last_step']);
        self::recordAttempt('reauth', $step !== null);
        if ($step === null) return false;
        Db::exec('UPDATE admin SET totp_last_step = ? WHERE id = 1', [$step]);
        return true;
    }

    public static function verifyCurrentPassword(string $password): bool
    {
        self::assertNotLocked();
        $ok = password_verify($password, self::admin()['password_hash']);
        self::recordAttempt('reauth', $ok);
        return $ok;
    }

    public static function changePassword(string $new): void
    {
        Db::exec('UPDATE admin SET password_hash = ?, must_change_password = 0, session_version = session_version + 1, updated_at = ? WHERE id = 1',
            [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), Db::now()]);
        self::promote('full');
    }

    public static function passwordProblem(string $new, string $repeat): ?string
    {
        if (mb_strlen($new) < 12) return 'Lozinka mora imati barem 12 znakova.';
        if (strlen($new) > 72) return 'Lozinka smije imati najviše 72 bajta.';
        if ($new !== $repeat) return 'Lozinke se ne podudaraju.';
        return null;
    }

    /** @return string[] plain codes, shown once */
    public static function regenerateRecoveryCodes(): array
    {
        Db::exec('DELETE FROM recovery_codes');
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = strtolower(Totp::base32Encode(random_bytes(6))); // 10 chars
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            Db::exec('INSERT INTO recovery_codes (code_hash) VALUES (?)', [password_hash(self::normalizeRecovery($raw), PASSWORD_BCRYPT)]);
        }
        return $codes;
    }

    public static function unusedRecoveryCodes(): int
    {
        return (int) Db::one('SELECT COUNT(*) AS n FROM recovery_codes WHERE used_at IS NULL')['n'];
    }

    private static function normalizeRecovery(string $code): string
    {
        return strtolower(preg_replace('/[^a-z2-7]/i', '', $code));
    }

    // ---- brute-force protection ---------------------------------------

    public static function clientIp(): string
    {
        if (Config::get('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }

    public static function isLocked(): bool
    {
        $since = date('Y-m-d H:i:s', time() - 60 * Config::get('lockout_minutes'));
        $ip = Db::one('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?', [self::clientIp(), $since]);
        if ((int) $ip['n'] >= Config::get('max_failed_per_ip')) return true;
        $all = Db::one('SELECT COUNT(*) AS n FROM login_attempts WHERE success = 0 AND attempted_at >= ?', [$since]);
        return (int) $all['n'] >= Config::get('max_failed_global');
    }

    private static function assertNotLocked(): void
    {
        if (self::isLocked()) throw new HttpError(429, 'Previše neuspjelih pokušaja. Pokušajte ponovno kasnije.');
    }

    private static function recordAttempt(string $stage, bool $success): void
    {
        Db::exec('INSERT INTO login_attempts (ip, stage, success, attempted_at) VALUES (?, ?, ?, ?)', [self::clientIp(), $stage, $success ? 1 : 0, Db::now()]);
        if ($success && in_array($stage, ['totp', 'recovery', 'enroll'], true)) {
            // Only a completed second factor clears this IP's failure streak; a correct
            // password alone must not reset the counter guarding the 2FA step.
            Db::exec('DELETE FROM login_attempts WHERE ip = ? AND success = 0', [self::clientIp()]);
        }
        Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
    }
}

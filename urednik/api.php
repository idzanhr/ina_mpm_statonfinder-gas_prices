<?php
declare(strict_types=1);

// JSON API for the admin editor. Every call requires a session that passed password AND 2FA.
require dirname(__DIR__) . '/app/bootstrap.php';

use InaPostaje\{Audit, Auth, Config, Db, HttpError, InaSync, Stations, Totp, Web};

Web::securityHeaders();
Auth::startSession();

try {
    if (Auth::stage() !== 'editor') throw new HttpError(401, 'Prijava je istekla. Osvježite stranicu.');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST') Web::assertCsrf();
    $body = [];
    if ($method === 'POST' && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) throw new HttpError(400, 'Neispravan zahtjev.');
    }

    switch ("$method $action") {
        case 'GET list':
            Web::json(['stations' => Stations::all()]);

        case 'POST save':
            $id = isset($body['id']) ? (int) $body['id'] : null;
            $station = Stations::save($id ?: null, $body);
            Stations::publish();
            Web::json(['station' => $station]);

        case 'POST delete':
            Stations::delete((int) ($body['id'] ?? 0));
            Stations::publish();
            Web::json(['ok' => true]);

        case 'POST publish':
            Stations::publish();
            Audit::log('publish');
            Web::json(['ok' => true]);

        case 'POST import':
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new HttpError(400, 'Datoteka nije učitana.');
            if ($file['size'] > 5 * 1024 * 1024) throw new HttpError(413, 'Datoteka je prevelika (najviše 5 MB).');
            [$created, $updated, $errors] = Stations::import((string) file_get_contents($file['tmp_name']), (string) $file['name']);
            Stations::publish();
            Audit::log('import', '', "Novih: $created, ažuriranih: $updated, grešaka: " . count($errors));
            Web::json(['created' => $created, 'updated' => $updated, 'errors' => $errors]);

        case 'GET export':
            $stations = Stations::all();
            $stamp = date('Y-m-d');
            header('Cache-Control: no-store');
            if (($_GET['format'] ?? 'json') === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"postaje-$stamp.csv\"");
                echo Stations::toCsv($stations);
            } else {
                header('Content-Type: application/json; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"postaje-$stamp.json\"");
                echo json_encode(['exported_at' => date('c'), 'stations' => $stations], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            exit;

        case 'POST ina-sync':
            // "Osvježi s ina.hr" for one station, right now (same rules as the cron sync).
            $id = (int) ($body['id'] ?? 0);
            if (!Stations::find($id)) throw new HttpError(404, 'Postaja ne postoji.');
            $lines = [];
            $r = InaSync::sync(null, (string) $id, function (string $l) use (&$lines) { $lines[] = $l; });
            Web::json(['result' => $r, 'log' => $lines, 'station' => Stations::find($id)]);

        case 'POST ina-directory':
            // Reads only the ina.hr finder: new stations, changed details, stations no longer listed.
            @set_time_limit(60);
            $lines = [];
            $r = InaSync::sync(null, null, function (string $l) use (&$lines) { $lines[] = $l; }, pages: false);
            Web::json(['result' => $r, 'log' => array_slice($lines, 0, 200)]);

        case 'GET prices':
            Web::json(['changes' => Db::all('SELECT p.fuel_name, p.category, p.old_price, p.new_price, p.changed_at, s.name AS station_name'
                . ' FROM price_history p LEFT JOIN stations s ON s.id = p.station_id ORDER BY p.id DESC LIMIT 100')]);

        case 'GET audit':
            Web::json(['entries' => Audit::recent(150)]);

        // ---- security settings: each one re-confirms with a fresh 2FA code ----

        case 'POST change-password':
            if (!Auth::verifyCurrentPassword((string) ($body['current'] ?? ''))) throw new HttpError(422, 'Trenutna lozinka nije ispravna.');
            if (!Auth::verifyCurrentTotp((string) ($body['code'] ?? ''))) throw new HttpError(422, 'Kod iz aplikacije nije ispravan.');
            if ($problem = Auth::passwordProblem((string) ($body['new'] ?? ''), (string) ($body['repeat'] ?? ''))) throw new HttpError(422, $problem);
            Auth::changePassword((string) $body['new']);
            Audit::log('password');
            Web::json(['ok' => true, 'csrf' => Web::csrfToken()]);

        case 'POST recovery-codes':
            if (!Auth::verifyCurrentTotp((string) ($body['code'] ?? ''))) throw new HttpError(422, 'Kod iz aplikacije nije ispravan.');
            $codes = Auth::regenerateRecoveryCodes();
            Audit::log('recovery-codes');
            Web::json(['codes' => $codes]);

        case 'POST totp-start':
            // Rotate to a new authenticator: the old one stays active until the new one is confirmed.
            if (!Auth::verifyCurrentPassword((string) ($body['current'] ?? ''))) throw new HttpError(422, 'Trenutna lozinka nije ispravna.');
            if (!Auth::verifyCurrentTotp((string) ($body['code'] ?? ''))) throw new HttpError(422, 'Kod iz aplikacije nije ispravan.');
            unset($_SESSION['pending_totp']);
            $secret = Auth::pendingSecret();
            Web::json(['uri' => Totp::uri($secret, Auth::admin()['email'], Config::get('totp_issuer')), 'secret' => trim(chunk_split($secret, 4, ' '))]);

        case 'POST totp-confirm':
            $codes = Auth::confirmEnrollment((string) ($body['code'] ?? ''), false);
            if ($codes === null) throw new HttpError(422, 'Kod iz nove aplikacije nije ispravan.');
            Audit::log('totp-rotate');
            Web::json(['codes' => $codes, 'csrf' => Web::csrfToken()]);

        default:
            throw new HttpError(404, 'Nepoznata radnja.');
    }
} catch (HttpError $e) {
    Web::json(['error' => $e->getMessage()], $e->status);
} catch (\Throwable $e) {
    error_log('[ina-postaje] ' . $e);
    Web::json(['error' => 'Greška na poslužitelju.'], 500);
}

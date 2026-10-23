<?php
declare(strict_types=1);

namespace InaPostaje;

final class Audit
{
    public static function log(string $action, string $stationName = '', string $detail = ''): void
    {
        Db::exec('INSERT INTO audit_log (action, station_name, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)',
            [$action, mb_substr($stationName, 0, 190), $detail, PHP_SAPI === 'cli' ? 'cli' : Auth::clientIp(), Db::now()]);
    }

    public static function recent(int $limit = 50): array
    {
        return Db::all('SELECT action, station_name, detail, ip, created_at FROM audit_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }
}

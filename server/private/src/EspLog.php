<?php
/** Imports the old flat esp.log format into the light_events table (for history and testing). */
final class EspLog
{
    /** Line format: 2026-05-17 11:25:21 - Lux: 141.67 - duration: 831556 - timestamp: 1779031520 */
    public static function import(string $file): int
    {
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - Lux: ([\d.]+)(?: - duration: (\d*))?(?: - timestamp: (\d+))?/', $line, $m)) {
                continue;
            }
            $recv = strtotime($m[1]);
            if ($recv === false) {
                continue;
            }
            $dev = isset($m[4]) ? (int)$m[4] : 0;
            $ts = ($dev > 1600000000 && abs($dev - $recv) <= 600) ? $dev : (int)$recv;
            if (Db::val('SELECT 1 FROM light_events WHERE ts_utc = ? AND lux = ?', [$ts, (float)$m[2]]) !== null) {
                continue;
            }
            Db::run('INSERT INTO light_events (ts_utc, lux, duration, received_utc) VALUES (?, ?, ?, ?)',
                [$ts, (float)$m[2], (int)($m[3] ?? 0), (int)$recv]);
            $n++;
        }
        return $n;
    }
}

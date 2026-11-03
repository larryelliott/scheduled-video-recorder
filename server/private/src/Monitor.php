<?php
/** Offline alerts (with a silence switch), missed-recording checks and housekeeping. Run from cron. */
final class Monitor
{
    public const DEVICES = [
        'agent' => ['label' => 'The Windows PC', 'env' => 'AGENT_OFFLINE_MINUTES', 'default' => 60],
        'esp'   => ['label' => 'The pulpit light sensor', 'env' => 'ESP_OFFLINE_MINUTES', 'default' => 120],
    ];

    /** Called whenever a device checks in. Clears an alert and says so if one had been sent. */
    public static function seen(string $name, array $info): void
    {
        $now = Clock::now();
        $row = Db::one('SELECT * FROM devices WHERE name = ?', [$name]);
        $json = json_encode($info);
        if ($row === null) {
            Db::run('INSERT INTO devices (name, last_seen_utc, info) VALUES (?, ?, ?)', [$name, $now, $json]);
            return;
        }
        Db::run('UPDATE devices SET last_seen_utc = ?, info = ? WHERE name = ?', [$now, $json, $name]);
        if ($row['alert_state'] !== 'ok') {
            Db::run("UPDATE devices SET alert_state = 'ok', alert_utc = NULL WHERE name = ?", [$name]);
            if ($row['alert_state'] === 'alerted') {
                Notify::send('device_offline', self::DEVICES[$name]['label'] . ' is back online',
                    'It checked in again after being offline.', '/');
            }
        }
    }

    public static function device(string $name): ?array
    {
        return Db::one('SELECT * FROM devices WHERE name = ?', [$name]);
    }

    public static function setSilenced(string $name, bool $silenced): void
    {
        if (!isset(self::DEVICES[$name])) {
            return;
        }
        Db::run('UPDATE devices SET alert_state = ? WHERE name = ?', [$silenced ? 'silenced' : 'ok', $name]);
    }

    /** Alert once per outage. Stays quiet until the device returns (or someone un-silences it). */
    public static function checkDevices(): array
    {
        $log = [];
        $now = Clock::now();
        foreach (self::DEVICES as $name => $cfg) {
            $row = self::device($name);
            if ($row === null || !$row['last_seen_utc'] || $row['alert_state'] !== 'ok') {
                continue;
            }
            $age = $now - (int)$row['last_seen_utc'];
            if ($age > Env::int($cfg['env'], $cfg['default']) * 60) {
                Db::run("UPDATE devices SET alert_state = 'alerted', alert_utc = ? WHERE name = ?", [$now, $name]);
                Notify::send('device_offline', $cfg['label'] . ' has not checked in',
                    'Last heard from ' . ago((int)$row['last_seen_utc']) . '. Open the dashboard to silence this until it is fixed.', '/');
                $log[] = $name . ' offline alert sent';
            }
        }
        return $log;
    }

    /** A service whose recording window opened more than 15 minutes ago but never started. */
    public static function checkMissed(): array
    {
        $log = [];
        $rows = Db::all("SELECT * FROM services WHERE skip = 0 AND is_test = 0 AND status = 'scheduled' AND missed_alerted = 0
                         AND start_utc > ? AND start_utc < ?", [Clock::now() - 86400, Clock::now() + 86400]);
        foreach ($rows as $s) {
            if (Clock::now() > Schedule::window($s)[0] + 15 * 60) {
                Db::run('UPDATE services SET missed_alerted = 1 WHERE id = ?', [(int)$s['id']]);
                Notify::send('needs_attention', 'Recording did not start',
                    Services::label($s) . ' was supposed to start recording but has not.', '/services/' . (int)$s['id']);
                $log[] = 'missed recording alert for service ' . $s['id'];
            }
        }
        return $log;
    }

    /** Recording finished a while ago but no sermon times were found. */
    public static function checkCuts(): array
    {
        $log = [];
        $rows = Db::all("SELECT * FROM services WHERE status = 'recorded' AND cut_start_utc IS NULL AND attention_sent = 0
                         AND job_state = '' AND is_test = 0");
        foreach ($rows as $s) {
            $stopped = (int)($s['recording_stopped_utc'] ?? 0);
            if ($stopped > 0 && Clock::now() - $stopped > 20 * 60) {
                Db::run('UPDATE services SET attention_sent = 1 WHERE id = ?', [(int)$s['id']]);
                Notify::send('needs_attention', 'Sermon times not found',
                    Services::label($s) . ': ' . ($s['cut_note'] !== '' ? $s['cut_note'] : 'no light reading found')
                    . ' Open the service to set the start and end by hand.', '/services/' . (int)$s['id']);
                $log[] = 'cuts attention alert for service ' . $s['id'];
            }
        }
        return $log;
    }

    public static function housekeeping(): array
    {
        $log = [];
        foreach (Files::cleanup() as $n) {
            $log[] = 'deleted expired server file ' . $n;
        }
        Db::run('DELETE FROM light_events WHERE ts_utc < ?', [Clock::now() - 120 * 86400]);
        Db::run('DELETE FROM sessions WHERE expires_utc < ?', [Clock::now()]);
        Db::run('DELETE FROM notif_log WHERE ts_utc < ?', [Clock::now() - 90 * 86400]);
        return $log;
    }

    /** Cron entry point. By default only runs 8 AM - 9 PM church time. */
    public static function cron(bool $force = false): array
    {
        $h = (int)fmt_local(Clock::now(), 'G');
        if (!$force && ($h < 8 || $h > 21)) {
            return ['outside active hours, nothing to do'];
        }
        $log = [];
        try {
            $r = Schedule::refresh();
            $log[] = $r['ok'] ? 'schedule refreshed (' . $r['count'] . ' rows)' : 'schedule error: ' . $r['error'];
        } catch (Throwable $e) {
            $log[] = 'schedule exception: ' . $e->getMessage();
        }
        Cuts::refreshActive();
        $log = array_merge($log, self::checkDevices(), self::checkMissed(), self::checkCuts(), self::housekeeping());
        return $log;
    }
}

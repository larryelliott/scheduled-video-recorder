<?php
/** Machine-to-machine endpoints (ESP8266 light sensor and the Windows agent). */
final class Api
{
    /** Token comes in the X-Api-Token header (Authorization: Bearer also accepted). */
    public static function tokenOk(string $envKey): bool
    {
        $want = (string)Env::get($envKey, '');
        $got = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '');
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($got === '' && stripos($auth, 'Bearer ') === 0) {
            $got = trim(substr($auth, 7));
        }
        return $want !== '' && strlen($want) >= 16 && hash_equals($want, $got);
    }

    /** ESP8266 reading: {lux, duration, timestamp, rssi, fw} */
    public static function esp(array $b): array
    {
        $now = Clock::now();
        $dev = (int)($b['timestamp'] ?? 0);
        // Trust the sensor's NTP clock only when it agrees with ours within 10 minutes.
        $ts = ($dev > 1600000000 && abs($dev - $now) <= 600) ? $dev : $now;
        $lux = (float)($b['lux'] ?? 0);
        $prev = Db::val('SELECT lux FROM light_events ORDER BY ts_utc DESC, id DESC LIMIT 1');
        Db::run('INSERT INTO light_events (ts_utc, lux, duration, received_utc, rssi, fw) VALUES (?, ?, ?, ?, ?, ?)',
            [$ts, $lux, (int)($b['duration'] ?? 0), $now, isset($b['rssi']) ? (int)$b['rssi'] : null,
             substr((string)($b['fw'] ?? ''), 0, 20)]);
        Monitor::seen('esp', ['lux' => $lux, 'rssi' => $b['rssi'] ?? null, 'fw' => $b['fw'] ?? '', 'uptime' => $b['uptime'] ?? null]);
        try {
            Services::lightChanged($lux, $prev === null ? null : (float)$prev, $ts);
        } catch (Throwable $e) {
            error_log('lightChanged failed: ' . $e->getMessage()); // never let this break the sensor's reply
        }
        return ['ok' => true, 'server_time' => $now];
    }

    /**
     * Agent heartbeat, every ~20 s:
     * {version, state, recording, obs_ok, disk_free_gb, message, busy_service_id, files:[{name,kind,size,mtime_utc}]}
     */
    public static function heartbeat(array $b): array
    {
        $keys = ['version', 'state', 'recording', 'obs_ok', 'disk_free_gb', 'message', 'busy_service_id'];
        $info = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $b)) {
                $info[$k] = $b[$k];
            }
        }
        Monitor::seen('agent', $info);
        if (isset($b['files']) && is_array($b['files'])) {
            Files::replaceAgentFiles($b['files']);
        }
        try {
            Schedule::refreshIfStale(600);
        } catch (Throwable $e) {
            error_log('schedule refresh failed: ' . $e->getMessage());
        }
        Services::requeueStale(isset($b['busy_service_id']) && $b['busy_service_id'] !== null ? (int)$b['busy_service_id'] : null);
        Cuts::refreshActive();
        $next = Schedule::next();
        return [
            'ok' => true,
            'server_time' => microtime(true),
            'retention_days' => Files::retentionDays(),
            'pinned' => Files::pinnedNames(),
            'next_service' => $next !== null ? Services::publicNext($next) : null,
            'job' => Services::nextJob(),
        ];
    }

    public static function recording(array $b): array
    {
        return Services::onRecording($b);
    }

    public static function job(array $b): array
    {
        return Services::onJob($b);
    }

    /** Called after the router has copied the request body to $tmpPath. */
    public static function finishUpload(
        int $serviceId,
        string $name,
        string $tmpPath,
        int $declaredLength,
        int $expectedLength = -1
    ): array
    {
        $got = (int)@filesize($tmpPath);
        if (($declaredLength >= 0 && $got !== $declaredLength)
            || ($expectedLength >= 0 && $got !== $expectedLength)) {
            @unlink($tmpPath);
            $expected = $expectedLength >= 0 ? $expectedLength : $declaredLength;
            return ['ok' => false, 'error' => "Upload incomplete: got $got of $expected bytes"];
        }
        if (Services::get($serviceId) === null) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => 'Unknown service'];
        }
        return Files::register($serviceId, $name, $tmpPath);
    }
}

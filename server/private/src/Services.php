<?php
/** Service records and the recording / processing lifecycle. */
final class Services
{
    public static function get(int $id): ?array
    {
        return Db::one('SELECT * FROM services WHERE id = ?', [$id]);
    }

    /** [code, label, tone] for display. tone: ok|warn|bad|rec|muted */
    public static function state(array $s): array
    {
        $now = Clock::now();
        if ((int)$s['skip'] === 1) {
            return ['skipped', 'Skipped', 'muted'];
        }
        if ($s['job_state'] === 'running') {
            return ['processing', 'Processing video (' . (int)$s['job_progress'] . '%)', 'warn'];
        }
        if ($s['job_state'] === 'failed') {
            return ['failed', 'Processing failed', 'bad'];
        }
        if ($s['status'] === 'ready') {
            return ['ready', 'Video ready', 'ok'];
        }
        if ($s['status'] === 'recording') {
            return ['recording', 'Recording', 'rec'];
        }
        if ($s['status'] === 'recorded') {
            return $s['job_state'] === 'pending'
                ? ['queued', 'Recorded, waiting to process', 'warn']
                : ['recorded', 'Recorded, waiting for sermon times', 'warn'];
        }
        if ($s['status'] === 'failed') {
            return ['failed', 'Recording failed', 'bad'];
        }
        [$a, $b] = Schedule::window($s);
        if ($b <= $now) {
            return ['missed', 'Not recorded', 'bad'];
        }
        if ($a <= $now) {
            return ['starting', 'Starting', 'warn'];
        }
        return ['scheduled', 'Scheduled', 'muted'];
    }

    public static function upcoming(int $limit = 8): array
    {
        return Db::all("SELECT * FROM services WHERE start_utc >= ? AND is_test = 0 ORDER BY start_utc LIMIT " . (int)$limit,
            [Clock::now() - 3600]);
    }

    public static function past(int $limit = 20): array
    {
        return Db::all("SELECT * FROM services WHERE start_utc < ? ORDER BY start_utc DESC LIMIT " . (int)$limit,
            [Clock::now() - 3600]);
    }

    public static function latestReady(): ?array
    {
        return Db::one("SELECT * FROM services WHERE status = 'ready' ORDER BY ready_utc DESC LIMIT 1");
    }

    public static function publicNext(array $s): array
    {
        [$a, $b] = Schedule::window($s);
        return [
            'id' => (int)$s['id'],
            'start_utc' => (int)$s['start_utc'],
            'rec_start_utc' => $a,
            'rec_stop_utc' => $b,
            'subtitle' => (string)$s['subtitle'],
            'is_test' => (int)$s['is_test'],
        ];
    }

    public static function jobPayload(array $s): array
    {
        return [
            'service_id' => (int)$s['id'],
            'cut_start_utc' => (int)$s['cut_start_utc'],
            'cut_end_utc' => (int)$s['cut_end_utc'],
            'intro' => Schedule::intro($s),
            'output_name' => Schedule::outputName($s),
        ];
    }

    public static function nextJob(): ?array
    {
        $s = Db::one("SELECT * FROM services WHERE job_state = 'pending' AND cut_start_utc IS NOT NULL
                      AND cut_end_utc IS NOT NULL ORDER BY start_utc LIMIT 1");
        return $s ? self::jobPayload($s) : null;
    }

    /** Jobs stuck in "running" (agent restarted mid-job) go back to pending, up to 3 attempts. */
    public static function requeueStale(?int $busyServiceId): void
    {
        $rows = Db::all("SELECT * FROM services WHERE job_state = 'running' AND job_updated_utc < ?", [Clock::now() - 600]);
        foreach ($rows as $r) {
            if ($busyServiceId !== null && $busyServiceId === (int)$r['id']) {
                continue;
            }
            if ((int)$r['job_attempts'] >= 3) {
                Db::run("UPDATE services SET job_state = 'failed', job_msg = ?, job_updated_utc = ? WHERE id = ?",
                    ['Gave up after 3 attempts.', Clock::now(), (int)$r['id']]);
                Notify::send('error', 'Video processing failed', self::label($r) . ': gave up after 3 attempts.', '/services/' . (int)$r['id']);
            } else {
                Db::run("UPDATE services SET job_state = 'pending', job_updated_utc = ? WHERE id = ?", [Clock::now(), (int)$r['id']]);
            }
        }
    }

    public static function label(array $s): string
    {
        $t = trim((string)$s['subtitle']);
        return ($t !== '' ? $t : 'Service') . ' (' . fmt_local((int)$s['start_utc'], 'M j') . ')';
    }

    /** Agent reports: {service_id, event: started|stopped|failed, ts_utc, video_file, video_started_utc, message} */
    public static function onRecording(array $b): array
    {
        $s = self::get((int)($b['service_id'] ?? 0));
        if ($s === null) {
            return ['ok' => false, 'error' => 'Unknown service'];
        }
        $id = (int)$s['id'];
        $ev = (string)($b['event'] ?? '');
        $now = Clock::now();
        $path = '/services/' . $id;

        if ($ev === 'started') {
            $vs = (int)($b['video_started_utc'] ?? $b['ts_utc'] ?? $now);
            $wasRecording = $s['status'] === 'recording';
            Db::run("UPDATE services SET status = 'recording', video_started_utc = COALESCE(video_started_utc, ?),
                     video_file = ?, updated_utc = ? WHERE id = ?",
                [$vs, (string)($b['video_file'] ?? ''), $now, $id]);
            if ((int)$s['is_test'] === 1 && $s['cut_start_utc'] === null) {
                Db::run("UPDATE services SET cut_start_utc = ?, cut_end_utc = ?, cut_source = 'test',
                         cut_note = 'Test recording: fixed 25 second cut.', job_state = 'pending' WHERE id = ?",
                    [$vs + 10, $vs + 35, $id]);
            }
            if (!$wasRecording) {
                Notify::send('recording_started', 'Recording started', self::label($s) . ' started recording.', $path);
            }
        } elseif ($ev === 'stopped') {
            $changed = in_array($s['status'], ['scheduled', 'recording'], true);
            if ($changed) {
                Db::run("UPDATE services SET status = 'recorded', recording_stopped_utc = ?, updated_utc = ? WHERE id = ?",
                    [(int)($b['ts_utc'] ?? $now), $now, $id]);
                Notify::send('recording_stopped', 'Recording stopped', self::label($s) . ' stopped recording.', $path);
            }
        } elseif ($ev === 'failed') {
            Notify::send('error', 'Recording problem', self::label($s) . ': ' . (string)($b['message'] ?? 'unknown error'), $path);
        } else {
            return ['ok' => false, 'error' => 'Unknown event'];
        }
        return ['ok' => true];
    }

    /** Agent reports: {service_id, state: running|failed|done, message, progress} */
    public static function onJob(array $b): array
    {
        $s = self::get((int)($b['service_id'] ?? 0));
        if ($s === null) {
            return ['ok' => false, 'error' => 'Unknown service'];
        }
        $id = (int)$s['id'];
        $state = (string)($b['state'] ?? '');
        $msg = substr((string)($b['message'] ?? ''), 0, 500);
        $now = Clock::now();
        $path = '/services/' . $id;

        if ($state === 'running') {
            $attempts = (int)$s['job_attempts'] + ($s['job_state'] === 'running' ? 0 : 1);
            Db::run("UPDATE services SET job_state = 'running', job_msg = ?, job_progress = ?, job_attempts = ?,
                     job_updated_utc = ? WHERE id = ?",
                [$msg, max(0, min(100, (int)($b['progress'] ?? 0))), $attempts, $now, $id]);
        } elseif ($state === 'failed') {
            Db::run("UPDATE services SET job_state = 'failed', job_msg = ?, job_updated_utc = ? WHERE id = ?", [$msg, $now, $id]);
            Notify::send('error', 'Video processing failed', self::label($s) . ': ' . $msg, $path);
        } elseif ($state === 'done') {
            $files = Files::forService($id);
            if (count($files) === 0) {
                return ['ok' => false, 'error' => 'No uploaded file found for this service'];
            }
            Db::run("UPDATE services SET job_state = 'done', job_msg = '', job_progress = 100, status = 'ready',
                     ready_utc = ?, output_name = ?, job_updated_utc = ?, updated_utc = ? WHERE id = ?",
                [$now, $files[0]['name'], $now, $now, $id]);
            Notify::send('video_ready', 'Video ready', self::label($s) . ' is ready to download.', $path);
        } else {
            return ['ok' => false, 'error' => 'Unknown state'];
        }
        return ['ok' => true];
    }

    // ---- automatic recording from the pulpit light ----------------------------------------

    /**
     * Called after every light reading. When the light turns on and nothing is scheduled, a recording is started
     * automatically; it ends when the light goes off, or after AUTO_LIGHT_MAX_MINUTES, whichever comes first.
     * @param float|null $prevLux the reading before this one (null if there was none)
     */
    public static function lightChanged(float $lux, ?float $prevLux, int $ts): void
    {
        if (Env::get('AUTO_LIGHT_RECORD', '1') === '0') {
            return;
        }
        $now = Clock::now();
        $hi = (float)Env::get('CUT_LUX_ON', '100');
        $lo = (float)Env::get('CUT_LUX_OFF', '50');

        if ($lux < $lo) { // light off: end a light-triggered recording that is still open
            Db::run('UPDATE services SET rec_stop_utc = ?, updated_utc = ? WHERE auto_light = 1 AND rec_stop_utc > ?', [$now, $now, $now]);
            return;
        }
        if ($lux <= $hi || $prevLux === null || $prevLux > $hi) {
            return; // not bright, or the light was already on: only the moment it turns on counts
        }

        $max = max(1, Env::int('AUTO_LIGHT_MAX_MINUTES', 30)) * 60;
        foreach (Db::all('SELECT * FROM services WHERE start_utc > ? AND start_utc < ?', [$now - 86400, $now + 86400]) as $r) {
            [$a, $b] = Schedule::window($r);
            if ($a <= $now && $now < $b) {
                return; // a recording is already scheduled (or deliberately skipped) right now
            }
            if ((int)$r['skip'] === 0 && $a > $now && $a - $now <= $max) {
                return; // a scheduled recording starts within the next stretch anyway
            }
        }

        $start = min($ts, $now);
        while (Db::val('SELECT 1 FROM services WHERE start_utc = ?', [$start]) !== null) {
            $start++;
        }
        Db::run("INSERT INTO services (start_utc, subtitle, is_sheet, intro_edited, auto_light, rec_start_utc, rec_stop_utc, updated_utc)
                 VALUES (?, ?, 0, 1, 1, ?, ?, ?)",
            [$start, 'Sermon ' . fmt_local($start, 'Y-m-d'), $start, $now + $max, $now]);
        $id = Db::id();
        Notify::send('needs_attention', 'Recording started automatically',
            'The pulpit light turned on and nothing was scheduled, so a recording was started. It stops when the light goes off or after '
            . intdiv($max, 60) . ' minutes. Open the service to edit the intro.', '/services/' . $id);
    }

    // ---- actions from the web interface -------------------------------------------------

    /** Start recording now: uses today's service if one starts within 4 hours, else makes a manual one. */
    public static function startNow(): array
    {
        $now = Clock::now();
        $max = Env::int('RECORD_MAX_MINUTES', 100) * 60;
        $next = Schedule::next();
        if ($next !== null && (int)$next['start_utc'] - $now < 4 * 3600) {
            Db::run('UPDATE services SET rec_start_utc = ?, rec_stop_utc = ?, skip = 0, updated_utc = ? WHERE id = ?',
                [$now, $now + $max, $now, (int)$next['id']]);
            return self::get((int)$next['id']);
        }
        $start = $now;
        while (Db::val('SELECT 1 FROM services WHERE start_utc = ?', [$start]) !== null) {
            $start++;
        }
        Db::run("INSERT INTO services (start_utc, subtitle, is_sheet, intro_edited, rec_start_utc, rec_stop_utc, updated_utc)
                 VALUES (?, 'Manual recording', 0, 1, ?, ?, ?)", [$start, $now, $now + $max, $now]);
        return self::get(Db::id());
    }

    public static function stopNow(): bool
    {
        $now = Clock::now();
        $s = Db::one("SELECT * FROM services WHERE status = 'recording' ORDER BY start_utc DESC LIMIT 1");
        if ($s === null) {
            $n = Schedule::next();
            if ($n !== null && Schedule::window($n)[0] <= $now) {
                $s = $n;
            }
        }
        if ($s === null) {
            return false;
        }
        Db::run('UPDATE services SET rec_stop_utc = ?, updated_utc = ? WHERE id = ?', [$now, $now, (int)$s['id']]);
        return true;
    }

    /** A 100-second recording with a fixed cut, so the whole chain can be tested without waiting for Sunday. */
    public static function createTest(): int
    {
        $now = Clock::now();
        $start = $now + 90;
        while (Db::val('SELECT 1 FROM services WHERE start_utc = ?', [$start]) !== null) {
            $start++;
        }
        Db::run("INSERT INTO services (start_utc, subtitle, description, is_sheet, is_test, intro_edited, rec_start_utc, rec_stop_utc, updated_utc)
                 VALUES (?, 'Test recording', 'This is only a test', 0, 1, 1, ?, ?, ?)", [$start, $now, $now + 100, $now]);
        return Db::id();
    }

    public static function setSkip(int $id, bool $skip): void
    {
        Db::run('UPDATE services SET skip = ?, updated_utc = ? WHERE id = ?', [$skip ? 1 : 0, Clock::now(), $id]);
    }

    public static function saveIntro(int $id, array $f): void
    {
        Db::run('UPDATE services SET subtitle = ?, date_text = ?, text4 = ?, description = ?, description2 = ?,
                 intro_edited = 1, updated_utc = ? WHERE id = ?',
            [trim((string)($f['subtitle'] ?? '')), trim((string)($f['date_text'] ?? '')), trim((string)($f['text4'] ?? '')),
             trim((string)($f['description'] ?? '')), trim((string)($f['description2'] ?? '')), Clock::now(), $id]);
    }

    /** Manual cut times. The service is queued for processing straight away. */
    public static function saveCuts(int $id, int $start, int $end): ?string
    {
        if ($end <= $start) {
            return 'The end time must be after the start time.';
        }
        $s = self::get($id);
        if ($s === null) {
            return 'Unknown service.';
        }
        $queue = in_array($s['status'], ['recording', 'recorded', 'ready'], true) && $s['job_state'] !== 'running';
        Db::run("UPDATE services SET cut_start_utc = ?, cut_end_utc = ?, cut_source = 'manual', cut_note = 'Set by hand.',
                 job_state = CASE WHEN ? = 1 THEN 'pending' ELSE job_state END, job_attempts = 0, job_msg = '', updated_utc = ? WHERE id = ?",
            [$start, $end, $queue ? 1 : 0, Clock::now(), $id]);
        return null;
    }

    public static function resetCuts(int $id): void
    {
        Db::run("UPDATE services SET cut_source = 'auto', cut_start_utc = NULL, cut_end_utc = NULL, cut_note = '',
                 job_state = CASE WHEN job_state = 'pending' THEN '' ELSE job_state END, updated_utc = ? WHERE id = ?",
            [Clock::now(), $id]);
        Cuts::refreshActive();
    }

    public static function retry(int $id): void
    {
        Db::run("UPDATE services SET job_state = 'pending', job_attempts = 0, job_msg = '', job_progress = 0,
                 job_updated_utc = ?, updated_utc = ? WHERE id = ? AND cut_start_utc IS NOT NULL", [Clock::now(), Clock::now(), $id]);
    }
}

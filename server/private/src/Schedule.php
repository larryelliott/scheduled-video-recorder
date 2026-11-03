<?php
/** The service schedule: read from a Google Sheet (published CSV), cached in SQLite. */
final class Schedule
{
    public static string $lastError = '';

    /** Recording window for a service: [start, stop] as UTC unix times. */
    public static function window(array $s): array
    {
        $lead = Env::int('RECORD_LEAD_MINUTES', 10) * 60;
        $max = Env::int('RECORD_MAX_MINUTES', 100) * 60;
        $defStart = (int)$s['start_utc'] - $lead;
        $defStop = $defStart + $max;
        $start = $s['rec_start_utc'] !== null ? (int)$s['rec_start_utc'] : $defStart;
        $stop = $s['rec_stop_utc'] !== null ? (int)$s['rec_stop_utc'] : $defStop;
        return [$start, $stop];
    }

    /** The next (or current) service the PC should record, or null. */
    public static function next(): ?array
    {
        $now = Clock::now();
        $rows = Db::all(
            "SELECT * FROM services WHERE skip = 0 AND status IN ('scheduled','recording','recorded')
             AND start_utc > ? AND start_utc < ? ORDER BY start_utc",
            [$now - 86400, $now + 86400 * 60]
        );
        $best = null;
        $bestStart = PHP_INT_MAX;
        foreach ($rows as $r) {
            [$a, $b] = self::window($r);
            if ($b <= $now) {
                continue;
            }
            if ($a < $bestStart) {
                $bestStart = $a;
                $best = $r;
            }
        }
        return $best;
    }

    public static function intro(array $s): array
    {
        $date = trim((string)$s['date_text']) !== '' ? $s['date_text'] : fmt_local((int)$s['start_utc'], 'F j, Y');
        return [
            'duration'         => Env::int('INTRO_SECONDS', 5),
            'title'            => (string)Env::get('INTRO_TITLE', 'Sermons'),
            'subtitle'         => (string)$s['subtitle'],
            'date'             => (string)$date,
            'description'      => (string)$s['description'],
            'description2'     => (string)$s['description2'],
            'text1'            => (string)Env::get('INTRO_TEXT1', ''),
            'text2'            => (string)Env::get('INTRO_TEXT2', ''),
            'text3'            => (string)Env::get('INTRO_TEXT3', ''),
            'text4'            => (string)$s['text4'],
            'background_color' => (string)Env::get('INTRO_BG', '0x000000'),
            'text_color'       => (string)Env::get('INTRO_FG', '0xFFFFFF'),
            'accent_color'     => (string)Env::get('INTRO_ACCENT', '0x7F0000'),
            'font'             => (string)Env::get('INTRO_FONT', 'C:/Windows/Fonts/arial.ttf'),
        ];
    }

    /** Subtitle with every run of non-alphanumerics turned into one hyphen, e.g. All-Saints-Day.mp4 (same rule as the old process). */
    public static function outputName(array $s): string
    {
        $slug = slug((string)$s['subtitle']);
        return ($slug !== '' ? $slug : 'untitled') . '.mp4';
    }

    public static function fetch(): ?string
    {
        $url = Env::get('SHEET_CSV_URL');
        if (!$url) {
            $id = Env::get('SHEET_ID');
            if (!$id) {
                self::$lastError = 'SHEET_ID is not set in .env';
                return null;
            }
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($id)
                . '/export?format=csv&gid=' . rawurlencode((string)Env::get('SHEET_GID', '0'));
        }
        $body = Http::request('GET', $url, null, [], 12);
        if ($body === null || trim($body) === '') {
            self::$lastError = 'Could not download the sheet' . (Http::$error !== '' ? ' (' . Http::$error . ')' : '')
                . '. Is it shared as "anyone with the link can view"?';
            return null;
        }
        if (stripos($body, '<html') !== false) {
            self::$lastError = 'Google returned a web page instead of CSV. Share the sheet as "anyone with the link can view".';
            return null;
        }
        return $body;
    }

    /**
     * Parses CSV text into rows. Columns are matched by header name, case-insensitive:
     * Date, Service Time, Subtitle, Text4, Description, Description2.
     */
    public static function parse(string $csv): array
    {
        $csv = (string)preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $hdr = null;
        $out = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue;
            }
            if ($hdr === null) {
                $hdr = array_map(function ($x) {
                    return strtolower(trim((string)$x));
                }, $r);
                continue;
            }
            $row = [];
            foreach ($hdr as $i => $k) {
                if ($k !== '') {
                    $row[$k] = trim((string)($r[$i] ?? ''));
                }
            }
            $date = $row['date'] ?? '';
            $time = $row['service time'] ?? ($row['time'] ?? '');
            if ($time === '') {
                $time = (string)Env::get('DEFAULT_SERVICE_TIME', '');
            }
            if ($date === '' || $time === '') {
                continue;
            }
            $ts = strtotime($date . ' ' . $time);
            if ($ts === false) {
                continue;
            }
            $out[] = [
                'start'        => (int)$ts,
                'subtitle'     => $row['subtitle'] ?? '',
                'text4'        => $row['text4'] ?? '',
                'description'  => $row['description'] ?? '',
                'description2' => $row['description2'] ?? '',
            ];
        }
        fclose($fh);
        return $out;
    }

    public static function refresh(): array
    {
        Db::setSetting('schedule_attempt_utc', (string)Clock::now());
        $csv = self::fetch();
        if ($csv === null) {
            Db::setSetting('schedule_error', self::$lastError);
            return ['ok' => false, 'error' => self::$lastError];
        }
        $rows = self::parse($csv);
        $now = Clock::now();
        $seen = [];
        $n = 0;
        foreach ($rows as $r) {
            if ($r['start'] < $now - 2 * 86400 || $r['start'] > $now + 90 * 86400) {
                continue;
            }
            $seen[] = $r['start'];
            $ex = Db::one('SELECT id, intro_edited FROM services WHERE start_utc = ?', [$r['start']]);
            if ($ex === null) {
                Db::run('INSERT INTO services (start_utc, subtitle, text4, description, description2, is_sheet, updated_utc)
                         VALUES (?, ?, ?, ?, ?, 1, ?)',
                    [$r['start'], $r['subtitle'], $r['text4'], $r['description'], $r['description2'], $now]);
            } elseif ((int)$ex['intro_edited'] === 0) {
                Db::run('UPDATE services SET subtitle = ?, text4 = ?, description = ?, description2 = ?, updated_utc = ? WHERE id = ?',
                    [$r['subtitle'], $r['text4'], $r['description'], $r['description2'], $now, (int)$ex['id']]);
            }
            $n++;
        }
        // Future, untouched services that vanished from the sheet are removed.
        $future = Db::all(
            "SELECT id, start_utc FROM services WHERE is_sheet = 1 AND status = 'scheduled' AND start_utc > ?
             AND rec_start_utc IS NULL AND intro_edited = 0",
            [$now]
        );
        foreach ($future as $f) {
            if (!in_array((int)$f['start_utc'], $seen, true)) {
                Db::run('DELETE FROM services WHERE id = ?', [(int)$f['id']]);
            }
        }
        Db::setSetting('schedule_ok_utc', (string)$now);
        Db::setSetting('schedule_error', '');
        return ['ok' => true, 'count' => $n];
    }

    public static function refreshIfStale(int $maxAgeSec = 600): void
    {
        $last = (int)Db::setting('schedule_attempt_utc', '0');
        if (Clock::now() - $last >= $maxAgeSec) {
            self::refresh();
        }
    }
}

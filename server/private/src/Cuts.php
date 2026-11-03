<?php
/**
 * Works out where the sermon starts and ends from the pulpit light sensor.
 * A "cycle" begins at the first reading above CUT_LUX_ON and ends at the first
 * reading below CUT_LUX_OFF. The longest plausible cycle in the service window wins.
 */
final class Cuts
{
    public static function events(int $from, int $to): array
    {
        return Db::all('SELECT ts_utc, lux FROM light_events WHERE ts_utc >= ? AND ts_utc <= ? ORDER BY ts_utc, id', [$from, $to]);
    }

    public static function window(int $serviceStart): array
    {
        return [
            $serviceStart - Env::int('CUT_WINDOW_BEFORE_MIN', 20) * 60,
            $serviceStart + Env::int('CUT_WINDOW_AFTER_MIN', 180) * 60,
        ];
    }

    public static function detect(int $serviceStart): array
    {
        [$from, $to] = self::window($serviceStart);
        return self::analyze(self::events($from, $to));
    }

    /** @param array $ev rows with ts_utc and lux, ordered by time */
    public static function analyze(array $ev): array
    {
        $hi = (float)Env::get('CUT_LUX_ON', '100');
        $lo = (float)Env::get('CUT_LUX_OFF', '50');
        $minSpan = Env::int('CUT_MIN_SECONDS', 180);
        $maxSpan = Env::int('CUT_MAX_SECONDS', 5400);
        $padStart = Env::int('CUT_PAD_START_SECONDS', 0);
        $padEnd = Env::int('CUT_PAD_END_SECONDS', 0);

        $spans = [];
        $on = null;
        $first = true;
        $alreadyOn = false;
        foreach ($ev as $e) {
            $lux = (float)$e['lux'];
            $ts = (int)$e['ts_utc'];
            if ($on === null) {
                if ($lux > $hi) {
                    $on = $ts;
                    if ($first) {
                        $alreadyOn = true;
                    }
                }
            } elseif ($lux < $lo) {
                $spans[] = [$on, $ts];
                $on = null;
            }
            $first = false;
        }

        $valid = [];
        foreach ($spans as $s) {
            $len = $s[1] - $s[0];
            if ($len >= $minSpan && $len <= $maxSpan) {
                $valid[] = $s;
            }
        }

        if (count($valid) === 0) {
            if ($on !== null) {
                return ['status' => 'waiting', 'note' => 'The pulpit light is on; waiting for it to turn off.'];
            }
            return ['status' => 'none', 'note' => count($spans) > 0
                ? 'The light turned on and off, but not for a plausible sermon length.'
                : 'No pulpit light-on reading found in the service window.'];
        }

        usort($valid, function ($a, $b) {
            return ($b[1] - $b[0]) <=> ($a[1] - $a[0]);
        });
        $best = $valid[0];
        $notes = [];
        if (count($valid) > 1) {
            $notes[] = count($valid) . ' light cycles found; used the longest.';
        }
        if ($alreadyOn) {
            $notes[] = 'The light was already on at the first reading.';
        }
        return [
            'status' => 'ok',
            'start'  => $best[0] - $padStart,
            'end'    => $best[1] + $padEnd,
            'note'   => implode(' ', $notes),
        ];
    }

    /** Recomputes cuts for services that are recording or recorded and not yet processed. */
    public static function refreshActive(): void
    {
        $rows = Db::all(
            "SELECT * FROM services WHERE status IN ('recording','recorded') AND cut_source = 'auto'
             AND job_state IN ('', 'pending') AND is_test = 0"
        );
        foreach ($rows as $s) {
            $r = self::detect((int)$s['start_utc']);
            if ($r['status'] === 'ok') {
                Db::run(
                    "UPDATE services SET cut_start_utc = ?, cut_end_utc = ?, cut_note = ?,
                       job_state = CASE WHEN job_state = '' THEN 'pending' ELSE job_state END, updated_utc = ? WHERE id = ?",
                    [$r['start'], $r['end'], $r['note'], Clock::now(), (int)$s['id']]
                );
            } else {
                Db::run('UPDATE services SET cut_note = ? WHERE id = ?', [$r['note'], (int)$s['id']]);
            }
        }
    }
}

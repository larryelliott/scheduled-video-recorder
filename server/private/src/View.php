<?php
/** HTML helpers shared by the pages. */
final class View
{
    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . h(Auth::csrf()) . '">';
    }

    public static function layout(string $title, string $body, string $active = ''): string
    {
        $user = Auth::user();
        $tabs = ['dash' => ['/', 'Today'], 'services' => ['/services', 'Services'], 'files' => ['/files', 'Files'], 'settings' => ['/settings', 'Settings']];
        $nav = '';
        if ($user !== null) {
            foreach ($tabs as $k => [$path, $label]) {
                $nav .= '<a href="' . h(base_path() . $path) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
            }
            $nav = '<nav class="tabs" aria-label="Main">' . $nav . '</nav>';
        }
        $b = h(base_path());
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="robots" content="noindex,nofollow"><meta name="base" content="' . $b . '">'
            . '<meta name="theme-color" content="#4a2c82">'
            . '<meta name="apple-mobile-web-app-capable" content="yes">'
            . '<meta name="apple-mobile-web-app-title" content="Sermon Video">'
            . '<title>' . h($title) . '</title>'
            . '<link rel="stylesheet" href="' . $b . '/assets/app.css?v=1"></head><body>'
            . '<main class="wrap">' . $body . '</main>' . $nav
            . '<script src="' . $b . '/assets/app.js?v=1" defer></script></body></html>';
    }

    public static function badge(array $state): string
    {
        return '<span class="badge ' . h($state[2]) . '">' . h($state[1]) . '</span>';
    }

    public static function post(string $action, string $label, string $class = 'btn', string $confirm = '', array $hidden = []): string
    {
        $h = '';
        foreach ($hidden as $k => $v) {
            $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        }
        return '<form method="post" action="' . h(base_path() . $action) . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
            . self::csrf() . $h . '<button class="' . h($class) . '">' . h($label) . '</button></form>';
    }

    /** Live status lamp and device health. Replaced in place by app.js every few seconds. */
    public static function statusCard(): string
    {
        $now = Clock::now();
        $agent = Monitor::device('agent');
        $esp = Monitor::device('esp');
        $rec = Db::one("SELECT * FROM services WHERE status = 'recording' ORDER BY start_utc DESC LIMIT 1");
        $job = Db::one("SELECT * FROM services WHERE job_state = 'running' LIMIT 1");
        $next = Schedule::next();
        $agentSeen = $agent['last_seen_utc'] ?? null;
        $agentStale = $agentSeen === null || ($now - (int)$agentSeen) > 300;

        if ($rec !== null) {
            $vs = (int)($rec['video_started_utc'] ?? $now);
            $lamp = 'rec';
            $head = 'Recording';
            $sub = 'Started ' . fmt_local($vs, 'g:i A') . ', running ' . fmt_duration($now - $vs) . '. ' . Services::label($rec);
        } elseif ($job !== null) {
            $lamp = 'busy';
            $head = 'Processing video';
            $sub = Services::label($job) . ': ' . (int)$job['job_progress'] . '% done' . ($job['job_msg'] !== '' ? ', ' . $job['job_msg'] : '');
        } elseif ($agentStale) {
            $lamp = 'warn';
            $head = 'Windows PC not responding';
            $sub = $agentSeen === null ? 'It has never checked in.' : 'Last check-in ' . ago((int)$agentSeen) . '.';
        } elseif ($next !== null && Schedule::window($next)[0] - $now < 3600) {
            $lamp = 'armed';
            $head = 'Ready to record';
            $sub = 'Recording starts ' . fmt_local(max(Schedule::window($next)[0], $now), 'g:i A') . ' for ' . Services::label($next) . '.';
        } else {
            $lamp = 'idle';
            $head = 'Standing by';
            $sub = $next !== null ? 'Next recording ' . fmt_local(Schedule::window($next)[0]) . '.' : 'No upcoming service in the schedule.';
        }

        $agentInfo = self::info($agent);
        $agentText = 'has not checked in yet';
        if ($agent !== null && $agentSeen) {
            $agentText = 'checked in ' . ago((int)$agentSeen);
            if (isset($agentInfo['disk_free_gb'])) {
                $agentText .= ', ' . round((float)$agentInfo['disk_free_gb']) . ' GB free';
            }
        }
        $espInfo = self::info($esp);
        $espText = 'has not reported yet';
        if ($esp !== null && $esp['last_seen_utc']) {
            $espText = 'reading ' . round((float)($espInfo['lux'] ?? 0)) . ' lux, ' . ago((int)$esp['last_seen_utc']);
        }
        $rows = self::deviceRow('agent', 'Windows PC', $agent, $agentText)
            . self::deviceRow('esp', 'Pulpit light sensor', $esp, $espText);

        return '<section class="status ' . $lamp . '" id="status-card"><div class="lamp" aria-hidden="true"></div>'
            . '<div class="status-text"><h1>' . h($head) . '</h1><p>' . h($sub) . '</p></div></section>'
            . '<ul class="devices">' . $rows . '</ul>';
    }

    private static function info(?array $dev): array
    {
        $i = json_decode((string)($dev['info'] ?? '{}'), true);
        return is_array($i) ? $i : [];
    }

    private static function deviceRow(string $key, string $label, ?array $dev, string $text): string
    {
        $state = $dev['alert_state'] ?? 'ok';
        $extra = '';
        if ($state === 'alerted') {
            $extra = '<span class="badge bad">Offline</span> ' . self::post('/actions/silence', 'Silence alerts', 'btn small', '', ['device' => $key, 'on' => '1']);
        } elseif ($state === 'silenced') {
            $extra = '<span class="badge muted">Alerts silenced</span> ' . self::post('/actions/silence', 'Resume alerts', 'btn small', '', ['device' => $key, 'on' => '0']);
        }
        return '<li><span><strong>' . h($label) . '</strong> ' . h($text) . '</span>' . ($extra !== '' ? '<span class="dev-actions">' . $extra . '</span>' : '') . '</li>';
    }

    /** A scaled-down copy of the 1920x1080 intro card, laid out like the ffmpeg render. */
    public static function introPreview(array $i): string
    {
        $rows = [
            ['title', 200, 72], ['subtitle', 320, 48], ['date', 380, 42], ['description', 500, 36], ['description2', 550, 36],
            ['text1', 680, 36], ['text2', 730, 36], ['text3', 780, 36], ['text4', 830, 36],
        ];
        $css = sprintf('--bg:%s;--fg:%s;--ac:%s', self::color((string)$i['background_color']), self::color((string)$i['text_color']), self::color((string)$i['accent_color']));
        $out = '<div class="intro" style="' . h($css) . '" role="img" aria-label="Preview of the video intro">';
        foreach ($rows as [$k, $y, $size]) {
            $out .= sprintf('<div class="it" data-f="%s" style="top:%.2f%%;font-size:%.3fcqw">%s</div>', h($k), $y / 10.8, $size / 19.2, h((string)($i[$k] ?? '')));
            if ($k === 'title') {
                $out .= '<div class="rule"></div>';
            }
        }
        return $out . '</div>';
    }

    /** ffmpeg 0xRRGGBB (or a name) to a CSS colour. */
    private static function color(string $c): string
    {
        if (preg_match('/^0x([0-9a-fA-F]{6})$/', $c, $m)) {
            return '#' . $m[1];
        }
        return preg_match('/^[a-zA-Z]+$/', $c) ? $c : '#000000';
    }
}

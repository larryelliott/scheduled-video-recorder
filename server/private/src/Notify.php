<?php
/** Notifications: email and/or ntfy push, chosen per user and per event. */
final class Notify
{
    public const EVENTS = [
        'recording_started' => 'Recording started',
        'recording_stopped' => 'Recording stopped',
        'video_ready'       => 'Video ready',
        'needs_attention'   => 'Needs attention (sermon times not found, recording missed)',
        'error'             => 'Errors',
        'device_offline'    => 'Windows PC or light sensor offline',
    ];

    /** @var callable|null test hook: function(string $channel, array $user, string $title, string $body, string $url) */
    public static $transport = null;

    /** Admins get everything by default; regular users start with nothing and opt in. */
    public static function prefsFor(array $user): array
    {
        $saved = json_decode((string)($user['prefs'] ?? '{}'), true);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::EVENTS as $ev => $_label) {
            $def = $user['role'] === 'admin';
            $out[$ev] = [
                'email' => (bool)($saved[$ev]['email'] ?? $def),
                'ntfy'  => (bool)($saved[$ev]['ntfy'] ?? $def),
            ];
        }
        return $out;
    }

    public static function send(string $event, string $title, string $body, string $path = '/'): void
    {
        $url = base_url() . $path;
        $sent = [];
        foreach (Db::all('SELECT * FROM users WHERE active = 1') as $u) {
            $p = self::prefsFor($u)[$event] ?? ['email' => false, 'ntfy' => false];
            if ($p['email'] && $u['email'] !== '' && self::deliver('email', $u, $title, $body, $url, $event)) {
                $sent[] = $u['name'] . ':email';
            }
            if ($p['ntfy'] && $u['ntfy_topic'] !== '' && self::deliver('ntfy', $u, $title, $body, $url, $event)) {
                $sent[] = $u['name'] . ':ntfy';
            }
        }
        Db::run('INSERT INTO notif_log (ts_utc, event, title, sent_to) VALUES (?, ?, ?, ?)',
            [Clock::now(), $event, $title, implode(', ', $sent)]);
    }

    private static function deliver(string $channel, array $u, string $title, string $body, string $url, string $event): bool
    {
        if (self::$transport !== null) {
            (self::$transport)($channel, $u, $title, $body, $url);
            return true;
        }
        try {
            if ($channel === 'email') {
                $ok = Mailer::send($u['email'], $title, $body . "\r\n\r\n" . $url);
                // Remember the last problem so Settings can show it instead of failing silently.
                Db::setSetting('mail_error', $ok ? '' : date('M j g:i A') . ': ' . Mailer::$lastError);
                if (!$ok) {
                    error_log('email to ' . $u['email'] . ' failed: ' . Mailer::$lastError);
                }
                return $ok;
            }
            $server = rtrim((string)Env::get('NTFY_SERVER', 'https://ntfy.sh'), '/');
            $prio = ($event === 'error' || $event === 'device_offline' || $event === 'needs_attention') ? '4' : '3';
            $r = Http::request('POST', $server . '/' . rawurlencode($u['ntfy_topic']), $body,
                ['Title: ' . self::ascii($title), 'Click: ' . $url, 'Priority: ' . $prio], 8);
            return $r !== null;
        } catch (Throwable $e) {
            error_log('notify failed: ' . $e->getMessage());
            return false;
        }
    }

    /** HTTP headers must be plain ASCII. */
    private static function ascii(string $s): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $s);
    }
}

<?php
/** Small shared helpers. */

final class Clock
{
    public static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    return rtrim((string)Env::get('BASE_PATH', '/video-manager'), '/');
}

function base_url(): string
{
    return rtrim((string)Env::get('BASE_URL', 'https://peacealma.org/video-manager'), '/');
}

function app_tz(): DateTimeZone
{
    return new DateTimeZone((string)Env::get('APP_TIMEZONE', 'America/Detroit'));
}

/** Format a UTC unix time in the church's time zone. */
function fmt_local(int $ts, string $fmt = 'D, M j \a\t g:i A'): string
{
    $d = new DateTimeImmutable('@' . $ts);
    return $d->setTimezone(app_tz())->format($fmt);
}

function fmt_duration(int $sec): string
{
    $sec = max(0, $sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function fmt_bytes(int $b): string
{
    if ($b >= 1073741824) {
        return number_format($b / 1073741824, 2) . ' GB';
    }
    if ($b >= 1048576) {
        return number_format($b / 1048576, 0) . ' MB';
    }
    return number_format($b / 1024, 0) . ' KB';
}

/** "5 min ago", "2 h ago", "never". */
function ago(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $d = Clock::now() - $ts;
    if ($d < 0) {
        $d = 0;
    }
    if ($d < 90) {
        return $d . ' sec ago';
    }
    if ($d < 5400) {
        return round($d / 60) . ' min ago';
    }
    if ($d < 172800) {
        return round($d / 3600) . ' h ago';
    }
    return round($d / 86400) . ' days ago';
}

/** Letters, digits and hyphens only, like the original sanitize_filename(). */
function slug(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}

function safe_filename(string $s): string
{
    $s = basename($s);
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', $s);
    return trim((string)$s, '.-');
}

function redirect(string $path): void
{
    header('Location: ' . base_path() . $path);
    exit;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/** Request / HTTP helpers with test hooks. */
final class Http
{
    public static ?string $bodyOverride = null;
    /** @var callable|null function(string $method, string $url, ?string $body, array $headers): ?string */
    public static $fetcher = null;
    public static string $error = '';

    public static function body(): string
    {
        return self::$bodyOverride ?? (string)file_get_contents('php://input');
    }

    public static function json(): array
    {
        $d = json_decode(self::body(), true);
        return is_array($d) ? $d : [];
    }

    public static function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 10): ?string
    {
        self::$error = '';
        if (self::$fetcher !== null) {
            return (self::$fetcher)($method, $url, $body, $headers);
        }
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]]);
            $res = @file_get_contents($url, false, $ctx);
            if ($res === false) {
                self::$error = 'request failed';
                return null;
            }
            return $res;
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (strtoupper($method) === 'POST') {
            // CURLOPT_POST lets cURL follow Apps Script's 302 response as a GET.
            $options[CURLOPT_POST] = true;
        } elseif (strtoupper($method) !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $code >= 400) {
            self::$error = $err !== '' ? $err : ('HTTP ' . $code);
            return null;
        }
        return (string)$res;
    }
}

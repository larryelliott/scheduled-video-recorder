<?php
/**
 * One-time notices ("Saved.", "Starting...") passed across a redirect in a short-lived cookie,
 * so the address bar never carries message text and a page refresh does not show the notice again.
 */
final class Flash
{
    private const COOKIE = 'vm_flash';

    public static function encode(string $msg, string $tone): string
    {
        $json = json_encode(['m' => substr($msg, 0, 500), 't' => $tone === 'bad' ? 'bad' : 'ok'], JSON_INVALID_UTF8_SUBSTITUTE);
        return rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
    }

    /** @return array{0:string,1:string} [message, tone] */
    public static function decode(string $raw): array
    {
        $j = json_decode((string)base64_decode(strtr($raw, '-_', '+/')), true);
        if (!is_array($j)) {
            return ['', 'ok'];
        }
        return [(string)($j['m'] ?? ''), ($j['t'] ?? '') === 'bad' ? 'bad' : 'ok'];
    }

    public static function set(string $msg, string $tone = 'ok'): void
    {
        setcookie(self::COOKIE, self::encode($msg, $tone), self::opts(Clock::now() + 120));
    }

    /** Reads and clears the notice. Returns ['', 'ok'] when there is none. */
    public static function take(): array
    {
        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') {
            return ['', 'ok'];
        }
        unset($_COOKIE[self::COOKIE]);
        setcookie(self::COOKIE, '', self::opts(Clock::now() - 3600));
        return self::decode($raw);
    }

    private static function opts(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => base_path() === '' ? '/' : base_path(),
            'secure' => strpos(base_url(), 'https://') === 0,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}

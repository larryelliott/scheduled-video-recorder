<?php
/**
 * Magic-link login. A link is a long random token; only its SHA-256 hash is stored.
 * Opening the link and tapping the confirm button sets a one-year, HttpOnly cookie.
 * Links are reusable (so chat-app link previews can't burn them) until revoked.
 */
final class Auth
{
    public const COOKIE = 'vm_session';
    public const LIFETIME = 31536000; // one year

    private static bool $loaded = false;
    private static ?array $user = null;

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $t): string
    {
        return hash('sha256', $t);
    }

    public static function createUser(string $name, string $role = 'user', string $email = ''): int
    {
        Db::run('INSERT INTO users (name, role, email, created_utc) VALUES (?, ?, ?, ?)',
            [$name, $role === 'admin' ? 'admin' : 'user', $email, Clock::now()]);
        return Db::id();
    }

    /** Creates a link and returns the plain token (shown once, never stored). */
    public static function createLink(int $userId): string
    {
        $t = self::token();
        Db::run('INSERT INTO links (user_id, token_hash, created_utc) VALUES (?, ?, ?)',
            [$userId, self::hash($t), Clock::now()]);
        return $t;
    }

    public static function linkUrl(string $token): string
    {
        return base_url() . '/m/' . $token;
    }

    /** @return array|null user row (plus link_id) if the token is valid. */
    public static function redeem(string $token): ?array
    {
        return Db::one(
            'SELECT l.id AS link_id, u.* FROM links l JOIN users u ON u.id = l.user_id
             WHERE l.token_hash = ? AND l.revoked_utc IS NULL AND u.active = 1',
            [self::hash($token)]
        );
    }

    public static function revokeLink(int $linkId): void
    {
        Db::run('UPDATE links SET revoked_utc = ? WHERE id = ? AND revoked_utc IS NULL', [Clock::now(), $linkId]);
        Db::run('DELETE FROM sessions WHERE link_id = ?', [$linkId]);
    }

    public static function start(array $user, ?int $linkId): void
    {
        $t = self::token();
        $now = Clock::now();
        Db::run(
            'INSERT INTO sessions (user_id, token_hash, csrf, link_id, created_utc, last_seen_utc, expires_utc, ua)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int)$user['id'], self::hash($t), self::token(), $linkId, $now, $now, $now + self::LIFETIME,
             substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]
        );
        self::setCookie($t, $now + self::LIFETIME);
    }

    private static function setCookie(string $token, int $expires): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => $expires,
            'path' => base_path() === '' ? '/' : base_path(),
            'secure' => strpos(base_url(), 'https://') === 0,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $t = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($t === '') {
            return null;
        }
        $now = Clock::now();
        $row = Db::one(
            'SELECT s.id AS sid, s.csrf, s.last_seen_utc, u.* FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_utc > ? AND u.active = 1',
            [self::hash($t), $now]
        );
        if ($row === null) {
            return null;
        }
        if ($now - (int)$row['last_seen_utc'] > 86400) { // slide the expiry about once a day
            Db::run('UPDATE sessions SET last_seen_utc = ?, expires_utc = ? WHERE id = ?',
                [$now, $now + self::LIFETIME, (int)$row['sid']]);
            self::setCookie($t, $now + self::LIFETIME);
        }
        self::$user = $row;
        return $row;
    }

    public static function logout(): void
    {
        $t = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($t !== '') {
            Db::run('DELETE FROM sessions WHERE token_hash = ?', [self::hash($t)]);
        }
        self::setCookie('', Clock::now() - 3600);
        self::$user = null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    public static function csrf(): string
    {
        $u = self::user();
        return $u === null ? '' : (string)$u['csrf'];
    }

    public static function checkCsrf(): void
    {
        $sent = (string)($_POST['_csrf'] ?? '');
        if ($sent === '' || !hash_equals(self::csrf(), $sent)) {
            http_response_code(403);
            echo 'Your session expired. Go back, reload the page and try again.';
            exit;
        }
    }
}

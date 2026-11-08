<?php
/** Stores small notification previews behind unguessable, narrowly scoped URLs. */
final class NotificationImages
{
    private const KINDS = ['recording_started', 'youtube_published'];
    private const MAX_BYTES = 300000;

    public static function store(int $serviceId, string $kind, string $encoded): ?string
    {
        if ($serviceId < 1 || !in_array($kind, self::KINDS, true)) {
            return null;
        }
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || strlen($bytes) < 4 || strlen($bytes) > self::MAX_BYTES
            || substr($bytes, 0, 3) !== "\xFF\xD8\xFF") {
            error_log('Ignoring invalid or oversized notification image.');
            return null;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::run('INSERT INTO notification_images (service_id, kind, image_data, public_token, created_utc) VALUES (?, ?, ?, ?, ?) '
            . 'ON CONFLICT(service_id, kind) DO UPDATE SET image_data = excluded.image_data, '
            . 'public_token = excluded.public_token, created_utc = excluded.created_utc',
            [$serviceId, $kind, base64_encode($bytes), $token, Clock::now()]);
        return self::url($serviceId, $kind);
    }

    public static function url(int $serviceId, string $kind): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        $token = Db::val('SELECT public_token FROM notification_images WHERE service_id = ? AND kind = ?', [$serviceId, $kind]);
        return is_string($token) && $token !== ''
            ? base_url() . '/notification-image/' . $serviceId . '/' . rawurlencode($kind) . '/' . rawurlencode($token)
            : null;
    }

    public static function read(int $serviceId, string $kind, string $token): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        $row = Db::one('SELECT image_data, public_token FROM notification_images WHERE service_id = ? AND kind = ?',
            [$serviceId, $kind]);
        if ($row === null || !hash_equals((string)$row['public_token'], $token)) {
            return null;
        }
        $bytes = base64_decode((string)$row['image_data'], true);
        return is_string($bytes) ? $bytes : null;
    }

    public static function cleanup(): void
    {
        Db::run('DELETE FROM notification_images WHERE created_utc < ?', [Clock::now() - Files::retentionDays() * 86400]);
    }
}

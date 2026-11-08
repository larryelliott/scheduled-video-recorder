<?php
/** Publishes a server-stored video through the configured Google Apps Script endpoint. */
final class YouTubePublisher
{
    public static function isConfigured(): bool
    {
        return self::endpoint() !== null && trim((string)Env::get('YOUTUBE_PLAYLIST_ID', '')) !== '';
    }

    public static function status(int $fileId): ?array
    {
        $file = Db::one('SELECT service_id, name FROM files WHERE id = ?', [$fileId]);
        return $file === null ? null : Db::one('SELECT * FROM youtube_publications WHERE service_id = ? AND file_name = ?',
            [(int)$file['service_id'], (string)$file['name']]);
    }

    public static function publish(int $fileId): array
    {
        $endpoint = self::endpoint();
        $playlistId = trim((string)Env::get('YOUTUBE_PLAYLIST_ID', ''));
        if ($endpoint === null || $playlistId === '') {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'YouTube publishing is not configured.'];
        }

        $video = Db::one('SELECT f.*, s.status, s.is_test FROM files f '
            . 'LEFT JOIN services s ON s.id = f.service_id WHERE f.id = ?', [$fileId]);
        if ($video === null) {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'The video is no longer on the server.'];
        }
        if ((int)($video['is_test'] ?? 0) === 1) {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'Test videos cannot be published.'];
        }
        if (($video['status'] ?? '') !== 'ready') {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'This video is not ready to publish yet.'];
        }
        $videoUrl = Files::publicUrl($fileId);
        if ($videoUrl === null) {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'Could not create a public link for this video.'];
        }
        $service = Services::get((int)$video['service_id']);
        if ($service === null) {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'The service for this video could not be found.'];
        }
        $serviceId = (int)$video['service_id'];
        $fileName = (string)$video['name'];
        $description = implode("\n\n", array_filter([
            trim((string)$service['description']),
            trim((string)$service['description2']),
        ], function ($line) {
            return $line !== '';
        }));
        $payload = json_encode([
            'videoUrl' => $videoUrl,
            'title' => Services::label($service),
            'description' => $description,
            'playlistId' => $playlistId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'Could not prepare the video for publishing.'];
        }

        $claim = self::claim($fileId, $serviceId, $fileName);
        if (!$claim['ok']) {
            return $claim;
        }

        $timeout = max(15, min(300, Env::int('YOUTUBE_PUBLISH_TIMEOUT', 120)));
        @set_time_limit($timeout + 15);
        try {
            $response = Http::request('POST', $endpoint, $payload, ['Content-Type: application/json'], $timeout);
        } catch (Throwable $e) {
            error_log('YouTube publishing request failed: ' . $e->getMessage());
            return self::finish($serviceId, $fileName, 'unknown', 'The publishing response could not be confirmed. Check YouTube before taking further action.');
        }
        if ($response === null) {
            if (Http::$error !== '') {
                error_log('YouTube publishing response unavailable: ' . Http::$error);
            }
            return self::finish($serviceId, $fileName, 'unknown', 'The publishing response could not be confirmed. Check YouTube before taking further action.');
        }
        $result = json_decode($response, true);
        if (!is_array($result)) {
            return self::finish($serviceId, $fileName, 'unknown', 'The publishing service returned an unrecognized response. Check YouTube before taking further action.');
        }

        $state = strtolower((string)($result['status'] ?? ''));
        $success = ($result['ok'] ?? null) === true || ($result['success'] ?? null) === true
            || in_array($state, ['success', 'published'], true);
        if ($success) {
            $videoId = (string)($result['videoId'] ?? $result['video_id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $videoId)) {
                $videoId = '';
            }
            $saved = Db::run("UPDATE youtube_publications SET state = 'published', published_utc = ?, youtube_video_id = ?, error = '' "
                . "WHERE service_id = ? AND file_name = ? AND state = 'publishing'",
                [Clock::now(), $videoId !== '' ? $videoId : null, $serviceId, $fileName]);
            if ($saved->rowCount() !== 1) {
                error_log('YouTube upload succeeded but its publication state was not saved.');
                return ['ok' => false, 'tone' => 'warn', 'message' => 'YouTube may have accepted the video, but the result was not saved. Check YouTube before taking further action.'];
            }
            return ['ok' => true, 'tone' => 'ok', 'message' => 'Video published to YouTube.'];
        }
        $failed = ($result['ok'] ?? null) === false || ($result['success'] ?? null) === false
            || in_array($state, ['error', 'failed', 'rejected'], true);
        if ($failed) {
            $message = trim((string)($result['error'] ?? $result['message'] ?? 'The publishing service rejected the video.'));
            return self::finish($serviceId, $fileName, 'failed', substr($message, 0, 500));
        }
        return self::finish($serviceId, $fileName, 'unknown', 'The publishing service returned an unrecognized response. Check YouTube before taking further action.');
    }

    public static function retryUnknown(int $fileId): array
    {
        $file = Db::one('SELECT service_id, name FROM files WHERE id = ?', [$fileId]);
        if ($file === null) {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'The video is no longer on the server.'];
        }
        $claimed = Db::run("UPDATE youtube_publications SET state = 'failed', error = 'Admin confirmed no upload exists' "
            . "WHERE service_id = ? AND file_name = ? AND state = 'unknown'",
            [(int)$file['service_id'], (string)$file['name']]);
        if ($claimed->rowCount() !== 1) {
            return ['ok' => false, 'tone' => 'warn', 'message' => 'This video is no longer awaiting an outcome check. Reload the page.'];
        }
        return self::publish($fileId);
    }

    private static function endpoint(): ?string
    {
        $url = trim((string)Env::get('GOOGLE_APPS_SCRIPT_URL', ''));
        $parts = $url !== '' ? parse_url($url) : false;
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || trim((string)($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        return $url;
    }

    private static function claim(int $fileId, int $serviceId, string $fileName): array
    {
        $now = Clock::now();
        $insert = Db::run("INSERT INTO youtube_publications (file_id, service_id, file_name, state, attempted_utc) "
            . "VALUES (?, ?, ?, 'publishing', ?) ON CONFLICT(service_id, file_name) DO NOTHING",
            [$fileId, $serviceId, $fileName, $now]);
        if ($insert->rowCount() === 1) {
            return ['ok' => true];
        }
        $retry = Db::run("UPDATE youtube_publications SET state = 'publishing', attempted_utc = ?, published_utc = NULL, "
            . "youtube_video_id = NULL, error = '', file_id = ? WHERE service_id = ? AND file_name = ? AND state = 'failed'",
            [$now, $fileId, $serviceId, $fileName]);
        if ($retry->rowCount() === 1) {
            return ['ok' => true];
        }
        $current = Db::one('SELECT * FROM youtube_publications WHERE service_id = ? AND file_name = ?', [$serviceId, $fileName]);
        if ($current !== null && $current['state'] === 'published') {
            return ['ok' => false, 'tone' => 'warn', 'message' => 'This video has already been published.'];
        }
        if ($current !== null && $current['state'] === 'unknown') {
            return ['ok' => false, 'tone' => 'bad', 'message' => 'The last publishing outcome is unknown. Check YouTube; another attempt is blocked to prevent a duplicate.'];
        }
        return ['ok' => false, 'tone' => 'warn', 'message' => 'A publishing attempt is already in progress.'];
    }

    private static function finish(int $serviceId, string $fileName, string $state, string $message): array
    {
        $updated = Db::run('UPDATE youtube_publications SET state = ?, error = ? WHERE service_id = ? AND file_name = ? AND state = \'publishing\'',
            [$state, $message, $serviceId, $fileName]);
        if ($updated->rowCount() !== 1) {
            error_log('Could not save YouTube publication outcome.');
            return ['ok' => false, 'tone' => 'warn', 'message' => 'The publishing outcome could not be saved. Check YouTube before taking further action.'];
        }
        return ['ok' => false, 'tone' => $state === 'failed' ? 'bad' : 'warn', 'message' => $message];
    }
}

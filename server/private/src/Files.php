<?php
/** Videos stored on the web server: retention, pinning, uploads and Range-capable downloads. */
final class Files
{
    public static function dir(): string
    {
        $d = Env::get('VIDEO_DIR') ?: (APP_DIR . '/data/videos');
        if (!is_dir($d)) {
            mkdir($d, 0700, true);
        }
        return $d;
    }

    public static function retentionDays(): int
    {
        return max(1, Env::int('FILE_RETENTION_DAYS', 14));
    }

    public static function expiresAt(int $createdUtc): int
    {
        return $createdUtc + self::retentionDays() * 86400;
    }

    public static function pinnedNames(): array
    {
        return array_map('strval', array_column(Db::all('SELECT name FROM pins'), 'name'));
    }

    public static function isPinned(string $name): bool
    {
        return Db::val('SELECT 1 FROM pins WHERE name = ?', [$name]) !== null;
    }

    public static function togglePin(string $name): bool
    {
        if (self::isPinned($name)) {
            Db::run('DELETE FROM pins WHERE name = ?', [$name]);
            return false;
        }
        Db::run('INSERT INTO pins (name, pinned_utc) VALUES (?, ?)', [$name, Clock::now()]);
        return true;
    }

    public static function replaceAgentFiles(array $files): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run('DELETE FROM agent_files');
            foreach ($files as $f) {
                if (!is_array($f) || empty($f['name'])) {
                    continue;
                }
                Db::run('INSERT OR REPLACE INTO agent_files (name, kind, size, mtime_utc) VALUES (?, ?, ?, ?)',
                    [substr((string)$f['name'], 0, 300), substr((string)($f['kind'] ?? 'file'), 0, 20),
                     (int)($f['size'] ?? 0), (int)($f['mtime_utc'] ?? 0)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Streams an upload that was already copied to $tmpPath, then registers it. */
    public static function register(int $serviceId, string $name, string $tmpPath): array
    {
        $name = safe_filename($name);
        if ($name === '' || !preg_match('/\.(mp4|mkv|mov)$/i', $name)) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => 'File name must end in .mp4'];
        }
        $dest = self::dir() . '/' . $serviceId . '-' . $name;
        if (!rename($tmpPath, $dest)) {
            @unlink($tmpPath);
            return ['ok' => false, 'error' => 'Could not store the file'];
        }
        $old = Db::all('SELECT id, path FROM files WHERE service_id = ? AND name = ?', [$serviceId, $name]);
        foreach ($old as $o) {
            if ($o['path'] !== $dest) {
                @unlink($o['path']);
            }
            Db::run('DELETE FROM files WHERE id = ?', [(int)$o['id']]);
        }
        $size = (int)filesize($dest);
        Db::run('INSERT INTO files (service_id, name, path, size, created_utc) VALUES (?, ?, ?, ?, ?)',
            [$serviceId, $name, $dest, $size, Clock::now()]);
        $fileId = Db::id();
        Db::run('UPDATE youtube_publications SET file_id = ? WHERE service_id = ? AND file_name = ?',
            [$fileId, $serviceId, $name]);
        return ['ok' => true, 'size' => $size, 'file_id' => $fileId];
    }

    public static function forService(int $serviceId): array
    {
        return Db::all('SELECT * FROM files WHERE service_id = ? ORDER BY id DESC', [$serviceId]);
    }

    public static function publicToken(int $fileId): ?string
    {
        $f = Db::one('SELECT id, public_token FROM files WHERE id = ?', [$fileId]);
        if ($f === null) {
            return null;
        }
        if ($f['public_token'] !== null && $f['public_token'] !== '') {
            return (string)$f['public_token'];
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::run('UPDATE files SET public_token = ? WHERE id = ? AND public_token IS NULL', [$token, $fileId]);
        $stored = Db::val('SELECT public_token FROM files WHERE id = ?', [$fileId]);
        return $stored === null ? null : (string)$stored;
    }

    public static function publicUrl(int $fileId): ?string
    {
        $token = self::publicToken($fileId);
        return $token === null ? null : base_url() . '/public-video/' . $fileId . '/' . rawurlencode($token);
    }

    public static function publicFile(int $fileId, string $token): ?array
    {
        $f = Db::one('SELECT * FROM files WHERE id = ?', [$fileId]);
        if ($f === null || !is_file((string)$f['path']) || !is_string($f['public_token']) || $f['public_token'] === ''
            || !hash_equals((string)$f['public_token'], $token)) {
            return null;
        }
        return $f;
    }

    public static function allServerFiles(): array
    {
        return Db::all('SELECT * FROM files ORDER BY created_utc DESC');
    }

    /** Deletes server files older than the retention period unless pinned. Returns names removed. */
    public static function cleanup(): array
    {
        $removed = [];
        $now = Clock::now();
        foreach (Db::all('SELECT * FROM files') as $f) {
            if (self::expiresAt((int)$f['created_utc']) > $now || self::isPinned($f['name'])) {
                continue;
            }
            @unlink($f['path']);
            Db::run('DELETE FROM files WHERE id = ?', [(int)$f['id']]);
            $removed[] = $f['name'];
        }
        return $removed;
    }

    public static function delete(int $fileId): void
    {
        $f = Db::one('SELECT * FROM files WHERE id = ?', [$fileId]);
        if ($f) {
            @unlink($f['path']);
            Db::run('DELETE FROM files WHERE id = ?', [$fileId]);
        }
    }

    /** Download with HTTP Range support (iPhone Safari needs it for playback and resumable saves). */
    public static function stream(string $path, string $downloadName, bool $inline): void
    {
        if (!is_file($path)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }
        $size = (int)filesize($path);
        $start = 0;
        $end = $size - 1;
        $code = 200;
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int)$m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            $code = 206;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @set_time_limit(0);
        http_response_code($code);
        $ext = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
        $contentType = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=0');
        header('Content-Length: ' . ($end - $start + 1));
        if ($code === 206) {
            header("Content-Range: bytes $start-$end/$size");
        }
        $disp = $inline ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disp . '; filename="' . addslashes($downloadName) . '"');
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, min(1048576, $left));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
    }
}

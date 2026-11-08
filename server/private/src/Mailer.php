<?php
/**
 * Sends email through your own SMTP account when SMTP_HOST is set, otherwise through PHP's mail().
 * Shared hosts often block or spam-folder mail(); authenticated SMTP is far more reliable.
 */
final class Mailer
{
    public static string $lastError = '';

    public static function from(): string
    {
        $from = trim((string)Env::get('MAIL_FROM', ''));
        if ($from === '') {
            $user = trim((string)Env::get('SMTP_USER', ''));
            $from = strpos($user, '@') !== false ? $user : 'noreply@' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
        }
        return $from;
    }

    public static function describe(): string
    {
        $host = trim((string)Env::get('SMTP_HOST', ''));
        return $host === '' ? 'PHP mail() (set SMTP_HOST in .env for more reliable delivery)'
            : 'SMTP via ' . $host . ':' . Env::int('SMTP_PORT', 587);
    }

    public static function send(string $to, string $subject, string $body, ?string $imageUrl = null): bool
    {
        self::$lastError = '';
        try {
            $host = trim((string)Env::get('SMTP_HOST', ''));
            if ($host === '') {
                if ($imageUrl !== null) {
                    $boundary = 'vm_' . bin2hex(random_bytes(12));
                    $headers = 'From: Video Manager <' . self::addr(self::from()) . ">\r\nMIME-Version: 1.0\r\n"
                        . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                    $message = self::multipartBody($body, $imageUrl, $boundary);
                } else {
                    $headers = 'From: Video Manager <' . self::addr(self::from()) . ">\r\nContent-Type: text/plain; charset=UTF-8";
                    $message = $body;
                }
                $ok = @mail(self::addr($to), self::encodeHeader($subject), $message, $headers);
                if (!$ok) {
                    self::$lastError = 'PHP mail() refused the message. Set SMTP_HOST, SMTP_USER and SMTP_PASS in .env to send through a real mailbox.';
                }
                return $ok;
            }
            return self::smtp($host, self::addr($to), self::addr(self::from()), $subject, $body, $imageUrl);
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    private static function smtp(string $host, string $to, string $from, string $subject, string $body, ?string $imageUrl): bool
    {
        $port = Env::int('SMTP_PORT', 587);
        $secure = strtolower(trim((string)Env::get('SMTP_SECURE', 'tls'))); // tls = STARTTLS (587), ssl = implicit (465), none
        $user = (string)Env::get('SMTP_USER', '');
        $pass = (string)Env::get('SMTP_PASS', '');
        $verify = Env::get('SMTP_VERIFY_PEER', '1') !== '0';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("Could not connect to $host:$port ($errstr)");
        }
        stream_set_timeout($fp, 15);
        $me = (string)(parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
        try {
            self::expect($fp, [220]);
            self::cmd($fp, "EHLO $me", [250]);
            if ($secure === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('The secure (STARTTLS) handshake failed. Check SMTP_PORT and SMTP_SECURE; SMTP_VERIFY_PEER=0 skips certificate checks.');
                }
                self::cmd($fp, "EHLO $me", [250]);
            }
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334], 'sending the username');
                self::cmd($fp, base64_encode($pass), [235], 'sending the password');
            }
            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            $headers = [
                'Date: ' . date('r'),
                'From: Video Manager <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $me . '>',
                'MIME-Version: 1.0',
            ];
            if ($imageUrl !== null) {
                $boundary = 'vm_' . bin2hex(random_bytes(12));
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $message = self::multipartBody($body, $imageUrl, $boundary);
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $message = chunk_split(base64_encode($body), 76, "\r\n");
            }
            // Base64 parts: no line can start with "." so SMTP dot-stuffing is unnecessary.
            fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $message . ".\r\n");
            self::expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
            return true;
        } finally {
            fclose($fp);
        }
    }

    private static function cmd($fp, string $line, array $ok, ?string $label = null): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $ok, $label ?? $line);
    }

    private static function expect($fp, array $ok, string $sent = ''): void
    {
        $text = '';
        $code = 0;
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) >= 4 && $line[3] !== '-') {
                $code = (int)substr($line, 0, 3);
                break;
            }
            if (strlen($line) < 4) {
                break;
            }
        }
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('The mail server said: ' . ($text !== '' ? trim($text) : 'nothing (timed out)') . ($sent !== '' ? " (after: $sent)" : ''));
        }
    }

    private static function addr(string $a): string
    {
        return (string)preg_replace('/[^A-Za-z0-9@._+\-]/', '', $a);
    }

    private static function encodeHeader(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private static function multipartBody(string $body, string $imageUrl, string $boundary): string
    {
        $safeBody = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><body><p>' . nl2br($safeBody, false)
            . '</p><p><img src="' . $safeUrl . '" alt="Video preview" style="max-width:100%;height:auto"></p></body></html>';
        return '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n")
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
    }
}

<?php
/** Tiny .env reader. Values may also come from real environment variables. */
final class Env
{
    private static array $v = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $p = strpos($line, '=');
            if ($p === false) {
                continue;
            }
            $k = trim(substr($line, 0, $p));
            $val = trim(substr($line, $p + 1));
            $n = strlen($val);
            if ($n >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$n - 1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            self::$v[$k] = $val;
        }
    }

    public static function get(string $k, ?string $default = null): ?string
    {
        if (array_key_exists($k, self::$v)) {
            return self::$v[$k];
        }
        $e = getenv($k);
        return ($e !== false) ? $e : $default;
    }

    public static function int(string $k, int $default): int
    {
        $x = self::get($k);
        return ($x === null || $x === '') ? $default : (int)$x;
    }

    public static function set(string $k, string $val): void
    {
        self::$v[$k] = $val;
    }
}

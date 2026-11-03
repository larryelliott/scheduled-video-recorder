<?php
/** SQLite access (PDO). Integers are bound as integers so SQL comparisons behave. */
final class Db
{
    public const SCHEMA_VERSION = 1;
    private static ?PDO $pdo = null;

    public static function open(string $path): PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 8000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        self::migrate($pdo);
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::open(Env::get('DB_PATH') ?: (APP_DIR . '/data/app.sqlite'));
        }
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($ver >= self::SCHEMA_VERSION) {
            return;
        }
        $pdo->exec((string)file_get_contents(APP_DIR . '/schema.sql'));
        $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $v) {
            if (is_int($v)) {
                $st->bindValue($i, $v, PDO::PARAM_INT);
            } elseif ($v === null) {
                $st->bindValue($i, null, PDO::PARAM_NULL);
            } elseif (is_bool($v)) {
                $st->bindValue($i, $v ? 1 : 0, PDO::PARAM_INT);
            } else {
                $st->bindValue($i, $v);
            }
            $i++;
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function id(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function setting(string $k, ?string $default = null): ?string
    {
        $v = self::val('SELECT v FROM settings WHERE k = ?', [$k]);
        return $v === null ? $default : (string)$v;
    }

    public static function setSetting(string $k, string $v): void
    {
        self::run('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
    }
}

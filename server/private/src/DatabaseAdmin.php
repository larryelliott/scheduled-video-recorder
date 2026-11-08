<?php
/** Cautious, admin-only row editor for selected application tables. */
final class DatabaseAdmin
{
    private const TABLES = [
        'services', 'light_events', 'youtube_publications', 'agent_files', 'pins', 'notif_log', 'settings',
    ];

    public static function tables(): array
    {
        return self::TABLES;
    }

    public static function hasTable(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    public static function columns(string $table): array
    {
        self::checkTable($table);
        $quoted = self::quote($table);
        return Db::pdo()->query('PRAGMA table_info(' . $quoted . ')')->fetchAll();
    }

    public static function primaryKeys(string $table): array
    {
        $cols = array_values(array_filter(self::columns($table), static function ($col) {
            return (int)$col['pk'] > 0;
        }));
        usort($cols, static function ($a, $b) {
            return (int)$a['pk'] <=> (int)$b['pk'];
        });
        return array_column($cols, 'name');
    }

    public static function rows(string $table): array
    {
        $keys = self::primaryKeys($table);
        if ($keys === []) {
            throw new InvalidArgumentException('This table cannot be edited because it has no primary key.');
        }
        $order = implode(', ', array_map([self::class, 'quote'], $keys));
        return Db::all('SELECT * FROM ' . self::quote($table) . ' ORDER BY ' . $order . ' DESC LIMIT 100');
    }

    public static function row(string $table, array $key): ?array
    {
        $keys = self::primaryKeys($table);
        if (array_keys($key) !== $keys) {
            throw new InvalidArgumentException('Invalid row key.');
        }
        $where = implode(' AND ', array_map(static function ($col) {
            return self::quote($col) . ' = ?';
        }, $keys));
        return Db::one('SELECT * FROM ' . self::quote($table) . ' WHERE ' . $where, array_values($key));
    }

    public static function decodeKey(string $encoded, string $table): array
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        $key = $raw === false ? null : json_decode($raw, true);
        if (!is_array($key) || array_is_list($key) || array_keys($key) !== self::primaryKeys($table)) {
            throw new InvalidArgumentException('Invalid row key.');
        }
        return $key;
    }

    public static function encodeKey(string $table, array $row): string
    {
        $key = [];
        foreach (self::primaryKeys($table) as $column) {
            $key[$column] = $row[$column];
        }
        return rtrim(strtr(base64_encode((string)json_encode($key, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)), '+/', '-_'), '=');
    }

    public static function save(string $table, ?array $key, array $input): void
    {
        $columns = self::columns($table);
        $keys = self::primaryKeys($table);
        if ($keys === []) {
            throw new InvalidArgumentException('This table has no primary key and cannot be edited.');
        }
        if ($key !== null && array_keys($key) !== $keys) {
            throw new InvalidArgumentException('Invalid row key.');
        }
        $values = [];
        foreach ($columns as $col) {
            $name = (string)$col['name'];
            if ($key !== null && in_array($name, $keys, true)) {
                continue;
            }
            if ($key === null && count($keys) === 1 && $name === $keys[0]
                && strtoupper((string)$col['type']) === 'INTEGER') {
                continue;
            }
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $raw = trim((string)$input[$name]);
            if ($raw === '' && $col['dflt_value'] !== null) {
                continue;
            }
            if ($raw === '' && (int)$col['notnull'] === 0) {
                $values[$name] = null;
                continue;
            }
            $type = strtoupper((string)$col['type']);
            if (strpos($type, 'INT') !== false) {
                $parsed = filter_var($raw, FILTER_VALIDATE_INT);
                if ($parsed === false) {
                    throw new InvalidArgumentException($name . ' must be a whole number.');
                }
                $values[$name] = $parsed;
            } elseif (preg_match('/REAL|FLOA|DOUB|NUM/', $type)) {
                if ($raw !== '' && !is_numeric($raw)) {
                    throw new InvalidArgumentException($name . ' must be a number.');
                }
                $values[$name] = $raw === '' ? 0 : (float)$raw;
            } else {
                $values[$name] = (string)$input[$name];
            }
        }
        if ($values === []) {
            throw new InvalidArgumentException('Enter at least one value.');
        }
        $pdo = Db::pdo();
        if ($key === null) {
            $names = array_keys($values);
            Db::run('INSERT INTO ' . self::quote($table) . ' (' . implode(', ', array_map([self::class, 'quote'], $names))
                . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')', array_values($values));
            return;
        }
        $assignments = implode(', ', array_map(static function ($name) {
            return self::quote($name) . ' = ?';
        }, array_keys($values)));
        $where = implode(' AND ', array_map(static function ($name) {
            return self::quote($name) . ' = ?';
        }, $keys));
        Db::run('UPDATE ' . self::quote($table) . ' SET ' . $assignments . ' WHERE ' . $where,
            array_merge(array_values($values), array_values($key)));
    }

    public static function delete(string $table, array $key): void
    {
        $keys = self::primaryKeys($table);
        if ($keys === [] || array_keys($key) !== $keys) {
            throw new InvalidArgumentException('Invalid row key.');
        }
        $where = implode(' AND ', array_map(static function ($col) {
            return self::quote($col) . ' = ?';
        }, $keys));
        Db::run('DELETE FROM ' . self::quote($table) . ' WHERE ' . $where, array_values($key));
    }

    private static function checkTable(string $table): void
    {
        if (!self::hasTable($table)) {
            throw new InvalidArgumentException('That table is not available in the cautious editor.');
        }
    }

    private static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

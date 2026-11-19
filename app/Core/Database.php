<?php
final class Database {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        global $config;
        if (self::$pdo) return self::$pdo;
        $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
        if ($config['db_driver'] === 'sqlite') {
            self::$pdo = new PDO('sqlite:' . $config['sqlite_path'], null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            // Chosen from a short list, never interpolated from raw configuration.
            $journal = strtolower((string)($config['sqlite_journal_mode'] ?? 'wal'));
            if (!in_array($journal, ['wal', 'delete', 'truncate', 'persist'], true)) $journal = 'wal';
            self::$pdo->exec('PRAGMA journal_mode = ' . $journal);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']);
            self::$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
            self::$pdo->exec("SET time_zone = '+01:00'");
        }
        return self::$pdo;
    }
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    public static function all(string $sql, array $params = []): array { return self::query($sql, $params)->fetchAll(); }
    public static function one(string $sql, array $params = []): ?array { return self::query($sql, $params)->fetch() ?: null; }
    public static function value(string $sql, array $params = []): mixed { return self::query($sql, $params)->fetchColumn(); }
    public static function insert(string $table, array $data): int {
        self::identifier($table);
        $columns = array_keys($data);
        foreach ($columns as $column) self::identifier($column);
        self::query('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
        return (int)self::connection()->lastInsertId();
    }
    public static function update(string $table, array $data, int $id): void {
        self::identifier($table);
        $sets = [];
        foreach (array_keys($data) as $column) { self::identifier($column); $sets[] = "$column = ?"; }
        self::query('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE id = ?', [...array_values($data), $id]);
    }
    public static function identifier(string $name): void {
        // \z, not $: a bare $ also matches before a trailing newline.
        if (!preg_match('/^[a-z_]+\z/', $name)) throw new InvalidArgumentException('Invalid database identifier.');
    }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
}

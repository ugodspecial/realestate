<?php
declare(strict_types=1);
// Versioned database migrations. Fresh installs and upgrades share one path:
// install.php runs the migrator on an empty database, bin/upgrade.php runs it
// on an existing one. A migration file database/migrations/NNN_name.php must
// return ['description' => string, 'up' => callable(PDO, string): void]; the
// callable receives the PDO handle and the driver name ('mysql'/'sqlite').
// Applied migrations are tracked in the migrations table and must never be
// edited afterwards — every change ships as a new migration instead.
final class Migrator {
    public const TABLE = 'migrations';

    public static function dir(): string { return ROOT_PATH . '/database/migrations'; }

    public static function ensureTable(PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (version VARCHAR(12) PRIMARY KEY, description VARCHAR(190) NOT NULL, checksum CHAR(40) NOT NULL, applied_at DATETIME NOT NULL, duration_ms INTEGER NOT NULL DEFAULT 0)');
    }

    /** @return array<int, array{version: string, file: string, description: string, checksum: string}> */
    public static function files(string $dir): array {
        $found = [];
        foreach ((glob($dir . '/*.php') ?: []) as $file) {
            $base = basename($file);
            if (!preg_match('/^(\d{3})_[a-z0-9_]+\.php$/', $base, $m)) {
                throw new RuntimeException("Invalid migration filename: {$base} (expected NNN_description.php).");
            }
            $def = require $file;
            if (!is_array($def) || !isset($def['description'], $def['up']) || !is_callable($def['up'])) {
                throw new RuntimeException("Invalid migration {$base}: must return ['description' => ..., 'up' => callable].");
            }
            $found[] = ['version' => $m[1], 'file' => $file, 'description' => (string) $def['description'], 'checksum' => (string) sha1_file($file)];
        }
        usort($found, static fn($a, $b) => $a['version'] <=> $b['version']);
        $versions = array_column($found, 'version');
        if (count($versions) !== count(array_unique($versions))) {
            throw new RuntimeException('Duplicate migration version detected.');
        }
        return $found;
    }

    /** @return array<string, array{description: string, checksum: string, applied_at: string}> */
    public static function applied(PDO $pdo): array {
        self::ensureTable($pdo);
        $rows = [];
        $stmt = $pdo->query('SELECT version, description, checksum, applied_at FROM ' . self::TABLE . ' ORDER BY version');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['version']] = $row;
        return $rows;
    }

    /** Zero-padded applied versions in order (array keys alone would lose the padding). @return string[] */
    public static function versions(PDO $pdo): array {
        return array_map(static fn($v) => str_pad((string) $v, 3, '0', STR_PAD_LEFT), array_keys(self::applied($pdo)));
    }

    /** @return array<int, array{version: string, file: string, description: string, checksum: string}> */
    public static function pending(PDO $pdo, string $dir): array {
        $done = self::applied($pdo);
        return array_values(array_filter(self::files($dir), static fn($m) => !isset($done[$m['version']])));
    }

    /** Warn (do not fail) when an applied migration file changed since it ran. @return string[] */
    public static function verify(PDO $pdo, string $dir): array {
        $done = self::applied($pdo);
        $warnings = [];
        foreach (self::files($dir) as $m) {
            if (isset($done[$m['version']]) && !hash_equals($done[$m['version']]['checksum'], $m['checksum'])) {
                $warnings[] = "Migration {$m['version']} changed since it was applied on {$done[$m['version']]['applied_at']} — applied migrations must not be edited; ship a new one instead.";
            }
        }
        return $warnings;
    }

    /** Apply every pending migration in version order. Returns the applied versions. */
    public static function run(PDO $pdo, string $dir): array {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $applied = [];
        foreach (self::pending($pdo, $dir) as $m) {
            $def = require $m['file'];
            $outer = $pdo->inTransaction();
            if (!$outer) $pdo->beginTransaction();
            $start = hrtime(true);
            try {
                $def['up']($pdo, $driver);
                $ms = (int) ((hrtime(true) - $start) / 1000000);
                $stmt = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (version, description, checksum, applied_at, duration_ms) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$m['version'], $m['description'], $m['checksum'], gmdate('Y-m-d H:i:s'), $ms]);
                if (!$outer) $pdo->commit();
            } catch (Throwable $e) {
                if (!$outer && $pdo->inTransaction()) $pdo->rollBack();
                throw new RuntimeException("Migration {$m['version']} ({$m['description']}) failed: {$e->getMessage()}", 0, $e);
            }
            $applied[] = $m['version'];
        }
        return $applied;
    }
}

<?php
// 003 — Password lifecycle. Adds the flag that forces a password change at
// next sign-in (used when an administrator issues a temporary password) and
// the bookkeeping columns the password reset flow needs on password_resets.
// Every statement tolerates an already-upgraded database, because legacy
// installs adopt the baseline schema and may already carry these columns.
return [
    'description' => 'First-login password change and password reset support',
    'up' => static function (PDO $pdo, string $driver): void {
        $hasColumn = static function (string $table, string $column) use ($pdo, $driver): bool {
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $stmt->execute([$table, $column]);
                return (int) $stmt->fetchColumn() > 0;
            }
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strcasecmp((string) $row['name'], $column) === 0) return true;
            }
            return false;
        };
        $hasTable = static function (string $table) use ($pdo, $driver): bool {
            if ($driver !== 'mysql') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
                $stmt->execute([$table]);
                return (int) $stmt->fetchColumn() > 0;
            }
            try {
                $pdo->query('SELECT 1 FROM ' . $table . ' WHERE 1=0');
                return true;
            } catch (PDOException) {
                return false;
            }
        };

        // Staff who are given a password must choose their own before they
        // can use the dashboard.
        if (!$hasColumn('users', 'must_change_password')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0');
        }

        // password_resets already existed for token storage. These columns
        // record when a token was issued and when it was spent, so a reset
        // link is single-use and the trail survives the token being deleted.
        if ($hasTable('password_resets')) {
            if (!$hasColumn('password_resets', 'created_at')) {
                $pdo->exec('ALTER TABLE password_resets ADD COLUMN created_at DATETIME NULL');
            }
            if (!$hasColumn('password_resets', 'used_at')) {
                $pdo->exec('ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL');
            }
        }
    },
];

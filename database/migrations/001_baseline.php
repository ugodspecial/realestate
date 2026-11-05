<?php
// 001 — Baseline schema. Creates every table and index; safe to run on legacy
// databases because each statement tolerates already-existing objects.
return [
    'description' => 'Baseline schema (all tables and indexes)',
    'up' => static function (PDO $pdo, string $driver): void {
        [$tables, $indexes] = (require ROOT_PATH . '/database/schema.php')($driver);
        foreach ($tables as $sql) $pdo->exec($sql);
        foreach ($indexes as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'duplicate') && !str_contains($msg, 'already exists')) throw $e;
            }
        }
    },
];

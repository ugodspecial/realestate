<?php
// Fixture for tests/migrations.php only. Never runs against the real database.
return [
    'description' => 'Fixture: create notes table',
    'up' => static function (PDO $pdo, string $driver): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)');
    },
];

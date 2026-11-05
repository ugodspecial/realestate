<?php
// Fixture for tests/migrations.php only. Never runs against the real database.
return [
    'description' => 'Fixture: index notes body',
    'up' => static function (PDO $pdo, string $driver): void {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_body ON notes(body)');
    },
];

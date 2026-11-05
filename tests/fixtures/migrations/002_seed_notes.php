<?php
// Fixture for tests/migrations.php only. Never runs against the real database.
return [
    'description' => 'Fixture: seed notes rows',
    'up' => static function (PDO $pdo, string $driver): void {
        $stmt = $pdo->prepare('INSERT INTO notes (body) VALUES (?)');
        $stmt->execute(['first']);
        $stmt->execute(['second']);
    },
];

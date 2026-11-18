<?php
/**
 * Report whether the database matches the code. Safe to run at any time;
 * changes nothing.
 *
 * Usage: php bin/status.php
 *
 * Exists because a half-finished upgrade used to be invisible: the site kept
 * working, the new features quietly stayed off, and nothing said so.
 */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);
require dirname(__DIR__) . '/app/bootstrap.php';

$driver = 'unknown';
try {
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot reach the database: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Check the DB_* settings in config.local.php or the environment.' . PHP_EOL);
    exit(1);
}

echo 'Cedar Tranquility — application v' . APP_VERSION . ' (' . config('app_env') . ', ' . $driver . ')' . PHP_EOL;

try {
    $users = (int) Database::value('SELECT COUNT(*) FROM users');
} catch (Throwable) {
    fwrite(STDERR, PHP_EOL . 'The database has no schema yet.' . PHP_EOL);
    fwrite(STDERR, 'Run: php bin/install.php' . PHP_EOL);
    exit(1);
}

$dir = Migrator::dir();
$applied = Migrator::versions($pdo);
$pending = Migrator::pending($pdo, $dir);

echo 'Staff accounts:      ' . $users . PHP_EOL;
echo 'Migrations applied:  ' . count($applied) . ' (' . (implode(', ', $applied) ?: 'none') . ')' . PHP_EOL;
echo 'Migrations pending:  ' . count($pending) . PHP_EOL;

foreach (Migrator::verify($pdo, $dir) as $warning) echo 'WARNING: ' . $warning . PHP_EOL;

// The feature flags this release introduced. Reported so the operator can see
// exactly which capability is off, not just that something is out of date.
// The column can physically exist while the migration is still unrecorded
// (a partially applied upgrade). Report the effective state: the feature is
// only trustworthy when the migration is recorded AND the column is there.
$hasColumn = Auth::hasPasswordLifecycle();
$version003 = in_array('003', $applied, true);
$passwordLifecycle = $hasColumn && $version003 && $pending === [];
echo 'First-login password change: ' . ($passwordLifecycle
    ? 'available'
    : 'NOT available (' . ($hasColumn ? 'migration 003 not recorded' : 'migration 003 not applied') . ')') . PHP_EOL;

echo PHP_EOL;
if ($pending !== []) {
    echo 'The database is behind the code. New features that depend on pending' . PHP_EOL;
    echo 'migrations are switched off until you finish the upgrade.' . PHP_EOL;
    echo PHP_EOL;
    foreach ($pending as $m) echo '  - ' . $m['version'] . ' ' . $m['description'] . PHP_EOL;
    echo PHP_EOL . 'Run: php bin/upgrade.php' . PHP_EOL;
    echo 'If the backup step fails there, use: php bin/upgrade.php --backup-dir=/a/writable/path' . PHP_EOL;
    exit(1);
}

echo 'Up to date. Nothing to do.' . PHP_EOL;
exit(0);

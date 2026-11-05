<?php
/** Upgrade an existing site: back up the database, then apply pending migrations.
 * Usage: php bin/upgrade.php [--dry-run] [--no-backup] [--backup-dir=path]
 * --dry-run shows pending migrations without changing anything. --no-backup
 * skips the safety backup (CI/ephemeral copies only — never production). */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);
require dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['dry-run', 'no-backup', 'backup-dir:']);
$dryRun = isset($opts['dry-run']);
$noBackup = isset($opts['no-backup']);
$backupDir = $opts['backup-dir'] ?? ROOT_PATH . '/storage/backups';
$backupPath = null;

$fail = static function (string $msg): void { fwrite(STDERR, "ERROR: {$msg}" . PHP_EOL); exit(1); };

try {
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo 'Cedar Tranquility upgrade — app v' . APP_VERSION . ' (' . config('app_env') . ', ' . $driver . ')' . PHP_EOL;

    if ($driver === 'sqlite' && !is_file((string) config('sqlite_path'))) {
        $fail('No SQLite database found. Run bin/install.php first.');
    }
    try {
        $hasUsers = (int) Database::value('SELECT COUNT(*) FROM users') > 0;
    } catch (Throwable) {
        $hasUsers = false;
    }
    $applied = Migrator::applied($pdo);
    if (!$hasUsers && $applied === []) {
        $fail('No existing installation found (no users, no migrations). Run bin/install.php first.');
    }

    foreach (Migrator::verify($pdo, Migrator::dir()) as $warning) echo 'WARNING: ' . $warning . PHP_EOL;
    $pending = Migrator::pending($pdo, Migrator::dir());
    echo 'Migrations applied: ' . count($applied) . '; pending: ' . count($pending) . PHP_EOL;
    foreach ($pending as $m) echo "  - {$m['version']} {$m['description']}" . PHP_EOL;
    if ($dryRun) exit(0);

    if (!$noBackup) {
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) $fail("Cannot create backup dir {$backupDir}.");
        if (!is_writable($backupDir)) $fail("Backup dir {$backupDir} is not writable.");
        $stamp = gmdate('Ymd-His') . '-' . getmypid();
        if ($driver === 'sqlite') {
            $backupPath = $backupDir . '/db-' . $stamp . '.sqlite';
            $quoted = "'" . str_replace("'", "''", $backupPath) . "'";
            try {
                $pdo->exec("VACUUM INTO {$quoted}");
            } catch (PDOException $e) {
                if (!copy((string) config('sqlite_path'), $backupPath)) $fail('SQLite backup failed: ' . $e->getMessage());
            }
        } else {
            if (!function_exists('exec') || !function_exists('shell_exec')) {
                $fail('exec/shell_exec are disabled, so mysqldump cannot run. Take a manual backup, then re-run with --no-backup.');
            }
            $dump = trim((string) shell_exec('command -v mysqldump'));
            if ($dump === '') $fail('mysqldump was not found. Install a MySQL client, take a manual backup, then re-run with --no-backup.');
            $backupPath = $backupDir . '/db-' . $stamp . '.sql';
            $errFile = $backupPath . '.err';
            $cmd = escapeshellarg($dump)
                . ' --host=' . escapeshellarg((string) config('db_host'))
                . ' --port=' . escapeshellarg((string) config('db_port'))
                . ' --user=' . escapeshellarg((string) config('db_user'))
                . ' --password=' . escapeshellarg((string) config('db_pass'))
                . ' --single-transaction --routines --events --skip-comments '
                . escapeshellarg((string) config('db_name'))
                . ' > ' . escapeshellarg($backupPath) . ' 2> ' . escapeshellarg($errFile);
            exec($cmd, $out, $code);
            @unlink($errFile);
            if ($code !== 0 || !is_file($backupPath) || filesize($backupPath) === 0) {
                @unlink($backupPath);
                $fail('mysqldump failed (exit ' . $code . '). Check credentials and try again.');
            }
        }
        file_put_contents($backupPath . '.json', json_encode([
            'app_version' => APP_VERSION, 'driver' => $driver, 'created_at' => gmdate('c'),
            'migrations' => Migrator::versions($pdo),
        ], JSON_PRETTY_PRINT));
        echo "Backup written: {$backupPath}" . PHP_EOL;
    }

    $ran = Migrator::run($pdo, Migrator::dir());
    foreach ($ran as $version) echo "Applied migration {$version}" . PHP_EOL;
    if ($ran === []) echo 'Database is already current — nothing to apply.' . PHP_EOL;

    if (Migrator::pending($pdo, Migrator::dir()) !== []) $fail('Upgrade incomplete: migrations still pending.');
    $current = Migrator::versions($pdo);
    echo 'Upgrade complete. Current schema: ' . (end($current) ?: 'none') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    if ($backupPath) fwrite(STDERR, "Restore {$backupPath} to roll back, then retry. See docs/UPGRADING.md." . PHP_EOL);
    exit(1);
}

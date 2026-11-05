<?php
/** Migrator self-test on scratch SQLite databases: php tests/migrations.php (isolated, read/write). */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);

error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$phpIssues): bool {
    $phpIssues[] = "$errstr in $errfile:$errline";
    return true;
});

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$ok) $failures++;
}

$tmp = sys_get_temp_dir() . '/cedar-migrate-' . getmypid();
@mkdir($tmp, 0777, true);
$makeDb = static fn(string $name): PDO => new PDO('sqlite:' . $tmp . '/' . $name, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$copyDir = static function (string $src, string $dst) use (&$copyDir): void {
    @mkdir($dst, 0777, true);
    foreach ((glob($src . '/*') ?: []) as $file) {
        is_dir($file) ? $copyDir($file, $dst . '/' . basename($file)) : copy($file, $dst . '/' . basename($file));
    }
};

$fixtures = ROOT_PATH . '/tests/fixtures/migrations';

// 1. Happy path: ordered apply, tracking, data writes, idempotent re-run.
$pdo = $makeDb('a.sqlite');
$ran = Migrator::run($pdo, $fixtures);
check('applies fixtures in version order', $ran === ['001', '002', '010']);
check('data migration wrote rows', (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 2);
check('re-run is a no-op', Migrator::run($pdo, $fixtures) === []);
check('pending is empty afterwards', Migrator::pending($pdo, $fixtures) === []);
check('applied records carry checksums', count(Migrator::applied($pdo)) === 3);

// 2. Tamper detection: editing an applied migration warns instead of re-running.
$tampered = $tmp . '/tampered';
$copyDir($fixtures, $tampered);
$pdo2 = $makeDb('b.sqlite');
Migrator::run($pdo2, $tampered);
file_put_contents($tampered . '/002_seed_notes.php', file_get_contents($tampered . '/002_seed_notes.php') . "\n// edited after apply\n");
$warnings = Migrator::verify($pdo2, $tampered);
check('tampered migration warns', count($warnings) === 1 && str_contains($warnings[0], '002'));
check('tampered migration does not re-run', Migrator::run($pdo2, $tampered) === []);

// 3. Failure: a broken migration aborts, records nothing, preserves earlier work.
$broken = $tmp . '/broken';
$copyDir($fixtures, $broken);
file_put_contents($broken . '/004_broken.php', "<?php\nreturn ['description' => 'Broken', 'up' => static function (PDO \$pdo, string \$driver): void { throw new RuntimeException('boom'); }];\n");
$pdo3 = $makeDb('c.sqlite');
$thrown = null;
try {
    Migrator::run($pdo3, $broken);
} catch (Throwable $e) {
    $thrown = $e->getMessage();
}
check('broken migration aborts the run', $thrown !== null && str_contains($thrown, '004'));
check('failed migration is not recorded', !isset(Migrator::applied($pdo3)['004']));
check('earlier migrations survive failure', Migrator::versions($pdo3) === ['001', '002']);
check('failed run preserves data', (int) $pdo3->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 2);

// 4. Strict file validation.
$badName = $tmp . '/badname';
@mkdir($badName, 0777, true);
file_put_contents($badName . '/add_stuff.php', "<?php\nreturn ['description' => 'x', 'up' => static function (): void {}];\n");
$badNameThrown = false;
try {
    Migrator::files($badName);
} catch (RuntimeException) {
    $badNameThrown = true;
}
check('invalid filename is rejected', $badNameThrown);

$dup = $tmp . '/dup';
@mkdir($dup, 0777, true);
file_put_contents($dup . '/001_one.php', "<?php\nreturn ['description' => 'one', 'up' => static function (): void {}];\n");
file_put_contents($dup . '/001_two.php', "<?php\nreturn ['description' => 'two', 'up' => static function (): void {}];\n");
$dupThrown = false;
try {
    Migrator::files($dup);
} catch (RuntimeException) {
    $dupThrown = true;
}
check('duplicate version is rejected', $dupThrown);

// 5. Baseline tolerance: legacy databases (tables already exist) adopt cleanly.
$legacy = $tmp . '/legacy';
$copyDir($fixtures, $legacy);
$pdo4 = $makeDb('d.sqlite');
$pdo4->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)');
$pdo4->exec("INSERT INTO notes (body) VALUES ('pre-existing')");
$ranLegacy = Migrator::run($pdo4, $legacy);
check('legacy database adopts migrations', $ranLegacy === ['001', '002', '010']);
check('legacy data is preserved', (int) $pdo4->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 3);

foreach ($phpIssues as $issue) {
    $failures++;
    echo 'FAIL  clean runtime: ' . $issue . PHP_EOL;
}

// Best-effort cleanup of scratch files.
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach ((glob($path . '/*') ?: []) as $child) $remove($child);
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$remove($tmp);

exit($failures > 0 ? 1 : 0);

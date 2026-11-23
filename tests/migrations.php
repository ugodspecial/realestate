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

// 6. MySQL commits DDL implicitly, closing the runner's transaction early —
// the runner must tolerate that instead of throwing on commit.
$implicit = $tmp . '/implicit';
@mkdir($implicit, 0777, true);
file_put_contents($implicit . '/001_early_commit.php', "<?php\nreturn ['description' => 'early commit', 'up' => static function (PDO \$pdo, string \$driver): void { \$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)'); if (\$pdo->inTransaction()) \$pdo->commit(); }];\n");
$pdo5 = $makeDb('e.sqlite');
check('mid-migration commit does not break the runner', Migrator::run($pdo5, $implicit) === ['001'] && Migrator::versions($pdo5) === ['001']);

// 7. The production installer (bin/install.php), run for real on a scratch copy of the app and a fresh database.
// It stopped with "UNIQUE constraint failed: settings.key_name" on a fresh database: migration 002 writes the brand settings,
// and the installer then inserted them again. That happened after the administrator was created and before the contact email,
// the texts and the categories were saved, and a second run said "Schema is ready" and left it like that. (The demo install,
// which is what every other test uses, takes another route and never showed it.)
$installRoot = $tmp . '/install-app';
foreach (['app', 'database'] as $dir) $copyDir(ROOT_PATH . '/' . $dir, $installRoot . '/' . $dir);
@mkdir($installRoot . '/bin', 0777, true);
copy(ROOT_PATH . '/bin/install.php', $installRoot . '/bin/install.php');
@mkdir($installRoot . '/storage/logs', 0777, true);
@mkdir($installRoot . '/storage/sessions', 0777, true);
$runInstaller = static function (array $args) use ($installRoot): array {
    $saved = [];
    foreach (['APP_ENV' => 'local', 'DB_DRIVER' => 'sqlite', 'SQLITE_JOURNAL_MODE' => 'delete'] as $name => $value) {
        $saved[$name] = getenv($name);
        putenv($name . '=' . $value);
    }
    $lines = [];
    $code = -1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($installRoot . '/bin/install.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $code);
    foreach ($saved as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
    return ['out' => implode("\n", $lines), 'code' => $code];
};
$probeOut = [];
$probeCode = -1;
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo 7; exit(3);') . ' 2>&1', $probeOut, $probeCode);
if (($probeOut[0] ?? '') !== '7') {
    // A runner that cannot start a PHP (the WebAssembly one used for local runs) cannot run this; CI must never skip it.
    if (getenv('CI') !== false && getenv('CI') !== '') {
        check('the installer test can start a child PHP (it is required in CI)', false);
    } else {
        echo 'SKIP  the production installer :: cannot start a child PHP here' . PHP_EOL;
    }
} else {
    $exitsReported = $probeCode === 3; // some runners start a child but report every exit as 0: then only the exit-code checks are skipped
    $database = $installRoot . '/storage/cedar.sqlite';
    $password = $tmp . '/install-password.txt';
    file_put_contents($password, 'correct-horse-battery-staple');
    $rows = static function (string $sql) use ($database): array {
        $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $all = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $db = null;
        return $all;
    };
    $count = static fn(string $table): int => (int) ($rows('SELECT COUNT(*) FROM ' . $table)[0] ?? -1);

    $r = $runInstaller(['--email=' . "\"a\\\r\\\nBcc:v@example.net\"@example.com", '--password-file=' . $password]);
    check('the installer refuses an address that is not an ordinary one (a quoted local part with a line break), and creates nothing',
        str_contains($r['out'], 'Provide --email') && $count('users') === 0 && $count('categories') === 0);

    // A failure part-way: a category that is already there makes the last inserts fail, after the administrator and the settings.
    $settingsBefore = $count('settings');
    $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("INSERT INTO categories (name, slug, description, icon) VALUES ('Houses', 'houses', '', 'home')");
    $db = null;
    $r = $runInstaller(['--name=Ada Okafor', '--email=Owner@Example.com', '--password-file=' . $password]);
    check('a failure part-way says so and undoes everything: no administrator and no half of the settings are left behind',
        str_contains($r['out'], 'undone') && $count('users') === 0 && $count('settings') === $settingsBefore, $r['out']);
    if ($exitsReported) check('...and the exit code says it failed', $r['code'] === 1, (string) $r['code']);
    $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('DELETE FROM categories');
    $db = null;

    $r = $runInstaller(['--name=Ada Okafor', '--email=Owner@Example.com', '--password-file=' . $password]);
    check('a fresh database installs (this stopped at the first duplicate setting)', str_contains($r['out'], 'Super Administrator created') && !str_contains($r['out'], 'SQLSTATE'), $r['out']);
    if ($exitsReported) check('...with exit code 0', $r['code'] === 0, (string) $r['code']);
    check('...one Super Administrator, the address in lower case', $rows('SELECT email || "|" || role || "|" || name FROM users') === ['owner@example.com|super_admin|Ada Okafor']);
    $wanted = ['brand_name', 'brand_tagline', 'brand_logo', 'hero_title', 'hero_subtitle', 'email', 'notify_email', 'phone', 'whatsapp', 'address', 'office_hours',
               'about_title', 'about_summary', 'about_body', 'contact_title', 'contact_intro', 'footer_text'];
    $have = $rows('SELECT key_name FROM settings');
    check('...every default setting is saved, including the ones after the brand settings that stopped it', array_diff($wanted, $have) === [], json_encode(array_values(array_diff($wanted, $have))));
    check('...and the contact email, which the enquiry alert and the sender address start from, is the administrator\'s',
        $rows("SELECT value FROM settings WHERE key_name = 'email'") === ['owner@example.com'] && $rows("SELECT value FROM settings WHERE key_name = 'notify_email'") === ['owner@example.com']);
    check('...the brand settings a migration had written are kept, not written twice', $rows("SELECT value FROM settings WHERE key_name = 'brand_tagline'") === ['REAL ESTATE']);
    check('...and the five categories exist', $count('categories') === 5);
    $r = $runInstaller(['--email=second@example.com', '--password-file=' . $password]);
    check('running it again changes nothing and says so', str_contains($r['out'], 'Schema is ready') && $count('users') === 1 && $count('categories') === 5);
}

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

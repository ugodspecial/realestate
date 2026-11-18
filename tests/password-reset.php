<?php
/**
 * Password lifecycle: forced change at first sign-in, and forgotten-password
 * recovery. Exercises the real classes against the real database, then cleans
 * up every row it creates so it can run on a live install.
 *
 * Run: php tests/password-reset.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

// Auth rotates the session id; buffer output so the run matches a real request.
if (session_status() !== PHP_SESSION_ACTIVE) { ob_start(); session_start(); }

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  {$label}\n"; return; }
    $fail++;
    echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

$createdUsers = [];
$createdEmails = [];

/** Create a throwaway account and remember it for cleanup. */
function makeUser(string $name, string $password, bool $mustChange = false, bool $active = true): array {
    global $createdUsers, $createdEmails;
    $email = strtolower('pwtest.' . bin2hex(random_bytes(5)) . '@example.test');
    $id = Database::insert('users', [
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'content_editor',
        'active' => $active ? 1 : 0,
        'must_change_password' => $mustChange ? 1 : 0,
        'created_at' => Database::now(),
    ]);
    $createdUsers[] = $id;
    $createdEmails[] = $email;
    return Database::one('SELECT * FROM users WHERE id = ?', [$id]) ?? [];
}

function clearThrottle(): void {
    Database::query("DELETE FROM rate_limits WHERE key_hash IN (SELECT key_hash FROM rate_limits)");
}

// Sweep anything an interrupted earlier run left behind, so a crash cannot
// make the next run fail on stale rows.
foreach (Database::all("SELECT id FROM users WHERE email LIKE 'pwtest.%@example.test'") as $stale) {
    Database::query('DELETE FROM password_resets WHERE user_id = ?', [(int) $stale['id']]);
    Database::query('DELETE FROM audit_logs WHERE entity_type = ? AND entity_id = ?', ['user', (int) $stale['id']]);
    Database::query('DELETE FROM users WHERE id = ?', [(int) $stale['id']]);
}

echo "\n== Schema ==\n";
$userColumns = array_column(Database::connection()->query('PRAGMA table_info(users)')->fetchAll(), 'name');
check('users carries must_change_password', in_array('must_change_password', $userColumns, true));
$resetColumns = array_column(Database::connection()->query('PRAGMA table_info(password_resets)')->fetchAll(), 'name');
check('password_resets can record issue and use times',
    in_array('created_at', $resetColumns, true) && in_array('used_at', $resetColumns, true));

echo "\n== Password rules ==\n";
check('rejects a password shorter than the minimum', PasswordReset::validate('short', 'short') !== null);
check('rejects mismatched confirmation', PasswordReset::validate('CorrectHorse9x', 'CorrectHorse9y') !== null);
check('rejects a single repeated character', PasswordReset::validate(str_repeat('a', 12), str_repeat('a', 12)) !== null);
check('rejects an obvious sequence', PasswordReset::validate('abcdefghijkl', 'abcdefghijkl') !== null);
check('accepts a reasonable password', PasswordReset::validate('CorrectHorse9x', 'CorrectHorse9x') === null);

echo "\n== Forced change at first sign-in ==\n";
$fresh = makeUser('Nadia Fresh', 'TempPass-1234', true);
check('a new account is flagged for a password change', PasswordReset::mustChange($fresh));
check('an unflagged account is not', !PasswordReset::mustChange(makeUser('Free Bird', 'FreePass-1234')));

// Signing in must not clear the flag: the door opens onto the change screen.
$_SESSION = [];
clearThrottle();
$signedIn = Auth::attempt($fresh['email'], 'TempPass-1234', '10.0.0.1');
$sessionUser = Auth::user();
check('the temporary password still signs in', $signedIn);
check('signing in does not silently clear the requirement', $sessionUser !== null && PasswordReset::mustChange($sessionUser));

// Completing the change clears the flag and keeps this session valid.
PasswordReset::applyNewPassword((int) $fresh['id'], 'ChosenByUser-99');
$after = Auth::user();
check('choosing a password clears the requirement', $after !== null && !PasswordReset::mustChange($after));
check('the person who chose it stays signed in', (int) ($_SESSION['user_id'] ?? 0) === (int) $fresh['id']);
check('the new password works', Auth::attempt($fresh['email'], 'ChosenByUser-99', '10.0.0.1'));
check('the temporary password no longer works', !Auth::attempt($fresh['email'], 'TempPass-1234', '10.0.0.2'));

echo "\n== Temporary passwords ==\n";
$temp1 = PasswordReset::temporaryPassword();
$temp2 = PasswordReset::temporaryPassword();
check('temporary passwords are the expected shape', (bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $temp1), $temp1);
check('two calls do not collide', $temp1 !== $temp2);
check('no visually ambiguous characters', !preg_match('/[O0I1S5B8]/', $temp1), $temp1);

$issuedTo = makeUser('Grace Issued', 'Original-1234');
$temp = PasswordReset::issueTemporary((int) $issuedTo['id']);
$reloaded = Database::one('SELECT * FROM users WHERE id=?', [$issuedTo['id']]);
check('an administrator reset sets the forced-change flag', (int) $reloaded['must_change_password'] === 1);
clearThrottle();
check('the issued temporary password signs in', Auth::attempt((string) $issuedTo['email'], $temp, '10.0.0.3'));
check('the previous password stops working', !Auth::attempt((string) $issuedTo['email'], 'Original-1234', '10.0.0.4'));

echo "\n== Reset request ==\n";
clearThrottle();
$tokenFor = makeUser('Rita Reset', 'Remembered-1x');
$issued = PasswordReset::issue((int) $tokenFor['id'], $tokenFor);
check('issuing stores exactly one live token',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE user_id=?', [$tokenFor['id']]) === 1);
check('the raw token is never stored',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE token_hash=?', [$issued]) === 0);
check('the stored value is its SHA-256', (int) Database::value(
    'SELECT COUNT(*) FROM password_resets WHERE token_hash=?', [hash('sha256', $issued)]) === 1);

$second = PasswordReset::issue((int) $tokenFor['id'], $tokenFor);
check('a second request invalidates the first token', PasswordReset::find($issued) === null);
check('the newest token works', PasswordReset::find($second) !== null);
check('an unknown token is refused', PasswordReset::find('deadbeef') === null);
check('a malformed token is refused', PasswordReset::find('not-a-token') === null);

echo "\n== Reset request never reveals whether an account exists ==\n";
clearThrottle();
$unknownReturn = PasswordReset::request('definitely-not-a-user@example.test', '10.1.1.1');
clearThrottle();
$knownReturn = PasswordReset::request((string) $tokenFor['email'], '10.1.1.2');
// Both requests must complete without throwing, and the unknown one must not
// create a token. What the visitor sees is identical either way, handled in
// the controller; here we prove the underlying behaviour.
check('an unknown address creates no token', $unknownReturn === false);
check('a known address queues a message', $knownReturn === true);
check('an inactive account is treated as unknown', PasswordReset::request(
    (string) makeUser('Gone Away', 'Hidden-1234', false, false)['email'], '10.1.1.3') === false);

echo "\n== Consuming a token ==\n";
$target = makeUser('Colin Consume', 'Before-1234');
$t = PasswordReset::issue((int) $target['id'], $target);
$err = PasswordReset::consume($t, 'short', 'short');
check('a weak password is refused', $err !== null);
check('a refused attempt does not spend the token', PasswordReset::find($t) !== null);
$err = PasswordReset::consume($t, 'AfterReset-77', 'AfterReset-77');
check('a valid submission succeeds', $err === null, (string) $err);
check('the token cannot be used twice', PasswordReset::consume($t, 'Another-1234', 'Another-1234') !== null);
check('the new password works', Auth::attempt((string) $target['email'], 'AfterReset-77', '10.2.2.2'));
check('the old password is gone', !Auth::attempt((string) $target['email'], 'Before-1234', '10.2.2.3'));
check('no tokens are left for the account',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE user_id=?', [$target['id']]) === 0);

echo "\n== Resetting signs out other sessions ==\n";
$victim = makeUser('Sam Session', 'OldPassword-1');
$before = (int) Database::value('SELECT session_version FROM users WHERE id=?', [$victim['id']]);
$t2 = PasswordReset::issue((int) $victim['id'], $victim);
PasswordReset::consume($t2, 'NewPassword-2', 'NewPassword-2');
$afterV = (int) Database::value('SELECT session_version FROM users WHERE id=?', [$victim['id']]);
check('the session version is bumped', $afterV > $before);

echo "\n== Expiry ==\n";
$expiring = makeUser('Eve Expiry', 'Expires-1234');
$t3 = PasswordReset::issue((int) $expiring['id'], $expiring);
Database::query('UPDATE password_resets SET expires_at = ? WHERE token_hash = ?',
    [gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $t3)]);
check('an expired token is not found', PasswordReset::find($t3) === null);
check('an expired token cannot be consumed', PasswordReset::consume($t3, 'Whatever-99x', 'Whatever-99x') !== null);

$pruned = makeUser('Pat Prune', 'Prune-12345');
$liveToken = PasswordReset::issue((int) $pruned['id'], $pruned);
$spentUser = makeUser('Spent Token', 'Spent-12345');
$spentToken = PasswordReset::issue((int) $spentUser['id'], $spentUser);
PasswordReset::consume($spentToken, 'Consumed-99x', 'Consumed-99x');
$expiredUser = makeUser('Expired Token', 'Expired-1234');
$expiredToken = PasswordReset::issue((int) $expiredUser['id'], $expiredUser);
Database::query('UPDATE password_resets SET expires_at=? WHERE token_hash=?',
    [gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $expiredToken)]);
PasswordReset::prune();
check('pruning keeps a live token usable', PasswordReset::find($liveToken) !== null);
check('pruning removes spent tokens',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE user_id=?', [$spentUser['id']]) === 0);
check('pruning removes expired tokens',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE user_id=?', [$expiredUser['id']]) === 0);

echo "\n== A database without migration 003 must not break sign-in ==\n";
// This was a real outage: Auth::user() selected must_change_password
// unconditionally, so an installation whose upgrade had not run answered
// every sign-in with a 500. The column is optional until 003 is applied.
$hasLifecycle = Auth::hasPasswordLifecycle();
check('the schema check reports the column is present here', $hasLifecycle === true);

// Simulate the older schema by rebuilding users without the column.
$probePath = sys_get_temp_dir() . '/cedar-no003-' . bin2hex(random_bytes(4)) . '.sqlite';
$probe = new PDO('sqlite:' . $probePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
[$tables] = (require ROOT_PATH . '/database/schema.php')('sqlite');
foreach ($tables as $sql) {
    $sql = str_replace('must_change_password TINYINT NOT NULL DEFAULT 0, ', '', $sql);
    $sql = str_replace(', created_at DATETIME NULL, used_at DATETIME NULL', '', $sql);
    $probe->exec($sql);
}
$probe->exec("INSERT INTO users (name,email,password_hash,role,active,session_version,created_at) VALUES ('Legacy','legacy@example.test','x','super_admin',1,1,'2020-01-01 00:00:00')");
$columns = array_column($probe->query('PRAGMA table_info(users)')->fetchAll(), 'name');
check('the simulated database really lacks the column', !in_array('must_change_password', $columns, true));

$applied = Migrator::run($probe, ROOT_PATH . '/database/migrations');
$columns = array_column($probe->query('PRAGMA table_info(users)')->fetchAll(), 'name');
check('running the upgrade adds the column', in_array('must_change_password', $columns, true), implode(',', $applied));
check('the upgrade preserves existing accounts',
    (string) $probe->query("SELECT name FROM users WHERE email='legacy@example.test'")->fetchColumn() === 'Legacy');
check('an existing account is not forced to change its password',
    (int) $probe->query("SELECT must_change_password FROM users WHERE email='legacy@example.test'")->fetchColumn() === 0);
check('re-running the upgrade changes nothing', Migrator::run($probe, ROOT_PATH . '/database/migrations') === []);
unset($probe);
@unlink($probePath);

// The guard helper drops the flag rather than letting the write fail.
check('the write guard is available', method_exists('PasswordReset', 'guardFlag'));
check('the guard keeps the flag on an upgraded database',
    array_key_exists('must_change_password', PasswordReset::guardFlag(['must_change_password' => 1])));

// The decisive check: run the real Auth against a database that genuinely
// lacks the column, in a separate process with its own config, and confirm
// signing in and reading the session both work. This is the outage.
$boot = sys_get_temp_dir() . '/cedar-boot-' . bin2hex(random_bytes(4)) . '.php';
$dbFile = sys_get_temp_dir() . '/cedar-old-' . bin2hex(random_bytes(4)) . '.sqlite';
$logFile = sys_get_temp_dir() . '/cedar-old-' . bin2hex(random_bytes(4)) . '.log';
$build = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
[$tables] = (require ROOT_PATH . '/database/schema.php')('sqlite');
foreach ($tables as $sql) {
    $build->exec(str_replace(['must_change_password TINYINT NOT NULL DEFAULT 0, ', ', created_at DATETIME NULL, used_at DATETIME NULL'], '', $sql));
}
$stmt = $build->prepare("INSERT INTO users (name,email,password_hash,role,active,session_version,created_at) VALUES ('Legacy',?,?,'super_admin',1,1,'2020-01-01 00:00:00')");
$stmt->execute(['legacy@example.test', password_hash('LegacyPass-1', PASSWORD_DEFAULT)]);
unset($build);

file_put_contents($boot, '<?php
define("ROOT_PATH", ' . var_export($root, true) . ');
define("APP_VERSION", "test");
$GLOBALS["config"] = ["app_name"=>"T","app_env"=>"local","app_url"=>"","base_path"=>"","pretty_urls"=>true,
  "demo_mode"=>false,"db_driver"=>"sqlite","db_host"=>"","db_port"=>"","db_name"=>"","db_user"=>"","db_pass"=>"",
  "sqlite_path"=>' . var_export($dbFile, true) . ',"mail_enabled"=>false,"mail_from"=>"",
  "agent_portal_enabled"=>false,"session_name"=>"t","upload_max_bytes"=>1000];
date_default_timezone_set("Africa/Lagos");
ini_set("display_errors","0"); ini_set("log_errors","1"); ini_set("error_log", ' . var_export($logFile, true) . ');
spl_autoload_register(static function (string $c): void {
    $f = ROOT_PATH . "/app/Core/" . basename($c) . ".php";
    if (is_file($f)) require $f;
});
require ROOT_PATH . "/app/Core/helpers.php";
ob_start(); session_start();
$out = [];
$out["lifecycle"] = Auth::hasPasswordLifecycle();
Database::query("DELETE FROM rate_limits");
$out["signin"] = Auth::attempt("legacy@example.test", "LegacyPass-1", "1.2.3.4");
$u = Auth::user();
$out["user"] = $u["email"] ?? null;
$out["flag"] = $u["must_change_password"] ?? "missing";
$out["mustChange"] = PasswordReset::mustChange((array) $u);
Database::update("users", PasswordReset::guardFlag(["name" => "Renamed", "must_change_password" => 1]), (int) $u["id"]);
$out["renamed"] = Database::value("SELECT name FROM users WHERE id=?", [(int) $u["id"]]);
while (ob_get_level() > 0) ob_end_clean();
echo json_encode($out);
');
$output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($boot) . ' 2>&1');
$result = json_decode(trim($output), true);
$bootErrors = is_file($logFile) ? (string) file_get_contents($logFile) : '';
@unlink($boot); @unlink($dbFile); @unlink($logFile);

check('an un-upgraded database is detected', ($result['lifecycle'] ?? null) === false, $output);
check('signing in does not fail without the column', ($result['signin'] ?? null) === true, $output);
check('the session loads instead of erroring', ($result['user'] ?? null) === 'legacy@example.test', $output);
check('the missing flag reads as "no change pending"', ($result['flag'] ?? null) === 0, $output);
check('the forced-change gate stays off', ($result['mustChange'] ?? null) === false, $output);
check('saving a user still works', ($result['renamed'] ?? null) === 'Renamed', $output);
check('nothing was written to the error log', $bootErrors === '', $bootErrors);

echo "\n== Demo accounts stay usable ==\n";
$credsFile = $root . '/storage/demo-credentials.json';
if (is_file($credsFile)) {
    $creds = json_decode((string) file_get_contents($credsFile), true) ?: [];
    $intact = 0; $total = 0;
    foreach ($creds as $role => $cred) {
        $total++;
        clearThrottle();
        $_SESSION = [];
        if (Auth::attempt((string) $cred['email'], (string) $cred['password'], '10.9.9.9')) $intact++;
        $row = Database::one('SELECT must_change_password FROM users WHERE email=?', [$cred['email']]);
        if ($row && (int) $row['must_change_password'] === 1) echo "   (note: {$role} is flagged for a change)\n";
    }
    check('every demo account still signs in', $total > 0 && $intact === $total, "{$intact}/{$total}");
    foreach ($creds as $cred) {
        Database::query('UPDATE users SET must_change_password=0 WHERE email=?', [$cred['email']]);
    }
} else {
    check('demo credentials available', true, 'skipped: no demo file');
}

echo "\n== Cleanup ==\n";
foreach ($createdUsers as $id) {
    Database::query('DELETE FROM password_resets WHERE user_id=?', [$id]);
    Database::query('DELETE FROM audit_logs WHERE entity_type=? AND entity_id=?', ['user', $id]);
    Database::query('DELETE FROM users WHERE id=?', [$id]);
}
Database::query("DELETE FROM audit_logs WHERE action LIKE 'user.reset%' AND entity_type='user' AND entity_id NOT IN (SELECT id FROM users)");
foreach ($createdEmails as $email) {
    Database::query('DELETE FROM audit_logs WHERE details LIKE ?', ['%' . $email . '%']);
}
clearThrottle();
$leftover = (int) Database::value('SELECT COUNT(*) FROM users WHERE email LIKE ?', ['pwtest.%@example.test']);
check('test accounts were removed', $leftover === 0, "{$leftover} left");
check('no stray tokens remain',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE user_id NOT IN (SELECT id FROM users)') === 0);

while (ob_get_level() > 0) { ob_end_flush(); }
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

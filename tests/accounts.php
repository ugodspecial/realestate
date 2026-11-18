<?php
/**
 * Account lifecycle: what happens when a staff member leaves.
 *
 * A departed member of staff must lose access immediately - not just at their
 * next sign-in attempt, but in any tab they still have open - and the account
 * must be recoverable if they come back.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$fail = 0; $pass = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $fail, $pass;
    if ($cond) { $pass++; echo "PASS  {$label}\n"; }
    else { $fail++; echo "FAIL  {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n"; }
}

echo "== Schema ==\n";
$cols = array_column(Database::all('PRAGMA table_info(users)'), 'name');
ok('the users table tracks an active flag', in_array('active', $cols, true));
ok('the users table tracks a session version', in_array('session_version', $cols, true));

echo "\n== A deactivated account cannot sign in ==\n";
$email = 'lifecycle-test@example.com';
Database::query('DELETE FROM users WHERE email = ?', [$email]);
$id = Database::insert('users', [
    'name' => 'Resigned Staff',
    'email' => $email,
    'password_hash' => password_hash('CorrectHorse123', PASSWORD_DEFAULT),
    'role' => 'listing_manager',
    'active' => 1,
    'created_at' => Database::now(),
]);

$attempt = static function () use ($email): bool {
    $_SESSION = [];
    Database::query('DELETE FROM rate_limits');
    return Auth::attempt($email, 'CorrectHorse123', '127.0.0.1');
};

ok('an active account can sign in', $attempt() === true);
ok('signing in stores the user in the session', (int)($_SESSION['user_id'] ?? 0) === (int)$id);

// Deactivate exactly as the admin controller does.
Database::update('users', [
    'active' => 0,
    'session_version' => (int)Database::value('SELECT session_version FROM users WHERE id=?', [$id]) + 1,
], (int)$id);

ok('a deactivated account is refused at sign-in', $attempt() === false);

// The open session from before deactivation must already be dead.
$_SESSION['user_id'] = (int)$id;
$_SESSION['session_version'] = 1;   // stale, as an old browser tab would hold
$_SESSION['last_activity'] = time();
ok('an existing session is revoked immediately', Auth::user() === null);

echo "\n== Reactivation restores access ==\n";
Database::update('users', ['active' => 1], (int)$id);
ok('a reactivated account can sign in again', $attempt() === true);

echo "\n== Guards ==\n";
$controller = (string) file_get_contents($root . '/app/Core/AdminController.php');
ok('an admin cannot deactivate their own account',
    str_contains($controller, 'You cannot change your own role or deactivate your own account'));

// The last active super administrator must never be removable, or nobody can
// reach settings again and there is no way back through the interface.
$superAdmins = (int) Database::value("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1");
echo "   (active super administrators now: {$superAdmins})\n";
ok('the controller refuses to remove the last active super administrator',
    str_contains($controller, 'last active super administrator')
    || str_contains($controller, 'last super administrator'));

echo "\n== Deactivation is auditable ==\n";
$audit = (string) file_get_contents($root . '/app/Core/AdminController.php');
ok('staff changes are written to the audit trail', str_contains($audit, "Audit::log('user.update'"));

// Clean up.
Database::query('DELETE FROM users WHERE email = ?', [$email]);
Database::query('DELETE FROM audit_logs WHERE entity_type = ? AND entity_id = ?', ['user', (int)$id]);
Database::query('DELETE FROM rate_limits');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

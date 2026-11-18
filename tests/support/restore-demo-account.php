<?php
/**
 * Restore one demo account's password to the value recorded in
 * storage/demo-credentials.json, clear any forced password change, and drop
 * outstanding reset tokens. Tests change passwords; this puts them back.
 *
 * Restoring from the credentials file rather than a hash captured at runtime
 * matters: if an earlier run crashed after changing the password, a captured
 * hash would simply write the wrong value back again.
 *
 * Usage: php tests/support/restore-demo-account.php [role ...]   (default: all)
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$file = ROOT_PATH . '/storage/demo-credentials.json';
if (!is_file($file)) { fwrite(STDERR, "No demo credentials file; nothing to restore.\n"); exit(1); }
$creds = json_decode((string) file_get_contents($file), true) ?: [];
$wanted = array_slice($argv, 1);
if ($wanted === []) $wanted = array_keys($creds);

$restored = 0;
foreach ($wanted as $role) {
    if (!isset($creds[$role]['email'], $creds[$role]['password'])) {
        fwrite(STDERR, "Unknown role: {$role}\n");
        exit(1);
    }
    $id = (int) Database::value('SELECT id FROM users WHERE email = ?', [$creds[$role]['email']]);
    if ($id === 0) { fwrite(STDERR, "No user for role {$role}\n"); exit(1); }
    $version = (int) Database::value('SELECT session_version FROM users WHERE id = ?', [$id]);
    Database::update('users', [
        'password_hash' => password_hash((string) $creds[$role]['password'], PASSWORD_DEFAULT),
        'must_change_password' => 0,
        'active' => 1,
        'session_version' => $version + 1,
    ], $id);
    Database::query('DELETE FROM password_resets WHERE user_id = ?', [$id]);
    $restored++;
}
Database::query('DELETE FROM rate_limits');
echo "Restored {$restored} demo account(s).\n";

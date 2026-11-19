<?php
/**
 * The Host header is chosen by whoever sends the request, so it must never
 * decide where a link that is EMAILED points.
 *
 * The attack this guards against is password-reset poisoning: ask for a reset
 * for a member of staff with "Host: evil.example". If the link in the genuine
 * email is built from that header, the genuine message - sent from the genuine
 * address, carrying a live token - points at the attacker's server. Visiting it
 * hands over the token, and the account with it.
 *
 * Needs no web server: it sets the request environment directly.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) { ob_start(); session_start(); }

$fail = 0; $pass = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $fail, $pass;
    if ($cond) { $pass++; echo "PASS  {$label}\n"; }
    else { $fail++; echo "FAIL  {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n"; }
}

/** Run a callable with the given request/config environment, then restore it. */
function withEnv(array $config, ?string $host, callable $fn): mixed {
    $saved = $GLOBALS['config'];
    $savedHost = $_SERVER['HTTP_HOST'] ?? null;
    $savedHttps = $_SERVER['HTTPS'] ?? null;
    foreach ($config as $k => $v) $GLOBALS['config'][$k] = $v;
    if ($host === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $host;
    unset($_SERVER['HTTPS']);
    try { return $fn(); }
    finally {
        $GLOBALS['config'] = $saved;
        if ($savedHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $savedHost;
        if ($savedHttps !== null) $_SERVER['HTTPS'] = $savedHttps;
    }
}

echo "== trusted_origin() ==\n";
ok('a configured APP_URL always wins over the Host header',
    withEnv(['app_url' => 'https://real.example'], 'evil.example', fn() => trusted_origin()) === 'https://real.example');
ok('a trailing slash on APP_URL is trimmed',
    withEnv(['app_url' => 'https://real.example/'], null, fn() => trusted_origin()) === 'https://real.example');
ok('a well-formed Host is accepted when nothing is configured',
    withEnv(['app_url' => ''], 'demo.example:8080', fn() => trusted_origin()) === 'http://demo.example:8080');

$hostile = [
    'a quote'                 => 'x"y.example',
    'a script breakout'       => 'x"</script><script>alert(1)</script>',
    'userinfo'                => 'real.example@evil.example',
    'a path'                  => 'evil.example/phish',
    'a space'                 => 'evil.example evil2.example',
    'a CRLF header split'     => "evil.example\r\nX-Injected: 1",
    'a backslash'             => 'evil.example\\@real.example',
    'a leading dot'           => '.evil.example',
    'an over-long name'       => str_repeat('a', 300) . '.example',
    'a port out of shape'     => 'evil.example:80a',
];
foreach ($hostile as $label => $host) {
    ok("a Host with {$label} is refused",
        withEnv(['app_url' => ''], $host, fn() => trusted_origin()) === '', var_export($host, true));
}
ok('a missing Host yields no origin rather than a guess',
    withEnv(['app_url' => ''], null, fn() => trusted_origin()) === '');

echo "\n== Canonical and Open Graph URLs ==\n";
ok('the share image uses APP_URL, not a forged Host',
    withEnv(['app_url' => 'https://real.example'], 'evil.example', fn() => str_starts_with(social_image_url(), 'https://real.example/')));
ok('the canonical URL uses APP_URL, not a forged Host',
    withEnv(['app_url' => 'https://real.example'], 'evil.example', fn() => !str_contains(social_url(), 'evil.example')));
ok('a hostile Host produces no canonical URL at all',
    withEnv(['app_url' => ''], 'x"y.example', fn() => social_url()) === '');

echo "\n== The password-reset link ==\n";
ok('in production the link never takes its host from the request',
    withEnv(['app_env' => 'production', 'app_url' => ''], 'evil.example', fn() => PasswordReset::link('abc')) === '');
ok('in production a plausible-looking forged Host is still refused',
    withEnv(['app_env' => 'production', 'app_url' => ''], 'company-support.example', fn() => PasswordReset::link('abc')) === '');
$configured = withEnv(['app_env' => 'production', 'app_url' => 'https://real.example', 'base_path' => ''], 'evil.example', fn() => PasswordReset::link('abc'));
ok('in production the link is built from APP_URL', $configured === 'https://real.example/reset-password?token=abc', $configured);
ok('APP_URL is used even when the request names another host', !str_contains($configured, 'evil.example'));
$local = withEnv(['app_env' => 'local', 'app_url' => ''], 'demo.example:8080', fn() => PasswordReset::link('abc'));
ok('outside production a well-formed Host still works, so demos and tests do', str_starts_with($local, 'http://demo.example:8080/'), $local);
$cli = withEnv(['app_env' => 'local', 'app_url' => ''], null, fn() => PasswordReset::link('abc'));
ok('on the command line the link falls back to localhost', str_starts_with($cli, 'http://localhost'), $cli);

echo "\n== Failing closed ==\n";
$email = 'host-header-test@example.com';
Database::query('DELETE FROM users WHERE email = ?', [$email]);
$uid = Database::insert('users', [
    'name' => 'Host Header Test', 'email' => $email,
    'password_hash' => password_hash('CorrectHorse123', PASSWORD_DEFAULT),
    'role' => 'content_editor', 'active' => 1,
    'created_at' => Database::now(),
]);
$auditBefore = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.reset_unavailable'");
$token = withEnv(['app_env' => 'production', 'app_url' => '', 'mail_enabled' => false], 'evil.example',
    fn() => PasswordReset::issue($uid, ['name' => 'Host Header Test', 'email' => $email]));
ok('with no APP_URL in production nothing is issued', $token === '');
ok('no live token is left behind for a link that was never sent',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE user_id = ?', [$uid]) === 0);
ok('the refusal is recorded for the operator',
    (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.reset_unavailable'") === $auditBefore + 1);
$auditText = (string) Database::value("SELECT details FROM audit_logs WHERE action = 'user.reset_unavailable' ORDER BY id DESC LIMIT 1");
ok('and the record does not repeat the forged host', !str_contains($auditText, 'evil.example'), $auditText);

$requested = withEnv(['app_env' => 'production', 'app_url' => '', 'mail_enabled' => false], 'evil.example',
    fn() => PasswordReset::request($email, '203.0.113.' . random_int(1, 254)));
ok('request() reports that nothing was issued', $requested === false);

$token = withEnv(['app_env' => 'production', 'app_url' => 'https://real.example', 'mail_enabled' => false], 'evil.example',
    fn() => PasswordReset::issue($uid, ['name' => 'Host Header Test', 'email' => $email]));
ok('with APP_URL set a token is issued', strlen($token) === 64 && ctype_xdigit($token));
ok('and it is a real, usable token', PasswordReset::find($token) !== null);

// Cleanup: leave no trace.
Database::query('DELETE FROM password_resets WHERE user_id = ?', [$uid]);
Database::query('DELETE FROM users WHERE id = ?', [$uid]);
Database::query("DELETE FROM audit_logs WHERE action = 'user.reset_unavailable' AND details LIKE 'Reset link not sent%'");
Database::query("DELETE FROM audit_logs WHERE action = 'user.reset_requested' AND entity_id = ?", [$uid]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

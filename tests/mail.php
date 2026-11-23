<?php
/**
 * Email: does the site really send it, and say plainly when it cannot?
 *
 * Until this suite existed nothing in the project had ever sent a message: every other test
 * runs with email switched off. So "email works" was an assumption. Here a real SMTP server
 * (tests/fixtures/smtp-sink.py - plain, sign-in, STARTTLS, TLS, and a dozen ways to misbehave)
 * receives real messages over real sockets, and the bytes it received are checked.
 *
 *   php tests/mail.php
 *   php -d sendmail_path="sh $PWD/tests/fixtures/mail-capture.sh -t -i" tests/mail.php   (adds the mail() driver, Unix)
 *   php -d sendmail_path=/nonexistent/sendmail tests/mail.php --mail-broken              (mail() with no mail program)
 *
 * On Windows with no sendmail_path set, PHP's mail() talks SMTP itself, to php.ini's SMTP and smtp_port;
 * there the suite points those at the test server and checks the mail() driver that way. (Laragon sets up
 * a mail program of its own for mail(), so that is a different path: see docs/EMAIL.md.)
 *
 * The sink needs python3 (and openssl for the TLS cases). Without it those checks are skipped
 * and say so; everything else (settings, message format, log driver) still runs. Set
 * MAIL_TEST_SINKS=/path/state.json to use a sink that is already running, and
 * MAIL_TEST_REQUIRE=1 to make a skipped section a failure (CI does).
 *
 * Needs no web server and leaves nothing behind.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
$_SESSION = [];
$brokenMode = in_array('--mail-broken', $argv ?? [], true);

$fail = 0; $pass = 0; $skipped = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $fail, $pass;
    if ($cond) { $pass++; echo "PASS  {$label}\n"; }
    else { $fail++; echo "FAIL  {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n"; }
}
function skipped(string $label, string $why): void {
    global $skipped, $fail;
    // Where the checks must run (CI sets MAIL_TEST_REQUIRE=1), a skipped section would hide a broken
    // test harness behind a green run, so it counts as a failure there.
    if (getenv('MAIL_TEST_REQUIRE')) {
        $fail++;
        echo "FAIL  {$label} :: skipped, but this run requires it ({$why})\n";
        return;
    }
    $skipped++;
    echo "SKIP  {$label} :: {$why}\n";
}

// ---------------------------------------------------------------- scaffolding
// The site this suite runs as: a notification address left EMPTY (the screen saves an emptied field as
// an empty string) and a public email to fall back on. This is the ordinary way for alerts to stop
// without anyone noticing, so the whole suite runs in that state. setting() caches on first use and
// nothing has used it yet, which the first check below proves.
$saveSetting = static function (string $key, string $value): void {
    if (Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        Database::query('INSERT INTO settings (key_name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, $value]);
    } else {
        Database::query('INSERT INTO settings (key_name,value) VALUES (?,?) ON CONFLICT(key_name) DO UPDATE SET value=excluded.value', [$key, $value]);
    }
};
$originalSettings = array_column(Database::all("SELECT key_name, value FROM settings WHERE key_name IN ('email','notify_email')"), 'value', 'key_name');
$restoreSettings = static function () use (&$originalSettings, $saveSetting): void {
    foreach (['email', 'notify_email'] as $key) {
        if (array_key_exists($key, $originalSettings)) $saveSetting($key, (string) $originalSettings[$key]);
        else Database::query('DELETE FROM settings WHERE key_name = ?', [$key]);
    }
};
register_shutdown_function($restoreSettings); // also on a fatal error, so a crash cannot leave the demo data changed
$saveSetting('notify_email', '');
$saveSetting('email', 'front-desk@example.test');
$baseConfig = $GLOBALS['config'];
function mailConfig(array $overrides = []): void {
    global $baseConfig;
    $GLOBALS['config'] = array_replace($baseConfig, [
        'mail_enabled' => true, 'mail_driver' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => 0,
        'mail_encryption' => 'none', 'mail_username' => '', 'mail_password' => '', 'mail_verify_peer' => true,
        'mail_cafile' => '', 'mail_timeout' => 3, 'mail_from' => 'noreply@example.com',
    ], $overrides);
}

// Failures write one line to the error log. Point it at a scratch file so this suite can read it,
// and so the CI guard that fails the build on "PHP Warning" sees only what the site itself logs.
$errorLogFile = sys_get_temp_dir() . '/cedar-mail-test-' . bin2hex(random_bytes(4)) . '.log';
$previousErrorLog = (string) ini_get('error_log');
ini_set('error_log', $errorLogFile);
function errorLogText(): string { return is_file($GLOBALS['errorLogFile']) ? (string) file_get_contents($GLOBALS['errorLogFile']) : ''; }
function clearErrorLog(): void { if (is_file($GLOBALS['errorLogFile'])) unlink($GLOBALS['errorLogFile']); }

/** @return array{ports:array<string,int>,cert:string,dir:string,proc:mixed,oauth:array<string,string>}|null */
function startSink(string $root): ?array {
    $existing = (string) getenv('MAIL_TEST_SINKS');
    if ($existing !== '' && is_file($existing)) {
        $state = json_decode((string) file_get_contents($existing), true);
        if (is_array($state) && isset($state['ports'])) return ['ports' => $state['ports'], 'cert' => (string) $state['cert'], 'dir' => dirname($existing), 'proc' => null, 'oauth' => (array) ($state['oauth'] ?? [])];
    }
    if (!function_exists('proc_open')) return null;
    $dir = sys_get_temp_dir() . '/cedar-mail-sink-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    foreach (['python3', 'python'] as $python) {
        $proc = @proc_open([$python, $root . '/tests/fixtures/smtp-sink.py', '--outdir', $dir, '--state', $dir . '/state.json', '--idle-exit', '120'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']], $pipes);
        if (!is_resource($proc)) continue;
        for ($i = 0; $i < 80 && !is_file($dir . '/state.json'); $i++) {
            if (!(proc_get_status($proc)['running'] ?? false)) break; // e.g. a Windows Store stub for "python3"
            usleep(125000);
        }
        if (is_file($dir . '/state.json')) {
            $state = json_decode((string) file_get_contents($dir . '/state.json'), true);
            return ['ports' => $state['ports'], 'cert' => (string) $state['cert'], 'dir' => $dir, 'proc' => $proc, 'oauth' => (array) ($state['oauth'] ?? [])];
        }
        proc_terminate($proc);
    }
    return null;
}
function stopSink(?array $sink): void {
    if (!$sink || $sink['proc'] === null) return;
    proc_terminate($sink['proc']);
    proc_close($sink['proc']);
    foreach (glob($sink['dir'] . '/*') ?: [] as $file) unlink($file);
    rmdir($sink['dir']);
}
/** @return list<array<string,mixed>> */
function sinkEvents(array $sink, string $scenario): array {
    $file = $sink['dir'] . '/' . $scenario . '.jsonl';
    if (!is_file($file)) return [];
    return array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($file))));
}
/** @return list<array<string,mixed>> */
function sinkMessages(array $sink, string $scenario): array {
    return array_values(array_filter(sinkEvents($sink, $scenario), static fn($e) => ($e['event'] ?? '') === 'message'));
}
function sinkSaw(array $sink, string $scenario, string $event): int {
    return count(array_filter(sinkEvents($sink, $scenario), static fn($e) => ($e['event'] ?? '') === $event));
}

/** @return array{headers:array<string,list<string>>,head:string,body:string} */
function parseMessage(string $raw): array {
    $split = strpos($raw, "\r\n\r\n");
    $head = $split === false ? $raw : substr($raw, 0, $split);
    $body = $split === false ? '' : substr($raw, $split + 4);
    $headers = [];
    foreach (explode("\r\n", (string) preg_replace('/\r\n[ \t]+/', ' ', $head)) as $line) {
        $pair = explode(':', $line, 2);
        $headers[strtolower(trim($pair[0]))][] = trim($pair[1] ?? '');
    }
    return ['headers' => $headers, 'head' => $head, 'body' => $body];
}
/** Decode RFC 2047 encoded words (what a mail program does), and report the longest word. */
function decodeWords(string $header, int &$longest = 0): string {
    return (string) preg_replace_callback('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=\s*/', static function (array $m) use (&$longest): string {
        $longest = max($longest, strlen(trim($m[0])));
        return (string) base64_decode($m[1]);
    }, $header);
}
function crlf(string $text): string { return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text); }
/** Text with line breaks normalised and trailing ones dropped: PHP's mail() adds one more at the end. */
function plainText(string $text): string { return rtrim((string) preg_replace('/\r\n|\r|\n/', "\n", $text), "\n"); }

$sample = "Dear team,\n\nA dash — a pound sign £ and an e-acute é.\n.This line starts with a dot\n..and this with two\n"
    . str_repeat('x', 5000) . "\nTrailing spaces   \nLast line, no newline";

if ($brokenMode) {
    echo "== mail() with no usable mail program ==\n";
    mailConfig(['mail_driver' => 'mail', 'mail_host' => '']);
    clearErrorLog();
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a missing mail program is a failure, not a success', $sent === false);
    ok('...explained, with the way out', str_contains(Mailer::lastError(), 'mail() could not pass the message') && str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_DRIVER=smtp'), Mailer::lastError());
    ok('...with PHP\'s own warning captured into that explanation, not written to the error log as a second, raw line', substr_count(errorLogText(), "\n") === 1 && !preg_match('/PHP (Warning|Notice|Deprecated)/', errorLogText()) && str_contains(errorLogText(), 'Mail not sent (mail):'), errorLogText());
    ini_set('error_log', $previousErrorLog);
    if (is_file($errorLogFile)) unlink($errorLogFile);
    $restoreSettings();
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

// ================================================================== settings
echo "== Settings: what the site decides for itself ==\n";
ok('the suite starts from settings it set itself (nothing read them first)', Mailer::officeAddress() === 'front-desk@example.test', Mailer::officeAddress());
mailConfig(['mail_driver' => '', 'mail_host' => '']);
ok('with no host and no driver, mail() is used (a site that works today keeps working)', Mailer::settings()['driver'] === 'mail');
mailConfig(['mail_driver' => '', 'mail_host' => 'smtp.example.com']);
ok('setting only MAIL_HOST selects SMTP', Mailer::settings()['driver'] === 'smtp');
mailConfig(['mail_driver' => 'LOG', 'mail_host' => 'smtp.example.com']);
ok('an explicit driver wins, whatever its case', Mailer::settings()['driver'] === 'log');

$enc = static function (array $o): array { mailConfig($o); $s = Mailer::settings(); return [$s['encryption'], $s['port']]; };
ok('a remote host defaults to STARTTLS on 587', $enc(['mail_host' => 'smtp.example.com', 'mail_encryption' => '', 'mail_port' => '']) === ['tls', 587]);
ok('port 465 means TLS from the first byte', $enc(['mail_host' => 'smtp.example.com', 'mail_encryption' => '', 'mail_port' => '465']) === ['ssl', 465]);
ok('a server on this machine needs no encryption, on port 25 unless told', $enc(['mail_host' => 'localhost', 'mail_encryption' => '', 'mail_port' => '']) === ['none', 25]);
ok('127.0.0.1 is local too, and takes the port it is given', $enc(['mail_host' => '127.0.0.1', 'mail_encryption' => '', 'mail_port' => '1025']) === ['none', 1025]);
ok('"ssl" without a port is 465', $enc(['mail_host' => 'smtp.example.com', 'mail_encryption' => 'ssl', 'mail_port' => '']) === ['ssl', 465]);
ok('"starttls" is accepted for "tls"', $enc(['mail_host' => 'smtp.example.com', 'mail_encryption' => 'STARTTLS', 'mail_port' => '2525']) === ['tls', 2525]);
ok('"none" can be chosen for a remote relay', $enc(['mail_host' => 'relay.example.com', 'mail_encryption' => 'none', 'mail_port' => '']) === ['none', 25]);
ok('a nonsense port falls back to the default', $enc(['mail_host' => 'smtp.example.com', 'mail_encryption' => 'tls', 'mail_port' => '99999']) === ['tls', 587]);

$pick = static fn(string $n, string $p): string => Mailer::pickOfficeAddress($n, $p);
ok('alerts go to the notification address when there is one', $pick('alerts@example.com', 'hello@example.com') === 'alerts@example.com');
ok('an EMPTY notification address falls back to the public email (it used to stop alerts silently)', $pick('', 'hello@example.com') === 'hello@example.com');
ok('so does one that is only spaces', $pick('   ', 'hello@example.com') === 'hello@example.com');
ok('so does one that is not an address', $pick('not-an-email', 'hello@example.com') === 'hello@example.com');
ok('surrounding spaces are ignored', $pick(' alerts@example.com ', '') === 'alerts@example.com');
ok('with neither, there is no address, and the caller is told so', $pick('', '') === '' && $pick('x', 'y') === '');

mailConfig(['mail_enabled' => 'true']);
ok('"true" as text switches mail on (config.local.php, environment)', Mailer::enabled());
mailConfig(['mail_enabled' => 'false']);
ok('"false" as text switches it off, not on', !Mailer::enabled());
mailConfig(['mail_enabled' => false]);
ok('false switches it off', !Mailer::enabled());

mailConfig(['mail_driver' => 'smtp', 'mail_host' => '']);
ok('SMTP with no host is reported', in_array('MAIL_DRIVER is smtp but MAIL_HOST is empty.', Mailer::problems(), true));
mailConfig(['mail_host' => 'smtp.example.com', 'mail_username' => 'me@example.com', 'mail_password' => '']);
ok('a username without a password is reported', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'MAIL_PASSWORD is empty')));
mailConfig(['mail_host' => 'smtp.example.com', 'mail_encryption' => 'tls', 'mail_username' => 'me@example.com', 'mail_password' => 'a-long-password']);
ok('a complete, encrypted SMTP setup has no problems', Mailer::problems() === [], implode(' | ', Mailer::problems()));
mailConfig(['mail_host' => 'relay.example.com', 'mail_encryption' => 'none', 'mail_username' => 'me', 'mail_password' => 'a-long-password']);
ok('signing in without encryption to a remote host is flagged', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'unencrypted')));
mailConfig(['mail_host' => 'smtp.example.com', 'mail_encryption' => 'tls', 'mail_verify_peer' => false]);
ok('switching certificate checks off is flagged', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'certificate is not checked') || str_contains($p, 'not checked')));
mailConfig(['mail_driver' => 'carrier-pigeon']);
ok('an unknown driver is reported', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'carrier-pigeon')));
mailConfig(['mail_driver' => 'log', 'app_env' => 'production']);
ok('the log driver in production is reported as unusable', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'refused in production')));
mailConfig(['mail_from' => '']);
$GLOBALS['config']['mail_from'] = 'not an address';
ok('no valid sender is reported', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'no valid sender')));
mailConfig(['mail_host' => 'smtp.example.com', 'mail_username' => 'me@example.com', 'mail_password' => 'Very-Secret-Pw-123']);
ok('the description never contains the password', !str_contains(Mailer::describe(), 'Very-Secret-Pw-123') && str_contains(Mailer::describe(), 'smtp.example.com'));
ok('settings() reports the password only as present', Mailer::settings()['has_password'] === true && !str_contains(json_encode(Mailer::settings()), 'Very-Secret-Pw-123'));


// ================================================================== OAuth, settings only
echo "\n== OAuth sign-in (Microsoft 365): the settings and the wording, with no network ==\n";
ok('SmtpClient::xoauth2 reproduces the example printed in Microsoft\'s documentation, byte for byte',
    SmtpClient::xoauth2('test@contoso.onmicrosoft.com', 'EwBAAl3BAAUFFpUAo7J3Ve0bjLBWZWCclRC3EoAA') === 'dXNlcj10ZXN0QGNvbnRvc28ub25taWNyb3NvZnQuY29tAWF1dGg9QmVhcmVyIEV3QkFBbDNCQUFVRkZwVUFvN0ozVmUwYmpMQldaV0NjbFJDM0VvQUEBAQ==');
ok('...and the one in Google\'s',
    SmtpClient::xoauth2('someuser@example.com', 'ya29.vF9dft4qmTc2Nvb3RlckBhdHRhdmlzdGEuY29tCg') === 'dXNlcj1zb21ldXNlckBleGFtcGxlLmNvbQFhdXRoPUJlYXJlciB5YTI5LnZGOWRmdDRxbVRjMk52YjNSbGNrQmhkSFJoZG1semRHRXVZMjl0Q2cBAQ==');
ok('the decoded string is user=, the mailbox, 0x01, auth=Bearer, the token, and two 0x01', base64_decode(SmtpClient::xoauth2('a@b.example', 'TOK')) === "user=a@b.example\x01auth=Bearer TOK\x01\x01");

$oauthStoreFile = sys_get_temp_dir() . '/cedar-oauth-test-' . bin2hex(random_bytes(4)) . '.json';
$oauthBase = ['mail_oauth_store' => $oauthStoreFile, 'mail_host' => '', 'mail_driver' => '', 'mail_port' => '', 'mail_encryption' => '', 'mail_username' => '', 'mail_password' => '', 'mail_from' => ''];
$appSettings = ['mail_oauth_provider' => 'microsoft', 'mail_oauth_flow' => 'app', 'mail_oauth_tenant' => 'a1b2c3d4-0000-4000-8000-000000000001', 'mail_oauth_client_id' => 'a1b2c3d4-0000-4000-8000-000000000002', 'mail_oauth_client_secret' => 'Not-A-Real-Secret~Value', 'mail_username' => 'noreply@contoso.example'];
$withOAuth = static fn(array $o = []): array => array_replace($oauthBase, $appSettings, $o);

mailConfig($oauthBase);
ok('with no provider named, OAuth is off, adds no problem, and mail behaves exactly as before', !MailOAuth::enabled() && Mailer::settings()['oauth'] === '' && MailOAuth::problems() === [] && Mailer::settings()['driver'] === 'mail');
foreach (['microsoft', 'Microsoft', 'microsoft365', 'Microsoft-365', 'office365', 'M365'] as $alias) {
    mailConfig($withOAuth(['mail_oauth_provider' => $alias]));
    ok("\"{$alias}\" means Microsoft 365", MailOAuth::provider() === 'microsoft' && Mailer::settings()['oauth'] === 'Microsoft 365');
}
mailConfig($withOAuth());
$s = Mailer::settings();
ok('naming the provider is enough to select SMTP, its own server, STARTTLS and 587 (no MAIL_HOST to look up)', $s['driver'] === 'smtp' && $s['host'] === 'smtp.office365.com' && $s['port'] === 587 && $s['encryption'] === 'tls', json_encode($s));
ok('the sender defaults to the mailbox (Microsoft 365 will not send as the website\'s public address)', $s['from'] === 'noreply@contoso.example');
mailConfig($withOAuth(['mail_from' => 'sales@contoso.example']));
ok('...unless a sender is set', Mailer::settings()['from'] === 'sales@contoso.example');
ok('a complete app setup has no problems', Mailer::problems() === [], implode(' | ', Mailer::problems()));
ok('the description says how mail is signed in, and names no secret', str_contains(Mailer::describe(), 'smtp.office365.com:587') && str_contains(Mailer::describe(), 'Microsoft 365 OAuth as noreply@contoso.example') && !str_contains(Mailer::describe(), 'Not-A-Real-Secret'));
ok('settings() holds no secret of any kind', !str_contains(json_encode(Mailer::settings()), 'Not-A-Real-Secret') && !str_contains(json_encode(MailOAuth::settings()), 'Not-A-Real-Secret') && MailOAuth::settings()['has_secret'] === true);
mailConfig($withOAuth(['mail_oauth_flow' => '']));
ok('with a client secret and no flow named, the website signs in as an app', MailOAuth::settings()['flow'] === 'app');
mailConfig($withOAuth(['mail_oauth_flow' => '', 'mail_oauth_client_secret' => '']));
ok('without a secret and no flow named, a person connects (the "user" way)', MailOAuth::settings()['flow'] === 'user');

$problemWith = static function (array $o, string $needle) use ($withOAuth): bool {
    mailConfig($withOAuth($o));
    return (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, $needle));
};
ok('no tenant is reported, in the words of the screen that has it', $problemWith(['mail_oauth_tenant' => ''], 'MAIL_OAUTH_TENANT is empty'));
ok('a tenant that is not a name or a GUID is reported', $problemWith(['mail_oauth_tenant' => 'a b/../c'], 'cannot be part of a tenant ID'));
ok('"common" cannot be the tenant when the app signs in as itself', $problemWith(['mail_oauth_tenant' => 'common'], 'must be your own Directory (tenant) ID'));
ok('no client ID is reported', $problemWith(['mail_oauth_client_id' => ''], 'MAIL_OAUTH_CLIENT_ID is empty'));
ok('no client secret is reported for the app way, with the reminder to use the Value and not the Secret ID', $problemWith(['mail_oauth_flow' => 'app', 'mail_oauth_client_secret' => ''], 'MAIL_OAUTH_CLIENT_SECRET is empty') && $problemWith(['mail_oauth_flow' => 'app', 'mail_oauth_client_secret' => ''], 'not its Secret ID'));
ok('a flow that is neither app nor user is reported', $problemWith(['mail_oauth_flow' => 'magic'], 'is not one of app or user'));
ok('a provider that is not supported is reported, not ignored', $problemWith(['mail_oauth_provider' => 'carrier-pigeon'], 'is not supported'));
ok('a sign-in address that is not https is reported', $problemWith(['mail_oauth_authority' => 'http://login.example.com'], 'MAIL_OAUTH_AUTHORITY must be'));
ok('...but one on this machine may be plain http (the tests use one)', !$problemWith(['mail_oauth_authority' => 'http://127.0.0.1:8123'], 'MAIL_OAUTH_AUTHORITY'));
ok('a password left in the settings is reported as unused', $problemWith(['mail_password' => 'a-long-password-nobody-needs'], 'MAIL_PASSWORD is set but not used'));
ok('a mailbox missing is reported for the app way', $problemWith(['mail_username' => ''], 'MAIL_USERNAME is empty'));
ok('OAuth with MAIL_DRIVER=mail is reported (mail() cannot sign in)', $problemWith(['mail_driver' => 'mail'], 'which cannot sign in with OAuth'));
ok('OAuth with MAIL_DRIVER=log is reported (it would send nothing)', $problemWith(['mail_driver' => 'log'], 'which sends nothing'));
ok('OAuth over an unencrypted connection to a remote server is reported as sending the token in the clear', $problemWith(['mail_encryption' => 'none'], 'access token would cross the network unencrypted'));
ok('a connect-by-person setup that has not been connected says so, and what to press', $problemWith(['mail_oauth_flow' => 'user', 'mail_oauth_client_secret' => ''], 'is not connected yet'));
mailConfig(array_replace($oauthBase, ['mail_host' => 'smtp.office365.com', 'mail_encryption' => 'tls', 'mail_username' => 'me@contoso.example', 'mail_password' => 'a-long-password-123']));
ok('a PASSWORD set-up on Microsoft 365\'s server is told, before it breaks, that Microsoft is switching passwords off', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'switching password sign-in off') && str_contains($p, 'MAIL_OAUTH_PROVIDER=microsoft')), implode(' | ', Mailer::problems()));
mailConfig(array_replace($oauthBase, ['mail_host' => 'smtp.gmail.com', 'mail_encryption' => 'tls', 'mail_username' => 'me@example.com', 'mail_password' => 'a-long-password-123']));
ok('...and nobody else is told that', !array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'Microsoft')));

// Microsoft's error codes in plain words, and what to try. The codes are the ones Microsoft documents.
$say = static fn(string $error, string $text): array => MailOAuth::explain($error, $text);
ok('a wrong client secret says to use the Value, not the Secret ID', str_contains($say('invalid_client', 'AADSTS7000215: Invalid client secret provided.')[0], 'secret is wrong') && str_contains($say('invalid_client', 'AADSTS7000215: x')[1], 'Value'));
ok('an expired secret says to make a new one', str_contains($say('invalid_client', 'AADSTS7000222: The provided client secret keys are expired.')[0], 'expired'));
ok('an unknown client ID points at the two IDs on the Overview page', str_contains($say('invalid_request', 'AADSTS700016: Application not found')[1], 'Overview'));
ok('a missing tenant is explained', str_contains($say('invalid_request', 'AADSTS90002: Tenant not found')[1], 'MAIL_OAUTH_TENANT'));
ok('consent that is missing says where to grant it', str_contains($say('invalid_grant', 'AADSTS65001: The user or administrator has not consented')[1], 'Grant admin consent'));
ok('"public client flows" being off is explained for the connect way', str_contains($say('invalid_client', 'AADSTS7000218: The request body must contain the following parameter: client_assertion or client_secret.')[1], 'Allow public client flows'));
ok('a lapsed saved sign-in says to connect again', str_contains($say('invalid_grant', 'AADSTS700082: The refresh token has expired due to inactivity.')[1], 'Connect again'));
ok('a Conditional Access block is named as one', str_contains($say('interaction_required', 'AADSTS53003: Access has been blocked by Conditional Access policies.')[0], 'Conditional Access'));
ok('a refusal with no known code still gets a sentence by its OAuth name', $say('authorization_declined', '')[0] !== '' && $say('expired_token', '')[0] !== '' && $say('invalid_scope', '')[0] !== '');
ok('and an error nobody has heard of is left to Microsoft\'s own words', $say('some_new_error', 'AADSTS999999: Something new.') === ['', '']);

// The id_token only supplies the account's address for display; whatever it holds is checked before use.
$jwt = static fn(array $claims): string => 'x.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.y';
ok('the signed-in account is read from the id_token', MailOAuth::accountFrom($jwt(['preferred_username' => 'boss@contoso.example'])) === 'boss@contoso.example');
ok('...falling back to email, ignoring a claim that is not an address, and tolerating junk', MailOAuth::accountFrom($jwt(['preferred_username' => 'not an address', 'email' => 'e@contoso.example'])) === 'e@contoso.example'
    && MailOAuth::accountFrom($jwt(['preferred_username' => "a@b.example\r\nBcc: x@y.example"])) === '' && MailOAuth::accountFrom('nonsense') === '' && MailOAuth::accountFrom('') === '');

// The example file people copy.
echo "\n== The Microsoft 365 example file ==\n";
$m365 = $root . '/docs/config-examples/microsoft365.php';
ok('docs/config-examples/microsoft365.php exists', is_file($m365));
if (is_file($m365)) {
    ob_start();
    $example = (static fn() => require $m365)();
    $printed = (string) ob_get_clean();
    ok('it returns an array and prints nothing', is_array($example) && $printed === '', $printed);
    $example = (array) $example;
    ok('every setting in it is one the application reads', array_diff(array_keys($example), array_keys($baseConfig)) === [], implode(',', array_diff(array_keys($example), array_keys($baseConfig))));
    $GLOBALS['config'] = array_replace($baseConfig, $example);
    $s = Mailer::settings();
    ok('it switches email on, through Microsoft 365, and the settings code has nothing to complain about', Mailer::enabled() && $s['oauth'] === 'Microsoft 365' && $s['driver'] === 'smtp' && Mailer::problems() === [], json_encode($s) . ' ' . implode(' | ', Mailer::problems()));
    ok('it holds no password (the point of it), and its secrets are obvious placeholders', !isset($example['mail_password']) && str_starts_with((string) $example['mail_oauth_client_secret'], 'the-')
        && ($example['mail_oauth_tenant'] ?? '') === '00000000-0000-0000-0000-000000000000' && ($example['mail_oauth_client_id'] ?? '') === '00000000-0000-0000-0000-000000000000');
    $guideText = (string) file_get_contents($root . '/docs/EMAIL.md');
    $unexplained = array_filter(array_keys($example), static fn($k) => str_starts_with((string) $k, 'mail_') && !str_contains($guideText, "'" . $k . "'"));
    ok('every mail setting in it is explained in docs/EMAIL.md', $unexplained === [], implode(',', $unexplained));
    $GLOBALS['config'] = $baseConfig;
}

// ================================================================== the example files
echo "\n== The example configuration files people copy ==\n";
// docs/config-examples/*.php are what the README and the guides tell people to copy to
// config.local.php. A typo in one (mail_hots) is ignored without a word, so the site would simply
// never send, and nobody would know why: so every key is checked against the ones the application
// reads, and each file is run through the same settings code the site uses.
$mailKeys = array_values(array_filter(array_keys($baseConfig), static fn($k) => str_starts_with((string) $k, 'mail_') && $k !== 'mail_log_file'));
$guide = (string) file_get_contents($root . '/docs/EMAIL.md');
$deploy = (string) file_get_contents($root . '/docs/DEPLOYMENT.md');
foreach (['live' => 'smtp', 'local' => 'log'] as $name => $driver) {
    $file = $root . "/docs/config-examples/{$name}.php";
    ok("docs/config-examples/{$name}.php exists", is_file($file));
    if (!is_file($file)) continue;
    ob_start();
    $example = (static fn() => require $file)();
    $printed = (string) ob_get_clean();
    ok("{$name}: it returns an array and prints nothing, so it is harmless if requested by mistake", is_array($example) && $printed === '', $printed);
    $example = (array) $example;
    $unknown = array_diff(array_keys($example), array_keys($baseConfig));
    ok("{$name}: every setting in it is one the application reads (a typo would be ignored in silence)", $unknown === [], implode(', ', $unknown));
    $GLOBALS['config'] = array_replace($baseConfig, $example);
    $s = Mailer::settings();
    ok("{$name}: email is switched on and uses the {$driver} method", Mailer::enabled() && $s['driver'] === $driver, json_encode($s));
    ok("{$name}: the settings code finds nothing to complain about", Mailer::problems() === [], implode(' | ', Mailer::problems()));
    ok("{$name}: the sender is a valid address", (bool) filter_var($s['from'], FILTER_VALIDATE_EMAIL), $s['from']);
    if ($driver === 'smtp') {
        ok("{$name}: it names a mail server (port 587, STARTTLS) and signs in", $s['host'] !== '' && $s['port'] === 587 && $s['encryption'] === 'tls' && $s['username'] !== '' && $s['has_password'], json_encode($s));
        ok("{$name}: it says it is the real website: production, with the public address reset links are built from", ($example['app_env'] ?? '') === 'production' && str_starts_with((string) ($example['app_url'] ?? ''), 'https://') && !str_ends_with((string) ($example['app_url'] ?? ''), '/'));
        ok("{$name}: the database it names is MySQL (production refuses anything else)", ($example['db_driver'] ?? '') === 'mysql');
        $placeholders = array_filter(['mail_host', 'mail_username', 'mail_password', 'db_pass'], static fn($k) => str_contains((string) ($example[$k] ?? ''), 'example') || str_starts_with((string) ($example[$k] ?? ''), 'the-'));
        ok("{$name}: every secret in it is an obvious placeholder, never a real password", count($placeholders) === 4, implode(',', $placeholders));
    } else {
        ok("{$name}: it says it is a development machine, with a database that needs no server", ($example['app_env'] ?? '') === 'local' && ($example['db_driver'] ?? '') === 'sqlite');
        $logFile = sys_get_temp_dir() . '/cedar-example-mail-' . bin2hex(random_bytes(4)) . '.log';
        $GLOBALS['config']['mail_log_file'] = $logFile;
        $sentLocal = Mailer::send('dev@example.test', 'From the example settings', 'Reset link: http://cedar.test/reset-password?token=abc');
        ok("{$name}: a message really goes to the log, readable, with its link", $sentLocal && is_file($logFile) && str_contains((string) file_get_contents($logFile), 'http://cedar.test/reset-password?token=abc'), Mailer::lastError());
        if (is_file($logFile)) unlink($logFile);
    }
    $unexplained = array_filter(array_keys($example), static fn($k) => str_starts_with((string) $k, 'mail_') && !str_contains($guide, "'" . $k . "'"));
    ok("{$name}: every mail setting in it is explained in docs/EMAIL.md", $unexplained === [], implode(',', $unexplained));
    $GLOBALS['config'] = $baseConfig;
}
// The other direction: every mail setting the application reads must be written up where an operator looks.
foreach ($mailKeys as $key) {
    $env = strtoupper((string) $key);
    ok("{$env} is documented in docs/EMAIL.md (a row of the settings table, both spellings) and in the table in docs/DEPLOYMENT.md",
        preg_match('/^\| `' . preg_quote($env, '/') . '` \| `\'' . preg_quote((string) $key, '/') . '\'/m', $guide) === 1 && preg_match('/^\| `' . preg_quote($env, '/') . '`\s*\|/m', $deploy) === 1);
}
preg_match_all("/getenv\\('(MAIL_[A-Z_]+)'\\)/", (string) file_get_contents($root . '/app/config.php'), $found);
$envNames = array_values(array_unique($found[1]));
$expectedNames = array_map(static fn($k) => strtoupper((string) $k), $mailKeys);
ok('the environment variable behind each mail setting is that setting\'s own name in capitals, no more and no fewer (so the guides are right about both)', !array_diff($expectedNames, $envNames) && !array_diff($envNames, $expectedNames) && count($envNames) >= 10, implode(',', $envNames));
// The table of failure messages is only any use if the messages in it are the ones people really see.
$tableAt = strpos($guide, '## When the test fails');
$table = substr($guide, (int) $tableAt, (int) strpos($guide, '## What has and has not been tested') - (int) $tableAt);
$said = '';
foreach (array_merge(glob($root . '/app/Core/*.php') ?: [], glob($root . '/bin/*.php') ?: []) as $sourceFile) $said .= file_get_contents($sourceFile) . "\n";
$said = strtolower(str_replace("\\'", "'", $said));
// Quoted from Microsoft's own mail server, whose words the code shows but does not write.
$theirWords = ['5.7.139', 'smtpclientauthentication is disabled', '550 5.7.30 basic authentication is not supported'];
$drifted = [];
$rowsChecked = 0;
foreach (preg_split('/\R/', $table) ?: [] as $row) {
    if (!str_starts_with($row, '| *')) continue;
    $rowsChecked++;
    preg_match_all('/\*([^*]+)\*/u', explode('|', $row)[1], $pieces);
    foreach ($pieces[1] as $piece) {
        foreach (preg_split('/…|\.\.\./u', $piece) ?: [] as $part) {
            $part = strtolower(trim((string) preg_replace('/\s+\(.*$/', '', $part), " :,"));
            $part = (string) preg_replace('/^(microsoft would not issue an access token:\s*|microsoft 365 )/', '', $part); // the front is shared; what follows is Microsoft's explanation, or the label of the account
            if (strlen($part) < 6 || in_array($part, $theirWords, true)) continue;
            if (!str_contains($said, $part)) $drifted[] = $part;
        }
    }
}
ok('every message in the "When the test fails" table is one the code can really print, not a paraphrase of it (' . $rowsChecked . ' rows)', $drifted === [] && $rowsChecked >= 20, implode(' | ', $drifted));

// ================================================================== message
echo "\n== The message itself ==\n";
$raw = Mailer::render('noreply@example.com', 'Cedar Tranquility Real Estate', 'office@example.com', 'New property enquiry · CTE-1234ABCD', $sample);
$m = parseMessage($raw);
ok('every line ends CRLF, none is longer than 998 characters', !preg_match('/(?<!\r)\n/', $raw) && max(array_map('strlen', explode("\r\n", $raw))) <= 998);
ok('body lines are at most 76 characters (a 5,000-character word included)', max(array_map('strlen', explode("\r\n", $m['body']))) <= 76);
ok('the body round-trips exactly (dash, pound, é, leading dots, trailing spaces)', quoted_printable_decode($m['body']) === crlf($sample) . "\r\n");
ok('no line of the message starts with a dot, so no transport can take one for the end of it', !preg_match('/(^|\r\n)\./', $raw)
    && str_contains($m['body'], "\r\n=2EThis line starts with a dot\r\n") && str_contains($m['body'], "\r\n=2E.and this with two\r\n"), $m['body']);
ok('the body is declared UTF-8 quoted-printable', ($m['headers']['content-type'][0] ?? '') === 'text/plain; charset=UTF-8' && ($m['headers']['content-transfer-encoding'][0] ?? '') === 'quoted-printable' && ($m['headers']['mime-version'][0] ?? '') === '1.0');
ok('a non-ASCII subject is sent as an encoded word and reads back the same', decodeWords($m['headers']['subject'][0] ?? '') === 'New property enquiry · CTE-1234ABCD' && str_starts_with($m['headers']['subject'][0] ?? '', '=?UTF-8?B?'));
ok('an ASCII subject is left readable', (parseMessage(Mailer::render('a@example.com', 'N', 'b@example.com', 'Reset your password', 'x'))['headers']['subject'][0] ?? '') === 'Reset your password');
ok('there is a Date that parses, and is current', ($t = strtotime($m['headers']['date'][0] ?? '')) !== false && abs($t - time()) < 300);
ok('there is a Message-ID at the sender\'s domain, different each time', (bool) preg_match('/^<[0-9a-f]{24}@example\.com>$/', $m['headers']['message-id'][0] ?? '')
    && ($m['headers']['message-id'][0] ?? 'a') !== (parseMessage(Mailer::render('noreply@example.com', 'N', 'b@example.com', 's', 'x'))['headers']['message-id'][0] ?? 'b'));
ok('To is the recipient and From carries the sender', ($m['headers']['to'][0] ?? '') === 'office@example.com' && str_ends_with($m['headers']['from'][0] ?? '', '<noreply@example.com>'));

$long = 'Enquiry · ' . str_repeat('é', 70);
$words = 0; $longest = 0;
$h = parseMessage(Mailer::render('a@example.com', 'N', 'b@example.com', $long, 'x'))['headers']['subject'][0] ?? '';
$decoded = decodeWords($h, $longest);
ok('a long non-ASCII subject is split into encoded words of at most 75 characters', $longest > 0 && $longest <= 75, 'longest ' . $longest);
ok('...cut between characters, so it reads back whole', $decoded === $long && mb_check_encoding($decoded, 'UTF-8'));

$names = [
    'plain name' => 'Cedar Tranquility Real Estate',
    'commas, quotes and angle brackets' => 'Smith, "Sons" & Co. <Realty>',
    'a backslash' => 'Back\\slash Realty',
    'accents and a dash' => 'Cédar Réal Estate — Lagos',
    'a very long accented name' => str_repeat('Ünïcode ', 25),
    'an attempt to inject a header' => "Evil\r\nBcc: attacker@example.com",
    'an attempt using a bare line feed' => "Evil\nBcc: attacker@example.com",
    'control characters' => "Tab\there\x00nul",
];
foreach ($names as $label => $name) {
    $p = parseMessage(Mailer::render('noreply@example.com', $name, 'office@example.com', 'Hello', 'Body'));
    $from = $p['headers']['from'][0] ?? '';
    $scrubbed = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));
    $longest = 0;
    $fromName = preg_match('/^(.*) <noreply@example\.com>$/s', $from, $mm) ? $mm[1] : null;
    if ($fromName !== null && str_starts_with($fromName, '"')) {
        $read = stripcslashes(substr($fromName, 1, -1));
        $shape = (bool) preg_match('/^"(?:[^"\\\\]|\\\\.)*"$/', $fromName);
    } else {
        $read = $fromName === null ? '' : decodeWords($fromName, $longest);
        $shape = $longest <= 75 && ($fromName === null || !preg_match('/[^\x20-\x7E]/', $fromName));
    }
    ok("From is well formed and reads back for {$label}", $fromName !== null && $shape && $read === $scrubbed && count($p['headers']['from'] ?? []) === 1, $from);
    ok("...and cannot add a header ({$label})", !isset($p['headers']['bcc']) && count(array_filter(explode("\r\n", $p['head']), static fn($l) => stripos($l, 'bcc:') === 0)) === 0);
}

$rt = static fn(string $replyTo): array => parseMessage(Mailer::render('noreply@example.com', 'N', 'office@example.com', 'Hello', 'Body', $replyTo))['headers'];
ok('a Reply-To is added for a plain address, and From stays the site', ($rt('visitor@example.org')['reply-to'][0] ?? '') === 'visitor@example.org' && str_ends_with($rt('visitor@example.org')['from'][0] ?? '', '<noreply@example.com>'));
ok('no Reply-To when none is given', !isset($rt('')['reply-to']));
// Includes the shape of address behind the PHPMailer/mail() command-injection advisories: PHP's own
// FILTER_VALIDATE_EMAIL accepts a quoted local part holding escaped spaces and quotes.
$hostileReplyTos = ["a@example.org\r\nBcc: attacker@example.com", "a@example.org\nBcc: attacker@example.com", '"a\\ b"@example.org', '"a\\ b\\ -X/tmp/pwned"@example.com',
          '"a\\"\\ -oQ/tmp/\\ -X/var/www/x.php\\ some"@example.com', 'a@example.org, b@example.org', 'Name <a@example.org>', 'not an address', "a@exa\x00mple.org"];
foreach ($hostileReplyTos as $bad) {
    $h = parseMessage(Mailer::render('noreply@example.com', 'N', 'office@example.com', 'Hello', 'Body', $bad));
    ok('a Reply-To that is not a plain address is dropped, never written to a header: ' . json_encode($bad), !isset($h['headers']['reply-to']) && !isset($h['headers']['bcc']) && count($h['headers']['from'] ?? []) === 1);
}

// ================================================================== a host that has switched off network functions
echo "\n== Hosts that disable PHP network functions ==\n";
ok('on this server nothing SMTP needs is switched off', SmtpClient::unavailable('tls') === '' && SmtpClient::unavailable('ssl') === '' && SmtpClient::unavailable('none') === '');
$without = static fn(string ...$gone): callable => static fn(string $function): bool => !in_array($function, $gone, true);
$one = SmtpClient::unavailable('tls', $without('stream_socket_client'));
ok('a host that disables stream_socket_client gets a sentence that names it and says what to do', str_contains($one, 'stream_socket_client') && str_contains($one, 'disable_functions') && str_contains($one, 'MAIL_DRIVER=mail') && !str_contains($one, 'Call to undefined'), $one);
ok('...and several at once are listed together', str_contains(SmtpClient::unavailable('tls', $without('stream_socket_client', 'stream_set_timeout')), 'stream_socket_client, stream_set_timeout'));
ok('STARTTLS needs stream_socket_enable_crypto, while plain and TLS-from-the-start do not', SmtpClient::unavailable('tls', $without('stream_socket_enable_crypto')) !== '' && SmtpClient::unavailable('ssl', $without('stream_socket_enable_crypto')) === '' && SmtpClient::unavailable('none', $without('stream_socket_enable_crypto')) === '');

// ================================================================== log driver
echo "\n== The log driver (local development) ==\n";
$logFile = sys_get_temp_dir() . '/cedar-mail-log-' . bin2hex(random_bytes(4)) . '.log';
mailConfig(['mail_driver' => 'log', 'mail_log_file' => $logFile, 'app_env' => 'local']);
clearErrorLog();
$sent = Mailer::send('office@example.com', 'Reset your password', "Open this link:\nhttp://localhost/reset-password?token=abc123\nIt expires in an hour.", 'visitor@example.org');
$written = is_file($logFile) ? (string) file_get_contents($logFile) : '';
ok('writing to the log succeeds', $sent === true && Mailer::lastError() === '', Mailer::lastError());
ok('the message is readable there: recipient, subject and the link, undisguised', str_contains($written, 'To: office@example.com') && str_contains($written, 'Subject: Reset your password') && str_contains($written, 'http://localhost/reset-password?token=abc123'));
ok('and says plainly that nothing was sent', str_contains($written, 'not sent'));
ok('a Reply-To is shown too', str_contains($written, 'Reply-To: visitor@example.org'));
// The log is a file a person reads for reset links, so a Reply-To must not be able to forge lines in it.
foreach ($hostileReplyTos as $bad) {
    @unlink($logFile);
    $sentBad = Mailer::send('office@example.com', 'Hostile reply-to', 'Body', $bad);
    $loggedBad = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    ok('the log driver drops a Reply-To that is not a plain address, and writes no forged line: ' . json_encode($bad),
        $sentBad === true && !str_contains($loggedBad, 'Reply-To:') && !preg_match('/^(Bcc|Cc):/mi', $loggedBad) && !str_contains($loggedBad, 'attacker@example.com') && substr_count($loggedBad, "\nTo: ") === 1, $loggedBad);
}
Mailer::send('second@example.com', 'Another', 'Second message');
ok('later messages are appended, not overwritten', substr_count($written = (string) file_get_contents($logFile), '=== ') === 2);
file_put_contents($logFile, str_repeat('x', 1048577));
Mailer::send('office@example.com', 'After rotation', 'Fresh');
ok('a log past 1 MB is rotated, not left to grow forever', is_file($logFile . '.1') && filesize($logFile) < 2000 && str_contains((string) file_get_contents($logFile), 'After rotation'));
ok('a successful log write leaves nothing in the error log', errorLogText() === '', errorLogText());
mailConfig(['mail_driver' => 'log', 'mail_log_file' => $logFile, 'app_env' => 'production']);
clearErrorLog();
unlink($logFile); if (is_file($logFile . '.1')) unlink($logFile . '.1');
ok('in production the log driver refuses (it would hold live reset links)', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'refused in production') && !is_file($logFile));
mailConfig(['mail_driver' => 'log', 'mail_log_file' => '/dev/null/cannot/mail.log', 'app_env' => 'local']);
ok('an unwritable log path is a clear failure, not a warning', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'Could not write') && !preg_match('/PHP (Warning|Notice|Deprecated)/', errorLogText()), Mailer::lastError());

// ================================================================== refusals
echo "\n== Refusals that need no server ==\n";
clearErrorLog();
mailConfig(['mail_enabled' => false]);
ok('switched off: nothing is sent, the reason says why', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'MAIL_ENABLED') && str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_ENABLED=true'));
ok('...and a deliberate choice is not logged as an error', errorLogText() === '', errorLogText());
mailConfig();
ok('a recipient that is not an address is refused', Mailer::send('not-an-address', 'x', 'y') === false && str_contains(Mailer::lastError(), 'recipient'));
ok('a recipient with a line break is refused', Mailer::send("a@example.com\r\nBcc: b@example.com", 'x', 'y') === false);
ok('a subject with a line break is refused (header injection)', Mailer::send('office@example.com', "Hi\r\nBcc: attacker@example.com", 'y') === false && str_contains(Mailer::lastError(), 'line break'));
mailConfig(['mail_from' => 'nobody']);
ok('no usable sender address is refused, naming the setting', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'MAIL_FROM'));
mailConfig(['mail_driver' => 'smtp', 'mail_host' => '']);
ok('SMTP with no host is refused, naming the setting', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'MAIL_HOST'));
mailConfig(['mail_driver' => 'pigeon']);
ok('an unknown driver is refused, naming the choices', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'mail, smtp or log'));
ok('every refusal that is a fault leaves one plain line in the error log', substr_count(errorLogText(), 'Mail not sent (') >= 5 && !preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', errorLogText()));

echo "\n== Failure messages in plain words ==\n";
$explain = [
    'getaddrinfo for mail.nope.invalid failed: Name or service not known' => 'could not be found',
    'php_network_getaddresses: getaddrinfo failed: No such host is known.' => 'could not be found',
    'Connection refused' => 'connection refused',
    'No connection could be made because the target machine actively refused it' => 'connection refused',
    'Connection timed out' => 'timed out',
    'A connection attempt failed because the connected party did not properly respond after a period of time' => 'timed out',
    'certificate verify failed' => 'certificate could not be verified',
    'self-signed certificate in certificate chain' => 'certificate could not be verified',
    '' => 'no further detail',
];
foreach ($explain as $raw => $expect) {
    $said = SmtpClient::explain((string) $raw);
    ok('"' . ($raw === '' ? '(nothing)' : substr($raw, 0, 48)) . '" is explained', str_contains($said, $expect), $said);
}
ok('PHP\'s own wording is kept in brackets for whoever has to search for it', str_contains(SmtpClient::explain('Connection refused'), '[Connection refused]'));
foreach ([
    'nothing is listening there (connection refused)' => 'MAIL_PORT',
    'the connection timed out' => 'block outgoing mail ports',
    'its TLS certificate could not be verified' => 'MAIL_VERIFY_PEER',
    'Sign-in was refused (check MAIL_USERNAME and MAIL_PASSWORD): 535' => 'app password',
    'The server refused the sender address' => 'MAIL_FROM',
    'The server refused the recipient address' => 'MAIL_USERNAME',
    'The server does not offer STARTTLS' => 'MAIL_ENCRYPTION=ssl',
    'The server does not offer to sign in on this connection. If it wants encryption first' => 'MAIL_ENCRYPTION=tls',
    'PHP\'s mail() could not pass the message to a mail program' => 'MAIL_DRIVER=smtp',
] as $error => $needle) {
    ok('advice for "' . substr($error, 0, 42) . '..."', str_contains(Mailer::hint($error), $needle), Mailer::hint($error));
}
ok('nothing unhelpful is invented for an error with no known advice', Mailer::hint('The server did not accept the message: 451 try later') === '');

// ================================================================== real SMTP
echo "\n== SMTP against a real server ==\n";
$sink = startSink($root);
$port = static fn(string $name): int => (int) ($GLOBALS['sink']['ports'][$name] ?? 0);
// ================================================================== mail() on Windows: the advice
echo "\n== The advice for mail() on Windows ==\n";
$never = static function (string $h, int $p): bool { throw new LogicException('must not be probed'); };
ok('on Linux and macOS there is nothing to say, and nothing is probed', Mailer::windowsMailNote('Linux', '', 'localhost', 25, $never) === '' && Mailer::windowsMailNote('Darwin', '', 'localhost', 25, $never) === '');
ok('where a mail program is set up (Laragon, XAMPP with sendmail), there is nothing to say and nothing is probed', Mailer::windowsMailNote('Windows', 'C:/laragon/bin/sendmail/sendmail.exe -t -i', 'localhost', 25, $never) === '');
ok('where something answers at the address in php.ini (a mail catcher), nobody is warned', Mailer::windowsMailNote('Windows', '', 'localhost', 1025, static fn(string $h, int $p): bool => $h === 'localhost' && $p === 1025) === '');
$note = Mailer::windowsMailNote('Windows', '', 'mail.nowhere.test', 2525, static fn(): bool => false);
ok('where nothing answers, the advice names the address PHP will use and the ways out', str_contains($note, 'mail.nowhere.test:2525') && str_contains($note, 'nothing answers') && str_contains($note, "Laragon's Mail Catcher") && str_contains($note, 'MAIL_DRIVER=log') && str_contains($note, 'MAIL_PORT=1025'), $note);
ok('...and an empty php.ini setting is read as PHP reads it: localhost, port 25', str_contains(Mailer::windowsMailNote('Windows', '  ', '', 0, static fn(): bool => false), 'localhost:25'));
ok('...in printable text, with no password or path in it', !preg_match('/[^\x20-\x7E]/', $note) && !str_contains($note, ROOT_PATH));
if ($sink !== null) {
    ok('the real check finds a server that is listening', Mailer::windowsMailNote('Windows', '', '127.0.0.1', $port('plain')) === '');
    $gone = stream_socket_server('tcp://127.0.0.1:0');
    $gonePort = (int) substr((string) stream_socket_get_name($gone, false), strrpos((string) stream_socket_get_name($gone, false), ':') + 1);
    fclose($gone);
    $started = microtime(true);
    $goneNote = Mailer::windowsMailNote('Windows', '', '127.0.0.1', $gonePort);
    if (PHP_OS_FAMILY !== 'Windows' && php_uname('s') === 'Emscripten') {
        skipped('the real check reports a port nobody listens on', 'the WebAssembly PHP used for local runs does not refuse connections the way PHP does; CI runs this');
    } else {
        ok('...and reports a port nobody listens on, within a couple of seconds', $goneNote !== '' && microtime(true) - $started < 3 && str_contains($goneNote, '127.0.0.1:' . $gonePort), $goneNote);
    }
}

if ($sink === null) {
    skipped('every real-socket check below', 'python3 could not be started here (it runs tests/fixtures/smtp-sink.py); set MAIL_TEST_SINKS to use a running sink');
} else {
    $secret = 's3cret-pass';
    $base = fn(string $scenario, array $o = []): array => $o + ['mail_port' => $port($scenario)];
    $auth = ['mail_username' => 'mailer', 'mail_password' => $secret];

    // --- plain, no sign-in: a local mail catcher
    clearErrorLog();
    mailConfig($base('plain'));
    $subject = 'Enquiry · CTE-0A1B2C3D';
    $sent = Mailer::send('office@example.com', $subject, $sample);
    $got = sinkMessages($sink, 'plain');
    ok('a message is handed to a plain server (a local mail catcher)', $sent === true && count($got) === 1, Mailer::lastError());
    $first = $got[0] ?? ['mail_from' => '', 'rcpts' => [], 'data' => '', 'tls' => null, 'ehlo' => ''];
    ok('the envelope names the sender and the one recipient', $first['mail_from'] === 'noreply@example.com' && $first['rcpts'] === ['office@example.com']);
    ok('it introduced itself with a host name', preg_match('/^[A-Za-z0-9.\-]+$/', (string) $first['ehlo']) === 1 && $first['ehlo'] !== '', (string) $first['ehlo']);
    $received = parseMessage((string) $first['data']);
    ok('the server received the message byte for byte as rendered, minus its random parts', quoted_printable_decode($received['body']) === crlf($sample) . "\r\n");
    ok('a line starting with a dot survived the wire', str_contains(quoted_printable_decode($received['body']), "\r\n.This line starts with a dot\r\n") && str_contains(quoted_printable_decode($received['body']), "\r\n..and this with two\r\n"));
    // The client's own protection, with a message that has not been through Mailer's encoding:
    $raw = "Subject: dots\r\n\r\nbefore\r\n.\r\n.hidden line\r\n..two dots\r\nafter";
    $client = new SmtpClient(['host' => '127.0.0.1', 'port' => $port('plain'), 'encryption' => 'none', 'timeout' => 3]);
    $beforeCount = count(sinkMessages($sink, 'plain'));
    $sentRaw = $client->send('noreply@example.com', 'office@example.com', $raw);
    $rawGot = sinkMessages($sink, 'plain')[$beforeCount]['data'] ?? '';
    ok('a lone dot, a leading dot and two leading dots in a raw message arrive exactly as written (dot-stuffing), and the message is not cut short', $sentRaw === true && $rawGot === $raw . "\r\n", json_encode($rawGot));
    ok('the subject arrived intact', decodeWords($received['headers']['subject'][0] ?? '') === $subject);
    ok('a success clears the last error and leaves the error log empty', Mailer::lastError() === '' && errorLogText() === '', errorLogText());
    ok('the conversation is kept for diagnosis', in_array('C: DATA', Mailer::conversation(), true) && (bool) array_filter(Mailer::conversation(), static fn($l) => str_starts_with($l, 'S: 250')));

    $before = sinkSaw($sink, 'plain', 'connect');
    Mailer::send('office@example.com', "Hi\r\nBcc: attacker@example.com", 'y');
    ok('a refused (injected) subject never even connects', sinkSaw($sink, 'plain', 'connect') === $before);
    mailConfig($base('plain'));
    foreach ($hostileReplyTos as $bad) {
        $before = count(sinkMessages($sink, 'plain'));
        $sentBad = Mailer::send('office@example.com', 'Hostile reply-to', 'Body', $bad);
        $gotBad = array_slice(sinkMessages($sink, 'plain'), $before);
        $headersBad = parseMessage((string) ($gotBad[0]['data'] ?? ''));
        ok('over SMTP a Reply-To that is not a plain address is dropped (the message still goes, with no extra header): ' . json_encode($bad),
            $sentBad === true && count($gotBad) === 1 && ($gotBad[0]['rcpts'] ?? []) === ['office@example.com'] && !isset($headersBad['headers']['reply-to']) && !isset($headersBad['headers']['bcc']) && count($headersBad['headers']['from'] ?? []) === 1, Mailer::lastError());
    }
    // The SMTP client does not trust its callers either: an address that could carry a second
    // command (a line break, angle brackets) is refused before any connection is made.
    $rawClient = new SmtpClient(['host' => '127.0.0.1', 'port' => $port('plain'), 'encryption' => 'none', 'timeout' => 3]);
    foreach (["a@example.com\r\nRCPT TO:<evil@example.net>", 'a@example.com>', "a@example.com\0", ''] as $badEnvelope) {
        $before = sinkSaw($sink, 'plain', 'connect');
        $sentBad = $rawClient->send($badEnvelope, 'office@example.com', "Subject: x\r\n\r\ny\r\n") || $rawClient->send('noreply@example.com', $badEnvelope, "Subject: x\r\n\r\ny\r\n");
        ok('the SMTP client refuses an envelope address that could smuggle a command, without connecting: ' . json_encode($badEnvelope),
            $sentBad === false && str_contains($rawClient->error(), 'envelope') && sinkSaw($sink, 'plain', 'connect') === $before, $rawClient->error());
    }

    // --- sign-in
    mailConfig($base('auth', $auth));
    $sent = Mailer::send('office@example.com', 'Signed in', 'body');
    $got = sinkMessages($sink, 'auth');
    ok('signing in with AUTH PLAIN works, and the server knows who sent the message', $sent === true && ($got[0]['authed'] ?? '') === 'mailer', Mailer::lastError());
    $talk = implode("\n", Mailer::conversation());
    ok('the password and its base64 form are never kept in the conversation', !str_contains($talk, $secret) && !str_contains($talk, base64_encode("\0mailer\0" . $secret)) && str_contains($talk, 'AUTH PLAIN ***'));
    mailConfig($base('loginonly', $auth));
    $sent = Mailer::send('office@example.com', 'Login only', 'body');
    ok('a server that only offers AUTH LOGIN still works', $sent === true && count(sinkMessages($sink, 'loginonly')) === 1 && sinkSaw($sink, 'loginonly', 'auth_ok') === 1, Mailer::lastError());
    ok('...and AUTH LOGIN is masked in the conversation too', !str_contains(implode("\n", Mailer::conversation()), base64_encode($secret)));
    mailConfig($base('unsupported', $auth));
    ok('a server offering only mechanisms we lack is refused, listing them', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'XOAUTH2'), Mailer::lastError());

    clearErrorLog();
    $wrong = 'Wr0ng-Sentinel-Pw';
    mailConfig($base('auth', ['mail_username' => 'mailer', 'mail_password' => $wrong]));
    $sent = Mailer::send('office@example.com', 'Wrong password', 'body');
    $leak = Mailer::lastError() . implode("\n", Mailer::conversation()) . errorLogText();
    ok('a wrong password is refused with a plain explanation', $sent === false && str_contains(Mailer::lastError(), 'Sign-in was refused') && str_contains(Mailer::lastError(), '535'), Mailer::lastError());
    ok('...no message was delivered, and the advice names the settings', sinkSaw($sink, 'auth', 'auth_failed') === 1 && count(sinkMessages($sink, 'auth')) === 1 && str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_PASSWORD'));
    ok('...and the password is in neither the error, the conversation nor the log', !str_contains($leak, $wrong) && !str_contains($leak, base64_encode("\0mailer\0" . $wrong)));
    mailConfig($base('auth'));
    ok('a server that wants sign-in refuses a message sent without it, and we say so', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), '530'), Mailer::lastError());

    // --- STARTTLS
    mailConfig($base('starttls', $auth + ['mail_encryption' => 'tls', 'mail_verify_peer' => false]));
    $sent = Mailer::send('office@example.com', 'Over STARTTLS', 'body');
    $got = sinkMessages($sink, 'starttls');
    ok('STARTTLS then sign-in then the message works (the port-587 pattern)', $sent === true && count($got) === 1 && ($got[0]['tls'] ?? false) === true && ($got[0]['authed'] ?? '') === 'mailer', Mailer::lastError());
    ok('it greeted the server again once encrypted', sinkSaw($sink, 'starttls', 'ehlo') === 2 && sinkSaw($sink, 'starttls', 'starttls_ok') === 1);
    ok('sign-in happened only after encryption', array_values(array_filter(sinkEvents($sink, 'starttls'), static fn($e) => ($e['event'] ?? '') === 'auth_ok'))[0]['tls'] ?? false);

    $mark = count(sinkEvents($sink, 'starttls'));
    clearErrorLog();
    mailConfig($base('starttls', $auth + ['mail_encryption' => 'tls', 'mail_verify_peer' => true]));
    $sent = Mailer::send('office@example.com', 'Unverified', 'body');
    $after = array_slice(sinkEvents($sink, 'starttls'), $mark);
    ok('a certificate that cannot be verified is refused', $sent === false && str_contains(Mailer::lastError(), 'certificate'), Mailer::lastError());
    ok('...before the password is sent anywhere', !array_filter($after, static fn($e) => in_array($e['event'] ?? '', ['auth_attempt', 'auth_ok', 'message'], true)));
    ok('...with advice, and no PHP warning in the log', str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_VERIFY_PEER') && !preg_match('/PHP (Warning|Notice|Deprecated)/', errorLogText()), errorLogText());
    mailConfig($base('starttls', $auth + ['mail_host' => 'localhost', 'mail_encryption' => 'tls', 'mail_verify_peer' => true, 'mail_cafile' => $sink['cert']]));
    ok('the same server is accepted when its certificate is trusted (MAIL_CAFILE) and the name matches', Mailer::send('office@example.com', 'Verified', 'body') === true, Mailer::lastError());
    mailConfig($base('starttls', $auth + ['mail_host' => '127.0.0.1', 'mail_encryption' => 'tls', 'mail_verify_peer' => true, 'mail_cafile' => $sink['cert']]));
    ok('...and by address, since the certificate lists 127.0.0.1', Mailer::send('office@example.com', 'Verified by address', 'body') === true, Mailer::lastError());

    // --- implicit TLS
    mailConfig($base('implicit', $auth + ['mail_encryption' => 'ssl', 'mail_verify_peer' => false]));
    $sent = Mailer::send('office@example.com', 'Over TLS', 'body');
    $got = sinkMessages($sink, 'implicit');
    ok('TLS from the first byte works (the port-465 pattern)', $sent === true && ($got[0]['tls'] ?? false) === true && ($got[0]['authed'] ?? '') === 'mailer', Mailer::lastError());
    mailConfig($base('implicit', $auth + ['mail_encryption' => 'ssl', 'mail_verify_peer' => true]));
    $sent = Mailer::send('office@example.com', 'Unverified TLS', 'body');
    ok('an unverifiable certificate is refused here too, and nothing is delivered', $sent === false && str_contains(Mailer::lastError(), 'certificate') && count(sinkMessages($sink, 'implicit')) === 1, Mailer::lastError());
    mailConfig($base('implicit', $auth + ['mail_host' => 'localhost', 'mail_encryption' => 'ssl', 'mail_verify_peer' => true, 'mail_cafile' => $sink['cert']]));
    ok('...and accepted once the certificate is trusted', Mailer::send('office@example.com', 'Trusted TLS', 'body') === true, Mailer::lastError());

    // --- encryption demanded, not offered: never downgrade
    mailConfig($base('nostarttls', $auth + ['mail_encryption' => 'tls']));
    $sent = Mailer::send('office@example.com', 'No downgrade', 'body');
    ok('asked to encrypt, a server without STARTTLS is refused rather than used in the clear', $sent === false && str_contains(Mailer::lastError(), 'does not offer STARTTLS'), Mailer::lastError());
    ok('...and the password was never sent', sinkSaw($sink, 'nostarttls', 'auth_attempt') === 0 && count(sinkMessages($sink, 'nostarttls')) === 0);
    mailConfig($base('nostarttls', $auth + ['mail_encryption' => 'none']));
    ok('with encryption explicitly off, the same server works (a trusted relay)', Mailer::send('office@example.com', 'Chosen plain', 'body') === true, Mailer::lastError());

    // --- refusals and misbehaving servers
    clearErrorLog();
    mailConfig($base('reject'));
    ok('a refused recipient is reported as such, with a 550', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'refused the recipient') && str_contains(Mailer::lastError(), '550'), Mailer::lastError());
    mailConfig($base('rejectfrom'));
    ok('a refused sender names MAIL_FROM', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'refused the sender') && str_contains(Mailer::lastError(), 'noreply@example.com'), Mailer::lastError());
    mailConfig($base('greylist'));
    ok('a message deferred after DATA (451) is a failure, not a success', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), '451') && count(sinkMessages($sink, 'greylist')) === 0, Mailer::lastError());
    mailConfig($base('small'));
    ok('a message larger than the server\'s SIZE limit is refused before it is sent', Mailer::send('office@example.com', 'x', $sample) === false && str_contains(Mailer::lastError(), 'larger than the server accepts'), Mailer::lastError());
    ok('...while a small one still goes through', Mailer::send('office@example.com', 'x', 'short') === true, Mailer::lastError());
    mailConfig($base('dropbanner'));
    ok('a server that hangs up at once is reported', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'closed the connection'), Mailer::lastError());
    mailConfig($base('dropmail'));
    ok('a server that hangs up mid-conversation is reported', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'closed the connection'), Mailer::lastError());
    mailConfig($base('garbage'));
    ok('something that is not a mail server is recognised', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'does not look like a mail server'), Mailer::lastError());
    ok('...and what it said is shown as plain text only (escape codes, NUL and bytes that are not text are gone)', !preg_match('/[^\x20-\x7E]/', Mailer::lastError()) && str_contains(Mailer::lastError(), 'This is a web server'), Mailer::lastError());


    mailConfig($base('silent', ['mail_timeout' => 1]));
    $started = microtime(true);
    $sent = Mailer::send('office@example.com', 'x', 'y');
    $took = microtime(true) - $started;
    ok('a server that never answers is given up on after the timeout, not waited for', $sent === false && $took < 4.5 && str_contains(Mailer::lastError(), 'did not answer'), sprintf('%.1fs: %s', $took, Mailer::lastError()));

    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $closedPort = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
    fclose($probe);
    mailConfig(['mail_port' => $closedPort, 'mail_timeout' => 2]);
    $started = microtime(true);
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a port nothing listens on fails quickly with a reason', $sent === false && microtime(true) - $started < 5 && Mailer::lastError() !== '', Mailer::lastError());
    ok('...in words, not as raw PHP output', !preg_match('/stream_socket_client|Warning/i', Mailer::lastError()), Mailer::lastError());

    ok('through every one of those failures PHP raised no warning, notice or deprecation in the log', !preg_match('/PHP (Warning|Notice|Deprecated|Fatal)|Uncaught/', errorLogText()), substr(errorLogText(), 0, 300));
    ok('...and each of the nine failures since the log was last cleared left exactly one plain "Mail not sent" line', substr_count(errorLogText(), 'Mail not sent (smtp):') === 9 && substr_count(errorLogText(), "\n") === 9, (string) substr_count(errorLogText(), 'Mail not sent (smtp):'));
    ok('...none of them with the SMTP password in it', !str_contains(errorLogText(), $secret) && !str_contains(errorLogText(), $wrong));

    // A careless server's refusal: it echoes the password it was sent (plain and base64), adds control
    // characters and bytes that are not text, and runs on for hundreds of characters. What the operator
    // reads - on the Settings page, in the activity log, in the error log - must be a short line of plain
    // text that does not hold the password.
    $hostilePassword = 'Pw-' . bin2hex(random_bytes(6));
    clearErrorLog();
    mailConfig($base('hostile', ['mail_username' => 'mailer', 'mail_password' => $hostilePassword]));
    $sentBad = Mailer::send('office@example.com', 'x', 'y');
    $shown = Mailer::lastError();
    $everywhere = $shown . ' ' . errorLogText() . ' ' . implode(' ', Mailer::conversation());
    ok('a refusal that echoes the password, plain and base64, is shown with it masked', $sentBad === false && str_contains($shown, 'Sign-in was refused') && !str_contains($everywhere, $hostilePassword) && !str_contains($everywhere, base64_encode($hostilePassword)) && str_contains($shown, '***'), $shown);
    ok('...as plain printable text: the escape code, NUL and the bytes that are not text are gone', !preg_match('/[^\x20-\x7E]/', $shown) && str_contains($shown, 'Bad login for mailer'), bin2hex(substr($shown, 0, 80)));
    ok('...and cut to a sensible length instead of pages of server chatter', strlen($shown) <= 400 && str_contains($shown, '...'), (string) strlen($shown));
    ok('...and the error log line for it is one line, also free of the password', substr_count(rtrim(errorLogText()), "\n") === 0 && !str_contains(errorLogText(), $hostilePassword), errorLogText());
    // The SMTP client promises on its own that what it reports is safe to show (it is also used without Mailer
    // around it, and the --verbose output of bin/mail-test.php prints its conversation as it is).
    $bare = new SmtpClient(['host' => '127.0.0.1', 'port' => $port('hostile'), 'encryption' => 'none', 'username' => 'mailer', 'password' => $hostilePassword, 'timeout' => 3]);
    $sentBare = $bare->send('noreply@example.com', 'office@example.com', "Subject: x\r\n\r\ny\r\n");
    $conversationText = implode("\n", $bare->conversation());
    ok('SmtpClient on its own: its error is printable text of bounded length without the password', $sentBare === false && !preg_match('/[^\x20-\x7E]/', $bare->error()) && strlen($bare->error()) <= 300 && !str_contains($bare->error(), $hostilePassword) && !str_contains($bare->error(), base64_encode($hostilePassword)), $bare->error());
    ok('...and so is every line of the conversation it keeps (what a server says is never trusted)', $bare->conversation() !== [] && !preg_match('/[^\x20-\x7E\n]/', $conversationText) && max(array_map('strlen', $bare->conversation())) <= 303 && !str_contains($conversationText, $hostilePassword) && !str_contains($conversationText, base64_encode($hostilePassword)), substr($conversationText, 0, 200));
    mailConfig($base('plain'));
}

// ================================================================== OAuth against real sockets
echo "\n== OAuth sign-in against a stand-in for Microsoft (token service and XOAUTH2 servers) ==\n";
if ($sink === null || empty($sink['oauth']) || empty($sink['ports']['token_https'])) {
    skipped('OAuth sign-in against the token service and the XOAUTH2 servers', $sink === null ? 'needs the SMTP sink' : 'the running sink has no token service (an older tests/fixtures/smtp-sink.py?)');
} else {
    $kit = $sink['oauth'];
    $https = 'https://127.0.0.1:' . $port('token_https');
    $http = 'http://127.0.0.1:' . $port('token_http');
    $net = ['timeout' => 3, 'verify_peer' => true, 'cafile' => $sink['cert']];
    $requests = static fn(string $label): array => array_values(array_filter(sinkEvents($GLOBALS['sink'], $label),
        static fn($e) => ($e['event'] ?? '') === 'request' && !str_starts_with((string) $e['path'], '/_control/')));
    $tokenUrl = static fn(string $tenant, bool $tls = true): string => ($tls ? $GLOBALS['https'] : $GLOBALS['http']) . '/' . $tenant . '/oauth2/v2.0/token';
    $control = static fn(string $what) => HttpClient::postForm($GLOBALS['http'] . '/_control/' . $what, [], ['timeout' => 3]);
    $form = ['grant_type' => 'client_credentials', 'client_id' => $kit['app_client_id'], 'client_secret' => $kit['app_secret'], 'scope' => 'https://outlook.office365.com/.default'];
    $store = $oauthStoreFile;
    $resetStore = static function () use ($store): void { foreach (array_merge([$store], glob($store . '.*') ?: []) as $f) if (is_file($f)) unlink($f); };
    $readStore = static fn(): array => (array) json_decode(is_file($GLOBALS['store']) ? (string) file_get_contents($GLOBALS['store']) : '[]', true);
    $writeStore = static function (array $data): void { file_put_contents($GLOBALS['store'], json_encode($data)); chmod($GLOBALS['store'], 0600); };
    $appCfg = static fn(array $o = []): array => array_replace([
        'mail_driver' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => $GLOBALS['port']('oauth'), 'mail_encryption' => 'tls',
        'mail_username' => $GLOBALS['kit']['mailbox'], 'mail_password' => '', 'mail_from' => $GLOBALS['kit']['mailbox'], 'mail_verify_peer' => true,
        'mail_cafile' => $GLOBALS['sink']['cert'], 'mail_timeout' => 3,
        'mail_oauth_provider' => 'microsoft', 'mail_oauth_flow' => 'app', 'mail_oauth_tenant' => 'contoso', 'mail_oauth_client_id' => $GLOBALS['kit']['app_client_id'],
        'mail_oauth_client_secret' => $GLOBALS['kit']['app_secret'], 'mail_oauth_authority' => $GLOBALS['https'], 'mail_oauth_store' => $GLOBALS['store'],
    ], $o);
    $userCfg = static fn(array $o = []): array => $appCfg(array_replace([
        'mail_oauth_flow' => 'user', 'mail_oauth_client_secret' => '', 'mail_oauth_client_id' => $GLOBALS['kit']['user_client_id'], 'mail_username' => '', 'mail_from' => '',
    ], $o));
    $resetStore();

    // ---------------------------------------------------------------- the HTTPS client on its own
    echo "\n-- the web client the token request uses --\n";
    $before = count($requests('token_http'));
    $odd = ['grant_type' => 'client_credentials', 'client_secret' => 'p@ss w0rd&x=1%2F+é', 'scope' => 'a b'];
    $r = HttpClient::postForm($tokenUrl('contoso', false), $odd, $net);
    $seen = array_slice($requests('token_http'), $before);
    ok('a form is POSTed to the path asked for, as a urlencoded form, and arrives exactly as written (spaces, &, =, % and non-ASCII included)',
        count($seen) === 1 && $seen[0]['method'] === 'POST' && $seen[0]['path'] === '/contoso/oauth2/v2.0/token' && str_starts_with((string) $seen[0]['ctype'], 'application/x-www-form-urlencoded') && $seen[0]['form'] === $odd, json_encode($seen));
    ok('...with a Host header that names the server and its port', ($seen[0]['host'] ?? '') === '127.0.0.1:' . $port('token_http'), json_encode($seen));
    ok('an OAuth refusal (HTTP 400) comes back as an answer to be read, not as an exception', $r['status'] === 400 && str_contains($r['body'], 'invalid_request'), $r['status'] . ' ' . substr($r['body'], 0, 80));
    $r = HttpClient::postForm($tokenUrl('contoso'), $form, $net);
    $answer = json_decode($r['body'], true);
    ok('over HTTPS, with the certificate checked against the CA file, a token request is answered', $r['status'] === 200 && ($answer['token_type'] ?? '') === 'Bearer' && strlen((string) ($answer['access_token'] ?? '')) > 800, $r['status'] . ' ' . substr($r['body'], 0, 80));
    try { HttpClient::postForm($tokenUrl('contoso'), $form, ['timeout' => 3, 'verify_peer' => true]); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('...and a certificate the machine does not trust is refused, in words', str_contains($why, 'certificate could not be verified') && !preg_match('/[^\x20-\x7E]/', $why), $why);
    $r = HttpClient::postForm($tokenUrl('contoso'), $form, ['timeout' => 3, 'verify_peer' => false]);
    ok('...unless checking was switched off, as it is for MAIL_VERIFY_PEER=false', $r['status'] === 200);
    foreach (['chunked' => 'a chunked answer (with an extension and a trailer)', 'closedelim' => 'an answer that ends when the server hangs up'] as $tenant => $what) {
        $r = HttpClient::postForm($tokenUrl($tenant), $form, $net);
        $answer = json_decode($r['body'], true);
        ok("{$what} is read whole", $r['status'] === 200 && ($answer['token_type'] ?? '') === 'Bearer' && strlen((string) ($answer['access_token'] ?? '')) > 800, $r['status'] . ' ' . strlen($r['body']));
    }
    $r = HttpClient::postForm($tokenUrl('longheader'), $form, $net);
    $answer = json_decode($r['body'], true);
    ok('headers far longer than a line buffer (a 5000 character header and a 3000 character cookie, as real services send) do not stop the answer being read',
        $r['status'] === 200 && ($answer['token_type'] ?? '') === 'Bearer' && strlen((string) ($answer['access_token'] ?? '')) > 800 && ($r['headers']['connection'] ?? '') === 'close', $r['status'] . ' ' . strlen($r['body']));
    $before = count($requests('token_https'));
    $r = HttpClient::postForm($tokenUrl('redirect'), $form, $net);
    ok('a redirect is NOT followed (the form would go wherever it points); it is reported as it is', $r['status'] === 302 && ($r['headers']['location'] ?? '') === 'http://evil.example/steal' && count($requests('token_https')) === $before + 1);
    $failure = static function (string $url, array $options = []) use ($form, $net): string {
        try { HttpClient::postForm($url, $form, array_replace($net, $options)); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
    };
    ok('an answer of megabytes is refused, not read', str_contains($failure($tokenUrl('huge')), 'far larger'), $failure($tokenUrl('huge')));
    ok('...also when the answer has no length and simply goes on until the connection closes', str_contains($failure($tokenUrl('hugeclose')), 'far larger'), $failure($tokenUrl('hugeclose')));
    ok('...or arrives in chunks that are each modest and together far too much', str_contains($failure($tokenUrl('hugechunk')), 'far larger'), $failure($tokenUrl('hugechunk')));
    ok('a header line of 200 kilobytes is refused, not read to the end', str_contains($failure($tokenUrl('hugeheader')), 'header line far longer'), $failure($tokenUrl('hugeheader')));
    ok('a server that hangs up without answering is reported', str_contains($failure($tokenUrl('dropped')), 'closed the connection'), $failure($tokenUrl('dropped')));
    ok('something that is not a web server is recognised, and shown as plain text', str_contains($failure($tokenUrl('garbage')), 'does not look like a web server') && !preg_match('/[^\x20-\x7E]/', $failure($tokenUrl('garbage'))), $failure($tokenUrl('garbage')));
    $started = microtime(true);
    $why = $failure($tokenUrl('slow'), ['timeout' => 1]);
    ok('a server that never answers is given up on after the timeout', str_contains($why, 'did not answer') && microtime(true) - $started < 4, sprintf('%.1fs %s', microtime(true) - $started, $why));
    $started = microtime(true);
    $why = $failure('http://example.com/oauth2/token');
    ok('sign-in details are never sent without encryption to another machine, and nothing is connected to', str_contains($why, 'without encryption') && microtime(true) - $started < 0.5, $why);
    ok('an address with a user name and password in it, or a fragment, or a line break is refused', str_contains($failure('https://user:pw@example.com/x'), 'not usable') && str_contains($failure('https://example.com/x#frag'), 'not usable') && str_contains($failure("https://example.com/x\r\nHost: evil"), 'not usable'));
    $gone = stream_socket_server('tcp://127.0.0.1:0');
    $gonePort = (int) substr((string) stream_socket_get_name($gone, false), strrpos((string) stream_socket_get_name($gone, false), ':') + 1);
    fclose($gone);
    $why = $failure('http://127.0.0.1:' . $gonePort . '/x');
    ok('a port nobody listens on fails quickly with a reason, as plain text', $why !== '' && !preg_match('/stream_socket_client|Warning|[^\x20-\x7E]/', $why), $why);

    // ---------------------------------------------------------------- the token, the app way
    echo "\n-- the app way (client credentials) --\n";
    $resetStore();
    mailConfig($appCfg());
    $before = count($requests('token_https'));
    $token = MailOAuth::accessToken($net);
    $seen = array_slice($requests('token_https'), $before);
    ok('the website asks for a token with the client credentials, for the SMTP resource, from the tenant\'s own address, and sends nothing else',
        count($seen) === 1 && $seen[0]['path'] === '/contoso/oauth2/v2.0/token' && $seen[0]['form'] === $form, json_encode($seen));
    ok('the token is a long run of visible characters (it goes into a command line)', strlen($token) > 800 && preg_match('/^[\x21-\x7E]+$/', $token) === 1);
    ok('it is saved in a file only its owner can read', is_file($store) && (PHP_OS_FAMILY === 'Windows' || (fileperms($store) & 0077) === 0), decoct(fileperms($store) & 0777));
    ok('...which holds the token and not the client secret, and no half-written file is left beside it', str_contains((string) file_get_contents($store), $token) && !str_contains((string) file_get_contents($store), $kit['app_secret']) && !glob($store . '.*.tmp'));
    ok('what it did is reported without the token in it', str_contains(MailOAuth::note(), 'new access token') && !str_contains(MailOAuth::note(), $token));
    $before = count($requests('token_https'));
    ok('the next message reuses the saved token, with no request to Microsoft at all', MailOAuth::accessToken($net) === $token && count($requests('token_https')) === $before && MailOAuth::lastTokenWasSaved() && str_contains(MailOAuth::note(), 'saved earlier'));
    mailConfig($appCfg(['mail_oauth_tenant' => 'short']));
    $first = MailOAuth::accessToken($net);
    $second = MailOAuth::accessToken($net);
    ok('a token with under five minutes left is not reused (it could expire during the message): each message gets a fresh one', $first !== $second && !MailOAuth::lastTokenWasSaved());
    ok('a token saved for another tenant or app is never offered for this one', MailOAuth::accessToken($net) !== $token);
    $resetStore();
    mailConfig($appCfg());
    $good = MailOAuth::accessToken($net);
    mailConfig($appCfg(['mail_oauth_client_secret' => 'Changed~To.Something-Wrong-1']));
    try { MailOAuth::accessToken($net); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('a client secret that was just changed is tried at once: the token saved under the old one is not reused (the test button must not say "fine" for an hour)', $good !== '' && str_contains($why, 'client secret is wrong'), $why);
    mailConfig($appCfg());
    ok('...and putting the right one back finds the saved token again (nothing was thrown away)', MailOAuth::accessToken($net) === $good && MailOAuth::lastTokenWasSaved());
    $resetStore();

    $closed = stream_socket_server('tcp://127.0.0.1:0');
    $closedAt = (int) substr((string) stream_socket_get_name($closed, false), strrpos((string) stream_socket_get_name($closed, false), ':') + 1);
    fclose($closed);
    $problemOf = static function (array $cfg) use ($appCfg, $net, $resetStore): string {
        $resetStore();
        mailConfig($appCfg($cfg));
        try { MailOAuth::accessToken(['timeout' => (int) ($cfg['mail_timeout'] ?? 3)] + $net); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
    };
    $wrongSecret = 'Wr0ng~Secret.Sentinel-9876';
    $msg = $problemOf(['mail_oauth_client_secret' => $wrongSecret]);
    ok('a wrong client secret is explained in plain words, with Microsoft\'s own code, and without Microsoft\'s trace lines', str_contains($msg, 'Microsoft would not issue an access token') && str_contains($msg, 'client secret is wrong') && str_contains($msg, 'AADSTS7000215') && !str_contains($msg, 'Trace ID'), $msg);
    ok('...never with the secret in it', !str_contains($msg, $wrongSecret) && !str_contains($msg, $kit['app_secret']));
    ok('...and Mailer::hint says what to do about it', str_contains(Mailer::hint($msg), 'Certificates & secrets'), Mailer::hint($msg));
    $msg = $problemOf(['mail_oauth_client_id' => '99999999-9999-4999-8999-999999999999']);
    ok('an unknown app is explained, pointing at the Overview page', str_contains($msg, 'No app with that client ID') && str_contains(Mailer::hint($msg), 'Overview'), $msg);
    $msg = $problemOf(['mail_oauth_tenant' => 'echo', 'mail_oauth_client_secret' => $kit['app_secret']]);
    ok('...nor in the form it travelled in (URL-encoded), which is what a proxy echoing the request body would show', !str_contains($msg, urlencode($kit['app_secret'])) && !str_contains($msg, substr($kit['app_secret'], 0, 12)), $msg);
    ok('a refusal that echoes back the client secret is shown with the secret masked, as plain text', str_contains($msg, '***') && !str_contains($msg, $kit['app_secret']) && !preg_match('/[^\x20-\x7E]/', $msg) && strlen($msg) < 700, $msg);
    $started = microtime(true);
    $msg = $problemOf(['mail_oauth_tenant' => 'slow', 'mail_timeout' => 1]);
    ok('a sign-in service that never answers is reported as unreachable, quickly, with the way out', str_contains($msg, 'Could not reach the Microsoft sign-in service') && str_contains($msg, 'did not answer') && microtime(true) - $started < 5 && str_contains(Mailer::hint($msg), 'login.microsoftonline.com'), sprintf('%.1fs %s', microtime(true) - $started, $msg));
    foreach (['html' => 'HTTP 200', 'badjson' => 'HTTP 200', 'redirect' => 'HTTP 302'] as $tenant => $code) {
        $msg = $problemOf(['mail_oauth_tenant' => $tenant]);
        ok("an answer that is not a token ({$tenant}) is said to be that, with its HTTP status", str_contains($msg, 'not a token') && str_contains($msg, $code), $msg);
    }
    // The first step (asking for a sign-in code) gets the same kind of answer, and says what it was waiting for.
    foreach (['html', 'badjson'] as $tenant) {
        $resetStore();
        mailConfig($userCfg(['mail_oauth_tenant' => $tenant]));
        try { MailOAuth::beginDeviceLogin($net); $msg = ''; } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        ok("...an answer to the request for a sign-in code that is not one ({$tenant}) says \"not a sign-in code\", not \"not a token\"", str_contains($msg, 'something that is not a sign-in code (HTTP 200)') && !str_contains($msg, 'not a token'), $msg);
    }
    mailConfig($appCfg());
    $msg = $problemOf(['mail_oauth_authority' => 'https://127.0.0.1:' . $closedAt]);
    ok('an address nothing listens on is reported as unreachable', str_contains($msg, 'Could not reach the Microsoft sign-in service') && !preg_match('/stream_socket_client|Warning|[^\x20-\x7E]/', $msg), $msg);
    $before = count($requests('token_https'));
    $msg = $problemOf(['mail_oauth_tenant' => '']);
    ok('a setting that cannot work is reported without asking Microsoft anything', str_contains($msg, 'MAIL_OAUTH_TENANT is empty') && count($requests('token_https')) === $before, $msg);
    $resetStore();
    // A store that cannot be written is only a cache that cannot be kept.
    file_put_contents($store, '{}');   // a path beneath a plain file can never be created, on any system
    mailConfig($appCfg(['mail_oauth_store' => $store . '/below/mail-oauth.json']));
    clearErrorLog();
    $unsaved = MailOAuth::accessToken($net);
    ok('a token store that cannot be written costs a request per message, not the message, and no PHP warning in the log', strlen($unsaved) > 800 && !preg_match('/PHP (Warning|Notice|Deprecated)/', errorLogText()), errorLogText());
    $resetStore();

    // ---------------------------------------------------------------- the whole thing: a message, signed in by token
    echo "\n-- a message, signed in with an access token --\n";
    mailConfig($appCfg());
    clearErrorLog();
    $beforeMsgs = count(sinkMessages($sink, 'oauth'));
    $beforeTokens = count($requests('token_https'));
    $sent = Mailer::send('office@example.com', 'Via OAuth · CTE-0A1B2C3D', $sample, 'visitor@example.org');
    $got = array_slice(sinkMessages($sink, 'oauth'), $beforeMsgs);
    ok('a message goes through a server that only takes OAuth (STARTTLS first, then AUTH XOAUTH2)', $sent === true && count($got) === 1 && ($got[0]['tls'] ?? false) === true, Mailer::lastError());
    ok('the server knows who sent it (the mailbox in the sign-in string), and the envelope is the mailbox to the office', ($got[0]['authed'] ?? '') === $kit['mailbox'] && ($got[0]['mail_from'] ?? '') === $kit['mailbox'] && ($got[0]['rcpts'] ?? []) === ['office@example.com']);
    $oauthEvents = sinkEvents($sink, 'oauth');
    $okEvent = array_values(array_filter($oauthEvents, static fn($e) => ($e['event'] ?? '') === 'auth_ok'));
    $usedToken = (string) end($okEvent)['token'];
    ok('what it signed in with was the token Microsoft\'s service issued, over the encrypted connection, as XOAUTH2 and never as a password', $usedToken === (string) ($readStore()['app']['access_token'] ?? 'none') && end($okEvent)['mech'] === 'XOAUTH2' && end($okEvent)['tls'] === true && count($requests('token_https')) === $beforeTokens + 1);
    $talk = implode("\n", Mailer::conversation());
    $xoauthString = SmtpClient::xoauth2($kit['mailbox'], $usedToken);
    ok('the conversation shows the sign-in, with neither the token nor the string it travelled in, in either encoding', str_contains($talk, 'AUTH XOAUTH2 ***') && !str_contains($talk, $usedToken) && !str_contains($talk, $xoauthString) && !str_contains($talk, base64_encode($usedToken)));
    ok('...and says where the token came from (without it)', str_contains($talk, 'OAuth: got a new access token') && str_contains($talk, 'good for 59 minutes'));
    $mh = parseMessage((string) ($got[0]['data'] ?? ''));
    ok('the message itself is the same one every driver sends (subject, sender, reply-to, body)', decodeWords($mh['headers']['subject'][0] ?? '') === 'Via OAuth · CTE-0A1B2C3D' && str_ends_with($mh['headers']['from'][0] ?? '', '<' . $kit['mailbox'] . '>') && ($mh['headers']['reply-to'][0] ?? '') === 'visitor@example.org' && quoted_printable_decode($mh['body']) === crlf($sample) . "\r\n");
    ok('a success leaves the error log empty', errorLogText() === '' && Mailer::lastError() === '', errorLogText());
    $beforeTokens = count($requests('token_https'));
    $sent = Mailer::send('office@example.com', 'Second', 'again');
    $okEvent = array_values(array_filter(sinkEvents($sink, 'oauth'), static fn($e) => ($e['event'] ?? '') === 'auth_ok'));
    ok('the second message signs in with the same saved token, and Microsoft is not asked again', $sent === true && end($okEvent)['token'] === $usedToken && count($requests('token_https')) === $beforeTokens && str_contains(implode("\n", Mailer::conversation()), 'saved earlier'));

    // A token taken back before it expired: the server refuses it, the saved copy is dropped, one fresh token is tried.
    $control('revoke');
    clearErrorLog();
    $beforeFail = sinkSaw($sink, 'oauth', 'auth_failed');
    $beforeTokens = count($requests('token_https'));
    $beforeMsgs = count(sinkMessages($sink, 'oauth'));
    $sent = Mailer::send('office@example.com', 'After a revoked token', 'body');
    $okEvent = array_values(array_filter(sinkEvents($sink, 'oauth'), static fn($e) => ($e['event'] ?? '') === 'auth_ok'));
    ok('a saved token the server no longer accepts is replaced at once, and the message still goes (once)', $sent === true && sinkSaw($sink, 'oauth', 'auth_failed') === $beforeFail + 1 && count($requests('token_https')) === $beforeTokens + 1
        && count(sinkMessages($sink, 'oauth')) === $beforeMsgs + 1 && end($okEvent)['token'] !== $usedToken && end($okEvent)['token'] === ($readStore()['app']['access_token'] ?? ''), Mailer::lastError());
    ok('...with nothing in the error log (it was recovered)', errorLogText() === '', errorLogText());

    // Refused for good: the wrong mailbox. A fresh token is not retried; a saved one gets exactly one more chance.
    $wrongBox = 'someone.else@contoso.test';
    $resetStore();
    mailConfig($appCfg(['mail_username' => $wrongBox, 'mail_from' => $wrongBox]));
    clearErrorLog();
    $beforeFail = sinkSaw($sink, 'oauth', 'auth_failed');
    $beforeTokens = count($requests('token_https'));
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a mailbox the token is not for is refused, in words, and a FRESH token is not tried twice', $sent === false && str_contains(Mailer::lastError(), 'OAuth sign-in was refused') && str_contains(Mailer::lastError(), '535 5.7.3') && sinkSaw($sink, 'oauth', 'auth_failed') === $beforeFail + 1 && count($requests('token_https')) === $beforeTokens + 1, Mailer::lastError());
    ok('...with the exact things to check, naming Authenticated SMTP, the mailbox address and the service principal', str_contains(Mailer::hint(Mailer::lastError()), 'Authenticated SMTP') && str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_USERNAME') && str_contains(Mailer::hint(Mailer::lastError()), 'service principal'), Mailer::hint(Mailer::lastError()));
    ok('...and exactly one plain line in the error log, free of the token', substr_count(errorLogText(), 'Mail not sent (smtp): OAuth sign-in was refused') === 1 && substr_count(rtrim(errorLogText()), "\n") === 0 && !str_contains(errorLogText(), (string) ($readStore()['app']['access_token'] ?? 'none')), errorLogText());
    $beforeFail = sinkSaw($sink, 'oauth', 'auth_failed');
    $beforeTokens = count($requests('token_https'));
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a SAVED token that is refused gets exactly one more try, with a new one, and then the failure is reported (no loop)', $sent === false && sinkSaw($sink, 'oauth', 'auth_failed') === $beforeFail + 2 && count($requests('token_https')) === $beforeTokens + 1, Mailer::lastError());

    // Other servers, other ways of saying no.
    $resetStore();
    mailConfig($appCfg(['mail_port' => $port('oauth334')]));
    MailOAuth::accessToken($net);
    $control('revoke');   // the saved token is now one the server will refuse, with a 334 explanation first
    $beforeMsgs = count(sinkMessages($sink, 'oauth334'));
    $beforeChallenge = sinkSaw($sink, 'oauth334', 'challenge_answered');
    $sent = Mailer::send('office@example.com', 'Gmail style', 'body');
    $challenges = array_values(array_filter(sinkEvents($sink, 'oauth334'), static fn($e) => ($e['event'] ?? '') === 'challenge_answered'));
    ok('a server that explains a refusal with a 334 gets the empty line the protocol asks for (without it the real 535 never arrives), and the message still goes after the retry',
        $sent === true && count(sinkMessages($sink, 'oauth334')) === $beforeMsgs + 1 && sinkSaw($sink, 'oauth334', 'challenge_answered') === $beforeChallenge + 1 && end($challenges)['line'] === '', Mailer::lastError());
    mailConfig($appCfg(['mail_port' => $port('oauth334'), 'mail_username' => $wrongBox, 'mail_from' => $wrongBox]));
    $resetStore();
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('...and when the refusal is for good, the 535 behind the 334 is what is reported, with the server\'s explanation decoded in the conversation', $sent === false && str_contains(Mailer::lastError(), 'OAuth sign-in was refused') && str_contains(Mailer::lastError(), '535 5.7.8')
        && str_contains(implode("\n", Mailer::conversation()), 'explanation, decoded') && str_contains(implode("\n", Mailer::conversation()), 'Bearer'), Mailer::lastError());

    $resetStore();
    mailConfig($appCfg(['mail_port' => $port('oauthoff')]));
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('"Authenticated SMTP is switched off" (535 5.7.139) is recognised, and the hint is where to switch it on', $sent === false && str_contains(Mailer::lastError(), '5.7.139') && str_contains(Mailer::hint(Mailer::lastError()), 'Manage email apps') && str_contains(Mailer::hint(Mailer::lastError()), 'Authenticated SMTP'), Mailer::lastError());

    $resetStore();
    clearErrorLog();
    mailConfig($appCfg(['mail_port' => $port('oauthecho'), 'mail_username' => $wrongBox, 'mail_from' => $wrongBox]));
    $sent = Mailer::send('office@example.com', 'x', 'y');
    $saved = (string) ($readStore()['app']['access_token'] ?? 'none');
    $everything = Mailer::lastError() . ' ' . errorLogText() . ' ' . implode(' ', Mailer::conversation());
    $lastXoauth = SmtpClient::xoauth2($wrongBox, $saved);
    ok('a careless server that echoes the token back, plain and in base64, in a long reply, leaves it nowhere: not on the page, in the log or in the conversation',
        $sent === false && $saved !== 'none' && !str_contains($everything, $saved) && !str_contains($everything, base64_encode($saved)) && !str_contains($everything, $lastXoauth) && !str_contains($everything, base64_encode($lastXoauth)) && str_contains(Mailer::lastError(), 'OAuth sign-in was refused'), substr(Mailer::lastError(), 0, 200));
    // Real tokens are far longer than the 300 characters of a message, so whether the end of one slips through
    // cannot be seen from a whole-token comparison: look for pieces of it, and ask the masking directly.
    $piece = static fn(string $s): string => substr($s, 20, 40);
    ok('...not even a piece of the token, of its base64 or of the string it travelled in', !str_contains($everything, $piece($saved)) && !str_contains($everything, $piece(base64_encode($saved))) && !str_contains($everything, substr($lastXoauth, 100, 40)) && str_contains(Mailer::lastError(), '***'), substr(Mailer::lastError(), 0, 300));
    $longToken = 'EwB' . str_repeat('ey', 300) . '.' . str_repeat('Zm', 200);
    $wholeAndPartial = SmtpClient::maskSecret('a ' . $longToken . ' b ' . base64_encode($longToken) . ' c ' . urlencode('Sup3r~Secret.Value+0123456789/x') . ' d ' . substr(base64_encode($longToken), 0, 500), $longToken);
    ok('masking hides a secret in the forms a careless server echoes it (as sent, base64), whole or cut short in the middle of a piece', $wholeAndPartial === 'a *** b *** c ' . urlencode('Sup3r~Secret.Value+0123456789/x') . ' d ***', $wholeAndPartial);
    $spec = 'Sup3r~Secret.Value+0123456789/x';
    $encodedForms = SmtpClient::maskSecret('x=' . urlencode($spec) . '&y=' . rawurlencode($spec) . '&z=' . base64_encode($spec) . '&w=' . $spec, $spec);
    ok('...URL-encoded (both ways) and base64 too, so a proxy that echoes a request body leaks nothing', $encodedForms === 'x=***&y=***&z=***&w=***', $encodedForms);
    ok('...and leaves text without the secret alone, an empty secret alone, and cannot be sent into a loop by a secret that looks like its own mask',
        SmtpClient::maskSecret('nothing to hide here', $longToken) === 'nothing to hide here' && SmtpClient::maskSecret('abc', '') === 'abc' && SmtpClient::maskSecret('x *** y ***** z', '*****') === 'x *** y *** z');
    $scrub = new ReflectionMethod(SmtpClient::class, 'scrub');
    $masked = $scrub->invoke(new SmtpClient(['host' => 'x.example', 'username' => 'a@b.example', 'token' => $longToken]), 'before ' . $longToken . ' middle ' . base64_encode($longToken) . ' end');
    ok('a token is masked wherever it stands in a server\'s text, plain and in base64, before the text is cut to length', $masked === 'before *** middle *** end', $masked);
    ok('...as printable text, cut to a sensible length', !preg_match('/[^\x20-\x7E]/', Mailer::lastError()) && strlen(Mailer::lastError()) <= 400, (string) strlen(Mailer::lastError()));

    // A password, aimed at a server that wants OAuth or has stopped taking passwords.
    mailConfig(['mail_port' => $port('basicoff'), 'mail_encryption' => 'tls', 'mail_cafile' => $sink['cert'], 'mail_username' => $kit['mailbox'], 'mail_password' => 'a-password-microsoft-no-longer-takes', 'mail_oauth_provider' => '']);
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a password set-up meeting Microsoft\'s "Basic authentication is not supported" (550 5.7.30) is told what has happened and exactly what to set instead', $sent === false && str_contains(Mailer::lastError(), '5.7.30')
        && str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_OAUTH_PROVIDER=microsoft') && str_contains(Mailer::hint(Mailer::lastError()), 'docs/EMAIL.md'), Mailer::lastError());
    mailConfig(['mail_port' => $port('oauth'), 'mail_encryption' => 'tls', 'mail_cafile' => $sink['cert'], 'mail_username' => $kit['mailbox'], 'mail_password' => 'a-password-for-a-server-that-wants-oauth', 'mail_oauth_provider' => '']);
    $beforeAttempts = sinkSaw($sink, 'oauth', 'auth_attempt');
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a password aimed at a server that takes only OAuth is refused before anything is sent, with the way out', $sent === false && str_contains(Mailer::lastError(), 'only accepts OAuth sign-in') && sinkSaw($sink, 'oauth', 'auth_attempt') === $beforeAttempts && str_contains(Mailer::hint(Mailer::lastError()), 'MAIL_OAUTH_PROVIDER=microsoft'), Mailer::lastError());
    $resetStore();
    mailConfig($appCfg(['mail_port' => $port('starttls')]));
    $beforeAttempts = sinkSaw($sink, 'starttls', 'auth_attempt');
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('OAuth aimed at a server that offers only passwords says so, lists what it does offer, and sends no token', $sent === false && str_contains(Mailer::lastError(), 'does not offer OAuth sign-in (XOAUTH2); it offers PLAIN, LOGIN') && sinkSaw($sink, 'starttls', 'auth_attempt') === $beforeAttempts && str_contains(Mailer::hint(Mailer::lastError()), 'smtp.office365.com'), Mailer::lastError());
    $resetStore();
    mailConfig($appCfg(['mail_port' => $port('oauthnotls')]));
    $beforeAttempts = sinkSaw($sink, 'oauthnotls', 'auth_attempt');
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a token is never sent over a connection that was not encrypted when encryption was asked for', $sent === false && str_contains(Mailer::lastError(), 'STARTTLS') && sinkSaw($sink, 'oauthnotls', 'auth_attempt') === $beforeAttempts, Mailer::lastError());

    // ---------------------------------------------------------------- the token, a person's way (device code)
    echo "\n-- the connect way (a person signs in once) --\n";
    $resetStore();
    mailConfig($userCfg());
    $beforeTokens = count($requests('token_https'));
    ok('before anyone has connected, sending says so and asks Microsoft nothing', Mailer::send('office@example.com', 'x', 'y') === false && str_contains(Mailer::lastError(), 'is not connected yet') && str_contains(Mailer::hint(Mailer::lastError()), 'press Connect') && count($requests('token_https')) === $beforeTokens, Mailer::lastError());
    ok('...and the settings report it before anything is pressed', (bool) array_filter(Mailer::problems(), static fn($p) => str_contains($p, 'is not connected yet')));
    $beforeDev = count($requests('token_https'));
    $device = MailOAuth::beginDeviceLogin($net);
    $seen = array_slice($requests('token_https'), $beforeDev);
    ok('connecting asks for a device code with the app\'s client ID and the SMTP.Send permission, a refresh token and the identity scopes, and no secret',
        count($seen) === 1 && $seen[0]['path'] === '/contoso/oauth2/v2.0/devicecode' && $seen[0]['form'] === ['client_id' => $kit['user_client_id'], 'scope' => 'https://outlook.office.com/SMTP.Send offline_access openid profile'], json_encode($seen));
    ok('what comes back is the short code to type, the page to open, a lifetime and a polling interval, all within sane bounds', $device['user_code'] === 'AB12CD34' && $device['verification_uri'] === 'https://microsoft.com/devicelogin' && $device['expires_at'] > time() + 600 && $device['expires_at'] <= time() + 900 && $device['interval'] === 5 && strlen($device['device_code']) > 40);
    $beforeDev = count($requests('token_https'));
    $state = MailOAuth::pollDeviceLogin($device, $net);
    $seen = array_slice($requests('token_https'), $beforeDev);
    ok('asking before the person has signed in is "pending", and the question is the standard device-code grant with no secret',
        $state['state'] === 'pending' && count($seen) === 1 && $seen[0]['form'] === ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $kit['user_client_id'], 'device_code' => $device['device_code']], json_encode($seen));
    ok('...and nothing is saved yet', !MailOAuth::settings()['connected'] && $readStore() === []);
    $control('approve');
    $state = MailOAuth::pollDeviceLogin($device, $net);
    ok('once the person has signed in, the next question connects: the account is read from the answer', $state['state'] === 'connected' && $state['account'] === $kit['mailbox'], json_encode($state));
    $saved = $readStore()['user'] ?? [];
    ok('the refresh token and the account are saved in a file only the owner can read', strlen((string) ($saved['refresh_token'] ?? '')) > 60 && ($saved['account'] ?? '') === $kit['mailbox'] && (PHP_OS_FAMILY === 'Windows' || (fileperms($store) & 0077) === 0));
    ok('...and the website now knows it is connected, as that account', MailOAuth::settings()['connected'] && MailOAuth::settings()['account'] === $kit['mailbox'] && Mailer::settings()['username'] === $kit['mailbox'] && Mailer::settings()['from'] === $kit['mailbox'], json_encode(MailOAuth::settings()));
    ok('...with nothing left to fix in the settings', Mailer::problems() === [], implode(' | ', Mailer::problems()));
    ok('...and describe() says which account', str_contains(Mailer::describe(), 'Microsoft 365 OAuth as ' . $kit['mailbox']), Mailer::describe());

    clearErrorLog();
    $beforeMsgs = count(sinkMessages($sink, 'oauth'));
    $beforeTokens = count($requests('token_https'));
    $sent = Mailer::send('office@example.com', 'Sent as the connected account', 'body');
    ok('a message goes out with the token the sign-in already produced, without another request', $sent === true && count(sinkMessages($sink, 'oauth')) === $beforeMsgs + 1 && count($requests('token_https')) === $beforeTokens && errorLogText() === '', Mailer::lastError());
    // Expiry: the saved access token has run out, so the saved refresh token is used, and Microsoft's new one replaces it.
    $data = $readStore();
    $oldRefresh = (string) $data['user']['refresh_token'];
    $data['user']['expires_at'] = time() - 5;
    $writeStore($data);
    $beforeDev = count($requests('token_https'));
    $sent = Mailer::send('office@example.com', 'After the token expired', 'body');
    $seen = array_slice($requests('token_https'), $beforeDev);
    $after = $readStore()['user'] ?? [];
    ok('when the access token has run out, the refresh token gets a new one (refresh grant, with the SMTP scopes, no secret) and the message goes',
        $sent === true && count($seen) === 1 && ($seen[0]['form']['grant_type'] ?? '') === 'refresh_token' && ($seen[0]['form']['refresh_token'] ?? '') === $oldRefresh && ($seen[0]['form']['client_id'] ?? '') === $kit['user_client_id']
        && !isset($seen[0]['form']['client_secret']) && str_contains((string) $seen[0]['form']['scope'], 'https://outlook.office.com/SMTP.Send'), Mailer::lastError() . ' ' . json_encode($seen));
    ok('Microsoft\'s replacement refresh token is kept in place of the old one, with the account and the date it was connected', ($after['refresh_token'] ?? '') !== $oldRefresh && strlen((string) ($after['refresh_token'] ?? '')) > 60 && ($after['account'] ?? '') === $kit['mailbox'] && ($after['connected_at'] ?? '') === ($data['user']['connected_at'] ?? 'x'));
    // A connection Microsoft no longer honours.
    $data = $readStore();
    $data['user']['refresh_token'] = '0.Abogus-refresh-token-Microsoft-never-issued';
    $data['user']['expires_at'] = time() - 5;
    $writeStore($data);
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('a lapsed connection (a refresh token Microsoft no longer honours) is explained, and the way out is to connect again', $sent === false && str_contains(Mailer::lastError(), 'expired because it was not used') && str_contains(Mailer::hint(Mailer::lastError()), 'Connect again'), Mailer::lastError());
    ok('...without showing the refresh token', !str_contains(Mailer::lastError(), 'bogus-refresh-token') && !str_contains(errorLogText(), 'bogus-refresh-token'));
    mailConfig($userCfg(['mail_oauth_client_id' => '33333333-3333-4333-8333-333333333333']));
    ok('a connection made with another app registration is not offered for this one', !MailOAuth::settings()['connected']);
    mailConfig($userCfg(['mail_oauth_tenant' => 'evilurl']));
    $device = MailOAuth::beginDeviceLogin($net);
    ok('a sign-in page address that is not https (a javascript: address, say) is never offered as a link: Microsoft\'s own is used instead', $device['verification_uri'] === 'https://microsoft.com/devicelogin', $device['verification_uri']);
    mailConfig($userCfg(['mail_oauth_tenant' => 'evilquote']));
    $device = MailOAuth::beginDeviceLogin($net);
    ok('...nor one with a quote or a bracket in it (it would end the link and start markup)', $device['verification_uri'] === 'https://microsoft.com/devicelogin', $device['verification_uri']);
    mailConfig($userCfg(['mail_oauth_tenant' => 'evilcode']));
    try { MailOAuth::beginDeviceLogin($net); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('a sign-in code that is not plain letters and digits (markup, say) is refused, not shown', $why !== '' && !str_contains($why, '<script>'), $why);
    mailConfig($userCfg());
    $badCode = ['device_code' => 'a-device-code-nobody-issued', 'expires_at' => time() + 600];
    try { MailOAuth::pollDeviceLogin($badCode, $net); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('a sign-in code Microsoft does not know is refused in words', str_contains($why, 'did not recognise that sign-in code'), $why);
    try { MailOAuth::pollDeviceLogin(['device_code' => 'x', 'expires_at' => time() - 1], $net); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('a code that has expired is refused without asking Microsoft', str_contains($why, 'has expired'), $why);
    mailConfig($appCfg());
    try { MailOAuth::beginDeviceLogin($net); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('there is nothing to connect when the website signs in as an app', str_contains($why, 'nothing to connect'), $why);
    mailConfig($userCfg(['mail_oauth_client_id' => '99999999-9999-4999-8999-999999999999']));
    try { MailOAuth::beginDeviceLogin($net); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
    ok('connecting with a client ID Microsoft does not know says so, pointing at the Overview page', str_contains($why, 'Microsoft would not issue an access token') && str_contains(Mailer::hint($why), 'Overview'), $why);
    mailConfig($userCfg());
    MailOAuth::disconnect();
    ok('disconnecting forgets the account: not connected, and a message says so', !MailOAuth::settings()['connected'] && (Mailer::send('office@example.com', 'x', 'y') === false) && str_contains(Mailer::lastError(), 'is not connected yet'));

    // ---------------------------------------------------------------- the buttons on the Settings page
    echo "\n-- the sign-in card on the Settings page --\n";
    $admin = ['id' => 987655, 'name' => 'Test Administrator', 'email' => 'owner@example.org'];
    $oauthKey = 'mailoauth|' . $admin['id'];
    RateLimiter::clear($oauthKey);
    $_SESSION = [];
    $resetStore();
    mailConfig($userCfg());
    $renderCard = static function (): string {
        $settings = [];
        $mail = ['enabled' => Mailer::enabled(), 'describe' => Mailer::describe(), 'problems' => Mailer::problems(), 'to' => 'owner@example.org', 'oauth' => (new ReflectionMethod(AdminController::class, 'mailOAuthView'))->invoke(null)];
        ob_start();
        include ROOT_PATH . '/app/views/admin/settings.php';
        return (string) ob_get_clean();
    };
    $page = $renderCard();
    ok('not connected: the card says so and offers Connect, in a form of its own with a CSRF token', str_contains($page, 'id="mail-oauth"') && str_contains($page, 'Not connected') && str_contains($page, 'value="oauth-connect"') && !str_contains($page, 'value="oauth-check"')
        && preg_match('/<form[^>]*method="post"[^>]*>\s*<input type="hidden" name="_token" value="[a-f0-9]+">\s*<div class="admin-card admin-form" id="mail-oauth">/', $page) === 1 && substr_count($page, '<form') === substr_count($page, '</form>'));
    $r = AdminController::mailOAuthStep($admin, 'oauth-connect');
    $deviceCode = (string) ($_SESSION['mail_oauth_device']['device_code'] ?? 'none');
    ok('Connect keeps the long device code in the session and tells the person only the short code', $r['ok'] && str_contains($r['message'], 'AB12CD34') && strlen($deviceCode) > 40 && !str_contains($r['message'], $deviceCode), json_encode($r));
    $page = $renderCard();
    $noteAt = strpos($page, 'gave the sign-in code AB12CD34. Follow the steps below.');
    ok('the answer to the press is shown inside the card (under its heading, above the steps), not flashed at the top of the page', $noteAt !== false && $noteAt > strpos($page, 'id="mail-oauth"') && $noteAt < strpos($page, 'class="oauth-steps"') && !isset($_SESSION['flash']) && !isset($_SESSION['mail_oauth_note']));
    ok('...once: showing the page again does not show it again', !str_contains($renderCard(), 'gave the sign-in code'));
    $prettyBefore = $GLOBALS['config']['pretty_urls'] ?? true;
    $landing = [];
    foreach ([true, false] as $pretty) { $GLOBALS['config']['pretty_urls'] = $pretty; $landing[] = AdminController::mailOAuthLanding(); }
    $GLOBALS['config']['pretty_urls'] = $prettyBefore;
    ok('after a press the person is sent to the card itself, with either kind of link the site uses (the "#" is never encoded into the route)', $landing === ['/admin/settings#mail-oauth', '/index.php?route=admin%2Fsettings#mail-oauth'], json_encode($landing));
    $providerBefore = $GLOBALS['config']['mail_oauth_provider'] ?? '';
    $GLOBALS['config']['mail_oauth_provider'] = '';
    ok('...and with OAuth off, to the plain Settings page (there is no card to land on)', AdminController::mailOAuthLanding() === '/admin/settings');
    $GLOBALS['config']['mail_oauth_provider'] = $providerBefore;
    ok('the card now shows the steps: the page to open, the code to type, and the button to press afterwards', str_contains($page, 'https://microsoft.com/devicelogin') && str_contains($page, 'AB12CD34') && str_contains($page, 'value="oauth-check"') && str_contains($page, 'I have signed in') && str_contains($page, 'value="oauth-cancel"') && str_contains($page, 'target="_blank" rel="noopener noreferrer"'));
    ok('...and no secret, token or device code anywhere on the page', !str_contains($page, $deviceCode) && !str_contains($page, $kit['app_secret']) && !str_contains($page, 'refresh_token') && !str_contains($page, 'access_token'));
    $r = AdminController::mailOAuthStep($admin, 'oauth-check');
    $page = $renderCard();
    ok('pressing it too early is not an error: the page says Microsoft has not seen the sign-in yet and keeps the code', $r === ['ok' => true, 'message' => ''] && str_contains($page, 'has not seen the sign-in yet') && str_contains($page, 'AB12CD34'), json_encode($r));
    // A network failure leaves the code usable; a refusal ends it.
    mailConfig($userCfg(['mail_oauth_authority' => 'https://127.0.0.1:' . $closedAt]));
    $r = AdminController::mailOAuthStep($admin, 'oauth-check');
    $page = $renderCard();
    ok('a failure is shown in the card as a failure (the error colours and the alert icon), with what to try', preg_match('/<div class="notice notice-error" role="status">.*?Could not reach the Microsoft sign-in service.*?What to try:/s', $page) === 1);
    $_SESSION['mail_oauth_note'] = ['ok' => false, 'message' => 'left over from a time when OAuth was on'];
    mailConfig($userCfg(['mail_oauth_provider' => '']));
    ok('with OAuth off there is no card to show a note, and a note left over is dropped, not saved for later', !str_contains($renderCard(), 'left over') && !isset($_SESSION['mail_oauth_note']));
    mailConfig($userCfg());
    ok('if Microsoft cannot be reached the person is told what to try, and the code stays usable', $r['ok'] === false && str_contains($r['message'], 'Could not reach the Microsoft sign-in service') && str_contains($r['message'], 'What to try:') && isset($_SESSION['mail_oauth_device']), $r['message']);
    mailConfig($userCfg());
    $control('approve');
    $r = AdminController::mailOAuthStep($admin, 'oauth-check');
    ok('once Microsoft has the sign-in, checking connects, and says which account and what to do next', $r['ok'] === true && str_contains($r['message'], 'is connected as ' . $kit['mailbox']) && str_contains($r['message'], 'Send a test email') && !isset($_SESSION['mail_oauth_device']) && MailOAuth::settings()['connected'], json_encode($r));
    ok('...and the connection is in the activity log, without a token', (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.mail_oauth' AND details = ?", ['Connected Microsoft 365 as ' . $kit['mailbox']]) >= 1);
    $page = $renderCard();
    ok('the card then says Connected, as whom and since when, and offers Connect again and Disconnect', str_contains($page, 'Connected</strong> as <strong>' . $kit['mailbox']) && str_contains($page, date('j F Y')) && str_contains($page, 'value="oauth-disconnect"') && str_contains($page, 'Connect again'), strip_tags(substr($page, (int) strpos($page, 'id="mail-oauth"'), 900)));
    $_SESSION['mail_oauth_device'] = ['device_code' => 'a-device-code-nobody-issued', 'user_code' => 'ZZ99ZZ99', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_at' => time() + 600, 'interval' => 5, 'checked_at' => 0];
    $r = AdminController::mailOAuthStep($admin, 'oauth-check');
    ok('a code Microsoft refuses ends the attempt: the message says why, and the code is dropped from the session', $r['ok'] === false && str_contains($r['message'], 'did not recognise that sign-in code') && !isset($_SESSION['mail_oauth_device']), $r['message']);
    $noteOfFailure = (string) Database::value("SELECT details FROM audit_logs WHERE action = 'settings.mail_oauth' AND details LIKE 'Microsoft 365 sign-in failed:%' ORDER BY id DESC LIMIT 1");
    ok('...and the refusal is in the activity log, in words and without the device code', str_contains($noteOfFailure, 'did not recognise that sign-in code') && !str_contains($noteOfFailure, 'a-device-code-nobody-issued'), $noteOfFailure);
    $r = AdminController::mailOAuthStep($admin, 'oauth-check');
    ok('checking when nothing is waiting says so', $r['ok'] === false && str_contains($r['message'], 'No sign-in is waiting'), $r['message']);
    $_SESSION['mail_oauth_device'] = ['device_code' => 'x', 'user_code' => 'AB12CD34', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_at' => time() - 1, 'interval' => 5, 'checked_at' => 0];
    ok('a code past its time is not shown on the page', !str_contains($renderCard(), 'AB12CD34') && !isset($_SESSION['mail_oauth_device']));
    $r = AdminController::mailOAuthStep($admin, 'oauth-disconnect');
    ok('Disconnect forgets the account, says what that means, and is recorded', $r['ok'] === true && str_contains($r['message'], 'Disconnected') && !MailOAuth::settings()['connected'] && (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.mail_oauth' AND details = 'Disconnected Microsoft 365'") >= 1, json_encode($r));
    $r = AdminController::mailOAuthStep($admin, 'oauth-cancel');
    ok('cancelling needs nothing said', $r === ['ok' => true, 'message' => '']);
    $r = AdminController::mailOAuthStep($admin, 'oauth-nonsense');
    ok('a button this page does not know is refused', $r['ok'] === false && str_contains($r['message'], 'not one this page knows'));
    mailConfig($appCfg());
    $page = $renderCard();
    $notesBefore = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.mail_oauth'");
    $r = AdminController::mailOAuthStep($admin, 'oauth-connect');
    ok('...and pressing it anyway is turned away before anything is called or written down', (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.mail_oauth'") === $notesBefore);
    ok('with the app way there is nothing to connect: the card shows the app and the mailbox, offers no button, and a press is refused', str_contains($page, 'No password is used') && str_contains($page, $kit['app_client_id']) && str_contains($page, $kit['mailbox']) && !str_contains($page, 'value="oauth-connect"') && !str_contains($page, $kit['app_secret']) && $r['ok'] === false && str_contains($r['message'], 'nothing to connect'), $r['message']);
    mailConfig($appCfg(['mail_oauth_provider' => '']));
    $page = $renderCard();
    $r = AdminController::mailOAuthStep($admin, 'oauth-connect');
    ok('with OAuth off the card is not there at all, and a press is refused', !str_contains($page, 'id="mail-oauth"') && $r['ok'] === false && str_contains($r['message'], 'not switched on'));
    mailConfig($userCfg());
    RateLimiter::clear($oauthKey);
    $results = [];
    for ($i = 0; $i < 32; $i++) $results[] = AdminController::mailOAuthStep($admin, 'oauth-cancel');
    ok('thirty presses in ten minutes are allowed, the next is refused (Microsoft is not hammered)', count(array_filter($results, static fn($x) => $x['ok'])) === 30 && str_contains($results[30]['message'], 'enough sign-in attempts'));
    RateLimiter::clear($oauthKey);
    $escaped = (static function (): string {
        $settings = [];
        $mail = ['enabled' => true, 'describe' => 'x', 'problems' => [], 'to' => 'a@b.example', 'oauth' => ['label' => 'Microsoft 365', 'flow' => 'user', 'client_id' => 'c', 'mailbox' => '', 'connected' => true,
            'account' => '"><script>alert(1)</script>', 'connected_at' => '2026-11-23T10:00:00+01:00', 'supported' => true, 'device' => null]];
        ob_start();
        include ROOT_PATH . '/app/views/admin/settings.php';
        return (string) ob_get_clean();
    })();
    ok('whatever the account is called, it is escaped on the page', !str_contains($escaped, '<script>alert(1)</script>') && str_contains($escaped, '&lt;script&gt;'));
    $escapedNote = (static function (): string {
        $settings = [];
        $mail = ['enabled' => true, 'describe' => 'x', 'problems' => [], 'to' => 'a@b.example', 'oauth' => ['label' => 'Microsoft 365', 'flow' => 'user', 'client_id' => 'c', 'mailbox' => '', 'connected' => false,
            'account' => '', 'connected_at' => '', 'supported' => true, 'device' => null, 'note' => ['ok' => false, 'message' => '<img src=x onerror=alert(1)> said no']]];
        ob_start();
        include ROOT_PATH . '/app/views/admin/settings.php';
        return (string) ob_get_clean();
    })();
    ok('...and the note is text too: markup in what Microsoft said is shown, not run', !str_contains($escapedNote, '<img src=x') && str_contains($escapedNote, '&lt;img src=x onerror=alert(1)&gt; said no'));
    $escapedSteps = (static function (): string {
        $settings = [];
        $mail = ['enabled' => true, 'describe' => 'x', 'problems' => [], 'to' => 'a@b.example', 'oauth' => ['label' => 'Microsoft 365', 'flow' => 'user', 'client_id' => 'c', 'mailbox' => '', 'connected' => false,
            'account' => '', 'connected_at' => '', 'supported' => true,
            'device' => ['user_code' => '<b>AB12</b>', 'verification_uri' => 'https://x.example/a"><script>alert(1)</script>', 'checked' => true, 'minutes_left' => 1]]];
        ob_start();
        include ROOT_PATH . '/app/views/admin/settings.php';
        return (string) ob_get_clean();
    })();
    ok('...and so are the code and the address in the sign-in steps, even if something upstream let markup through', !str_contains($escapedSteps, '<script>alert(1)</script>') && !str_contains($escapedSteps, '<b>AB12</b>')
        && str_contains($escapedSteps, '&lt;b&gt;AB12&lt;/b&gt;') && str_contains($escapedSteps, '&quot;&gt;&lt;script&gt;') && str_contains($escapedSteps, '1 more minute.'), substr($escapedSteps, (int) strpos($escapedSteps, 'oauth-steps'), 700));
    Database::query("DELETE FROM audit_logs WHERE action = 'settings.mail_oauth'");
    $_SESSION = [];
    $resetStore();
    mailConfig();
}

// ================================================================== the callers
echo "\n== The two things the site actually emails ==\n";
if ($sink === null) {
    skipped('enquiry alert and password-reset email end to end', 'needs the SMTP sink');
} else {
    // An enquiry from the public form reaches the office.
    $ip = '203.0.113.' . random_int(2, 250);
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION = ['form_started' => time() - 30];
    mailConfig($base('plain'));
    $office = Mailer::officeAddress();
    $enquiry = static fn(array $over = []): array => Enquiries::submit($over + [
        'name' => 'Mail Test Visitor', 'email' => 'visitor@example.org', 'phone' => '+234 800 000 0000',
        'message' => "I would like to view the house on Admiralty Way — please call me.\n.Thank you", 'consent' => '1', 'type' => 'enquiry',
    ]);
    $failedAlerts = static fn(int $id): array => array_column(Database::all("SELECT details FROM audit_logs WHERE action = 'enquiry.alert_failed' AND entity_id = ?", [$id]), 'details');
    $cleanup = [];
    $before = count(sinkMessages($sink, 'plain'));
    $result = $enquiry();
    $reference = (string) ($result['reference'] ?? '');
    $cleanup[] = (int) ($result['id'] ?? 0);
    $got = array_slice(sinkMessages($sink, 'plain'), $before);
    ok('an enquiry is saved', $reference !== '' && (int) Database::value('SELECT COUNT(*) FROM enquiries WHERE reference = ?', [$reference]) === 1, json_encode($result));
    ok('with the notification address left empty, the alert goes to the public email instead', $office === 'front-desk@example.test' && count($got) === 1 && ($got[0]['rcpts'] ?? []) === [$office], $office . ' ' . json_encode($got[0]['rcpts'] ?? null));
    $alert = parseMessage((string) ($got[0]['data'] ?? ''));
    $alertBody = quoted_printable_decode($alert['body']);
    ok('the alert\'s subject carries the reference', decodeWords($alert['headers']['subject'][0] ?? '') === 'New property enquiry · ' . $reference);
    ok('the alert shows who wrote, how to reach them, and what they said', str_contains($alertBody, 'visitor@example.org') && str_contains($alertBody, '+234 800 000 0000') && str_contains($alertBody, 'Admiralty Way — please call me.'));
    ok('replying to the alert answers the visitor (Reply-To), while it is still sent from the site', ($alert['headers']['reply-to'][0] ?? '') === 'visitor@example.org' && str_ends_with($alert['headers']['from'][0] ?? '', '<noreply@example.com>') && ($got[0]['mail_from'] ?? '') === 'noreply@example.com');
    ok('a delivered alert leaves nothing in the failure log', $failedAlerts((int) $result['id']) === []);

    // A valid but unusual address is accepted by the form; it must not become a header.
    $_SESSION = ['form_started' => time() - 30];
    $before = count(sinkMessages($sink, 'plain'));
    $odd = $enquiry(['email' => '"odd\\ name"@example.org']);
    $cleanup[] = (int) ($odd['id'] ?? 0);
    $got = array_slice(sinkMessages($sink, 'plain'), $before);
    $oddAlert = parseMessage((string) ($got[0]['data'] ?? ''));
    ok('a valid but unusual (quoted) address still gets its enquiry alerted, but is never put in a header', !empty($odd['reference']) && count($got) === 1 && !isset($oddAlert['headers']['reply-to']) && !str_contains($oddAlert['head'], 'odd'), json_encode($odd));

    // Mail failing must not lose or fail the enquiry, and must be findable afterwards.
    mailConfig(['mail_port' => $closedPort, 'mail_timeout' => 2]);
    $_SESSION = ['form_started' => time() - 30];
    clearErrorLog();
    $second = $enquiry(['message' => 'This one is sent while the mail server is unreachable.']);
    $secondRef = (string) ($second['reference'] ?? '');
    $cleanup[] = (int) ($second['id'] ?? 0);
    ok('an enquiry is still saved and accepted when the mail server is down', $secondRef !== '' && (int) Database::value('SELECT COUNT(*) FROM enquiries WHERE reference = ?', [$secondRef]) === 1, json_encode($second));
    $notes = $failedAlerts((int) ($second['id'] ?? 0));
    ok('...and the office can see in the activity log that its alert did not go out, and why', count($notes) === 1 && str_contains($notes[0], 'could not be sent') && Mailer::lastError() !== '' && str_contains($notes[0], substr(Mailer::lastError(), 0, 40)), json_encode($notes));
    ok('...the reason is in the error log too, without a PHP warning', str_contains(errorLogText(), 'Mail not sent (smtp):') && !preg_match('/PHP (Warning|Notice|Deprecated)/', errorLogText()), errorLogText());

    mailConfig(['mail_enabled' => false]);
    $_SESSION = ['form_started' => time() - 30];
    $quiet = $enquiry(['message' => 'This one is sent while email is switched off entirely.']);
    $cleanup[] = (int) ($quiet['id'] ?? 0);
    ok('with email switched off an enquiry is saved and nothing is recorded as a failure (that is a choice)', !empty($quiet['reference']) && $failedAlerts((int) $quiet['id']) === []);
    foreach (array_filter($cleanup) as $enquiryId) Database::query('DELETE FROM enquiries WHERE id = ?', [$enquiryId]);
    Database::query("DELETE FROM audit_logs WHERE action = 'enquiry.alert_failed' AND entity_id IN (" . implode(',', array_map('intval', $cleanup ?: [0])) . ')');
    RateLimiter::clear('enquiry|' . $ip);
    unset($_SERVER['REMOTE_ADDR']);

    // A password-reset link reaches the member of staff.
    $email = 'mail-test-' . bin2hex(random_bytes(4)) . '@example.test';
    $uid = Database::insert('users', [
        'name' => 'Mail Test', 'email' => $email, 'password_hash' => password_hash('CorrectHorse123', PASSWORD_DEFAULT),
        'role' => 'content_editor', 'active' => 1, 'created_at' => Database::now(),
    ]);
    $failures = static fn(): int => (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.reset_mail_failed' AND entity_id = ?", [$uid]);
    mailConfig($base('plain', ['app_url' => 'https://real.example']));
    $before = count(sinkMessages($sink, 'plain'));
    $token = PasswordReset::issue($uid, ['name' => 'Mail Test', 'email' => $email]);
    $got = array_slice(sinkMessages($sink, 'plain'), $before);
    ok('a reset token is issued', strlen($token) === 64 && ctype_xdigit($token));
    ok('...and the member of staff is emailed once', count($got) === 1 && ($got[0]['rcpts'] ?? []) === [$email]);
    $resetBody = quoted_printable_decode(parseMessage((string) ($got[0]['data'] ?? ''))['body']);
    ok('the email holds the working link, built from APP_URL', str_contains($resetBody, PasswordReset::link($token)) && str_contains($resetBody, 'https://real.example/reset-password?token=' . $token), substr($resetBody, 0, 200));
    ok('a delivered email leaves no failure entry in the activity log', $failures() === 0);

    mailConfig(['mail_port' => $closedPort, 'mail_timeout' => 2, 'app_url' => 'https://real.example']);
    $token = PasswordReset::issue($uid, ['name' => 'Mail Test', 'email' => $email]);
    ok('with the mail server down a token is still issued (the visitor sees the same neutral page)', strlen($token) === 64);
    ok('...but the operator can now see the email failed (activity log)', $failures() === 1);
    $note = (string) Database::value("SELECT details FROM audit_logs WHERE action = 'user.reset_mail_failed' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
    ok('...with the reason, and without the token', str_contains($note, 'could not be sent') && !str_contains($note, $token), $note);

    mailConfig(['mail_enabled' => false, 'app_url' => 'https://real.example']);
    $token = PasswordReset::issue($uid, ['name' => 'Mail Test', 'email' => $email]);
    ok('with email switched off no failure is recorded (that is a choice, not a fault)', strlen($token) === 64 && $failures() === 1);
    Database::query('DELETE FROM password_resets WHERE user_id = ?', [$uid]);
    Database::query("DELETE FROM audit_logs WHERE entity_id = ? AND action IN ('user.reset_requested','user.reset_mail_failed')", [$uid]);
    Database::query('DELETE FROM users WHERE id = ?', [$uid]);

    // "Send a test email" on the settings screen (the same check as bin/mail-test.php, for a host with no terminal).
    echo "\n== The test button on the settings screen ==\n";
    $admin = ['id' => 987654, 'name' => 'Test Administrator', 'email' => 'owner@example.org'];
    $testKey = 'mailtest|' . $admin['id'];
    RateLimiter::clear($testKey);
    $noted = static fn(string $like): int => (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.mail_test' AND details LIKE ?", [$like]);
    mailConfig(['mail_enabled' => false]);
    $r = AdminController::mailTest($admin);
    ok('with email switched off the button explains instead of failing mysteriously', $r['ok'] === false && str_contains($r['message'], 'switched off') && str_contains($r['message'], 'docs/EMAIL.md'), $r['message']);
    mailConfig($base('plain'));
    $before = count(sinkMessages($sink, 'plain'));
    $r = AdminController::mailTest($admin);
    $got = array_slice(sinkMessages($sink, 'plain'), $before);
    ok('with email on, one test message goes to the signed-in person\'s own address, and only there', $r['ok'] === true && count($got) === 1 && ($got[0]['rcpts'] ?? []) === ['owner@example.org'], json_encode([$r, $got[0]['rcpts'] ?? null]));
    ok('...and the answer says where to look', str_contains($r['message'], 'owner@example.org') && str_contains($r['message'], 'spam folder'), $r['message']);
    ok('...and it is recorded in the activity log', $noted('Sent a test email') >= 1);
    mailConfig($base('auth', ['mail_username' => 'mailer', 'mail_password' => 'Wr0ng-Sentinel-Pw']));
    $r = AdminController::mailTest($admin);
    ok('a failure shows the server\'s reason and what to change, in the page', $r['ok'] === false && str_contains($r['message'], 'Sign-in was refused') && str_contains($r['message'], 'MAIL_PASSWORD'), $r['message']);
    ok('...without the password', !str_contains($r['message'], 'Wr0ng-Sentinel-Pw'));
    ok('...as two sentences, what happened and then what to try, never run together', preg_match('/\w\)?[^.]*\. What to try: [A-Z]/', $r['message']) === 1 && str_ends_with($r['message'], '.'), $r['message']);
    ok('...and the failure is recorded for whoever looks later', $noted('Test email failed: Sign-in was refused%') >= 1);
    RateLimiter::clear($testKey);
    mailConfig($base('plain'));
    $results = [];
    for ($i = 0; $i < 7; $i++) $results[] = AdminController::mailTest($admin);
    ok('five tests are allowed in ten minutes, the sixth is refused (no mailbox flooding)', count(array_filter($results, static fn($x) => $x['ok'])) === 5 && str_contains($results[5]['message'], 'enough test emails') && !$results[6]['ok'], json_encode(array_map(static fn($x) => $x['ok'], $results)));
    RateLimiter::clear($testKey);
    Database::query("DELETE FROM audit_logs WHERE action = 'settings.mail_test' AND (details LIKE 'Sent a test email' OR details LIKE 'Test email failed:%')");

    // The settings page itself, rendered with a password configured.
    $renderSettings = static function (array $mail): string {
        $settings = [];
        ob_start();
        include ROOT_PATH . '/app/views/admin/settings.php';
        return (string) ob_get_clean();
    };
    $mailData = static fn(string $to = 'owner@example.org'): array => ['enabled' => Mailer::enabled(), 'describe' => Mailer::describe(), 'problems' => Mailer::problems(), 'to' => $to];
    $sentinel = 'Sentinel-Pw-' . bin2hex(random_bytes(4));
    mailConfig($base('auth', ['mail_username' => 'mailer', 'mail_password' => $sentinel]));
    $page = $renderSettings($mailData());
    ok('with email on, the page says how mail is sent and offers the button, in a form of its own with a CSRF token',
        str_contains($page, 'id="email-delivery"') && str_contains($page, 'Send a test email to me') && str_contains($page, 'signing in as mailer')
        && preg_match('/<form[^>]*method="post"[^>]*>\s*<input type="hidden" name="_token" value="[a-f0-9]+">\s*<input type="hidden" name="action" value="test-mail">/', $page) === 1
        && substr_count($page, '<form') === substr_count($page, '</form>'));
    ok('...and the password appears nowhere on it, plain or encoded', !str_contains($page, $sentinel) && !str_contains($page, base64_encode($sentinel)) && !str_contains($page, base64_encode("\0mailer\0" . $sentinel)));
    $page = $renderSettings($mailData('"><script>alert(1)</script>@example.org'));
    ok('the address shown is escaped, whatever it holds', !str_contains($page, '<script>alert(1)</script>') && str_contains($page, '&lt;script&gt;'));
    mailConfig(['mail_enabled' => false]);
    $page = $renderSettings($mailData());
    ok('with email off the page says so and points to the guide, with no button that could only fail', str_contains($page, 'off') && str_contains($page, 'docs/EMAIL.md') && !str_contains($page, 'Send a test email to me'));
    mailConfig($base('plain', ['mail_encryption' => 'none', 'mail_host' => 'mail.example.com', 'mail_username' => 'u', 'mail_password' => '', 'mail_verify_peer' => false]));
    $page = $renderSettings($mailData());
    ok('a setup that will not work is flagged on the page itself, before anyone presses anything', str_contains($page, 'MAIL_PASSWORD is empty') && str_contains($page, 'not checked'), strip_tags($page));
}

// ================================================================== mail()
echo "\n== PHP's mail() driver ==\n";
$capturing = str_contains((string) ini_get('sendmail_path'), 'mail-capture.sh');
$windowsSmtp = PHP_OS_FAMILY === 'Windows' && trim((string) ini_get('sendmail_path')) === '';
if ($capturing) {
    $captured = $root . '/storage/logs/mail-capture.eml';
    $capturedArgs = $root . '/storage/logs/mail-capture.args';
    @unlink($captured);
    @unlink($capturedArgs);
    mailConfig(['mail_driver' => 'mail', 'mail_host' => '']);
    clearErrorLog();
    $subject = 'Reset your Cédar password';
    $sent = Mailer::send('office@example.com', $subject, $sample, 'visitor@example.org');
    $eml = is_file($captured) ? (string) file_get_contents($captured) : '';
    ok('mail() hands the message to the mail program', $sent === true && $eml !== '', Mailer::lastError());
    $split = (int) strpos($eml, "\r\n\r\n");
    $headBlock = substr($eml, 0, $split);
    $bodyBlock = substr($eml, $split + 4);
    $mh = parseMessage($eml);
    ok('it carries To, a readable Subject, and the From the site chose', ($mh['headers']['to'][0] ?? '') === 'office@example.com' && decodeWords($mh['headers']['subject'][0] ?? '') === $subject && str_ends_with($mh['headers']['from'][0] ?? '', '<noreply@example.com>'), $headBlock);
    ok('the same Date, Message-ID, Reply-To and MIME headers as the other drivers', isset($mh['headers']['date'], $mh['headers']['message-id']) && ($mh['headers']['reply-to'][0] ?? '') === 'visitor@example.org' && ($mh['headers']['content-transfer-encoding'][0] ?? '') === 'quoted-printable');
    ok('the body decodes to exactly what was sent', plainText(quoted_printable_decode($bodyBlock)) === plainText($sample), substr($bodyBlock, 0, 120));
    ok('...and no line of it starts with a dot', !preg_match('/(^|\n)\./', $bodyBlock));
    ok('no body line is longer than 76 characters', max(array_map(static fn($l) => strlen(rtrim($l, "\r")), explode("\n", $bodyBlock))) <= 76);
    $args = trim((string) @file_get_contents($capturedArgs));
    ok('the envelope sender is passed to the mail program (-f), so the Return-Path is the site\'s, not the server account\'s', str_contains($args, '-fnoreply@example.com'), $args);
    ok('a successful mail() leaves the error log empty', errorLogText() === '', errorLogText());
    mailConfig(['mail_driver' => 'mail', 'mail_host' => '', 'mail_from' => '"a\\ b\\ -X/tmp/pwned"@example.com']);
    @unlink($captured); @unlink($capturedArgs);
    $sent = Mailer::send('office@example.com', 'Odd sender', 'x');
    $args = trim((string) @file_get_contents($capturedArgs));
    ok('an unusual sender address (valid, but able to smuggle options) is never put on the command line', !str_contains($args, '-f') && !str_contains($args, 'pwned'), $args);
    mailConfig(['mail_driver' => 'mail', 'mail_host' => '']);
    foreach ($hostileReplyTos as $bad) {
        @unlink($captured); @unlink($capturedArgs);
        $sentBad = Mailer::send('office@example.com', 'Hostile reply-to', 'Body', $bad);
        $emlBad = is_file($captured) ? (string) file_get_contents($captured) : '';
        $headersBad = parseMessage($emlBad)['headers'];
        ok('through mail() a Reply-To that is not a plain address is dropped (the message still goes, with no extra header): ' . json_encode($bad),
            $sentBad === true && $emlBad !== '' && !isset($headersBad['reply-to']) && !isset($headersBad['bcc']) && count($headersBad['from'] ?? []) === 1 && !str_contains($emlBad, 'attacker@example.com'), Mailer::lastError());
    }
    @unlink($captured);
    @unlink($capturedArgs);
} elseif ($windowsSmtp && $sink !== null) {
    // Windows PHP has no sendmail: mail() speaks SMTP itself to php.ini's SMTP and smtp_port. This is
    // what Laragon, XAMPP and WAMP run unless sendmail_path was set up.
    $old = [ini_get('SMTP'), ini_get('smtp_port'), ini_get('sendmail_from')];
    ini_set('SMTP', '127.0.0.1');
    ini_set('smtp_port', (string) $port('plain'));
    ini_set('sendmail_from', 'noreply@example.com');
    mailConfig(['mail_driver' => 'mail', 'mail_host' => '']);
    $before = count(sinkMessages($sink, 'plain'));
    clearErrorLog();
    $sent = Mailer::send('office@example.com', 'Reset your Cédar password', $sample, 'visitor@example.org');
    $got = array_slice(sinkMessages($sink, 'plain'), $before);
    ok('on Windows mail() delivers to the SMTP server named in php.ini', $sent === true && count($got) === 1, Mailer::lastError());
    $first = $got[0] ?? ['mail_from' => '', 'rcpts' => [], 'data' => ''];
    $mh = parseMessage((string) $first['data']);
    ok('...to the right recipient, from the right sender', ($first['rcpts'] ?? []) === ['office@example.com'] && $first['mail_from'] === 'noreply@example.com', json_encode([$first['mail_from'], $first['rcpts']]));
    ok('...with a readable subject, the Reply-To and the body intact', decodeWords($mh['headers']['subject'][0] ?? '') === 'Reset your Cédar password' && ($mh['headers']['reply-to'][0] ?? '') === 'visitor@example.org' && plainText(quoted_printable_decode($mh['body'])) === plainText($sample), substr((string) $first['data'], 0, 300));
    ok('a successful mail() leaves the error log empty', errorLogText() === '', errorLogText());
    ini_set('smtp_port', '1');
    $sent = Mailer::send('office@example.com', 'x', 'y');
    ok('with nothing listening, mail() fails with a reason and no PHP warning in the log', $sent === false && str_contains(Mailer::lastError(), 'mail() could not pass the message') && !preg_match('/PHP (Warning|Notice|Deprecated)/', errorLogText()), Mailer::lastError() . ' | ' . errorLogText());
    ini_set('SMTP', (string) $old[0]); ini_set('smtp_port', (string) $old[1]); ini_set('sendmail_from', (string) $old[2]);
} else {
    skipped('the mail() driver', 'run with -d sendmail_path="sh $PWD/tests/fixtures/mail-capture.sh -t -i" to capture what mail() hands to a mail program (on Windows it uses the SMTP test server)');
}

// ================================================================== the command line
echo "\n== bin/mail-test.php ==\n";
$cliCan = $sink !== null && function_exists('proc_open') && php_uname('s') !== 'Emscripten' && !is_file($root . '/config.local.php');
if (!$cliCan) {
    skipped('the command-line check', $sink === null ? 'needs the SMTP sink'
        : (is_file($root . '/config.local.php') ? 'config.local.php exists here and would override the settings this check passes in (and could send real email)' : 'cannot start a child PHP here'));
} else {
    $run = static function (array $env, array $args, string $script = 'mail-test.php') use ($root): array {
        $clean = ['MAIL_ENABLED' => '', 'MAIL_DRIVER' => '', 'MAIL_HOST' => '', 'MAIL_PORT' => '', 'MAIL_ENCRYPTION' => '', 'MAIL_USERNAME' => '',
                  'MAIL_PASSWORD' => '', 'MAIL_FROM' => '', 'MAIL_VERIFY_PEER' => '', 'MAIL_CAFILE' => '', 'MAIL_TIMEOUT' => '', 'APP_ENV' => 'local',
                  'MAIL_OAUTH_PROVIDER' => '', 'MAIL_OAUTH_FLOW' => '', 'MAIL_OAUTH_TENANT' => '', 'MAIL_OAUTH_CLIENT_ID' => '', 'MAIL_OAUTH_CLIENT_SECRET' => '',
                  'MAIL_OAUTH_AUTHORITY' => '', 'MAIL_OAUTH_STORE' => ''];
        $proc = proc_open(array_merge([PHP_BINARY, $root . '/bin/' . $script], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root,
            array_merge(getenv(), $clean, array_map('strval', $env)));
        if (!is_resource($proc)) return ['could not start php', -1];
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [trim(str_replace("\r\n", "\n", $out)), proc_close($proc)];
    };
    $catcher = ['MAIL_ENABLED' => 'true', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => $port('plain'), 'MAIL_FROM' => 'noreply@example.com'];
    $before = count(sinkMessages($sink, 'plain'));
    [$out, $code] = $run($catcher, ['someone@example.org']);
    ok('the check reports ACCEPTED and exits 0 when the server takes the message', str_contains($out, 'ACCEPTED') && $code === 0, $out . " (exit {$code})");
    ok('...and the server really received it, addressed as asked', count(sinkMessages($sink, 'plain')) === $before + 1 && (sinkMessages($sink, 'plain')[$before]['rcpts'] ?? []) === ['someone@example.org']);
    ok('it reports how mail is configured, from environment variables alone', str_contains($out, 'SMTP server 127.0.0.1:' . $port('plain')) && str_contains($out, 'no encryption'));
    $before = count(sinkMessages($sink, 'plain'));
    [$out, $code] = $run($catcher, []);
    ok('with no address given it tests the office address (public email when notifications are empty)', str_contains($out, 'ACCEPTED') && (sinkMessages($sink, 'plain')[$before]['rcpts'] ?? []) === ['front-desk@example.test'], $out);
    [$out, $code] = $run(array_replace($catcher, ['MAIL_PORT' => $port('auth'), 'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => 'Wr0ng-Sentinel-Pw']), ['someone@example.org']);
    ok('a failure says NOT SENT, why, and what to try, and exits 1', str_contains($out, 'NOT SENT') && str_contains($out, 'Sign-in was refused') && str_contains($out, 'What to try') && $code === 1, $out . " (exit {$code})");
    ok('...without printing the password', !str_contains($out, 'Wr0ng-Sentinel-Pw'));
    [$out] = $run(array_replace($catcher, ['MAIL_PORT' => $port('auth'), 'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => $secret]), ['someone@example.org', '--verbose']);
    ok('--verbose shows the conversation with the sign-in masked', str_contains($out, 'S: 220') && str_contains($out, 'AUTH PLAIN ***') && !str_contains($out, $secret) && !str_contains($out, base64_encode("\0mailer\0" . $secret)), $out);
    [$out, $code] = $run(['MAIL_ENABLED' => 'false'], ['someone@example.org']);
    ok('with email switched off it says so and exits 2', str_contains($out, 'switched off') && !str_contains($out, 'ACCEPTED') && $code === 2, $out . " (exit {$code})");
    [$out, $code] = $run($catcher, ['not-an-address']);
    ok('an address that is not one exits 2 with a usage line', $code === 2 && str_contains($out, 'Say where to send it'), $out . " (exit {$code})");
    [$out, $code] = $run(['MAIL_ENABLED' => 'true', 'MAIL_DRIVER' => 'log', 'MAIL_FROM' => 'noreply@example.com'], ['someone@example.org']);
    $logged = $root . '/storage/logs/mail.log';
    ok('the log driver needs no server at all, and says where the message went', str_contains($out, 'ACCEPTED') && str_contains($out, 'mail.log') && is_file($logged) && str_contains((string) file_get_contents($logged), 'To: someone@example.org'), $out);
    if (is_file($logged)) unlink($logged);

    // ---- the same, with OAuth: bin/mail-test.php signs in with a token, bin/mail-oauth.php connects a person
    if (!isset($kit, $https)) {
        skipped('the command-line OAuth checks', 'the running sink has no token service (an older tests/fixtures/smtp-sink.py?)');
    } else {
        $cliStore = sys_get_temp_dir() . '/cedar-oauth-cli-' . bin2hex(random_bytes(4)) . '.json';
        $oauthEnv = ['MAIL_ENABLED' => 'true', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => $port('oauth'), 'MAIL_ENCRYPTION' => 'tls', 'MAIL_CAFILE' => $sink['cert'], 'MAIL_TIMEOUT' => 3,
                     'MAIL_OAUTH_PROVIDER' => 'microsoft', 'MAIL_OAUTH_TENANT' => 'contoso', 'MAIL_OAUTH_AUTHORITY' => $https, 'MAIL_OAUTH_STORE' => $cliStore];
        $cliApp = array_replace($oauthEnv, ['MAIL_OAUTH_FLOW' => 'app', 'MAIL_OAUTH_CLIENT_ID' => $kit['app_client_id'], 'MAIL_OAUTH_CLIENT_SECRET' => $kit['app_secret'], 'MAIL_USERNAME' => $kit['mailbox']]);
        $cliUser = array_replace($oauthEnv, ['MAIL_OAUTH_FLOW' => 'user', 'MAIL_OAUTH_TENANT' => 'auto', 'MAIL_OAUTH_CLIENT_ID' => $kit['user_client_id']]);
        $cliSaved = static function () use ($cliStore): array { return (array) json_decode(is_file($cliStore) ? (string) file_get_contents($cliStore) : '[]', true); };
        $before = count(sinkMessages($sink, 'oauth'));
        [$out, $code] = $run($cliApp, ['someone@example.org']);
        $used = (string) ($cliSaved()['app']['access_token'] ?? 'none');
        ok('the check signs in with OAuth as an app, from environment variables alone: ACCEPTED, exit 0, and the server received it from the mailbox',
            str_contains($out, 'ACCEPTED') && $code === 0 && count(sinkMessages($sink, 'oauth')) === $before + 1 && (sinkMessages($sink, 'oauth')[$before]['authed'] ?? '') === $kit['mailbox'], $out . " (exit {$code})");
        ok('...saying how it signed in, and printing neither the client secret nor the token it got', preg_match('/Sign-in:\s+Microsoft 365 OAuth, the website signs in as an app \(no password\)/', $out) === 1 && str_contains($out, 'Microsoft 365 OAuth as ' . $kit['mailbox']) && $used !== 'none' && !str_contains($out, $kit['app_secret']) && !str_contains($out, $used), $out);
        [$out] = $run($cliApp, ['someone@example.org', '--verbose']);
        ok('--verbose shows the OAuth sign-in with the token and its XOAUTH2 string masked, in both encodings', str_contains($out, 'AUTH XOAUTH2 ***') && $used !== 'none' && !str_contains($out, $used) && !str_contains($out, SmtpClient::xoauth2($kit['mailbox'], $used)) && !str_contains($out, base64_encode($used)), $out);
        [$out, $code] = $run(array_replace($cliApp, ['MAIL_OAUTH_CLIENT_SECRET' => 'Wr0ng~Sentinel.Secret-42']), ['someone@example.org']);
        ok('a wrong client secret says NOT SENT, in Microsoft\'s words and plain ones, with what to try, exit 1, and without printing the secret',
            str_contains($out, 'NOT SENT') && str_contains($out, 'client secret is wrong') && str_contains($out, 'What to try') && $code === 1 && !str_contains($out, 'Wr0ng~Sentinel.Secret-42'), $out . " (exit {$code})");
        [$out, $code] = $run($cliUser, ['someone@example.org']);
        ok('connect-by-person mail before anyone has connected: NOT SENT, "not connected yet", exit 1', str_contains($out, 'NOT SENT') && str_contains($out, 'is not connected yet') && $code === 1, $out . " (exit {$code})");

        [$out] = $run($cliUser, [], 'status.php');
        ok('status.php names the way in when nobody has connected: the warning, and the same step from a terminal', str_contains($out, 'is not connected yet') && str_contains($out, 'php bin/mail-oauth.php connect'), $out);
        [$out] = $run($cliApp, [], 'status.php');
        ok('...and says nothing of the kind for the app way, which has nothing to connect', str_contains($out, 'Microsoft 365 OAuth as ' . $kit['mailbox']) && !str_contains($out, 'bin/mail-oauth.php connect') && !str_contains($out, 'WARNING'), $out);
        [$out, $code] = $run($cliUser, ['status'], 'mail-oauth.php');
        ok('mail-oauth status before connecting says NOT connected and exits 1', str_contains($out, 'NOT connected') && $code === 1, $out . " (exit {$code})");
        [$out, $code] = $run($cliUser, ['connect', '--poll=2'], 'mail-oauth.php');
        ok('connect prints the code and the page to open, waits for the sign-in, saves it, and exits 0', str_contains($out, 'AB12CD34') && str_contains($out, 'https://microsoft.com/devicelogin') && str_contains($out, 'CONNECTED as ' . $kit['mailbox']) && $code === 0, $out . " (exit {$code})");
        $saved = $cliSaved()['user'] ?? [];
        ok('...the file holds the refresh token and the account, readable only by its owner, and the output shows no token', strlen((string) ($saved['refresh_token'] ?? '')) > 60 && ($saved['account'] ?? '') === $kit['mailbox']
            && (PHP_OS_FAMILY === 'Windows' || (fileperms($cliStore) & 0077) === 0) && !str_contains($out, (string) ($saved['refresh_token'] ?? 'none')) && !str_contains($out, (string) ($saved['access_token'] ?? 'none')));
        [$out, $code] = $run($cliUser, ['status'], 'mail-oauth.php');
        ok('status then says connected, as whom, and exits 0', str_contains($out, 'is connected as ' . $kit['mailbox']) && $code === 0, $out . " (exit {$code})");
        $before = count(sinkMessages($sink, 'oauth'));
        [$out, $code] = $run($cliUser, ['someone@example.org']);
        ok('the connected account then sends the check email (exit 0), and the check says whose sign-in it used', str_contains($out, 'ACCEPTED') && preg_match('/Sign-in:\s+Microsoft 365 OAuth, connected as ' . preg_quote($kit['mailbox'], '/') . '/', $out) === 1 && $code === 0 && count(sinkMessages($sink, 'oauth')) === $before + 1, $out . " (exit {$code})");
        // Housekeeping uses the connection once a day so that a quiet site does not lose it to Microsoft's 90 days.
        $data = $cliSaved();
        $data['user']['expires_at'] = time() - 5;
        file_put_contents($cliStore, json_encode($data));
        $oldRefresh = (string) $data['user']['refresh_token'];
        $beforeRequests = count($requests('token_https'));
        [$out, $code] = $run($cliUser, [], 'maintenance.php');
        $asked = array_slice($requests('token_https'), $beforeRequests);
        ok('bin/maintenance.php uses a saved connection (so Microsoft\'s 90 days of silence never start): it reports "renewed", asked Microsoft once with the refresh token, and kept the new one',
            str_contains($out, 'Microsoft 365 sign-in') && str_contains($out, 'renewed') && str_contains($out, 'Done.') && count($asked) === 1 && ($asked[0]['form']['refresh_token'] ?? '') === $oldRefresh
            && ($cliSaved()['user']['refresh_token'] ?? $oldRefresh) !== $oldRefresh && !str_contains($out, $oldRefresh), $out);
        [$out, $code] = $run(array_replace($cliUser, ['MAIL_OAUTH_CLIENT_ID' => $kit['user_client_id'], 'MAIL_ENABLED' => 'false']), [], 'maintenance.php');
        ok('...and does nothing of the sort when email is switched off', str_contains($out, 'Done.') && !str_contains($out, 'sign-in'), $out);
        [$out, $code] = $run($cliUser, ['disconnect'], 'mail-oauth.php');
        ok('disconnect says so and exits 0', str_contains($out, 'Disconnected') && $code === 0, $out . " (exit {$code})");
        [$out, $code] = $run($cliUser, ['status'], 'mail-oauth.php');
        ok('...after which status says NOT connected again, and the saved sign-in is gone from the file', str_contains($out, 'NOT connected') && $code === 1 && !isset($cliSaved()['user']), $out . " (exit {$code})");
        [$out, $code] = $run($cliApp, ['connect'], 'mail-oauth.php');
        ok('with the app way there is nothing to connect: said so, exit 2', str_contains($out, 'nothing to connect') && $code === 2, $out . " (exit {$code})");
        [$out, $code] = $run($cliApp, ['status'], 'mail-oauth.php');
        ok('...and status says the website signs in as an app, and points at the mail check (exit 0)', str_contains($out, 'signs in as an app') && str_contains($out, 'bin/mail-test.php') && $code === 0, $out . " (exit {$code})");
        [$out, $code] = $run([], ['status'], 'mail-oauth.php');
        ok('with OAuth switched off it says so and exits 2', str_contains($out, 'not switched on') && $code === 2, $out . " (exit {$code})");
        [$out, $code] = $run($cliUser, ['frobnicate'], 'mail-oauth.php');
        ok('a command it does not know exits 2 with the usage line', str_contains($out, 'Unknown command frobnicate') && str_contains($out, 'Usage:') && $code === 2, $out . " (exit {$code})");
        [$out, $code] = $run(array_replace($cliUser, ['MAIL_OAUTH_CLIENT_ID' => '99999999-9999-4999-8999-999999999999']), ['connect', '--poll=2'], 'mail-oauth.php');
        ok('connect with a client ID Microsoft does not know says NOT CONNECTED, why and what to try, exit 1, without waiting', str_contains($out, 'NOT CONNECTED') && str_contains($out, 'What to try') && !str_contains($out, 'Waiting for you') && $code === 1, $out . " (exit {$code})");
        [$out, $code] = $run(array_replace($cliUser, ['MAIL_OAUTH_TENANT' => '']), ['connect'], 'mail-oauth.php');
        ok('a setting that cannot work is named, and nothing is asked of Microsoft (exit 2)', str_contains($out, 'MAIL_OAUTH_TENANT is empty') && !str_contains($out, 'Waiting') && $code === 2, $out . " (exit {$code})");
        foreach (array_merge([$cliStore], glob($cliStore . '.*') ?: []) as $f) if (is_file($f)) unlink($f);
        Database::query("DELETE FROM audit_logs WHERE action = 'settings.mail_oauth'");
    }
}

// ================================================================== done
stopSink($sink);
$restoreSettings();
ini_set('error_log', $previousErrorLog);
if (is_file($errorLogFile)) unlink($errorLogFile);
$GLOBALS['config'] = $baseConfig;
if ($skipped > 0) echo "\n{$skipped} check(s) skipped: see the SKIP lines above.\n";
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

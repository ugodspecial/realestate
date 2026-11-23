<?php
/**
 * Connect, check or disconnect the Microsoft 365 account the website sends its email as, from a terminal.
 * It does what Dashboard > Settings > Email delivery > "Microsoft 365 sign-in" does, for a person who is on
 * the server anyway. It matters only for MAIL_OAUTH_FLOW=user (a person signs in once); with
 * MAIL_OAUTH_FLOW=app the website signs in as an app and there is nothing to connect.
 *
 * Usage: php bin/mail-oauth.php connect      sign in as the mailbox that should send the website's email
 *        php bin/mail-oauth.php status       say whether it is connected, and as whom
 *        php bin/mail-oauth.php disconnect   forget the saved sign-in
 *
 * "connect" prints a short code and a web address. Open the address on ANY device (your phone will do),
 * type the code, sign in as the mailbox and accept. This command waits until Microsoft has seen that,
 * for as long as the code lives (about 15 minutes), then saves the sign-in. Nothing secret is printed.
 * Afterwards run  php bin/mail-test.php you@yourdomain.com  to prove that mail really goes out.
 *
 * Option for "connect":  --poll=SECONDS  how often to ask Microsoft (default: what Microsoft says, normally 5).
 *
 * Exit status: 0 done / connected, 1 failed, refused or not connected, 2 OAuth is not set up for this
 * (or bad arguments).
 */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);
require dirname(__DIR__) . '/app/bootstrap.php';

$usage = 'Usage: php bin/mail-oauth.php connect | status | disconnect   (connect takes --poll=SECONDS)';
$command = '';
$poll = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') { echo $usage . PHP_EOL; exit(0); }
    elseif (preg_match('/^--poll=(\d{1,3})$/', $arg, $m)) $poll = max(1, (int) $m[1]);
    elseif (str_starts_with($arg, '-') || $command !== '') { fwrite(STDERR, 'Unknown argument ' . $arg . PHP_EOL . $usage . PHP_EOL); exit(2); }
    else $command = $arg;
}
if ($command === '') $command = 'status';
if (!in_array($command, ['connect', 'status', 'disconnect'], true)) { fwrite(STDERR, 'Unknown command ' . $command . PHP_EOL . $usage . PHP_EOL); exit(2); }

$o = MailOAuth::settings();
if ($o['provider'] === '') {
    echo 'OAuth sign-in is not switched on: MAIL_OAUTH_PROVIDER is empty. See "Microsoft 365" in docs/EMAIL.md.' . PHP_EOL;
    exit(2);
}
// Mistakes in the settings, apart from "not connected yet", which is what this command is for.
$problems = array_values(array_filter(MailOAuth::problems(), static fn(string $p): bool => !str_contains($p, 'is not connected yet')));
foreach ($problems as $problem) echo '! ' . $problem . PHP_EOL;
if ($problems !== []) exit(2);

if ($o['flow'] !== 'user') {
    echo 'The website signs in as an app (MAIL_OAUTH_FLOW=app, client ID ' . $o['client_id'] . '), so there is nothing to connect.' . PHP_EOL;
    if ($command === 'status') echo 'Prove that it works with:  php bin/mail-test.php you@example.com' . PHP_EOL;
    exit($command === 'status' ? 0 : 2);
}

if ($command === 'status') {
    if ($o['connected']) {
        echo $o['label'] . ' is connected as ' . ($o['account'] !== '' ? $o['account'] : 'a saved account')
            . ($o['connected_at'] !== '' ? ' (since ' . date('j F Y', (int) strtotime($o['connected_at'])) . ')' : '') . '.' . PHP_EOL;
        exit(0);
    }
    echo $o['label'] . ' is NOT connected. Run:  php bin/mail-oauth.php connect' . PHP_EOL;
    exit(1);
}

if ($command === 'disconnect') {
    if (!MailOAuth::disconnect()) {
        echo 'NOT DISCONNECTED: the file that holds the saved sign-in could not be changed (is it writable by this user?), so it is still there.' . PHP_EOL;
        exit(1);
    }
    echo 'Disconnected. The website cannot send email through ' . $o['label'] . ' until it is connected again.' . PHP_EOL;
    exit(0);
}

// connect
$mail = Mailer::settings();
$net = ['timeout' => $mail['timeout'], 'verify_peer' => $mail['verify_peer'], 'cafile' => $mail['cafile']];
$fail = static function (RuntimeException $e): never {
    echo 'NOT CONNECTED: ' . $e->getMessage() . PHP_EOL;
    $advice = MailOAuth::hint($e->getMessage());
    if ($advice !== '') echo 'What to try: ' . $advice . PHP_EOL;
    exit(1);
};
try {
    $device = MailOAuth::beginDeviceLogin($net);
} catch (RuntimeException $e) {
    $fail($e);
}
echo 'Connect ' . $o['label'] . ':' . PHP_EOL;
echo '  1. On any device, open  ' . $device['verification_uri'] . PHP_EOL;
echo '  2. Type this code:      ' . $device['user_code'] . PHP_EOL;
echo '  3. Sign in as the mailbox that should send the website\'s email, and accept.' . PHP_EOL;
echo 'Waiting for you to finish (the code works for about ' . max(1, (int) ceil(($device['expires_at'] - time()) / 60)) . ' minutes; Ctrl+C gives up) ...' . PHP_EOL;

$interval = $poll > 0 ? $poll : $device['interval'];
$hiccups = 0;
while (true) {
    sleep($interval);
    try {
        $result = MailOAuth::pollDeviceLogin($device, $net);
    } catch (RuntimeException $e) {
        // A network hiccup (the code is still good) is tried again a few times; anything else ends it.
        if ($e->getCode() === MailOAuth::TRANSIENT && ++$hiccups < 4 && time() < $device['expires_at']) continue;
        $fail($e);
    }
    $hiccups = 0;
    if ($result['state'] === 'connected') {
        $who = $result['account'] !== '' ? ' as ' . $result['account'] : '';
        try {
            Audit::log('settings.mail_oauth', 'settings', null, 'Connected ' . $o['label'] . $who . ' (command line)');
        } catch (Throwable) {
            // The activity log is a courtesy here: with no database yet the connection itself is still saved.
        }
        echo 'CONNECTED' . $who . '. Now check that mail goes out:  php bin/mail-test.php you@example.com' . PHP_EOL;
        exit(0);
    }
    if (time() >= $device['expires_at']) {
        echo 'NOT CONNECTED: the code expired before the sign-in was finished. Run this again to get a new one.' . PHP_EOL;
        exit(1);
    }
}

<?php
/**
 * Check, in about a second, whether this site can really send email from the machine it is
 * running on. It sends one short message through exactly the settings the website uses and says,
 * in plain words, what happened and what to change if it failed.
 *
 * Usage: php bin/mail-test.php you@example.com
 *        php bin/mail-test.php you@example.com --verbose   (also prints the server conversation)
 *        php bin/mail-test.php                              (to the enquiry-alert address in Website settings)
 *
 * Run it on the server (or in Laragon's terminal) after setting MAIL_* in the environment or in
 * config.local.php. With no terminal on your hosting plan, use "Send a test email" at the bottom of
 * Dashboard > Settings instead: it runs the same check.
 *
 * "Accepted" below means the mail server took the message. Whether it then lands in the inbox
 * or the spam folder depends on your domain's SPF and DKIM records, which only the DNS settings
 * of your domain can fix.
 *
 * Exit status: 0 accepted, 1 not sent, 2 not set up / bad arguments.
 */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);
require dirname(__DIR__) . '/app/bootstrap.php';

$verbose = false;
$to = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verbose' || $arg === '-v') $verbose = true;
    elseif ($arg === '--help' || $arg === '-h') { echo "Usage: php bin/mail-test.php [you@example.com] [--verbose]" . PHP_EOL; exit(0); }
    elseif (str_starts_with($arg, '-')) { fwrite(STDERR, 'Unknown option ' . $arg . PHP_EOL); exit(2); }
    else $to = $arg;
}
if ($to === '') $to = Mailer::officeAddress();
if (!Mailer::isAddress($to)) {
    fwrite(STDERR, 'Say where to send it:  php bin/mail-test.php you@example.com' . PHP_EOL);
    exit(2);
}

$s = Mailer::settings();
echo 'Email check for ' . brand() . PHP_EOL;
echo '  Switch (MAIL_ENABLED): ' . ($s['enabled'] ? 'on' : 'OFF') . PHP_EOL;
echo '  Method:                ' . Mailer::describe() . PHP_EOL;
echo '  Sent from:             ' . ($s['from'] !== '' ? $s['from'] : '(none)') . PHP_EOL;
if ($s['driver'] === 'smtp') echo '  Certificate check:     ' . ($s['verify_peer'] ? 'on' : 'OFF') . PHP_EOL;
if ($s['oauth'] !== '') {
    $o = MailOAuth::settings();
    echo '  Sign-in:               ' . $s['oauth'] . ' OAuth, ' . ($o['flow'] === 'app' ? 'the website signs in as an app (no password)'
        : ($o['connected'] ? 'connected as ' . ($o['account'] !== '' ? $o['account'] : 'a saved account') : 'NOT connected yet (Settings > Email delivery)')) . PHP_EOL;
}
foreach (Mailer::problems() as $problem) echo '  ! ' . $problem . PHP_EOL;
echo PHP_EOL;

if (!$s['enabled']) {
    echo 'Email is switched off, so the site sends nothing. Turn it on with MAIL_ENABLED=true (environment)' . PHP_EOL;
    echo "or 'mail_enabled' => true (config.local.php), then run this again." . PHP_EOL;
    exit(2);
}

echo 'Sending a test message to ' . $to . ' ...' . PHP_EOL;
$started = microtime(true);
$sent = Mailer::send($to, 'Test email from ' . brand(), implode("\n", [
    'This is a test message from the ' . brand() . ' website.',
    '',
    'If you can read it, the site can send email from this machine.',
    'Sent ' . date('Y-m-d H:i:s T') . ' by "php bin/mail-test.php".',
]));
$seconds = number_format(microtime(true) - $started, 1);

if ($verbose && Mailer::conversation()) {
    echo PHP_EOL . 'Conversation (passwords and access tokens are masked):' . PHP_EOL;
    foreach (Mailer::conversation() as $line) echo '  ' . $line . PHP_EOL;
    echo PHP_EOL;
}

if ($sent) {
    echo 'ACCEPTED in ' . $seconds . 's.' . ($s['driver'] === 'log' ? ' It was written to storage/logs/mail.log (nothing is sent with MAIL_DRIVER=log).' : ' The mail server has taken the message.') . PHP_EOL;
    if ($s['driver'] !== 'log') echo 'Now look in the inbox of ' . $to . ' (and the spam folder) - that is the real test.' . PHP_EOL;
    exit(0);
}

echo 'NOT SENT after ' . $seconds . 's: ' . Mailer::lastError() . PHP_EOL;
$hint = Mailer::hint(Mailer::lastError());
if ($hint !== '') echo 'What to try: ' . $hint . PHP_EOL;
if (!$verbose && $s['driver'] === 'smtp') echo 'Add --verbose to see the conversation with the server.' . PHP_EOL;
exit(1);

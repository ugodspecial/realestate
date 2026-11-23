<?php
/**
 * Sends the site's e-mail: enquiry alerts to the office and password-reset links to staff.
 *
 * One entry point, Mailer::send(), and three ways out, chosen by MAIL_DRIVER:
 *
 *   mail  PHP's mail(): hands the message to the server's own mail program. No setup where
 *         that exists (most shared hosting) and nothing at all where it does not (a Windows
 *         PC, many VPSs and cloud hosts, hosts that switch mail() off).
 *   smtp  Talks to a mail server you name (MAIL_HOST ...): the mailbox from your hosting
 *         panel, a mail provider, or a local mail catcher. Works from any machine that is
 *         allowed to open a connection to that server.
 *   log   Writes the message to storage/logs/mail.log instead of sending it. For local
 *         development only: with no mail server at all, a password-reset link is still
 *         readable. Refused in production, where that file would hold live reset links.
 *
 * With MAIL_DRIVER unset it is "smtp" when MAIL_HOST is set and "mail" otherwise, so a site
 * that already works through mail() keeps working unchanged.
 *
 * The smtp driver signs in with a password, or - for providers that no longer accept one, Microsoft 365
 * above all - with an OAuth access token (MAIL_OAUTH_PROVIDER, see MailOAuth). Which of the two is
 * decided by the settings, never by trying one and falling back to the other.
 *
 * send() never throws and never lets a PHP warning out: it returns false and lastError() says
 * why in plain words. True means the mail server ACCEPTED the message. Whether it reaches an
 * inbox rather than a spam folder depends on DNS records (SPF, DKIM) that no code can set.
 */
final class Mailer {
    private static string $error = '';
    /** @var list<string> */
    private static array $conversation = [];

    /** Why the last send() returned false; '' after a success. Never contains the SMTP password. */
    public static function lastError(): string { return self::$error; }

    /** @return list<string> The last SMTP conversation, credentials masked (smtp driver only). */
    public static function conversation(): array { return self::$conversation; }

    public static function enabled(): bool {
        return filter_var(config('mail_enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Where enquiry alerts go: the notification address in Website settings, else the public
     * email, else '' (nothing usable). The two are separate settings, and the settings screen
     * saves an emptied field as an empty string - which setting()'s own default does not
     * replace - so "leave the notification address empty to use the public one" has to be
     * decided here, not left to a default argument that never applies.
     */
    public static function officeAddress(): string {
        return self::pickOfficeAddress(setting('notify_email'), setting('email'));
    }

    /** The decision itself, without the database, so it can be checked against every combination. */
    public static function pickOfficeAddress(string $notify, string $public): string {
        foreach ([$notify, $public] as $address) {
            $address = trim($address);
            if ($address !== '' && self::isAddress($address)) return $address;
        }
        return '';
    }

    /**
     * Is this an address that can safely be written into a message header and an SMTP command?
     *
     * PHP's FILTER_VALIDATE_EMAIL says yes to more than that. A quoted local part may hold control characters, so
     *     "a\<CR>\<LF>Bcc:someone@example.net"@example.com
     * passes it, and is a header line of its own in every place it is written without further checks (the From line of
     * a message handed to mail(), the To line in a log file). This keeps the filter and adds what it leaves out: visible
     * ASCII only (no space, no control character), no quote, no backslash, no angle bracket, at most 254 characters.
     * Every ordinary address passes; one that needs a quoted local part does not. Use this wherever an address is
     * accepted that will one day be mailed to or from.
     *
     * Which clause does the work (measured on PHP 8.5): the quote / backslash / bracket rule is the only one that refuses a
     * quoted local part made of plain visible characters ("a@b"@example.com). The filter already refuses a space or a control
     * character outside quotes, and a plain address longer than 254; the first two clauses say so again, so that the rule
     * stays what this comment says if a later PHP changes the filter's pattern. They cost nothing and cannot be tested apart.
     */
    public static function isAddress(string $address): bool {
        return strlen($address) <= 254
            && preg_match('/^[\x21-\x7E]+$/D', $address) === 1
            && preg_match('/["\\\\<>]/', $address) !== 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * The settings in force, with defaults worked out. No secrets: the password is reported
     * only as "is there one".
     *
     * @return array{enabled:bool,driver:string,from:string,host:string,port:int,encryption:string,
     *               username:string,has_password:bool,oauth:string,verify_peer:bool,cafile:string,timeout:int}
     *         oauth is the provider's name when OAuth sign-in is on ("Microsoft 365"), else ''.
     */
    public static function settings(): array {
        // Asked once and kept in $sign: it reads the saved sign-in from disk, and the answer is needed twice below.
        $sign = MailOAuth::enabled() ? MailOAuth::settings() : null;
        $oauth = $sign !== null;
        // With OAuth the provider's own server is the default, so MAIL_HOST can stay empty.
        $host = trim((string) config('mail_host', '')) ?: MailOAuth::defaultHost();
        $driver = strtolower(trim((string) config('mail_driver', '')));
        if ($driver === '') $driver = ($host !== '' || $oauth) ? 'smtp' : 'mail';

        $port = (int) config('mail_port', 0);
        $encryption = match (strtolower(trim((string) config('mail_encryption', '')))) {
            'tls', 'starttls' => 'tls',
            'ssl', 'smtps' => 'ssl',
            'none', 'off', 'false' => 'none',
            default => '',
        };
        // Unset: port 465 means TLS from the first byte, a server on this machine needs no
        // encryption, anything else is expected to offer STARTTLS.
        if ($encryption === '') $encryption = $port === 465 ? 'ssl' : (SmtpClient::isLoopback($host) ? 'none' : 'tls');
        if ($port < 1 || $port > 65535) $port = $encryption === 'ssl' ? 465 : ($encryption === 'none' ? 25 : 587);

        // The mailbox: MAIL_USERNAME, or - for a person who connected their own account - that account.
        $username = trim((string) config('mail_username', '')) ?: ($sign !== null ? $sign['account'] : '');
        return [
            'enabled' => self::enabled(),
            'driver' => $driver,
            // Microsoft 365 only sends as the mailbox itself (or an address it holds Send As rights for), so
            // with OAuth the default sender is the mailbox, not the public email address on the website.
            'from' => trim((string) (config('mail_from') ?: ($oauth && $username !== '' ? $username : setting('email')))),
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'has_password' => (string) config('mail_password', '') !== '',
            'oauth' => $sign !== null ? $sign['label'] : '',
            'verify_peer' => filter_var(config('mail_verify_peer', true), FILTER_VALIDATE_BOOLEAN),
            'cafile' => trim((string) config('mail_cafile', '')),
            'timeout' => max(1, min(60, (int) config('mail_timeout', 8) ?: 8)),
        ];
    }

    /** Things the operator should fix, in plain words. Empty when the settings look usable. */
    public static function problems(): array {
        $s = self::settings();
        $p = [];
        if (!self::isAddress($s['from'])) {
            $p[] = 'There is no valid sender address. Set MAIL_FROM (an address on your own domain), or fill in the public email under Website settings.';
        }
        switch ($s['driver']) {
            case 'smtp':
                if ($s['host'] === '') $p[] = 'MAIL_DRIVER is smtp but MAIL_HOST is empty.';
                $unavailable = SmtpClient::unavailable($s['encryption']);
                if ($unavailable !== '') $p[] = $unavailable;
                if ($s['oauth'] !== '') {
                    array_push($p, ...MailOAuth::problems());
                    $o = MailOAuth::settings();
                    if ($s['username'] === '' && ($o['flow'] === 'app' || $o['connected'])) {
                        $p[] = 'MAIL_USERNAME is empty. With OAuth it is the mailbox the mail is sent from, for example noreply@yourcompany.com.';
                    }
                    if ($s['has_password']) $p[] = 'MAIL_PASSWORD is set but not used: with OAuth no password is sent. Remove it so nobody keeps a password that does nothing.';
                } else {
                    if ($s['username'] !== '' && !$s['has_password']) $p[] = 'MAIL_USERNAME is set but MAIL_PASSWORD is empty.';
                    // Said before it stops working, not after: Microsoft's own schedule (Exchange team, January 2026).
                    if ($s['username'] !== '' && strtolower($s['host']) === MailOAuth::SMTP_HOST) {
                        $p[] = 'Microsoft is switching password sign-in off for Microsoft 365 mail (by default from the end of December 2026), so this setup will stop working unless an administrator switches it back on. Sign in with OAuth instead: MAIL_OAUTH_PROVIDER=microsoft, and see "Microsoft 365" in docs/EMAIL.md.';
                    }
                }
                if ($s['encryption'] !== 'none' && !extension_loaded('openssl')) $p[] = 'The PHP openssl extension is off, so encrypted SMTP cannot work. Enable extension=openssl in php.ini.';
                if ($s['encryption'] === 'none' && ($s['username'] !== '' || $s['oauth'] !== '') && !SmtpClient::isLoopback($s['host'])) $p[] = 'MAIL_ENCRYPTION is none while signing in: the ' . ($s['oauth'] !== '' ? 'access token' : 'password') . ' would cross the network unencrypted.';
                if (!$s['verify_peer'] && !SmtpClient::isLoopback($s['host'])) $p[] = 'MAIL_VERIFY_PEER is off, so the mail server\'s certificate is not checked. Use that only while testing.';
                break;
            case 'mail':
                if ($s['oauth'] !== '') $p[] = 'MAIL_OAUTH_PROVIDER is set, but MAIL_DRIVER is mail, which cannot sign in with OAuth. Use MAIL_DRIVER=smtp.';
                if (!function_exists('mail')) {
                    $p[] = 'PHP\'s mail() is disabled on this server (disable_functions). Use MAIL_DRIVER=smtp.';
                } else {
                    $note = self::windowsMailNote(PHP_OS_FAMILY, (string) ini_get('sendmail_path'), (string) ini_get('SMTP'), (int) ini_get('smtp_port'));
                    if ($note !== '') $p[] = $note;
                }
                break;
            case 'log':
                if (config('app_env') === 'production') $p[] = 'MAIL_DRIVER=log is for local development only and is refused in production, so no email is sent.';
                if ($s['oauth'] !== '') $p[] = 'MAIL_OAUTH_PROVIDER is set, but MAIL_DRIVER is log, which sends nothing. Use MAIL_DRIVER=smtp to send through ' . $s['oauth'] . '.';
                break;
            default:
                $p[] = 'MAIL_DRIVER "' . $s['driver'] . '" is not one of mail, smtp or log.';
        }
        return $p;
    }

    /**
     * Windows only. With no sendmail_path, PHP's mail() speaks SMTP itself to php.ini's SMTP and
     * smtp_port (a fresh PHP, XAMPP or WAMP without a sendmail program). A tool that sets up a mail
     * program instead, such as Laragon's Mail Catcher and Mail Sender, never gets here. Whether
     * anything answers at that address is the one thing that decides if mail() can work, so it is
     * asked (a TCP connect; nothing is sent) rather than assumed: nobody is warned about a setup
     * that works.
     *
     * @param (callable(string,int):bool)|null $probe Replaces the real connection (for tests).
     * @return string The advice when mail() cannot work as set up, otherwise ''.
     */
    public static function windowsMailNote(string $osFamily, string $sendmailPath, string $host, int $port, ?callable $probe = null): string {
        if ($osFamily !== 'Windows' || trim($sendmailPath) !== '') return '';
        $host = trim($host) !== '' ? trim($host) : 'localhost';
        $port = $port > 0 ? $port : 25;
        if ($probe !== null ? $probe($host, $port) : self::listening($host, $port)) return '';
        return 'On Windows PHP\'s mail() sends to the SMTP server named in php.ini (' . $host . ':' . $port . '), and nothing answers there. '
            . 'Switch on Laragon\'s Mail Catcher, or use MAIL_DRIVER=log (each message is written to storage/logs/mail.log), '
            . 'or MAIL_DRIVER=smtp with a mail catcher (MAIL_HOST=127.0.0.1, MAIL_PORT=1025, MAIL_ENCRYPTION=none) or your provider.';
    }

    /** Does anything accept a connection at host:port within a second? Nothing is sent. */
    private static function listening(string $host, int $port): bool {
        set_error_handler(static fn(): bool => true);
        try {
            $connection = stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 1.0);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($connection)) return false;
        fclose($connection);
        return true;
    }

    /** One line saying how mail leaves this site. */
    public static function describe(): string {
        $s = self::settings();
        return match ($s['driver']) {
            'smtp' => 'SMTP server ' . ($s['host'] ?: '(not set)') . ':' . $s['port'] . ' ('
                . ['tls' => 'STARTTLS', 'ssl' => 'TLS', 'none' => 'no encryption'][$s['encryption']]
                . ($s['oauth'] !== ''
                    ? ', ' . $s['oauth'] . ' OAuth' . ($s['username'] !== '' ? ' as ' . $s['username'] : ', mailbox not known yet')
                    : ($s['username'] !== '' ? ', signing in as ' . $s['username'] : ', no sign-in')) . ')',
            'mail' => 'PHP mail() through this server\'s own mail program',
            'log' => 'a log file (storage/logs/mail.log); nothing is actually sent',
            default => 'an unknown method "' . $s['driver'] . '"',
        };
    }

    /** A one-line suggestion for a failure message from send(); '' when there is nothing useful to add. */
    public static function hint(string $error): string {
        // Sign-in by OAuth (and a password meeting a server that wants OAuth) has its own, more exact advice.
        $oauth = MailOAuth::hint($error);
        if ($oauth !== '') return $oauth;
        return match (true) {
            str_contains($error, 'switched off') => 'Set MAIL_ENABLED=true.',
            str_contains($error, 'could not be found') => 'Check MAIL_HOST for typos.',
            str_contains($error, 'connection refused') => 'Nothing is listening on that port. Check MAIL_HOST and MAIL_PORT (587 for STARTTLS, 465 for SSL, 25 only for a server on this machine); for a local mail catcher, start it first.',
            str_contains($error, 'timed out') || str_contains($error, 'did not answer') || str_contains($error, 'too slow') => 'Many hosting plans block outgoing mail ports except to their own mail server. Use the SMTP host from your hosting panel\'s email settings.',
            str_contains($error, 'certificate') => 'Use the host name printed on the server\'s certificate (usually mail.yourdomain.com, not an IP address). On Windows PHP may need a CA bundle: set openssl.cafile in php.ini or MAIL_CAFILE. For local testing only, MAIL_VERIFY_PEER=false skips the check.',
            str_contains($error, 'old version of TLS') => 'Ask whoever runs that mail server (your host, or whoever looks after it) to switch on TLS 1.2 or newer. This site refuses TLS 1.0 and 1.1 on purpose: they are no longer safe to send a password over. Mail providers such as Microsoft 365, Google, Brevo or Mailgun already offer it.',
            str_contains($error, 'does not offer STARTTLS') => 'Try MAIL_ENCRYPTION=ssl with port 465, or MAIL_ENCRYPTION=none for a server on this machine.',
            str_contains($error, 'wants encryption first') => 'Use MAIL_ENCRYPTION=tls with MAIL_PORT=587, or MAIL_ENCRYPTION=ssl with MAIL_PORT=465.',
            str_contains($error, 'Sign-in was refused') => 'Check MAIL_USERNAME and MAIL_PASSWORD. Many hosts want the full email address as the username; Gmail and Outlook need an app password.',
            str_contains($error, 'refused the sender') => 'MAIL_FROM should be an address on the same domain or account as the SMTP sign-in.',
            str_contains($error, 'refused the recipient') => 'Check the address the mail is going to. A server also refuses other domains until you sign in, so set MAIL_USERNAME and MAIL_PASSWORD.',
            str_contains($error, 'mail()') => 'Use MAIL_DRIVER=smtp with the SMTP details from your hosting panel, or MAIL_DRIVER=log while developing.',
            default => '',
        };
    }

    /**
     * @param string|null $replyTo Where a reply should go (the person who wrote in, for an enquiry
     *        alert). Whoever writes it out (the message headers, the log file) uses it only when it
     *        is a plain address; anything else is dropped rather than risk a header, and the
     *        message still goes.
     */
    public static function send(string $to, string $subject, string $body, ?string $replyTo = null): bool {
        self::$error = '';
        self::$conversation = [];
        $s = self::settings();
        if (!$s['enabled']) return self::fail('Email is switched off (MAIL_ENABLED is not true).', false);
        if (!self::isAddress($to)) return self::fail('The recipient address is not valid.');
        if (preg_match('/[\r\n]/', $subject)) return self::fail('The subject contains a line break.');
        if (!self::isAddress($s['from'])) return self::fail('There is no valid sender address (set MAIL_FROM, or the public email in Website settings).');
        $subject = mb_substr($subject, 0, 150);

        $replyTo = (string) $replyTo;
        return match ($s['driver']) {
            'smtp' => self::viaSmtp($s, $to, $subject, $body, $replyTo),
            'mail' => self::viaMail($s, $to, $subject, $body, $replyTo),
            'log' => self::viaLog($s, $to, $subject, $body, $replyTo),
            default => self::fail('MAIL_DRIVER "' . $s['driver'] . '" is not one of mail, smtp or log.'),
        };
    }

    // ------------------------------------------------------------------ after the response

    /** @var list<callable():void> */
    private static array $later = [];
    private static bool $hooked = false;
    /** @var callable|null|false false: look for the server's own function; null or a callable: what a test says it has. */
    private static $finisher = false;
    /** @var callable|null What a test says closes the session; null: the real one. */
    private static $releaser = null;

    /**
     * Run $job once the person who asked has their answer, where this server can answer first and carry on (PHP-FPM
     * and LiteSpeed can: fastcgi_finish_request, litespeed_finish_request; Apache's mod_php, CGI and "php -S" cannot).
     * Where it cannot, $job runs right now, exactly as it always did: nothing is skipped, only made to wait for.
     *
     * Why: a message takes about a dozen round trips to the mail server, so with the server 100 ms away a visitor
     * waits over a second for a thank-you page, and a blocked mail port makes it eight to sixteen seconds. It also
     * made a password-reset request for a real account measurably slower than one for an address nobody has, which
     * tells a stranger who works here. MAIL_DEFER=false switches this off.
     *
     * $job must not write to the session (it is saved and closed first, so the person's next page does not wait for
     * the mail) and should not throw: one that does is logged, never shown, and never stops the jobs after it.
     *
     * @param callable():void $job
     * @return bool true when the job was left for later, false when it has already run
     */
    public static function afterResponse(callable $job): bool {
        $finish = filter_var(config('mail_defer', true), FILTER_VALIDATE_BOOLEAN) ? self::finisher() : null;
        if ($finish === null) {
            self::runJob($job);
            return false;
        }
        self::$later[] = $job;
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'runLater']);
        }
        return true;
    }

    /** What PHP calls when the request is over, and what a test calls to stand in for that. Public for those two. */
    public static function runLater(): void {
        $jobs = self::$later;
        self::$later = [];
        if ($jobs === []) return;
        // In this order. First the session is saved and unlocked: its file stays locked while this request lives, and
        // the browser is about to ask for the next page with the same session, so answering first and unlocking later
        // would only move the wait to that page. Then the answer goes out. Then the work.
        if (self::$releaser !== null) (self::$releaser)();
        elseif (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $finish = self::finisher();
        if ($finish !== null) $finish();
        ignore_user_abort(true);
        foreach ($jobs as $job) self::runJob($job);
    }

    /**
     * For tests: say this server has (a callable) or has not (null) a way to answer first; false goes back to looking. $release
     * stands in for closing the session, so that the ORDER of the three steps can be seen without a real session.
     */
    public static function answerFirstWith(callable|false|null $finish, ?callable $release = null): void {
        self::$finisher = $finish;
        self::$releaser = $release;
    }

    /** @return callable|null The server's way to end the response and keep working, or null when it has none. */
    private static function finisher(): ?callable {
        if (self::$finisher !== false) return self::$finisher;
        foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $name) {
            if (function_exists($name)) return $name;
        }
        return null;
    }

    private static function runJob(callable $job): void {
        try {
            $job();
        } catch (Throwable $e) {
            error_log('A job that waits for the mail server failed: ' . get_class($e) . ': ' . self::tidy($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------ drivers

    private static function viaSmtp(array $s, string $to, string $subject, string $body, string $replyTo): bool {
        if ($s['host'] === '') return self::fail('MAIL_DRIVER is smtp but MAIL_HOST is empty.');
        $message = self::render($s['from'], brand(), $to, $subject, $body, $replyTo);
        $options = [
            'host' => $s['host'], 'port' => $s['port'], 'encryption' => $s['encryption'],
            'username' => $s['username'], 'password' => (string) config('mail_password', ''),
            'timeout' => $s['timeout'], 'verify_peer' => $s['verify_peer'], 'cafile' => $s['cafile'],
            'helo' => self::heloName(),
        ];
        if ($s['oauth'] === '') {
            $client = new SmtpClient($options);
            $sent = $client->send($s['from'], $to, $message);
            self::$conversation = $client->conversation();
            return $sent ? true : self::fail($client->error());
        }

        // OAuth: no password is sent, an access token is. A token is asked for only when the saved one is
        // about to expire, so ordinarily no request to the provider is made at all.
        $net = ['timeout' => $s['timeout'], 'verify_peer' => $s['verify_peer'], 'cafile' => $s['cafile']];
        for ($attempt = 1; ; $attempt++) {
            try {
                $token = MailOAuth::accessToken($net);
            } catch (RuntimeException $e) {
                self::$conversation = [];
                return self::fail($e->getMessage());
            }
            $client = new SmtpClient(['password' => '', 'token' => $token] + $options);
            $sent = $client->send($s['from'], $to, $message);
            self::$conversation = array_merge(MailOAuth::note() !== '' ? [MailOAuth::note()] : [], $client->conversation());
            if ($sent) return true;
            // The saved token can be one the provider has since taken back (a consent withdrawn, a permission
            // changed). When the server refuses a token that came from the file, discard it and try once with a fresh one.
            if ($attempt === 1 && MailOAuth::lastTokenWasSaved() && str_contains($client->error(), 'OAuth sign-in was refused')) {
                MailOAuth::forgetAccessToken();
                continue;
            }
            return self::fail($client->error());
        }
    }

    private static function viaMail(array $s, string $to, string $subject, string $body, string $replyTo): bool {
        if (!function_exists('mail')) return self::fail('PHP\'s mail() is disabled on this server (disable_functions), so use MAIL_DRIVER=smtp.');
        // mail() adds To: and Subject: itself. Folding is spelled with a plain space here: a
        // line break inside the subject argument is not something every PHP version accepts.
        [$headers, $encoded] = self::compose($s['from'], brand(), $to, $subject, $body, false, ' ', $replyTo);
        $windows = PHP_OS_FAMILY === 'Windows';
        // Unix mail programs expect bare LF in what they are piped (and some turn CRLF into
        // CR CR LF); PHP's own SMTP code on Windows expects CRLF.
        if (!$windows) $encoded = str_replace("\r\n", "\n", $encoded);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            // -f sets the envelope sender (the Return-Path). Without it a shared host mails "from"
            // the server's own account name, which fails SPF and is what gets mail sent through
            // mail() filed as spam. Only a plain address is passed on to the shell command line.
            $envelope = (!$windows && self::plainAddress($s['from']) !== '') ? '-f' . $s['from'] : '';
            $ok = mail($to, self::encodeHeader($subject, ' '), $encoded, implode("\r\n", $headers), $envelope);
        } catch (Throwable $e) {
            $ok = false;
            $warnings[] = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if ($ok) return true;
        $detail = $warnings ? ' (' . self::tidy((string) preg_replace('/^mail\(\):\s*/', '', $warnings[0])) . ')' : '';
        return self::fail('PHP\'s mail() could not pass the message to a mail program' . $detail . '.');
    }

    private static function viaLog(array $s, string $to, string $subject, string $body, string $replyTo): bool {
        if (config('app_env') === 'production') {
            return self::fail('MAIL_DRIVER=log is refused in production: it would write live password-reset links to a file.');
        }
        $file = (string) config('mail_log_file', ROOT_PATH . '/storage/logs/mail.log');
        $dir = dirname($file);
        $replyTo = self::plainAddress($replyTo); // written into a file a person reads: no forged lines
        $text = '=== ' . date('c') . " (not sent: MAIL_DRIVER=log) ===\n"
            . 'From: ' . $s['from'] . "\nTo: " . $to . ($replyTo !== '' ? "\nReply-To: " . $replyTo : '') . "\nSubject: " . $subject . "\n\n"
            . str_replace("\r\n", "\n", $body) . "\n\n";
        set_error_handler(static fn(): bool => true);
        try {
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            if (is_file($file) && filesize($file) > 1048576) rename($file, $file . '.1'); // keep it small
            // It will hold live reset links on a machine other people may log in to: private from the first byte, not
            // made private after the first message is in it.
            if (!is_file($file)) {
                touch($file);
                chmod($file, 0600);
            }
            $ok = file_put_contents($file, $text, FILE_APPEND | LOCK_EX) !== false;
        } finally {
            restore_error_handler();
        }
        return $ok ? true : self::fail('Could not write ' . basename($file) . ' (is storage/logs writable?).');
    }

    // ------------------------------------------------------------------ message

    /**
     * The complete message the smtp driver sends: headers, a blank line, the encoded body, every
     * line ended with CRLF. Public so the exact bytes can be checked for any site name and any
     * text, including text a visitor typed.
     */
    public static function render(string $from, string $fromName, string $to, string $subject, string $body, string $replyTo = ''): string {
        [$headers, $encoded] = self::compose($from, $fromName, $to, $subject, $body, true, "\r\n ", $replyTo);
        return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
    }

    /**
     * Headers and the encoded body. Every driver gets the same message, so it reads the same
     * wherever it goes: UTF-8 text, quoted-printable (no line longer than 76 characters, safe
     * through a 7-bit hop, and none of the spam-score penalty base64 text carries), a Date and a
     * Message-ID of its own, and a From name that cannot break the header whatever the
     * website is called.
     *
     * @return array{0:list<string>,1:string}
     */
    private static function compose(string $from, string $fromName, string $to, string $subject, string $body, bool $recipientHeaders, string $fold, string $replyTo = ''): array {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $fromName));
        $replyTo = self::plainAddress($replyTo); // the one place a Reply-To becomes a header, for every driver
        $domain = preg_replace('/[^A-Za-z0-9.\-]/', '', substr((string) strrchr($from, '@'), 1)) ?: 'localhost';
        $headers = ['Date: ' . date('r'), 'From: ' . self::mailbox($name, $from, $fold)];
        if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
        if ($recipientHeaders) {
            $headers[] = 'To: ' . $to;
            $headers[] = 'Subject: ' . self::encodeHeader($subject, $fold);
        }
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $headers[] = 'X-Mailer: CedarTranquility';
        $body = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $body);
        $encoded = quoted_printable_encode($body);
        // A line that begins with "." ends the message early on any transport that does not
        // escape it, and PHP's own SMTP code on Windows (what mail() uses under Laragon, XAMPP
        // and WAMP) is documented as not doing so. Spelled =2E it reads the same in every mail
        // program and can never be taken for the end of the message.
        $encoded = (string) preg_replace('/^\./m', '=2E', $encoded);
        if (!str_ends_with($encoded, "\r\n")) $encoded .= "\r\n";
        return [$headers, $encoded];
    }

    /** "Name" <address>, safe whatever Name holds: quoted when plain, RFC 2047 words when not. */
    private static function mailbox(string $name, string $address, string $fold): string {
        if ($name === '') return $address;
        if (preg_match('/^[\x20-\x7E]+$/', $name)) return '"' . addcslashes($name, '"\\') . '" <' . $address . '>';
        return self::encodeHeader($name, $fold) . ' <' . $address . '>';
    }

    /**
     * Header text as ASCII. Plain text passes through; anything else becomes RFC 2047 encoded
     * words of at most 75 characters (45 bytes each, cut between characters), which is the
     * limit a strict mail server holds a header to.
     */
    private static function encodeHeader(string $text, string $fold): string {
        if (preg_match('/^[\x20-\x7E]*$/', $text)) return $text;
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text) as $char) {
            if ($chunk !== '' && strlen($chunk . $char) > 45) { $words[] = $chunk; $chunk = ''; }
            $chunk .= $char;
        }
        if ($chunk !== '') $words[] = $chunk;
        return implode($fold, array_map(static fn(string $w): string => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
    }

    private static function heloName(): string {
        $host = (string) parse_url((string) config('app_url', ''), PHP_URL_HOST);
        if (!str_contains($host, '.')) {
            $local = (string) gethostname();
            if (str_contains($local, '.')) return $local;
        }
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * The address if it is the ordinary kind (letters, digits and . _ % + - around one @), else ''.
     * FILTER_VALIDATE_EMAIL also accepts quoted local parts with spaces and quotes, which are
     * legal but are exactly what has been abused to smuggle options into a mail program's command
     * line and extra fields into a header; an address that reaches either place is held to this.
     */
    private static function plainAddress(string $address): string {
        $address = trim($address);
        return strlen($address) <= 254 && preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?\.[A-Za-z]{2,}$/D', $address) ? $address : '';
    }

    // ------------------------------------------------------------------ failure

    private static function tidy(string $text): string {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text)));
        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
    }

    /** Record why, and leave one plain line in the error log (not for "switched off": that is a choice). */
    private static function fail(string $reason, bool $log = true): bool {
        self::$error = self::tidy($reason);
        if ($log) error_log('Mail not sent (' . (self::settings()['driver']) . '): ' . self::$error);
        return false;
    }
}

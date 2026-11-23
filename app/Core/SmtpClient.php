<?php
/**
 * A deliberately small SMTP client: it hands ONE message to ONE mail server and, when it
 * cannot, says in plain words why. It exists because PHP's mail() only works where the
 * machine already has a configured mail program - which a Windows development PC, a VPS
 * and some hosting plans do not. Talking SMTP directly works anywhere the server may open a
 * connection to a mail server.
 *
 * What it does: connect (plain, or TLS from the first byte), EHLO, upgrade with STARTTLS when
 * asked, sign in (AUTH PLAIN, else LOGIN; or AUTH XOAUTH2 with an OAuth access token, which is how
 * Microsoft 365 wants it - see MailOAuth), MAIL FROM, RCPT TO, DATA, QUIT.
 *
 * What it will not do, on purpose:
 *   - send a password over a connection it was told to encrypt but could not (no silent
 *     downgrade: if STARTTLS is required and not offered, it stops);
 *   - skip certificate checks unless the operator turned them off (MAIL_VERIFY_PEER=false);
 *   - queue, retry or send to more than one recipient - a failure is reported to the caller;
 *   - put the password, or an OAuth access token, in an error message or in the conversation it keeps;
 *   - fall back to a password when it was given a token (and the other way round): which one it signs in
 *     with is decided by the caller, never guessed.
 *
 * Nothing here throws to the caller and no PHP warning escapes: a refused connection or a bad
 * certificate would otherwise land in php-error.log as a "PHP Warning" for every attempt.
 */
final class SmtpClient {
    // RFC 5321 allows a reply line 512 bytes; this is four times that. (Not larger: a longer
    // fgets() buffer crashes the WebAssembly PHP build used for local verification.)
    private const MAX_LINE = 2048;

    /** @var resource|null */
    private $socket = null;
    private string $error = '';
    /** @var list<string> What was said, with credentials masked (bin/mail-test.php --verbose). */
    private array $conversation = [];
    /** @var list<string> PHP warnings raised by the call being guarded. */
    private array $warnings = [];
    /** @var array<string,string> Capabilities from the last EHLO. */
    private array $capabilities = [];
    private float $deadline = 0.0;
    /** Seconds the current read or write was told it may take. */
    private int $armedFor = 1;

    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    /** An OAuth access token. When set, the client signs in with XOAUTH2 instead of a password. */
    private string $token;
    /** @var list<string> Further text that must never be echoed (what an OAuth sign-in sent). */
    private array $hidden = [];
    private int $timeout;
    private bool $verifyPeer;
    private string $cafile;
    private string $helo;

    /**
     * @param array{host:string,port?:int,encryption?:string,username?:string,password?:string,token?:string,
     *              timeout?:int,verify_peer?:bool,cafile?:string,helo?:string} $options
     *        encryption: 'tls' = connect, then STARTTLS (port 587); 'ssl' = TLS from the first
     *        byte (port 465); 'none' = no encryption (a local mail catcher or a trusted relay).
     *        token: an OAuth access token; with it, username is the mailbox and no password is used.
     */
    public function __construct(array $options) {
        $this->host = (string) ($options['host'] ?? '');
        $this->encryption = (string) ($options['encryption'] ?? 'tls');
        $this->port = (int) ($options['port'] ?? 0) ?: ($this->encryption === 'ssl' ? 465 : ($this->encryption === 'none' ? 25 : 587));
        $this->username = (string) ($options['username'] ?? '');
        $this->password = (string) ($options['password'] ?? '');
        $this->token = (string) ($options['token'] ?? '');
        $this->timeout = max(1, min(60, (int) ($options['timeout'] ?? 8)));
        $this->verifyPeer = (bool) ($options['verify_peer'] ?? true);
        $this->cafile = (string) ($options['cafile'] ?? '');
        $this->helo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($options['helo'] ?? '')) ?: 'localhost';
    }

    /**
     * The PHP functions sending through SMTP needs, and whether this server has left them enabled.
     * Some hosts list network functions in disable_functions; without this the failure would read
     * "Call to undefined function", which tells nobody what to do.
     *
     * @param (callable(string):bool)|null $exists Replaces function_exists (for tests).
     * @return string What is missing and what to do, or '' when everything needed is there.
     */
    public static function unavailable(string $encryption, ?callable $exists = null): string {
        $exists ??= 'function_exists';
        $needed = ['stream_socket_client', 'stream_set_timeout', 'stream_set_blocking', 'stream_get_meta_data'];
        if ($encryption === 'tls') $needed[] = 'stream_socket_enable_crypto';
        $missing = array_values(array_filter($needed, static fn(string $function): bool => !$exists($function)));
        if ($missing === []) return '';
        $many = count($missing) > 1;
        return 'This server has switched off the PHP function' . ($many ? 's ' : ' ') . implode(', ', $missing)
            . ' (disable_functions), which sending through SMTP needs. Ask your host to allow ' . ($many ? 'them' : 'it') . ', or use MAIL_DRIVER=mail.';
    }

    /** Why the last send() failed; '' after a success. Safe to show: printable ASCII, no password. */
    public function error(): string { return $this->error; }

    /** @return list<string> The conversation so far, client lines prefixed "C:", server lines "S:". */
    public function conversation(): array { return $this->conversation; }

    /**
     * @param string $message Complete message, headers then body, lines ended with CRLF.
     */
    public function send(string $from, string $recipient, string $message): bool {
        $this->error = '';
        $this->conversation = [];
        $this->capabilities = [];
        $this->deadline = microtime(true) + $this->timeout * 2;
        try {
            foreach ([$from, $recipient] as $address) {
                if ($address === '' || preg_match('/[<>\r\n\0]/', $address)) $this->fail('An e-mail address in the envelope is not usable.');
            }
            if ($this->host === '') $this->fail('No SMTP host is configured (MAIL_HOST).');
            $unavailable = self::unavailable($this->encryption);
            if ($unavailable !== '') $this->fail($unavailable);
            if ($this->encryption !== 'none' && !extension_loaded('openssl')) {
                $this->fail('Encrypted SMTP needs the PHP openssl extension, which is not enabled (enable extension=openssl in php.ini).');
            }
            $this->connect();
            [$code, $text] = $this->reply();
            if ($code !== 220) $this->fail('The mail server did not welcome us: ' . $this->say($code, $text));

            $this->greet();
            if ($this->encryption === 'tls') {
                if (!isset($this->capabilities['STARTTLS'])) {
                    $this->fail('The server does not offer STARTTLS, so the connection cannot be encrypted. Use MAIL_ENCRYPTION=ssl (port 465) if the server expects TLS from the start, or none only for a local or trusted server.');
                }
                $this->command('STARTTLS', [220], 'STARTTLS', 'The server refused to start encryption');
                $upgraded = $this->guard(fn() => stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT));
                if ($upgraded !== true) $this->fail('Could not switch to an encrypted connection: ' . $this->why());
                $this->conversation[] = '-- connection is now encrypted --';
                $this->greet();
            }
            if ($this->username !== '' || $this->token !== '') $this->authenticate();

            $limit = (int) ($this->capabilities['SIZE'] ?? 0);
            if ($limit > 0 && strlen($message) > $limit) $this->fail('The message is larger than the server accepts (' . $limit . ' bytes).');

            $this->command('MAIL FROM:<' . $from . '>', [250], null, 'The server refused the sender address ' . $from . ' (MAIL_FROM should be an address on the account you sign in with)');
            $this->command('RCPT TO:<' . $recipient . '>', [250, 251], null, 'The server refused the recipient address');
            $this->command('DATA', [354], null, 'The server would not take the message');
            // Dot-stuffing: a line that starts with "." would otherwise end the message early.
            $data = preg_replace('/^\./m', '..', $message) ?? $message;
            if (!str_ends_with($data, "\r\n")) $data .= "\r\n";
            $this->write($data . ".\r\n");
            $this->conversation[] = 'C: (message, ' . strlen($message) . ' bytes)';
            [$code, $text] = $this->reply();
            if ($code !== 250) $this->fail('The server did not accept the message: ' . $this->say($code, $text));

            $this->quit();
            return true;
        } catch (Throwable $e) {
            $this->error = $this->scrub($e->getMessage());
            $this->quit();
            return false;
        }
    }

    private function connect(): void {
        $host = $this->host;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']'; // IPv6 literal
        $target = ($this->encryption === 'ssl' ? 'ssl' : 'tcp') . '://' . $host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        // A closure with references, not an arrow function: the error code and text come back through them.
        $socket = $this->guard(function () use ($target, &$errno, &$errstr) {
            return stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $this->context());
        });
        if (!is_resource($socket)) {
            $this->fail('Could not connect to ' . $this->host . ':' . $this->port . ' - ' . $this->why($errstr));
        }
        $this->socket = $socket;
        stream_set_blocking($this->socket, true);
        $this->conversation[] = '-- connected to ' . $this->host . ':' . $this->port . ($this->encryption === 'ssl' ? ' (TLS)' : '') . ' --';
    }

    private function context() {
        return self::tlsContext($this->host, $this->verifyPeer, $this->cafile);
    }

    /**
     * The TLS settings for a connection to $host. One place, used by this client and by HttpClient (the
     * OAuth token request), so "check the certificate" and "use this CA bundle" mean the same thing for both.
     *
     * @return resource
     */
    public static function tlsContext(string $host, bool $verifyPeer, string $cafile = '') {
        $ssl = ['peer_name' => trim($host, '[]'), 'SNI_enabled' => true];
        if ($verifyPeer) {
            $ssl += ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
            if ($cafile !== '') $ssl['cafile'] = $cafile;
        } else {
            $ssl += ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
        }
        return stream_context_create(['ssl' => $ssl]);
    }

    /** EHLO, falling back to HELO for a server too old to know it. Fills $this->capabilities. */
    private function greet(): void {
        $this->capabilities = [];
        $this->write('EHLO ' . $this->helo . "\r\n");
        $this->conversation[] = 'C: EHLO ' . $this->helo;
        [$code, $text] = $this->reply();
        if ($code !== 250) {
            $this->command('HELO ' . $this->helo, [250], null, 'The server did not accept our greeting');
            return;
        }
        $lines = explode("\n", $text);
        array_shift($lines); // the first line is the server's own greeting, not a capability
        foreach ($lines as $line) {
            $line = strtoupper(trim($line));
            if ($line === '') continue;
            $parts = preg_split('/[ =]/', $line, 2) ?: [$line];
            $this->capabilities[$parts[0]] = $parts[1] ?? '';
        }
    }

    private function authenticate(): void {
        $offered = preg_split('/\s+/', $this->capabilities['AUTH'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($offered === []) {
            $this->fail('The server does not offer to sign in on this connection. If it wants encryption first, use MAIL_ENCRYPTION=tls (port 587) or ssl (port 465).');
        }
        if ($this->token !== '') {
            $this->authenticateWithToken($offered);
            return;
        }
        $refused = 'Sign-in was refused (check MAIL_USERNAME and MAIL_PASSWORD)';
        if (in_array('PLAIN', $offered, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], 'AUTH PLAIN ***', $refused);
        } elseif (in_array('LOGIN', $offered, true)) {
            $this->command('AUTH LOGIN', [334], null, $refused);
            $this->command(base64_encode($this->username), [334], '***', $refused);
            $this->command(base64_encode($this->password), [235], '***', $refused);
        } elseif (in_array('XOAUTH2', $offered, true)) {
            $this->fail('The server only accepts OAuth sign-in (' . implode(', ', $offered) . '), not a password.');
        } else {
            $this->fail('The server only offers sign-in methods this site does not support (' . implode(', ', $offered) . ').');
        }
    }

    /**
     * The SASL XOAUTH2 initial response: base64 of  user=<mailbox> ^A auth=Bearer <token> ^A ^A  (^A is
     * the byte 0x01). Public so the exact bytes can be checked against the examples in Microsoft's and
     * Google's documentation, which print the same string for the same inputs.
     */
    public static function xoauth2(string $mailbox, string $token): string {
        return base64_encode('user=' . $mailbox . "\x01auth=Bearer " . $token . "\x01\x01");
    }

    /**
     * Sign in with an OAuth access token (AUTH XOAUTH2), the way Microsoft 365 and Gmail accept it.
     * The token goes in the same line as the command. When it is refused, the server answers 334 with a
     * base64 JSON explanation; the protocol then wants an EMPTY line from us, after which the real
     * failure code arrives. Without that empty line some servers wait for us until they time out.
     *
     * @param list<string> $offered The mechanisms the server listed.
     */
    private function authenticateWithToken(array $offered): void {
        if ($this->username === '') $this->fail('OAuth sign-in needs the mailbox address (MAIL_USERNAME).');
        if (!in_array('XOAUTH2', $offered, true)) {
            $this->fail('The server does not offer OAuth sign-in (XOAUTH2); it offers ' . implode(', ', $offered) . '.');
        }
        $initial = self::xoauth2($this->username, $this->token);
        $this->hidden = [$initial];
        $this->write('AUTH XOAUTH2 ' . $initial . "\r\n");
        $this->conversation[] = 'C: AUTH XOAUTH2 ***';
        [$code, $text] = $this->reply();
        if ($code === 334) {
            $explanation = base64_decode(trim($text), true);
            if ($explanation !== false && $explanation !== '') $this->conversation[] = 'S: (the server\'s explanation, decoded) ' . $this->scrub($explanation);
            $this->write("\r\n");
            $this->conversation[] = 'C: (an empty line, as the protocol asks after a sign-in error)';
            [$code, $text] = $this->reply();
        }
        if ($code !== 235) $this->fail('OAuth sign-in was refused: ' . $this->say($code, $text));
    }

    /**
     * Send one command and require one of $accept as the answer.
     * @param list<int> $accept
     */
    private function command(string $line, array $accept, ?string $logAs = null, string $onRefusal = 'The server refused a command'): void {
        $this->write($line . "\r\n");
        $this->conversation[] = 'C: ' . ($logAs ?? $line);
        [$code, $text] = $this->reply();
        if (!in_array($code, $accept, true)) $this->fail($onRefusal . ': ' . $this->say($code, $text));
    }

    /** @return array{0:int,1:string} Reply code and the text of all its lines. */
    private function reply(): array {
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $this->arm();
            $began = microtime(true);
            $line = $this->guard(fn() => fgets($this->socket, self::MAX_LINE));
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                // A timeout is a read that waited as long as it was allowed to. Some PHP builds raise the
                // timed_out flag on a connection that was simply closed, so the flag alone is not believed.
                $waitedFully = microtime(true) - $began >= $this->armedFor - 0.25;
                $this->fail(!empty($meta['timed_out']) && $waitedFully
                    ? 'The mail server did not answer within ' . $this->timeout . ' seconds.'
                    : 'The mail server closed the connection unexpectedly.');
            }
            $line = rtrim((string) $line, "\r\n");
            $this->conversation[] = 'S: ' . $this->scrub($line); // what a server says is untrusted, and may echo what it was sent
            if (!preg_match('/^(\d{3})([ -]|$)(.*)$/s', $line, $m)) {
                $this->fail('That does not look like a mail server (it said: "' . $this->scrub($line) . '").');
            }
            $lines[] = $m[3];
            if ($m[2] !== '-') return [(int) $m[1], implode("\n", $lines)];
        }
        $this->fail('The mail server sent an endless reply.');
    }

    private function write(string $data): void {
        $length = strlen($data);
        for ($sent = 0; $sent < $length;) {
            $this->arm();
            $written = $this->guard(fn() => fwrite($this->socket, substr($data, $sent, 8192)));
            if ($written === false || $written === 0) $this->fail('The mail server closed the connection unexpectedly.');
            $sent += $written;
        }
    }

    /** Give the next read or write whatever is left of the overall budget, up to the per-step timeout. */
    private function arm(): void {
        $left = $this->deadline - microtime(true);
        if ($left <= 0) $this->fail('The mail server was too slow (more than ' . ($this->timeout * 2) . ' seconds in all).');
        $this->armedFor = max(1, (int) min($this->timeout, ceil($left)));
        stream_set_timeout($this->socket, $this->armedFor);
    }

    private function quit(): void {
        if (!is_resource($this->socket)) return;
        $this->guard(function () {
            fwrite($this->socket, "QUIT\r\n");
            fclose($this->socket);
        });
        $this->socket = null;
    }

    /**
     * Run $fn with PHP warnings captured instead of logged. A refused connection, a failed
     * handshake or a dropped socket each raise one, and the log should hold our one clear
     * line, not "PHP Warning" noise.
     */
    private function guard(callable $fn): mixed {
        $this->warnings = [];
        set_error_handler(function (int $severity, string $message): bool {
            $this->warnings[] = $message;
            return true;
        });
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** A short, human reason for a connection or TLS failure. */
    private function why(string $errstr = ''): string {
        return $this->scrub(self::failureReason($errstr, $this->warnings));
    }

    /**
     * What PHP said about a failed connection or handshake (its own error text plus the warnings raised
     * meanwhile), as one plain sentence with PHP's wording kept in brackets. Static so HttpClient words
     * its failures exactly as this client does.
     *
     * @param list<string> $warnings
     */
    public static function failureReason(string $errstr, array $warnings, string $hostAdvice = 'check MAIL_HOST for typos'): string {
        $raw = [];
        if ($errstr !== '') $raw[] = $errstr;
        foreach ($warnings as $warning) {
            // "SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed"
            if (preg_match('/error:[0-9A-Fa-f]+:[^:\n]*:[^:\n]*:([^\n]+)/', $warning, $m)) { $raw[] = trim($m[1]); continue; }
            $raw[] = (string) preg_replace('/^[a-z_]+\(\):\s*/i', '', $warning);
        }
        $raw = array_values(array_unique(array_filter($raw, static fn($r) => $r !== '' && !preg_match('/^(Failed to enable crypto|Unable to connect to)/i', $r))));
        return self::explain(implode('; ', $raw), $hostAdvice);
    }

    /**
     * A connection or TLS failure in plain words, with PHP's own wording kept in brackets for
     * whoever has to search for it. Pure, so it can be checked against what each operating
     * system really says (Linux and Windows word the same failures differently).
     */
    public static function explain(string $raw, string $hostAdvice = 'check MAIL_HOST for typos'): string {
        $plain = match (true) {
            (bool) preg_match('/getaddrinfo|name or service not known|no such host|nodename nor servname|temporary failure in name resolution/i', $raw) => 'that host name could not be found (' . $hostAdvice . ')',
            (bool) preg_match('/refused/i', $raw) => 'nothing is listening there (connection refused)',
            (bool) preg_match('/timed out|timeout|did not properly respond/i', $raw) => 'the connection timed out (a firewall, or the hosting plan, may block this port)',
            (bool) preg_match('/certificate|self[- ]signed/i', $raw) => 'its TLS certificate could not be verified',
            default => '',
        };
        $raw = $raw === '' ? 'no further detail from PHP' : $raw;
        return $plain === '' ? $raw : $plain . ' [' . $raw . ']';
    }

    private function say(int $code, string $text): string {
        return $this->scrub($code . ' ' . $text);
    }

    /**
     * Hide a secret in text that came from another machine. Wherever the text shows it, it is replaced with ***:
     * as it was sent, in base64, and URL-encoded (the three ways a careless server or proxy echoes a request back).
     * A secret that is only partly there still counts - a long reply is read in pieces, and a piece can end in the
     * middle of a token - so what is hidden is the longest start of the secret that the text shows.
     */
    public static function maskSecret(string $text, string $secret): string {
        if ($secret === '') return $text;
        // Find every place any form shows, then replace once: replacing form by form would let one form's
        // start eat the start of another (a ~ is the same in two forms and different in a third) and leave a tail.
        $spans = [];
        foreach (array_unique([$secret, base64_encode($secret), rawurlencode($secret), urlencode($secret)]) as $form) {
            $head = substr($form, 0, 12);
            for ($from = 0; ($at = strpos($text, $head, $from)) !== false; $from = $at + 1) {
                $same = strlen($head);
                $limit = min(strlen($form), strlen($text) - $at);
                while ($same < $limit && $text[$at + $same] === $form[$same]) $same++;
                $spans[] = [$at, $at + $same];
            }
        }
        if ($spans === []) return $text;
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($spans as [$a, $b]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $a <= $merged[$last][1]) $merged[$last][1] = max($merged[$last][1], $b);
            else $merged[] = [$a, $b];
        }
        $out = '';
        $pos = 0;
        foreach ($merged as [$a, $b]) {
            $out .= substr($text, $pos, $a - $pos) . '***';
            $pos = $b;
        }
        return $out . substr($text, $pos);
    }

    /** Printable ASCII only (server text is untrusted), no password or access token, bounded length. */
    private function scrub(string $text): string {
        if (strlen($this->password) >= 4) $text = self::maskSecret($text, $this->password);
        // An access token is long and unmistakable; a server that echoes a failed sign-in back would
        // otherwise put it, or the string it travelled in, on the settings page and in the logs.
        foreach (array_filter([$this->token, ...$this->hidden], static fn(string $secret): bool => strlen($secret) >= 8) as $secret) {
            $text = self::maskSecret($text, $secret);
        }
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text)));
        return strlen($text) > 300 ? substr($text, 0, 297) . '...' : $text;
    }

    private function fail(string $message): never {
        throw new RuntimeException($message);
    }
}

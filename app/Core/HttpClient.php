<?php
/**
 * The one kind of web request this site makes: a form POST to an OAuth sign-in service, to get the
 * access token a mail server wants instead of a password (see MailOAuth).
 *
 * It is written on PHP's plain socket functions rather than curl for the same reason SmtpClient is: a
 * site that can send mail over SMTP already has everything this needs (stream_socket_client and
 * openssl), whereas curl, or allow_url_fopen, is one more thing a hosting plan may have switched off.
 *
 * What it will not do, on purpose:
 *   - send a form (it holds a client secret or a refresh token) without encryption to anything but
 *     this machine;
 *   - skip certificate checks unless the operator turned them off (the same MAIL_VERIFY_PEER switch);
 *   - follow a redirect: a token service does not redirect, and a redirect could carry the form elsewhere;
 *   - read more than a few hundred kilobytes of answer, or wait longer than twice the timeout in all;
 *   - put anything it was sent into an error message, or let a PHP warning escape.
 *
 * Nothing in the form is ever logged. Failures throw RuntimeException with a message that is safe to show.
 */
final class HttpClient {
    // A token answer is a few kilobytes. Anything much larger is not one.
    private const MAX_BODY = 262144;
    // (Not larger: a longer fgets() buffer crashes the WebAssembly PHP build used for local verification.)
    private const MAX_LINE = 2048;
    // A header line longer than MAX_LINE (a long cookie, say) is skipped in pieces of that size, up to this many.
    private const MAX_SKIPPED_PIECES = 64;

    /** @var resource|null */
    private $socket = null;
    private float $deadline = 0.0;
    private int $armedFor = 1;
    /** @var list<string> PHP warnings raised by the call being guarded. */
    private array $warnings = [];

    private function __construct(private readonly int $timeout, private readonly bool $verifyPeer, private readonly string $cafile) {}

    /**
     * @param array<string,string> $fields The form. Sent as application/x-www-form-urlencoded.
     * @param array{timeout?:int,verify_peer?:bool,cafile?:string} $options
     * @return array{status:int,body:string,headers:array<string,string>} Whatever the server answered,
     *         including 4xx and 5xx: an OAuth server reports its refusals with those.
     * @throws RuntimeException When no answer could be had; the message says why in plain words.
     */
    public static function postForm(string $url, array $fields, array $options = []): array {
        $client = new self(
            max(1, min(60, (int) ($options['timeout'] ?? 8))),
            (bool) ($options['verify_peer'] ?? true),
            (string) ($options['cafile'] ?? ''),
        );
        try {
            return $client->post($url, $fields);
        } finally {
            $client->close();
        }
    }

    /** @param array<string,string> $fields */
    private function post(string $url, array $fields): array {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) $path .= '?' . $parts['query'];
        // The host goes into the Host header and onto the connection: letters, digits, dots and hyphens (or a bracketed IPv6
        // address) and nothing else, so no value can add a header line or name a different machine than it appears to.
        if (!in_array($scheme, ['https', 'http'], true) || !preg_match('/^(?:[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])$/D', $host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !preg_match('/^[\x21-\x7E]+$/D', $path)) {
            throw new RuntimeException('The sign-in address is not usable: it should look like https://login.microsoftonline.com.');
        }
        if ($scheme === 'http' && !SmtpClient::isLoopback($host)) {
            throw new RuntimeException('Refusing to send sign-in details to ' . $this->show($host) . ' without encryption (the address must start with https://).');
        }
        $unavailable = SmtpClient::unavailable('ssl');
        if ($unavailable !== '') throw new RuntimeException($unavailable);
        if ($scheme === 'https' && !extension_loaded('openssl')) {
            throw new RuntimeException('Encrypted connections need the PHP openssl extension, which is not enabled (enable extension=openssl in php.ini).');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $hostHeader = $host . (isset($parts['port']) && $port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '');
        $this->deadline = microtime(true) + $this->timeout * 2;

        $errno = 0;
        $errstr = '';
        $target = ($scheme === 'https' ? 'ssl' : 'tcp') . '://' . $host . ':' . $port;
        $context = $scheme === 'https' ? SmtpClient::tlsContext($host, $this->verifyPeer, $this->cafile) : stream_context_create();
        $socket = $this->guard(function () use ($target, &$errno, &$errstr, $context) {
            return stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        });
        if (!is_resource($socket)) {
            throw new RuntimeException('Could not connect to ' . $this->show($host) . ':' . $port . ' - '
                . $this->show(SmtpClient::failureReason($errstr, $this->warnings, 'this server may have no internet access, or its DNS is not working')));
        }
        $this->socket = $socket;
        stream_set_blocking($this->socket, true);

        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $this->write('POST ' . $path . " HTTP/1.1\r\nHost: " . $hostHeader . "\r\nUser-Agent: CedarTranquility\r\nAccept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);

        $statusLine = $this->line();
        if (!preg_match('#^HTTP/1\.[01] (\d{3})\b#', $statusLine, $m)) {
            throw new RuntimeException('That does not look like a web server (it said: "' . $this->show($statusLine) . '").');
        }
        $headers = [];
        for ($i = 0; ; $i++) {
            if ($i > 100) throw new RuntimeException('The web server sent far too many header lines.');
            $line = $this->line(true);
            if ($line === '') break;
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
        }
        return ['status' => (int) $m[1], 'body' => $this->body($headers), 'headers' => $headers];
    }

    /** @param array<string,string> $headers */
    private function body(array $headers): string {
        if (stripos($headers['transfer-encoding'] ?? '', 'chunked') !== false) {
            $body = '';
            while (true) {
                $sizeLine = trim(explode(';', $this->line(), 2)[0]);
                if ($sizeLine === '' || !ctype_xdigit($sizeLine) || strlen($sizeLine) > 8) throw new RuntimeException('The web server\'s answer was not in a form this site can read.');
                $size = (int) hexdec($sizeLine);
                if ($size === 0) {
                    for ($i = 0; $i < 50 && $this->line(true) !== ''; $i++); // trailers, if any, up to the blank line
                    return $body;
                }
                if (strlen($body) + $size > self::MAX_BODY) throw new RuntimeException('The web server\'s answer was far larger than a sign-in answer should be.');
                $body .= $this->bytes($size);
                $this->line(); // the line break that ends each chunk
            }
        }
        if (isset($headers['content-length'])) {
            if (!ctype_digit($headers['content-length'])) throw new RuntimeException('The web server\'s answer was not in a form this site can read.');
            $length = (int) $headers['content-length'];
            if ($length > self::MAX_BODY) throw new RuntimeException('The web server\'s answer was far larger than a sign-in answer should be.');
            return $this->bytes($length);
        }
        // Neither: the answer runs until the server closes the connection.
        $body = '';
        while (true) {
            $this->arm();
            $began = microtime(true);
            $chunk = $this->guard(fn() => fread($this->socket, 8192));
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) return $body;
                $this->failRead($began);
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_BODY) throw new RuntimeException('The web server\'s answer was far larger than a sign-in answer should be.');
        }
    }

    /** Exactly $length bytes, or an exception. */
    private function bytes(int $length): string {
        $data = '';
        while (strlen($data) < $length) {
            $this->arm();
            $began = microtime(true);
            $chunk = $this->guard(fn() => fread($this->socket, min(8192, $length - strlen($data))));
            if ($chunk === false || $chunk === '') $this->failRead($began);
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * One line, without its line break. Only a header line may be longer than MAX_LINE: real servers send long
     * cookies, and nothing this site needs is in one, so the start is kept and the rest is read and dropped.
     * The status line and a chunk size are never that long.
     */
    private function line(bool $header = false): string {
        $this->arm();
        $began = microtime(true);
        $line = $this->guard(fn() => fgets($this->socket, self::MAX_LINE));
        if ($line === false) $this->failRead($began);
        $line = (string) $line;
        if (strlen($line) >= self::MAX_LINE - 1 && !str_ends_with($line, "\n")) {
            if (!$header) throw new RuntimeException('The web server sent a line far longer than any sign-in answer has.');
            for ($pieces = 0; ; $pieces++) {
                if ($pieces >= self::MAX_SKIPPED_PIECES) throw new RuntimeException('The web server sent a header line far longer than any sign-in answer has.');
                $this->arm();
                $began = microtime(true);
                $more = $this->guard(fn() => fgets($this->socket, self::MAX_LINE));
                if ($more === false) $this->failRead($began);
                if (str_ends_with((string) $more, "\n")) break;
            }
        }
        return rtrim($line, "\r\n");
    }

    private function failRead(float $began): never {
        $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];
        // A timeout is a read that waited as long as it was allowed to. Some PHP builds raise the timed_out
        // flag on a connection that was simply closed, so the flag alone is not believed.
        $waitedFully = microtime(true) - $began >= $this->armedFor - 0.25;
        throw new RuntimeException(!empty($meta['timed_out']) && $waitedFully
            ? 'The sign-in service did not answer within ' . $this->timeout . ' seconds.'
            : 'The sign-in service closed the connection before it had finished answering.');
    }

    private function write(string $data): void {
        $length = strlen($data);
        for ($sent = 0; $sent < $length;) {
            $this->arm();
            $written = $this->guard(fn() => fwrite($this->socket, substr($data, $sent, 8192)));
            if ($written === false || $written === 0) throw new RuntimeException('The sign-in service closed the connection before it had been asked anything.');
            $sent += $written;
        }
    }

    /** Give the next read or write whatever is left of the overall budget, up to the per-step timeout. */
    private function arm(): void {
        $left = $this->deadline - microtime(true);
        if ($left <= 0) throw new RuntimeException('The sign-in service was too slow (more than ' . ($this->timeout * 2) . ' seconds in all).');
        $this->armedFor = max(1, (int) min($this->timeout, ceil($left)));
        stream_set_timeout($this->socket, $this->armedFor);
    }

    private function close(): void {
        if (is_resource($this->socket)) $this->guard(fn() => fclose($this->socket));
        $this->socket = null;
    }

    /** Run $fn with PHP warnings captured rather than logged: a reset or refused connection raises one each. */
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

    /** Printable ASCII only (what a server says is untrusted), bounded. */
    private function show(string $text): string {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text)));
        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
    }
}

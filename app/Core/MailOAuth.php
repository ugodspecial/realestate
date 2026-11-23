<?php
/**
 * Signing in to the mail server with OAuth 2.0 instead of a password.
 *
 * Why this exists: Microsoft 365 is switching password sign-in for SMTP off (for existing tenants,
 * "disabled by default" from the end of December 2026; new tenants never had it), and a mailbox there
 * can then only be used by presenting an OAuth access token over the normal SMTP connection
 * (SASL XOAUTH2, which SmtpClient speaks). This class gets that token.
 *
 * Two ways to get one, chosen by MAIL_OAUTH_FLOW:
 *
 *   app   The website signs in as itself ("client credentials"): an app registered in Microsoft
 *         Entra, its client secret, and an Exchange service principal that was given access to the
 *         mailbox. Nobody signs in; nothing expires except the secret (24 months at most when it is
 *         made in the portal). Set up by a tenant administrator.
 *   user  A person signs in once and the website keeps the refresh token ("device code" sign-in, from
 *         Settings -> Connect). Needs no client secret and no PowerShell, but the connection can lapse
 *         (a long time unused, a password change) and is then made again with one button.
 *
 * Tokens last about an hour. They are kept in a small file (storage/mail-oauth.json, mode 0600, outside
 * the web root and refused by .htaccess) so that most e-mails need no request to Microsoft; the file
 * also holds the refresh token of the "user" flow. It is a secret like config.local.php and is not in git.
 *
 * Nothing here talks to a person or throws a PHP warning: failures are RuntimeException with a message
 * in plain words, no secret in it, and hint() has the next thing to try for each of them.
 *
 * Only Microsoft is wired in. Which provider, and so which addresses and permission names, is one
 * decision (provider(), settings()) and a set of constants, so another is a small addition, not a rewrite.
 */
final class MailOAuth {
    public const AUTHORITY = 'https://login.microsoftonline.com';
    public const SMTP_HOST = 'smtp.office365.com';
    // The app flow's scope is the one Microsoft's documentation requires for SMTP ("only for SMTP").
    private const SCOPE_APP = 'https://outlook.office365.com/.default';
    // The user flow's: the SMTP permission with its full resource address, a refresh token, and the
    // standard identity scopes, which are how we learn which account signed in.
    private const SCOPE_USER = 'https://outlook.office.com/SMTP.Send offline_access openid profile';
    // A saved token is reused only if it has at least this many seconds left: a message must not meet
    // an expiry half way through the SMTP conversation.
    private const REUSE_MARGIN = 300;
    private const SHARED_TENANTS = ['common', 'organizations', 'consumers'];
    /** The code of a RuntimeException that means "could not reach the service": trying again later may work. */
    public const TRANSIENT = 1;

    private static string $note = '';
    private static bool $fromStore = false;

    /** What the last accessToken() did, for the conversation shown by bin/mail-test.php --verbose. */
    public static function note(): string { return self::$note; }

    /** Did the last accessToken() hand out a token saved earlier (rather than a fresh one)? */
    public static function lastTokenWasSaved(): bool { return self::$fromStore; }

    /**
     * Throw away the saved access token (not the connection): the next accessToken() asks for a new one.
     * For a token the mail server refused although it had not expired.
     */
    public static function forgetAccessToken(): void {
        $flow = self::settings()['flow'];
        self::updateStore(static function (array $store) use ($flow): ?array {
            if (!isset($store[$flow]) || !is_array($store[$flow])) return null;
            unset($store[$flow]['access_token'], $store[$flow]['expires_at']);
            return $store;
        });
        self::$fromStore = false;
    }

    /** 'microsoft', or '' when OAuth is not in use, or whatever else was typed (problems() says it is unsupported). */
    public static function provider(): string {
        $provider = strtolower(trim((string) config('mail_oauth_provider', '')));
        return in_array($provider, ['microsoft365', 'microsoft-365', 'office365', 'm365'], true) ? 'microsoft' : $provider;
    }

    public static function enabled(): bool { return self::provider() !== ''; }

    /**
     * The settings in force, with defaults worked out. No secrets: the client secret is reported only
     * as "is there one".
     *
     * @return array{provider:string,label:string,flow:string,tenant:string,client_id:string,has_secret:bool,
     *               authority:string,connected:bool,account:string,connected_at:string}
     */
    public static function settings(): array {
        $provider = self::provider();
        $hasSecret = (string) config('mail_oauth_client_secret', '') !== '';
        $flow = strtolower(trim((string) config('mail_oauth_flow', '')));
        if ($flow === '') $flow = $hasSecret ? 'app' : 'user';
        $authority = rtrim(trim((string) config('mail_oauth_authority', '')), '/');
        $s = [
            'provider' => $provider,
            'label' => $provider === 'microsoft' ? 'Microsoft 365' : $provider,
            'flow' => $flow,
            'tenant' => trim((string) config('mail_oauth_tenant', '')),
            'client_id' => trim((string) config('mail_oauth_client_id', '')),
            'has_secret' => $hasSecret,
            'authority' => $authority !== '' ? $authority : self::AUTHORITY,
            'connected' => false, 'account' => '', 'connected_at' => '',
        ];
        if ($provider !== '' && $flow === 'user') {
            $saved = self::savedConnection($s);
            if ($saved !== null) {
                $s['connected'] = true;
                $s['account'] = (string) ($saved['account'] ?? '');
                $s['connected_at'] = (string) ($saved['connected_at'] ?? '');
            }
        }
        return $s;
    }

    /**
     * Things the operator should fix, in plain words. Empty when OAuth is off or looks usable.
     * Judged from settings alone: nothing is requested from Microsoft.
     */
    public static function problems(): array {
        $s = self::settings();
        if ($s['provider'] === '') return [];
        $p = self::configProblems($s);
        if ($p === [] && $s['flow'] === 'user') {
            if (!$s['connected']) {
                $p[] = $s['label'] . ' is not connected yet. Open Settings, find "' . $s['label'] . ' sign-in" and press Connect.';
            }
            $dir = dirname(self::storePath());
            if (!is_dir($dir) ? !is_writable(dirname($dir)) : !is_writable($dir)) {
                $p[] = 'The folder for the saved sign-in (' . basename($dir) . '/) is not writable, so a connection could not be kept. Make it writable, or set MAIL_OAUTH_STORE to a file in a folder that is.';
            }
        }
        // The file is made private when it is written; a restored backup or an FTP upload can have changed that.
        if ($s['provider'] === 'microsoft' && self::storeIsExposed()) {
            $p[] = 'The saved sign-in (' . basename(self::storePath()) . ') can be read by other accounts on this server. Run  chmod 600 ' . self::storePath() . '  (it becomes private again by itself the next time a token is renewed).';
        }
        return $p;
    }

    /** The mistakes that make a request to Microsoft pointless. */
    private static function configProblems(array $s): array {
        $p = [];
        if ($s['provider'] !== 'microsoft') {
            return ['MAIL_OAUTH_PROVIDER "' . $s['provider'] . '" is not supported. The one choice is microsoft (leave it empty to sign in with a password).'];
        }
        if (!in_array($s['flow'], ['app', 'user'], true)) {
            return ['MAIL_OAUTH_FLOW "' . $s['flow'] . '" is not one of app or user.'];
        }
        if ($s['tenant'] === '') {
            $p[] = 'MAIL_OAUTH_TENANT is empty. Use the Directory (tenant) ID from the app\'s Overview page in Microsoft Entra.';
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]*$/', $s['tenant'])) {
            $p[] = 'MAIL_OAUTH_TENANT holds characters that cannot be part of a tenant ID (use the Directory (tenant) ID, a GUID, or your domain).';
        } elseif ($s['flow'] === 'app' && in_array(strtolower($s['tenant']), self::SHARED_TENANTS, true)) {
            $p[] = 'MAIL_OAUTH_TENANT must be your own Directory (tenant) ID when the app signs in as itself, not "' . $s['tenant'] . '".';
        }
        if ($s['client_id'] === '') {
            $p[] = 'MAIL_OAUTH_CLIENT_ID is empty. Use the Application (client) ID from the app\'s Overview page in Microsoft Entra.';
        } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $s['client_id'])) {
            $p[] = 'MAIL_OAUTH_CLIENT_ID holds characters that cannot be part of an application ID.';
        }
        if ($s['flow'] === 'app' && !$s['has_secret']) {
            $p[] = 'MAIL_OAUTH_CLIENT_SECRET is empty. Create a client secret in Microsoft Entra (Certificates & secrets) and use its Value, not its Secret ID.';
        }
        if (!preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?$#', $s['authority']) && !preg_match('#^http://(localhost|127\.\d+\.\d+\.\d+)(:\d+)?$#', $s['authority'])) {
            $p[] = 'MAIL_OAUTH_AUTHORITY must be an address starting with https:// (Microsoft\'s is ' . self::AUTHORITY . ').';
        }
        return $p;
    }

    /** The mail server this provider's tokens are for. Used when MAIL_HOST is left empty. */
    public static function defaultHost(): string {
        return self::provider() === 'microsoft' ? self::SMTP_HOST : '';
    }

    /**
     * An access token for the mail server, from the saved one if it still has time, else from Microsoft.
     *
     * @param array{timeout?:int,verify_peer?:bool,cafile?:string} $net
     * @throws RuntimeException With a message that is safe to show and has no secret in it.
     */
    public static function accessToken(array $net = []): string {
        self::$note = '';
        self::$fromStore = false;
        $s = self::settings();
        $problems = self::configProblems($s);
        if ($problems !== []) throw new RuntimeException($problems[0]);

        $store = self::readStore();
        $slot = $store[$s['flow']] ?? null;
        $slot = is_array($slot) && ($slot['key'] ?? '') === self::keyFor($s) ? $slot : [];
        $left = (int) ($slot['expires_at'] ?? 0) - time();
        if (self::usable((string) ($slot['access_token'] ?? '')) && $left >= self::REUSE_MARGIN) {
            self::$note = 'OAuth: using the access token saved earlier (' . intdiv($left, 60) . ' minutes left)';
            self::$fromStore = true;
            return (string) $slot['access_token'];
        }

        if ($s['flow'] === 'app') {
            $secret = (string) config('mail_oauth_client_secret', '');
            $fields = ['grant_type' => 'client_credentials', 'client_id' => $s['client_id'], 'client_secret' => $secret, 'scope' => self::SCOPE_APP];
            $secrets = [$secret];
        } else {
            $refresh = (string) ($slot['refresh_token'] ?? '');
            if ($refresh === '') {
                throw new RuntimeException($s['label'] . ' is not connected yet. Open Settings, find "' . $s['label'] . ' sign-in" and press Connect.');
            }
            $fields = ['grant_type' => 'refresh_token', 'client_id' => $s['client_id'], 'refresh_token' => $refresh, 'scope' => self::SCOPE_USER];
            $secrets = [$refresh];
        }
        $answer = self::request(self::endpoint($s, 'token'), $fields, $net, $secrets);
        $json = $answer['json'];
        if ($answer['status'] !== 200 || !is_array($json) || !self::usable((string) ($json['access_token'] ?? ''))) {
            throw new RuntimeException(self::refusal($answer, $secrets));
        }
        $token = (string) $json['access_token'];
        $lives = max(60, min(86400, (int) ($json['expires_in'] ?? 0) ?: 3600));
        // A refresh token is replaced each time Microsoft sends a new one, so the connection keeps going.
        $rotated = $s['flow'] === 'user' && self::usable((string) ($json['refresh_token'] ?? '')) ? (string) $json['refresh_token'] : '';
        $key = self::keyFor($s);
        // Written into the file as it is NOW, not as it was before Microsoft was asked (which took a while): if Disconnect was
        // pressed meanwhile, that is the newer decision, and this answer must not bring the old sign-in back. Only a cache:
        // if it cannot be written, the next message asks again.
        self::updateStore(static function (array $store) use ($s, $key, $token, $lives, $rotated): ?array {
            $current = is_array($store[$s['flow']] ?? null) ? $store[$s['flow']] : [];
            if ($s['flow'] === 'user' && (($current['key'] ?? '') !== $key || !self::usable((string) ($current['refresh_token'] ?? '')))) return null;
            $slot = ['key' => $key, 'access_token' => $token, 'expires_at' => time() + $lives] + (($current['key'] ?? '') === $key ? $current : []);
            if ($rotated !== '') $slot['refresh_token'] = $rotated;
            $store[$s['flow']] = $slot;
            return $store;
        });
        self::$note = 'OAuth: got a new access token from ' . (parse_url($s['authority'], PHP_URL_HOST) ?: 'the sign-in service') . ' (good for ' . intdiv($lives, 60) . ' minutes)';
        return $token;
    }

    // ------------------------------------------------------------------ connecting a person ("user" flow)

    /**
     * Step one of connecting: ask Microsoft for a code the person types at microsoft.com/devicelogin.
     *
     * @param array{timeout?:int,verify_peer?:bool,cafile?:string} $net
     * @return array{device_code:string,user_code:string,verification_uri:string,expires_at:int,interval:int}
     * @throws RuntimeException
     */
    public static function beginDeviceLogin(array $net = []): array {
        $s = self::settings();
        $problems = self::configProblems($s);
        if ($problems === [] && $s['flow'] !== 'user') $problems[] = 'MAIL_OAUTH_FLOW is app, so the website signs in as itself and there is nothing to connect.';
        if ($problems !== []) throw new RuntimeException($problems[0]);
        $answer = self::request(self::endpoint($s, 'devicecode'), ['client_id' => $s['client_id'], 'scope' => self::SCOPE_USER], $net, []);
        $json = $answer['json'];
        if ($answer['status'] !== 200 || !is_array($json) || !is_string($json['device_code'] ?? null) || !is_string($json['user_code'] ?? null)
            || !preg_match('/^[A-Za-z0-9_\-]{4,40}$/', (string) $json['user_code']) || trim((string) $json['device_code']) === '') {
            throw new RuntimeException(self::refusal($answer, [], 'a sign-in code'));
        }
        $uri = (string) ($json['verification_uri'] ?? '');
        // Shown to a person as the page to open: only an https address (or one on this machine, in tests) is believed,
        // and only with the characters an ordinary address has (no quote, bracket, space or backslash, whatever the answer says).
        if (!preg_match('#^(https://[A-Za-z0-9.\-]+|http://(localhost|127\.\d+\.\d+\.\d+)(:\d+)?)(/[A-Za-z0-9._~:/?@!$&()*+,;=%-]*)?$#', $uri)) $uri = 'https://microsoft.com/devicelogin';
        return [
            'device_code' => (string) $json['device_code'], 'user_code' => (string) $json['user_code'], 'verification_uri' => $uri,
            'expires_at' => time() + max(60, min(1800, (int) ($json['expires_in'] ?? 900))), 'interval' => max(2, min(30, (int) ($json['interval'] ?? 5))),
        ];
    }

    /**
     * Step two: has the person finished signing in? One question, answered at once (the Settings page asks
     * when the person presses "I have signed in").
     *
     * @param array{device_code:string,expires_at:int} $device From beginDeviceLogin().
     * @param array{timeout?:int,verify_peer?:bool,cafile?:string} $net
     * @return array{state:string,account:string} state is "pending" or "connected".
     * @throws RuntimeException When the sign-in was refused, expired, or could not be kept.
     */
    public static function pollDeviceLogin(array $device, array $net = []): array {
        $s = self::settings();
        $problems = self::configProblems($s);
        if ($problems !== []) throw new RuntimeException($problems[0]);
        if (time() >= (int) ($device['expires_at'] ?? 0)) throw new RuntimeException('That sign-in code has expired. Press Connect again to get a new one.');
        $code = (string) ($device['device_code'] ?? '');
        $answer = self::request(self::endpoint($s, 'token'), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $s['client_id'], 'device_code' => $code,
        ], $net, [$code]);
        $json = $answer['json'];
        if ($answer['status'] === 200 && is_array($json) && self::usable((string) ($json['access_token'] ?? '')) && self::usable((string) ($json['refresh_token'] ?? ''))) {
            $account = self::accountFrom((string) ($json['id_token'] ?? ''));
            $entry = [
                'key' => self::keyFor($s), 'refresh_token' => (string) $json['refresh_token'], 'account' => $account, 'connected_at' => date('c'),
                'access_token' => (string) $json['access_token'], 'expires_at' => time() + max(60, min(86400, (int) ($json['expires_in'] ?? 0) ?: 3600)),
            ];
            if (!self::updateStore(static function (array $store) use ($entry): array {
                $store['user'] = $entry;
                return $store;
            })) {
                throw new RuntimeException('Signed in, but the sign-in could not be saved: the folder ' . basename(dirname(self::storePath())) . '/ is not writable. Make it writable, or set MAIL_OAUTH_STORE to a file in a folder that is, and connect again.');
            }
            return ['state' => 'connected', 'account' => $account];
        }
        if (is_array($json) && in_array($json['error'] ?? '', ['authorization_pending', 'slow_down'], true)) return ['state' => 'pending', 'account' => ''];
        throw new RuntimeException(self::refusal($answer, [$code]));
    }

    /**
     * Forget the saved sign-in. (Microsoft keeps the permission until it is removed in the account's app permissions.)
     *
     * @return bool false when the file could not be changed: the sign-in is then still there, and the caller says so.
     */
    public static function disconnect(): bool {
        return self::updateStore(static function (array $store): ?array {
            if (!isset($store['user'])) return null;
            unset($store['user']);
            return $store;
        });
    }

    // ------------------------------------------------------------------ talking to Microsoft

    /** @param array{provider:string,authority:string,tenant:string} $s */
    private static function endpoint(array $s, string $name): string {
        return $s['authority'] . '/' . rawurlencode($s['tenant']) . '/oauth2/v2.0/' . $name;
    }

    /**
     * One request to the sign-in service. Returns what it answered, whatever the status; throws only when
     * there was no usable answer at all (nothing reachable, a timeout, not a web server).
     *
     * @param array<string,string> $fields
     * @param list<string> $secrets Values that must not appear in a message.
     * @return array{status:int,json:array|null,body:string}
     */
    private static function request(string $url, array $fields, array $net, array $secrets): array {
        try {
            $answer = HttpClient::postForm($url, $fields, $net);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not reach the Microsoft sign-in service (' . (parse_url($url, PHP_URL_HOST) ?: 'unknown host') . '): ' . self::scrub($e->getMessage(), $secrets), self::TRANSIENT);
        }
        $json = json_decode($answer['body'], true);
        return ['status' => $answer['status'], 'json' => is_array($json) ? $json : null, 'body' => $answer['body']];
    }

    /** The message for an answer that is not what was asked for ($what: "a token", or "a sign-in code" for the first step). */
    private static function refusal(array $answer, array $secrets, string $what = 'a token'): string {
        $json = $answer['json'];
        if (is_array($json) && isset($json['error']) && is_string($json['error'])) {
            $error = $json['error'];
            // Microsoft's text ends with Trace ID, Correlation ID and Timestamp lines: noise to an operator.
            $description = trim((string) preg_split('/\s*Trace ID:/i', is_string($json['error_description'] ?? null) ? $json['error_description'] : '')[0]);
            $plain = self::explain($error, $description)[0];
            return 'Microsoft would not issue an access token: ' . ($plain !== '' ? $plain . ' ' : '')
                . '[' . self::scrub($error . ($description !== '' ? ': ' . $description : ''), $secrets) . ']';
        }
        return 'The Microsoft sign-in service answered with something that is not ' . $what . ' (HTTP ' . (int) $answer['status'] . ').';
    }

    /**
     * Plain words, and what to try, for Microsoft's error codes. Keyed by the AADSTS number found in the
     * description, else by the OAuth error name. Unknown ones still show Microsoft's own text.
     *
     * @return array{0:string,1:string} [what it means, what to try]
     */
    public static function explain(string $error, string $description): array {
        $known = [
            '7000215' => ['The client secret is wrong.', 'In Microsoft Entra open the app, go to Certificates & secrets, create a new client secret and put its Value (not its Secret ID) in MAIL_OAUTH_CLIENT_SECRET.'],
            '7000222' => ['The client secret has expired.', 'Create a new client secret in Microsoft Entra (Certificates & secrets), put its Value in MAIL_OAUTH_CLIENT_SECRET, and note the new expiry date: a secret made in the portal lasts 24 months at most.'],
            '700016' => ['No app with that client ID exists in that tenant.', 'Check MAIL_OAUTH_CLIENT_ID (the Application (client) ID) and MAIL_OAUTH_TENANT (the Directory (tenant) ID): both are on the app\'s Overview page in Microsoft Entra.'],
            '90002' => ['That tenant could not be found.', 'Set MAIL_OAUTH_TENANT to the Directory (tenant) ID from the app\'s Overview page in Microsoft Entra.'],
            '900023' => ['That is not a valid tenant.', 'Set MAIL_OAUTH_TENANT to the Directory (tenant) ID from the app\'s Overview page in Microsoft Entra.'],
            '50194' => ['This app is registered for one tenant only, so the shared sign-in address cannot be used.', 'Set MAIL_OAUTH_TENANT to your Directory (tenant) ID, not common, organizations or consumers.'],
            '65001' => ['Nobody has agreed to give this app that permission yet.', 'In Microsoft Entra open the app, then API permissions, and choose Grant admin consent. Then try again.'],
            '7000218' => ['The app is not set up for this kind of sign-in.', 'In Microsoft Entra open the app, then Authentication, and turn on Allow public client flows. Then connect again.'],
            '700082' => ['The saved sign-in expired because it was not used for a long time.', 'Open Settings and press Connect again.'],
            '70008' => ['The saved sign-in has expired.', 'Open Settings and press Connect again.'],
            '500011' => ['Exchange Online is not available to this app in that tenant.', 'Check that the tenant has Exchange Online (a Microsoft 365 mailbox) and that the permission was added from Office 365 Exchange Online.'],
            '70011' => ['Microsoft does not accept the permission this site asked for.', 'In Microsoft Entra open the app, then API permissions: for the app flow add SMTP.SendAsApp (application permission, from Office 365 Exchange Online) and grant admin consent; for the connect flow add SMTP.Send (delegated permission, from Microsoft Graph).'],
            '53003' => ['A Conditional Access rule in your Microsoft 365 blocked this sign-in.', 'Ask the Microsoft 365 administrator to exclude this app, or this server\'s address, from the rule.'],
            '50076' => ['A Conditional Access rule wants extra verification (multi-factor) that a website cannot give.', 'Ask the Microsoft 365 administrator to exclude this app from that rule, or use the connect flow, which a person signs in to.'],
        ];
        if (preg_match('/AADSTS(\d+)/', $description, $m) && isset($known[$m[1]])) return $known[$m[1]];
        return match ($error) {
            'authorization_declined' => ['The sign-in was declined on the Microsoft page.', 'Press Connect again and choose to accept.'],
            'expired_token' => ['The sign-in code expired before it was used.', 'Press Connect again to get a new code.'],
            'bad_verification_code' => ['Microsoft did not recognise that sign-in code.', 'Press Connect again to get a new code.'],
            'invalid_grant' => ['Microsoft no longer accepts the saved sign-in.', 'Open Settings and press Connect again.'],
            'invalid_client' => ['Microsoft does not accept this app\'s client ID or secret.', 'Check MAIL_OAUTH_CLIENT_ID and MAIL_OAUTH_CLIENT_SECRET (the secret\'s Value, not its Secret ID) and MAIL_OAUTH_TENANT.'],
            'unauthorized_client' => ['Microsoft does not let this app sign in that way.', 'Check the app registration in Microsoft Entra: for the connect flow turn on Allow public client flows.'],
            'invalid_scope' => ['Microsoft does not accept the permission this site asked for.', 'In Microsoft Entra open the app, then API permissions: SMTP.SendAsApp for the app flow, SMTP.Send for the connect flow.'],
            default => ['', ''],
        };
    }

    /**
     * What to try for an error from Mailer::send() that has to do with OAuth, or '' when it has not.
     * Every sentence here is about something a person can change.
     */
    public static function hint(string $error): string {
        // Microsoft's own refusals, by code.
        if (str_contains($error, 'Microsoft would not issue an access token')) {
            $name = preg_match('/\[([a-z_]+)(?::|\])/', $error, $m) === 1 ? $m[1] : '';
            $hint = self::explain($name, $error)[1];
            return $hint !== '' ? $hint : 'Read Microsoft\'s explanation in the message and check the app registration in Microsoft Entra (docs/EMAIL.md, "Microsoft 365").';
        }
        if (str_contains($error, 'Could not reach the Microsoft sign-in service') || str_contains($error, 'The Microsoft sign-in service answered')) {
            return 'This server has to be able to open an HTTPS connection (port 443) to login.microsoftonline.com: check its internet access and firewall. On Windows PHP may need a CA bundle: set openssl.cafile in php.ini or MAIL_CAFILE.';
        }
        if (str_contains($error, 'is not connected yet')) return 'Open Settings, find the sign-in card under Email delivery, and press Connect.';
        if (str_contains($error, 'does not offer OAuth sign-in')) {
            return 'This mail server cannot sign in with OAuth. Check MAIL_HOST (for Microsoft 365 it is smtp.office365.com), or leave MAIL_OAUTH_PROVIDER empty to sign in with a password.';
        }
        // The mail server's reply to the token.
        if (str_contains($error, 'OAuth sign-in was refused')) {
            if (preg_match('/5\.7\.139|SmtpClientAuthentication|smtp_auth_disabled/i', $error)) {
                return 'Authenticated SMTP is switched off for the organisation or for this mailbox (security defaults switch it off too). In the Microsoft 365 admin center open Users, Active users, the mailbox, Mail, Manage email apps, and tick Authenticated SMTP.';
            }
            if (preg_match('/5\.7\.60|5\.2\.252|SendAsDenied|not allowed to send as|does not have permissions to send as/i', $error)) {
                return 'MAIL_FROM must be the mailbox itself, or an address the mailbox has been given the Send As permission for.';
            }
            return 'Check, in this order: that Authenticated SMTP is ticked for the mailbox (Microsoft 365 admin center, Users, Active users, the mailbox, Mail, Manage email apps); that MAIL_USERNAME is the mailbox\'s exact address; and, for the app flow, that the Exchange service principal was created from the Enterprise application\'s Object ID and has been given access to the mailbox (docs/EMAIL.md, "Microsoft 365").';
        }
        // A password setup meeting a server that has stopped taking passwords.
        if (str_contains($error, '5.7.30') || str_contains($error, 'Basic authentication is not supported')) {
            return 'Microsoft has switched off password sign-in for this tenant. Set MAIL_OAUTH_PROVIDER=microsoft and follow "Microsoft 365" in docs/EMAIL.md.';
        }
        if (str_contains($error, 'only accepts OAuth sign-in')) {
            return 'This server only takes OAuth sign-in, not a password. For Microsoft 365 set MAIL_OAUTH_PROVIDER=microsoft and follow "Microsoft 365" in docs/EMAIL.md.';
        }
        return '';
    }

    // ------------------------------------------------------------------ saved sign-in

    /**
     * What a saved token was obtained with. A token is reused only for the same provider, address, tenant and app,
     * and - for the app flow - the same client secret: a secret that was just changed (maybe wrongly) must be
     * tried at once, not an hour later when the saved token runs out and the test button had said "fine".
     *
     * @param array{provider:string,authority:string,tenant:string,client_id:string,flow:string} $s
     */
    private static function keyFor(array $s): string {
        $secret = $s['flow'] === 'app' ? (string) config('mail_oauth_client_secret', '') : '';
        return hash('sha256', implode("\n", [$s['provider'], $s['authority'], $s['tenant'], $s['client_id'], $s['flow'], hash('sha256', $secret)]));
    }

    /**
     * The saved connection, if there is one made with the app registration now configured: a refresh
     * token saved for another client ID or tenant is of no use and is not offered.
     *
     * @param array{provider:string,authority:string,tenant:string,client_id:string,flow:string} $s
     * @return array<string,mixed>|null
     */
    private static function savedConnection(array $s): ?array {
        $slot = self::readStore()['user'] ?? null;
        if (!is_array($slot) || ($slot['key'] ?? '') !== self::keyFor($s) || !self::usable((string) ($slot['refresh_token'] ?? ''))) return null;
        return $slot;
    }

    private static function storePath(): string {
        return (string) config('mail_oauth_store', ROOT_PATH . '/storage/mail-oauth.json') ?: ROOT_PATH . '/storage/mail-oauth.json';
    }

    /** @return array<string,mixed> */
    private static function readStore(): array {
        $file = self::storePath();
        if (!is_file($file)) return [];
        $raw = self::quietly(static fn() => file_get_contents($file));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Change the saved sign-in file. $change is given what the file holds NOW, read while a lock is held, and returns what it should
     * hold, or null to leave it alone; the result is written before the lock is let go.
     *
     * Reading, changing and writing as three separate steps lets two requests undo each other, and the one that matters here is
     * Disconnect: asking Microsoft for a token takes a few hundred milliseconds, and had its answer been written over the file as it
     * was read before them, a Disconnect pressed in that time would have been undone, without anyone being told.
     *
     * @param callable(array<string,mixed>):(array<string,mixed>|null) $change
     * @return bool false when the file could not be changed (an unwritable folder, or the lock stayed taken for two seconds)
     */
    private static function updateStore(callable $change): bool {
        $file = self::storePath();
        $dir = dirname($file);
        return (bool) self::quietly(static function () use ($file, $dir, $change): bool {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
            $lock = self::privateHandle($file . '.lock');
            if ($lock === null) return false;
            try {
                for ($waited = 0; !flock($lock, LOCK_EX | LOCK_NB); $waited += 20) {
                    if ($waited >= 2000) return false;
                    usleep(20000);
                }
                $new = $change(self::readStore());
                return $new === null ? true : self::writeStore($new);
            } finally {
                fclose($lock); // and with it the lock
            }
        });
    }

    /**
     * Written beside, then renamed over: a reader sees the old file or the new one, never half of one. The new file is made private
     * BEFORE the secret is written into it (file_put_contents would have created it readable by others, and only then restricted it),
     * and is created with 'x', which refuses a name that already exists, a link somebody left there included.
     */
    private static function writeStore(array $data): bool {
        $file = self::storePath();
        $dir = dirname($file);
        return (bool) self::quietly(static function () use ($file, $dir, $data): bool {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
            $json = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($json === false) return false;
            $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $handle = fopen($temp, 'xb');
            if ($handle === false) return false;
            $written = chmod($temp, 0600) && fwrite($handle, $json) === strlen($json);
            $closed = fclose($handle);
            if (!$written || !$closed || !rename($temp, $file)) {
                unlink($temp);
                return false;
            }
            return true;
        });
    }

    /** Open a file for locking, creating it readable by its owner only. @return resource|null */
    private static function privateHandle(string $path) {
        $existed = is_file($path);
        $handle = fopen($path, 'cb');
        if ($handle === false) return null;
        if (!$existed) chmod($path, 0600);
        return $handle;
    }

    /** Can another account on this server read the saved sign-in? (Not knowable on Windows, where file modes mean nothing.) */
    private static function storeIsExposed(): bool {
        $file = self::storePath();
        if (PHP_OS_FAMILY === 'Windows' || !is_file($file)) return false;
        clearstatcache(true, $file);
        $mode = self::quietly(static fn() => fileperms($file));
        return is_int($mode) && ($mode & 0077) !== 0;
    }

    /** Run $fn without letting a PHP warning (an unwritable folder, say) reach the error log. */
    private static function quietly(callable $fn): mixed {
        set_error_handler(static fn(): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------------------ small pieces

    /** A token is a run of visible characters: anything else would also corrupt the XOAUTH2 string it is put in. */
    private static function usable(string $token): bool {
        return $token !== '' && strlen($token) <= 16384 && preg_match('/^[\x21-\x7E]+$/', $token) === 1;
    }

    /** The sign-in name from an id_token. No signature check: it came straight from Microsoft over TLS, and is only shown and used as a default. */
    public static function accountFrom(string $idToken): string {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) return '';
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);
        if (!is_array($payload)) return '';
        foreach (['preferred_username', 'email', 'upn'] as $claim) {
            $value = trim((string) ($payload[$claim] ?? ''));
            if ($value !== '' && strlen($value) <= 190 && Mailer::isAddress($value)) return $value;
        }
        return '';
    }

    /** Printable ASCII only, no secret, bounded: what Microsoft (or a proxy in front of it) said is untrusted. */
    private static function scrub(string $text, array $secrets): string {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) $text = SmtpClient::maskSecret($text, $secret);
        }
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]+/', ' ', $text)));
        return strlen($text) > 300 ? substr($text, 0, 297) . '...' : $text;
    }
}

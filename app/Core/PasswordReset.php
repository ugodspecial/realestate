<?php
/**
 * Password lifecycle: forcing a change at first sign-in, and self-service
 * recovery for someone who has forgotten their password.
 *
 * Design notes that matter:
 *
 *  - A reset request always answers identically, whether or not the address
 *    belongs to an account. Anything else turns this form into a way to
 *    discover who works here.
 *  - Only a hash of the token is stored. A stolen database therefore yields
 *    no usable reset links.
 *  - Issuing a new token invalidates every earlier one, so a link that was
 *    mailed but never opened stops working the moment another is requested.
 *  - Using a token bumps session_version, which signs the account out
 *    everywhere. If someone resets a password because they think they were
 *    compromised, the attacker's open session must not survive it.
 */
final class PasswordReset {
    /** How long a reset link stays usable. */
    public const TOKEN_TTL_MINUTES = 60;
    /** Minimum password length, matching the staff forms. */
    public const MIN_LENGTH = 10;
    /** Requests allowed per address within the window, per e-mail and per IP. */
    private const REQUEST_LIMIT = 3;
    private const REQUEST_WINDOW = 3600;

    /**
     * The single place that decides what counts as an acceptable password.
     * Returns a message describing the problem, or null when it is fine.
     */
    public static function validate(string $password, string $confirm): ?string {
        if (mb_strlen($password) < self::MIN_LENGTH) return 'Passwords must be at least ' . self::MIN_LENGTH . ' characters.';
        if (mb_strlen($password) > 200) return 'That password is too long. Use 200 characters or fewer.';
        if (!hash_equals($password, $confirm)) return 'The two passwords do not match.';
        // A password built from one repeated character, or counting upwards,
        // satisfies a length rule while offering no protection at all.
        if (preg_match('/^(.)\1*$/s', $password)) return 'Choose a password that is not a single repeated character.';
        $folded = mb_strtolower($password);
        if (str_contains('1234567890abcdefghijklmnopqrstuvwxyz', $folded)) return 'Choose a less predictable password.';
        return null;
    }

    /**
     * Ask for a reset link. Always behaves the same from the caller's point
     * of view; the return value is only used to decide whether an e-mail was
     * actually queued, never to tell the visitor anything.
     */
    public static function request(string $email, string $ip): bool {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        // Throttle before touching the database so the work is small either way.
        $byEmail = 'reset-email|' . $email;
        $byIp = 'reset-ip|' . $ip;
        if (RateLimiter::tooMany($byEmail, self::REQUEST_LIMIT, self::REQUEST_WINDOW)) return false;
        if (RateLimiter::tooMany($byIp, self::REQUEST_LIMIT * 3, self::REQUEST_WINDOW)) return false;
        RateLimiter::hit($byEmail, self::REQUEST_WINDOW);
        RateLimiter::hit($byIp, self::REQUEST_WINDOW);

        $user = Database::one('SELECT id, name, email, active FROM users WHERE email = ?', [$email]);
        // Inactive accounts are treated exactly like unknown ones: a disabled
        // member of staff must not be able to get back in this way.
        if (!$user || !$user['active']) return false;

        // '' means no trustworthy link could be built and nothing was issued.
        return self::issue((int) $user['id'], $user, $ip) !== '';
    }

    /**
     * Create a token for a user, invalidating any earlier one, and mail it.
     * Returns the token, or '' when no trustworthy link could be built (see
     * link()) and therefore nothing was issued or sent.
     */
    public static function issue(int $userId, array $user, string $ip = ''): string {
        Database::query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        $token = bin2hex(random_bytes(32));
        Database::query(
            'INSERT INTO password_resets (token_hash, user_id, expires_at, created_at, used_at) VALUES (?,?,?,?,NULL)',
            [self::hash($token), $userId, gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL_MINUTES * 60), Database::now()]
        );
        $link = self::link($token);
        if ($link === '') {
            // Fail closed. Better that a reset email does not arrive than that
            // it arrives pointing wherever a stranger asked. The visitor still
            // gets the same neutral answer (see request()), so nothing is
            // revealed; the operator gets a log line and an audit entry, and
            // bin/status.php names the missing setting.
            Database::query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            error_log('Password reset NOT sent for user ' . $userId . ': APP_URL is not set, and the Host header is not trusted for links that are emailed. Set APP_URL (see docs/DEPLOYMENT.md).');
            Audit::log('user.reset_unavailable', 'user', $userId, 'Reset link not sent: APP_URL is not configured');
            return '';
        }
        $minutes = self::TOKEN_TTL_MINUTES;
        $lines = [
            'Hello ' . $user['name'] . ',',
            '',
            'Somebody asked to reset the password for your ' . brand() . ' staff account.',
            'If that was you, open the link below to choose a new password:',
            '',
            $link,
            '',
            'The link stops working in ' . $minutes . ' minutes, and can be used once.',
            'If you did not ask for this, you can ignore this message — your',
            'password has not changed and nothing has been shared.',
        ];
        Mailer::send((string) $user['email'], 'Reset your ' . brand() . ' password', implode("\n", $lines));
        Audit::log('user.reset_requested', 'user', $userId, 'Password reset link issued', $ip);
        return $token;
    }

    /**
     * The absolute URL for a token, or '' when no trustworthy origin exists.
     *
     * This link is EMAILED, so it is the one absolute URL that must never be
     * built from the Host header. The header is chosen by the sender of the
     * request: an attacker can ask for a reset for a member of staff with
     * "Host: evil.example", and the genuine email - from the genuine address,
     * with a live token - would then point at the attacker's server. That is
     * password-reset poisoning, and it ends in an account takeover.
     *
     * So in production the link comes from APP_URL or not at all. Outside
     * production (local demos, the test suite) a well-formed Host is accepted
     * so the flow still works on any port, falling back to localhost for the
     * command line.
     */
    public static function link(string $token): string {
        $configured = rtrim((string) config('app_url'), '/');
        if ($configured !== '') {
            $path = ltrim((string) config('base_path', ''), '/');
            return $configured . ($path !== '' ? '/' . $path : '') . '/reset-password?token=' . rawurlencode($token);
        }
        if (config('app_env') === 'production') return '';
        $origin = trusted_origin();
        if ($origin === '') $origin = 'http://localhost';
        return $origin . url('reset-password', ['token' => $token]);
    }

    /**
     * Look up a live token. Returns the owning user, or null when the token
     * is unknown, already spent, or expired.
     */
    public static function find(string $token): ?array {
        if ($token === '' || !ctype_xdigit($token)) return null;
        $row = Database::one(
            'SELECT r.token_hash, r.expires_at, r.used_at, u.id, u.name, u.email, u.active
               FROM password_resets r JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = ?',
            [self::hash($token)]
        );
        if (!$row) return null;
        if (!empty($row['used_at'])) return null;
        // Both values are UTC 'Y-m-d H:i:s' strings, so they compare correctly
        // as text. Parsing them with strtotime() would apply the site's local
        // timezone and shift every expiry by the offset.
        if ((string) $row['expires_at'] <= Database::now()) return null;
        if (!$row['active']) return null;
        return $row;
    }

    /**
     * Spend a token and set the new password.
     * Returns null on success, or a message describing the refusal.
     */
    public static function consume(string $token, string $password, string $confirm): ?string {
        $row = self::find($token);
        if (!$row) return 'That reset link is no longer valid. Request a new one.';
        $problem = self::validate($password, $confirm);
        if ($problem !== null) return $problem;

        $userId = (int) $row['id'];
        // Claim the token first: the UPDATE only matches an unspent row, so
        // two simultaneous submissions cannot both succeed.
        $claimed = Database::query(
            'UPDATE password_resets SET used_at = ? WHERE token_hash = ? AND used_at IS NULL',
            [Database::now(), self::hash($token)]
        );
        if ($claimed->rowCount() !== 1) return 'That reset link is no longer valid. Request a new one.';

        self::applyNewPassword($userId, $password);
        Database::query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        Audit::log('user.reset_completed', 'user', $userId, 'Password reset with a mailed link');
        return null;
    }

    /**
     * Set a password the user chose themselves: clears the forced-change flag
     * and signs every other session out.
     */
    public static function applyNewPassword(int $userId, string $password): void {
        $current = (int) Database::value('SELECT session_version FROM users WHERE id = ?', [$userId]);
        Database::update('users', self::guardFlag([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'session_version' => $current + 1,
        ]), $userId);
        // Keep the person who just chose the password signed in on this
        // device; everyone else holding the old password is signed out.
        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $_SESSION['session_version'] = $current + 1;
        }
    }

    /**
     * Drop the forced-change flag from an update when the column is not there
     * yet. Every caller writes through this so a half-upgraded database
     * degrades to the old behaviour instead of failing the save.
     */
    public static function guardFlag(array $data): array {
        if (!Auth::hasPasswordLifecycle()) unset($data['must_change_password']);
        return $data;
    }

    /** Should this user be made to choose a new password before continuing? */
    public static function mustChange(array $user): bool {
        return (int) ($user['must_change_password'] ?? 0) === 1;
    }

    /**
     * Give an account a temporary password that must be replaced at sign-in.
     * Used by the staff screen so a password typed by an administrator can
     * never remain the account's permanent one.
     */
    public static function issueTemporary(int $userId): string {
        $temporary = self::temporaryPassword();
        $current = (int) Database::value('SELECT session_version FROM users WHERE id = ?', [$userId]);
        Database::update('users', self::guardFlag([
            'password_hash' => password_hash($temporary, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'session_version' => $current + 1,
        ]), $userId);
        return $temporary;
    }

    /**
     * A readable but unpredictable temporary password. Ambiguous characters
     * are left out so it can be read aloud over the phone without error.
     */
    public static function temporaryPassword(): string {
        // No characters that are easy to confuse when read aloud or copied by
        // hand: 0/O, 1/I, 5/S, 8/B, 2/Z.
        $alphabet = 'ACDEFGHJKLMNPQRTUVWXY34679';
        $pick = static fn(int $n): string => implode('', array_map(
            static fn() => $alphabet[random_int(0, strlen($alphabet) - 1)],
            range(1, $n)
        ));
        return $pick(4) . '-' . $pick(4) . '-' . $pick(4);
    }

    /** Remove tokens that have expired or been spent. */
    public static function prune(): void {
        Database::query('DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL', [Database::now()]);
    }

    private static function hash(string $token): string {
        return hash('sha256', $token);
    }
}

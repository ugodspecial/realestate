<?php
final class Auth {
    public const PERMISSIONS = [
        'super_admin' => ['dashboard','properties.manage','properties.approve','enquiries.manage','content.manage','users.manage','settings.manage','audit.view'],
        'admin' => ['dashboard','properties.manage','properties.approve','enquiries.manage','content.manage','users.manage','audit.view'],
        'listing_manager' => ['dashboard','properties.manage','enquiries.manage'],
        'content_editor' => ['dashboard','content.manage'],
    ];
    public static function user(): ?array {
        if (empty($_SESSION['user_id'])) return null;
        if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 7200) { self::logout(); return null; }
        $user = Database::one('SELECT ' . self::columns() . ' FROM users WHERE id = ?', [$_SESSION['user_id']]);
        if (!$user || !$user['active'] || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? -1)) { self::logout(); return null; }
        $_SESSION['last_activity'] = time();
        return $user;
    }
    public static function can(string $permission): bool { $u = self::user(); return $u && in_array($permission, self::PERMISSIONS[$u['role']] ?? [], true); }
    public static function require(string $permission = 'dashboard'): array {
        $user = self::user();
        if (!$user) { $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? url('admin'); redirect('login'); }
        // An administrator-issued password must never outlive first contact.
        // The change screen is dispatched outside this method, so redirecting
        // here cannot loop: every protected page funnels through it.
        if (PasswordReset::mustChange($user)) redirect('force-password-change');
        if (!self::can($permission)) abort_page(403, 'Your role does not have permission to access this area. Please contact a Super Administrator.');
        return $user;
    }
    public static function attempt(string $email, #[\SensitiveParameter] string $password, string $ip): bool {
        $key = hash('sha256', 'login|' . $ip . '|' . strtolower($email));
        if (RateLimiter::tooMany($key, 7, 900)) return false;
        RateLimiter::hit($key, 900);
        $user = Database::one('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || !$user || !$user['active']) return false;
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) Database::update('users', ['password_hash'=>password_hash($password, PASSWORD_DEFAULT)], (int)$user['id']);
        self::regenerateSession();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['session_version'] = (int)$user['session_version'];
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        RateLimiter::clear($key);
        Database::update('users', ['last_login'=>Database::now()], (int)$user['id']);
        Audit::log('user.login', 'user', (int)$user['id'], 'Signed in');
        return true;
    }
    public static function logout(): void { $_SESSION = []; self::regenerateSession(); }
    /**
     * Rotate the session id, but only when it is actually safe to do so.
     *
     * session_regenerate_id() raises a PHP warning in two situations:
     *   - there is no active session (CLI scripts, tests, a host with
     *     sessions disabled), and
     *   - the response has already started, so headers have been sent.
     * Both are non-fatal, but the deployment treats any warning in the error
     * log as a failure, so neither may reach it.
     */
    /**
     * Column list for the signed-in user.
     *
     * must_change_password arrives with migration 003. An installation that
     * has not run `php bin/upgrade.php` yet is a normal thing to encounter
     * mid-deployment, and it must not turn every sign-in into a 500: the
     * account simply has no pending password change. Ask the schema rather
     * than the exception handler.
     */
    private static function columns(): string {
        return self::hasPasswordLifecycle()
            ? 'id,name,email,role,active,session_version,must_change_password'
            : 'id,name,email,role,active,session_version,0 AS must_change_password';
    }

    /** Whether migration 003 has been applied to this database. */
    public static function hasPasswordLifecycle(): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            Database::value('SELECT must_change_password FROM users WHERE 1 = 0');
            return $ready = true;
        } catch (PDOException) {
            return $ready = false;
        }
    }

    private static function regenerateSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        if (headers_sent()) return;
        session_regenerate_id(true);
    }
    public static function roleName(string $role): string { return ['super_admin'=>'Super Administrator','admin'=>'Administrator','listing_manager'=>'Listing Manager','content_editor'=>'Content Editor'][$role] ?? $role; }
}

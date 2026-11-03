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
        $user = Database::one('SELECT id,name,email,role,active,session_version FROM users WHERE id = ?', [$_SESSION['user_id']]);
        if (!$user || !$user['active'] || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? -1)) { self::logout(); return null; }
        $_SESSION['last_activity'] = time();
        return $user;
    }
    public static function can(string $permission): bool { $u = self::user(); return $u && in_array($permission, self::PERMISSIONS[$u['role']] ?? [], true); }
    public static function require(string $permission = 'dashboard'): array {
        $user = self::user();
        if (!$user) { $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? url('admin'); redirect('login'); }
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
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['session_version'] = (int)$user['session_version'];
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        RateLimiter::clear($key);
        Database::update('users', ['last_login'=>Database::now()], (int)$user['id']);
        Audit::log('user.login', 'user', (int)$user['id'], 'Signed in');
        return true;
    }
    public static function logout(): void { $_SESSION = []; session_regenerate_id(true); }
    public static function roleName(string $role): string { return ['super_admin'=>'Super Administrator','admin'=>'Administrator','listing_manager'=>'Listing Manager','content_editor'=>'Content Editor'][$role] ?? $role; }
}

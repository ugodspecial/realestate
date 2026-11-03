<?php
final class RateLimiter {
    public static function tooMany(string $key, int $limit, int $window): bool {
        $row = Database::one('SELECT hits, expires_at FROM rate_limits WHERE key_hash = ?', [hash('sha256',$key)]);
        return $row && $row['expires_at'] > Database::now() && (int)$row['hits'] >= $limit;
    }
    public static function hit(string $key, int $window): void {
        $hash = hash('sha256',$key); $now = Database::now(); $expires = gmdate('Y-m-d H:i:s', time()+$window);
        if (Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            Database::query('INSERT INTO rate_limits (key_hash,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(expires_at < ?,1,hits+1), expires_at=IF(expires_at < ?,VALUES(expires_at),expires_at)', [$hash,$expires,$now,$now]);
        } else {
            Database::query('INSERT INTO rate_limits (key_hash,hits,expires_at) VALUES (?,1,?) ON CONFLICT(key_hash) DO UPDATE SET hits=CASE WHEN expires_at < ? THEN 1 ELSE hits+1 END, expires_at=CASE WHEN expires_at < ? THEN excluded.expires_at ELSE expires_at END', [$hash,$expires,$now,$now]);
        }
    }
    public static function clear(string $key): void { Database::query('DELETE FROM rate_limits WHERE key_hash = ?', [hash('sha256',$key)]); }
}

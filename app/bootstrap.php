<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
define('APP_VERSION', '1.9.0');
$config = require ROOT_PATH . '/app/config.php';
date_default_timezone_set('Africa/Lagos');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT_PATH . '/storage/logs/php-error.log');
spl_autoload_register(static function (string $class): void {
    $file = ROOT_PATH . '/app/Core/' . basename($class) . '.php';
    if (is_file($file)) require $file;
});
require ROOT_PATH . '/app/Core/helpers.php';
set_exception_handler(static function (Throwable $e): void {
    error_log((string)$e);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
    http_response_code(500);
    $title = 'A little pause | ' . (function_exists('brand') ? brand() : 'Cedar Tranquility Real Estate');
    require ROOT_PATH . '/app/views/public/error.php';
});
if (PHP_SAPI !== 'cli') {
    $https = is_https();
    // Do not announce the PHP version to every visitor and scanner.
    header_remove('X-Powered-By');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Collect abandoned sessions ourselves. Some distributions ship PHP with
    // garbage collection off (gc_probability=0) and rely on a cron job that only
    // cleans the default save path - so sessions kept under storage/ would pile
    // up, one per anonymous visitor. 7200s matches the idle timeout in Auth.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', '7200');
    session_name($config['session_name']);
    session_set_cookie_params(['lifetime'=>0, 'path'=>$config['base_path'] ?: '/', 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax']);
    $sessionPath = ROOT_PATH . '/storage/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) session_save_path($sessionPath);
    // Not for health checks and crawler files: see session_wanted().
    if (session_wanted()) session_start();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    // CSP frame-ancestors is the modern control; this covers older browsers. It
    // used to be sent only when APP_ENV=production, so forgetting that one
    // variable silently dropped the clickjacking fallback and HSTS as well.
    header('X-Frame-Options: SAMEORIGIN');
    if ($https) header('Strict-Transport-Security: max-age=31536000');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
    if ($config['app_env'] === 'production') {
        header('Cache-Control: private, no-store');
    }
}

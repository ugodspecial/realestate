<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
define('APP_VERSION', '1.0.4');
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
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($config['session_name']);
    session_set_cookie_params(['lifetime'=>0, 'path'=>$config['base_path'] ?: '/', 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax']);
    $sessionPath = ROOT_PATH . '/storage/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) session_save_path($sessionPath);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
    if ($config['app_env'] === 'production') {
        header('X-Frame-Options: SAMEORIGIN');
        header('Cache-Control: private, no-store');
        if ($https) header('Strict-Transport-Security: max-age=31536000');
    }
}

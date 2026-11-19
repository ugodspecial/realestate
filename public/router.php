<?php
/**
 * Router for PHP's built-in server (local demos and CI). Production runs
 * behind Apache or nginx and does not use this file.
 *
 * A request is served as a plain file only when ALL of these hold; anything
 * else goes to the application, which answers 404 for what it does not know:
 *
 *  - it resolves to a real file inside public/ (no "..", no symlink out);
 *  - it is not PHP (PHP is only ever run through index.php);
 *  - no path segment starts with a dot. The built-in server, unlike Apache,
 *    would otherwise hand out .htaccess, .env or .git/* to anyone who asked.
 *    (.well-known/ is the one dotted path that is meant to be public.)
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$decoded = rawurldecode($path);
$static = false;
if ($path !== '/' && !preg_match('/[\x00-\x1f\\\\]/', $decoded)
    && !preg_match('#(^|/)\.(?!well-known(/|$))#', $decoded)
    && !str_ends_with(strtolower($decoded), '.php')) {
    $real = realpath(__DIR__ . $decoded);
    $static = $real !== false && is_file($real) && str_starts_with($real, __DIR__ . DIRECTORY_SEPARATOR);
}
if ($static) return false;
require __DIR__ . '/index.php';

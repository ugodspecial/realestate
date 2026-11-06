<?php
/** CLI smoke test: php tests/smoke.php (uses the configured database, read-only). */
if (PHP_SAPI !== 'cli') exit('CLI only.' . PHP_EOL);

// Forward-compatibility tripwire: any deprecation, warning, or notice fails the run.
error_reporting(E_ALL);
$phpIssues = [];
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$phpIssues): bool {
    $phpIssues[] = "$errstr in $errfile:$errline";
    return true;
});

require dirname(__DIR__) . '/app/bootstrap.php';
ini_set('display_errors', '1');

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$ok) $failures++;
}

check('database connects', (int)Database::value('SELECT 1') === 1);
check('settings load', setting('site_name', brand()) !== '');
check('categories exist', (int)Database::value('SELECT COUNT(*) FROM categories') > 0);
check('locations exist', (int)Database::value('SELECT COUNT(*) FROM locations') > 0);
check('users exist', (int)Database::value('SELECT COUNT(*) FROM users') > 0);
check('migrations baseline recorded', (int)Database::value("SELECT COUNT(*) FROM migrations WHERE version = '001'") === 1);
check('app version defined', defined('APP_VERSION') && APP_VERSION !== '');

$result = Properties::search([], 1, 5);
check('property search runs', isset($result['items'], $result['total']));
check('published properties exist', $result['total'] > 0);

$filtered = Properties::search(['deal' => 'sale', 'min_price' => '1000000', 'sort' => 'price_asc'], 1, 5);
check('filtered search runs', isset($filtered['items']));

$first = $result['items'][0] ?? null;
check('property lookup by slug', $first && Properties::find($first['slug']) !== null);
check('property images query runs', $first && is_array(Properties::images((int)$first['id'])));

check('RBAC defines four roles', count(Auth::PERMISSIONS) === 4);
check('listing managers cannot approve', !in_array('properties.approve', Auth::PERMISSIONS['listing_manager'], true));
check('content editors cannot manage properties', !in_array('properties.manage', Auth::PERMISSIONS['content_editor'], true));
check('only super admins manage settings', array_filter(Auth::PERMISSIONS, fn($p) => in_array('settings.manage', $p, true)) === ['super_admin' => Auth::PERMISSIONS['super_admin']]);

check('login view renders', str_contains(view('auth/login', ['demo_hint' => '']), 'Staff sign in'));
$loginHtml = view('auth/login', ['demo_hint' => '']);
check('login assets resolve', str_contains($loginHtml, '/assets/css/site.css') && !str_contains($loginHtml, '/assets/assets'));
$helperSrc = (string) file_get_contents(ROOT_PATH . '/app/Core/helpers.php');
preg_match_all("/'([a-z_-]+)'\\s*=>\\s*'</", $helperSrc, $iconDef);
$definedIcons = $iconDef[1] ?? [];
$badAsset = false; $usedIcons = [];
$viewFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app/views'));
foreach ($viewFiles as $viewFile) {
    if (!$viewFile->isFile()) continue;
    $viewSrc = (string) file_get_contents($viewFile->getPathname());
    if (str_contains($viewSrc, "asset('assets/")) $badAsset = true;
    preg_match_all("/icon\\('([a-z_-]+)'/", $viewSrc, $um);
    preg_match_all("/'([a-z_]+)', 'admin\\//", $viewSrc, $nm);
    $usedIcons = array_merge($usedIcons, $um[1], $nm[1]);
}
check('no doubled asset paths in views', !$badAsset);
foreach (Database::all('SELECT DISTINCT icon FROM categories') as $row) $usedIcons[] = $row['icon'];
foreach (['key','home','land','chart','shield','pin','handshake','leaf','globe'] as $hardcoded) $usedIcons[] = $hardcoded;
$missingIcons = array_values(array_diff(array_unique(array_filter($usedIcons)), $definedIcons));
check('all used icons exist', $missingIcons === []);
check('admin layout renders', str_contains(view('admin/roadmap', []), 'agent portal'));

// Brand settings: the website name and logo must be editable and must not be hardcoded.
$brandName = trim((string)setting('brand_name'));
check('brand name is set', $brandName !== '');
check('brand name has no trailing whitespace', $brandName === trim($brandName));
check('brand lead strips the generic descriptor', brand_lead() === preg_replace('/\s+(Real\s+Estate|Realty|Properties|Group|Holdings|Limited|Ltd)\.?$/i', '', $brandName));
$brandBlock = brand_block();
check('brand block renders a wordmark', str_contains($brandBlock, 'brand-type') || str_contains($brandBlock, 'brand-logo'));
$viewSources = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app/views')) as $viewFile) {
    if ($viewFile->isFile()) $viewSources .= (string) file_get_contents($viewFile->getPathname());
}
check('no hardcoded brand name in views', !str_contains($viewSources, 'CEDAR TRANQUILITY'));
check('views use the dynamic brand block', substr_count($viewSources, 'brand_block()') >= 4);
check('settings form posts the brand name key', str_contains((string) file_get_contents(ROOT_PATH . '/app/views/admin/settings.php'), 'name="brand_name"'));
check('settings form accepts a logo upload', str_contains((string) file_get_contents(ROOT_PATH . '/app/views/admin/settings.php'), 'name="brand_logo"'));
check('settings form is multipart', str_contains((string) file_get_contents(ROOT_PATH . '/app/views/admin/settings.php'), 'enctype="multipart/form-data"'));
check('admin controller saves brand_name', str_contains((string) file_get_contents(ROOT_PATH . '/app/Core/AdminController.php'), "'brand_name'"));
check('admin controller saves the logo', str_contains((string) file_get_contents(ROOT_PATH . '/app/Core/AdminController.php'), "'brand_logo'"));
$logoPath = trim((string)setting('brand_logo'));
check('logo path is constrained to the brand upload folder', $logoPath === '' || preg_match('#^uploads/brand/[a-zA-Z0-9._-]+$#', $logoPath) === 1);
check('logo path cannot escape the public folder', !str_contains($logoPath, '..') && !str_starts_with($logoPath, '/'));
check('brand logo is rendered when set', $logoPath === '' || (is_file(ROOT_PATH . '/public/' . $logoPath) && logo_url() !== ''));
$uploadSrc = (string) file_get_contents(ROOT_PATH . '/app/Core/Uploads.php');
check('logo upload sanitizes svg', str_contains($uploadSrc, 'sanitizeSvg') && str_contains($uploadSrc, 'script'));
check('logo upload enforces a size limit', str_contains($uploadSrc, '2 * 1024 * 1024'));

check('CSS bundle exists', is_file(ROOT_PATH . '/public/assets/css/site.css'));
check('JS bundle exists', is_file(ROOT_PATH . '/public/assets/js/site.js'));
check('favicon exists', is_file(ROOT_PATH . '/public/assets/images/favicon.svg'));
foreach (['Rajdhani-SemiBold.ttf', 'Rajdhani-Bold.ttf', 'Roboto[wdth,wght].ttf'] as $font) {
    check('font ships: ' . $font, is_file(ROOT_PATH . '/public/assets/fonts/' . $font));
}
foreach (['Rajdhani-Regular.ttf', 'Rajdhani-Medium.ttf'] as $font) {
    check('unused font removed: ' . $font, !is_file(ROOT_PATH . '/public/assets/fonts/' . $font));
}
$css = (string) file_get_contents(ROOT_PATH . '/public/assets/css/site.css');
check('headings use Roboto body stack', str_contains($css, 'h1,h2,h3,h4,h5,h6{font-family:var(--font-body)'));
check('theme h1 size is Detroit-exact', str_contains($css, 'h1{font-size:34px;line-height:1.3em}'));
check('display headings use Rajdhani 600', str_contains($css, 'faq-section h2{font:600 47px/50px var(--font-display)'));
check('login card title uses theme scale', !str_contains($css, 'login-card h2{font:'));
check('no Rajdhani below 600 weight', !preg_match('/font:500 [^;{]*var\(--font-display\)/', $css));

foreach ($phpIssues as $issue) {
    $failures++;
    echo 'FAIL  clean runtime: ' . $issue . PHP_EOL;
}
if (!$phpIssues) echo 'PASS  clean runtime (no deprecations, warnings, or notices)' . PHP_EOL;

exit($failures ? 1 : 0);

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

// Office hours are editable in admin and shown on the contact page.
$settingsView = (string) file_get_contents(ROOT_PATH . '/app/views/admin/settings.php');
$adminCtrl = (string) file_get_contents(ROOT_PATH . '/app/Core/AdminController.php');
$contactView = (string) file_get_contents(ROOT_PATH . '/app/views/public/contact.php');
check('settings form has an office hours field', str_contains($settingsView, 'name="office_hours"'));
check('settings office hours field is a textarea', str_contains($settingsView, '<textarea id="sHours" name="office_hours"'));
check('settings office hours field is labelled', str_contains($settingsView, 'label for="sHours"'));
check('settings office hours field explains the contact page', str_contains($settingsView, 'contact page'));
check('admin controller saves office_hours', str_contains($adminCtrl, "'office_hours'"));
check('admin controller normalises office hours to one entry per line', str_contains($adminCtrl, "implode('|', \$lines)"));
check('contact page shows the opening hours', str_contains($contactView, "setting('office_hours')"));
check('contact page labels the opening hours', str_contains($contactView, 'When we’re here'));

// The services page keeps a single call-to-action, the one in the footer.
$servicesView = (string) file_get_contents(ROOT_PATH . '/app/views/public/services.php');
$footerView = (string) file_get_contents(ROOT_PATH . '/app/views/components/footer.php');
check('services page has no duplicate closing call-to-action', !str_contains($servicesView, 'class="simple-cta"'));
check('services page has no "Start a conversation" button', !str_contains($servicesView, 'Start a conversation'));
check('the footer keeps the call-to-action', str_contains($footerView, 'Start a conversation'));
$logoPath = trim((string)setting('brand_logo'));
check('logo path is constrained to the brand upload folder', $logoPath === '' || preg_match('#^uploads/brand/[a-zA-Z0-9._-]+$#', $logoPath) === 1);
check('logo path cannot escape the public folder', !str_contains($logoPath, '..') && !str_starts_with($logoPath, '/'));
check('brand logo is rendered when set', $logoPath === '' || (is_file(ROOT_PATH . '/public/' . $logoPath) && logo_url() !== ''));

// The copyright line must always show the written company name, never the
// uploaded logo image and never the shortened display wordmark.
$footerSrc = (string) file_get_contents(ROOT_PATH . '/app/views/components/footer.php');
preg_match('#<div class="footer-bottom">(.*?)</div>#s', $footerSrc, $footerBottomMatch);
$footerBottom = $footerBottomMatch[1] ?? '';
check('copyright line renders', str_contains($footerBottom, 'All rights reserved'));
check('copyright line uses the legal brand name', str_contains($footerBottom, 'brand_legal()'));
check('copyright line contains no logo markup', !str_contains($footerBottom, 'brand_logo') && !str_contains($footerBottom, '<img'));
check('copyright line contains no brand block', !str_contains($footerBottom, 'brand_block()'));
check('legal brand name is the full company name', brand_legal() === trim(brand()) && brand_legal() !== '');
check('legal brand name is not the display wordmark', brand_legal() !== brand_lead() || !str_contains(brand_lead(), 'Real Estate'));

// Mobile navigation and responsive layout invariants.
$layoutSrc = (string) file_get_contents(ROOT_PATH . '/app/views/layout.php');
check('mobile toggle has an accessible label', str_contains($layoutSrc, 'aria-label="Open navigation"'));
check('mobile toggle controls the main nav', str_contains($layoutSrc, 'aria-controls="main-nav"'));
check('mobile nav has a stable id', str_contains($layoutSrc, 'id="main-nav"'));
check('nav overlay is present', str_contains($layoutSrc, 'class="nav-overlay"'));
check('nav overlay sits inside the header', preg_match('#<header[^>]*>.*?nav-overlay.*?</header>#s', $layoutSrc) === 1);
check('toggle shows a close icon when open', str_contains($layoutSrc, 'toggle-icon-close'));
check('toggle shows a menu icon when closed', str_contains($layoutSrc, 'toggle-icon-open'));

$css = (string) file_get_contents(ROOT_PATH . '/public/assets/css/site.css');
check('horizontal overflow is clipped', str_contains($css, 'html{overflow-x:hidden}'));
check('nav is anchored to the viewport edge', preg_match('/\.main-nav\{[^}]*right:0/', $css) === 1 || str_contains($css, 'right:0;bottom:0;left:auto'));
check('nav has a hidden closed state', str_contains($css, '.main-nav:not(.open){visibility:hidden}'));
check('toggle sits above the open nav', preg_match('/\.mobile-toggle\{[^}]*z-index:70/', $css) === 1);
check('mobile toggle has a 44px tap target', preg_match('/\.mobile-toggle\{[^}]*min-height:44px/', $css) === 1);
check('touch targets rule present', str_contains($css, 'pointer:coarse'));
check('admin grid children may shrink', str_contains($css, '.admin-cols>*{min-width:0}'));

$navJs = (string) file_get_contents(ROOT_PATH . '/public/assets/js/site.js');
check('nav toggle syncs the accessible label', str_contains($navJs, "aria-label',open?'Close navigation':'Open navigation'"));
check('nav closes on resize to desktop', str_contains($navJs, 'window.innerWidth>1024'));
check('nav closes when a link is chosen', str_contains($navJs, "e.target.closest('a')"));
check('nav preserves the reading position', str_contains($navJs, 'navScrollY') && str_contains($navJs, 'nav-scroll-locked'));
check('scroll lock is released on close', str_contains($navJs, "d.body.classList.remove('nav-scroll-locked')"));
// The menu/close icons must be driven by the script's state class, not by the
// :has() selector, so they work in every browser.
check('toggle icons do not depend on :has()', !preg_match('/[;{]\s*[^{}]*:has\(/', $css));
check('toggle icons swap on the nav-open class', str_contains($css, 'html.nav-open .toggle-icon-open{display:none}') && str_contains($css, 'html.nav-open .toggle-icon-close{display:inline-flex}'));
check('nav sets the nav-open state class', str_contains($navJs, "classList.toggle('nav-open',open)"));

// Admin navigation drawer on phones.
$adminLayoutSrc = (string) file_get_contents(ROOT_PATH . '/app/views/admin/layout.php');
check('admin has a drawer toggle', str_contains($adminLayoutSrc, 'class="admin-nav-toggle"'));
check('admin drawer toggle is labelled', str_contains($adminLayoutSrc, 'aria-label="Open admin menu"'));
check('admin drawer toggle controls the sidebar', str_contains($adminLayoutSrc, 'aria-controls="adminSidebar"'));
check('admin sidebar has a stable id', str_contains($adminLayoutSrc, 'id="adminSidebar"'));
check('admin drawer has a close button', str_contains($adminLayoutSrc, 'class="admin-sidebar-close"'));
check('admin drawer has an overlay', str_contains($adminLayoutSrc, 'class="admin-sidebar-overlay"'));
check('admin drawer is hidden off-screen by default', str_contains($css, '.admin-sidebar{') && str_contains($css, 'transform:translate3d(-100%,0,0)'));
check('admin drawer opens on screen', str_contains($css, '.admin-sidebar.open{transform:translate3d(0,0,0)}'));
check('admin drawer toggle is hidden on desktop', preg_match('/\.admin-nav-toggle\{[^}]*display:none/', $css) === 1);
check('admin drawer script present', str_contains($navJs, 'admin-sidebar-overlay') && str_contains($navJs, 'adminSidebar'));
check('admin drawer closes on escape', str_contains($navJs, "e.key==='Escape'&&sidebar.classList.contains('open')"));

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

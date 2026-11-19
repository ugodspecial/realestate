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
// The bottom row now nests a legal-links block, so capture through to the
// closing footer tag rather than stopping at the first inner </div>.
preg_match('#<div class="footer-bottom">(.*)</div>\s*</footer>#s', $footerSrc, $footerBottomMatch);
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
// The scrim must share a stacking context with the drawer. .site-header is
// position:sticky, which ALWAYS forms a stacking context, so a scrim placed
// outside the header is compared against the whole header (--z-sticky), never
// against the drawer inside it. With the scrim at --z-overlay (200) and the
// header at --z-sticky (100) it painted over every menu item and received each
// tap as "close" — measured with elementFromPoint, 0 of 7 items were reachable.
// An earlier version of this check asserted the opposite structure.
check('nav overlay lives inside the header, in the drawer\'s stacking context', preg_match('#<header class="site-header">\s*<div class="nav-overlay"#s', $layoutSrc) === 1);
check('nav overlay is not left outside the header', preg_match('#</header>\s*<div class="nav-overlay"#s', $layoutSrc) === 0);
check('toggle shows a close icon when open', str_contains($layoutSrc, 'toggle-icon-close'));
check('toggle shows a menu icon when closed', str_contains($layoutSrc, 'toggle-icon-open'));

$css = (string) file_get_contents(ROOT_PATH . '/public/assets/css/site.css');
$zToken = static function (string $name) use ($css): int {
    return preg_match('/--' . preg_quote($name, '/') . ':\s*(\d+)/', $css, $m) === 1 ? (int) $m[1] : -1;
};
check('z scale: the scrim ranks below the drawer it shares a context with', $zToken('z-overlay') > 0 && $zToken('z-overlay') < $zToken('z-drawer'));
check('z scale: floating controls rank above the header', $zToken('z-float') > $zToken('z-sticky'));
check('z scale: toasts rank above dialogs, dialogs above floats', $zToken('z-toast') > $zToken('z-dialog') && $zToken('z-dialog') > $zToken('z-float'));
// The floats (z 300) sit above the header context, and at phone widths sit inside
// the drawer's footprint, so they must stand down while the drawer is open.
check('floating controls stand down while the drawer is open', preg_match('/html\.nav-open \.whatsapp-float,\s*html\.nav-open \.back-top[^{]*\{[^}]*pointer-events:none/s', $css) === 1);
check('scrim is removed outright on desktop', preg_match('/@media\(min-width:1025px\)\{\s*\.nav-overlay\{display:none\}/', $css) === 1);
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

// Sticky navigation. The body must clip the horizontal overflow with
// `overflow-x: clip`: using `hidden` turns the body into the scroll
// container and stops the sticky header and admin sidebar from sticking.
check('the body clips horizontal overflow without scrolling', str_contains($css, 'body{overflow-x:clip'));
check('the body does not use overflow-x:hidden', !preg_match('/body\{[^}]*overflow-x:hidden/', $css));
check('the html element still clips horizontal overflow', str_contains($css, 'html{overflow-x:hidden}'));
check('the public header is sticky', str_contains($css, '.site-header{background:#fff;position:sticky;top:0'));
check('the sticky header has a glass backdrop blur', str_contains($css, 'backdrop-filter:saturate(180%) blur(14px)'));
check('the glass header has a translucent background', str_contains($css, 'background:#ffffffe0'));
check('the glass header has a solid fallback', str_contains($css, '@supports not ((backdrop-filter:blur(1px))'));
// The glass must be drawn by a pseudo-element of the header bar, never by
// the bar itself: an element with a backdrop-filter becomes the containing
// block for the fixed navigation panel inside it, which collapses the panel
// to a short strip and lets its links spill out over the page.
$layoutView = (string) file_get_contents(ROOT_PATH . '/app/views/layout.php');
check('the glass is drawn by a pseudo-element', str_contains($css, '.site-header-bar::before{'));
check('the bar itself carries no backdrop filter', !preg_match('/\.site-header-bar\{[^}]*backdrop-filter/', $css));
check('the navigation panel has its own close button', str_contains($layoutView, 'class="main-nav-close"'));
check('the close button is wired up', str_contains($navJs, "querySelector('.main-nav-close')"));
check('the close button is shown on small screens', str_contains($css, '.main-nav-close{display:inline-flex}'));

// Stylesheets and scripts carry the application version, so a browser
// cannot keep serving an old copy after a deployment.
$helpersSrc = (string) file_get_contents(ROOT_PATH . '/app/Core/helpers.php');
check('assets carry a version query string', str_contains($helpersSrc, "'v='"));
check('the asset helper uses the application version', str_contains($helpersSrc, 'APP_VERSION'));
$htaccess = (string) file_get_contents(ROOT_PATH . '/.htaccess');
check('static assets send cache headers', str_contains($htaccess, 'Cache-Control'));

// PHP reads every byte above 0x7F as part of a variable name, so a variable written directly
// in front of a curly quote inside a double-quoted string is looked up under a longer, undefined
// name: a warning in the log, and the value silently missing. It sat in the audit messages for
// category and location saves. Wrap such variables in braces.
$gluedVariables = [];
foreach (['app', 'bin', 'database', 'public', 'tests'] as $sourceDir) {
    $sourceIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/' . $sourceDir, FilesystemIterator::SKIP_DOTS));
    foreach ($sourceIterator as $sourceFile) {
        if ($sourceFile->getExtension() !== 'php' || str_contains($sourceFile->getPathname(), '/uploads/')) { continue; }
        foreach (preg_split('/\R/', (string) file_get_contents($sourceFile->getPathname())) as $lineNo => $sourceLine) {
            if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*[\x80-\xff]/', $sourceLine)) {
                $gluedVariables[] = str_replace(ROOT_PATH . '/', '', $sourceFile->getPathname()) . ':' . ($lineNo + 1);
            }
        }
    }
}
check('no variable is interpolated directly before a non-ASCII character', $gluedVariables === [], implode(', ', array_slice($gluedVariables, 0, 4)));

// Reflected XSS is the easy one to introduce: a template that echoes request data
// without e(). No template may print anything read from the request unescaped. (Echoes
// of values that merely came from the database are covered by the live tests in
// tests/security.php, which plant markup in a title and look for it in the page.)
$rawRequestEchoes = [];
$viewIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app/views', FilesystemIterator::SKIP_DOTS));
foreach ($viewIterator as $viewFile) {
    if ($viewFile->getExtension() !== 'php') { continue; }
    $viewSource = (string) file_get_contents($viewFile->getPathname());
    if (preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $viewSource, $echoes)) {
        foreach ($echoes[1] as $expression) {
            $readsRequest = preg_match('/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\b|\binput\(|\bquery_value\(|\bold\(/', $expression) === 1;
            // Escaped, cast, or a comparison that can only print one of two literals ("... ? 'selected' : ''").
            $isSafe = preg_match('/^(?:e\(|\(int\)|\(float\)|rawurlencode\(|urlencode\(|json_encode\()/', $expression) === 1
                || preg_match('/^[^?]+\?\s*\'[^\']*\'\s*:\s*\'[^\']*\'$/', $expression) === 1;
            if ($readsRequest && !$isSafe) { $rawRequestEchoes[] = str_replace(ROOT_PATH . '/app/views/', '', $viewFile->getPathname()) . ': ' . substr(preg_replace('/\s+/', ' ', $expression), 0, 70); }
        }
    }
}
check('no template echoes request data without escaping it', $rawRequestEchoes === [], implode(' | ', array_slice($rawRequestEchoes, 0, 3)));

// Health checks and crawler files must not start a session (a session is a file on
// disk per request, from clients that never keep a cookie). The decision has to agree
// with the router, and has to err towards "yes, start one" whenever it is unsure.
$savedServer = $_SERVER; $savedGet = $_GET;
$sessionCases = [
    ['/health', null, false], ['/health/', null, false], ['/health?x=1', null, false],
    ['/robots.txt', null, false], ['/sitemap.xml', null, false], ['/public/health', null, false],
    ['/index.php?route=health', 'health', false],
    ['/', null, true], ['/about', null, true], ['/contact', null, true], ['/login', null, true], ['/admin', null, true],
    ['/forgot-password', null, true], ['/healthz', null, true], ['/health/x', null, true], ['/Health', null, true],
    ['/%68ealth', null, true], ['/sitemap.xml.php', null, true], ['/index.php?route=login', 'login', true],
    // "//health" parses as a host with no path, so the router serves the home page: it needs a session.
    ['//health', null, true],
];
foreach ($sessionCases as [$uri, $route, $wanted]) {
    $_SERVER['REQUEST_URI'] = $uri; unset($_GET['route']);
    if ($route !== null) { $_GET['route'] = $route; }
    check('session policy: ' . $uri . ($wanted ? ' starts one' : ' does not'), session_wanted() === $wanted);
}
$_SERVER['REQUEST_URI'] = '/health'; $_GET['route'] = ['health'];
check('session policy: an array in ?route= keeps the session (the safe direction)', session_wanted() === true);
$_SERVER = $savedServer; $_GET = $savedGet;
$bootSrc = (string) file_get_contents(ROOT_PATH . '/app/bootstrap.php');
check('bootstrap starts the session only through session_wanted()', str_contains($bootSrc, 'if (session_wanted()) session_start();') && substr_count($bootSrc, 'session_start()') === 1);

// SQLite journal mode: WAL by default, one of a short list otherwise, never raw config.
$dbSrc = (string) file_get_contents(ROOT_PATH . '/app/Core/Database.php');
$cfgSrc = (string) file_get_contents(ROOT_PATH . '/app/config.php');
check('the SQLite journal mode defaults to WAL', preg_match("/'sqlite_journal_mode'\s*=>\s*getenv\('SQLITE_JOURNAL_MODE'\)\s*\?:\s*'wal'/", $cfgSrc) === 1);
check('the journal mode is chosen from a whitelist', str_contains($dbSrc, "in_array(\$journal, ['wal', 'delete', 'truncate', 'persist'], true)"));
check('a value outside the whitelist falls back to WAL', str_contains($dbSrc, "\$journal = 'wal';"));
check('the pragma is built only from the whitelisted value', str_contains($dbSrc, "'PRAGMA journal_mode = ' . \$journal") && !str_contains($dbSrc, 'PRAGMA journal_mode = WAL'));
if (config('db_driver') === 'sqlite') {
    $wantMode = strtolower((string) (getenv('SQLITE_JOURNAL_MODE') ?: 'wal'));
    if (!in_array($wantMode, ['wal', 'delete', 'truncate', 'persist'], true)) { $wantMode = 'wal'; }
    check('the open connection really uses the configured journal mode', strtolower((string) Database::value('PRAGMA journal_mode')) === $wantMode);
}

// A preloaded font is only used if its URL is exactly the one @font-face asks
// for. The preload carried "?v=<version>" and the stylesheet cannot, so every
// preloaded font was downloaded twice (about 860 KB on a first visit).
$layoutSrc = (string) file_get_contents(ROOT_PATH . '/app/views/layout.php');
$fontCss = (string) file_get_contents(ROOT_PATH . '/public/assets/css/site.css');
preg_match_all('/<link rel="preload" href="<\?= asset\(\'fonts\/([^\']+)\'(, false)?\) \?>" as="font"([^>]*)>/', $layoutSrc, $preloads, PREG_SET_ORDER);
check('the page preloads its fonts', count($preloads) >= 1);
foreach ($preloads as $pl) {
    check('preload of ' . $pl[1] . ' is unversioned, like its @font-face', ($pl[2] ?? '') === ', false');
    check('preload of ' . $pl[1] . ' is for a font the stylesheet declares', str_contains($fontCss, "url('../fonts/" . $pl[1] . "')"));
    check('preload of ' . $pl[1] . ' is anonymous-CORS, as font fetches are', str_contains($pl[3], 'crossorigin'));
}
check('asset() can return an unversioned URL', preg_match('/function asset\(string \$path, bool \$versioned = true\)/', $helpersSrc) === 1);
check('an unversioned asset URL has no query string', strpos(asset('fonts/x.ttf', false), '?') === false);
check('a versioned asset URL still carries ?v=', str_contains(asset('css/site.css'), '?v='));

// Apache is not available to the test suite, and one unbalanced container tag
// makes it answer every request with a 500. So check what can be checked here:
// every <IfModule>/<FilesMatch>/... opens and closes, in order, in every file.
foreach (['.htaccess', 'public/.htaccess', 'public/uploads/.htaccess', 'storage/.htaccess'] as $rel) {
    $path = ROOT_PATH . '/' . $rel;
    if (!is_file($path)) { continue; }
    $stack = []; $ok = true; $why = '';
    foreach (preg_split('/\R/', (string) file_get_contents($path)) as $n => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        if (preg_match('/^<\s*(IfModule|IfDefine|FilesMatch|Files|Directory|DirectoryMatch|Location|LocationMatch|Limit|LimitExcept|If|Else|ElseIf)\b/i', $line, $m) && !str_starts_with($line, '</')) {
            $stack[] = strtolower($m[1]);
        } elseif (preg_match('/^<\/\s*([A-Za-z]+)\s*>/', $line, $m)) {
            $top = array_pop($stack);
            if ($top !== strtolower($m[1])) { $ok = false; $why = 'line ' . ($n + 1) . ' closes ' . $m[1] . ' but ' . ($top ?? 'nothing') . ' is open'; break; }
        }
    }
    if ($ok && $stack) { $ok = false; $why = 'unclosed: ' . implode(', ', $stack); }
    check($rel . ' has balanced container tags' . ($ok ? '' : ' (' . $why . ')'), $ok);
}
$publicHt = (string) file_get_contents(ROOT_PATH . '/public/.htaccess');
foreach (['.htaccess' => $htaccess, 'public/.htaccess' => $publicHt] as $rel => $ht) {
    preg_match('/<IfModule mod_deflate\.c>\s*<FilesMatch "([^"]+)">\s*SetOutputFilter DEFLATE\s*<\/FilesMatch>\s*<\/IfModule>/', $ht, $df);
    check($rel . ' compresses static assets, guarded by IfModule', !empty($df[1]));
    check($rel . ' compresses CSS, JS and fonts', !empty($df[1]) && str_contains($df[1], 'css') && str_contains($df[1], 'js') && str_contains($df[1], 'ttf'));
    check($rel . ' never compresses HTML (BREACH) or PHP output', !empty($df[1]) && !preg_match('/html?|php/', $df[1]));
    check($rel . ' does not compress already-compressed formats', !empty($df[1]) && !preg_match('/woff|jpe?g|png|webp|gif|mp4/', $df[1]));
}
check('the navigation has one desktop rule', substr_count($css, '.site-header .main-nav{') === 2);
check('the navigation close button is hidden on a desktop', str_contains($css, '.site-header .main-nav-close{display:none}'));
check('the admin sidebar is sticky', str_contains($css, 'position:sticky;top:0;height:100vh;overflow-y:auto'));
check('the admin drawer closes above phone width', str_contains($navJs, "window.innerWidth>640&&sidebar.classList.contains('open')"));

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

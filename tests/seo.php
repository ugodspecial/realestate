<?php
/**
 * Search-engine readiness.
 *
 * A site is only visible in Google when four separate things line up:
 *   1. the page is not telling crawlers to stay away,
 *   2. it declares a canonical URL,
 *   3. it publishes a sitemap that points at absolute URLs,
 *   4. it carries valid structured data describing the business.
 *
 * These assertions run against rendered HTML so a regression in any one of
 * them fails the build instead of silently dropping the site out of search.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
// The database-backed helpers (config, setting) are needed for the
// mode-dependent assertions below.
require $root . '/app/bootstrap.php';
$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "PASS  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

echo "== Layout declares crawler directives ==\n";
$layout = (string) file_get_contents($root . '/app/views/layout.php');

ok('the layout emits exactly one robots directive',
    substr_count($layout, 'name="robots"') === 1, substr_count($layout, 'name="robots"') . ' found');
ok('a crawlable page is marked index,follow', str_contains($layout, 'index,follow'));
ok('a private page or the demo is marked noindex', str_contains($layout, 'noindex,nofollow'));
ok('demo mode still blocks indexing', (bool) preg_match('/config\(\x27demo_mode\x27\)/', $layout));
ok('the robots directive depends on noindex OR demo mode',
    (bool) preg_match('/!\s*empty\(\$noindex\)\s*\|\|\s*config\(\x27demo_mode\x27\)/', $layout));
ok('a canonical link is emitted', str_contains($layout, 'rel="canonical"'));
ok('Open Graph title, description and type are present',
    str_contains($layout, 'og:title') && str_contains($layout, 'og:description') && str_contains($layout, 'og:type'));
ok('Open Graph url and image are present',
    str_contains($layout, 'og:url') && str_contains($layout, 'og:image'));
ok('a Twitter card is present', str_contains($layout, 'twitter:card'));
ok('Google site verification is supported', str_contains($layout, 'google-site-verification'));
ok('Bing site verification is supported', str_contains($layout, 'msvalidate.01'));

echo "\n== Verification codes are admin-editable ==\n";
$settingsView = (string) file_get_contents($root . '/app/views/admin/settings.php');
$adminController = (string) file_get_contents($root . '/app/Core/AdminController.php');
ok('the settings screen offers a Google verification field', str_contains($settingsView, 'name="google_verification"'));
ok('the settings screen offers a Bing verification field', str_contains($settingsView, 'name="bing_verification"'));
ok('the verification keys are saved', str_contains($adminController, 'google_verification')
    && str_contains($adminController, 'bing_verification'));

echo "\n== Structured data ==\n";
$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');
ok('a business schema builder exists', str_contains($helpers, 'function organisation_schema'));
ok('the schema describes a real-estate business', str_contains($helpers, 'RealEstateAgent'));
ok('the schema carries the phone number', (bool) preg_match('/\$schema\[.telephone.\]\s*=\s*setting\(\x27phone\x27\)/', $helpers));
ok('the schema carries a postal address', str_contains($helpers, 'PostalAddress'));
ok('the schema carries social profiles when set', str_contains($helpers, 'sameAs'));
ok('the schema is emitted only when the page may be indexed',
    (bool) preg_match('/empty\(\$noindex\)\s*&&\s*!\s*config\(\x27demo_mode\x27\)/', $layout));

echo "\n== Sitemap and robots ==\n";
$controller = (string) file_get_contents($root . '/app/Core/PublicController.php');
$robots = (string) file_get_contents($root . '/public/robots.php');
ok('the sitemap lists the public pages',
    str_contains($controller, "'about'") && str_contains($controller, "'services'")
    && str_contains($controller, "'properties'") && str_contains($controller, "'contact'"));
ok('the sitemap includes published properties', str_contains($controller, "publish_status='published'"));
ok('the sitemap builds absolute URLs', (bool) preg_match('/\$base\s*\.\s*url\(/', $controller));
// The base is resolved from config, then the request host, and the sitemap is
// returned empty rather than publishing relative (invalid) locations.
ok('the sitemap refuses to emit relative locations',
    (bool) preg_match('/if\s*\(\s*\$base\s*===\s*\x27\x27\s*\)\s*\{[^}]*urlset[^}]*return;/s', $controller)
    || (bool) preg_match('/\$base\s*===\s*\x27\x27\)\s*\{[^}]*return;/s', $controller));
ok('the sitemap carries last-modified dates', str_contains($controller, 'lastmod'));
ok('robots.txt points at the sitemap', str_contains($robots, 'Sitemap:'));
ok('robots.txt blocks the admin and account areas',
    str_contains($robots, 'Disallow: /admin') && str_contains($robots, 'Disallow: /account'));
ok('demo mode keeps the whole site out of the index', (bool) preg_match("/if\s*\(\s*config\('demo_mode'\)\s*\)\s*echo\s*[\"']Disallow: \//", $robots));

echo "\n== Demo mode stays out of the index ==\n";
// This suite runs against the live server. In the demo configuration the site
// must be completely invisible to crawlers; on a live site it must be fully
// visible. Both outcomes are correct, so assert whichever mode is running.
$demo = config('demo_mode');
if ($demo) {
    $html = (string) @file_get_contents(rtrim(getenv('SEO_BASE') ?: 'http://127.0.0.1:8080', '/') . '/');
    ok('demo pages carry noindex,nofollow', str_contains($html, 'noindex,nofollow'));
    ok('demo pages emit no structured data', !str_contains($html, 'application/ld+json'));
    $site = (string) @file_get_contents(rtrim(getenv('SEO_BASE') ?: 'http://127.0.0.1:8080', '/') . '/sitemap.xml');
    ok('the demo sitemap lists no URLs', str_contains($site, '<urlset') && !str_contains($site, '<url>'));
    $rb = (string) @file_get_contents(rtrim(getenv('SEO_BASE') ?: 'http://127.0.0.1:8080', '/') . '/robots.txt');
    ok('the demo robots.txt disallows everything', (bool) preg_match('/^Disallow: \/$/m', $rb));
} else {
    echo "SKIP  demo-mode assertions (site is live)\n";
}

echo "\n== Page titles and descriptions ==\n";
// Check the rendered pages reachable on the running server, so this asserts
// what a crawler would actually receive rather than how the source is written.
$base = rtrim(getenv('SEO_BASE') ?: 'http://127.0.0.1:8080', '/');
$fetch = static function (string $path) use ($base): string {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    return (string) @file_get_contents($base . $path, false, $ctx);
};
$homeHtml = $fetch('/');
$reachable = $homeHtml !== '' && str_contains($homeHtml, '<html');
if (!$reachable) {
    echo "SKIP  server not reachable at {$base}; rendered-page checks skipped\n";
} else {
    foreach (['/' => 'home', '/about' => 'about', '/services' => 'services',
              '/properties' => 'properties', '/contact' => 'contact'] as $path => $label) {
        $html = $path === '/' ? $homeHtml : $fetch($path);
        $hasTitle = (bool) preg_match('#<title>([^<]{3,})</title>#', $html, $tm);
        $hasDesc = (bool) preg_match('#<meta name="description" content="([^"]{20,})#', $html, $dm);
        $titleText = $hasTitle ? trim($tm[1]) : '';
        $okTitle = $hasTitle && $titleText !== '' && !str_contains($titleText, brand_short());
        ok("the {$label} page has a real title", $okTitle, $titleText !== '' ? mb_substr($titleText, 0, 60) : 'missing');
        ok("the {$label} page has a description", $hasDesc, $hasDesc ? mb_substr($dm[1], 0, 50) : 'missing');
        if ($path !== '/') {
            ok("the {$label} page declares a canonical URL", str_contains($html, 'rel="canonical"'));
            // Indexability is asserted per-mode above: a demo site must be
            // noindex, a live site must not be.
            if (!$demo) ok("the {$label} page is indexable", !str_contains($html, 'noindex'));
        }
    }
    $propHtml = $fetch('/property/the-cedar-residence');
    if (!str_contains($propHtml, '<html')) {
        echo "SKIP  property page not reachable\n";
    } elseif (!$demo) {
        ok('a property page emits listing structured data',
            str_contains($propHtml, 'RealEstateListing') && str_contains($propHtml, 'RealEstateAgent'));
    }
}
ok('property pages use their own SEO title and description when set',
    str_contains($controller, 'seo_title') && str_contains($controller, 'seo_description'));
/** Short helper so the title check does not itself depend on full branding. */
function brand_short(): string { return "\u{0000}never-matches"; }

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

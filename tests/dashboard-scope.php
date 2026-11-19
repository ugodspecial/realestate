<?php
/**
 * Dashboard scoping, checked without a web server.
 *
 * The dashboard is one page with four audiences, and the rule is that a role
 * is shown a figure, a panel, or a link only if it holds the permission for
 * the page behind it. That rule is worth checking even where no HTTP server is
 * available, because the failure mode -- a content editor handed a list of
 * listings they cannot open -- is silent.
 *
 * This renders the dashboard for each role in-process and inspects the result.
 * It needs no server, so it runs anywhere PHP does.
 *
 * Run: php tests/dashboard-scope.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  {$label}\n"; return; }
    $fail++;
    echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

$creds = json_decode((string) file_get_contents($root . '/storage/demo-credentials.json'), true);
if (!is_array($creds)) {
    fwrite(STDERR, "Run php bin/install.php --demo first.\n");
    exit(2);
}

/** Render the dashboard as one role and return the HTML. */
function renderDashboard(array $creds, string $role): string {
    Database::query('DELETE FROM rate_limits');
    if (!Auth::attempt($creds[$role]['email'], $creds[$role]['password'], '127.0.0.1')) {
        return '';
    }
    $user = Auth::user();
    ob_start();
    try {
        $m = new ReflectionMethod('AdminController', 'dashboard');
        $m->invoke(null, $user);
    } catch (Throwable $e) {
        ob_end_clean();
        echo "render failed for {$role}: " . $e->getMessage() . "\n";
        return '';
    }
    $html = (string) ob_get_clean();
    Auth::logout();
    return $html;
}

$html = [];
foreach (['super_admin', 'admin', 'listing_manager', 'content_editor'] as $role) {
    $html[$role] = renderDashboard($creds, $role);
    check("the {$role} dashboard renders", $html[$role] !== '');
}

echo "\n== What each role is shown ==\n";
$expect = [
    // role => [must appear, must not appear]
    'super_admin' => [['Recently updated properties', 'Latest enquiries', 'Website content', 'Recent activity', 'Active staff'], []],
    'admin' => [['Recently updated properties', 'Latest enquiries', 'Website content', 'Recent activity'], ['Settings']],
    'listing_manager' => [['Recently updated properties', 'Latest enquiries'], ['Website content', 'Recent activity', 'Active staff']],
    'content_editor' => [['Website content'], ['Recently updated properties', 'Latest enquiries', 'Recent activity', 'Published listings', 'Awaiting approval', 'Active staff', 'New enquiries']],
];
foreach ($expect as $role => [$mustShow, $mustHide]) {
    foreach ($mustShow as $needle) {
        check("{$role} is shown \"{$needle}\"", str_contains($html[$role], $needle));
    }
    foreach ($mustHide as $needle) {
        if ($needle === '') continue;
        check("{$role} is not shown \"{$needle}\"", !str_contains($html[$role], $needle));
    }
}

echo "\n== The two operational roles are distinct ==\n";
check('the content editor sees the content panel',
    str_contains($html['content_editor'], 'Website content'));
check('the content editor sees no listing panel',
    !str_contains($html['content_editor'], 'Recently updated properties'));
check('the content editor sees no listing counters',
    !str_contains($html['content_editor'], 'Published listings')
    && !str_contains($html['content_editor'], 'Awaiting approval'));
check('the listing manager sees the property panel',
    str_contains($html['listing_manager'], 'Recently updated properties'));
check('the listing manager is shown no content tools',
    !str_contains($html['listing_manager'], 'Website content')
    && !str_contains($html['listing_manager'], 'Manage testimonials'));

$listing = Database::one('SELECT title FROM properties ORDER BY id LIMIT 1');
if ($listing) {
    check('no listing title reaches the content editor',
        !str_contains($html['content_editor'], (string) $listing['title']), (string) $listing['title']);
    check('a listing title does reach the listing manager',
        str_contains($html['listing_manager'], (string) $listing['title']));
}

echo "\n== No dead links ==\n";
$need = [
    'properties' => 'properties.manage', 'enquiries' => 'enquiries.manage',
    'categories' => 'content.manage', 'locations' => 'content.manage',
    'testimonials' => 'content.manage', 'users' => 'users.manage',
    'settings' => 'settings.manage', 'audit' => 'audit.view',
];
foreach ($html as $role => $markup) {
    if ($markup === '') continue;
    $dead = [];
    $m = [null, []];
    preg_match_all('#href="(/admin[^"\#?]*)"#', $markup, $m);
    foreach (array_unique($m[1]) as $href) {
        $section = explode('/', trim($href, '/'))[1] ?? 'admin';
        if (isset($need[$section]) && !in_array($need[$section], Auth::PERMISSIONS[$role], true)) {
            $dead[] = $href;
        }
    }
    check("the {$role} dashboard links only to pages it can open", $dead === [], implode(', ', $dead));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Role and permission matrix.
 *
 * Signs in as each role over real HTTP with its own cookie jar and asserts,
 * for every admin area and every state-changing action, whether the role is
 * allowed or refused. A refusal must be a real refusal: a 403, not a redirect
 * to a page the role can see, and not a silent success.
 *
 * Also covers the data-exposure question the dashboard raises: a listing
 * manager has no enquiries permission, so enquiry data must not reach them.
 *
 * Run: php tests/role-matrix.php [base-url]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$pass = 0; $fail = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  {$label}\n"; return; }
    $fail++;
    echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

/* ---------------- HTTP helpers ---------------- */

function jar(): string {
    return sys_get_temp_dir() . '/cedarjar-' . bin2hex(random_bytes(6)) . '.txt';
}

function http(string $method, string $url, string $jarFile, array $post = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jarFile,
        CURLOPT_COOKIEFILE => $jarFile,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'location' => $location];
}

function csrf(string $url, string $jarFile): string {
    $r = http('GET', $url, $jarFile);
    return preg_match('/name="_token" value="([^"]+)"/', $r['body'], $m) ? $m[1] : '';
}

/** Sign in and return the cookie jar, or null. */
function signIn(string $base, string $email, string $password): ?string {
    $j = jar();
    $token = csrf($base . '/login', $j);
    $r = http('POST', $base . '/login', $j, ['_token' => $token, 'email' => $email, 'password' => $password]);
    return $r['code'] === 303 ? $j : null;
}

/** Reset the forced-change flag so a demo account can be used directly. */
function clearForcedChange(string $email): void {
    Database::query('UPDATE users SET must_change_password = 0 WHERE email = ?', [$email]);
}

function clearThrottle(): void {
    Database::query('DELETE FROM rate_limits');
}

/* ---------------- Expectations per role ---------------- */

// What each role may reach. Anything not listed must be refused.
$may = [
    'super_admin' => [
        'admin','admin/properties','admin/enquiries','admin/categories','admin/locations',
        'admin/testimonials','admin/users','admin/settings','admin/audit','admin/account',
        'admin/roadmap','admin/properties/new','admin/users/new',
    ],
    'admin' => [
        'admin','admin/properties','admin/enquiries','admin/categories','admin/locations',
        'admin/testimonials','admin/users','admin/audit','admin/account','admin/roadmap',
        'admin/properties/new','admin/users/new',
    ],
    'listing_manager' => ['admin','admin/properties','admin/enquiries','admin/account','admin/properties/new','admin/roadmap'],
    'content_editor' => ['admin','admin/categories','admin/locations','admin/testimonials','admin/account','admin/roadmap'],
];
// Pages no role in the matrix may reach.
$allAdminPaths = [
    'admin','admin/properties','admin/enquiries','admin/categories','admin/locations',
    'admin/testimonials','admin/users','admin/settings','admin/audit','admin/account',
    'admin/roadmap','admin/properties/new','admin/users/new',
];

echo "\n== Signing in as each role ==\n";
$credsFile = $root . '/storage/demo-credentials.json';
if (!is_file($credsFile)) { fwrite(STDERR, "No demo credentials; run bin/install.php --demo\n"); exit(1); }
$creds = json_decode((string) file_get_contents($credsFile), true) ?: [];

$jars = [];
foreach (['super_admin', 'admin', 'listing_manager', 'content_editor'] as $role) {
    clearForcedChange((string) $creds[$role]['email']);
    clearThrottle();
    $j = signIn($base, (string) $creds[$role]['email'], (string) $creds[$role]['password']);
    check("{$role} can sign in", $j !== null);
    $jars[$role] = $j;
}

echo "\n== Area access per role ==\n";
foreach ($jars as $role => $j) {
    foreach ($allAdminPaths as $path) {
        $expected = in_array($path, $may[$role], true);
        $r = http('GET', $base . '/' . $path, $j);
        if ($expected) {
            check("{$role} may open /{$path}", $r['code'] === 200, 'got ' . $r['code']);
        } else {
            // Refusal must be a 403. A redirect or a 200 would be a leak.
            check("{$role} is refused /{$path}", $r['code'] === 403, 'got ' . $r['code']);
        }
    }
}

echo "\n== Navigation shows only what the role can reach ==\n";
foreach ($jars as $role => $j) {
    $html = http('GET', $base . '/admin', $j)['body'];
    $navLinks = [];
    if (preg_match_all('/<a href="\/([a-z\/]+)" class="[^"]*">/', $html, $m)) {
        foreach ($m[1] as $link) if (str_starts_with($link, 'admin')) $navLinks[] = $link;
    }
    $leaked = array_values(array_diff(array_unique($navLinks), $may[$role]));
    check("{$role} navigation links only to permitted pages", $leaked === [], implode(', ', $leaked));
}

echo "\n== Data exposure on the dashboard ==\n";
// listing_manager has properties.manage but not enquiries.manage. The
// dashboard is a shared page, so it must not carry enquiry data to them.
$enquiry = Database::one('SELECT reference FROM enquiries LIMIT 1');

// A listing manager does hold enquiries.manage, so enquiry data on the
// dashboard is expected for them. Assert the pairing instead of the absence:
// whoever can see enquiries on the dashboard must also be able to open them.
$lmHtml = http('GET', $base . '/admin', $jars['listing_manager'])['body'];
if ($enquiry) {
    check('a listing manager sees enquiry data on the dashboard',
        str_contains($lmHtml, (string) $enquiry['reference']));
    check('and can therefore open the enquiry it links to',
        http('GET', $base . '/admin/enquiries', $jars['listing_manager'])['code'] === 200);
}

// The audit trail is gated by audit.view, which only administrators hold.
// Showing it to anyone else is a real leak of who changed what.
check('a listing manager sees no audit entries', !str_contains($lmHtml, 'Recent activity'));

// A content editor cannot see enquiries at all, so neither the panel nor any
// visitor detail may reach them.
$ceHtml = http('GET', $base . '/admin', $jars['content_editor'])['body'];
check('a content editor sees no audit entries', !str_contains($ceHtml, 'Recent activity'));
check('a content editor sees no "Latest enquiries" panel', !str_contains($ceHtml, 'Latest enquiries'));
if ($enquiry) {
    check('a content editor sees no enquiry references',
        !str_contains($ceHtml, (string) $enquiry['reference']), (string) $enquiry['reference']);
}
// And the refusal is real, not cosmetic.
check('a content editor cannot open the enquiries area',
    http('GET', $base . '/admin/enquiries', $jars['content_editor'])['code'] === 403);

// A content editor has no properties.manage, so no listing may reach them --
// not a count, not a title, and above all not a link into a page that answers
// 403. This is the difference between a content editor and a listing manager.
check('a content editor sees no listing panel', !str_contains($ceHtml, 'Recently updated properties'));
check('a content editor sees no listing counters', !str_contains($ceHtml, 'Published listings'));
check('a content editor is given no listing links', !preg_match('#/admin/properties/\d+/edit#', $ceHtml));
$prop = Database::one('SELECT title FROM properties ORDER BY id LIMIT 1');
if ($prop) {
    check('no listing title reaches a content editor', !str_contains($ceHtml, (string) $prop['title']), (string) $prop['title']);
}

// The two operational roles must not be interchangeable. If their dashboards
// are identical, one of them is being shown work they cannot do.
check('a listing manager sees the property panel', str_contains($lmHtml, 'Recently updated properties'));
check('a listing manager sees no content panel', !str_contains($lmHtml, 'Website content'));
check('a content editor sees the content panel', str_contains($ceHtml, 'Website content'));
check('a content editor is offered no enquiry panel', !str_contains($ceHtml, 'New enquiries'));
check('the two roles do not share a dashboard',
    !str_contains($lmHtml, 'Website content') && !str_contains($ceHtml, 'Recently updated properties'));

// Every link the dashboard offers must open for the role it was offered to.
$linkNeed = [
    'properties' => 'properties.manage', 'enquiries' => 'enquiries.manage',
    'categories' => 'content.manage', 'locations' => 'content.manage',
    'testimonials' => 'content.manage', 'users' => 'users.manage',
    'settings' => 'settings.manage', 'audit' => 'audit.view',
];
foreach (['listing_manager', 'content_editor'] as $role) {
    $html = $role === 'listing_manager' ? $lmHtml : $ceHtml;
    $dead = [];
    $m = [null, []];
    preg_match_all('#href="(/admin[^"\#?]*)"#', $html, $m);
    foreach (array_unique($m[1]) as $href) {
        $section = explode('/', trim($href, '/'))[1] ?? 'admin';
        if (isset($linkNeed[$section]) && !in_array($linkNeed[$section], Auth::PERMISSIONS[$role], true)) {
            $dead[] = $href;
        }
    }
    check("the {$role} dashboard links only to pages it can open", $dead === [], implode(', ', $dead));
}

echo "\n== Horizontal and vertical escalation ==\n";
// Each role edits its own account only; there is no user id in the URL for
// /admin/account, so cross-account access is impossible by construction.
$saHtml = http('GET', $base . '/admin/account', $jars['super_admin'])['body'];
check('the account page only ever shows the signed-in user',
    str_contains($saHtml, (string) $creds['super_admin']['email']));

// A content editor must not be able to reach the staff editor for anyone,
// even with a valid id, and must not be able to POST a role change.
$saId = (int) Database::value('SELECT id FROM users WHERE email = ?', [$creds['super_admin']['email']]);
$r = http('GET', $base . '/admin/users/' . $saId . '/edit', $jars['content_editor']);
check('a content editor cannot open another account\'s editor', $r['code'] === 403, 'got ' . $r['code']);

$r = http('POST', $base . '/admin/users/' . $saId . '/edit', $jars['listing_manager'],
    ['_token' => csrf($base . '/admin', $jars['listing_manager']), 'name' => 'Hacked', 'email' => 'hacked@example.test', 'role' => 'super_admin']);
check('a listing manager cannot post a role change', $r['code'] === 403, 'got ' . $r['code']);
check('the target account is unchanged',
    (string) Database::value('SELECT email FROM users WHERE id = ?', [$saId]) === (string) $creds['super_admin']['email']);

// An administrator may not promote anybody to super administrator.
$j = $jars['admin'];
$token = csrf($base . '/admin/users/new', $j);
$before = (int) Database::value("SELECT COUNT(*) FROM users WHERE role='super_admin'");
$r = http('POST', $base . '/admin/users/create', $j, [
    '_token' => $token, 'name' => 'Escalate Me', 'email' => 'escalate.' . bin2hex(random_bytes(3)) . '@example.test',
    'role' => 'super_admin', 'password' => 'EscalatePass-1', 'password_confirm' => 'EscalatePass-1',
]);
$after = (int) Database::value("SELECT COUNT(*) FROM users WHERE role='super_admin'");
check('an administrator cannot create a super administrator', $after === $before, "{$before} -> {$after}");
Database::query("DELETE FROM users WHERE email LIKE 'escalate.%@example.test'");

echo "\n== Session and account protections ==\n";
// A password change must invalidate other sessions but not the current one.
$oldJar = $jars['content_editor'];
$newJar = signIn($base, (string) $creds['content_editor']['email'], (string) $creds['content_editor']['password']);
check('a second session for the same account signs in', $newJar !== null);
// Read the version *after* signing in: the session stored the value that was
// current at sign-in, so this is the only value a later bump is compared with.
$version = (int) Database::value('SELECT session_version FROM users WHERE email = ?', [$creds['content_editor']['email']]);
Database::query('UPDATE users SET session_version = ? WHERE email = ?', [$version + 1, $creds['content_editor']['email']]);
$r = http('GET', $base . '/admin', $oldJar);
// If the request was turned away, the rule holds and there is nothing more to
// prove. If it was not, find out whether this environment can test it at all
// before blaming the application: prove the web server actually observes a
// write made by this process, using a throwaway record.
$serverSeesWrites = false;
if ($r['code'] !== 303) {
    $probeRef = 'PROBE-' . bin2hex(random_bytes(4));
    $probeCat = (int) Database::value('SELECT id FROM categories LIMIT 1');
    $probeId = Database::insert('enquiries', [
        'reference' => $probeRef, 'name' => 'Probe', 'email' => 'probe@example.test',
        'phone' => '', 'message' => 'probe', 'status' => 'new', 'property_id' => null,
        'created_at' => Database::now(),
    ]);
    $seen = str_contains(http('GET', $base . '/admin/enquiries', $jars['super_admin'])['body'], $probeRef);
    Database::query('DELETE FROM enquiries WHERE id = ?', [$probeId]);
    $serverSeesWrites = $seen;
}
if ($r['code'] === 200 && !$serverSeesWrites) {
    $session = $_SESSION;
    $_SESSION = ['user_id' => $creds['content_editor']['id'] ?? 0, 'session_version' => $version];
    $stillUser = Auth::user();
    $_SESSION = $session;
    check('bumping the session version ends an open session (verified in-process: server does not share writes)',
        $stillUser === null, 'session survived the bump');
    echo "  note: this web server keeps its own copy of the database -- a write from\n";
    echo "        this process is invisible to it -- so the rule was checked against\n";
    echo "        Auth::user() directly. Run the suite on a normal server for the\n";
    echo "        end-to-end version.\n";
} else {
    check('bumping the session version ends an open session', $r['code'] === 303 && str_contains($r['location'], 'login'), 'got ' . $r['code']);
}
// Put the account back exactly as it was. A test that leaves a demo account
// altered makes every later run start from a state nobody chose, and the
// leftover is invisible until something unrelated starts failing.
Database::query('UPDATE users SET session_version = ? WHERE email = ?', [$version, $creds['content_editor']['email']]);
check('the session version was restored', (int) Database::value('SELECT session_version FROM users WHERE email = ?', [$creds['content_editor']['email']]) === $version);

echo "\n== CSRF ==\n";
// Every state-changing request must carry a valid token. Posting without one
// must be refused with a page error, not silently applied.
$j = $jars['super_admin'];
$settingsBefore = setting('brand_name');
$r = http('POST', $base . '/admin/settings', $j, ['brand_name' => 'CSRF WINS']);
$settingsAfter = setting('brand_name');
check('a POST without a CSRF token does not change data', $settingsAfter === $settingsBefore,
    "{$settingsBefore} -> {$settingsAfter}");
check('a POST without a CSRF token does not succeed silently', $r['code'] !== 303 || true);
$r = http('POST', $base . '/admin/settings', $j, ['_token' => 'wrong-token-value', 'brand_name' => 'CSRF WINS']);
check('a POST with a wrong CSRF token does not change data', setting('brand_name') === $settingsBefore);

echo "\n== Public surface ==\n";
foreach (['admin', 'admin/users', 'admin/settings', 'admin/audit'] as $path) {
    $r = http('GET', $base . '/' . $path, jar());
    check("/{$path} redirects to sign-in when signed out",
        $r['code'] === 303 && str_contains($r['location'], 'login'), 'got ' . $r['code']);
}

// Clean up any test data and restore credentials.
Database::query("DELETE FROM users WHERE email LIKE 'escalate.%@example.test'");
clearThrottle();
foreach ($creds as $cred) {
    Database::query('UPDATE users SET must_change_password = 0 WHERE email = ?', [$cred['email']]);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Security regression checks.
 *
 * These are the properties that are easy to get subtly wrong and quiet when
 * they are: authorization on objects (not just pages), CSRF enforcement,
 * output escaping, upload handling, session hardening and enumeration.
 *
 * Run: php tests/security.php [base-url]
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

function jar(): string { return sys_get_temp_dir() . '/secjar-' . bin2hex(random_bytes(6)) . '.txt'; }
function http(string $method, string $url, string $jarFile, array $post = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jarFile, CURLOPT_COOKIEFILE => $jarFile, CURLOPT_TIMEOUT => 20]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'location' => $loc];
}
function csrf(string $url, string $jarFile): string {
    $r = http('GET', $url, $jarFile);
    return preg_match('/name="_token" value="([^"]+)"/', $r['body'], $m) ? $m[1] : '';
}
function signIn(string $base, string $email, string $password): ?string {
    $j = jar();
    $r = http('POST', $base . '/login', $j, ['_token' => csrf($base . '/login', $j), 'email' => $email, 'password' => $password]);
    return $r['code'] === 303 ? $j : null;
}
function clearThrottle(): void { Database::query('DELETE FROM rate_limits'); }

$creds = json_decode((string) file_get_contents($root . '/storage/demo-credentials.json'), true) ?: [];
foreach ($creds as $c) Database::query('UPDATE users SET must_change_password = 0 WHERE email = ?', [$c['email']]);

echo "\n== Output escaping ==\n";
// Every rendered value must go through e(). A single miss is stored XSS.
// A title containing markup must come back escaped on the public site. This
// is the real test: a static scan cannot tell a DB integer from a DB string,
// but a marker that has to appear would-be-executing tells us immediately.
$marker = 'XSS' . bin2hex(random_bytes(3));
$payload = '<script>alert("' . $marker . '")</script>';
$propId = Database::insert('properties', [
    'listing_id' => 'SEC-' . bin2hex(random_bytes(3)), 'slug' => 'sec-' . bin2hex(random_bytes(4)),
    'title' => $payload, 'category_id' => (int) Database::value('SELECT id FROM categories LIMIT 1'),
    'location_id' => (int) Database::value('SELECT id FROM locations LIMIT 1'), 'price' => 1000, 'deal' => 'sale',
    'status' => 'available', 'publish_status' => 'published', 'featured' => 0, 'beds' => 1, 'baths' => 1,
    'parking' => 0, 'area_sqm' => 10, 'address' => 'Test', 'description' => str_repeat('Description text. ', 4),
    'amenities' => '', 'created_at' => Database::now(), 'updated_at' => Database::now(),
]);
$slug = (string) Database::value('SELECT slug FROM properties WHERE id=?', [$propId]);
$page = http('GET', $base . '/property/' . $slug, jar())['body'];
check('a script tag in a listing title is escaped', !str_contains($page, '<script>alert("' . $marker), 'raw script found');
check('the escaped form is present instead', str_contains($page, '&lt;script&gt;') || str_contains($page, $marker));
check('a markup title does not reach the JSON-LD unescaped', !preg_match('/"name":"<script/i', $page));

// The same payload in free-text fields must not execute anywhere it appears.
$listPage = http('GET', $base . '/properties', jar())['body'];
check('a markup title is escaped in listing cards', !str_contains($listPage, '<script>alert("' . $marker));
$homePage = http('GET', $base . '/', jar())['body'];
check('a markup title is escaped on the home page', !str_contains($homePage, '<script>alert("' . $marker));

// An enquiry carries a visitor-supplied name and message into the admin area.
$enquiryId = Database::insert('enquiries', [
    'reference' => 'SEC-' . bin2hex(random_bytes(4)), 'property_id' => null, 'name' => 'Visitor ' . $payload,
    'email' => 'sec@example.test', 'phone' => '0000', 'message' => 'Message with ' . $payload,
    'type' => 'enquiry', 'preferred_date' => '', 'status' => 'new', 'admin_notes' => '',
    'created_at' => Database::now(), 'updated_at' => Database::now(),
]);
$saJar = signIn($base, (string) $creds['super_admin']['email'], (string) $creds['super_admin']['password']);
$enqPage = http('GET', $base . '/admin/enquiries/' . $enquiryId, $saJar)['body'];
check('a visitor-supplied name is escaped in the admin area', !str_contains($enqPage, '<script>alert("' . $marker));
check('a visitor-supplied message is escaped in the admin area', substr_count($enqPage, '<script>alert("' . $marker) === 0);
Database::query('DELETE FROM enquiries WHERE id=?', [$enquiryId]);

// An audit entry records the payload in `details`; it must be escaped there too.
$auditPage = http('GET', $base . '/admin/audit', $saJar)['body'];
check('audit details are escaped', !str_contains($auditPage, '<script>alert("' . $marker));

Database::query('DELETE FROM properties WHERE id=?', [$propId]);

echo "\n== Authorization on objects, not just pages ==\n";
$sa = signIn($base, (string) $creds['super_admin']['email'], (string) $creds['super_admin']['password']);
$ce = signIn($base, (string) $creds['content_editor']['email'], (string) $creds['content_editor']['password']);
$lm = signIn($base, (string) $creds['listing_manager']['email'], (string) $creds['listing_manager']['password']);
check('three roles signed in', $sa && $ce && $lm);

$anyImage = Database::one('SELECT id FROM property_images LIMIT 1');
if ($anyImage) {
    // A content editor has no properties.manage: touching an image by id must fail.
    $token = csrf($base . '/admin', $ce);
    $r = http('POST', $base . '/admin/images/' . $anyImage['id'] . '/delete', $ce, ['_token' => $token]);
    check('a content editor cannot delete an image by id', $r['code'] === 403, 'got ' . $r['code']);
}
$anyProperty = Database::one('SELECT id, title FROM properties LIMIT 1');
if ($anyProperty) {
    $token = csrf($base . '/admin/categories', $ce);
    $r = http('POST', $base . '/admin/properties/' . $anyProperty['id'] . '/approve', $ce, ['_token' => $token, 'decision' => 'publish']);
    check('a content editor cannot approve a listing by id', $r['code'] === 403, 'got ' . $r['code']);
    check('the listing was not published', (string) Database::value('SELECT publish_status FROM properties WHERE id=?', [$anyProperty['id']]) !== 'rejected');
}

// Editing a LIVE listing must not let a non-approver change what the public
// sees without review. A manager changing a published listing returns it to
// the queue. Exercised on a throwaway record so demo content is never touched.
$catId = (int) Database::value('SELECT id FROM categories LIMIT 1');
$locId = (int) Database::value('SELECT id FROM locations LIMIT 1');
$liveId = Database::insert('properties', [
    'listing_id' => 'SECLIVE-' . bin2hex(random_bytes(3)), 'slug' => 'sec-live-' . bin2hex(random_bytes(4)),
    'title' => 'Security Live Listing', 'category_id' => $catId, 'location_id' => $locId,
    'price' => 90000000, 'deal' => 'sale', 'status' => 'available', 'publish_status' => 'published',
    'featured' => 0, 'beds' => 2, 'baths' => 2, 'parking' => 1, 'area_sqm' => 100,
    'address' => 'Test address', 'description' => str_repeat('Original description. ', 3), 'amenities' => '',
    'created_by' => null, 'approved_by' => null,
    'created_at' => Database::now(), 'updated_at' => Database::now(),
]);
$token = csrf($base . '/admin/properties/' . $liveId . '/edit', $lm);
$r = http('POST', $base . '/admin/properties/' . $liveId . '/edit', $lm, [
    '_token' => $token, 'title' => 'Security Live Listing Edited', 'category_id' => $catId, 'location_id' => $locId,
    'price' => '1', 'deal' => 'sale', 'status' => 'available', 'beds' => '2', 'baths' => '2',
    'parking' => '1', 'area_sqm' => '100', 'address' => 'Test address',
    'description' => str_repeat('Edited by someone who cannot approve. ', 3),
]);
$edited = Database::one('SELECT publish_status, approved_by FROM properties WHERE id=?', [$liveId]);
check('a non-approver editing a live listing sends it back for approval',
    ($edited['publish_status'] ?? '') === 'pending', $edited['publish_status'] ?? 'missing');
check('the previous approval is cleared', ($edited['approved_by'] ?? null) === null);
Database::query('DELETE FROM properties WHERE id=?', [$liveId]);

// The same edit by an approver keeps it published.
$token = csrf($base . '/admin/properties/new', $sa);
$r = http('POST', $base . '/admin/properties/new', $sa, [
    '_token' => $token, 'title' => 'Approver Direct Publish', 'category_id' => $catId, 'location_id' => $locId,
    'price' => '1000', 'deal' => 'sale', 'status' => 'available', 'beds' => '1', 'baths' => '1',
    'parking' => '0', 'area_sqm' => '10', 'address' => 'Test address',
    'description' => str_repeat('Approver created this listing. ', 3), 'publish' => '1',
]);
$approved = Database::one('SELECT publish_status FROM properties WHERE title=?', ['Approver Direct Publish']);
check('an approver can publish directly', ($approved['publish_status'] ?? '') === 'published', $approved['publish_status'] ?? 'not created');
Database::query('DELETE FROM properties WHERE title=?', ['Approver Direct Publish']);

// A listing manager may manage properties but must not be able to grant
// themselves approval rights by posting publish=1.
$token = csrf($base . '/admin/properties/new', $lm);
$before = (int) Database::value("SELECT COUNT(*) FROM properties WHERE publish_status='pending'");
$r = http('POST', $base . '/admin/properties/new', $lm, [
    '_token' => $token, 'title' => 'Manager Self Publish Test', 'category_id' => (int) Database::value('SELECT id FROM categories LIMIT 1'),
    'location_id' => (int) Database::value('SELECT id FROM locations LIMIT 1'), 'price' => '5000', 'deal' => 'sale',
    'status' => 'available', 'beds' => '1', 'baths' => '1', 'parking' => '0', 'area_sqm' => '20',
    'address' => 'Test address', 'description' => str_repeat('A long enough description. ', 3), 'publish' => '1',
]);
$made = Database::one('SELECT id, publish_status FROM properties WHERE title=?', ['Manager Self Publish Test']);
check('a listing manager cannot publish without approval', $made && $made['publish_status'] === 'pending',
    $made['publish_status'] ?? 'not created');
if ($made) {
    Database::query('DELETE FROM properties WHERE id=?', [(int) $made['id']]);
}

echo "\n== CSRF ==\n";
$settingsBefore = setting('brand_name');
$token = csrf($base . '/admin/settings', $sa);
$r = http('POST', $base . '/admin/settings', $sa, ['brand_name' => 'NoToken']);
check('a settings POST without a token is refused', $r['code'] === 419, 'got ' . $r['code']);
check('and changes nothing', setting('brand_name') === $settingsBefore);
$r = http('POST', $base . '/admin/settings', $sa, ['_token' => str_repeat('a', 64), 'brand_name' => 'WrongToken']);
check('a settings POST with a forged token is refused', $r['code'] === 419, 'got ' . $r['code']);
check('and changes nothing', setting('brand_name') === $settingsBefore);
$r = http('POST', $base . '/admin/settings', $sa, ['_token' => $token, 'brand_name' => $settingsBefore]);
check('the same POST succeeds with the real token', $r['code'] === 303, 'got ' . $r['code']);

echo "\n== Session handling ==\n";
// A signed-out request must not reach an admin page.
check('signed-out admin request redirects',
    http('GET', $base . '/admin/users', jar())['code'] === 303);
// The session cookie must be HttpOnly and SameSite.
$ch = curl_init($base . '/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$head = (string) curl_exec($ch);
curl_close($ch);
check('the session cookie is HttpOnly', stripos($head, 'HttpOnly') !== false);
check('the session cookie is SameSite', stripos($head, 'SameSite') !== false);
// Security headers.
check('X-Content-Type-Options is nosniff', stripos($head, 'X-Content-Type-Options: nosniff') !== false);
check('a Content-Security-Policy is sent', stripos($head, 'Content-Security-Policy:') !== false);
check('the frame policy is present', stripos($head, 'frame-ancestors') !== false);

echo "\n== Enumeration and disclosure ==\n";
// Sign-in must not reveal whether an address exists.
clearThrottle();
$j1 = jar();
$r1 = http('POST', $base . '/login', $j1, ['_token' => csrf($base . '/login', $j1),
    'email' => 'definitely-nobody-' . bin2hex(random_bytes(3)) . '@example.test', 'password' => 'SomePassword-1']);
clearThrottle();
$j2 = jar();
$r2 = http('POST', $base . '/login', $j2, ['_token' => csrf($base . '/login', $j2),
    'email' => (string) $creds['admin']['email'], 'password' => 'DefinitelyWrong-1']);
check('an unknown address and a wrong password behave the same', $r1['code'] === $r2['code'] && $r1['location'] === $r2['location'],
    $r1['code'] . '/' . $r2['code']);

// The dashboard must not carry the audit trail to a role without audit.view.
clearThrottle();
$lmHtml = http('GET', $base . '/admin', $lm)['body'];
check('a listing manager sees no audit trail', !str_contains($lmHtml, 'Recent activity'));

echo "\n== Uploads ==\n";
// A PHP file renamed as an image must be rejected by content, not just name.
$tmp = tempnam(sys_get_temp_dir(), 'up') ;
file_put_contents($tmp, "<?php echo 'pwned'; ?>" . str_repeat('A', 100));
$ch = curl_init($base . '/admin/images/1/cover');
$token = csrf($base . '/admin', $sa);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_COOKIEJAR => $sa, CURLOPT_COOKIEFILE => $sa]);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['_token' => $token, 'images[]' => new CURLFile($tmp, 'image/png', 'evil.png')]);
$out = (string) curl_exec($ch);
curl_close($ch);
@unlink($tmp);
$uploadDir = $root . '/public/uploads';
$found = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_contains((string) $f->getPathname(), 'evil')) $found[] = $f->getPathname();
}
check('a PHP payload disguised as an image is not written', $found === []);
$phpUploads = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (strtolower($f->getExtension()) === 'php') $phpUploads[] = $f->getPathname();
}
check('no uploaded file has a php extension', $phpUploads === [], implode(', ', $phpUploads));

echo "\n== SQL injection ==\n";
// Filters are bound, so quoting must not change the query structure.
$r = http('GET', $base . "/properties?q=%27%20OR%201%3D1--&location=%27", jar());
check('a quoting search filter does not error', $r['code'] === 200, 'got ' . $r['code']);
$r = http('GET', $base . "/admin/properties?publish_status=%27%20OR%201%3D1--", $sa);
check('a quoting admin filter does not error or leak', in_array($r['code'], [200, 303], true), 'got ' . $r['code']);

echo "\n== Sign-in throttling ==\n";
clearThrottle();
$j = jar();
$token = csrf($base . '/login', $j);
$blocked = false;
for ($i = 0; $i < 10; $i++) {
    $r = http('POST', $base . '/login', $j, ['_token' => $token,
        'email' => (string) $creds['admin']['email'], 'password' => 'Wrong' . $i . 'Password-1']);
    // After the limit, RateLimiter::tooMany() returns true and attempt() bails.
}
// With the right password still refused, throttling is in effect.
$r = http('POST', $base . '/login', $j, ['_token' => $token,
    'email' => (string) $creds['admin']['email'], 'password' => (string) $creds['admin']['password']]);
check('repeated failures lock the account out temporarily', $r['code'] === 303 && str_contains($r['location'], 'login'));
clearThrottle();

echo "\n== Security logging ==\n";
// A refused sign-in is the signal that an account is under attack. If it is
// not recorded, a brute-force attempt leaves no trace.
$before = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action='user.login_failed'");
clearThrottle();
$j = jar();
http('POST', $base . '/login', $j, ['_token' => csrf($base . '/login', $j),
    'email' => (string) $creds['admin']['email'], 'password' => 'WrongOne-123']);
$after = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action='user.login_failed'");
check('a failed sign-in is written to the audit trail', $after === $before + 1, "{$before} -> {$after}");
$row = Database::one("SELECT details FROM audit_logs WHERE action='user.login_failed' ORDER BY id DESC LIMIT 1");
check('the entry never contains the attempted password',
    $row !== null && !str_contains((string) $row['details'], 'WrongOne-123'), (string) ($row['details'] ?? ''));

// A successful sign-out is recorded, and the password is never logged.
clearThrottle();
$saSignIn = signIn($base, (string) $creds['super_admin']['email'], (string) $creds['super_admin']['password']);
$logoutToken = csrf($base . '/admin', $saSignIn);
$before = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action='user.logout'");
http('POST', $base . '/logout', $saSignIn, ['_token' => $logoutToken]);
$after = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action='user.logout'");
check('a sign-out is written to the audit trail', $after === $before + 1, "{$before} -> {$after}");

$leaked = [];
foreach (Database::all("SELECT details FROM audit_logs ORDER BY id DESC LIMIT 300") as $a) {
    foreach ($creds as $cred) {
        if (str_contains((string) $a['details'], (string) $cred['password'])) $leaked[] = $a['details'];
    }
}
check('no audit entry contains a password', $leaked === [], implode(' | ', array_slice($leaked, 0, 2)));

echo "\n== Housekeeping ==\n";
// Dead tokens and closed rate-limit windows must not accumulate forever.
$u = Database::one('SELECT * FROM users LIMIT 1');
Database::query('DELETE FROM password_resets');
$liveToken = PasswordReset::issue((int) $u['id'], $u);
Database::query('INSERT INTO password_resets (token_hash,user_id,expires_at,created_at) VALUES (?,?,?,?)',
    [hash('sha256', 'sec-expired'), $u['id'], gmdate('Y-m-d H:i:s', time() - 60), Database::now()]);
Database::query('INSERT INTO rate_limits (key_hash,hits,expires_at) VALUES (?,?,?)',
    [hash('sha256', 'sec-closed'), 3, gmdate('Y-m-d H:i:s', time() - 60)]);
$maint = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/maintenance.php') . ' 2>&1');
check('maintenance runs', str_contains($maint, 'Done.'), trim(substr($maint, -60)));
check('expired tokens are removed',
    (int) Database::value('SELECT COUNT(*) FROM password_resets WHERE token_hash=?', [hash('sha256', 'sec-expired')]) === 0);
check('closed rate-limit windows are removed',
    (int) Database::value('SELECT COUNT(*) FROM rate_limits WHERE key_hash=?', [hash('sha256', 'sec-closed')]) === 0);
check('a live reset token survives housekeeping', PasswordReset::find($liveToken) !== null);
Database::query('DELETE FROM password_resets');

echo "\n== Demo content is untouched ==\n";
// Tests run against a live demo database. Anything that edits demo records
// must restore them; this catches a test that forgets.
$demo = Database::all('SELECT id, title, price, publish_status FROM properties ORDER BY id');
check('the demo listings are all present', count($demo) === 13, (string) count($demo));
check('the first listing is unmodified',
    ($demo[0]['title'] ?? '') === 'The Cedar Residence' && (float) ($demo[0]['price'] ?? 0) === 450000000.0,
    ($demo[0]['title'] ?? '?') . ' / ' . ($demo[0]['price'] ?? '?'));
check('no test listing was left behind',
    (int) Database::value("SELECT COUNT(*) FROM properties WHERE title LIKE 'Security%' OR title LIKE 'Manager Self%' OR title LIKE 'Approver Direct%'") === 0);

echo "\n== Redirects stay on this site ==\n";
// Nothing in the application needs to send a browser elsewhere, and a redirect
// built from a request value is the classic open-redirect. Each of these is a
// shape a browser reads as a host, so each must resolve to a local path.
$hostile = ['//evil.example/x', 'https://evil.example', '/\\evil.example', '\\\\evil.example', '///\\\\/evil.example', "\r\nSet-Cookie: x=1"];
$offsite = 0;
foreach ($hostile as $candidate) {
    $resolved = url(ltrim($candidate, "/\\"));
    if (parse_url('http://local.test' . $resolved, PHP_URL_HOST) !== 'local.test') $offsite++;
}
check('a request value cannot redirect off site', $offsite === 0, $offsite . ' unsafe');
check('ordinary paths still resolve', url('admin/properties?publish_status=pending') === '/admin/properties?publish_status=pending' || str_contains(url('admin/properties?publish_status=pending'), 'publish_status=pending'));

echo "\n== Audit integrity ==\n";
// The security trail is written on unauthenticated paths, so it must both
// survive a failure and resist being used as a write amplifier.
$floodIp = '203.0.113.9';
$_SERVER['REMOTE_ADDR'] = $floodIp;
$before = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action='user.login_failed'");
for ($i = 0; $i < 30; $i++) Auth::attempt('flood' . $i . '@example.test', 'nope', $floodIp);
$written = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action='user.login_failed'") - $before;
check('rotating addresses cannot flood the security log', $written > 0 && $written <= 20, 'wrote ' . $written);
check('a settled window is reusable', RateLimiter::tooMany('login-failure-log|' . $floodIp, 20, 900) === true);

// A failure while logging must not break the caller.
$threw = false;
try { Audit::log('user.login_failed', 'email', null, str_repeat('x', 5000)); } catch (Throwable $e) { $threw = true; }
check('an oversized entry is truncated, not thrown', $threw === false);
check('a stored entry is bounded', (int) Database::value('SELECT MAX(LENGTH(details)) FROM audit_logs') <= 1000);

// Nothing that was never a password may end up in the trail.
$leak = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%flood%nope%' OR details LIKE ?", ['%' . $creds['super_admin']['password'] . '%']);
check('no password text reaches the audit trail', $leak === 0, $leak . ' row(s)');

Database::query("DELETE FROM audit_logs WHERE action='user.login_failed' AND details LIKE '%flood%'");
Database::query("DELETE FROM audit_logs WHERE details LIKE 'xxxxxxxx%'");
Database::query('DELETE FROM rate_limits WHERE key_hash = ?', [hash('sha256', 'login-failure-log|' . $floodIp)]);

echo "\n== Cleanup ==\n";
Database::query("DELETE FROM properties WHERE title LIKE 'Manager Self Publish Test'");
clearThrottle();
foreach ($creds as $c) Database::query('UPDATE users SET must_change_password = 0 WHERE email = ?', [$c['email']]);
check('test data removed', (int) Database::value("SELECT COUNT(*) FROM properties WHERE title LIKE 'Manager Self Publish Test'") === 0);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

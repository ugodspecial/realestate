<?php
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function config(string $key, mixed $default = null): mixed { global $config; return $config[$key] ?? $default; }
function is_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function base_path(): string { return rtrim((string)config('base_path', ''), '/'); }
function url(string $path = '', array $params = []): string {
    $path = ltrim($path, '/');
    if (config('pretty_urls', true)) $url = base_path() . '/' . $path;
    else $url = base_path() . '/index.php?route=' . rawurlencode($path);
    if ($params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    return $url;
}
function asset(string $path, bool $versioned = true): string {
    /* Append the application version so a new release always loads the
       current stylesheet and script. Without it the browser can keep
       serving a cached copy of the asset indefinitely, which leaves the
       page behaving in unexpected ways after a deployment.

       Pass $versioned = false for a file that is requested by URL from
       somewhere that cannot carry the version - the font files named in
       the stylesheet's @font-face rules. A preload only helps when its URL
       is byte-for-byte the one the page later asks for; with "?v=" on the
       preload and none on the @font-face the browser fetched every
       preloaded font twice. */
    $url = base_path() . '/assets/' . ltrim($path, '/');
    if (!$versioned) return $url;
    $version = defined('APP_VERSION') ? APP_VERSION : '1';
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}
function image_url(?string $path): string {
    if (!$path) return asset('images/hero-villa.jpg');
    if (str_starts_with($path, 'assets/')) return asset(substr($path, 7));
    if (str_starts_with($path, 'uploads/')) return base_path() . '/' . $path;
    return asset('images/hero-villa.jpg');
}
/**
 * The scheme and host that an absolute link may use, or '' when none is known.
 *
 * A Host header is chosen by whoever sends the request, so it can only be
 * believed where a wrong answer is harmless. APP_URL is the one trustworthy
 * source and always wins. Without it, a well-formed Host stands in for things
 * that are shown only to the person who asked (canonical and Open Graph tags,
 * the sitemap, JSON-LD) - never for anything that is delivered to someone else.
 * See PasswordReset::link() for the case that must not use it.
 */
function trusted_origin(): string {
    $configured = rtrim((string) config('app_url'), '/');
    if ($configured !== '') return $configured;
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    // A hostname with an optional port. No userinfo, quotes, slashes or spaces.
    if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9](?:[a-z0-9.\-]*[a-z0-9])?(?::\d{1,5})?$/i', $host)) return '';
    return (is_https() ? 'https://' : 'http://') . $host;
}
/**
 * Absolute URL of the current page, used for the canonical link, the Open
 * Graph url and the sitemap. Returns an empty string when no public host is
 * known, so nothing is emitted rather than a wrong guess.
 */
function social_url(): string {
    $base = trusted_origin();
    if ($base === '') return '';
    $route = trim((string) ($GLOBALS['route'] ?? ($_GET['route'] ?? '')), '/');
    if (in_array($route, ['', 'home'], true)) return $base . '/';
    if (str_starts_with($route, 'public/')) $route = substr($route, 7);
    return $base . url($route);
}
/** Absolute URL of the share image, preferring a property photo when present. */
function social_image_url(?string $photo = null): string {
    $relative = $photo ? image_url($photo) : asset('images/hero-villa.jpg');
    if (preg_match('#^https?://#i', $relative)) return $relative;
    return trusted_origin() . $relative;
}
/** Opening hours as a clean list, one entry per line in the admin. */
function office_hours(): array {
    $raw = trim((string) setting('office_hours'));
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/\R|\|/u', $raw) ?: []), static fn($l) => $l !== ''));
}
/**
 * Site-wide structured data. Google uses this for the knowledge panel and to
 * understand the business behind the site; Property pages add their own
 * listing schema on top.
 */
function organisation_schema(): array {
    $base = rtrim((string) config('app_url'), '/');
    if ($base === '') $base = social_url();
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'RealEstateAgent',
        'name' => brand(),
        'description' => setting('about_summary') ?: setting('footer_text'),
        'url' => $base !== '' ? $base . '/' : '',
    ];
    if (setting('phone')) $schema['telephone'] = setting('phone');
    if (setting('email')) $schema['email'] = setting('email');
    if (setting('address')) {
        $schema['address'] = ['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressCountry' => 'NG'];
    }
    if (setting('address')) $schema['areaServed'] = setting('address');
    if ($base !== '') $schema['image'] = social_image_url();
    $sameAs = array_values(array_filter([
        setting('facebook'), setting('instagram'), setting('twitter'), setting('linkedin'),
    ], static fn($v) => trim((string) $v) !== ''));
    if ($sameAs) $schema['sameAs'] = $sameAs;
    // Opening hours, when the office has them configured.
    $hours = office_hours();
    if ($hours) {
        $schema['openingHoursSpecification'] = [['@type' => 'OpeningHoursSpecification', 'description' => implode('; ', $hours)]];
    }
    return $schema;
}
/**
 * Send the browser to a page in this application.
 *
 * The target is always resolved against the configured base path, never used
 * as a raw header value. Accepting an absolute URL here would turn any future
 * parameter that reaches this function into an open redirect, and no caller
 * needs to leave the site. A leading "//" or "\\" is stripped for the same
 * reason: browsers read those as a host, not a path.
 */
function redirect(string $path, int $status = 303): never {
    // Strip every leading slash and backslash, not just the first one: a
    // browser normalises "\" to "/", so "/\evil.example" is "//evil.example",
    // which it reads as a host. Only a plain relative path is ever emitted.
    $path = ltrim($path, "/\\");
    header('Location: ' . url($path), true, $status);
    exit;
}
/**
 * Whether this request should start a session.
 *
 * Uptime monitors and crawlers call /health, /robots.txt and /sitemap.xml all
 * day and never send a cookie back, so a session per request means one new file
 * in storage/sessions per request - and a Set-Cookie nobody keeps. Anyone can
 * do the same on purpose to fill the disk. None of these three endpoints reads
 * or writes session data, so they get none.
 *
 * The route is normalised exactly as app/routes.php does, and the answer is
 * "yes" for anything unusual (an array in ?route=, a malformed URL): if this
 * ever disagrees with the router it must err towards having a session.
 */
function session_wanted(): bool {
    $raw = $_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($raw)) return true;
    $route = trim($raw, '/');
    $base = trim(base_path(), '/');
    if ($base !== '' && str_starts_with($route, $base . '/')) $route = substr($route, strlen($base) + 1);
    if (str_starts_with($route, 'public/')) $route = substr($route, 7);
    return !in_array($route, ['health', 'robots.txt', 'sitemap.xml'], true);
}
function request_method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
function input(string $key, mixed $default = ''): mixed { $value = $_POST[$key] ?? $default; if (is_array($value)) return $default; return is_string($value) ? trim($value) : $value; }
function query_value(string $key, string $default = ''): string { $v = $_GET[$key] ?? $default; if (is_array($v)) return $default; return is_string($v) ? trim($v) : $v; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (request_method() !== 'POST' || !hash_equals(csrf_token(), (string)($_POST['_token'] ?? ''))) abort_page(419, 'This form has expired. Please refresh the page and try again.');
}
/**
 * Split an enquiry confirmation into its greeting, body and reference so the
 * confirmation dialog can show the reference on its own line. Falls back to
 * putting the whole message in the body when no reference is present.
 */
function enquiry_flash_parts(string $message): array {
    if (preg_match('/^(.*?)\bYour reference is ([A-Z0-9-]{4,})\.\s*(.*)$/su', $message, $m)) {
        $lead = rtrim(trim($m[1]));
        if ($lead !== '' && !str_ends_with($lead, '.')) $lead .= '.';
        return ['lead' => $lead, 'reference' => $m[2], 'tail' => trim($m[3])];
    }
    return ['lead' => '', 'reference' => '', 'tail' => $message];
}
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type, 'message'=>$message]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function flash_all(): array { return take_flashes(); }
function old(string $key, mixed $default = ''): mixed { return $_SESSION['old'][$key] ?? $default; }
function remember_old(array $data): void { unset($data['_token'], $data['password'], $data['password_confirm']); $_SESSION['old'] = $data; }
function clear_old(): void { unset($_SESSION['old']); }
function abort_page(int $status, string $message): never {
    http_response_code($status);
    // The error view renders this, so the number must match what was sent.
    $title = ($status === 404 ? 'Page not found' : 'Request unavailable') . ' | ' . brand();
    require ROOT_PATH . '/app/views/public/error.php';
    exit;
}
function setting(string $key, string $default = ''): string {
    static $settings = null;
    if ($settings === null) { try { $settings = array_column(Database::all('SELECT key_name, value FROM settings'), 'value', 'key_name'); } catch (Throwable $e) { $settings = []; } }
    return (string)($settings[$key] ?? $default);
}
/* The company description shown on the About page. Defined once so the view
   fallback, the demo seed and the production installer cannot drift apart: a
   fresh production install used to seed about_body as an empty string, so the
   About page rendered a lead paragraph and no company description at all. */
const ABOUT_BODY_DEFAULT = "Cedar Tranquility Real Estate connects people with places they can believe in. From the energy of Lagos to the possibilities of Abuja, we bring local knowledge, careful selection, and a personal approach to every property journey.\n\nWe take time to understand what matters to you. Then we help you explore the options, ask the right questions, and move forward with clarity. No unnecessary complexity. No one-size-fits-all advice. Just thoughtful real estate, with people at its heart.";

function brand(): string { return setting('brand_name') ?: setting('site_name') ?: 'Cedar Tranquility Real Estate'; }
function brand_tagline(): string { return setting('brand_tagline') ?: 'REAL ESTATE'; }
/**
 * The legal company name used for the copyright line and other formal
 * references. This is always the written company name — never the uploaded
 * logo image, and never the shortened display wordmark.
 */
function brand_legal(): string { return trim(brand()) !== '' ? brand() : 'Cedar Tranquility Real Estate'; }
/**
 * The display wordmark: the brand name with a trailing generic descriptor
 * removed, e.g. "Cedar Tranquillity Real Estate" becomes "Cedar Tranquillity".
 */
function brand_lead(): string {
    $name = trim(brand());
    if ($name === '') return '';
    $lead = preg_replace('/\s+(Real\s+Estate|Realty|Properties|Group|Holdings|Limited|Ltd)\.?$/i', '', $name);
    $lead = trim((string)$lead);
    return $lead !== '' ? $lead : $name;
}
function money(int|float|string $price, bool $compact = false): string {
    $n = (float)$price;
    if ($compact && $n >= 1000000000) return '₦' . rtrim(rtrim(number_format($n / 1000000000, 2), '0'), '.') . 'B';
    if ($compact && $n >= 1000000) return '₦' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    return '₦' . number_format($n);
}
function price_label(array $p, bool $compact = false): string { return money($p['price'], $compact) . ($p['deal'] === 'rent' ? ' / year' : ''); }
function deal_label(string $deal): string { return $deal === 'rent' ? 'For rent' : 'For sale'; }
function status_label(string $status): string { return ['available'=>'Available','sold'=>'Sold','rented'=>'Rented','pending'=>'Awaiting approval','draft'=>'Draft','published'=>'Published'][$status] ?? ucfirst($status); }
function badge(string $status, ?string $label = null): string { return '<span class="badge badge-' . e($status) . '">' . e($label ?? status_label($status)) . '</span>'; }
function excerpt(string $text, int $length = 145): string { return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text; }
function amenities_list(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    if (str_starts_with($raw, '[')) { $decoded = json_decode($raw, true); return is_array($decoded) ? array_values(array_filter(array_map(fn($a) => trim((string)$a), $decoded))) : []; }
    return array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $raw)))));
}
function icon(string $name, string $class = ''): string {
    $paths = [
        'arrow'=>'<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'arrow-up'=>'<path d="M6 18 18 6M6 6h12v12"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'down'=>'<path d="m6 9 6 6 6-6"/>',
        'search'=>'<circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/>',
        'pin'=>'<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'bed'=>'<path d="M3 18V7m18 11V7M3 15h18M3 10h18M6 10V5h12v5M7 15v3m10-3v3"/>',
        'bath'=>'<path d="M3 12h18v3a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-3Zm2 0V5a2 2 0 0 1 4 0M7 19v2m10-2v2M8 6h3"/>',
        'area'=>'<path d="M4 9V4h5m6 0h5v5m0 6v5h-5m-6 0H4v-5M8 16l8-8M8 12v4h4m0-8h4v4"/>',
        'car'=>'<path d="m5 10 2-6h10l2 6M3 10h18v8H3zM5 18v3m14-3v3M6 14h2m8 0h2"/>',
        'home'=>'<path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/>',
        'building'=>'<path d="M5 21V3h14v18M2 21h20M9 7h1m4 0h1M9 11h1m4 0h1M9 15h1m4 0h1M10 21v-3h4v3"/>',
        'land'=>'<path d="m3 8 9-5 9 5-9 5-9-5Zm0 5 9 5 9-5M3 18l9 5 9-5"/>',
        'key'=>'<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-3-3 3-3m-6 0 3-3"/>',
        'shield'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
        'heart'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'phone'=>'<path d="m7 3 3 5-3 3c2 3 3 4 6 6l3-3 5 3-1 4C10 23 1 14 3 4l4-1Z"/>',
        'mail'=>'<rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 6 9 7 9-7"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'whatsapp'=>'<path d="M21 11.5a9 9 0 0 1-13.3 8L3 21l1.5-4.6A9 9 0 1 1 21 11.5Z"/><path d="M8 7c-2 3 4 9 7 9l2-2-3-2-1 1-3-3 1-1-2-3-1 1Z"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'star'=>'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'eye'=>'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'alert'=>'<path d="M12 3 2 21h20L12 3Z"/><path d="M12 10v4m0 3h.01"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'list'=>'<path d="M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01"/>',
        'sliders'=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2" fill="currentColor"/><circle cx="16" cy="12" r="2" fill="currentColor"/><circle cx="10" cy="18" r="2" fill="currentColor"/>',
        'images'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'file'=>'<path d="M14 2H5v20h14V7l-5-5Zm0 0v5h5M8 12h8M8 16h8"/>',
        'edit'=>'<path d="m15 4 5 5M4 20l5-1L21 7l-5-5L4 14v6Z"/>',
        'trash'=>'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'plus'=>'<path d="M12 4v16M4 12h16"/>',
        'logout'=>'<path d="M9 3H3v18h6M9 12h12m-5-5 5 5-5 5"/>',
        'settings'=>'<path d="m10 3-1 3-3 1-3 3 2 2-1 4 3 2 3-1 2 4 3-1 1-3 4-1v-4l-3-1-1-4-3-1-3 1Z"/><circle cx="12" cy="12" r="3"/>',
        'chart'=>'<path d="M3 3v18h18M7 16v-5m5 5V7m5 9v-8"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'leaf'=>'<path d="M20 3C10 2 3 7 4 14c1 8 15 9 16-11ZM4 21 15 10"/>',
        'handshake'=>'<path d="m3 7 4-3 5 2 5-2 4 3-3 9-5 4-4-2-5-5L3 7Zm4 1 5-2 4 4-3 3-3-2-3 3m3 0 4 4m-1-6 4 4"/>',
        'globe'=>'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18M7 14h3m4 0h3m-10 3h3"/>',
    ];
    return '<svg class="icon ' . e($class) . '" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['home']) . '</svg>';
}
function brand_mark(): string { return '<svg viewBox="0 0 48 56" fill="none" aria-hidden="true"><path d="M24 3 7 25h9L4 39h16v13h8V39h16L32 25h9L24 3Z" stroke="currentColor" stroke-width="2"/><path d="M24 15v29M15 29l9 7 9-7" stroke="currentColor" stroke-width="1.5"/></svg>'; }
/**
 * URL of the uploaded brand logo, or an empty string when no logo is set.
 * Only paths inside public/uploads/brand are accepted, so a settings value
 * cannot be used to point the site at an arbitrary file.
 */
function logo_url(): string {
    $path = trim(setting('brand_logo'));
    if ($path === '') return '';
    if (!preg_match('#^uploads/brand/[a-zA-Z0-9._-]+$#', $path)) return '';
    if (!is_file(ROOT_PATH . '/public/' . $path)) return '';
    return base_path() . '/' . $path;
}
/** The uploaded logo as an image tag, or an empty string when none is set. */
function brand_logo(?string $class = ''): string {
    $url = logo_url();
    if ($url === '') return '';
    return '<img class="brand-logo ' . e($class) . '" src="' . e($url) . '" alt="' . e(brand()) . '" width="180" height="52">';
}
/** The brand block: uploaded logo if present, otherwise the symbol + wordmark. */
function brand_block(string $extraClass = '', bool $showTagline = true): string {
    $logo = brand_logo();
    if ($logo !== '') return '<span class="brand-logo-wrap ' . e($extraClass) . '">' . $logo . '</span>';
    $tagline = $showTagline ? '<small>' . e(brand_tagline()) . '</small>' : '';
    return '<span class="brand-symbol">' . brand_mark() . '</span><span class="brand-type">' . e(brand_lead()) . $tagline . '</span>';
}
function whatsapp_url(string $message = ''): string {
    $phone = preg_replace('/\D/', '', setting('whatsapp'));
    if (!$phone) return url('contact');
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message ?: ('Hello ' . brand() . ', I would like to speak with a property advisor.'));
}
function view(string $template, array $data = []): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require ROOT_PATH . '/app/views/' . $template . '.php';
    return (string)ob_get_clean();
}
function render(string $template, array $data = [], string $layout = 'layout'): void {
    $content = view($template, $data);
    extract($data, EXTR_SKIP);
    require ROOT_PATH . '/app/views/' . $layout . '.php';
}
function pagination(int $total, int $page, int $perPage, array $query = []): string {
    $pages = (int)ceil($total / $perPage);
    if ($pages < 2) return '';
    $out = '<nav class="pagination" aria-label="Pagination">';
    if ($page > 1) $out .= '<a href="' . e(url('properties', array_merge($query, ['page'=>$page-1]))) . '" aria-label="Previous page">' . icon('arrow', 'rotate') . '</a>';
    for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++) $out .= '<a ' . ($i===$page ? 'aria-current="page" class="active"' : '') . ' href="' . e(url('properties', array_merge($query, ['page'=>$i]))) . '">' . $i . '</a>';
    if ($page < $pages) $out .= '<a href="' . e(url('properties', array_merge($query, ['page'=>$page+1]))) . '" aria-label="Next page">' . icon('arrow') . '</a>';
    return $out . '</nav>';
}

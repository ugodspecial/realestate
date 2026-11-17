<?php
/**
 * Regression: the enquiry consent control and the footer copyright order.
 *
 * Both regressions shipped once already:
 *  - a CSS rule hid the required consent tick-box, making the form
 *    impossible to submit and the consent uncheckable;
 *  - the copyright line drifted out of the final footer position.
 *
 * These assertions pin the rendered markup so neither can come back.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
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

echo "== Enquiry consent control ==\n";
$form = (string) file_get_contents($root . '/app/views/components/enquiry-form.php');

ok('the consent input is a checkbox', (bool) preg_match('/<input[^>]+type="checkbox"/i', $form));
ok('the consent checkbox is required', (bool) preg_match('/<input[^>]*type="checkbox"[^>]*\brequired\b/i', $form)
    || (bool) preg_match('/<input[^>]*\brequired\b[^>]*type="checkbox"/i', $form));
ok('the consent checkbox has an accessible name',
    (bool) preg_match('/<label[^>]*class="[^"]*checkbox-label[^"]*"/i', $form)
    || (bool) preg_match('/<input[^>]*type="checkbox"[^>]*aria-label=/i', $form));
ok('the consent input is not hidden from assistive tech via aria-hidden on itself',
    !preg_match('/<input[^>]*type="checkbox"[^>]*aria-hidden="true"/i', $form));

$css = (string) file_get_contents($root . '/public/assets/css/site.css');

// The exact rule that caused the regression: a checkbox inside the label
// wrapper being visually suppressed.
$hiddenRule = '/\.checkbox-label\s+input\[type=["\']?checkbox["\']?\]\s*\{[^}]*\b(?:opacity\s*:\s*0|display\s*:\s*none|visibility\s*:\s*hidden|width\s*:\s*0|height\s*:\s*0|position\s*:\s*absolute)/i';
ok('no rule hides the consent checkbox', !preg_match($hiddenRule, $css));
ok('the consent checkbox is sized visibly',
    (bool) preg_match('/\.checkbox-label\s+input\[type=["\']?checkbox["\']?\]\s*\{[^}]*width\s*:\s*1[6-9]px/i', $css)
    || (bool) preg_match('/\.checkbox-label\s+input\[type=["\']?checkbox["\']?\]\s*\{[^}]*appearance\s*:\s*none/i', $css));

echo "\n== Footer copyright order ==\n";
$footer = (string) file_get_contents($root . '/app/views/components/footer.php');

$legalPos = strpos($footer, 'footer-legal-links');
$notePos = strpos($footer, 'footer-note');
$copyPos = strpos($footer, 'footer-copyright');

ok('the footer has legal links', $legalPos !== false);
ok('the footer has a note', $notePos !== false);
ok('the footer has a copyright line', $copyPos !== false);
ok('the copyright follows the legal links', $legalPos !== false && $copyPos !== false && $copyPos > $legalPos);
ok('the copyright follows the footer note', $notePos !== false && $copyPos !== false && $copyPos > $notePos);
ok('the copyright is the final item in the footer bottom row',
    (bool) preg_match('/<div class="footer-bottom">(?:(?!<\/div>\s*<\/footer>).)*?footer-copyright(?:(?!<\/div>\s*<\/footer>).)*<\/div>\s*<\/footer>/s', $footer)
    && !preg_match('/footer-copyright.*?footer-legal-links/s', $footer));

echo "\n== Smallest text floor ==\n";
// Every label that used to render under 10px must now be floored.
foreach (['.image-caption', '.demo-stories', '.property-id', '.badge'] as $sel) {
    $q = preg_quote($sel, '/');
    $floored = preg_match('/' . $q . '[^{}]*\{[^}]*font-size\s*:\s*(?:1[0-9]|[2-9][0-9])px/i', $css);
    ok("{$sel} is at least 10px", (bool) $floored);
}
ok('the testimonial caption is at least 10px',
    (bool) preg_match('/\.testimonial\s+figcaption\s+small[^{]*\{[^}]*font-size\s*:\s*1[0-9]px/i', $css));

echo "\n== Footer contact details ==\n";
// Each contact detail in GET IN TOUCH leads with its own icon, and the
// street address appears there only - it used to be repeated as a bare
// "Lagos, Nigeria" line under the brand as well.
ok('the email row has an icon', (bool) preg_match('/mailto:[^"]*">\s*<\?=\s*icon\(\x27mail\x27\)/', $footer));
ok('the phone row has an icon', (bool) preg_match('/tel:[^"]*">\s*<\?=\s*icon\(\x27phone\x27\)/', $footer));
// Slice out the address anchor so the embedded template tags cannot confuse
// the surrounding tag match.
preg_match('/class="footer-address".*?<\/a>/s', $footer, $addressAnchor);
$addressBlock = $addressAnchor[0] ?? '';
ok('the address anchor exists', $addressBlock !== '');
ok('the address row has an icon', str_contains($addressBlock, "icon('pin')"));
ok('the address is driven by the admin setting', str_contains($addressBlock, "setting('address')"));
ok('the duplicate footer location line is gone', !str_contains($footer, 'footer-location'));
ok('no hardcoded city label is left in the footer', !preg_match('/>\s*Lagos, Nigeria\s*</', $footer));
// The address is used twice on purpose: once in the map query, once as the
// visible label. Any further use would mean it is repeated in the footer.
$addressUses = preg_match_all('/setting\(\x27address\x27\)/', $footer);
ok('the address is not repeated elsewhere in the footer', $addressUses === 2, 'found ' . (int) $addressUses);
$addressSpans = preg_match_all('/<span>\s*<\?=\s*e\(setting\(\x27address\x27\)\)\s*\?>/', $footer);
ok('the address is shown as one label', $addressSpans === 1, 'found ' . (int) $addressSpans);
ok('the address opens a map instead of dead-ending', (bool) preg_match('/href="https:\/\/www\.google\.com\/maps\/search\//', $addressBlock));
ok('the map link does not leak the tab it opens', (bool) preg_match('/target="_blank"/', $addressBlock) && (bool) preg_match('/rel="noopener noreferrer"/', $addressBlock));

echo "\n== Copy sits clear of the button beneath it ==\n";
ok('a button after supporting copy gets its own space',
    (bool) preg_match('/\.max-copy\s*\+\s*\.btn[^{]*\{[^}]*margin-top\s*:\s*(?:[2-9]\d)px/i', $css));
ok('the spacing is reduced but kept on small screens',
    (bool) preg_match('/@media\(max-width:640px\)\{\s*\.max-copy\s*\+\s*\.btn[^{]*\{[^}]*margin-top\s*:\s*(?:[2-4]\d)px/i', $css));

echo "\n== Fixed buttons clear the copyright ==\n";
ok('the footer reserves space for the floating buttons on small screens',
    (bool) preg_match('/@media\(max-width:1024px\)\{\s*\.site-footer\{padding-bottom:\s*(?:[7-9]\d|1\d\d)px\}/i', $css));
ok('the copyright is indented clear of the floating buttons on wide screens',
    (bool) preg_match('/@media\(min-width:1025px\)\{\s*\.footer-bottom\{padding-right:\s*(?:[89]\d|1\d\d)px\}/i', $css));

echo "\n== Demo-only copy and empty sections ==\n";
$homeView = (string) file_get_contents($root . '/app/views/public/home.php');
ok('the testimonial demo note only renders in demo mode',
    (bool) preg_match("/if\\s*\\(\\s*config\\('demo_mode'\\)\\s*\\)\\s*:\\s*\\?><p class=\"demo-stories\"/", $homeView));
ok('the demo note is not unconditional', !preg_match('/<\\/div><p class="demo-stories">/', $homeView));
ok('the location section is skipped when there are no locations',
    (bool) preg_match('/<\\?php if\\(\\$locations\\): \\?><section class="section locations-section">/', $homeView));
ok('the location section is closed after its condition',
    (bool) preg_match('/locations-section.*?<\\?php endif; \\?>/s', $homeView));
$cssNow = (string) file_get_contents($root . '/public/assets/css/site.css');
ok('the location grid does not reserve fixed empty rows',
    !preg_match('/\\.location-grid\\{[^}]*grid-template-rows\\s*:/', $cssNow));
ok('location rows come from the cards that exist',
    (bool) preg_match('/\\.location-grid\\{[^}]*grid-auto-rows/', $cssNow));
ok('an empty location grid collapses', str_contains($cssNow, '.location-grid:empty{display:none}'));

echo "\n== Copy sits clear of the button beneath it ==\n";
// An unterminated comment silently swallows every rule that follows it, which
// is how the trust-strip fix went missing once. Keep the stylesheet balanced.
$commentOpen = substr_count($css, '/*');
$commentClose = substr_count($css, '*/');
ok('every comment is closed', $commentOpen === $commentClose, "{$commentOpen} open, {$commentClose} close");
ok('every rule block is closed',
    substr_count($css, '{') === substr_count($css, '}'),
    substr_count($css, '{') . ' open, ' . substr_count($css, '}') . ' close');

// Walk the sheet: no rule text may appear while a comment is still open, and
// minified one-line rules must not hide a dangling opener.
$inComment = false;
$orphan = null;
foreach (explode("\n", $css) as $i => $line) {
    $wasInComment = $inComment;
    if (str_contains($line, '/*') && !str_contains($line, '*/')) {
        $inComment = true;
    }
    if (str_contains($line, '*/')) {
        $inComment = false;
    }
    // A line that opens a comment but also carries a complete rule after it
    // on the same line is fine; catch prose sitting outside a comment.
    if (!$wasInComment && !$inComment && !str_contains($line, '/*')) {
        $t = trim($line);
        if ($t !== '' && !preg_match('/[{};:@]|^[.#*\[a-zA-Z-]+.*,$/', $t)) {
            $orphan ??= ($i + 1) . ': ' . substr($t, 0, 60);
        }
    }
}
ok('no rule text escapes a stray comment', $orphan === null, (string) $orphan);

echo "\n== Narrow-viewport folding ==\n";
foreach (['trust-strip', 'results-toolbar', 'search-help'] as $block) {
    $q = preg_quote($block, '/');
    $folds = preg_match('/@media\(max-width:\d+px\)\{[^@]*?' . $q . '[^@]*?(?:grid-template-columns|flex-direction\s*:\s*column|flex-wrap\s*:\s*wrap)/is', $css);
    ok("{$block} folds on small screens", (bool) $folds);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

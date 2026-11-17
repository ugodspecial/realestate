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

echo "\n== Fixed buttons clear the copyright ==\n";
ok('the footer reserves space for the floating buttons on small screens',
    (bool) preg_match('/@media\(max-width:1024px\)\{\s*\.site-footer\{padding-bottom:\s*(?:[7-9]\d|1\d\d)px\}/i', $css));
ok('the copyright is indented clear of the floating buttons on wide screens',
    (bool) preg_match('/@media\(min-width:1025px\)\{\s*\.footer-bottom\{padding-right:\s*(?:[89]\d|1\d\d)px\}/i', $css));

echo "\n== Narrow-viewport folding ==\n";
foreach (['trust-strip', 'results-toolbar', 'search-help'] as $block) {
    $q = preg_quote($block, '/');
    $folds = preg_match('/@media\(max-width:\d+px\)\{[^@]*?' . $q . '[^@]*?(?:grid-template-columns|flex-direction\s*:\s*column|flex-wrap\s*:\s*wrap)/is', $css);
    ok("{$block} folds on small screens", (bool) $folds);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

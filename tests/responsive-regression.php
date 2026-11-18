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

echo "\n== Stylesheet structure ==\n";
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

echo "\n== Enquiry confirmation dialog ==\n";
$layoutView = (string) file_get_contents($root . '/app/views/layout.php');
$pubController = (string) file_get_contents($root . '/app/Core/PublicController.php');
$helpersSrc = (string) file_get_contents($root . '/app/Core/helpers.php');
$js = (string) file_get_contents($root . '/public/assets/js/site.js');

ok('a successful enquiry marks its flash as a dialog',
    (bool) preg_match("/flash\\('enquiry'/", $pubController));
ok('the dialog is only rendered for an enquiry flash', str_contains($layoutView, "'enquiry'"));
ok('other flashes still render as banners', str_contains($layoutView, 'bannerFlashes'));
ok('the dialog is a labelled modal',
    str_contains($layoutView, 'role="dialog"') && str_contains($layoutView, 'aria-modal="true"')
    && str_contains($layoutView, 'aria-labelledby="enquiryDialogTitle"'));
ok('the dialog can be dismissed by an Okay button', str_contains($layoutView, 'confirm-dialog-ok'));
ok('the dialog has an X close control', str_contains($layoutView, 'confirm-dialog-close'));
ok('the X is labelled for assistive tech',
    (bool) preg_match('/confirm-dialog-close" data-dialog-dismiss aria-label="[^"]+"/', $layoutView));
ok('both controls share the dismiss hook', substr_count($layoutView, 'data-dialog-dismiss') >= 3);
ok('the reference is split out for display', str_contains($helpersSrc, 'function enquiry_flash_parts'));
ok('the reference helper falls back without a reference', str_contains($helpersSrc, "'reference' => ''"));
ok('the dialog transitions its opacity',
    (bool) preg_match('/\.confirm-dialog\{[^}]*transition:[^}]*opacity/s', $css));
ok('the closed dialog is hidden from the tab order and screen readers',
    (bool) preg_match('/\.confirm-dialog\{[^}]*visibility:hidden/s', $css));
ok('the open state restores visibility', str_contains($css, '.confirm-dialog.is-open'));
ok('the panel becomes a bottom sheet on phones',
    (bool) preg_match('/@media\(max-width:640px\)\{[^}]*\.confirm-dialog\{[^}]*align-items:flex-end/s', $css));
ok('reduced motion disables the transition',
    (bool) preg_match('/prefers-reduced-motion[^}]*\{[^}]*\.confirm-dialog[^}]*transition:none/s', $css));
ok('the close button meets the tap-target floor',
    (bool) preg_match('/\.confirm-dialog-close\{[^}]*width:40px[^}]*height:40px/s', $css));
ok('the script opens the dialog on load', (bool) preg_match("/getElementById\(.enquiryDialog.\)/", $js));
ok('Escape closes it', str_contains($js, "'Escape'"));
ok('focus is moved into the dialog', str_contains($js, 'first.focus()'));
ok('focus is trapped while open', str_contains($js, "e.key!=='Tab'"));
ok('scroll is locked while open', str_contains($js, "classList.add('dialog-open')"));
ok('the script waits for load before moving focus', str_contains($js, "'complete'"));

echo "\n== Account lifecycle guards ==\n";
$adminSrc = (string) file_get_contents($root . '/app/Core/AdminController.php');
$authSrc = (string) file_get_contents($root . '/app/Core/Auth.php');
$usersIndex = (string) file_get_contents($root . '/app/views/admin/users/index.php');
$usersForm = (string) file_get_contents($root . '/app/views/admin/users/form.php');

ok('sign-in refuses an inactive account', (bool) preg_match('/!\$user\[.active.\]\s*\)\s*return false/', $authSrc));
ok('an expired session is re-checked against the account',
    str_contains($authSrc, "WHERE id = ?") && str_contains($authSrc, "!\$user['active']"));
ok('deactivation bumps the session version so open tabs die',
    (bool) preg_match("/session_version.\]\s*=\s*\(int\)Database::value/", $adminSrc));
ok('an admin cannot deactivate themselves', str_contains($adminSrc, 'deactivate your own account'));
ok('the last active super administrator is protected',
    (bool) preg_match("/role='super_admin' AND active=1 AND id<>\?/", $adminSrc));
ok('only a super administrator can grant that role',
    str_contains($adminSrc, 'Only a Super Administrator can grant that role'));
ok('the rule covers both creating and editing', substr_count($adminSrc, 'Only a Super Administrator can grant that role') === 2);
ok('the staff list shows active versus deactivated',
    str_contains($usersIndex, "'Deactivated'"));
// The button name is chosen at render time from the account's current state.
ok('the edit screen offers deactivate and reactivate',
    str_contains($usersForm, "'deactivate' : 'reactivate'"));
ok('the deactivate control asks for confirmation', str_contains($usersForm, 'data-confirm'));

echo "\n== Password lifecycle guards ==\n";
$resetSrc = (string) file_get_contents($root . '/app/Core/PasswordReset.php');
$publicSrc = (string) file_get_contents($root . '/app/Core/PublicController.php');
$routesSrc = (string) file_get_contents($root . '/app/routes.php');
$loginView = (string) file_get_contents($root . '/app/views/auth/login.php');

// Recovery has to be reachable while signed out, so it must sit outside the
// admin dispatch that calls Auth::require().
ok('forgot-password is routed outside the admin area', str_contains($routesSrc, "PublicController::forgotPassword"));
ok('reset-password is routed outside the admin area', str_contains($routesSrc, "PublicController::resetPassword"));
ok('the forced change screen is routed', str_contains($routesSrc, "forcePasswordChange"));
ok('the sign-in page offers password recovery', str_contains($loginView, "forgot-password"));

// An administrator-issued password must never become the permanent one.
ok('signed-in staff are stopped while a change is pending',
    (bool) preg_match('/mustChange\(\$user\)\)\s*redirect\(/', $authSrc));
ok('setting a password by hand flags a change', str_contains($adminSrc, "'must_change_password' => 1"));
ok('the staff reset issues a forced change', str_contains($adminSrc, 'PasswordReset::issueTemporary'));
ok('clearing the flag happens when the user chooses their own',
    str_contains($resetSrc, "'must_change_password' => 0"));

// Token handling. These are the parts that are easy to get wrong and quiet
// when they are.
ok('only a hash of the token is stored', str_contains($resetSrc, "hash('sha256', \$token)"));
ok('a token is spent exactly once', str_contains($resetSrc, 'AND used_at IS NULL'));
ok('expiry compares timestamps without a timezone shift',
    str_contains($resetSrc, '(string) $row[\'expires_at\'] <= Database::now()'));
ok('resetting bumps the session version', str_contains($resetSrc, "'session_version' => \$current + 1"));
ok('an inactive account cannot request a reset', (bool) preg_match('/!\$user\s*\|\|\s*!\$user\[.active.\]/', $resetSrc));
ok('a new request replaces the previous token', str_contains($resetSrc, 'DELETE FROM password_resets WHERE user_id'));

// The submitted form carries the token in the body. Reading it only from the
// query string silently rejected every real submission; this is the guard.
ok('the reset handler reads the token from the submitted form',
    (bool) preg_match('/\$method === .POST. \? \(string\) input\(.token.\)/', $publicSrc));
ok('passwords are validated in one place', str_contains($resetSrc, 'public static function validate'));

// The user must be told nothing about whether an address has an account.
ok('recovery answers every address the same way',
    str_contains($publicSrc, 'If that email address belongs to a staff account'));

// Touch targets. The recovery link sits next to the sign-in button and is the
// one control a locked-out visitor on a phone must be able to hit.
$cssSrc = (string) file_get_contents($root . '/public/assets/css/site.css');
ok('the recovery link has a full-height tap target',
    (bool) preg_match('/\.login-links a\{[^}]*min-height:44px/', $cssSrc));
ok('the sign-out control is not styled as an uppercase CTA',
    str_contains($cssSrc, '.login-signout-link{') && !preg_match('/\.login-signout[^{]*\.text-link/', $cssSrc));
// The mobile panel stacked its contents with space-between inside a fixed
// min-height, which overlapped the wordmark with the eyebrow.
ok('the stacked auth panel spaces its contents instead of overlapping',
    (bool) preg_match('/\.login-visual\{gap:\d+px/', $cssSrc));
ok('the sign-in page does not link back to itself',
    str_contains((string) file_get_contents($root . '/app/views/auth/shell.php'), 'hideBackLink'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);



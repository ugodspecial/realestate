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

echo "\n== Header logo is a real tap target ==\n";
// The logo is the most-used link on a phone and its box was only as tall as the
// artwork (37-42px, 35-39px once scrolled). It is a centred flex row in a
// centred bar, so a min-height grows the target without moving the artwork.
ok('the header logo has a 48px minimum height on phones and tablets',
    (bool) preg_match('/@media\(max-width:1024px\)\{\s*\.site-header \.brand\{min-height:\s*(?:4[8-9]|[5-9]\d)px\}\s*\}/', $css));
ok('the logo link stays a vertically centred flex row, so the extra height is symmetric',
    (bool) preg_match('/\.brand\{[^}]*display:(?:inline-)?flex[^}]*align-items:center/', $css));

echo "\n== Category band is centred ==\n";
// `margin-inline:auto` cannot centre a box wider than its container: the auto
// margins resolve to 0 and the box sits flush-left, spilling to the right. The
// 1720px/94vw band IS wider than the 1240px container above ~1320px, so on a
// 1440px screen it ran 14px off the edge and at 1920px lost half a tile.
preg_match('/\.category-grid-wrap\{([^}]*)\}/', $css, $band);
$bandRule = $band[1] ?? '';
ok('the category band rule exists', $bandRule !== '');
ok('the band is positioned from its own centre line',
    (bool) preg_match('/margin-inline:\s*calc\(\s*50%\s*-\s*var\(--band\)\s*\/\s*2\s*\)/', $bandRule));
ok('the band does not rely on auto margins', !preg_match('/margin-inline:\s*auto/', $bandRule));
ok('the band width is one variable, so width and margin cannot drift apart',
    (bool) preg_match('/--band:\s*min\(1720px,\s*94vw\)/', $bandRule) && (bool) preg_match('/width:\s*var\(--band\)/', $bandRule));

echo "\n== Category tiles ==\n";
// The home tiles were reported broken at 423px and 1568px: the "N properties" footer
// rendered in the number row on top of the icon, a 16-28px grid gap broke every
// divider, and the fifth tile sat alone with a hole beside it. Each pin below
// guards the cause of one of those, and the simulation proves no category count
// can leave a hole.
$home    = (string) file_get_contents($root . '/app/views/public/home.php');
$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');
$tilePos = strrpos($css, 'CATEGORY TILES — footer placement');
$tiles   = $tilePos === false ? '' : substr($css, $tilePos);
ok('the tile block is in the stylesheet', $tiles !== '' && strlen($tiles) > 1500);
// Declarations of the first top-level rule with exactly this selector, comments
// removed so that prose explaining a declaration can never satisfy a pin for it.
$body = static function (string $selector) use ($tiles): string {
    $found = preg_match('/(?:^|[}\/])\s*' . preg_quote($selector, '/') . '\s*\{([^{}]*)\}/', $tiles, $m);
    return $found ? (string) preg_replace('~/\*.*?\*/~s', '', $m[1]) : '';
};
$wrap = $body('.category-grid-wrap');
$grid = $body('.category-grid');
$card = $body('.category-card');

echo "-- placement --\n";
// The footer had no grid-row, so auto-placement dropped it into the icon's cell.
ok('the footer has its own grid row, so it cannot auto-place into the number row',
    (bool) preg_match('/grid-area:\s*4\s*\/\s*1\s*\/\s*5\s*\/\s*-1/', $body('.category-bottom')));
ok('the footer is pinned to the bottom of its row', (bool) preg_match('/align-self:\s*end/', $body('.category-bottom')));
ok('the footer never wraps or splits the count from its arrow', (bool) preg_match('/white-space:\s*nowrap/', $body('.category-bottom')));
// An absolutely positioned child occupies no grid cell, so the icon must be static.
$icon = $body('.category-icon');
ok('the icon is a grid item in the number row', (bool) preg_match('/grid-area:\s*1\s*\/\s*2\b/', $icon));
ok('the icon is not absolutely positioned (that took it out of the grid)', (bool) preg_match('/position:\s*static/', $icon));
ok('the number is placed in row 1, column 1', (bool) preg_match('/grid-area:\s*1\s*\/\s*1\b/', $body('.category-number')));
ok('the label spans both columns of row 2', (bool) preg_match('/grid-area:\s*2\s*\/\s*1\s*\/\s*3\s*\/\s*-1/', $body('.category-label-row')));
ok('a spacer row absorbs spare height, which pins the footer to the bottom',
    (bool) preg_match('/grid-template-rows:\s*auto\s+auto\s+1fr\s+auto/', $card));

echo "-- panel and dividers --\n";
ok('the tiles touch: the grid has no gap (the shared 16-28px rule broke every divider)', (bool) preg_match('/\bgap:\s*0\b/', $grid));
ok('each tile draws its own right and bottom hairline',
    (bool) preg_match('/box-shadow:\s*inset\s+-1px\s+0\s+0\s+var\(--line\),\s*inset\s+0\s+-1px\s+0\s+var\(--line\)/', $card));
ok('the legacy tile borders are retired in one place', (bool) preg_match('/border:\s*0\s*!important/', $card));
ok('the panel, not the grid, carries the border, the radius and the clipping',
    (bool) preg_match('/border:\s*1px\s+solid/', $wrap) && (bool) preg_match('/border-radius:/', $wrap) && (bool) preg_match('/overflow:\s*hidden/', $wrap)
    && !preg_match('/overflow:\s*hidden/', $grid));
ok('the grid is pulled 1px past the panel edge, so the outer hairlines are clipped', (bool) preg_match('/margin:\s*0\s+-1px\s+-1px\s+0/', $grid));
ok('the panel clips composited children to its rounded corners in WebKit', (bool) preg_match('/isolation:\s*isolate/', $wrap));

echo "-- sizing and rows --\n";
ok('tiles sit on a 60-track grid (divisible by 5, 3, 2 and 1 columns, and by 1 to 4 left over)',
    (bool) preg_match('/grid-template-columns:\s*repeat\(60,\s*minmax\(0,\s*1fr\)\)/', $grid));
// An older compact-list rule declared an explicit first row, which made row 1 content-sized.
ok('every row is `1fr`, so no row is taller than another (and the older explicit first row is retired)',
    (bool) preg_match('/grid-auto-rows:\s*1fr/', $grid) && (bool) preg_match('/grid-template-rows:\s*none/', $grid));
ok('a tile spans its share of the 60 tracks', (bool) preg_match('/grid-column:\s*span\s+var\(--span\)/', $card));
ok('tile height is a minimum from the tile width, not an aspect-ratio (a widened tile must not grow taller)',
    (bool) preg_match('/min-height:\s*calc\(var\(--tile\)\s*\*\s*var\(--ratio\)\)/', $card) && (bool) preg_match('/aspect-ratio:\s*auto/', $card));
ok('type, icon and padding are fractions of one tile width, as in the reference',
    (bool) preg_match('/--tile:\s*calc\(var\(--band\)\s*\/\s*var\(--cols\)\)/', $wrap)
    && (bool) preg_match('/font:[^;]*clamp\([^;]*var\(--tile\)\s*\*\s*\.125/', $body('.category-card h3')));

echo "-- the ladder --\n";
ok('five columns by default', (bool) preg_match('/--cols:\s*5\b/', $wrap) && (bool) preg_match('/--span:\s*12\b/', $wrap));
ok('three columns from 641px to 1024px',
    (bool) preg_match('/@media\(min-width:641px\) and \(max-width:1024px\)\{\s*\.category-grid-wrap\{--cols:3;--span:20\}/', $tiles));
ok('two columns from 401px to 640px',
    (bool) preg_match('/@media\(min-width:401px\) and \(max-width:640px\)\{\s*\.category-grid-wrap\{--cols:2;--span:30\}/', $tiles));
ok('one compact row per tile at 400px and below',
    (bool) preg_match('/@media\(max-width:400px\)\{[^@]*?\.category-grid-wrap\{--cols:1;--span:60\}/s', $tiles)
    && (bool) preg_match('/grid-template-columns:\s*calc\(var\(--num\)\s*\*\s*1\.5\)\s+minmax\(0,1fr\)\s+auto/', $tiles));
ok('the compact row puts the count under its label, at row 2 column 2',
    (bool) preg_match('/@media\(max-width:400px\)\{.*\.category-bottom\{grid-area:\s*2\s*\/\s*2/s', $tiles));

// Simulate the stylesheet. For every number of categories an admin could have,
// the tiles of every row must add up to exactly 60 tracks: no row short (a hole)
// and no tile pushed to the next row (a gap). Rules are read from the CSS itself.
$simulate = static function (int $cols, string $inner, int $base): array {
    $rules = [];
    preg_match_all('/((?:\s*\.category-card:[^,{]+,?)+)\{--span:(\d+)\}/', $inner, $groups, PREG_SET_ORDER);
    foreach ($groups as $g) {
        foreach (explode(',', $g[1]) as $sel) {
            if (preg_match('/:(last-child|nth-last-child\((\d+)\)):nth-child\((\d+)n\+(\d+)\)/', $sel, $m)) {
                $rules[] = [
                    'fromEnd' => $m[1] === 'last-child' ? 1 : (int) $m[2],
                    'step'    => (int) $m[3],
                    'offset'  => (int) $m[4],
                    'span'    => (int) $g[2],
                ];
            }
        }
    }
    $errors = [];
    for ($n = 1; $n <= 40; $n++) {
        $sum = 0;
        for ($i = 1; $i <= $n; $i++) {
            $span = $base;
            foreach ($rules as $r) {
                if ($i === $n - $r['fromEnd'] + 1 && $i >= $r['offset'] && ($i - $r['offset']) % $r['step'] === 0) {
                    $span = $r['span'];
                }
            }
            $sum += $span;
            if ($sum > 60) { $errors[] = "{$n} tiles: tile {$i} overflows its row ({$sum} tracks)"; break; }
            if ($sum === 60) { $sum = 0; }
        }
        if ($sum !== 0 && !$errors) { $errors[] = "{$n} tiles: the last row is short ({$sum} of 60 tracks) - a hole"; }
        if (count($errors) >= 3) break;
    }
    return [count($rules), $errors];
};
foreach ([
    5 => ['/@media\(min-width:1025px\)\{(.*?)\n\}/s', 12],
    3 => ['/@media\(min-width:641px\) and \(max-width:1024px\)\{(.*?)\n\}/s', 20],
    2 => ['/@media\(min-width:401px\) and \(max-width:640px\)\{(.*?)\n\}/s', 30],
] as $cols => [$re, $base]) {
    $inner = preg_match($re, $tiles, $mm) ? $mm[1] : '';
    [$ruleCount, $errors] = $simulate($cols, $inner, $base);
    ok("{$cols} columns: the left-over tiles of an incomplete last row fill it, for 1 to 40 categories",
        $ruleCount === $cols * ($cols - 1) / 2 && $errors === [], ($ruleCount === $cols * ($cols - 1) / 2 ? '' : "found {$ruleCount} orphan selectors; ") . implode('; ', $errors));
}

echo "-- hover and reduced motion --\n";
ok('hover is still the green wash with a white label',
    (bool) preg_match('/\.category-card:hover\{background:var\(--green\)\}/', $css) && (bool) preg_match('/\.category-card:hover h3\{color:#fff\}/', $css));
// The older reset sat above an unconditional rule with the same specificity, so it never applied.
ok('with reduced motion the arrow does not nudge on hover',
    (bool) preg_match('/@media\(prefers-reduced-motion:reduce\)\{\s*\.category-card:hover \.category-bottom \.icon\{transform:none\}\s*\}/', $tiles));
ok('a keyboard-focused tile has a visible ring that sits inside it (the panel clips anything outside)',
    (bool) preg_match('/\.category-card:focus-visible\{outline:\s*2px solid[^}]*outline-offset:\s*-\d+px/', $tiles));

echo "-- markup --\n";
ok('property_count_label exists', (bool) preg_match('/function property_count_label\(int \$total, bool \$available = false\): string/', $helpers));
ok('the tile count goes through property_count_label, so one listing reads "1 property"',
    str_contains($home, "property_count_label((int)\$c['total'])"));
ok('the location cards use the same helper', str_contains($home, "property_count_label((int)\$l['total'], true)"));
ok('no card still prints a hard-coded plural after a number',
    !preg_match('/\(int\)\s*\$[cl]\[.total.\]\s*\?>\s*(?:available\s+)?properties/', $home));
ok('the decorative ghost number is hidden from assistive tech and always has two digits',
    (bool) preg_match('/class="category-number" aria-hidden="true"><\?= sprintf\(.%02d., \$i \+ 1\)/', $home));
ok('the section index follows the number of categories instead of a fixed 05',
    str_contains($home, "sprintf('01 — %02d', count(\$categories))") && !str_contains($home, '01 — 05'));
ok('with no categories the section is not rendered at all',
    (bool) preg_match('/<\?php if\(\$categories\): \?><section class="categories-section/', $home) && str_contains($home, '</section><?php endif; ?>'));

echo "\n== Footer columns on phones and tablets ==\n";
// Explore and Our company sit side by side below 1025px. They used to stack into
// one column on phones and, on tablets, were each paired with something else
// (brand | Explore, Company | Contact), which separated the two link lists.
$posExplore = strpos($footer, 'footer-explore');
$posCompany = strpos($footer, 'footer-company');
$posContact = strpos($footer, 'footer-contact');
ok('the footer marks its three link columns', $posExplore !== false && $posCompany !== false && $posContact !== false);
ok('Explore comes first, then Our company, then Get in touch',
    $posExplore !== false && $posCompany > $posExplore && $posContact > $posCompany);
ok('the footer columns carry the right headings',
    (bool) preg_match('/footer-explore"><h3>EXPLORE/', $footer)
    && (bool) preg_match('/footer-company"><h3>OUR COMPANY/', $footer)
    && (bool) preg_match('/footer-contact"><h3>GET IN TOUCH/', $footer));
ok('the two link lists are two equal columns at tablet and phone widths',
    (bool) preg_match('/@media\(max-width:1024px\)\{\s*\.footer-grid\{\s*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/', $css));
ok('the brand and the contact details span both columns',
    (bool) preg_match('/\.footer-grid>\.footer-brand-col,\s*\.footer-grid>\.footer-contact\{grid-column:\s*1\s*\/\s*-1\}/', $css));
ok('the grid is not collapsed to one column again below 641px',
    !preg_match('/@media\(max-width:(?:640|600|480|420|400)px\)\{[^@]*?\.footer-grid\{[^}]*grid-template-columns:\s*1fr[;}]/', substr($css, (int) strrpos($css, 'FOOTER — phones and tablets'))));
ok('each footer link row is a comfortable tap target',
    (bool) preg_match('/\.footer-grid>\.footer-col>a:not\(\.brand\)\{[^}]*min-height:\s*(?:4[4-9]|[5-9]\d)px/', $css));

echo "\n== Footer bottom row on phones and tablets ==\n";
ok('Privacy policy and Terms of use are a two-column row',
    (bool) preg_match('/\.footer-bottom\s+\.footer-legal-links\{[^}]*display:\s*grid[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/', $css));
ok('each legal link is a full-height tap target',
    (bool) preg_match('/\.footer-bottom\s+\.footer-legal-links\s+a\{[^}]*min-height:\s*(?:4[4-9]|[5-9]\d)px/', $css));
ok('the bottom row is centred',
    (bool) preg_match('/\.footer-bottom\{[^}]*flex-direction:\s*column[^}]*align-items:\s*center[^}]*text-align:\s*center/', $css));
ok('the footer note and the copyright are centred',
    (bool) preg_match('/\.footer-note,\s*\.footer-bottom\s+\.footer-copyright\{[^}]*text-align:\s*center/', $css));
ok('the copyright no longer pushes itself right with an auto margin on phones',
    (bool) preg_match('/\.footer-note,\s*\.footer-bottom\s+\.footer-copyright\{[^}]*margin:\s*0[;}]/', $css));
// The reserve must clear BOTH floating controls, or the centred copyright runs
// under the back-to-top button (it did at 360-390px with the old 84px).
preg_match('/@media\(max-width:1024px\)\{\s*body\{padding-bottom:0\}.*?\.site-footer\{padding-bottom:\s*(\d+)px\}.*?\.back-top\{right:16px;bottom:(\d+)px\}/s', $css, $reserve);
preg_match('/\.back-top\{[^}]*?width:\s*(\d+)px;\s*height:\s*(\d+)px/s', $css, $backTop);
$reservePx = (int) ($reserve[1] ?? 0);
$backTopTop = (int) ($reserve[2] ?? 0) + (int) ($backTop[2] ?? 999);
ok('the footer reserve is at least as tall as the back-to-top button sits',
    $reservePx >= $backTopTop + 8, "reserve {$reservePx}px, button top edge {$backTopTop}px");

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

echo "\n== Paragraph measure ==\n";
// Paragraph text looked narrow and the sections unbalanced: a dozen layers of px and ch
// caps, each far below its column, left the text at 36-77% of it. One rule now caps every
// section's paragraphs at --measure (they fill their column up to it), and the few places
// that fought it are pinned below. The slice starts at the comment that opens the block,
// and comments are removed, so prose can never satisfy a pin.
$pmMark = strrpos($css, 'PARAGRAPH MEASURE (1.8.9)');
$pmOpen = $pmMark === false ? false : strrpos(substr($css, 0, $pmMark), '/*');
$pm = $pmOpen === false ? '' : (string) preg_replace('~/\*.*?\*/~s', '', substr($css, $pmOpen));
ok('the paragraph-measure block is in the stylesheet (rules, not just its comment)', strlen($pm) > 500);
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $pm, $pmRules, PREG_SET_ORDER);
$pmSel = static fn (string $sel): array => array_map(static fn ($x) => preg_replace('/\s+/', ' ', trim($x)), explode(',', $sel));

// One measure, in ch so it follows each paragraph's own font size.
ok('--measure is one length in ch, between 70 and 100ch (about 680-770px for 14-16px text)',
    (bool) preg_match('/:root\s*\{[^{}]*--measure:\s*(\d+)ch\s*;/', $pm, $mm) && (int) $mm[1] >= 70 && (int) $mm[1] <= 100);
ok('--measure is defined once', substr_count($pm, '--measure:') === 1);

// Every paragraph that used to carry its own narrow cap.
$capped = ['.section-heading p', '.section-heading h2 + p', '.listing-page-heading .section-heading p', '.max-copy',
    '.why-list p', '.process-list p', '.contact-copy > p', '.contact-information > p', '.footer-brand-col p',
    '.service-detail p', '.service-detail > div > p', '.property-description p', '.property-enquiry-section > div > p', '.faq-list details p',
    '.about-copy p', '.values-grid p', '.service-card p'];
$measureSel = [];
foreach ($pmRules as $r) {
    if (preg_match('/max-width:\s*var\(--measure\)/', $r[2])) {
        $measureSel = array_merge($measureSel, $pmSel($r[1]));
    }
}
$missing = array_values(array_diff($capped, $measureSel));
ok('every section paragraph is capped by --measure, and by nothing else', $missing === [], implode(' | ', $missing));

// A later rule that caps one of them again (the way the old layers did) would silently
// beat the measure, so nothing in or after the block may.
$recap = [];
foreach ($pmRules as $r) {
    if (!preg_match('/(?<![\w-])max-width:\s*([^;}]+)/', $r[2], $mw) || trim($mw[1]) === 'var(--measure)') {
        continue;
    }
    $hit = array_intersect($pmSel($r[1]), $capped);
    if ($hit !== []) {
        $recap[] = implode(',', $hit) . ' => ' . trim($mw[1]);
    }
}
ok('no later rule re-caps those paragraphs at a fixed width', $recap === [], implode(' | ', $recap));

// The Why section's whole left block sat in a 44ch (346px) box inside a 590px column.
$whyBody = '';
foreach ($pmRules as $r) {
    if (preg_replace('/\s+/', ' ', trim($r[1])) === '.why-section .split-layout > div:first-child') {
        $whyBody = $r[2];
    }
}
ok('the Why section text block fills its column (it was held to 44ch inside it)', (bool) preg_match('/max-width:\s*none/', $whyBody));

// A heading and its intro on one row: with a wider intro the row overflows, and flex then
// shrinks every item in proportion, which squeezed the heading to four lines in 144px.
ok('the heading row: the intro grows from zero into what the heading leaves free (so it cannot squeeze the heading)',
    (bool) preg_match('/@media\s*\(min-width:\s*641px\)\s*\{\s*\.section-heading\s*>\s*p\s*\{[^{}]*flex:\s*1\s+1\s+0\b/', $pm));
ok('the heading row: the intro keeps a floor, so it can never collapse beside a long heading',
    (bool) preg_match('/\.section-heading\s*>\s*p\s*\{[^{}]*min-width:\s*min\(\s*18rem\s*,\s*100%\s*\)/', $pm));
ok('the heading row rule applies to the row layout only (a flex-basis in the stacked column would become a height)',
    (bool) preg_match('/@media\s*\(min-width:\s*641px\)\s*\{\s*\.section-heading\s*>\s*p\s*\{/', $pm));

// The hero: the lead was a fixed 490px, the assurance line a fixed 430px (it wrapped, "VALUE."
// alone on row two).
$heroLead = '';
$heroLine = '';
foreach ($pmRules as $r) {
    $sel = preg_replace('/\s+/', ' ', trim($r[1]));
    if ($sel === '.hero-copy > p') {
        $heroLead = $r[2];
    }
    if ($sel === '.hero-assurance') {
        $heroLine = $r[2];
    }
}
ok('the hero lead is capped in ch, so it follows its font size', (bool) preg_match('/max-width:\s*min\(\s*\d+ch\s*,\s*100%\s*\)/', $heroLead));
ok('the hero lead breaks evenly (its two sentences each take a row)', (bool) preg_match('/text-wrap:\s*balance/', $heroLead));
ok('the hero assurance line takes the width of its text instead of a fixed 430px',
    (bool) preg_match('/width:\s*fit-content/', $heroLine) && (bool) preg_match('/max-width:\s*100%/', $heroLine) && !preg_match('/\b\d+px/', $heroLine));

// Simulation: what the measure does to a paragraph of any font size in a column of any width.
// Text fills min(column, measure); at 14-16px the measure is 680-770px, so columns up to that
// are used in full and wider ones are never asked to carry a line past ~105 characters.
$chPx = static fn (float $fs): float => $fs * 0.5625;   // 1ch of the body face, measured in Chromium
$measureCh = isset($mm[1]) ? (int) $mm[1] : 0;
$fillOk = true;
$lineOk = true;
foreach ([14, 15, 16] as $fs) {
    foreach ([250, 320, 380, 430, 520, 590, 680, 707, 868, 942, 1045, 1240] as $col) {
        $w = min($col, $measureCh * $chPx($fs));
        $fillOk = $fillOk && $w >= min($col, 600);            // a column up to 600px is used in full
        $lineOk = $lineOk && $w / ($fs * 0.445) <= 125;       // average glyph 0.445em: about 125 characters at most
    }
}
ok('simulation: a column of up to 600px is filled by its paragraph at 14, 15 and 16px', $fillOk);
ok('simulation: no paragraph runs past about 125 characters per line at 14, 15 or 16px', $lineOk);

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

// The refusal may be a single guard or part of a compound condition that
// logs the attempt first; what matters is that an inactive account is refused.
ok('sign-in refuses an inactive account', (bool) preg_match('/!\$user\[.active.\]/', $authSrc));
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

echo "\n== Upgrades must not take the site down ==\n";
$routesSrc2 = (string) file_get_contents($root . '/app/routes.php');
// A new column may not be assumed to exist: an installation that has not run
// the upgrade yet must keep working. This caused a real 500 on every sign-in.
ok('the session query asks the schema before using the new column',
    str_contains($authSrc, 'hasPasswordLifecycle'));
ok('there is a fallback column list for older databases',
    str_contains($authSrc, "0 AS must_change_password"));
ok('writes pass through a guard', str_contains($resetSrc, 'public static function guardFlag'));
ok('the staff create path uses the guard', str_contains($adminSrc, 'PasswordReset::guardFlag'));
// An opaque 500 is not an acceptable answer to a half-finished upgrade.
ok('a missing column tells the operator to upgrade',
    str_contains($routesSrc2, 'bin/upgrade.php'));

echo "\n== Permission and audit guards ==\n";
$dashView = (string) file_get_contents($root . '/app/views/admin/dashboard.php');
// One dashboard serves four roles. A panel must never reach a role that
// cannot open the page it links to.
ok('the dashboard gates the enquiry panel', str_contains($dashView, 'if ($recentEnquiries)'));
ok('the dashboard gates the audit panel', str_contains($dashView, 'if ($recentAudit)'));
ok('enquiry data is only queried with permission',
    (bool) preg_match('/Auth::can\(.enquiries\.manage.\)/', $adminSrc));
ok('audit data is only queried with permission',
    (bool) preg_match('/Auth::can\(.audit\.view.\)/', $adminSrc));

// Editing a live listing must not let a non-approver change what the public
// sees without review.
ok('a non-approver edit returns a live listing to the queue',
    str_contains($adminSrc, "\$data['publish_status'] = 'pending';") && str_contains($adminSrc, 'returnedToQueue'));
ok('an edit clears the previous approver', str_contains($adminSrc, "\$data['approved_by'] = null;"));

// Security logging.
ok('a refused sign-in is recorded', str_contains($authSrc, 'user.login_failed'));
ok('a sign-out is recorded', str_contains($authSrc, 'user.logout'));
ok('the failed-sign-in entry never includes the password',
    !preg_match('/logFailedSignIn\([^)]*\$password/', $authSrc));
ok('housekeeping exists', is_file($root . '/bin/maintenance.php'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);



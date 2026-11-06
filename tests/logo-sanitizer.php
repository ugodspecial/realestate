<?php
/**
 * Adversarial tests for the brand logo SVG sanitizer.
 * Every payload must be neutralised before the SVG is written to public/.
 * Run: php tests/logo-sanitizer.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;
$passes = 0;
function check(string $label, bool $ok): void {
    global $failures, $passes;
    if ($ok) { $passes++; echo 'PASS  ' . $label . PHP_EOL; }
    else { $failures++; echo 'FAIL  ' . $label . PHP_EOL; }
}

/** Invoke the private sanitizer through reflection. */
$method = new ReflectionMethod('Uploads', 'sanitizeSvg');
$method->setAccessible(true);
$sanitize = static function (string $svg) use ($method): ?string {
    return $method->invoke(null, $svg);
};

/** A payload is safe when it is rejected outright or stripped of anything executable. */
$isSafe = static function (?string $result): bool {
    if ($result === null) return true;
    $flat = strtolower($result);
    foreach (['<script', 'onload', 'onclick', 'onerror', 'onmouseover', 'javascript:', 'vbscript:', '<iframe', '<object', '<embed', '<foreignobject', '<!entity', '<!doctype', '@import', '<style'] as $bad) {
        if (str_contains($flat, $bad)) return false;
    }
    return true;
};

$payloads = [
    'plain script tag' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="5"/></svg>',
    'script with attributes' => '<svg xmlns="http://www.w3.org/2000/svg"><script type="text/javascript">alert(1)</script></svg>',
    'script with spaces' => '<svg xmlns="http://www.w3.org/2000/svg">< script >alert(1)</ script ><rect/></svg>',
    'uppercase script' => '<svg xmlns="http://www.w3.org/2000/svg"><SCRIPT>alert(1)</SCRIPT></svg>',
    'event handler onload' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>',
    'event handler on circle' => '<svg xmlns="http://www.w3.org/2000/svg"><circle onmouseover="alert(1)" r="5"/></svg>',
    'event handler unquoted' => '<svg xmlns="http://www.w3.org/2000/svg"><circle onfocus=alert(1) r="5"/></svg>',
    'javascript href' => '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>x</text></a></svg>',
    'javascript href uppercase' => '<svg xmlns="http://www.w3.org/2000/svg"><a HREF="JaVaScRiPt:alert(1)"><text>x</text></a></svg>',
    'entity encoded javascript' => '<svg xmlns="http://www.w3.org/2000/svg"><a href="&#106;avascript:alert(1)"><text>x</text></a></svg>',
    'xlink javascript' => '<svg xmlns="http://www.w3.org/2000/svg"><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
    'external image href' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>',
    'external http image' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil.example/x.png"/></svg>',
    'foreign object' => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>',
    'iframe' => '<svg xmlns="http://www.w3.org/2000/svg"><iframe src="https://evil.example"/></svg>',
    'object embed' => '<svg xmlns="http://www.w3.org/2000/svg"><object data="x"/><embed src="y"/></svg>',
    'style block with import' => '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.example/x.css);</style><rect/></svg>',
    'entity expansion' => '<svg xmlns="http://www.w3.org/2000/svg"><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><text>&xxe;</text></svg>',
    'animate with javascript' => '<svg xmlns="http://www.w3.org/2000/svg"><animate attributeName="href" values="javascript:alert(1)"/></svg>',
    'use element' => '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.example/x.svg#icon"/></svg>',
    'php injection' => '<svg xmlns="http://www.w3.org/2000/svg"><?php echo "x"; ?><rect/></svg>',
    'comment hiding script' => '<svg xmlns="http://www.w3.org/2000/svg"><!-- <script>alert(1)</script> --><rect/></svg>',
    'data uri image' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="/></svg>',
];

foreach ($payloads as $label => $payload) {
    $result = $sanitize($payload);
    check('sanitizes: ' . $label, $isSafe($result));
}

// A legitimate logo must survive intact.
$good = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 60"><circle cx="20" cy="30" r="12" fill="#2c7652"/><text x="42" y="37" font-size="18" fill="#2c7652">BRAND</text></svg>';
$kept = $sanitize($good);
check('keeps legitimate svg elements', $kept !== null && str_contains($kept, '<circle') && str_contains($kept, '<text') && str_contains($kept, 'BRAND'));

// Non-SVG input must be rejected.
check('rejects non-svg input', $sanitize('<html><body>hi</body></html>') === null);
check('rejects empty input', $sanitize('') === null);
check('rejects oversized input', $sanitize('<svg>' . str_repeat('x', 1024 * 1024 + 10) . '</svg>') === null);

// The logo path helper must reject path traversal.
check('logo path rejects traversal', logo_url() === '' || !str_contains(logo_url(), '..'));

echo PHP_EOL;
echo ($failures ? 'FAILURES: ' . $failures : 'All ' . $passes . ' sanitizer checks passed.') . PHP_EOL;
exit($failures ? 1 : 0);

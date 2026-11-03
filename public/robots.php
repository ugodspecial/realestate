<?php
header('Content-Type: text/plain; charset=UTF-8');
$base=rtrim((string)config('app_url'),'/');
echo "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /login\nDisallow: /account\n";
if(config('demo_mode')) echo "Disallow: /\n";
elseif($base) echo "Sitemap: $base/sitemap.xml\n";

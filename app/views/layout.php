<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? brand()) ?></title>
<meta name="description" content="<?= e($description ?? setting('footer_text')) ?>">
<?php if(!empty($noindex) || config('demo_mode')): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<meta property="og:title" content="<?= e($title ?? brand()) ?>"><meta property="og:description" content="<?= e($description ?? '') ?>"><meta property="og:type" content="website"><meta property="og:site_name" content="<?= e(brand()) ?>">
<?php if(isset($p)): ?><meta property="og:image" content="<?= e(image_url($p['image'])) ?>"><?php endif; ?>
<?php if(config('app_url')): ?><link rel="canonical" href="<?= e(rtrim(config('app_url'),'/').url(in_array($route ?? 'home',['home',''],true) ? '' : (string)($route ?? ''))) ?>"><?php endif; ?>
<link rel="icon" href="<?= asset('images/favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= asset('fonts/Rajdhani-SemiBold.ttf') ?>" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="<?= asset('fonts/Roboto[wdth,wght].ttf') ?>" as="font" type="font/ttf" crossorigin>
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
<?php if(isset($schema)): ?><script type="application/ld+json"><?= json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><?php endif; ?>
<script src="<?= asset('js/site.js') ?>" defer></script>
</head>
<body class="public-site <?= e($page ?? 'home') ?>-page">
<a class="skip-link" href="#main">Skip to content</a>
<div class="utility-bar"><div class="container utility-inner"><span><?= icon('pin') ?> Rooted in Lagos. Connected to your future.</span><div><a href="mailto:<?= e(setting('email')) ?>"><?= icon('mail') ?><span><?= e(setting('email')) ?></span></a><a href="<?= url('login') ?>"><?= icon('user') ?> Staff login</a></div></div></div>
<header class="site-header"><div class="container header-inner">
<a class="brand" href="<?= url() ?>" aria-label="<?= e(brand()) ?> home"><span class="brand-symbol"><?= brand_mark() ?></span><span class="brand-type">CEDAR TRANQUILITY<small>REAL ESTATE</small></span></a>
<nav class="main-nav" id="main-nav" aria-label="Main navigation">
<?php foreach([''=>'Home','about'=>'About Us','services'=>'Services','properties'=>'Properties','contact'=>'Contact Us'] as $path=>$label): ?><a href="<?= url($path) ?>" <?= ($page ?? 'home')===($path ?: 'home')?'class="active" aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?>
<a class="mobile-nav-cta" href="<?= url('contact') ?>">Let’s talk <?= icon('arrow-up') ?></a>
</nav>
<a href="<?= url('contact') ?>" class="btn btn-primary header-cta">Let’s talk <?= icon('arrow-up') ?></a>
<button class="mobile-toggle" aria-label="Open navigation" aria-controls="main-nav" aria-expanded="false"><?= icon('menu') ?></button>
</div></header>
<div class="nav-overlay" aria-hidden="true"></div>
<main id="main">
<?php $flashes=take_flashes(); if($flashes): ?><div class="container flash-stack"><?php foreach($flashes as $f): ?><div class="notice notice-<?= e($f['type']) ?>" role="status"><?= icon($f['type']==='success'?'check':'file') ?><span><?= e($f['message']) ?></span><button class="notice-close" aria-label="Dismiss message"><?= icon('close') ?></button></div><?php endforeach; ?></div><?php endif; ?>
<?= $content ?>
</main>
<?php require ROOT_PATH.'/app/views/components/footer.php'; ?>
<a class="whatsapp-float" href="<?= e(whatsapp_url()) ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat with Cedar Tranquility on WhatsApp"><?= icon('whatsapp') ?><span>Let’s chat</span></a>
<button class="back-top" aria-label="Back to top"><?= icon('arrow', 'arrow-upward') ?></button>
</body></html>

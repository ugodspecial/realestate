<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? brand()) ?></title>
<meta name="description" content="<?= e($description ?? setting('footer_text')) ?>">
<meta name="robots" content="<?= (!empty($noindex) || config('demo_mode')) ? 'noindex,nofollow' : 'index,follow,max-image-preview:large' ?>">
<?php if(setting('google_verification')): ?><meta name="google-site-verification" content="<?= e(setting('google_verification')) ?>">
<?php endif; ?><?php if(setting('bing_verification')): ?><meta name="msvalidate.01" content="<?= e(setting('bing_verification')) ?>">
<?php endif; ?><meta property="og:title" content="<?= e($title ?? brand()) ?>"><meta property="og:description" content="<?= e($description ?? '') ?>"><meta property="og:type" content="<?= isset($p) ? 'article' : 'website' ?>"><meta property="og:site_name" content="<?= e(brand()) ?>">
<meta property="og:locale" content="en_NG">
<?php $ogUrl = social_url(); ?><?php if($ogUrl): ?><meta property="og:url" content="<?= e($ogUrl) ?>">
<?php endif; ?><?php if(isset($p)): ?><meta property="og:image" content="<?= e(image_url($p['image'])) ?>">
<?php else: ?><meta property="og:image" content="<?= e(social_image_url()) ?>">
<?php endif; ?><meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title ?? brand()) ?>"><meta name="twitter:description" content="<?= e($description ?? '') ?>">
<meta name="twitter:image" content="<?= e(isset($p) ? image_url($p['image']) : social_image_url()) ?>">
<?php if($ogUrl): ?><link rel="canonical" href="<?= e($ogUrl) ?>">
<?php endif; ?>
<link rel="icon" href="<?= asset('images/favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= asset('fonts/Rajdhani-SemiBold.ttf') ?>" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="<?= asset('fonts/Roboto[wdth,wght].ttf') ?>" as="font" type="font/ttf" crossorigin>
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
<?php
// Site-wide business schema on every page, plus the page-specific schema
// (a listing, for example) when the controller supplies one.
$ldJson = [organisation_schema()];
if (isset($schema) && is_array($schema)) $ldJson[] = $schema;
if (empty($noindex) && !config('demo_mode')): ?>
<script type="application/ld+json"><?= json_encode(count($ldJson) === 1 ? $ldJson[0] : ['@context'=>'https://schema.org','@graph'=>$ldJson], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<script src="<?= asset('js/site.js') ?>" defer></script>
</head>
<body class="public-site <?= e($page ?? 'home') ?>-page">
<a class="skip-link" href="#main">Skip to content</a>
<div class="read-progress" aria-hidden="true"><span></span></div>
<div class="utility-bar"><div class="container utility-inner"><span><?= icon('pin') ?> Rooted in Lagos. Connected to your future.</span><div><a href="mailto:<?= e(setting('email')) ?>"><?= icon('mail') ?><span><?= e(setting('email')) ?></span></a><a href="<?= url('login') ?>"><?= icon('user') ?> Staff login</a></div></div></div>
<header class="site-header"><div class="site-header-bar"><div class="container header-inner">
<a class="brand" href="<?= url() ?>" aria-label="<?= e(brand()) ?> home"><?= brand_block() ?></a>
<nav class="main-nav" id="main-nav" aria-label="Main navigation">
<button class="main-nav-close" type="button" aria-label="Close navigation"><?= icon('close') ?></button>
<?php foreach([''=>'Home','about'=>'About Us','services'=>'Services','properties'=>'Properties','contact'=>'Contact Us'] as $path=>$label): ?><a href="<?= url($path) ?>" <?= ($page ?? 'home')===($path ?: 'home')?'class="active" aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?>
<a class="mobile-nav-cta" href="<?= url('contact') ?>">Let’s talk <?= icon('arrow-up') ?></a>
</nav>
<a href="<?= url('contact') ?>" class="btn btn-primary header-cta">Let’s talk <?= icon('arrow-up') ?></a>
<button class="mobile-toggle" aria-label="Open navigation" aria-controls="main-nav" aria-expanded="false"><span class="toggle-icon-open"><?= icon('menu') ?></span><span class="toggle-icon-close" aria-hidden="true"><?= icon('close') ?></span></button>
</div></div></header>
<div class="nav-overlay" aria-hidden="true"></div>
<main id="main">
<?php
$flashes = take_flashes();
// A submitted enquiry gets a proper confirmation dialog rather than a banner:
// the reference is something the visitor may need to write down, so it should
// be unmissable and dismissible on its own terms.
$enquiryFlash = null;
$bannerFlashes = [];
foreach ($flashes as $f) {
    if (($f['type'] ?? '') === 'enquiry' && $enquiryFlash === null) { $enquiryFlash = $f; continue; }
    $bannerFlashes[] = $f;
}
if ($enquiryFlash):
    $parts = enquiry_flash_parts((string) $enquiryFlash['message']);
?>
<div class="confirm-dialog" id="enquiryDialog" role="dialog" aria-modal="true" aria-labelledby="enquiryDialogTitle" aria-describedby="enquiryDialogBody" hidden>
  <div class="confirm-dialog-backdrop" data-dialog-dismiss></div>
  <div class="confirm-dialog-panel" role="document">
    <button type="button" class="confirm-dialog-close" data-dialog-dismiss aria-label="Close this message"><?= icon('close') ?></button>
    <span class="confirm-dialog-icon" aria-hidden="true"><?= icon('check') ?></span>
    <h2 id="enquiryDialogTitle" class="confirm-dialog-title">Enquiry received</h2>
    <div id="enquiryDialogBody" class="confirm-dialog-body">
      <?php if ($parts['lead'] !== ''): ?><p><?= e($parts['lead']) ?></p><?php endif; ?>
      <?php if ($parts['reference'] !== ''): ?>
      <p class="confirm-dialog-ref">
        <span>Your reference</span>
        <strong><?= e($parts['reference']) ?></strong>
      </p>
      <?php endif; ?>
      <?php if ($parts['tail'] !== ''): ?><p><?= e($parts['tail']) ?></p><?php endif; ?>
    </div>
    <button type="button" class="btn btn-primary confirm-dialog-ok" data-dialog-dismiss>Okay</button>
  </div>
</div>
<?php endif; ?>
<?php if($bannerFlashes): ?><div class="container flash-stack"><?php foreach($bannerFlashes as $f): ?><div class="notice notice-<?= e($f['type']) ?>" role="status"><?= icon($f['type']==='success'?'check':'file') ?><span><?= e($f['message']) ?></span><button class="notice-close" aria-label="Dismiss message"><?= icon('close') ?></button></div><?php endforeach; ?></div><?php endif; ?>
<?= $content ?>
</main>
<?php require ROOT_PATH.'/app/views/components/footer.php'; ?>
<a class="whatsapp-float" href="<?= e(whatsapp_url()) ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat with <?= e(brand()) ?> on WhatsApp"><?= icon('whatsapp') ?><span>Let’s chat</span></a>
<button class="back-top" aria-label="Back to top"><?= icon('arrow', 'arrow-upward') ?></button>
</body></html>

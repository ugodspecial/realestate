<?php
/**
 * Shared shell for the signed-out account pages (sign in, forgot password,
 * reset password, forced password change). Keeping one shell means the four
 * pages cannot drift apart visually or in their security headers.
 *
 * Variables: $title, $eyebrow, $heading, $intro, $content (rendered HTML).
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — <?= e(brand()) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<link rel="icon" type="image/svg+xml" href="<?= asset('images/favicon.svg') ?>">
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
</head>
<body class="login-page">
<a class="skip-link" href="#authForm">Skip to form</a>
<div class="login-shell">
  <div class="login-visual">
    <img src="<?= asset('images/terrace.jpg') ?>" alt="" aria-hidden="true">
    <a class="brand brand-light" href="<?= url('/') ?>" aria-label="<?= e(brand()) ?> home"><?= brand_block() ?></a>
    <div>
      <span class="hero-eyebrow"><span></span><?= e($eyebrow) ?></span>
      <h1><?= e($heading) ?></h1>
      <p><?= e($intro) ?></p>
    </div>
    <small>Protected by role-based access. Every sign-in and change is audited.</small>
  </div>
  <div class="login-form-side">
    <div class="login-card" id="authForm">
      <?= $content ?>
      <?php /* On the sign-in page itself a link back to sign-in is noise. */ ?>
      <?php if (empty($hideBackLink)): ?>
        <a class="login-back" href="<?= url('login') ?>">&larr; Back to sign in</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="<?= asset('js/site.js') ?>" defer></script>
</body>
</html>

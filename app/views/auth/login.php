<?php
/** Staff sign-in. Variables: $error(?string), $email(?string). Standalone page, no public layout. */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Sign In — <?= e(brand()) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= asset('images/favicon.svg') ?>">
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
</head>
<body class="login-page">
<a class="skip-link" href="#loginForm">Skip to sign-in form</a>
<div class="login-shell">
  <div class="login-visual">
    <img src="<?= asset('images/terrace.jpg') ?>" alt="" aria-hidden="true">
    <a class="brand brand-light" href="<?= url('/') ?>" aria-label="<?= e(brand()) ?> home"><?= brand_block() ?></a>
    <div>
      <span class="hero-eyebrow"><span></span>STAFF PORTAL</span>
      <h1>Welcome back.</h1>
      <p>Manage listings, enquiries, content and users from one secure dashboard.</p>
    </div>
    <small>Protected by role-based access. Every sign-in and change is audited.</small>
  </div>
  <div class="login-form-side">
    <div class="login-card">
      <span class="eyebrow">SECURE ACCESS</span>
      <h2>Staff sign in</h2>
      <p>Use the credentials issued by your administrator.</p>
      <?php if (!empty($error)): ?>
        <div class="notice notice-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
      <?php endif; ?>
      <?php foreach (flash_all() as $flash): ?>
        <div class="notice<?= $flash['type'] === 'error' ? ' notice-error' : '' ?>" role="status"><?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?><div><?= e($flash['message']) ?></div></div>
      <?php endforeach; ?>
      <form method="post" action="<?= url('/login') ?>" id="loginForm" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="loginEmail">Email address</label>
          <input type="email" id="loginEmail" name="email" value="<?= e($email ?? old('email', '')) ?>" required autocomplete="username" autofocus>
        </div>
        <div class="field">
          <label for="loginPassword">Password</label>
          <div class="password-wrap">
            <input type="password" id="loginPassword" name="password" required autocomplete="current-password">
            <button type="button" class="password-toggle" data-target="loginPassword" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Sign in securely <?= icon('arrow') ?></button>
      </form>
      <p class="login-security"><?= icon('shield') ?> Sessions expire after inactivity. Repeated failed attempts are temporarily locked.</p>
      <?php if (!empty($demo_hint)): ?>
        <p class="login-help"><strong>Demo environment.</strong> <?= e($demo_hint) ?></p>
      <?php endif; ?>
      <a class="login-back" href="<?= url('/') ?>">&larr; Back to website</a>
    </div>
  </div>
</div>
<script src="<?= asset('js/site.js') ?>" defer></script>
</body>
</html>

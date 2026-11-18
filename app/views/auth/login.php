<?php
/**
 * Staff sign-in. Variables: $error(?string), $email(?string).
 * Rendered through the shared auth shell so it matches the recovery pages.
 */
ob_start();
?><span class="eyebrow">SECURE ACCESS</span>
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
<p class="login-links"><a href="<?= url('forgot-password') ?>">Forgot your password?</a></p>
<p class="login-security"><?= icon('shield') ?> Sessions expire after inactivity. Repeated failed attempts are temporarily locked.</p>
<?php if (!empty($demo_hint)): ?>
  <p class="login-help"><strong>Demo environment.</strong> <?= e($demo_hint) ?></p>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();
$title = 'Staff sign in';
$hideBackLink = true;
$eyebrow = 'STAFF PORTAL';
$heading = 'Welcome back.';
$intro = 'Manage listings, enquiries, content and users from one secure dashboard.';
require __DIR__ . '/shell.php';

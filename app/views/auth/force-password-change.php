<?php
/**
 * First sign-in with an administrator-issued password. Nothing else in the
 * admin area is reachable until this is completed, so the password an
 * administrator typed never becomes the account's permanent password.
 * Variables: $error(?string), $name(string).
 */
ob_start();
?><span class="eyebrow">ONE LAST STEP</span>
<h2>Choose your password</h2>
<p>Welcome, <?= e($name) ?>. The password you signed in with was issued by an administrator — set your own before continuing.</p>
<?php if (!empty($error)): ?>
  <div class="notice notice-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
<?php endif; ?>
<form method="post" action="<?= url('force-password-change') ?>" id="forceForm" novalidate>
  <?= csrf_field() ?>
  <div class="field">
    <label for="newPass">New password <small>(at least <?= PasswordReset::MIN_LENGTH ?> characters)</small></label>
    <div class="password-wrap">
      <input type="password" id="newPass" name="password" required minlength="<?= PasswordReset::MIN_LENGTH ?>" autocomplete="new-password" autofocus>
      <button type="button" class="password-toggle" data-target="newPass" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
    </div>
  </div>
  <div class="field">
    <label for="newPass2">Confirm new password</label>
    <input type="password" id="newPass2" name="password_confirm" required minlength="<?= PasswordReset::MIN_LENGTH ?>" autocomplete="new-password">
  </div>
  <button class="btn btn-primary" type="submit">Save and continue <?= icon('arrow') ?></button>
</form>
<form method="post" action="<?= url('logout') ?>" class="login-signout"><?= csrf_field() ?><button type="submit" class="login-signout-link">Sign out instead</button></form>
<p class="login-security"><?= icon('shield') ?> Only you will know this password. Choose something you have not used on another site.</p>
<?php
$content = (string) ob_get_clean();
$title = 'Choose your password';
$eyebrow = 'STAFF PORTAL';
$heading = 'Make it yours.';
$intro = 'Your account is ready — it just needs a password that belongs to you alone.';
require __DIR__ . '/shell.php';

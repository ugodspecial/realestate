<?php
/**
 * The page behind the emailed link. Variables: $token(string),
 * $invalid(bool), $email(string), $error(?string).
 */
ob_start();
if (!empty($error)):
?><span class="eyebrow">ACCOUNT RECOVERY</span>
<h2>Choose a new password</h2>
<div class="notice notice-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
<?php if (empty($token)): ?>
  <a class="btn btn-primary" href="<?= url('forgot-password') ?>">Request a new link <?= icon('arrow') ?></a>
<?php else: ?>
  <form method="post" action="<?= url('reset-password') ?>" id="resetForm" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="field">
      <label for="resetPass">New password <small>(min. <?= PasswordReset::MIN_LENGTH ?> characters)</small></label>
      <div class="password-wrap">
        <input type="password" id="resetPass" name="password" required minlength="<?= PasswordReset::MIN_LENGTH ?>" autocomplete="new-password" autofocus>
        <button type="button" class="password-toggle" data-target="resetPass" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
      </div>
    </div>
    <div class="field">
      <label for="resetPass2">Confirm new password</label>
      <input type="password" id="resetPass2" name="password_confirm" required minlength="<?= PasswordReset::MIN_LENGTH ?>" autocomplete="new-password">
    </div>
    <button class="btn btn-primary" type="submit">Save new password <?= icon('check') ?></button>
  </form>
<?php endif; ?>
<?php elseif ($invalid):
?><span class="eyebrow">ACCOUNT RECOVERY</span>
<h2>Link no longer valid</h2>
<div class="notice notice-error" role="alert"><?= icon('alert') ?><div>This reset link has expired or has already been used. Request a fresh one and it will work.</div></div>
<a class="btn btn-primary" href="<?= url('forgot-password') ?>">Request a new link <?= icon('arrow') ?></a>
<?php else: ?>
<span class="eyebrow">ACCOUNT RECOVERY</span>
<h2>Choose a new password</h2>
<p>Setting a new password for <strong><?= e($email) ?></strong> signs out any other devices.</p>
<form method="post" action="<?= url('reset-password') ?>" id="resetForm" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="field">
    <label for="resetPass">New password <small>(min. <?= PasswordReset::MIN_LENGTH ?> characters)</small></label>
    <div class="password-wrap">
      <input type="password" id="resetPass" name="password" required minlength="<?= PasswordReset::MIN_LENGTH ?>" autocomplete="new-password" autofocus>
      <button type="button" class="password-toggle" data-target="resetPass" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
    </div>
  </div>
  <div class="field">
    <label for="resetPass2">Confirm new password</label>
    <input type="password" id="resetPass2" name="password_confirm" required minlength="<?= PasswordReset::MIN_LENGTH ?>" autocomplete="new-password">
  </div>
  <button class="btn btn-primary" type="submit">Save new password <?= icon('check') ?></button>
</form>
<p class="login-security"><?= icon('shield') ?> Choose something you have not used elsewhere. This link stops working once you save.</p>
<?php endif;
$content = (string) ob_get_clean();
$title = !empty($invalid) ? 'Reset link expired' : 'Choose a new password';
$eyebrow = 'ACCOUNT RECOVERY';
$heading = 'Almost there.';
$intro = 'Pick a password only you know, and your dashboard is back.';
require __DIR__ . '/shell.php';

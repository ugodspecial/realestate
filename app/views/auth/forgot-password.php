<?php
/**
 * "Forgot your password?" — asks for an address and always answers the same
 * way, so it cannot be used to find out who has an account here.
 * Variables: $sent(bool).
 */
ob_start();
?><span class="eyebrow">ACCOUNT RECOVERY</span>
<h2>Reset your password</h2>
<p>Enter the email address on your staff account and we will send you a link to choose a new password.</p>
<?php foreach (flash_all() as $flash): ?>
  <div class="notice<?= $flash['type'] === 'error' ? ' notice-error' : '' ?>" role="status"><?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?><div><?= e($flash['message']) ?></div></div>
<?php endforeach; ?>
<form method="post" action="<?= url('forgot-password') ?>" id="forgotForm" novalidate>
  <?= csrf_field() ?>
  <div class="field">
    <label for="forgotEmail">Email address</label>
    <input type="email" id="forgotEmail" name="email" value="<?= e(old('email', '')) ?>" required autocomplete="username" autofocus>
  </div>
  <button class="btn btn-primary" type="submit">Send reset link <?= icon('arrow') ?></button>
</form>
<?php if ($sent): ?>
  <p class="login-help"><strong>Demo environment.</strong> Mail delivery is switched off here, so open <a href="<?= url('reset-password') ?>">the reset page</a> and use the token printed in the server log, or ask an administrator to reset your password from the staff screen.</p>
<?php endif; ?>
<p class="login-security"><?= icon('shield') ?> For your safety we never confirm whether an address has an account. Links expire after <?= PasswordReset::TOKEN_TTL_MINUTES ?> minutes and can be used once.</p>
<?php
$content = (string) ob_get_clean();
$title = 'Reset your password';
$eyebrow = 'ACCOUNT RECOVERY';
$heading = 'Locked out?';
$intro = 'A short email gets you back to managing listings, enquiries and content.';
require __DIR__ . '/shell.php';

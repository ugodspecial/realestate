<?php /** Variables: $account. */ ?>
<div class="admin-top">
  <div>
    <h1>My account</h1>
    <p><?= e($account['name']) ?> · <?= e(Auth::roleName($account['role'])) ?> · <?= e($account['email']) ?></p>
  </div>
</div>
<div class="admin-card admin-form">
  <h2>Profile</h2>
  <p>Update your display name or change your password. Changing your password signs out your other sessions.</p>
  <form method="post" action="<?= url('admin/account') ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="aName">Full name</label><input id="aName" name="name" value="<?= e($account['name']) ?>" required maxlength="120"></div>
      <div class="field"><label>Last sign-in</label><input value="<?= $account['last_login'] ? e(date('d M Y, H:i', strtotime($account['last_login']))) : 'First session' ?>" disabled></div>
      <div class="field"><label for="aCurrent">Current password <small>(only to change password)</small></label><input id="aCurrent" type="password" name="current_password" autocomplete="current-password"></div>
      <div class="field"><label for="aNew">New password <small>(min. 10 characters)</small></label><input id="aNew" type="password" name="password" autocomplete="new-password"></div>
      <div class="field"><label for="aNew2">Confirm new password</label><input id="aNew2" type="password" name="password_confirm" autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Save profile</button></div>
  </form>
</div>

<?php /** Variables: $edit(?array). */ $v = fn($k, $d = '') => old($k, $edit[$k] ?? $d); $isEdit = (bool)$edit; ?>
<div class="admin-top">
  <div>
    <h1><?= $isEdit ? 'Edit staff member' : 'Add staff member' ?></h1>
    <p><?= $isEdit ? e($edit['name']) . ' · ' . e(Auth::roleName($edit['role'])) : 'They can sign in immediately with the password you set.' ?></p>
  </div>
  <div class="admin-actions"><a class="btn btn-outline btn-small" href="<?= url('admin/users') ?>">All staff</a></div>
</div>
<div class="admin-card admin-form">
  <form method="post" action="<?= url($isEdit ? 'admin/users/' . $edit['id'] . '/edit' : 'admin/users/create') ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="uName">Full name <span>*</span></label><input id="uName" name="name" value="<?= e($v('name')) ?>" required maxlength="120"></div>
      <div class="field"><label for="uEmail">Email address <span>*</span></label><input id="uEmail" type="email" name="email" value="<?= e($v('email')) ?>" required maxlength="190"></div>
      <div class="field"><label for="uRole">Role <span>*</span></label><select id="uRole" name="role"><?php foreach (Auth::PERMISSIONS as $role => $perms): ?><option value="<?= $role ?>" <?= $v('role', 'content_editor') === $role ? 'selected' : '' ?>><?= e(Auth::roleName($role)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="uPass">Password <?= $isEdit ? '<small>(leave blank to keep current)</small>' : '<span>*</span> <small>(min. 10 characters)</small>' ?></label><input id="uPass" type="password" name="password" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password"></div>
      <div class="field"><label for="uPass2">Confirm password <?= $isEdit ? '' : '<span>*</span>' ?></label><input id="uPass2" type="password" name="password_confirm" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Create account' ?></button>
      <a class="btn btn-outline" href="<?= url('admin/users') ?>">Cancel</a>
    </div>
  </form>
</div>
<?php if ($isEdit && (int)$edit['id'] !== (int)($admin_user['id'] ?? 0)): ?>
<div class="admin-card">
  <h2>Account controls</h2>
  <p>Deactivation signs them out everywhere immediately. Password resets issue a one-time temporary password.</p>
  <div class="row-actions">
    <form method="post" action="<?= url('admin/users/' . $edit['id'] . '/reset-password') ?>" data-confirm="Issue a new temporary password for <?= e($edit['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($edit['name']) ?>"><input type="hidden" name="email" value="<?= e($edit['email']) ?>"><input type="hidden" name="role" value="<?= e($edit['role']) ?>"><button type="submit">Reset password</button></form>
    <form method="post" action="<?= url('admin/users/' . $edit['id'] . '/edit') ?>" data-confirm="<?= $edit['active'] ? 'Deactivate' : 'Reactivate' ?> <?= e($edit['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($edit['name']) ?>"><input type="hidden" name="email" value="<?= e($edit['email']) ?>"><input type="hidden" name="role" value="<?= e($edit['role']) ?>"><button type="submit" class="<?= $edit['active'] ? 'danger' : '' ?>" name="<?= $edit['active'] ? 'deactivate' : 'reactivate' ?>" value="1"><?= $edit['active'] ? 'Deactivate account' : 'Reactivate account' ?></button></form>
  </div>
</div>
<?php endif; ?>

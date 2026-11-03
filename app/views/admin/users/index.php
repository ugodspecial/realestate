<?php /** Variables: $items. */ ?>
<div class="admin-top">
  <div>
    <h1>Staff &amp; users</h1>
    <p><?= count($items) ?> accounts. Give every team member the smallest role they need.</p>
  </div>
  <div class="admin-actions"><a class="btn btn-primary btn-small" href="<?= url('admin/users/new') ?>"><?= icon('plus') ?> Add staff</a></div>
</div>
<div class="admin-card">
  <div class="permission-grid" style="margin-bottom:22px">
    <div class="permission-card"><h3>Super Administrator</h3>Everything, including settings and the activity log.</div>
    <div class="permission-card"><h3>Administrator</h3>Everything except website settings.</div>
    <div class="permission-card"><h3>Listing Manager</h3>Properties and enquiries. Cannot approve or publish.</div>
    <div class="permission-card"><h3>Content Editor</h3>Categories, locations, and testimonials only.</div>
  </div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <td><strong><?= e($u['name']) ?></strong></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e(Auth::roleName($u['role'])) ?></td>
        <td><?= $u['active'] ? badge('published', 'Active') : badge('draft', 'Deactivated') ?></td>
        <td><small><?= $u['last_login'] ? e(date('d M Y, H:i', strtotime($u['last_login']))) : 'Never' ?></small></td>
        <td><div class="row-actions"><a href="<?= url('admin/users/' . $u['id'] . '/edit') ?>">Edit</a></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

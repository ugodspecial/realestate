<?php /** Variables: $stats, $recentProperties, $recentEnquiries, $recentAudit. */ ?>
<div class="admin-top">
  <div>
    <h1>Dashboard</h1>
    <p>Welcome back, <?= e(explode(' ', (string)($admin_user['name'] ?? ''))[0]) ?>. Here is what needs your attention today.</p>
  </div>
  <div class="admin-actions">
    <?php if (Auth::can('properties.manage')): ?><a class="btn btn-primary btn-small" href="<?= url('admin/properties/new') ?>"><?= icon('plus') ?> Add property</a><?php endif; ?>
    <a class="btn btn-outline btn-small" href="<?= url('/') ?>" target="_blank" rel="noopener">View website</a>
  </div>
</div>
<div class="stats-grid">
  <div class="stat-card"><?= icon('home') ?><div><b><?= $stats['published'] ?></b><span>Published listings</span><small><?= $stats['available'] ?> currently available</small></div></div>
  <div class="stat-card"><?= icon('clock') ?><div><b><?= $stats['pending'] ?></b><span>Awaiting approval</span><small><?= Auth::can('properties.approve') ? 'Ready for your review' : 'Waiting for an approver' ?></small></div></div>
  <div class="stat-card"><?= icon('mail') ?><div><b><?= $stats['enquiries'] ?></b><span>New enquiries</span><small>Respond within one business day</small></div></div>
  <div class="stat-card"><?= icon('users') ?><div><b><?= (int)Database::value('SELECT COUNT(*) FROM users WHERE active=1') ?></b><span>Active staff</span><small>Across <?= count(array_unique(array_column(Database::all('SELECT role FROM users WHERE active=1'), 'role'))) ?> roles</small></div></div>
</div>
<div class="admin-cols">
  <div class="admin-card">
    <h2>Recently updated properties</h2>
    <p>The latest listing activity across your portfolio.</p>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Listing</th><th>Price</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentProperties as $p): ?>
        <tr>
          <td><a href="<?= url('admin/properties/' . $p['id'] . '/edit') ?>"><strong><?= e($p['title']) ?></strong></a><br><small><?= e($p['listing_id']) ?> · <?= e($p['location_name']) ?></small></td>
          <td><?= e(price_label($p, true)) ?></td>
          <td><?= badge($p['publish_status']) ?> <?= badge($p['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="admin-card">
    <h2>Latest enquiries</h2>
    <p>New messages from website visitors.</p>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>From</th><th>Property</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentEnquiries as $e): ?>
        <tr>
          <td><a href="<?= url('admin/enquiries/' . $e['id']) ?>"><strong><?= e($e['name']) ?></strong></a><br><small><?= e($e['reference']) ?></small></td>
          <td><?= e($e['property_title'] ?? 'General') ?></td>
          <td><?= badge($e['status'], ucfirst($e['status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<div class="admin-card">
  <h2>Recent activity</h2>
  <p>Who changed what, and when.</p>
  <div class="audit-list">
    <?php foreach ($recentAudit as $a): ?>
      <div><time><?= e(date('d M, H:i', strtotime($a['created_at']))) ?></time><div><b><?= e($a['user_name'] ?? 'System') ?></b> — <?= e($a['action']) ?> · <?= e($a['details']) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>

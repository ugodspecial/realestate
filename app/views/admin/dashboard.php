<?php
/**
 * Variables: $stats, $recentProperties, $recentEnquiries, $recentAudit,
 *            $contentSummary, and the $can* flags set by the controller.
 *
 * One template, four audiences. Nothing here is shown on the grounds that the
 * viewer reached the page: each block appears only when the viewer holds the
 * permission for the data inside it, and every link points at a page that
 * role can open. A dashboard full of links that answer 403 is worse than a
 * short dashboard.
 */
$stats = $stats ?? [];
$recentProperties = $recentProperties ?? [];
$recentEnquiries = $recentEnquiries ?? [];
$recentAudit = $recentAudit ?? [];
$contentSummary = $contentSummary ?? [];
$canListings = $canListings ?? false;
$canApprove = $canApprove ?? false;
$canEnquiries = $canEnquiries ?? false;
$canContent = $canContent ?? false;
$canUsers = $canUsers ?? false;
$canAudit = $canAudit ?? false;

// What this role is here for, said plainly, so nobody has to infer it from a
// mostly-empty page.
$purpose = match (true) {
    $canApprove && $canUsers => 'You have full oversight of listings, enquiries, staff, and settings.',
    $canListings && $canEnquiries => 'You manage listings and the enquiries that come in from the website.',
    $canContent => 'You look after the website content: categories, locations, and testimonials.',
    default => 'Here is what needs your attention today.',
};
?>
<div class="admin-top">
  <div>
    <h1>Dashboard</h1>
    <p>Welcome back, <?= e(explode(' ', (string)($admin_user['name'] ?? ''))[0]) ?>. <?= e($purpose) ?></p>
  </div>
  <div class="admin-actions">
    <?php if ($canListings): ?><a class="btn btn-primary btn-small" href="<?= url('admin/properties/new') ?>"><?= icon('plus') ?> Add property</a><?php endif; ?>
    <?php if ($canContent): ?><a class="btn btn-primary btn-small" href="<?= url('admin/testimonials') ?>"><?= icon('star') ?> Manage testimonials</a><?php endif; ?>
    <a class="btn btn-outline btn-small" href="<?= url('/') ?>" target="_blank" rel="noopener">View website</a>
  </div>
</div>

<?php if ($stats): ?>
<div class="stats-grid">
  <?php if ($canListings): ?>
  <div class="stat-card"><?= icon('home') ?><div><b><?= $stats['published'] ?></b><span>Published listings</span><small><?= $stats['available'] ?> currently available</small></div></div>
  <div class="stat-card"><?= icon('clock') ?><div><b><?= $stats['pending'] ?></b><span>Awaiting approval</span><small><?= $canApprove ? 'Ready for your review' : 'Waiting for an approver' ?></small></div></div>
  <?php endif; ?>
  <?php if ($canEnquiries): ?>
  <div class="stat-card"><?= icon('mail') ?><div><b><?= $stats['enquiries'] ?></b><span>New enquiries</span><small>Respond within one business day</small></div></div>
  <?php endif; ?>
  <?php if ($canUsers): ?>
  <div class="stat-card"><?= icon('users') ?><div><b><?= $stats['staff'] ?></b><span>Active staff</span><small>Across <?= $stats['roles'] ?> roles</small></div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($recentProperties || $recentEnquiries || $contentSummary): ?>
<div class="admin-cols">
  <?php if ($recentProperties): ?>
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
  <?php endif; ?>
  <?php if ($recentEnquiries): ?>
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
  <?php endif; ?>
  <?php if ($contentSummary): ?>
  <div class="admin-card">
    <h2>Website content</h2>
    <p>The parts of the site you maintain. Select one to edit it.</p>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Section</th><th>Entries</th><th>Action</th></tr></thead>
      <tbody>
        <tr>
          <td><a href="<?= url('admin/categories') ?>"><strong>Categories</strong></a><br><small>How listings are grouped</small></td>
          <td><?= (int)$contentSummary['categories'] ?></td>
          <td><a href="<?= url('admin/categories') ?>">Manage</a></td>
        </tr>
        <tr>
          <td><a href="<?= url('admin/locations') ?>"><strong>Locations</strong></a><br><small>Where properties are listed</small></td>
          <td><?= (int)$contentSummary['locations'] ?></td>
          <td><a href="<?= url('admin/locations') ?>">Manage</a></td>
        </tr>
        <tr>
          <td><a href="<?= url('admin/testimonials') ?>"><strong>Testimonials</strong></a><br><small><?= (int)$contentSummary['testimonials_pending'] ?> awaiting approval</small></td>
          <td><?= (int)$contentSummary['testimonials'] ?></td>
          <td><a href="<?= url('admin/testimonials') ?>">Manage</a></td>
        </tr>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($recentAudit): ?>
<div class="admin-card">
  <h2>Recent activity</h2>
  <p>Who changed what, and when.</p>
  <div class="audit-list">
    <?php foreach ($recentAudit as $a): ?>
      <div><time><?= e(date('d M, H:i', strtotime($a['created_at']))) ?></time><div><b><?= e($a['user_name'] ?? 'System') ?></b> — <?= e($a['action']) ?> · <?= e($a['details']) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

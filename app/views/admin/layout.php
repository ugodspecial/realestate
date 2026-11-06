<?php
/** Admin shell. Variables: $content, $admin_title, $admin_user, $admin_active. */
$nav = [
    'Manage' => [
        ['dashboard', 'Dashboard', 'chart', 'admin', 'dashboard'],
        ['properties', 'Properties', 'home', 'admin/properties', 'properties.manage'],
        ['enquiries', 'Enquiries', 'mail', 'admin/enquiries', 'enquiries.manage'],
    ],
    'Content' => [
        ['categories', 'Categories', 'grid', 'admin/categories', 'content.manage'],
        ['locations', 'Locations', 'pin', 'admin/locations', 'content.manage'],
        ['testimonials', 'Testimonials', 'star', 'admin/testimonials', 'content.manage'],
    ],
    'System' => [
        ['users', 'Staff & users', 'users', 'admin/users', 'users.manage'],
        ['settings', 'Settings', 'settings', 'admin/settings', 'settings.manage'],
        ['audit', 'Activity log', 'file', 'admin/audit', 'audit.view'],
        ['roadmap', 'Agent portal plan', 'calendar', 'admin/roadmap', 'dashboard'],
    ],
];
$pendingCount = Auth::can('properties.approve') ? (int)Database::value("SELECT COUNT(*) FROM properties WHERE publish_status='pending'") : 0;
$newEnquiries = Auth::can('enquiries.manage') ? (int)Database::value("SELECT COUNT(*) FROM enquiries WHERE status='new'") : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($admin_title ?? 'Dashboard') ?> · Admin — <?= e(brand()) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= asset('images/favicon.svg') ?>">
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
</head>
<body>
<a class="skip-link" href="#adminMain">Skip to main content</a>
<div class="admin-shell">
  <aside class="admin-sidebar" aria-label="Admin navigation">
    <div class="admin-brand">
      <?php $adminLogo = brand_logo(); ?>
      <?php if ($adminLogo !== ''): ?>
        <div class="admin-brand-logo"><?= $adminLogo ?></div>
      <?php else: ?>
        <div class="admin-brand-type"><?= e(brand_lead()) ?><small><?= e(brand_tagline()) ?> · ADMIN</small></div>
      <?php endif; ?>
    </div>
    <nav class="admin-nav">
      <?php foreach ($nav as $group => $links): ?>
        <?php $visible = array_filter($links, fn($l) => Auth::can($l[4])); ?>
        <?php if ($visible): ?>
          <span><?= e($group) ?></span>
          <?php foreach ($visible as [$key, $label, $ic, $path]): ?>
            <a href="<?= url($path) ?>" class="<?= ($admin_active ?? '') === $key ? 'active' : '' ?>"><?= icon($ic) ?> <?= e($label) ?>
              <?php if ($key === 'properties' && $pendingCount): ?><small><?= $pendingCount ?></small><?php endif; ?>
              <?php if ($key === 'enquiries' && $newEnquiries): ?><small><?= $newEnquiries ?></small><?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="admin-user">
      <?= e($admin_user['name'] ?? '') ?>
      <small><?= e(Auth::roleName($admin_user['role'] ?? '')) ?> · v<?= e(APP_VERSION) ?></small>
      <div>
        <a href="<?= url('admin/account') ?>">My account</a>
        <a href="<?= url('/') ?>" target="_blank" rel="noopener">View site</a>
        <button type="submit" form="adminLogout">Sign out</button>
      </div>
      <form id="adminLogout" method="post" action="<?= url('logout') ?>" hidden><?= csrf_field() ?></form>
    </div>
  </aside>
  <main class="admin-main" id="adminMain">
    <?php foreach (take_flashes() as $f): ?>
      <div class="notice<?= $f['type'] === 'error' ? ' notice-error' : '' ?>" role="status"><?= icon($f['type'] === 'error' ? 'alert' : 'check') ?><div><?= e($f['message']) ?></div><button class="notice-close" aria-label="Dismiss message"><?= icon('close') ?></button></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<script src="<?= asset('js/site.js') ?>" defer></script>
</body>
</html>

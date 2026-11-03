<?php /** Variables: $items, $total, $page, $pages, $filters, $categories, $locations. */ ?>
<div class="admin-top">
  <div>
    <h1>Properties</h1>
    <p><?= $total ?> listing<?= $total === 1 ? '' : 's' ?> found. Approve, publish, and manage every home from here.</p>
  </div>
  <div class="admin-actions"><a class="btn btn-primary btn-small" href="<?= url('admin/properties/new') ?>"><?= icon('plus') ?> Add property</a></div>
</div>
<div class="admin-card">
  <form class="filter-bar" method="get" action="<?= url('admin/properties') ?>">
    <div class="field"><label for="fQ">Search</label><input id="fQ" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Title, ID, location…"></div>
    <div class="field"><label for="fDeal">Classification</label><select id="fDeal" name="deal"><option value="">All</option><option value="sale" <?= ($filters['deal'] ?? '') === 'sale' ? 'selected' : '' ?>>For sale</option><option value="rent" <?= ($filters['deal'] ?? '') === 'rent' ? 'selected' : '' ?>>For rent</option></select></div>
    <div class="field"><label for="fPub">Publication</label><select id="fPub" name="publish_status"><option value="">All</option><?php foreach (['published' => 'Published', 'pending' => 'Awaiting approval', 'draft' => 'Draft'] as $v => $l): ?><option value="<?= $v ?>" <?= ($filters['publish_status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="fStatus">Availability</label><select id="fStatus" name="status"><option value="">All</option><?php foreach (['available' => 'Available', 'sold' => 'Sold', 'rented' => 'Rented'] as $v => $l): ?><option value="<?= $v ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit"><?= icon('search') ?> Filter</button>
    <?php if ($filters): ?><a class="btn btn-outline" style="margin-top:24px" href="<?= url('admin/properties') ?>">Clear</a><?php endif; ?>
  </form>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th></th><th>Listing</th><th>Price</th><th>Classification</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <tr>
        <td><img src="<?= e(image_url($p['image'])) ?>" alt="" loading="lazy"></td>
        <td><strong><?= e($p['title']) ?></strong><br><small><?= e($p['listing_id']) ?> · <?= e($p['category_name']) ?> · <?= e($p['location_name']) ?><?= $p['featured'] ? ' · ★ Featured' : '' ?></small></td>
        <td><?= e(price_label($p, true)) ?></td>
        <td><?= badge($p['deal'] === 'rent' ? 'rent' : 'sale', deal_label($p['deal'])) ?></td>
        <td><?= badge($p['publish_status']) ?><br><?= badge($p['status']) ?></td>
        <td>
          <div class="row-actions">
            <a href="<?= url('admin/properties/' . $p['id'] . '/edit') ?>">Edit</a>
            <a href="<?= url('admin/properties/' . $p['id'] . '/images') ?>">Photos</a>
            <?php if ($p['publish_status'] === 'pending' && Auth::can('properties.approve')): ?>
              <form method="post" action="<?= url('admin/properties/' . $p['id'] . '/approve') ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button type="submit">Approve</button></form>
            <?php endif; ?>
            <?php if ($p['publish_status'] === 'published'): ?><a href="<?= url('property/' . $p['slug']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="6">No properties match these filters.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="<?= url('admin/properties', $filters + ['page' => $page - 1]) ?>" aria-label="Previous page"><?= icon('chevron', 'rotate') ?></a><?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?><a href="<?= url('admin/properties', $filters + ['page' => $i]) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= url('admin/properties', $filters + ['page' => $page + 1]) ?>" aria-label="Next page"><?= icon('chevron') ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

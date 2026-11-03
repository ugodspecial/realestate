<?php /** Variables: $items, $table, $label. */ ?>
<div class="admin-top">
  <div>
    <h1><?= $label === 'Category' ? 'Categories' : 'Locations' ?></h1>
    <p>These power the website’s search filters<?= $label === 'Location' ? ' and featured locations' : '' ?>. <?= count($items) ?> in use.</p>
  </div>
</div>
<div class="admin-cols" style="grid-template-columns:1.2fr .8fr">
  <div class="admin-card">
    <h2>All <?= strtolower($label) ?>s</h2>
    <p>Click a row’s edit button to change it below.</p>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Name</th><th>URL slug</th><th>Live listings</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><strong><?= e($item['name']) ?></strong><?php if ($table === 'locations'): ?><br><small><?= e($item['city']) ?></small><?php endif; ?></td>
          <td><small><?= e($item['slug']) ?></small></td>
          <td><?= $item['live_count'] ?></td>
          <td><div class="row-actions">
            <button type="button" data-edit-taxonomy="<?= e(json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">Edit</button>
            <?php if (!$item['live_count']): ?><form method="post" action="<?= url('admin/' . $table . '/delete/' . $item['id']) ?>" data-confirm="Delete “<?= e($item['name']) ?>”?"><?= csrf_field() ?><button type="submit" class="danger">Delete</button></form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="admin-card admin-form">
    <h2 id="taxonomyFormTitle">Add <?= strtolower($label) ?></h2>
    <p>Keep names short — they appear in search filters.</p>
    <form method="post" action="<?= url('admin/' . $table . '/save') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="taxId" value="">
      <div class="field"><label for="taxName">Name <span>*</span></label><input id="taxName" name="name" required maxlength="100"></div>
      <div class="field" style="margin-top:12px"><label for="taxSlug">URL slug <small>(leave blank to auto-generate)</small></label><input id="taxSlug" name="slug" maxlength="120" placeholder="e.g. <?= $table === 'categories' ? 'duplexes' : 'lekki' ?>"></div>
      <?php if ($table === 'categories'): ?>
      <div class="field" style="margin-top:12px"><label for="taxIcon">Icon</label><select id="taxIcon" name="icon"><?php foreach (['home' => 'Home', 'building' => 'Apartments', 'land' => 'Land', 'key' => 'Rentals', 'building' => 'Commercial'] as $v => $l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <?php else: ?>
      <div class="field" style="margin-top:12px"><label for="taxCity">City</label><input id="taxCity" name="city" maxlength="80" placeholder="e.g. Lagos"></div>
      <?php endif; ?>
      <div class="field" style="margin-top:12px"><label for="taxDesc">Description</label><textarea id="taxDesc" name="description" rows="3"></textarea></div>
      <div class="form-actions"><button class="btn btn-primary btn-small" type="submit" id="taxonomySubmit">Add <?= strtolower($label) ?></button><button class="btn btn-outline btn-small" type="button" id="taxonomyReset" hidden>Cancel edit</button></div>
    </form>
  </div>
</div>

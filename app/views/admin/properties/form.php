<?php
/** Variables: $property(?array), $categories, $locations. */
$v = fn($key, $default = '') => old($key, $property[$key] ?? $default);
$isEdit = (bool)$property;
$action = $isEdit ? 'admin/properties/' . $property['id'] . '/edit' : 'admin/properties/new';
?>
<div class="admin-top">
  <div>
    <h1><?= $isEdit ? 'Edit property' : 'Add property' ?></h1>
    <p><?= $isEdit ? e($property['title']) . ' · ' . e($property['listing_id']) : 'Fill in the details below. You will add photographs on the next step.' ?></p>
  </div>
  <div class="admin-actions">
    <?php if ($isEdit): ?><a class="btn btn-outline btn-small" href="<?= url('admin/properties/' . $property['id'] . '/images') ?>">Manage photos</a><?php endif; ?>
    <a class="btn btn-outline btn-small" href="<?= url('admin/properties') ?>">All properties</a>
  </div>
</div>
<?php if ($isEdit): ?>
<div class="admin-card">
  <h2>Listing status</h2>
  <p>Publication: <?= badge($property['publish_status']) ?> &nbsp;Availability: <?= badge($property['status']) ?><?= $property['featured'] ? ' &nbsp;★ Featured on homepage' : '' ?></p>
  <div class="row-actions" style="margin-top:10px">
    <?php if (Auth::can('properties.approve') && $property['publish_status'] !== 'published'): ?>
      <form method="post" action="<?= url('admin/properties/' . $property['id'] . '/approve') ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button type="submit">Approve &amp; publish</button></form>
    <?php endif; ?>
    <?php if (Auth::can('properties.approve') && $property['publish_status'] === 'pending'): ?>
      <form method="post" action="<?= url('admin/properties/' . $property['id'] . '/approve') ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="reject"><button type="submit">Return to drafts</button></form>
    <?php endif; ?>
    <?php if ($property['publish_status'] === 'published'): ?><a href="<?= url('property/' . $property['slug']) ?>" target="_blank" rel="noopener">View live page</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<form method="post" action="<?= url($action) ?>">
<?= csrf_field() ?>
<div class="admin-card admin-form">
  <h2>Basic details</h2>
  <p>Write for buyers and tenants — clear titles and honest descriptions earn trust.</p>
  <div class="form-grid">
    <div class="field full"><label for="pfTitle">Property title <span>*</span></label><input id="pfTitle" name="title" value="<?= e($v('title')) ?>" required maxlength="180" placeholder="e.g. 4 Bedroom Detached Duplex with BQ in Lekki Phase 1"></div>
    <div class="field"><label for="pfCategory">Category <span>*</span></label><select id="pfCategory" name="category_id" required><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$v('category_id') === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="pfLocation">Location <span>*</span></label><select id="pfLocation" name="location_id" required><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= (int)$v('location_id') === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?> (<?= e($l['city']) ?>)</option><?php endforeach; ?></select></div>
    <div class="field"><label for="pfDeal">Classification <span>*</span></label><select id="pfDeal" name="deal"><option value="sale" <?= $v('deal', 'sale') === 'sale' ? 'selected' : '' ?>>For sale</option><option value="rent" <?= $v('deal') === 'rent' ? 'selected' : '' ?>>For rent</option></select></div>
    <div class="field"><label for="pfStatus">Availability <span>*</span></label><select id="pfStatus" name="status"><?php foreach (['available' => 'Available', 'sold' => 'Sold', 'rented' => 'Rented'] as $sv => $sl): ?><option value="<?= $sv ?>" <?= $v('status', 'available') === $sv ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select><span class="field-help">Sold and rented listings stay visible but marked accordingly.</span></div>
    <div class="field"><label for="pfPrice">Price (₦) <span>*</span></label><input id="pfPrice" name="price" inputmode="numeric" value="<?= e($v('price')) ?>" required placeholder="e.g. 185000000"><span class="field-help">Numbers only. Rentals are shown per year.</span></div>
    <div class="field"><label for="pfAddress">Address / neighborhood <span>*</span></label><input id="pfAddress" name="address" value="<?= e($v('address')) ?>" required maxlength="255" placeholder="e.g. Admiralty Way, Lekki Phase 1"></div>
    <div class="field full"><label for="pfDesc">Description <span>*</span> <small>(at least 30 characters)</small></label><textarea id="pfDesc" name="description" required rows="7" placeholder="Describe the layout, finishes, light, parking, security, and what makes this property special."><?= e($v('description')) ?></textarea></div>
  </div>
</div>
<div class="admin-card admin-form">
  <h2>Specifications</h2>
  <p>Use 0 where a specification does not apply (for example, land has 0 bedrooms).</p>
  <div class="form-grid">
    <div class="field"><label for="pfBeds">Bedrooms</label><input id="pfBeds" type="number" name="beds" min="0" max="60" value="<?= e($v('beds', '0')) ?>"></div>
    <div class="field"><label for="pfBaths">Bathrooms</label><input id="pfBaths" type="number" name="baths" min="0" max="60" value="<?= e($v('baths', '0')) ?>"></div>
    <div class="field"><label for="pfParking">Parking spaces</label><input id="pfParking" type="number" name="parking" min="0" max="200" value="<?= e($v('parking', '0')) ?>"></div>
    <div class="field"><label for="pfArea">Plot / floor area (sqm)</label><input id="pfArea" type="number" name="area_sqm" min="0" step="0.01" value="<?= e($v('area_sqm', '0')) ?>"></div>
    <div class="field full"><label for="pfAmen">Amenities &amp; features <small>(one per line)</small></label><textarea id="pfAmen" name="amenities" rows="4" placeholder="En-suite bedrooms&#10;Fitted kitchen&#10;24-hour security&#10;Ample parking"><?= e($v('amenities')) ?></textarea></div>
  </div>
</div>
<div class="admin-card admin-form">
  <h2>Visibility &amp; search</h2>
  <p>Featured listings appear first on the homepage. SEO fields are optional.</p>
  <div class="form-grid">
    <div class="field"><label class="checkbox-label" style="margin:26px 0 0"><input type="checkbox" name="featured" value="1" <?= (int)$v('featured', '0') ? 'checked' : '' ?>> Feature this property on the homepage</label></div>
    <?php if (!$isEdit && Auth::can('properties.approve')): ?>
    <div class="field"><label class="checkbox-label" style="margin:26px 0 0"><input type="checkbox" name="publish" value="1"> Publish immediately (otherwise it goes to drafts)</label></div>
    <?php elseif ($isEdit && Auth::can('properties.approve')): ?>
    <div class="field"><label class="checkbox-label" style="margin:26px 0 0"><input type="checkbox" name="publish" value="1"> Publish / keep published</label><label class="checkbox-label" style="margin:10px 0 0"><input type="checkbox" name="unpublish" value="1"> Move back to drafts</label></div>
    <?php elseif (!$isEdit): ?>
    <div class="field"><p class="field-help" style="margin-top:26px">Your new listing will be sent for approval before it appears on the website.</p></div>
    <?php endif; ?>
    <div class="field"><label for="pfSeoTitle">SEO title <small>(optional)</small></label><input id="pfSeoTitle" name="seo_title" value="<?= e($v('seo_title')) ?>" maxlength="180"></div>
    <div class="field"><label for="pfSeoDesc">SEO description <small>(optional)</small></label><input id="pfSeoDesc" name="seo_description" value="<?= e($v('seo_description')) ?>" maxlength="320"></div>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> <?= $isEdit ? 'Save changes' : 'Save & continue to photos' ?></button>
    <a class="btn btn-outline" href="<?= url('admin/properties') ?>">Cancel</a>
  </div>
</div>
</form>
<?php if ($isEdit): ?>
<div class="admin-card">
  <h2>Delete this listing</h2>
  <p>Permanently removes the property and all its photographs. This cannot be undone.</p>
  <form method="post" action="<?= url('admin/properties/' . $property['id'] . '/delete') ?>" data-confirm="Delete “<?= e($property['title']) ?>” permanently?"><?= csrf_field() ?><button class="btn btn-danger btn-small" type="submit"><?= icon('trash') ?> Delete property</button></form>
</div>
<?php endif; ?>

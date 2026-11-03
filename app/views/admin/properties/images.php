<?php /** Variables: $property, $images. */ ?>
<div class="admin-top">
  <div>
    <h1>Photographs</h1>
    <p><?= e($property['title']) ?> · <?= e($property['listing_id']) ?> · <?= count($images) ?> of 20 photos</p>
  </div>
  <div class="admin-actions">
    <a class="btn btn-outline btn-small" href="<?= url('admin/properties/' . $property['id'] . '/edit') ?>">Edit details</a>
    <a class="btn btn-outline btn-small" href="<?= url('admin/properties') ?>">All properties</a>
  </div>
</div>
<div class="admin-card">
  <h2>Upload new photographs</h2>
  <p>JPG, PNG, or WebP up to 5&nbsp;MB each. Photos are resized automatically; the first photo becomes the cover.</p>
  <form method="post" action="<?= url('admin/properties/' . $property['id'] . '/images') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="dropzone" id="dropzone"><?= icon('images') ?><br><strong>Drag photos here</strong> or <u>browse your device</u><br><small>Up to 8 at a time</small><input type="file" name="images[]" id="imageInput" accept="image/jpeg,image/png,image/webp" multiple required style="margin-top:14px"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('download') ?> Upload photographs</button></div>
  </form>
</div>
<div class="admin-card">
  <h2>Manage photographs</h2>
  <p>Set the cover photo visitors see first, write captions, or remove photos.</p>
  <?php if (!$images): ?><p>No photographs yet. Upload at least one so this listing looks its best.</p><?php endif; ?>
  <div class="image-manager">
    <?php foreach ($images as $img): ?>
      <div class="image-item">
        <img src="<?= e(image_url($img['path'])) ?>" alt="<?= e($img['alt']) ?>" loading="lazy">
        <div>
          <?php if ($img['is_cover']): ?><span class="badge badge-featured">Cover photo</span><?php endif; ?>
          <form method="post" action="<?= url('admin/images/' . $img['id'] . '/caption') ?>"><?= csrf_field() ?><input type="text" name="alt" value="<?= e($img['alt']) ?>" maxlength="255" aria-label="Photo caption"><button class="btn btn-outline btn-small" type="submit" style="margin-top:6px">Save caption</button></form>
          <div class="row-actions">
            <?php if (!$img['is_cover']): ?><form method="post" action="<?= url('admin/images/' . $img['id'] . '/cover') ?>"><?= csrf_field() ?><button type="submit">Make cover</button></form><?php endif; ?>
            <form method="post" action="<?= url('admin/images/' . $img['id'] . '/delete') ?>" data-confirm="Delete this photograph?"><?= csrf_field() ?><button type="submit" class="danger">Delete</button></form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

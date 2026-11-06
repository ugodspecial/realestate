<?php /** Variables: $items. */ ?>
<div class="admin-top">
  <div>
    <h1>Testimonials</h1>
    <p>Approved stories appear on the homepage. Only publish words you have permission to share.</p>
  </div>
</div>
<div class="admin-cols admin-cols-wide">
  <div class="admin-card">
    <h2>All testimonials</h2>
    <p><?= count($items) ?> total.</p>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Client</th><th>Quote</th><th>Rating</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $t): ?>
        <tr>
          <td><strong><?= e($t['name']) ?></strong><br><small><?= e($t['location']) ?></small></td>
          <td><small><?= e(excerpt($t['quote'], 120)) ?></small></td>
          <td><?= str_repeat('★', (int)$t['rating']) ?></td>
          <td><?= $t['approved'] ? badge('published', 'Shown') : badge('pending', 'Hidden') ?></td>
          <td><div class="row-actions">
            <form method="post" action="<?= url('admin/testimonials/save') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="name" value="<?= e($t['name']) ?>"><input type="hidden" name="location" value="<?= e($t['location']) ?>"><input type="hidden" name="quote" value="<?= e($t['quote']) ?>"><input type="hidden" name="rating" value="<?= $t['rating'] ?>"><?= $t['approved'] ? '' : '<input type="hidden" name="approved" value="1">' ?><button type="submit"><?= $t['approved'] ? 'Hide' : 'Approve' ?></button></form>
            <form method="post" action="<?= url('admin/testimonials/delete/' . $t['id']) ?>" data-confirm="Delete this testimonial?"><?= csrf_field() ?><button type="submit" class="danger">Delete</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="5">No testimonials yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
  <div class="admin-card admin-form">
    <h2>Add testimonial</h2>
    <p>Real names, real places, real experiences.</p>
    <form method="post" action="<?= url('admin/testimonials/save') ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="tmName">Client name <span>*</span></label><input id="tmName" name="name" required maxlength="120"></div>
      <div class="field" style="margin-top:12px"><label for="tmLoc">Location / context</label><input id="tmLoc" name="location" maxlength="120" placeholder="e.g. Homeowner, Lekki"></div>
      <div class="field" style="margin-top:12px"><label for="tmQuote">Testimonial <span>*</span></label><textarea id="tmQuote" name="quote" rows="4" required></textarea></div>
      <div class="field" style="margin-top:12px"><label for="tmRating">Rating</label><select id="tmRating" name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></option><?php endfor; ?></select></div>
      <label class="checkbox-label"><input type="checkbox" name="approved" value="1" checked> Show on the website immediately</label>
      <div class="form-actions"><button class="btn btn-primary btn-small" type="submit">Add testimonial</button></div>
    </form>
  </div>
</div>

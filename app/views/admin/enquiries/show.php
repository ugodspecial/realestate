<?php /** Variables: $enquiry. */ $e = $enquiry; ?>
<div class="admin-top">
  <div>
    <h1><?= e($e['reference']) ?></h1>
    <p>Received <?= e(date('d M Y \a\t H:i', strtotime($e['created_at']))) ?> · <?= badge($e['status'], ucfirst($e['status'])) ?></p>
  </div>
  <div class="admin-actions"><a class="btn btn-outline btn-small" href="<?= url('admin/enquiries') ?>">All enquiries</a></div>
</div>
<div class="admin-cols">
  <div class="admin-card">
    <h2>Message</h2>
    <p><strong><?= e($e['name']) ?></strong> · <a href="mailto:<?= e($e['email']) ?>"><?= e($e['email']) ?></a> · <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $e['phone'])) ?>"><?= e($e['phone']) ?></a></p>
    <p><strong>Type:</strong> <?= e(ucfirst($e['type'])) ?><?php if ($e['preferred_date']): ?> · <strong>Preferred date:</strong> <?= e($e['preferred_date']) ?><?php endif; ?></p>
    <?php if ($e['property_title']): ?><p><strong>About:</strong> <?= e($e['property_title']) ?> (<?= e($e['listing_id']) ?>)</p><?php endif; ?>
    <p style="white-space:pre-line;background:var(--paper);border:1px solid var(--line);border-radius:3px;padding:18px;margin-top:14px"><?= e($e['message']) ?></p>
    <div class="row-actions" style="margin-top:16px">
      <a href="mailto:<?= e($e['email']) ?>?subject=<?= rawurlencode('Re: Your enquiry ' . $e['reference']) ?>">Reply by email</a>
      <a href="https://wa.me/<?= e(preg_replace('/\D/', '', $e['phone'])) ?>?text=<?= rawurlencode('Hello ' . $e['name'] . ', thank you for your enquiry (' . $e['reference'] . ').') ?>" target="_blank" rel="noopener">WhatsApp</a>
    </div>
  </div>
  <div class="admin-card admin-form">
    <h2>Follow-up</h2>
    <p>Track this enquiry and keep internal notes.</p>
    <form method="post" action="<?= url('admin/enquiries/' . $e['id']) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="eqStatus">Status</label><select id="eqStatus" name="status"><?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $v => $l): ?><option value="<?= $v ?>" <?= $e['status'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin-top:14px"><label for="eqNotes">Internal notes <small>(never shown to the visitor)</small></label><textarea id="eqNotes" name="admin_notes" rows="6" placeholder="Calls made, inspection booked, documents sent…"><?= e($e['admin_notes']) ?></textarea></div>
      <div class="form-actions"><button class="btn btn-primary btn-small" type="submit"><?= icon('check') ?> Save follow-up</button></div>
    </form>
  </div>
</div>

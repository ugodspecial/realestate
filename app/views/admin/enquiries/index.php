<?php /** Variables: $items, $status, $counts. */ ?>
<div class="admin-top">
  <div>
    <h1>Enquiries</h1>
    <p><?= count($items) ?> shown. Follow up quickly — every enquiry is a relationship.</p>
  </div>
</div>
<div class="admin-card">
  <div class="filter-bar" role="group" aria-label="Filter by status" style="margin-bottom:20px">
    <a class="btn btn-small <?= $status === '' ? 'btn-primary' : 'btn-outline' ?>" href="<?= url('admin/enquiries') ?>">All (<?= array_sum($counts) ?>)</a>
    <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $v => $l): ?>
      <a class="btn btn-small <?= $status === $v ? 'btn-primary' : 'btn-outline' ?>" style="<?= $status === $v ? '' : 'margin-top:0' ?>" href="<?= url('admin/enquiries', ['status' => $v]) ?>"><?= $l ?> (<?= $counts[$v] ?? 0 ?>)</a>
    <?php endforeach; ?>
  </div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Reference</th><th>From</th><th>Property</th><th>Type</th><th>Received</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($items as $e): ?>
      <tr>
        <td><a href="<?= url('admin/enquiries/' . $e['id']) ?>"><strong><?= e($e['reference']) ?></strong></a></td>
        <td><?= e($e['name']) ?><br><small><?= e($e['phone']) ?></small></td>
        <td><?= $e['property_title'] ? e($e['property_title']) : '<small>General enquiry</small>' ?></td>
        <td><?= e(ucfirst($e['type'])) ?></td>
        <td><small><?= e(date('d M Y, H:i', strtotime($e['created_at']))) ?></small></td>
        <td><?= badge($e['status'], ucfirst($e['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="6">No enquiries here yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

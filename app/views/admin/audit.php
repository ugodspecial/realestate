<?php /** Variables: $items, $total, $page, $pages. */ ?>
<div class="admin-top">
  <div>
    <h1>Activity log</h1>
    <p><?= $total ?> recorded actions. Sign-ins, approvals, edits, and deletions — newest first.</p>
  </div>
</div>
<div class="admin-card">
  <div class="audit-list">
    <?php foreach ($items as $a): ?>
      <div><time><?= e(date('d M Y, H:i', strtotime($a['created_at']))) ?></time><div><b><?= e($a['user_name'] ?? 'System') ?></b> — <code><?= e($a['action']) ?></code> · <?= e($a['entity_type']) ?><?= $a['entity_id'] ? ' #' . $a['entity_id'] : '' ?> · <?= e($a['details']) ?> <small>(<?= e($a['ip']) ?>)</small></div></div>
    <?php endforeach; ?>
    <?php if (!$items): ?><p>No activity recorded yet.</p><?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="<?= url('admin/audit', ['page' => $page - 1]) ?>" aria-label="Previous page"><?= icon('chevron', 'rotate') ?></a><?php endif; ?>
      <span style="font-size:12px;color:#8b9880">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= url('admin/audit', ['page' => $page + 1]) ?>" aria-label="Next page"><?= icon('chevron') ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

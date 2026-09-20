<?php
use App\Icons;
/** @var array $assigned @var array $open @var string $active */
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Staff dashboard</h1>
      <p class="muted">Your work orders, plus read-only catalog access.</p></div></div>

    <div class="kpi-row">
      <div class="card stat"><span class="label">Assigned to you</span><span class="value"><?= count($assigned) ?></span></div>
      <div class="card stat accent"><span class="label">Still pending</span><span class="value"><?= count($open) ?></span></div>
    </div>

    <div class="page-head" style="margin-top:8px">
      <h2><?= Icons::get('clipboard', 18) ?> Assigned work orders</h2>
      <a class="btn btn-sm btn-primary" href="<?= e(url('admin/work-orders')) ?>">Open work orders</a>
    </div>
    <div class="table-wrap">
      <table class="grid">
        <thead><tr><th>Order</th><th>Client</th><th>Product</th><th>Qty</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$assigned): ?><tr><td colspan="5" class="empty">Nothing assigned yet.</td></tr><?php endif; ?>
        <?php foreach ($assigned as $w): ?>
          <tr>
            <td>#<?= (int)$w['order_id'] ?></td>
            <td><?= e(trim((string)$w['client_name']) !== '' ? $w['client_name'] : $w['client_email']) ?></td>
            <td><?= e($w['title']) ?></td>
            <td><?= (int)$w['qty'] ?></td>
            <td><span class="badge <?= e($w['status'] === 'complete' ? 'completed' : ($w['status'] === 'filled_from_stock' ? 'filled_from_stock' : ($w['status'] === 'stalled' ? 'stalled' : 'pending'))) ?>"><?= e($w['status'] === 'filled_from_stock' ? 'Filled from stock' : $w['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

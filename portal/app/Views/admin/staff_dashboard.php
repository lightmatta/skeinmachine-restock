<?php
use App\Icons;
use App\RestockOrders;
/** @var string $active */
$groups = RestockOrders::grouped();
$stats = RestockOrders::stats();
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Staff dashboard</h1>
      <p class="muted">Restock lines that have reached their minimum, plus read-only catalog access.</p></div></div>

    <div class="kpi-row">
      <div class="card stat"><span class="label">Below min</span><span class="value"><?= (int)$stats['below_min'] ?></span></div>
      <div class="card stat accent"><span class="label">Vendor out of stock</span><span class="value"><?= (int)$stats['unfulfillable'] ?></span></div>
    </div>

    <div class="page-head" style="margin-top:8px">
      <h2><?= Icons::get('clipboard', 18) ?> Restock orders</h2>
      <a class="btn btn-sm btn-primary" href="<?= e(url('admin/work-orders')) ?>">Open restock orders</a>
    </div>
    <div class="table-wrap">
      <table class="grid">
        <thead><tr><th>Vendor</th><th>Product</th><th>Our stock</th><th>Min</th><th>Vendor stock</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$groups): ?><tr><td colspan="6" class="empty">Nothing is below its restock minimum.</td></tr><?php endif; ?>
        <?php foreach ($groups as $g): ?>
          <?php foreach (array_merge($g['fulfillable'], $g['unfulfillable']) as $line): ?>
          <tr>
            <td><?= e((string)$g['vendor_name']) ?></td>
            <td><?= e((string)$line['title']) ?></td>
            <td><?= (int)$line['stock'] ?></td>
            <td><?= (int)$line['min_qty'] ?></td>
            <td><?= (int)$line['vendor_stock'] ?></td>
            <td><span class="badge <?= $line['fulfillable'] ? 'pending' : 'stalled' ?>"><?= $line['fulfillable'] ? 'can order' : 'vendor 0' ?></span></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

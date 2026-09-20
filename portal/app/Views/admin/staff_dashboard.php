<?php
use App\Icons;
use App\RestockOrders;
/** @var string $active @var list<array<string,mixed>> $reports */
$reports = $reports ?? RestockOrders::goalReports();
$below = 0;
foreach ($reports as $g) {
    $below += count($g['lines'] ?? []);
}
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Staff dashboard</h1>
      <p class="muted">Items below Goal, plus read-only catalog and source access.</p></div></div>

    <div class="kpi-row">
      <div class="card stat accent"><span class="label">Below goal</span><span class="value"><?= (int)$below ?></span></div>
      <div class="card stat"><span class="label">Vendors</span><span class="value"><?= count($reports) ?></span></div>
    </div>

    <div class="page-head" style="margin-top:8px">
      <h2><?= Icons::get('clipboard', 18) ?> Restock reports</h2>
      <a class="btn btn-sm btn-primary" href="<?= e(url('home')) ?>">Open Reports</a>
    </div>
    <div class="table-wrap">
      <table class="grid">
        <thead><tr><th>Vendor / Collection</th><th>Items</th></tr></thead>
        <tbody>
        <?php if (!$reports): ?><tr><td colspan="2" class="empty">Nothing is below its goal stock level.</td></tr><?php endif; ?>
        <?php foreach ($reports as $g): ?>
          <tr>
            <td><?= e((string)($g['label'] ?? $g['vendor_name'])) ?></td>
            <td><?= count($g['lines'] ?? []) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

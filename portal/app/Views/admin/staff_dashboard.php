<?php
use App\Icons;
use App\RestockOrders;
/** @var string $active @var list<array<string,mixed>> $reports @var list<array<string,mixed>> $attention */
$reports = $reports ?? RestockOrders::goalReports();
$attention = $attention ?? RestockOrders::attentionReports($reports, 3);
$attentionKeys = [];
foreach ($attention as $ag) {
    $attentionKeys[strtolower((string)($ag['vendor_name'] ?? ''))] = (int)($ag['below_min'] ?? 0);
}
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

    <?php if (!empty($attention)): ?>
    <div class="card attention-card" style="margin-bottom:20px">
      <h2><?= Icons::get('alert', 18) ?> Needs attention</h2>
      <p class="muted">The <?= count($attention) === 1 ? 'vendor report' : 'top ' . count($attention) . ' vendor reports' ?> with the most products at or below Min.</p>
      <ol class="attention-list">
        <?php foreach ($attention as $i => $ag): ?>
          <li class="attention-item">
            <span class="attention-rank"><?= $i + 1 ?></span>
            <div class="attention-copy">
              <strong><?= e((string)($ag['label'] ?? $ag['vendor_name'])) ?></strong>
              <span class="muted"><?= (int)$ag['below_min'] ?> below min · <?= count($ag['lines'] ?? []) ?> below goal</span>
            </div>
            <a class="btn btn-sm" href="<?= e(url('home')) ?>">Open Reports</a>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>

    <div class="page-head" style="margin-top:8px">
      <h2><?= Icons::get('clipboard', 18) ?> Restock reports</h2>
      <a class="btn btn-sm btn-primary" href="<?= e(url('home')) ?>">Open Reports</a>
    </div>
    <div class="table-wrap">
      <table class="grid">
        <thead><tr><th>Vendor / Collection</th><th>Items</th><th></th></tr></thead>
        <tbody>
        <?php if (!$reports): ?><tr><td colspan="3" class="empty">Nothing is below its goal stock level.</td></tr><?php endif; ?>
        <?php foreach ($reports as $g):
          $key = strtolower((string)($g['vendor_name'] ?? ''));
          $hot = array_key_exists($key, $attentionKeys);
        ?>
          <tr class="<?= $hot ? 'is-attention' : '' ?>">
            <td><?= e((string)($g['label'] ?? $g['vendor_name'])) ?><?php if ($hot): ?> <span class="badge hl">Needs attention</span><?php endif; ?></td>
            <td><?= count($g['lines'] ?? []) ?></td>
            <td><?= $hot ? (int)$attentionKeys[$key] . ' below min' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

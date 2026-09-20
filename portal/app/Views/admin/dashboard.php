<?php
use App\Icons;
/** @var array $unread @var array $activity @var array $stats @var array $restockGroups @var string $active */
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Overview</h1>
      <p class="muted">Vendor restock status at a glance.</p></div></div>

    <div class="kpi-row">
      <div class="card stat"><span class="label">Vendors</span><span class="value"><?= (int)($stats['vendors'] ?? 0) ?></span></div>
      <div class="card stat accent"><span class="label">Below restock min</span><span class="value"><?= (int)($stats['below_min'] ?? 0) ?></span></div>
      <div class="card stat"><span class="label">Can order now</span><span class="value"><?= (int)($stats['fulfillable'] ?? 0) ?></span></div>
      <div class="card stat"><span class="label">Vendor stock 0</span><span class="value"><?= (int)($stats['unfulfillable'] ?? 0) ?></span></div>
    </div>

    <?php if (!empty($restockGroups)): ?>
    <div class="card" style="margin-bottom:20px">
      <h2><?= Icons::get('clipboard', 18) ?> Restock needed</h2>
      <p class="muted">Catalog items at or below Min, grouped by vendor. Open Restock Orders for the full breakdown.</p>
      <ul class="help" style="margin:8px 0 0">
        <?php foreach ($restockGroups as $rg): ?>
          <li><?= e((string)$rg['vendor_name']) ?> · <?= count($rg['fulfillable']) ?> available · <?= count($rg['unfulfillable']) ?> unfulfillable</li>
        <?php endforeach; ?>
      </ul>
      <p style="margin:12px 0 0"><a class="btn btn-sm btn-primary" href="<?= e(url('admin/work-orders')) ?>">Open restock orders</a></p>
    </div>
    <?php endif; ?>

    <div class="split" style="align-items:start">
      <div class="card chats-box">
        <h2><?= Icons::get('message', 18) ?> Chats needing a reply</h2>
        <?php if (!$unread): ?><p class="muted" style="margin-bottom:0">All caught up.</p><?php endif; ?>
        <?php foreach ($unread as $u): ?>
          <a class="list-tile" href="<?= e(url('admin/messages')) ?>">
            <strong><?= e($u['email']) ?></strong> <span class="badge hl"><?= (int)$u['unread'] ?> new</span>
            <div class="muted"><?= fmt_datetime($u['last_at']) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <h2><?= Icons::get('clock', 18) ?> Recent activity</h2>
        <?php if (!$activity): ?><p class="muted">No activity yet.</p><?php endif; ?>
        <?php foreach ($activity as $act): ?>
          <div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid var(--line)">
            <span class="badge"><?= e($act['type']) ?></span>
            <span><?= e($act['description']) ?></span>
            <span class="muted" style="margin-left:auto"><?= fmt_datetime($act['created_at']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

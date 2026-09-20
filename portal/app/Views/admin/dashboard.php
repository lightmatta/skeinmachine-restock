<?php
use App\Icons;
use App\RestockOrders;
/** @var array $unread @var array $activity @var array $stats @var array $reports @var array $attention @var string $active */
$reports = $reports ?? [];
$attention = $attention ?? RestockOrders::attentionReports($reports, 3);
$attentionKeys = [];
foreach ($attention as $ag) {
    $attentionKeys[strtolower((string)($ag['vendor_name'] ?? ''))] = (int)($ag['below_min'] ?? 0);
}
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Overview</h1>
      <p class="muted">Sources, catalog, and restock reports at a glance.</p></div></div>

    <div class="kpi-row">
      <div class="card stat"><span class="label">Sources</span><span class="value"><?= (int)($stats['sources'] ?? 0) ?></span></div>
      <div class="card stat"><span class="label">Vendors</span><span class="value"><?= (int)($stats['vendors'] ?? 0) ?></span></div>
      <div class="card stat"><span class="label">Products</span><span class="value"><?= (int)($stats['products'] ?? 0) ?></span></div>
      <div class="card stat accent"><span class="label">Below goal</span><span class="value"><?= (int)($stats['below_goal'] ?? 0) ?></span></div>
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

    <?php if (!empty($reports)): ?>
    <div class="card" style="margin-bottom:20px">
      <h2><?= Icons::get('clipboard', 18) ?> Restock reports</h2>
      <p class="muted">Catalog items below Goal, grouped by vendor / collection.</p>
      <ul class="help report-overview-list" style="margin:8px 0 0">
        <?php foreach ($reports as $rg):
          $key = strtolower((string)($rg['vendor_name'] ?? ''));
          $hot = array_key_exists($key, $attentionKeys);
        ?>
          <li class="<?= $hot ? 'is-attention' : '' ?>">
            <?= e((string)($rg['label'] ?? $rg['vendor_name'])) ?>
            · <?= count($rg['lines'] ?? []) ?> item<?= count($rg['lines'] ?? []) === 1 ? '' : 's' ?>
            <?php if ($hot): ?><span class="badge hl">Needs attention</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p style="margin:12px 0 0"><a class="btn btn-sm btn-primary" href="<?= e(url('home')) ?>">Open Reports</a></p>
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

<?php
use App\Icons;
/** @var array $pendingApps @var array $newOrders @var array $unread @var array $activity @var array $stats @var array $unassignedOrders @var array $stalledWork @var string $active */
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Overview</h1>
      <p class="muted">Everything that needs your attention, at a glance.</p></div></div>

    <?php if (!empty($unassignedOrders)): ?>
    <div class="flash error dash-alert" role="alert">
      <h2><?= Icons::get('users', 18) ?> Provisioning orders without staff</h2>
      <p style="margin:6px 0 0;font-weight:400">These provisioning orders still have work that nobody is assigned to. Allocate a staff member on Work Orders.</p>
      <ul>
        <?php foreach ($unassignedOrders as $uo):
          $name = trim((string)($uo['client_name'] ?? '')) ?: (string)($uo['client_email'] ?? '');
          $n = (int)$uo['unassigned_items'];
        ?>
          <li>Order #<?= (int)$uo['order_id'] ?> · <?= e($name) ?> · <?= $n ?> unassigned item<?= $n === 1 ? '' : 's' ?></li>
        <?php endforeach; ?>
      </ul>
      <p style="margin:12px 0 0"><a class="btn btn-sm btn-primary" href="<?= e(url('admin/work-orders')) ?>">Assign staff</a></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($stalledWork)): ?>
    <div class="flash warn dash-alert" role="alert">
      <h2><?= Icons::get('clock', 18) ?> Stalled work orders</h2>
      <p style="margin:6px 0 0;font-weight:400">Staff marked these items stalled. They need a decision before the order can finish.</p>
      <ul>
        <?php foreach ($stalledWork as $sw):
          $name = trim((string)($sw['client_name'] ?? '')) ?: (string)($sw['client_email'] ?? '');
          $staff = trim((string)($sw['staff_name'] ?? '')) ?: (string)($sw['staff_email'] ?? 'Unassigned');
          $note = trim((string)($sw['notes'] ?? ''));
        ?>
          <li>Order #<?= (int)$sw['order_id'] ?> · <?= e($name) ?> · <?= e($sw['title']) ?>
            <span class="muted">(<?= e($staff) ?>)</span>
            <?php if ($note !== ''): ?> — <?= e($note) ?><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p style="margin:12px 0 0"><a class="btn btn-sm" href="<?= e(url('admin/work-orders')) ?>">Open stalled work</a></p>
    </div>
    <?php endif; ?>

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

    <?php if ($pendingApps): ?>
    <h2><?= Icons::get('users', 18) ?> Pending wholesale applications <span class="badge hl"><?= count($pendingApps) ?></span></h2>
    <div class="table-wrap" style="margin:12px 0 24px">
      <table class="grid"><thead><tr><th>Applicant</th><th>Email</th><th>Company site</th><th>Applied</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pendingApps as $a): ?>
        <tr>
          <td><?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?></td>
          <td><?= e($a['email']) ?></td>
          <td><?= e($a['company_website'] ?? '') ?: '—' ?></td>
          <td><?= fmt_datetime($a['created_at']) ?></td>
          <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/users')) ?>">Review</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endif; ?>

    <?php if (!empty($readyOrders)): ?>
    <div class="card" style="margin-bottom:20px">
      <h2><?= Icons::get('check', 18) ?> Work orders ready for approval</h2>
      <p class="muted">All items on these provisioning orders are complete. Approve them on the Work Orders page to mark the client order completed.</p>
      <ul class="help" style="margin:8px 0 0">
        <?php foreach ($readyOrders as $ro): ?>
          <li>Order #<?= (int)$ro['order_id'] ?> · <?= e($ro['client_name']) ?></li>
        <?php endforeach; ?>
      </ul>
      <p style="margin:12px 0 0"><a class="btn btn-sm btn-primary" href="<?= e(url('admin/work-orders')) ?>">Open work orders</a></p>
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
        <h2><?= Icons::get('orders', 18) ?> New orders (payment pending first)</h2>
        <div class="table-wrap">
          <table class="grid"><thead><tr><th>#</th><th>Client</th><th>Status</th><th>Pay</th><th>Total</th><th></th></tr></thead>
          <tbody>
          <?php if (!$newOrders): ?><tr><td colspan="6" class="empty">No orders yet.</td></tr><?php endif; ?>
          <?php foreach ($newOrders as $o): ?>
            <tr>
              <td>#<?= (int)$o['id'] ?></td>
              <td><?= e($o['client_email']) ?></td>
              <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
              <td><span class="badge <?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
              <td><?= money((int)$o['total_cents']) ?></td>
              <td><a class="btn btn-sm btn-ghost" href="<?= e(url('admin/orders')) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
    </div>

    <h2 style="margin-top:24px"><?= Icons::get('clock', 18) ?> Recent activity</h2>
    <div class="card">
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

<?php
use App\Icons;
/** @var array $orders @var array $pending @var array $active @var int $spend */
?>
<div class="container">
  <div class="page-head"><div><h1><?= Icons::get('grid', 22) ?> Dashboard</h1>
    <p class="muted">Your wholesale orders at a glance.</p></div>
    <a class="btn btn-primary" href="<?= e(url('catalog')) ?>"><?= Icons::get('store', 18) ?> Browse catalog</a></div>

  <div class="kpi-row">
    <div class="card stat"><span class="label">Open orders</span><span class="value"><?= count($pending) + count($active) ?></span></div>
    <div class="card stat accent"><span class="label">Pending approval</span><span class="value"><?= count($pending) ?></span></div>
    <div class="card stat"><span class="label">Total orders</span><span class="value"><?= count($orders) ?></span></div>
    <div class="card stat"><span class="label">Lifetime value (<?= e(\App\Currency::company()) ?>)</span><span class="value"><?= money($spend) ?></span><?= fx_total_note($spend) ?></div>
  </div>

  <h2><?= Icons::get('truck', 18) ?> Active orders &amp; tracking</h2>
  <div class="table-wrap" style="margin:12px 0 26px">
    <table class="grid">
      <thead><tr><th>Order</th><th>Status</th><th>Payment</th><th>Total</th><th>Tracking</th><th>Placed</th><th></th></tr></thead>
      <tbody>
        <?php $act = array_merge($pending, $active); if (!$act): ?>
          <tr><td colspan="7" class="empty">No open orders. <a href="<?= e(url('catalog')) ?>">Start an order →</a></td></tr>
        <?php endif; ?>
        <?php foreach ($act as $o): ?>
          <tr>
            <td><a href="<?= e(url('order', ['id' => $o['id']])) ?>">#<?= (int)$o['id'] ?></a></td>
            <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td><span class="badge <?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
            <td><?= money((int)$o['total_cents']) ?><?= fx_compact((int)$o['total_cents']) ?></td>
            <td><?php if (!empty($o['tracking_url'])): ?><a href="<?= e($o['tracking_url']) ?>" target="_blank" rel="noopener"><?= Icons::get('truck', 15) ?> Track</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?= fmt_datetime($o['created_at']) ?></td>
            <td><a class="btn btn-sm btn-ghost" href="<?= e(url('order', ['id' => $o['id']])) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2><?= Icons::get('clock', 18) ?> Order history</h2>
  <div class="table-wrap" style="margin-top:12px">
    <table class="grid">
      <thead><tr><th>Order</th><th>Status</th><th>Items total</th><th>Placed</th><th></th></tr></thead>
      <tbody>
        <?php if (!$orders): ?><tr><td colspan="5" class="empty">No orders yet.</td></tr><?php endif; ?>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td>#<?= (int)$o['id'] ?></td>
            <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td><?= money((int)$o['total_cents']) ?><?= fx_compact((int)$o['total_cents']) ?></td>
            <td><?= fmt_datetime($o['created_at']) ?></td>
            <td><a class="btn btn-sm btn-ghost" href="<?= e(url('order', ['id' => $o['id']])) ?>">View</a>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('order.print', ['id' => $o['id']])) ?>" target="_blank"><?= Icons::get('print', 15) ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
use App\Icons;
/** @var array $order @var array $items @var bool $placed @var bool $canModify */
?>
<div class="container">
  <div class="page-head">
    <div><h1><?= Icons::get('orders', 22) ?> Order #<?= (int)$order['id'] ?></h1>
      <p class="muted">Placed <?= fmt_datetime($order['created_at']) ?></p></div>
    <div class="row-actions">
      <a class="btn btn-ghost" href="<?= e(url('order.print', ['id'=>$order['id']])) ?>" target="_blank"><?= Icons::get('print',18) ?> Print</a>
      <?php if ($canModify): ?>
        <form method="post" action="<?= e(url('order.cancel')) ?>" onsubmit="return confirm('Cancel this pending order?')">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
          <button class="btn btn-danger" type="submit"><?= Icons::get('x',18) ?> Cancel order</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($placed): ?><div class="flash ok">Your order was submitted and is now pending review.</div><?php endif; ?>

  <div class="kpi-row">
    <div class="card stat"><span class="label">Status</span><span class="value"><span class="badge <?= e($order['status']) ?>"><?= e($order['status']) ?></span></span></div>
    <div class="card stat"><span class="label">Payment</span><span class="value"><span class="badge <?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></span></div>
    <?php $totals = order_totals($order, $items); ?>
    <div class="card stat"><span class="label">Total (<?= e(\App\Currency::company()) ?>)</span><span class="value"><?= money($totals['total']) ?></span><?= fx_total_note($totals['total']) ?></div>
  </div>

  <?php if (!$canModify): ?>
    <div class="flash">This order is in <strong><?= e($order['status']) ?></strong> and can no longer be modified online. Please contact us to make changes.</div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:16px"><?= Icons::get('truck',18) ?> Tracking number:
    <?php if (!empty($order['tracking_url'])): ?>
      <a href="<?= e($order['tracking_url']) ?>" target="_blank" rel="noopener"><?= e($order['tracking_url']) ?></a>
    <?php else: ?>
      <span class="muted">Not yet available</span>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line total</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr><td><?= e($it['title']) ?></td><td><?= (int)$it['qty'] ?></td><td><?= money((int)$it['unit_price_cents']) ?></td><td><?= money((int)$it['line_total_cents']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php if ($totals['discount'] > 0): ?>
          <tr><th colspan="3" style="text-align:right">Subtotal (<?= e(\App\Currency::company()) ?>)</th><th><?= money($totals['subtotal']) ?></th></tr>
          <tr><th colspan="3" style="text-align:right">Additional Discount</th><th>−<?= money($totals['discount']) ?></th></tr>
        <?php endif; ?>
        <tr><th colspan="3" style="text-align:right">Total (<?= e(\App\Currency::company()) ?>)</th><th><?= money($totals['total']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?= fx_total_note($totals['total']) ?>
  <?php if (!empty($order['notes'])): ?><p class="muted" style="margin-top:12px"><strong>Your notes:</strong> <?= e($order['notes']) ?></p><?php endif; ?>
</div>

<?php
/** @var array $order @var array $items @var array $cust @var string $company @var string $contactEmail @var string $contactPhone */
$name = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Order #<?= (int)$order['id'] ?> · <?= e($company) ?></title>
<style>
 body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;max-width:760px;margin:24px auto;padding:0 20px;}
 h1{margin:0;font-size:22px;} .muted{color:#666;} table{width:100%;border-collapse:collapse;margin-top:16px;}
 th,td{border-bottom:1px solid #ddd;padding:8px 10px;text-align:left;font-size:14px;} tfoot th{border-top:2px solid #111;}
 .head{display:flex;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:14px;margin-bottom:14px;}
 .box{border:1px solid #ddd;border-radius:8px;padding:12px;margin-bottom:8px;font-size:14px;}
 .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
 @media print{ .noprint{display:none;} }
 .btn{display:inline-block;border:1.5px solid #111;border-radius:8px;padding:6px 12px;cursor:pointer;background:#fff;}
 .fx-estimate{margin-top:12px;font-size:13px;color:#444;}
 .fx-estimate .fx-amount{font-weight:600;color:#111;margin-bottom:4px;}
</style></head>
<body onload="if(!window.location.search.includes('noauto'))window.print()">
  <div class="head">
    <div><h1><?= e($company) ?></h1><div class="muted">Order confirmation</div></div>
    <div style="text-align:right"><strong>Order #<?= (int)$order['id'] ?></strong><br>
      <span class="muted">Placed <?= fmt_datetime($order['created_at']) ?></span><br>
      <span class="muted">Status: <?= e($order['status']) ?> · Payment: <?= e($order['payment_status']) ?></span></div>
  </div>

  <div class="grid2">
    <div class="box"><strong>Bill to</strong><br><?= e($name) ?><br><?= e($cust['email'] ?? '') ?><br><?= nl2br(e($cust['office_address'] ?? '')) ?></div>
    <div class="box"><strong>Deliver to</strong><br><?= nl2br(e($cust['delivery_address'] ?? '')) ?><br>Phone: <?= e($cust['phone'] ?? '') ?></div>
  </div>
  <div class="box">
    <?php if (!empty($order['notes'])): ?><div><strong>Notes:</strong> <?= e($order['notes']) ?></div><?php endif; ?>
    <div><strong>Tracking number:</strong>
      <?php if (!empty($order['tracking_url'])): ?>
        <?= e($order['tracking_url']) ?>
      <?php else: ?>
        Not yet available
      <?php endif; ?>
    </div>
  </div>

  <?php $totals = order_totals($order, $items); ?>
  <table>
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
  <?= fx_total_note($totals['total']) ?>

  <p class="muted" style="margin-top:20px">Questions? <?= e($contactEmail) ?> · <?= e($contactPhone) ?></p>
  <p class="noprint"><button class="btn" onclick="window.print()">Print</button></p>
</body></html>

<?php
use App\Icons;
/** @var array $lines @var int $total */
?>
<div class="container">
  <div class="page-head"><div><h1><?= Icons::get('check', 22) ?> Confirm order</h1></div></div>
  <?php if (!$lines): ?>
    <div class="card empty">Your cart is empty.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line total</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td><?= e($l['title']) ?></td><td><?= (int)$l['qty'] ?></td><td><?= money($l['unit']) ?></td><td><?= money($l['line_total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="3" style="text-align:right">Total (<?= e(\App\Currency::company()) ?>)</th><th><?= money($total) ?></th></tr></tfoot>
    </table>
  </div>
  <?= fx_total_note($total) ?>
  <form method="post" action="<?= e(url('checkout')) ?>" class="card" style="margin-top:18px;max-width:640px">
    <?= csrf_field() ?>
    <label class="field"><span>Order notes</span><textarea name="notes" rows="3"></textarea></label>
    <button class="btn btn-primary" type="submit"><?= Icons::get('send', 18) ?> Place order</button>
    <a class="btn btn-ghost" href="<?= e(url('cart')) ?>">Back to cart</a>
  </form>
  <?php endif; ?>
</div>

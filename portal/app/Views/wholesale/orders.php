<?php use App\Icons; /** @var array $orders */ ?>
<div class="container">
  <div class="page-head"><div><h1><?= Icons::get('orders', 22) ?> My orders</h1></div></div>
  <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>Order</th><th>Status</th><th>Payment</th><th>Total</th><th>Placed</th><th></th></tr></thead>
      <tbody>
        <?php if (!$orders): ?><tr><td colspan="6" class="empty">No orders yet.</td></tr><?php endif; ?>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td>#<?= (int)$o['id'] ?></td>
            <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td><span class="badge <?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
            <td><?= money((int)$o['total_cents']) ?></td>
            <td><?= fmt_datetime($o['created_at']) ?></td>
            <td><a class="btn btn-sm btn-ghost" href="<?= e(url('order', ['id'=>$o['id']])) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

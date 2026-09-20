<?php
use App\Icons;
/** @var array $lines @var int $total */
?>
<div class="container">
  <div class="page-head"><div><h1><?= Icons::get('cart', 22) ?> Your cart</h1>
    <p class="muted">Quantities cannot go below each product’s Min unless your account has Ignore min. Use the trash button to remove a line.</p></div>
    <a class="btn btn-ghost" href="<?= e(url('catalog')) ?>">Continue shopping</a></div>

  <?php if (!$lines): ?>
    <div class="card empty">Your cart is empty. <a href="<?= e(url('catalog')) ?>">Browse the catalog →</a></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>Item</th><th>Type</th><th>Unit</th><th>Qty</th><th>Line total</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($lines as $l): ?>
          <tr data-scope="<?= e($l['scope']) ?>" data-product="<?= (int)$l['product_id'] ?>" data-bundle="<?= (int)($l['bundle_id'] ?? 0) ?>">
            <td>
              <div class="cart-item">
                <?php if (!empty($l['image'])): ?>
                  <img class="cart-thumb" src="<?= e($l['image']) ?>" alt="">
                <?php endif; ?>
                <span><?= e($l['title']) ?></span>
              </div>
            </td>
            <td><span class="badge <?= $l['scope']==='bundle'?'hl':'' ?>"><?= e($l['scope']) ?></span></td>
            <td><?= money($l['unit']) ?></td>
            <td><input type="number" class="qty" min="<?= (int)($l['min'] ?? 1) ?>" value="<?= (int)$l['qty'] ?>" step="1" style="width:80px" aria-label="Quantity"></td>
            <td class="lt"><?= money($l['line_total']) ?></td>
            <td><button class="btn btn-sm btn-danger rm"><?= Icons::get('trash',15) ?></button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="4" style="text-align:right">Total (<?= e(\App\Currency::company()) ?>)</th><th id="cartTotal"><?= money($total) ?></th><th></th></tr></tfoot>
    </table>
  </div>
  <?= fx_total_note($total) ?>

  <form method="post" action="<?= e(url('checkout')) ?>" class="card" style="margin-top:18px;max-width:640px">
    <?= csrf_field() ?>
    <label class="field"><span>Order notes (optional)</span><textarea name="notes" rows="3" placeholder="Delivery instructions, PO reference…"></textarea></label>
    <button class="btn btn-primary" type="submit"><?= Icons::get('check', 18) ?> Submit order</button>
  </form>
  <?php endif; ?>
</div>

<?php page_script(<<<'JS'
(function(){
  function money(c){ return hd.money(c); }
  document.querySelectorAll('tr[data-scope]').forEach(function(tr){
    var qty = tr.querySelector('.qty');
    var scope = tr.dataset.scope;
    qty.addEventListener('change', async function(){
      var qtyVal = parseInt(qty.value||'0', 10);
      if (qtyVal > 0) {
        var min = parseInt(qty.getAttribute('min') || '1', 10) || 1;
        if (qtyVal < min) {
          qty.value = String(min);
          hd.toast('Minimum quantity is '+min, 'error');
          qtyVal = min;
        }
      }
      var payload = { qty: qtyVal };
      if (scope === 'bundle') { payload.scope='bundleitem'; payload.bundle_id=tr.dataset.bundle; payload.product_id=tr.dataset.product; }
      else { payload.scope='product'; payload.id=tr.dataset.product; }
      var r = await hd.post('cart.update', payload);
      if (r.clamped) { qty.value = r.qty; hd.toast('Minimum quantity is '+r.min, 'error'); }
      location.reload();
    });
    tr.querySelector('.rm').addEventListener('click', async function(){
      var payload = scope==='bundle' ? {scope:'bundle', id:tr.dataset.bundle} : {scope:'product', id:tr.dataset.product};
      await hd.post('cart.remove', payload);
      location.reload();
    });
  });
})();
JS); ?>

<?php
use App\Icons;
/** @var array $products @var array $bundles */
$wholesale = true;
?>
<div class="container">
  <div class="page-head"><div><h1><?= Icons::get('store', 22) ?> Wholesale catalog</h1>
    <p class="muted">Wholesale pricing &amp; live stock. Open a product or bundle for the full view, or add it to your cart.</p></div>
  </div>

  <div class="toolbar"><input type="search" id="q" placeholder="Filter by title or description…" style="max-width:420px"></div>

  <?php require APP_ROOT . '/app/Views/_catalog_cards.php'; ?>
</div>

<?php page_script(<<<'JS'
(function(){
  var q = document.getElementById('q');
  if (q) q.addEventListener('input', function(){
    var t = q.value.trim().toLowerCase();
    document.querySelectorAll('.product').forEach(function(c){
      c.classList.toggle('hidden', !(!t || (c.getAttribute('data-search')||'').indexOf(t)!==-1));
    });
  });
  document.querySelectorAll('.add-product').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var id = btn.dataset.id;
      var qty = parseInt(document.getElementById('qty-'+id).value || '1', 10);
      var r = await hd.post('cart.add', {type:'product', id:id, qty:qty});
      if (r.ok) hd.toast('Added to cart ('+r.count+' lines)');
    });
  });
  document.querySelectorAll('.add-bundle').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var r = await hd.post('cart.add', {type:'bundle', id:btn.dataset.id});
      if (r.ok) hd.toast('Bundle added ('+r.count+' lines)');
    });
  });
})();
JS); ?>

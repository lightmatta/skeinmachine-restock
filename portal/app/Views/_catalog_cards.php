<?php
use App\Icons;
use App\Settings;
/** @var array $products @var array $bundles @var bool $wholesale @var bool $showStock @var bool $ignoreMin */
$wholesale = !empty($wholesale);
$showStock = !empty($showStock);
$ignoreMin = !empty($ignoreMin);
?>
<?php if (!empty($bundles)): ?>
<h2 style="margin-top:22px"><?= Icons::get('bundle', 18) ?> Bundles</h2>
<div class="cards" id="bundleCards" style="margin:12px 0 26px">
  <?php foreach ($bundles as $b): ?>
    <?php $href = url('bundle', ['id' => (int)$b['id']]); ?>
    <?php
      $bundleColours = [];
      foreach ($b['items'] as $it) {
          foreach (\App\Catalog::parseColours((string)($it['colours'] ?? '')) as $c) {
              $bundleColours[$c] = $c;
          }
      }
    ?>
    <div class="card product" data-search="<?= e(strtolower($b['title'] . ' ' . ($b['description'] ?? ''))) ?>" data-colours="<?= e(implode(',', $bundleColours)) ?>">
      <a class="thumb-link" href="<?= e($href) ?>"><?= catalog_bundle_preview($b) ?></a>
      <a class="title" href="<?= e($href) ?>"><?= e($b['title']) ?></a>
      <?= catalog_description($b['description'] ?? '') ?>
      <span class="badge hl">Bundle</span>
      <?php if ($wholesale): ?>
        <ul class="help bundle-mini" style="margin:4px 0 0;padding-left:16px">
          <?php foreach ($b['items'] as $it): ?>
            <li><?= e($it['title']) ?> — min <?= (int)\App\Catalog::itemFloorQty($it, $ignoreMin) ?> × <?= money(Settings::shopperWholesaleCents((int)$it['price_cents'])) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="price">From <?= money((int)$b['min_total_cents']) ?></div>
        <button type="button" class="btn btn-primary btn-sm add-bundle" data-id="<?= (int)$b['id'] ?>"><?= Icons::get('cart', 15) ?> Add to cart</button>
      <?php else: ?>
        <p class="help">Sign in as a wholesale client to view pricing &amp; availability.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2><?= Icons::get('box', 18) ?> Products</h2>
<div class="cards" id="productCards" style="margin-top:12px">
  <?php if (empty($products)): ?>
    <p class="empty">No products are listed yet.</p>
  <?php endif; ?>
  <?php foreach ($products as $p): ?>
    <?php $href = url('product', ['id' => (int)$p['id']]); ?>
    <div class="card product" data-search="<?= e(strtolower($p['title'] . ' ' . ($p['description'] ?? ''))) ?>" data-colours="<?= e(implode(',', \App\Catalog::parseColours((string)($p['colours'] ?? '')))) ?>">
      <a class="thumb-link" href="<?= e($href) ?>"><?= catalog_thumb($p) ?></a>
      <a class="title" href="<?= e($href) ?>"><?= e($p['title']) ?></a>
      <?php if (!empty($p['category'])): ?><span class="badge"><?= e($p['category']) ?></span><?php endif; ?>
      <?= catalog_description($p['description'] ?? '') ?>
      <?php if ($wholesale): ?>
        <div class="price"><?= money(Settings::shopperWholesaleCents((int)$p['price_cents'])) ?>
          <?= catalog_stock_badge($p, $showStock) ?></div>
        <?php
          $min = $ignoreMin ? 1 : Settings::minQtyForProduct($p);
        ?>
        <div class="toolbar" style="margin:0">
          <input type="number" class="qty-input" min="<?= (int)$min ?>" value="<?= (int)$min ?>" step="1" style="width:70px" id="qty-<?= (int)$p['id'] ?>" aria-label="Quantity">
          <button type="button" class="btn btn-primary btn-sm add-product" data-id="<?= (int)$p['id'] ?>"><?= Icons::get('cart', 15) ?> Add to cart</button>
        </div>
        <?php if (!$ignoreMin && $min > 1): ?><p class="help" style="margin:4px 0 0">Min <?= (int)$min ?></p><?php endif; ?>
      <?php else: ?>
        <p class="help">Sign in as a wholesale client to view pricing &amp; availability.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php
page_script(<<<'JS'
(function(){
  document.querySelectorAll('.catalog-more').forEach(function(btn){
    btn.addEventListener('click', function(){
      var wrap = btn.closest('.catalog-desc');
      if (!wrap) return;
      var body = wrap.querySelector('.catalog-desc-body');
      var open = btn.getAttribute('aria-expanded') === 'true';
      if (open) {
        body.textContent = wrap.getAttribute('data-short') || '';
        btn.textContent = '...more';
        btn.setAttribute('aria-expanded', 'false');
      } else {
        body.textContent = wrap.getAttribute('data-full') || '';
        btn.textContent = 'less';
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });
  document.querySelectorAll('.qty-input').forEach(function(inp){
    inp.addEventListener('change', function(){ hd.clampQty(inp, true); });
  });
  document.querySelectorAll('.add-product').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var id = btn.dataset.id;
      var input = document.getElementById('qty-'+id);
      var qty = hd.clampQty(input, true);
      var r = await hd.post('cart.add', {type:'product', id:id, qty:qty});
      if (r.ok) { hd.toast('Added to cart ('+r.count+' lines)'); hd.setCartCount(r.count); }
      else hd.toast(r.message || 'Could not add to cart', 'error');
    });
  });
  document.querySelectorAll('.add-bundle').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var r = await hd.post('cart.add', {type:'bundle', id:btn.dataset.id});
      if (r.ok) { hd.toast('Added to cart ('+r.count+' lines)'); hd.setCartCount(r.count); }
      else hd.toast(r.message || 'Could not add to cart', 'error');
    });
  });
})();
JS);
?>

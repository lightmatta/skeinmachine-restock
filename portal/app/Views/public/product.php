<?php
use App\Icons;
use App\Auth;
use App\Settings;
/** @var array $product @var list<array{src:string,alt:string}> $images @var bool $wholesale @var bool $ignoreMin */
$back = !empty($wholesale) ? url('catalog') : url('home');
$backLabel = !empty($wholesale) ? 'Wholesale catalog' : 'Home';
$feature = $images[0] ?? null;
$ignoreMin = !empty($ignoreMin);
$min = $ignoreMin ? 1 : Settings::minQtyForProduct($product);
?>
<div class="container product-page">
  <p class="crumb"><a href="<?= e($back) ?>"><?= Icons::get('chevron', 14) ?> <?= e($backLabel) ?></a></p>

  <article class="product-hero">
    <div class="gallery" data-gallery>
      <?php if ($feature): ?>
        <div class="gallery-main">
          <img src="<?= e($feature['src']) ?>" alt="<?= e($feature['alt'] ?: $product['title']) ?>">
        </div>
        <?php if (count($images) > 1): ?>
          <div class="gallery-thumbs" role="list">
            <?php foreach ($images as $i => $im): ?>
              <button type="button" class="gallery-thumb<?= $i === 0 ? ' is-active' : '' ?>"
                      data-src="<?= e($im['src']) ?>" data-alt="<?= e($im['alt'] ?: $product['title']) ?>"
                      aria-label="View image <?= $i + 1 ?>">
                <img src="<?= e($im['src']) ?>" alt="">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="gallery-main is-empty"><?= Icons::get('yarn', 64) ?></div>
      <?php endif; ?>
    </div>

    <div class="product-info">
      <?php if (!empty($product['category'])): ?>
        <span class="badge hl"><?= e($product['category']) ?></span>
      <?php endif; ?>
      <h1><?= e($product['title']) ?></h1>
      <?php if (!empty($product['sku'])): ?>
        <p class="muted">SKU <?= e($product['sku']) ?></p>
      <?php endif; ?>
      <?php if (!empty($product['description'])): ?>
        <div class="product-copy"><?= nl2br(e($product['description'])) ?></div>
      <?php endif; ?>

      <?php if (!empty($wholesale)): ?>
        <div class="product-buy">
          <div class="price"><?= money(Settings::shopperWholesaleCents((int)$product['price_cents'])) ?>
            <?= catalog_stock_badge($product, !empty($showStock)) ?>
          </div>
          <div class="toolbar" style="margin:0">
            <input type="number" class="qty-input" min="<?= (int)$min ?>" value="<?= (int)$min ?>" step="1" style="width:88px" id="qty-<?= (int)$product['id'] ?>" aria-label="Quantity">
            <button type="button" class="btn btn-primary add-product" data-id="<?= (int)$product['id'] ?>"><?= Icons::get('cart', 16) ?> Add to cart</button>
          </div>
          <?php if (!$ignoreMin && $min > 1): ?><p class="help">Minimum order quantity <?= (int)$min ?></p><?php endif; ?>
        </div>
      <?php elseif (!Auth::check()): ?>
        <p class="help">Sign in as a wholesale client to view pricing and order.</p>
      <?php endif; ?>
    </div>
  </article>
</div>
<?php if (count($images) > 1 || !empty($wholesale)):
page_script(<<<'JS'
(function(){
  var gallery = document.querySelector('[data-gallery]');
  if (gallery) {
    var main = gallery.querySelector('.gallery-main img');
    gallery.querySelectorAll('.gallery-thumb').forEach(function(btn){
      btn.addEventListener('click', function(){
        if (!main) return;
        main.src = btn.dataset.src;
        main.alt = btn.dataset.alt || '';
        gallery.querySelectorAll('.gallery-thumb').forEach(function(b){ b.classList.toggle('is-active', b === btn); });
      });
    });
  }
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
})();
JS);
endif; ?>

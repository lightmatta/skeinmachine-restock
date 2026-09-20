<?php
use App\Icons;
use App\Auth;
use App\Catalog;
use App\Settings;
/** @var array $bundle @var bool $wholesale @var bool $ignoreMin */
$back = !empty($wholesale) ? url('catalog') : url('home');
$backLabel = !empty($wholesale) ? 'Wholesale catalog' : 'Home';
?>
<div class="container product-page">
  <p class="crumb"><a href="<?= e($back) ?>"><?= Icons::get('chevron', 14) ?> <?= e($backLabel) ?></a></p>

  <header class="bundle-head">
    <span class="badge hl"><?= Icons::get('bundle', 14) ?> Bundle</span>
    <h1><?= e($bundle['title']) ?></h1>
    <?php if (!empty($bundle['description'])): ?>
      <p class="muted"><?= e($bundle['description']) ?></p>
    <?php endif; ?>
    <?php if (!empty($wholesale)): ?>
      <div class="product-buy">
        <div class="price">From <?= money((int)$bundle['min_total_cents']) ?></div>
        <button type="button" class="btn btn-primary add-bundle" data-id="<?= (int)$bundle['id'] ?>"><?= Icons::get('cart', 16) ?> Add to cart</button>
      </div>
    <?php elseif (!Auth::check()): ?>
      <p class="help">Sign in as a wholesale client to view pricing and order.</p>
    <?php endif; ?>
  </header>

  <h2>In this bundle</h2>
  <p class="muted" style="margin-top:-8px">Each product is shown with its feature image. Open a product to see the full gallery.</p>
  <div class="cards bundle-members" style="margin-top:16px">
    <?php foreach ($bundle['items'] as $it): ?>
      <?php $href = url('product', ['id' => (int)$it['id']]); ?>
      <div class="card product">
        <a class="thumb-link" href="<?= e($href) ?>"><?= catalog_thumb($it, 'yarn', 400) ?></a>
        <a class="title" href="<?= e($href) ?>"><?= e($it['title']) ?></a>
        <?php if (!empty($it['category'])): ?><span class="badge"><?= e($it['category']) ?></span><?php endif; ?>
        <?php if (!empty($wholesale)): ?>
          <div class="price"><?= money(Settings::shopperWholesaleCents((int)$it['price_cents'])) ?>
            <span class="help">min <?= (int)Catalog::itemFloorQty($it, !empty($ignoreMin)) ?></span></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php if (!empty($wholesale)):
page_script(<<<'JS'
document.querySelectorAll('.add-bundle').forEach(function(btn){
  btn.addEventListener('click', async function(){
    var r = await hd.post('cart.add', {type:'bundle', id:btn.dataset.id});
    if (r.ok) { hd.toast('Bundle added ('+r.count+' lines)'); hd.setCartCount(r.count); }
    else hd.toast(r.message || 'Could not add bundle', 'error');
  });
});
JS);
endif; ?>

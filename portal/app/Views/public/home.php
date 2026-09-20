<?php
use App\Icons;
use App\Settings;
/** @var array $products @var array $bundles @var string $intro @var bool $wholesale @var bool $showStock */
$wholesale = !empty($wholesale);
$showStock = !empty($showStock);
?>
<div class="container">
  <div class="page-head">
    <div>
      <h1><?= e(Settings::get('front_title', 'Welcome')) ?></h1>
      <p class="muted"><?= e(Settings::get('company_name', '')) ?> · <?= e(Settings::get('opening_hours', '')) ?></p>
    </div>
  </div>

  <?php if ($intro): ?><div class="card" style="margin-bottom:20px"><?= $intro /* admin-authored HTML */ ?></div><?php endif; ?>

  <div class="toolbar catalog-toolbar">
    <span class="badge"><?= Icons::get('search', 15) ?> Search catalog</span>
    <input type="search" id="q" placeholder="Search products & bundles by title or description…" style="max-width:420px">
    <div class="colour-filter" id="colourFilter" role="group" aria-label="Filter by colour">
      <?php foreach (\App\Catalog::colourSwatches() as $slug => $sw): ?>
        <button type="button" class="colour-chip" data-colour="<?= e($slug) ?>" title="<?= e($sw['label']) ?>" aria-pressed="false" style="--chip:<?= e($sw['hex']) ?>"><?= e($sw['label'][0]) ?></button>
      <?php endforeach; ?>
      <button type="button" class="colour-chip colour-variegated" data-variegated="1" title="Variegated" aria-pressed="false">Variegated</button>
    </div>
    <span class="muted" id="searchInfo"></span>
  </div>

  <?php require __DIR__ . '/../_catalog_cards.php'; ?>
</div>

<?php
page_script(<<<'JS'
(function(){
  var q = document.getElementById('q');
  var info = document.getElementById('searchInfo');
  var colour = '';
  var variegated = false;
  function apply(){
    var term = (q && q.value.trim().toLowerCase()) || '';
    var shown = 0;
    document.querySelectorAll('#productCards .product, #bundleCards .product').forEach(function(card){
      var hay = card.getAttribute('data-search') || '';
      var cols = (card.getAttribute('data-colours') || '').split(',').filter(Boolean);
      var match = !term || hay.indexOf(term) !== -1;
      if (colour && cols.indexOf(colour) === -1) match = false;
      if (variegated && cols.indexOf('variegated') === -1) match = false;
      card.classList.toggle('hidden', !match);
      if (match) shown++;
    });
    var bits = [];
    if (term) bits.push(shown + ' match' + (shown===1?'':'es'));
    else if (colour || variegated) bits.push(shown + ' colour match' + (shown===1?'':'es'));
    if (info) info.textContent = bits.join(' · ');
  }
  if (q) q.addEventListener('input', apply);
  document.querySelectorAll('#colourFilter .colour-chip').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (btn.getAttribute('data-variegated')) {
        variegated = !variegated;
        btn.classList.toggle('is-on', variegated);
        btn.setAttribute('aria-pressed', variegated ? 'true' : 'false');
      } else {
        var next = btn.getAttribute('data-colour') || '';
        colour = colour === next ? '' : next;
        document.querySelectorAll('#colourFilter .colour-chip[data-colour]').forEach(function(c){
          var on = (c.getAttribute('data-colour') || '') === colour;
          c.classList.toggle('is-on', on);
          c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      }
      apply();
    });
  });
})();
JS);
?>

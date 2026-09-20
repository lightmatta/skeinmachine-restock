<?php use App\Icons; use App\UserPrefs; /** @var string $active @var bool $readonly @var array $hiddenCols @var array $vendors */
$readonly = !empty($readonly);
$hiddenJson = json_encode(array_values($hiddenCols ?? ['source_url', 'vendor_product_id']), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_VENDOR_PRODUCTS);
$vendorOpts = [];
$vendorOpts[] = ['value' => 0, 'label' => '—'];
foreach ($vendors ?? [] as $v) {
    $vendorOpts[] = ['value' => (int)$v['id'], 'label' => (string)$v['name']];
}
$vendorOptsJson = json_encode($vendorOpts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('store', 22) ?> Vendor products</h1>
      <p class="muted">Stock scraped from each vendor. Rows are matched to catalog products by SKU, product id, then title so Restock Orders can compare levels. Group and filter by vendor; search product names. Select more than one row to set status instantly.</p></div></div>
    <div class="toolbar">
      <label>Vendor
        <select id="fVendor"><option value="">All vendors</option>
          <?php foreach ($vendors ?? [] as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= e((string)$v['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select id="fStatus">
          <option value="">Any</option>
          <option value="active">active</option>
          <option value="inactive">inactive</option>
        </select>
      </label>
    </div>
    <div id="vendorProductsGrid"></div>
  </div>
</div>
<?php
$roJs = $readonly ? 'true' : 'false';
page_script(<<<JS
(function(){
  var grid = new hd.DataGrid('vendorProductsGrid', {
    entity: 'vendor_products',
    readonly: $roJs,
    selectable: true,
    groupKey: 'vendor_name',
    bulk: [
      {key:'status', label:'Set status', options:[{value:'active',label:'active'},{value:'inactive',label:'inactive'}]}
    ],
    columns: [
      {key:'id', label:'#', local:true},
      {key:'vendor_name', label:'Vendor', local:true},
      {key:'vendor_id', label:'Vendor ID', editable:true, options: $vendorOptsJson, local:true},
      {key:'vendor_product_id', label:'Product ID', editable:true, local:true},
      {key:'sku', label:'SKU', editable:true, local:true},
      {key:'title', label:'Title', editable:true, local:true},
      {key:'stock', label:'Vendor stock', tip:'Available stock on the vendor site', editable:true, local:true},
      {key:'catalog_stock', label:'Our stock', tip:'Matched catalog on-hand quantity'},
      {key:'matched_title', label:'Matched product', tip:'Catalog product matched by SKU, product id, or title'},
      {key:'price_cents', label:'Price', type:'money', editable:true, local:true},
      {key:'status', label:'Status', type:'badge', editable:true, options:['active','inactive'], local:true},
      {key:'source_url', label:'Source URL', editable:true, local:true}
    ],
    hidden: $hiddenJson,
    persistHidden: $persistKey
  });
  function apply(){
    grid.filters = {};
    var v = document.getElementById('fVendor').value; if (v) grid.filters.vendor_id = v;
    var s = document.getElementById('fStatus').value; if (s) grid.filters.status = s;
    grid.reload();
  }
  ['fVendor','fStatus'].forEach(function(id){
    document.getElementById(id).addEventListener('change', apply);
  });
})();
JS);
?>

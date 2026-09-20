<?php use App\Icons; use App\UserPrefs; /** @var string $active @var bool $readonly @var array $hiddenCols @var array $vendors */
$readonly = !empty($readonly);
$hiddenJson = json_encode(array_values($hiddenCols ?? ['description', 'shopify_product_id']), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_PRODUCTS);
$vendorOpts = json_encode($vendors ?? [['value' => 0, 'label' => '—']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('box', 22) ?> Products</h1>
      <p class="muted"><?= $readonly
        ? 'Catalog is read-only for staff accounts.'
        : 'Shopify sync fills the catalog. Set Vendor, Min (restock trigger) and Goal (target on-hand). Select more than one row to bulk-set status, Min, or Goal. Double-click a cell to edit.' ?></p></div>
      <?php if (!$readonly): ?>
      <button type="button" class="btn btn-danger" id="deleteAllBtn"><?= Icons::get('trash', 16) ?> Delete all products</button>
      <?php endif; ?>
    </div>
    <div id="productsGrid"></div>
  </div>
</div>

<?php if (!$readonly): ?>
<div class="modal" id="deleteAllModal" hidden>
  <div class="modal-card" role="dialog" aria-labelledby="deleteAllTitle" aria-modal="true">
    <h2 id="deleteAllTitle"><?= Icons::get('trash', 20) ?> Delete all products?</h2>
    <p>This permanently removes <strong>every product</strong> from the catalog. Vendor matches stay, but this <strong>cannot be undone</strong>.</p>
    <label class="field" style="display:flex;gap:8px;align-items:center;margin:16px 0">
      <input type="checkbox" id="deleteAllAck" style="width:auto">
      <span style="margin:0">I understand this cannot be undone</span>
    </label>
    <div class="toolbar" style="margin:0">
      <button type="button" class="btn btn-danger" id="deleteAllConfirm" disabled>Delete all products</button>
      <button type="button" class="btn btn-ghost" id="deleteAllCancel">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$roJs = $readonly ? 'true' : 'false';
page_script(<<<JS
new hd.DataGrid('productsGrid', {
  entity: 'products',
  readonly: $roJs,
  selectable: true,
  bulk: [
    {key:'status', label:'Set status', options:[{value:'active',label:'active'},{value:'inactive',label:'inactive'}]}
  ],
  bulkNumber: [
    {key:'min_qty', label:'Set min', min:0, tip:'Set minimum quantity for restock'},
    {key:'goal_qty', label:'Set goal', min:0, tip:'Goal Stock Level'}
  ],
  columns: [
    {key:'id', label:'#'},
    {key:'sku', label:'SKU', editable:true},
    {key:'title', label:'Title', editable:true},
    {key:'category', label:'Category', editable:true},
    {key:'vendor_id', label:'Vendor', editable:true, options: $vendorOpts},
    {key:'price_cents', label:'Retail', type:'money', editable:true},
    {key:'stock', label:'Stock', tip:'Current on-hand quantity (Shopify inventory)', editable:true},
    {key:'min_qty', label:'Min', tip:'Set minimum quantity for restock', editable:true},
    {key:'goal_qty', label:'Goal', tip:'Goal Stock Level', editable:true},
    {key:'status', label:'Status', tip:'Active products are watched for restock. Inactive are ignored.', type:'badge', editable:true, options:['active','inactive']},
    {key:'shopify_product_id', label:'Shopify ID'},
    {key:'archived', label:'Archived', type:'bool', editable:true, options:['0','1']},
    {key:'description', label:'Description', editable:true}
  ],
  hidden: $hiddenJson,
  persistHidden: $persistKey
});
JS);

if (!$readonly) {
page_script(<<<'JS'
(function(){
  var modal = document.getElementById('deleteAllModal');
  var ack = document.getElementById('deleteAllAck');
  var confirmBtn = document.getElementById('deleteAllConfirm');
  function open(){ modal.hidden = false; ack.checked = false; confirmBtn.disabled = true; }
  function close(){ modal.hidden = true; }
  document.getElementById('deleteAllBtn').addEventListener('click', open);
  document.getElementById('deleteAllCancel').addEventListener('click', close);
  modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
  document.addEventListener('keydown', function(e){ if (!modal.hidden && e.key === 'Escape') close(); });
  ack.addEventListener('change', function(){ confirmBtn.disabled = !ack.checked; });
  confirmBtn.addEventListener('click', async function(){
    if (!ack.checked) return;
    confirmBtn.disabled = true;
    var r = await hd.post('admin/api', {entity:'products', op:'delete_all', confirm:true});
    if (r.error) { hd.toast(r.message || 'Delete failed', 'error'); confirmBtn.disabled = false; return; }
    hd.toast('Deleted ' + (r.deleted || 0) + ' products');
    close();
    location.reload();
  });
})();
JS);
}
?>

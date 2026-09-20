<?php use App\Icons; use App\UserPrefs; /** @var string $active @var bool $readonly @var int $wholesalePercent @var array $hiddenCols */
$readonly = !empty($readonly);
$wholesalePercent = (int)($wholesalePercent ?? 65);
$hiddenJson = json_encode(array_values($hiddenCols ?? ['description', 'shopify_product_id']), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_PRODUCTS);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('box', 22) ?> Products</h1>
      <p class="muted"><?= $readonly
        ? 'Catalog is read-only for staff accounts.'
        : 'Add, edit inline (double-click), archive, delete. Select more than one row to bulk-set wholesale status or SPT. Wholesale is ' . (int)$wholesalePercent . '% of retail. RS is Retail Stock. WS is Warehouse Stock. Min is the Minimum Order Quantity. Retail Status comes from Shopify or CSV and is not editable. Wholesale Status controls catalog visibility.' ?></p></div>
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
    <p>This permanently removes <strong>every product</strong> from the catalog. Bundle contents will be cleared. Historical orders keep their line titles, but this <strong>cannot be undone</strong>.</p>
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
  wholesalePercent: $wholesalePercent,
  bulk: [
    {key:'is_public', label:'Set wholesale status', options:[{value:'1',label:'visible'},{value:'0',label:'hidden'}]}
  ],
  columns: [
    {key:'id', label:'#'},
    {key:'sku', label:'SKU', editable:true},
    {key:'title', label:'Title', editable:true},
    {key:'category', label:'Category', editable:true},
    {key:'price_cents', label:'Retail', type:'money', editable:true},
    {key:'wholesale_cents', label:'Wholesale', type:'money'},
    {key:'stock', label:'RS', tip:'Retail Stock', editable:true},
    {key:'warehouse_stock', label:'WS', tip:'Warehouse Stock', editable:true},
    {key:'min_qty', label:'Min', tip:'Minimum Order Quantity', editable:true},
    {key:'spt', label:'SPT', tip:'Skeins Per Tray', editable:true},
    {key:'colours', label:'Colours', tip:'Comma-separated colour names. Not overwritten by sync unless Detect colours on import is on.', editable:true},
    {key:'variegated', label:'Variegated', tip:'More than two distinct colours, or set by hand.', type:'bool', editable:true, options:['0','1']},
    {key:'status', label:'Retail Status', tip:'Live / synced status from Shopify or CSV. Overwritten by sync and not editable.', type:'badge'},
    {key:'is_public', label:'Wholesale Status', tip:'Set by Admin. Controls visibility in the wholesale catalog.', type:'bool', editable:true, options:[{value:'1',label:'visible'},{value:'0',label:'hidden'}], yes:'visible', no:'hidden'},
    {key:'shopify_product_id', label:'Shopify ID'},
    {key:'archived', label:'Archived', type:'bool', editable:true, options:['0','1']},
    {key:'description', label:'Description', editable:true}
  ],
  hidden: $hiddenJson,
  persistHidden: $persistKey,
  bulkNumber: [{key:'spt', label:'Set SPT for selected', min:1}]
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

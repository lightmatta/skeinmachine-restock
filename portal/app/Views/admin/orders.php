<?php
use App\Icons;
use App\UserPrefs;
/** @var array $clients @var string $active @var array $hiddenCols */
$hiddenJson = json_encode(array_values($hiddenCols ?? ['notes']), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_ORDERS);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('orders', 22) ?> Orders</h1>
      <p class="muted">Double-click a cell to edit. Expand an order to add, edit, or delete line items. Create Bundle copies the line items into a hidden catalog bundle. Sort by clicking a header.</p></div></div>

    <div class="toolbar">
      <label>Client
        <select id="fClient"><option value="">All</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['email']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select id="fStatus"><option value="">Any</option>
          <?php foreach (['pending','provisioning','shipped','completed','cancelled'] as $s): ?><option><?= $s ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>From <input type="date" id="fFrom"></label>
      <label>To <input type="date" id="fTo"></label>
      <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="fArch" style="width:auto"> Show archived</label>
    </div>
    <div id="ordersGrid"></div>
  </div>
</div>

<?php page_script(<<<JS
(function(){
  function itemsHtml(orderId, payload){
    var items = payload.items || [];
    var products = payload.products || [];
    var html = '<div class="order-items" data-order="'+orderId+'">';
    html += '<table class="grid"><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line</th><th></th></tr></thead><tbody>';
    if (!items.length) {
      html += '<tr><td colspan="5" class="muted">No line items.</td></tr>';
    }
    items.forEach(function(it){
      html += '<tr data-item="'+it.id+'">';
      html += '<td>'+hd.escape(it.title)+'</td>';
      html += '<td><input type="number" min="1" class="oi-qty" value="'+hd.escape(String(it.qty))+'" style="width:72px"></td>';
      html += '<td><input type="number" min="0" step="0.01" class="oi-unit" value="'+(Number(it.unit_price_cents||0)/100).toFixed(2)+'" style="width:92px"></td>';
      html += '<td>'+hd.money(it.line_total_cents)+'</td>';
      html += '<td><button type="button" class="btn btn-sm btn-ghost oi-save">Save</button> <button type="button" class="btn btn-sm btn-danger oi-del">Delete</button></td>';
      html += '</tr>';
    });
    html += '</tbody></table>';
    html += '<div class="toolbar oi-add" style="margin:10px 0 0">';
    html += '<select class="oi-product" aria-label="Add product"><option value="">Add a product…</option>';
    products.forEach(function(p){
      html += '<option value="'+p.id+'" data-unit="'+p.wholesale_cents+'">'+hd.escape(p.title)+'</option>';
    });
    html += '</select>';
    html += '<input type="number" min="1" class="oi-add-qty" value="1" style="width:72px" aria-label="Quantity">';
    html += '<button type="button" class="btn btn-sm btn-primary oi-add-btn">Add item</button>';
    html += '<span class="help" style="margin:0">Order total '+hd.money(payload.total_cents)+'</span>';
    html += '</div></div>';
    return html;
  }

  var grid = new hd.DataGrid('ordersGrid', {
    entity: 'orders',
    columns: [
      {key:'id', label:'#', expand:true},
      {key:'client', label:'Client', expand:true},
      {key:'status', label:'Status', type:'badge', editable:true, options:['pending','provisioning','shipped','completed','cancelled']},
      {key:'payment_status', label:'Payment', type:'badge', editable:true, options:['pending','paid','refunded']},
      {key:'total_cents', label:'Total', type:'money', editable:true},
      {key:'discount_percent', label:'Discount', tip:'Override Discount Rate'},
      {key:'manual_discount_cents', label:'Manual Discount', tip:'Dollar amount subtracted from the order total', type:'money', editable:true},
      {key:'tracking_url', label:'Tracking', editable:true},
      {key:'notes', label:'Client notes'},
      {key:'admin_notes', label:'Admin notes', editable:true},
      {key:'created_at', label:'Placed'}
    ],
    rowActions: [{op:'create_bundle', title:'Create Bundle', icon:'bundle'}],
    hidden: $hiddenJson,
    persistHidden: $persistKey,
    expandOn: ['id','client'],
    onExpand: async function(row){
      var data = await hd.post('admin/api', {entity:'orders', op:'items', id:row.id});
      return itemsHtml(row.id, data);
    }
  });

  async function refreshExpand(orderId, payload){
    var row = (grid.rows || []).find(function(r){ return r.id == orderId; });
    if (!row) return;
    row.total_cents = payload.total_cents;
    row.__expandHtml = itemsHtml(orderId, payload);
    grid.paint();
  }

  var mount = document.getElementById('ordersGrid');
  mount.addEventListener('click', async function(e){
    var wrap = e.target.closest('.order-items');
    if (!wrap) return;
    var orderId = parseInt(wrap.dataset.order, 10);
    if (e.target.closest('.oi-save')) {
      var tr = e.target.closest('tr');
      var qty = parseInt(tr.querySelector('.oi-qty').value || '1', 10);
      var unit = Math.round(parseFloat(tr.querySelector('.oi-unit').value || '0') * 100);
      var r = await hd.post('admin/api', {entity:'orders', op:'update_item', item_id: parseInt(tr.dataset.item,10), qty:qty, unit_price_cents:unit});
      if (r.error) { hd.toast(r.message || 'Could not save line', 'error'); return; }
      hd.toast('Line saved');
      await refreshExpand(orderId, r);
    }
    if (e.target.closest('.oi-del')) {
      var trd = e.target.closest('tr');
      if (!confirm('Remove this line from the order?')) return;
      var rd = await hd.post('admin/api', {entity:'orders', op:'delete_item', item_id: parseInt(trd.dataset.item,10)});
      if (rd.error) { hd.toast(rd.message || 'Could not delete line', 'error'); return; }
      hd.toast('Line removed');
      await refreshExpand(orderId, rd);
    }
    if (e.target.closest('.oi-add-btn')) {
      var sel = wrap.querySelector('.oi-product');
      var pid = parseInt(sel.value || '0', 10);
      if (!pid) { hd.toast('Choose a product to add', 'error'); return; }
      var qty = parseInt(wrap.querySelector('.oi-add-qty').value || '1', 10);
      var ra = await hd.post('admin/api', {entity:'orders', op:'add_item', order_id: orderId, product_id: pid, qty: qty});
      if (ra.error) { hd.toast(ra.message || 'Could not add item', 'error'); return; }
      hd.toast('Item added');
      await refreshExpand(orderId, ra);
    }
  });

  function apply(){
    grid.filters = {};
    var c=document.getElementById('fClient').value; if(c) grid.filters.user_id=c;
    var s=document.getElementById('fStatus').value; if(s) grid.filters.status=s;
    var f=document.getElementById('fFrom').value; if(f) grid.filters.from=f;
    var t=document.getElementById('fTo').value; if(t) grid.filters.to=t;
    if(document.getElementById('fArch').checked) grid.filters.include_archived=1;
    grid.reload();
  }
  ['fClient','fStatus','fFrom','fTo','fArch'].forEach(function(id){
    document.getElementById(id).addEventListener('change', apply);
  });
})();
JS); ?>

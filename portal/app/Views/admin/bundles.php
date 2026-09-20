<?php
use App\Icons;
/** @var array $bundles @var array $byBundle @var array $products @var string $active @var bool $readonly */
$readonly = !empty($readonly);
$cols = $readonly ? 3 : 5;
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('bundle', 22) ?> Bundles</h1>
      <p class="muted"><?= $readonly
        ? 'Bundle contents are read-only for staff accounts.'
        : 'Group products into bundles, toggle public/hidden, and set each product\'s minimum order quantity. Hidden or deleted bundles stay on historical invoices. Drag a row to change the order — it saves as soon as you drop it.' ?></p></div>
      <?php if (!$readonly): ?>
      <button class="btn btn-primary" id="addBundle"><?= Icons::get('plus', 18) ?> New bundle</button>
      <?php endif; ?></div>

    <?php if (!$bundles): ?><div class="card empty">No bundles yet.</div><?php endif; ?>

    <div class="bundle-grid">
    <?php foreach ($bundles as $b): $items = $byBundle[(int)$b['id']] ?? []; $isPublic = (int)$b['is_public'] === 1; ?>
      <div class="card bundle-card" data-bid="<?= (int)$b['id'] ?>">
        <div class="page-head" style="margin-bottom:10px">
          <div>
            <h2 class="bundle-name-row">
              <?php if ($readonly): ?>
                <?= e($b['title']) ?>
              <?php else: ?>
                <input type="text" class="bundle-title" value="<?= e($b['title']) ?>" aria-label="Bundle name" maxlength="120">
              <?php endif; ?>
              <span class="badge <?= $isPublic ? 'active' : 'cancelled' ?> public-badge"><?= $isPublic ? 'public' : 'hidden' ?></span>
              <?php if ((int)$b['archived']): ?><span class="badge cancelled">archived</span><?php endif; ?>
            </h2>
            <div class="muted"><?= e($b['description'] ?? '') ?></div>
          </div>
          <div class="row-actions">
            <?php if (!$readonly): ?>
            <button type="button" class="btn btn-sm toggle-public" data-public="<?= $isPublic ? '1' : '0' ?>">
              <?= $isPublic ? 'Hide from catalog' : 'Make public' ?>
            </button>
            <button type="button" class="btn btn-sm btn-danger del-bundle"><?= Icons::get('trash', 15) ?> Delete</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!$readonly): ?>
        <div class="toolbar bundle-add" style="margin-bottom:10px">
          <div class="add-product-combo">
            <input type="search" class="add-product-filter" placeholder="Type a keyword to filter products…" autocomplete="off" aria-label="Filter products to add">
            <select class="add-product-sel" aria-label="Add to bundle">
              <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?> (<?= money((int)$p['price_cents']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <input type="number" min="1" value="1" class="add-min" style="width:90px" title="Minimum quantity">
          <button class="btn btn-sm add-item"><?= Icons::get('plus', 15) ?> Add product</button>
        </div>
        <?php endif; ?>
        <div class="table-wrap">
          <table class="grid bundle-items">
            <thead><tr>
              <?php if (!$readonly): ?><th class="drag-col" aria-label="Reorder"></th><?php endif; ?>
              <th>Product</th><th>Unit price</th><th>Min qty</th>
              <?php if (!$readonly): ?><th></th><?php endif; ?>
            </tr></thead>
            <tbody>
              <?php if (!$items): ?><tr class="empty-row"><td colspan="<?= (int)$cols ?>" class="empty">No products in this bundle.</td></tr><?php endif; ?>
              <?php foreach ($items as $it): ?>
                <tr data-item="<?= (int)$it['id'] ?>"<?= $readonly ? '' : ' draggable="true"' ?>>
                  <?php if (!$readonly): ?><td class="drag-cell"><span class="drag-grip" title="Drag to reorder"></span></td><?php endif; ?>
                  <td><?= e($it['title']) ?></td>
                  <td><?= money((int)$it['price_cents']) ?></td>
                  <td><?php if ($readonly): ?><?= (int)$it['min_qty'] ?><?php else: ?><input type="number" min="1" value="<?= (int)$it['min_qty'] ?>" class="min-qty" style="width:90px"><?php endif; ?></td>
                  <?php if (!$readonly): ?><td><button class="btn btn-sm btn-danger del-item"><?= Icons::get('trash', 15) ?></button></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="modal" id="deleteBundleModal" hidden>
  <div class="modal-card" role="dialog" aria-labelledby="deleteBundleTitle" aria-modal="true">
    <h2 id="deleteBundleTitle"><?= Icons::get('trash', 20) ?> Delete this bundle?</h2>
    <p>This permanently removes the bundle from the catalog. Wholesale clients will no longer see or order it. <strong>Past invoices keep their original line items</strong> and cannot be changed. This <strong>cannot be undone</strong>.</p>
    <label class="field" style="display:flex;gap:8px;align-items:center;margin:16px 0">
      <input type="checkbox" id="deleteBundleAck" style="width:auto">
      <span style="margin:0">I understand this cannot be undone</span>
    </label>
    <div class="toolbar" style="margin:0">
      <button type="button" class="btn btn-danger" id="deleteBundleConfirm" disabled>Delete bundle</button>
      <button type="button" class="btn btn-ghost" id="deleteBundleCancel">Cancel</button>
    </div>
  </div>
</div>

<?php page_script(<<<'JS'
(function(){
  function escapeHtml(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }
  function emptyRow(tbody){
    var tr = document.createElement('tr');
    tr.className = 'empty-row';
    tr.innerHTML = '<td colspan="5" class="empty">No products in this bundle.</td>';
    tbody.appendChild(tr);
  }
  function bindRow(card, tr){
    var qty = tr.querySelector('.min-qty');
    if (qty) qty.addEventListener('change', async function(){
      var id = tr.dataset.item;
      await hd.post('admin/api', {entity:'bundle_items', op:'update', id:id, min_qty:parseInt(qty.value||'1',10)});
      hd.toast('Minimum updated');
    });
    var del = tr.querySelector('.del-item');
    if (del) del.addEventListener('click', async function(){
      var id = tr.dataset.item;
      await hd.post('admin/api', {entity:'bundle_items', op:'delete', id:id});
      tr.remove();
      var tbody = card.querySelector('tbody');
      if (tbody && !tbody.querySelector('tr[data-item]')) emptyRow(tbody);
      hd.toast('Product removed');
    });
  }
  function itemRow(item){
    var tr = document.createElement('tr');
    tr.dataset.item = String(item.id);
    tr.setAttribute('draggable', 'true');
    tr.innerHTML = '<td class="drag-cell"><span class="drag-grip" title="Drag to reorder"></span></td>'
      + '<td>' + escapeHtml(item.title) + '</td>'
      + '<td>' + hd.money(item.price_cents) + '</td>'
      + '<td><input type="number" min="1" value="' + parseInt(item.min_qty||1,10) + '" class="min-qty" style="width:90px"></td>'
      + '<td><button class="btn btn-sm btn-danger del-item" type="button">Remove</button></td>';
    return tr;
  }
  function applyFilter(card){
    var filter = card.querySelector('.add-product-filter');
    var sel = card.querySelector('.add-product-sel');
    var addItem = card.querySelector('.add-item');
    if (!filter || !sel) return;
    var q = (filter.value || '').trim().toLowerCase();
    var visible = 0;
    var first = null;
    Array.prototype.forEach.call(sel.options, function(opt){
      var match = !q || (opt.textContent || '').toLowerCase().indexOf(q) !== -1;
      opt.hidden = !match;
      opt.disabled = !match;
      if (match) {
        visible++;
        if (!first) first = opt;
      }
    });
    var current = sel.options[sel.selectedIndex];
    if (first && (!current || current.hidden)) first.selected = true;
    sel.size = q ? Math.max(2, Math.min(8, visible || 2)) : 1;
    if (addItem) addItem.disabled = visible === 0;
  }
  async function saveOrder(card){
    var tbody = card.querySelector('tbody');
    var ids = Array.prototype.map.call(tbody.querySelectorAll('tr[data-item]'), function(tr){
      return parseInt(tr.dataset.item, 10);
    });
    var r = await hd.post('admin/api', {entity:'bundle_items', op:'reorder', bundle_id:card.dataset.bid, ids:ids});
    if (r.error) { hd.toast(r.message || 'Could not save order', 'error'); return; }
    hd.toast('Order saved');
  }
  function bindDrag(card){
    var tbody = card.querySelector('tbody');
    if (!tbody) return;
    var dragEl = null;
    tbody.addEventListener('mousedown', function(e){
      var tr = e.target.closest('tr[data-item]');
      if (!tr) return;
      tr.setAttribute('draggable', e.target.closest('.drag-grip, .drag-cell') ? 'true' : 'false');
    });
    tbody.addEventListener('dragstart', function(e){
      var tr = e.target.closest('tr[data-item]');
      if (!tr) return;
      if (e.target.closest('input,button,a,select')) { e.preventDefault(); return; }
      dragEl = tr;
      tr.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', tr.dataset.item);
    });
    tbody.addEventListener('dragend', function(){
      if (dragEl) dragEl.classList.remove('is-dragging');
      dragEl = null;
      tbody.querySelectorAll('.drag-over').forEach(function(el){ el.classList.remove('drag-over'); });
    });
    tbody.addEventListener('dragover', function(e){
      e.preventDefault();
      var tr = e.target.closest('tr[data-item]');
      if (!tr || tr === dragEl) return;
      tbody.querySelectorAll('.drag-over').forEach(function(el){ el.classList.remove('drag-over'); });
      tr.classList.add('drag-over');
    });
    tbody.addEventListener('drop', async function(e){
      e.preventDefault();
      var tr = e.target.closest('tr[data-item]');
      tbody.querySelectorAll('.drag-over').forEach(function(el){ el.classList.remove('drag-over'); });
      if (!dragEl || !tr || tr === dragEl) return;
      var rect = tr.getBoundingClientRect();
      var before = (e.clientY - rect.top) < rect.height / 2;
      tbody.insertBefore(dragEl, before ? tr : tr.nextSibling);
      await saveOrder(card);
    });
  }

  document.querySelectorAll('.card[data-bid]').forEach(function(card){
    var bid = card.dataset.bid;
    var titleInp = card.querySelector('.bundle-title');
    if (titleInp) titleInp.addEventListener('change', async function(){
      var title = (titleInp.value || '').trim();
      if (!title) { hd.toast('Bundle name cannot be empty', 'error'); titleInp.value = titleInp.defaultValue; return; }
      var r = await hd.post('admin/api', {entity:'bundles', op:'update', id:bid, changes:{title:title}});
      if (r.error) { hd.toast(r.message || 'Could not rename the bundle', 'error'); return; }
      titleInp.defaultValue = title;
      hd.toast('Bundle name saved');
    });
    card.querySelectorAll('tr[data-item]').forEach(function(tr){ bindRow(card, tr); });
    bindDrag(card);
    var filter = card.querySelector('.add-product-filter');
    if (filter) {
      filter.addEventListener('input', function(){ applyFilter(card); });
      filter.addEventListener('search', function(){ applyFilter(card); });
      applyFilter(card);
    }
    var addItem = card.querySelector('.add-item');
    if (addItem) addItem.addEventListener('click', async function(){
      var sel = card.querySelector('.add-product-sel');
      var pid = sel && sel.value;
      if (!pid) { hd.toast('No matching products', 'error'); return; }
      var min = parseInt(card.querySelector('.add-min').value||'1',10);
      var r = await hd.post('admin/api', {entity:'bundle_items', op:'add', bundle_id:bid, product_id:pid, min_qty:min});
      if (r.error || !r.item) { hd.toast((r && r.message) || 'Could not add product', 'error'); return; }
      var tbody = card.querySelector('tbody');
      var empty = tbody.querySelector('.empty-row');
      if (empty) empty.remove();
      var tr = itemRow(r.item);
      tbody.appendChild(tr);
      bindRow(card, tr);
      applyFilter(card);
      hd.toast('Product added');
    });
    var tog = card.querySelector('.toggle-public');
    if (tog) tog.addEventListener('click', async function(){
      var next = tog.dataset.public === '1' ? 0 : 1;
      var r = await hd.post('admin/api', {entity:'bundles', op:'update', id:bid, changes:{is_public:next}});
      if (r.error) { hd.toast(r.message || 'Could not update visibility', 'error'); return; }
      location.reload();
    });
  });
  var addBundle = document.getElementById('addBundle');
  if (addBundle) addBundle.addEventListener('click', async function(){
    var r = await hd.post('admin/api', {entity:'bundles', op:'create'});
    if (r.ok) location.reload();
  });

  var modal = document.getElementById('deleteBundleModal');
  var ack = document.getElementById('deleteBundleAck');
  var confirmBtn = document.getElementById('deleteBundleConfirm');
  if (modal && ack && confirmBtn) {
  var pendingId = null;
  function open(id){
    pendingId = id;
    ack.checked = false;
    confirmBtn.disabled = true;
    modal.hidden = false;
  }
  function close(){ modal.hidden = true; pendingId = null; }
  document.querySelectorAll('.del-bundle').forEach(function(btn){
    btn.addEventListener('click', function(){ open(btn.closest('[data-bid]').dataset.bid); });
  });
  document.getElementById('deleteBundleCancel').addEventListener('click', close);
  modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
  document.addEventListener('keydown', function(e){ if (!e.target.closest('.add-product-filter') && !modal.hidden && e.key === 'Escape') close(); });
  ack.addEventListener('change', function(){ confirmBtn.disabled = !ack.checked; });
  confirmBtn.addEventListener('click', async function(){
    if (!ack.checked || !pendingId) return;
    confirmBtn.disabled = true;
    var r = await hd.post('admin/api', {entity:'bundles', op:'delete', id:pendingId, confirm:true});
    if (r.error) { hd.toast(r.message || 'Delete failed', 'error'); confirmBtn.disabled = false; return; }
    hd.toast('Bundle deleted');
    close();
    location.reload();
  });
  }
})();
JS); ?>

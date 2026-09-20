<?php
use App\Icons;
use App\WorkOrders;
/** @var array $rows @var array $staff @var array $groups @var bool $isSuper @var bool $isAdmin @var string $active */
$qtyAlerts = [];
foreach ($groups as $oid => $g) {
    foreach ($g['items'] as $w) {
        if (!empty($w['qty_shortfall']) || !empty($w['qty_changed'])) {
            $qtyAlerts[] = ['order_id' => (int)$oid, 'client' => $g, 'item' => $w];
        }
    }
}
$statusOpts = ['pending' => 'pending', 'stalled' => 'stalled', 'complete' => 'complete', 'filled_from_stock' => 'Filled from stock'];
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('clipboard', 22) ?> Work orders</h1>
      <p class="muted">Provisioning orders appear here as one line per product type. Quantities above SPT split into Tray sub-tasks. Assign staff, check items off, mark stalled with a note, or fill from warehouse stock. Super Admin approves the client order when everything is complete or filled from stock.</p></div>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/work-orders/schedule')) ?>"><?= Icons::get('clock', 15) ?> Schedule</a></div>

    <?php if ($qtyAlerts): ?>
    <div class="flash warn wo-qty-alert" role="alert">
      <strong>Check originating order quantities before proceeding.</strong>
      <p style="margin:6px 0 0;font-weight:400">An admin recently changed the customer order quantity for one or more products. Tray totals below the updated order qty need a review on the originating order before work continues.</p>
      <ul>
        <?php foreach ($qtyAlerts as $alert):
          $item = $alert['item'];
          $oid = (int)$alert['order_id'];
          $client = trim((string)($alert['client']['client_name'] ?? '')) !== ''
            ? $alert['client']['client_name']
            : ($alert['client']['client_email'] ?? '');
          $was = $item['qty_was'] !== null && $item['qty_was'] !== '' ? (int)$item['qty_was'] : null;
          $ordered = (int)($item['ordered_qty'] ?? $item['qty']);
          $covered = (int)($item['covered_qty'] ?? $item['qty']);
        ?>
          <li>
            Order #<?= $oid ?> · <?= e((string)$client) ?> · <?= e((string)$item['title']) ?>
            <?php if ($was !== null && !empty($item['qty_changed'])): ?>
              — qty updated from <?= $was ?> to <?= $ordered ?>
            <?php endif; ?>
            <?php if (!empty($item['qty_shortfall'])): ?>
              — trays cover <?= $covered ?> of <?= $ordered ?> ordered
            <?php endif; ?>
            <?php if ($isAdmin): ?>
              · <a href="<?= e(url('admin/orders')) ?>">Open originating order</a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (!$groups): ?>
      <div class="card empty">No provisioning orders right now.</div>
    <?php endif; ?>

    <?php foreach ($groups as $oid => $g):
      $client = trim((string)$g['client_name']) !== '' ? $g['client_name'] : $g['client_email'];
      $allDone = $g['all_complete'];
    ?>
      <div class="wo-group" data-order="<?= (int)$oid ?>">
        <div class="wo-group-head">
          <div>
            <h2><?= e($client) ?> <span class="muted">· order #<?= (int)$oid ?></span></h2>
            <?php if ($allDone): ?><span class="badge completed">ready for approval</span><?php endif; ?>
          </div>
          <div class="toolbar" style="margin:0">
            <?php if ($isAdmin): ?>
              <label style="display:flex;gap:8px;align-items:center;margin:0">
                <span class="help" style="margin:0">Staff for this order</span>
                <select class="wo-assign-all" data-order="<?= (int)$oid ?>" style="width:auto;min-width:180px">
                  <option value="">Unassigned</option>
                  <?php foreach ($staff as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e(trim($s['first_name'] . ' ' . $s['last_name']) ?: $s['email']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <?php if ($isSuper && $allDone): ?>
              <button type="button" class="btn btn-primary btn-sm wo-approve" data-order="<?= (int)$oid ?>"><?= Icons::get('check', 15) ?> Approve</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap">
          <table class="grid">
            <thead><tr><th>Product</th><th>Qty</th><th>Client</th><th>Staff name</th><th>Status</th><th>Note</th></tr></thead>
            <?php foreach ($g['items'] as $w):
              $trays = $w['trays'] ?? [];
              $trayCount = count($trays);
              $parentDone = !empty($w['all_subtasks_done']);
              $collapsed = !empty($w['default_collapsed']);
              $blockClass = 'wo-block';
              if ($trayCount) {
                  $blockClass .= $collapsed ? ' is-collapsed' : ' is-open';
              }
            ?>
            <tbody class="<?= e($blockClass) ?>" data-wo="<?= (int)$w['id'] ?>" data-trays="<?= $trayCount ?>">
              <tr data-id="<?= (int)$w['id'] ?>" class="wo-parent<?= $parentDone ? ' wo-done' : '' ?>">
                <td>
                  <?php if ($trayCount): ?>
                    <button type="button" class="wo-toggle" aria-expanded="<?= $collapsed ? 'false' : 'true' ?>">
                      <span class="wo-title"><?= e($w['title']) ?></span>
                      <span class="wo-tray-count" title="<?= $trayCount ?> sub-task<?= $trayCount === 1 ? '' : 's' ?>"><?= $trayCount ?></span>
                    </button>
                  <?php else: ?>
                    <?= e($w['title']) ?>
                  <?php endif; ?>
                  <?php if (!empty($w['qty_changed']) || !empty($w['qty_shortfall'])): ?>
                    <div class="wo-qty-note">
                      <?php if (!empty($w['qty_changed'])): ?>
                        <span class="badge pending">qty updated<?= $w['qty_was'] !== null && $w['qty_was'] !== '' ? ' from ' . (int)$w['qty_was'] . ' to ' . (int)$w['qty'] : '' ?></span>
                      <?php endif; ?>
                      <?php if (!empty($w['qty_shortfall'])): ?>
                        <span class="badge stalled">trays <?= (int)$w['covered_qty'] ?> / ordered <?= (int)$w['ordered_qty'] ?> — check originating order</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><?= (int)$w['qty'] ?></td>
                <td><?= e($client) ?></td>
                <td>
                  <?php if ($isAdmin): ?>
                    <select class="wo-staff" style="width:auto;min-width:160px">
                      <option value="">Unassigned</option>
                      <?php foreach ($staff as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$w['staff_user_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                          <?= e(trim($s['first_name'] . ' ' . $s['last_name']) ?: $s['email']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <?= e(trim((string)($w['staff_name'] ?? '')) !== '' ? $w['staff_name'] : ($w['staff_email'] ?? 'Unassigned')) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <select class="wo-status" style="width:auto">
                    <?php foreach ($statusOpts as $val => $lab): ?>
                      <option value="<?= e($val) ?>" <?= $w['status'] === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <input type="text" class="wo-note" value="<?= e((string)($w['notes'] ?? '')) ?>" placeholder="Reason if stalled" style="min-width:180px">
                </td>
              </tr>
              <?php foreach ($trays as $tray):
                $trayDone = WorkOrders::isDone((string)$tray['status']);
              ?>
              <tr data-id="<?= (int)$tray['id'] ?>" class="wo-tray<?= $trayDone ? ' wo-done' : '' ?>">
                <td><span class="wo-tray-label"><?= e($tray['title']) ?></span></td>
                <td>
                  <input type="number" class="wo-qty" min="1" value="<?= (int)$tray['qty'] ?>" title="Override skeins on this tray" style="width:72px">
                </td>
                <td><?= e($client) ?></td>
                <td>
                  <?= e(trim((string)($tray['staff_name'] ?? '')) !== '' ? $tray['staff_name'] : ($tray['staff_email'] ?? '')) ?>
                </td>
                <td>
                  <select class="wo-status" style="width:auto">
                    <?php foreach ($statusOpts as $val => $lab): ?>
                      <option value="<?= e($val) ?>" <?= $tray['status'] === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <input type="text" class="wo-note" value="<?= e((string)($tray['notes'] ?? '')) ?>" placeholder="Reason if stalled" style="min-width:180px">
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php
page_script(<<<'JS'
(function(){
  document.querySelectorAll('.wo-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var block = btn.closest('tbody.wo-block');
      if (!block) return;
      var open = block.classList.contains('is-collapsed');
      block.classList.toggle('is-open', open);
      block.classList.toggle('is-collapsed', !open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.querySelectorAll('.wo-status').forEach(function(sel){
    sel.addEventListener('change', async function(){
      var tr = sel.closest('tr');
      var id = tr.dataset.id;
      var noteEl = tr.querySelector('.wo-note');
      var note = (noteEl && noteEl.value || '').trim();
      if (sel.value === 'stalled' && !note) {
        note = (window.prompt('Why is this work stalled?') || '').trim();
        if (!note) { sel.value = sel.getAttribute('data-prev') || 'pending'; return; }
        if (noteEl) noteEl.value = note;
      }
      var r = await hd.post('admin/api', {entity:'work_orders', op:'update', id:id, changes:{status: sel.value, notes: note}});
      if (r.error) { hd.toast(r.message || 'Could not update', 'error'); sel.value = sel.getAttribute('data-prev') || 'pending'; return; }
      hd.toast(sel.value === 'stalled' ? 'Stalled — admins have been notified' : 'Status saved');
      location.reload();
    });
    sel.setAttribute('data-prev', sel.value);
  });
  document.querySelectorAll('.wo-qty').forEach(function(inp){
    inp.addEventListener('change', async function(){
      var id = inp.closest('tr').dataset.id;
      var qty = parseInt(inp.value, 10) || 1;
      if (qty < 1) qty = 1;
      inp.value = String(qty);
      var r = await hd.post('admin/api', {entity:'work_orders', op:'update', id:id, changes:{qty: qty}});
      if (r.error) { hd.toast(r.message || 'Could not save qty', 'error'); return; }
      hd.toast('Tray quantity saved');
    });
  });
  document.querySelectorAll('.wo-note').forEach(function(inp){
    inp.addEventListener('change', async function(){
      var id = inp.closest('tr').dataset.id;
      var r = await hd.post('admin/api', {entity:'work_orders', op:'update', id:id, changes:{notes: inp.value}});
      if (r.error) { hd.toast(r.message || 'Could not save note', 'error'); return; }
      hd.toast('Note saved');
    });
  });
  document.querySelectorAll('.wo-staff').forEach(function(sel){
    sel.addEventListener('change', async function(){
      var id = sel.closest('tr').dataset.id;
      var r = await hd.post('admin/api', {entity:'work_orders', op:'update', id:id, changes:{staff_user_id: sel.value ? parseInt(sel.value,10) : 0}});
      if (r.error) { hd.toast(r.message || 'Could not assign', 'error'); return; }
      hd.toast('Staff assigned');
    });
  });
  document.querySelectorAll('.wo-assign-all').forEach(function(sel){
    sel.addEventListener('change', async function(){
      var r = await hd.post('admin/api', {entity:'work_orders', op:'assign_order', id: parseInt(sel.dataset.order,10), staff_user_id: sel.value ? parseInt(sel.value,10) : 0});
      if (r.error) { hd.toast(r.message || 'Could not assign', 'error'); return; }
      hd.toast('Staff set for this order');
      location.reload();
    });
  });
  document.querySelectorAll('.wo-approve').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var r = await hd.post('admin/api', {entity:'work_orders', op:'approve', id: parseInt(btn.dataset.order,10)});
      if (r.error) { hd.toast(r.message || 'Could not approve', 'error'); return; }
      hd.toast('Order marked completed');
      location.reload();
    });
  });
})();
JS);
?>

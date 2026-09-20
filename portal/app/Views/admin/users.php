<?php
use App\Icons;
use App\UserPrefs;
/** @var array $pending @var bool $canGrantAdmin @var string $active @var array $hiddenCols */
$hiddenJson = json_encode(array_values($hiddenCols ?? []), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_USERS);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('users', 22) ?> Users</h1>
      <p class="muted">Review applications, manage roles &amp; status. Tray rate is the maximum trays a staff member can complete per day. Discount is an Override Discount Rate (percent of retail) for that user’s orders; 0 uses the global wholesale percentage. Double-click a password cell, type the new password in clear text, then press Enter — it is stored as a one-way hash.</p></div></div>

    <?php if ($pending): ?>
    <h2>Pending applications <span class="badge hl"><?= count($pending) ?></span></h2>
    <div class="cards" style="margin:12px 0 26px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
      <?php foreach ($pending as $a): ?>
        <div class="card" data-uid="<?= (int)$a['id'] ?>">
          <strong><?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?></strong>
          <div class="muted"><?= e($a['email']) ?> · <?= e($a['phone'] ?? '') ?></div>
          <?php if (!empty($a['company_website'])): ?><div class="muted"><?= e($a['company_website']) ?></div><?php endif; ?>
          <p class="help">Finance: <?= e(trim(($a['fin_first_name'] ?? '') . ' ' . ($a['fin_last_name'] ?? ''))) ?: '—' ?></p>
          <p class="help"><strong>Office:</strong> <?= nl2br(e($a['office_address'] ?? '')) ?></p>
          <p class="help"><strong>Delivery:</strong> <?= nl2br(e($a['delivery_address'] ?? '')) ?></p>
          <?php if (!empty($a['application_message'])): ?><p class="help"><strong>Message:</strong> <?= e($a['application_message']) ?></p><?php endif; ?>
          <label style="display:flex;gap:6px;align-items:center;margin:8px 0">
            <input type="checkbox" class="ignore-min" style="width:auto"> Ignore bundle minimums for this client
          </label>
          <button class="btn btn-primary btn-sm approve-btn"><?= Icons::get('check', 15) ?> Approve as wholesale</button>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>All users</h2>
    <p class="help"><?= $canGrantAdmin
      ? 'You are the superuser: you may grant the <strong>admin</strong> or <strong>staff</strong> role.'
      : 'You may assign the <strong>staff</strong> role. Only the superuser can grant the admin role.' ?></p>
    <div id="usersGrid"></div>
  </div>
</div>

<?php
$roleOptions = $canGrantAdmin ? "['guest','wholesale','staff','admin']" : "['guest','wholesale','staff']";
page_script(<<<JS
(function(){
  document.querySelectorAll('.approve-btn').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var card = btn.closest('[data-uid]');
      var uid = card.dataset.uid;
      var ignore = card.querySelector('.ignore-min').checked ? 1 : 0;
      var r = await hd.post('admin/api', {entity:'user_action', op:'approve', id:uid, ignore_min_quantities:ignore});
      if (r.ok) { hd.toast('Approved'); card.remove(); }
    });
  });
  new hd.DataGrid('usersGrid', {
    entity: 'users',
    persistHidden: $persistKey,
    hidden: $hiddenJson,
    columns: [
      {key:'id', label:'#'},
      {key:'email', label:'Email', editable:true},
      {key:'first_name', label:'First', editable:true},
      {key:'last_name', label:'Last', editable:true},
      {key:'role', label:'Role', type:'badge', editable:true, options:$roleOptions},
      {key:'status', label:'Status', type:'badge', editable:true, options:['active','pending','disabled']},
      {key:'password', label:'Password', type:'password', editable:true},
      {key:'ignore_min_quantities', label:'Ignore min', tip:'Ignore minimum order quantities', type:'bool', editable:true, options:['0','1']},
      {key:'tray_rate', label:'Tray rate', tip:'Maximum trays this person can complete per day', editable:true},
      {key:'discount_percent', label:'Discount', tip:'Override Discount Rate', editable:true},
      {key:'phone', label:'Phone', editable:true},
      {key:'created_at', label:'Joined'}
    ]
  });
})();
JS);
?>

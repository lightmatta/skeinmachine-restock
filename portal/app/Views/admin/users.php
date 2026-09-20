<?php
use App\Icons;
use App\UserPrefs;
/** @var bool $canGrantAdmin @var string $active @var array $hiddenCols */
$hiddenJson = json_encode(array_values($hiddenCols ?? []), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_USERS);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('users', 22) ?> Users</h1>
      <p class="muted">Manage staff and admin accounts. Double-click a password cell, type the new password in clear text, then press Enter — it is stored as a one-way hash.</p></div></div>

    <h2>All users</h2>
    <p class="help"><?= $canGrantAdmin
      ? 'You are the superuser: you may grant the <strong>admin</strong> or <strong>staff</strong> role.'
      : 'You may assign the <strong>staff</strong> role. Only the superuser can grant the admin role.' ?></p>
    <div id="usersGrid"></div>
  </div>
</div>

<?php
$roleOptions = $canGrantAdmin ? "['staff','admin']" : "['staff']";
page_script(<<<JS
(function(){
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
      {key:'status', label:'Status', type:'badge', editable:true, options:['active','disabled']},
      {key:'password', label:'Password', type:'password', editable:true},
      {key:'phone', label:'Phone', editable:true},
      {key:'created_at', label:'Joined'}
    ]
  });
})();
JS);
?>

<?php use App\Icons; use App\UserPrefs; /** @var string $active @var bool $readonly @var array $hiddenCols */
$readonly = !empty($readonly);
$hiddenJson = json_encode(array_values($hiddenCols ?? []), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_SOURCES);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('store', 22) ?> Sources</h1>
      <p class="muted"><?= $readonly
        ? 'Shopify collection feeds are read-only for staff accounts. Sync now still pulls the collection when a Collection ID is set.'
        : 'One Shopify collection per row. Set Vendor Name, Collection ID and how often to sync (in days). Sync now refuses to run when Collection ID is empty.' ?></p></div></div>
    <div id="sourcesGrid"></div>
  </div>
</div>
<?php
$roJs = $readonly ? 'true' : 'false';
page_script(<<<JS
(function(){
  var grid = new hd.DataGrid('sourcesGrid', {
    entity: 'sources',
    readonly: $roJs,
    columns: [
      {key:'id', label:'#', local:true},
      {key:'vendor_name', label:'Vendor Name', editable:true, local:true},
      {key:'collection_name', label:'Source Name', tip:'Shopify collection title. Filled automatically when empty; a name you type is kept.', editable:true, local:true},
      {key:'collection_id', label:'Collection ID', tip:'Shopify collection used when you tap Sync now', editable:true, local:true},
      {key:'sync_frequency_days', label:'Sync frequency (days)', tip:'How often automated sync should pull this collection. New sources start from the default in Settings.', editable:true, local:true},
      {key:'last_sync_at', label:'Last sync', tip:'Date and time of the last successful Shopify pull'}
    ],
    hidden: $hiddenJson,
    persistHidden: $persistKey,
    rowActions: [{op:'sync', title:'Sync now', icon:'refresh'}]
  });
  var orig = grid.customAction.bind(grid);
  grid.customAction = async function(row, op, title){
    if (op === 'sync') {
      if (!(row.collection_id || '').toString().trim()) {
        hd.toast('Add a Collection ID before syncing this source.', 'error');
        return {ok:false, error:'no_collection'};
      }
      hd.toast('Syncing ' + (row.vendor_name || 'source') + '…');
    }
    var data = await orig(row, op, title);
    if (op === 'sync' && data && data.ok) {
      hd.toast(data.message || ('Synced ' + (data.fetched || 0) + ' products'));
      grid.reload();
    }
    return data;
  };
})();
JS);
?>

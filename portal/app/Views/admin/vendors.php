<?php use App\Icons; use App\UserPrefs; /** @var string $active @var bool $readonly @var array $hiddenCols */
$readonly = !empty($readonly);
$hiddenJson = json_encode(array_values($hiddenCols ?? ['notes']), JSON_UNESCAPED_SLASHES);
$persistKey = json_encode(UserPrefs::GRID_VENDORS);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('truck', 22) ?> Vendors</h1>
      <p class="muted">Suppliers you buy stock from. Store a vendor ID, name, and one stock URL — or a list of URLs, one per line — to scrape current stock. Use Scrape on a row after the URLs are saved.</p></div></div>
    <div id="vendorsGrid"></div>
  </div>
</div>
<?php
$roJs = $readonly ? 'true' : 'false';
page_script(<<<JS
(function(){
  var grid = new hd.DataGrid('vendorsGrid', {
    entity: 'vendors',
    readonly: $roJs,
    columns: [
      {key:'id', label:'#'},
      {key:'vendor_id', label:'Vendor ID', editable:true},
      {key:'name', label:'Vendor name', editable:true},
      {key:'stock_urls', label:'Stock URL(s)', tip:'One URL, or several URLs on separate lines. Each is scraped in turn.', editable:true},
      {key:'url_count', label:'URLs'},
      {key:'notes', label:'Notes', editable:true},
      {key:'archived', label:'Archived', type:'bool', editable:true, options:['0','1']}
    ],
    hidden: $hiddenJson,
    persistHidden: $persistKey,
    rowActions: [{op:'scrape', title:'Scrape stock', icon:'refresh'}]
  });
  var orig = grid.customAction.bind(grid);
  grid.customAction = async function(row, op, title){
    if (op === 'scrape') {
      hd.toast('Scraping ' + (row.name || 'vendor') + '…');
    }
    var data = await orig(row, op, title);
    if (op === 'scrape' && data && data.ok) {
      hd.toast('Scraped ' + (data.fetched || 0) + ': ' + (data.created || 0) + ' new, ' + (data.updated || 0) + ' updated');
    }
    return data;
  };
})();
JS);
?>

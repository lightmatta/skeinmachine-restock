<?php
use App\Icons;
/** @var array $settings @var bool $saved @var string $active */
$s = fn(string $k, string $d = '') => e($settings[$k] ?? $d);
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('settings', 22) ?> Settings</h1>
      <p class="muted">Branding, contact details, and Shopify sync for the products catalog.</p></div></div>

    <?php if ($saved): ?><div class="flash ok">Settings saved.</div><?php endif; ?>

    <form method="post" action="<?= e(url('admin/settings')) ?>">
      <?= csrf_field() ?>
      <div class="card" style="margin-bottom:16px">
        <h2>Branding</h2>
        <div class="form-grid">
          <label class="field"><span>Company name</span><input name="company_name" value="<?= $s('company_name') ?>"></label>
          <label class="field"><span>Highlight color</span><input type="color" name="highlight_color" value="<?= $s('highlight_color', '#FF6F61') ?>" style="height:42px"></label>
          <label class="field"><span>Logo URL</span><input name="logo_url" value="<?= $s('logo_url') ?>" placeholder="https://…/logo.svg"></label>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <h2>Contact</h2>
        <div class="form-grid">
          <label class="field"><span>Contact email</span><input name="contact_email" value="<?= $s('contact_email') ?>"></label>
          <label class="field"><span>Contact phone</span><input name="contact_phone" value="<?= $s('contact_phone') ?>"></label>
          <label class="field"><span>Opening hours</span><input name="opening_hours" value="<?= $s('opening_hours') ?>"></label>
          <label class="field"><span>Financial year start month (1–12)</span><input type="number" min="1" max="12" name="fy_start_month" value="<?= $s('fy_start_month', '7') ?>"></label>
          <label class="field"><span>Currency</span>
            <select name="currency">
              <?php foreach (\App\Currency::codes() as $code => $name): ?>
                <option value="<?= e($code) ?>" <?= ($settings['currency'] ?? 'AUD') === $code ? 'selected' : '' ?>><?= e($code) ?> — <?= e($name) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <h2><?= Icons::get('store', 18) ?> Shopify sync</h2>
        <p class="help">Pull products from a Shopify <strong>collection</strong> into the Products table. Save first, then use <strong>Refresh / Sync now</strong>. Vendor, Min, Goal and Status on existing products are kept; stock, title, SKU and price refresh from Shopify.</p>
        <p class="help">Newer Shopify Dev Dashboard apps no longer provide a copyable Admin API access token. Enter the app <strong>Client ID</strong> and <strong>Client secret</strong> plus the store domain. The portal requests and renews the Admin API token itself using Shopify’s client-credentials grant.</p>
        <div class="form-grid">
          <label class="field"><span>Store domain</span><input name="shopify_domain" value="<?= $s('shopify_domain') ?>" placeholder="your-shop.myshopify.com" autocomplete="off"></label>
          <label class="field"><span>Client ID (API key)</span><input name="shopify_client_id" value="<?= $s('shopify_client_id') ?>" placeholder="From Dev Dashboard → Settings → Credentials" autocomplete="off"></label>
          <label class="field"><span>Client secret (API secret)</span><input type="password" name="shopify_client_secret" placeholder="<?= ($settings['shopify_client_secret'] ?? '') !== '' ? '•••••••• (set — leave blank to keep)' : 'From Dev Dashboard → Settings → Credentials' ?>" autocomplete="new-password"></label>
          <label class="field"><span>API version</span><input name="shopify_api_version" value="<?= $s('shopify_api_version', '2024-10') ?>" placeholder="2024-10"></label>
          <label class="field"><span>Collection ID</span><input name="shopify_collection_id" value="<?= $s('shopify_collection_id') ?>" placeholder="e.g. 123456789"></label>
        </div>
        <label class="field" style="display:flex;gap:8px;align-items:center;margin-top:12px">
          <input type="checkbox" name="shopify_periodic_sync" value="1" style="width:auto" <?= ($settings['shopify_periodic_sync'] ?? '0') === '1' ? 'checked' : '' ?>>
          <span style="margin:0">Periodic Sync</span>
        </label>
        <label class="field" style="max-width:220px"><span>Sync interval (minutes)</span>
          <input type="number" name="shopify_periodic_minutes" min="1" max="1440" value="<?= e((string)($settings['shopify_periodic_minutes'] ?? '60')) ?>">
        </label>
        <p class="help" style="margin-top:-4px">When Periodic Sync is on, the portal pulls stock levels from the collection above on this interval and updates the Products grid. Saving restarts the clock from now.</p>
        <div class="toolbar" style="margin:4px 0 0">
          <button type="button" class="btn btn-sm" id="shopifyTest"><?= Icons::get('link', 16) ?> Test connection</button>
          <button type="button" class="btn btn-sm btn-primary" id="shopifySync"><?= Icons::get('refresh', 16) ?> Refresh / Sync now</button>
          <span id="shopifyResult" class="help"></span>
        </div>
        <?php if (!empty($settings['shopify_last_sync'])): ?>
          <p class="help" style="margin-top:8px">Last full sync: <?= e($settings['shopify_last_sync']) ?></p>
        <?php endif; ?>
        <?php if (!empty($settings['shopify_last_stock_sync'])): ?>
          <p class="help" style="margin-top:4px">Last stock sync: <?= e($settings['shopify_last_stock_sync']) ?></p>
        <?php endif; ?>
      </div>

      <button class="btn btn-primary" type="submit"><?= Icons::get('check', 18) ?> Save settings</button>
    </form>

    <div class="card" style="margin-top:16px">
      <h2><?= Icons::get('box', 18) ?> Shopify CSV import</h2>
      <p class="help">Alternative to the API connection. Upload a <strong>product export CSV</strong> from Shopify (Products → Export). Products are matched by portal id, Handle, or SKU. Vendor, Min and Goal on existing rows are left alone.</p>
      <div class="toolbar" style="margin-top:10px">
        <input type="file" id="shopifyCsvFile" accept=".csv,text/csv" style="max-width:320px">
        <button type="button" class="btn btn-sm btn-primary" id="shopifyCsvImport"><?= Icons::get('plus', 16) ?> Import CSV</button>
        <span id="shopifyCsvResult" class="help"></span>
      </div>
      <?php if (!empty($settings['shopify_last_csv_import'])): ?>
        <p class="help" style="margin-top:8px">Last CSV import: <?= e($settings['shopify_last_csv_import']) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php page_script(<<<'JS'
(function(){
  var res = document.getElementById('shopifyResult');
  function busy(msg){ res.textContent = msg; res.style.color = ''; }
  function ok(msg){ res.textContent = msg; res.style.color = '#157a3a'; }
  function err(msg){ res.textContent = msg; res.style.color = '#b3261e'; }
  var testBtn = document.getElementById('shopifyTest');
  var syncBtn = document.getElementById('shopifySync');
  if (testBtn) testBtn.addEventListener('click', async function(){
    busy('Testing connection…');
    var r = await hd.post('admin/api', {entity:'shopify', op:'test'});
    if (r.ok) ok('Connected to ' + (r.shop || 'store') + '.'); else err(r.error || 'Connection failed.');
  });
  if (syncBtn) syncBtn.addEventListener('click', async function(){
    busy('Syncing products from Shopify…');
    var r = await hd.post('admin/api', {entity:'shopify', op:'sync'});
    if (r.ok) ok('Synced: ' + r.fetched + ' fetched, ' + r.created + ' created, ' + r.updated + ' updated.');
    else err(r.error || 'Sync failed.');
  });
  var csvBtn = document.getElementById('shopifyCsvImport');
  var csvFile = document.getElementById('shopifyCsvFile');
  var csvRes = document.getElementById('shopifyCsvResult');
  function csvBusy(msg){ csvRes.textContent = msg; csvRes.style.color = ''; }
  function csvOk(msg){ csvRes.textContent = msg; csvRes.style.color = '#157a3a'; }
  function csvErr(msg){ csvRes.textContent = msg; csvRes.style.color = '#b3261e'; }
  if (csvBtn) csvBtn.addEventListener('click', async function(){
    if (!csvFile.files || !csvFile.files[0]) { csvErr('Choose a Shopify CSV file first.'); return; }
    csvBusy('Importing CSV…');
    var fd = new FormData();
    fd.append('csrf', hd.csrf);
    fd.append('csv', csvFile.files[0]);
    try {
      var res = await fetch('index.php?r=' + encodeURIComponent('admin/shopify-csv'), {
        method: 'POST',
        headers: { 'X-CSRF-Token': hd.csrf, 'X-Requested-With': 'fetch' },
        body: fd
      });
      var r = await res.json();
      if (r.ok) csvOk('Imported: ' + r.created + ' created, ' + r.updated + ' updated' + (r.skipped ? ', ' + r.skipped + ' skipped' : '') + '.');
      else csvErr(r.error || 'Import failed.');
    } catch (e) {
      csvErr('Import failed.');
    }
  });
})();
JS); ?>

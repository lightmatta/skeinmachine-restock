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
        <p class="help">Credentials used by every <strong>Sources</strong> row. Each source has its own Collection ID and <strong>Sync now</strong> action. Vendor, Min, Goal and Status on existing products are kept; stock, title, SKU and price refresh from Shopify.</p>
        <p class="help">Newer Shopify Dev Dashboard apps no longer provide a copyable Admin API access token. Enter the app <strong>Client ID</strong> and <strong>Client secret</strong> plus the store domain. The portal requests and renews the Admin API token itself using Shopify’s client-credentials grant.</p>
        <div class="form-grid">
          <label class="field"><span>Store domain</span><input name="shopify_domain" value="<?= $s('shopify_domain') ?>" placeholder="your-shop.myshopify.com" autocomplete="off"></label>
          <label class="field"><span>Client ID (API key)</span><input name="shopify_client_id" value="<?= $s('shopify_client_id') ?>" placeholder="From Dev Dashboard → Settings → Credentials" autocomplete="off"></label>
          <label class="field"><span>Client secret (API secret)</span><input type="password" name="shopify_client_secret" placeholder="<?= ($settings['shopify_client_secret'] ?? '') !== '' ? '•••••••• (set — leave blank to keep)' : 'From Dev Dashboard → Settings → Credentials' ?>" autocomplete="new-password"></label>
          <label class="field"><span>API version</span><input name="shopify_api_version" value="<?= $s('shopify_api_version', '2024-10') ?>" placeholder="2024-10"></label>
        </div>
        <label class="field" style="display:flex;gap:8px;align-items:center;margin-top:12px">
          <input type="checkbox" name="allow_automated_sync" value="1" style="width:auto" <?= ($settings['allow_automated_sync'] ?? '0') === '1' ? 'checked' : '' ?>>
          <span style="margin:0">Allow automated sync schedules</span>
        </label>
        <p class="help" style="margin-top:-4px">When this is on, each source syncs on its own frequency (in days). Sources due on the same day are spread evenly across 24 hours so the server is not hit with every collection at once.</p>
        <label class="field" style="margin-top:12px;max-width:280px">
          <span>Default sync frequency (days)</span>
          <input type="number" min="1" name="default_sync_frequency_days" value="<?= e((string)max(1, (int)($settings['default_sync_frequency_days'] ?? 1))) ?>">
        </label>
        <p class="help" style="margin-top:-4px">Applied when a new source is created. Admins can still change the frequency on each row in Sources.</p>
        <label class="field" style="display:flex;gap:8px;align-items:center;margin-top:12px">
          <input type="checkbox" name="report_urgency_colors" value="1" style="width:auto" <?= ($settings['report_urgency_colors'] ?? '1') !== '0' ? 'checked' : '' ?>>
          <span style="margin:0">Show restock urgency colours on report rows</span>
        </label>
        <p class="help" style="margin-top:-4px">Pastel red when inventory is 0, orange at or below 50% of Min, yellow at Min. Turn off for a plain table.</p>
        <div class="toolbar" style="margin:4px 0 0">
          <button type="button" class="btn btn-sm" id="shopifyTest"><?= Icons::get('link', 16) ?> Test connection</button>
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
  if (testBtn) testBtn.addEventListener('click', async function(){
    busy('Testing connection…');
    var r = await hd.post('admin/api', {entity:'shopify', op:'test'});
    if (r.ok) ok('Connected to ' + (r.shop || 'store') + '.'); else err(r.error || 'Connection failed.');
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

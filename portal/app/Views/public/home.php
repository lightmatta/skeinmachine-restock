<?php
use App\Icons;
use App\RestockOrders;
use App\Settings;
/** @var bool $signedIn @var list<array<string,mixed>> $reports */
$signedIn = !empty($signedIn);
$reports = $reports ?? [];
$company = Settings::get('company_name', '');
$urgencyOn = RestockOrders::urgencyColorsEnabled();
?>
<div class="container">
  <?php if (!$signedIn): ?>
    <div class="page-head">
      <div>
        <h1><?= e($company !== '' ? $company : 'Restock portal') ?></h1>
        <p class="muted">Sign in to view per-vendor restock reports for items below their goal stock level.</p>
      </div>
      <a class="btn btn-primary" href="<?= e(url('login')) ?>"><?= Icons::get('login', 17) ?> Sign in</a>
    </div>
    <div class="card">
      <p style="margin:0">This portal lists restock quantities by vendor. There is no public product catalog.</p>
    </div>
  <?php else: ?>
    <div class="page-head">
      <div>
        <h1><?= Icons::get('clipboard', 22) ?> Reports</h1>
        <p class="muted">Items whose current stock is below Goal, grouped by vendor. Copy or print a Restock Request Form for each vendor.</p>
        <?php if ($urgencyOn): ?>
        <div class="urgency-legend" aria-label="Restock urgency colours from out of stock to Min">
          <span class="urg-scale" aria-hidden="true"></span>
          <span class="urg-scale-labels">
            <span>0 — out of stock</span>
            <span>50% of Min</span>
            <span>Min</span>
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="toolbar report-filters">
      <label class="field" style="margin:0;min-width:220px">
        <span>Vendor Name</span>
        <input type="search" id="vendorFilter" placeholder="Filter by vendor name…" autocomplete="off">
      </label>
      <label class="field" style="margin:0;flex:1;min-width:220px">
        <span>Keyword</span>
        <input type="search" id="keywordFilter" placeholder="SKU, product ID, or product name…" autocomplete="off">
      </label>
      <span class="muted" id="reportInfo"></span>
    </div>

    <p class="muted" id="reportEmpty" <?= $reports ? 'hidden' : '' ?>><?= $reports
      ? 'No restock reports match those filters.'
      : 'No catalog items are below their goal stock level.' ?></p>

    <div class="restock-reports" id="restockReports">
      <?php foreach ($reports as $g):
        $copy = RestockOrders::reportText($g);
        $vendorKey = strtolower((string)$g['vendor_name']);
        $label = (string)($g['label'] ?? $g['vendor_name']);
        $pdfHref = url('report.pdf', ['vendor' => (string)$g['vendor_name']]);
      ?>
      <article class="card restock-report" data-vendor="<?= e($vendorKey) ?>" data-label="<?= e(strtolower($label)) ?>">
        <div class="restock-report-head">
          <div>
            <h2><?= e($label) ?></h2>
            <?php if (!empty($g['vendor_code'])): ?><p class="muted" style="margin:2px 0 0"><?= e((string)$g['vendor_code']) ?></p><?php endif; ?>
          </div>
          <div class="report-actions">
            <button type="button" class="copy-report" title="Copy as text" aria-label="Copy as text">
              <?= Icons::get('copy', 18) ?>
            </button>
            <a class="copy-report pdf-report" href="<?= e($pdfHref) ?>" title="Download Restock Request Form PDF" aria-label="Download Restock Request Form PDF" target="_blank" rel="noopener">
              <?= Icons::get('pdf', 18) ?>
            </a>
          </div>
        </div>
        <pre class="report-copy-src" hidden><?= e($copy) ?></pre>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th class="col-sku">SKU</th>
                <th class="col-pid">ProductID</th>
                <th class="col-name">Product Name</th>
                <th class="col-inv" title="Current Inventory Stock Level">Inv</th>
                <th class="col-qty">Order Quantity</th>
                <th class="col-total" title="Inv + Order Quantity">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($g['lines'] as $line):
                $hay = strtolower(trim(($line['sku'] ?? '') . ' ' . ($line['product_id'] ?? '') . ' ' . ($line['title'] ?? '')));
                $stock = (int)$line['stock'];
                $min = (int)($line['min_qty'] ?? 0);
                $urgColor = $urgencyOn ? RestockOrders::urgencyColor($stock, $min) : '';
                $skuShow = RestockOrders::displayCode($line['sku'] ?? '');
                $pidShow = RestockOrders::displayCode($line['product_id'] ?? '');
                $pct = $min > 0 ? (int)round(100 * $stock / $min) : ($stock <= 0 ? 0 : 100);
              ?>
              <tr class="<?= $urgColor !== '' ? 'has-urgency' : '' ?>"
                  <?= $urgColor !== '' ? 'style="--urg:' . e($urgColor) . '"' : '' ?>
                  data-search="<?= e($hay) ?>"
                  data-sku="<?= e((string)$line['sku']) ?>"
                  data-pid="<?= e((string)$line['product_id']) ?>"
                  data-title="<?= e((string)$line['title']) ?>"
                  data-need="<?= (int)$line['need_qty'] ?>"
                  data-goal="<?= (int)$line['goal_qty'] ?>"
                  data-stock="<?= (int)$line['stock'] ?>">
                <td class="col-sku"><?= e($skuShow) ?></td>
                <td class="col-pid"><?= e($pidShow) ?></td>
                <td class="col-name" title="<?= e((string)$line['title']) ?>" data-full="<?= e((string)$line['title']) ?>" tabindex="0">
                  <span class="name-clip"><?= e((string)$line['title']) ?></span>
                </td>
                <td class="col-inv need-qty" title="Current Inventory Stock Level · <?= (int)$pct ?>% of Min"><?= (int)$line['stock'] ?></td>
                <td class="col-qty need-qty"><?= (int)$line['need_qty'] ?></td>
                <td class="col-total need-qty"><?= (int)$line['stock'] + (int)$line['need_qty'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="modal" id="copyPreviewModal" hidden>
      <div class="modal-card copy-preview-card" role="dialog" aria-labelledby="copyPreviewTitle" aria-modal="true">
        <h2 id="copyPreviewTitle"><?= Icons::get('copy', 20) ?> Restock request</h2>
        <p class="muted" id="copyPreviewStatus">A neatly formatted copy of this request is ready.</p>
        <div class="copy-preview-letter">
          <pre id="copyPreviewText"></pre>
        </div>
        <div class="toolbar" style="margin:16px 0 0">
          <button type="button" class="btn btn-primary" id="copyPreviewClose">Done</button>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($signedIn):
page_script(<<<'JS'
(function(){
  var vendorInp = document.getElementById('vendorFilter');
  var kwInp = document.getElementById('keywordFilter');
  var info = document.getElementById('reportInfo');
  var empty = document.getElementById('reportEmpty');
  var cards = document.querySelectorAll('.restock-report');

  function visibleRows(card){
    return Array.prototype.filter.call(card.querySelectorAll('tbody tr[data-search]'), function(tr){ return !tr.hidden; });
  }

  function apply(){
    var vn = ((vendorInp && vendorInp.value) || '').trim().toLowerCase();
    var q = ((kwInp && kwInp.value) || '').trim().toLowerCase();
    var shown = 0;
    var lines = 0;
    cards.forEach(function(card){
      var name = (card.getAttribute('data-vendor') || '');
      var label = (card.getAttribute('data-label') || '');
      var vendorOk = !vn || name.indexOf(vn) !== -1 || label.indexOf(vn) !== -1;
      var visible = 0;
      card.querySelectorAll('tbody tr[data-search]').forEach(function(tr){
        var hay = (tr.getAttribute('data-search') || '');
        var ok = vendorOk && (!q || hay.indexOf(q) !== -1);
        tr.hidden = !ok;
        if (ok) visible++;
      });
      var show = vendorOk && visible > 0;
      card.hidden = !show;
      if (show) { shown++; lines += visible; }
      var pdf = card.querySelector('.pdf-report');
      if (pdf) {
        var vendor = card.getAttribute('data-vendor') || '';
        var href = 'index.php?r=' + encodeURIComponent('report.pdf') + '&vendor=' + encodeURIComponent(vendor);
        if (q) href += '&q=' + encodeURIComponent(q);
        pdf.setAttribute('href', href);
      }
    });
    if (info) {
      info.textContent = (vn || q) ? (shown + ' vendor' + (shown===1?'':'s') + ' · ' + lines + ' item' + (lines===1?'':'s')) : '';
    }
    if (empty) empty.hidden = shown > 0;
  }

  function reportText(card){
    var titleEl = card.querySelector('h2');
    var label = titleEl ? titleEl.textContent.trim() : 'Vendor';
    var codeEl = card.querySelector('.restock-report-head .muted');
    var code = codeEl ? codeEl.textContent.trim() : '';
    var rows = visibleRows(card);
    var total = 0;
    var items = rows.map(function(tr){
      var need = Number(tr.getAttribute('data-need') || 0);
      total += need;
      return {
        sku: tr.getAttribute('data-sku') || '',
        pid: tr.getAttribute('data-pid') || '',
        title: tr.getAttribute('data-title') || '',
        need: need,
        goal: Number(tr.getAttribute('data-goal') || 0),
        stock: Number(tr.getAttribute('data-stock') || 0)
      };
    });
    var n = items.length;
    var out = ['Restock Request Form', label];
    if (code) out.push('Vendor code: ' + code);
    out.push('Date: ' + new Date().toLocaleDateString(undefined, {day:'numeric', month:'long', year:'numeric'}));
    out.push(n + ' item' + (n===1?'':'s') + ' · ' + total + ' unit' + (total===1?'':'s') + ' to order');
    out.push('================================================');
    out.push('');
    out.push('Hello,');
    out.push('');
    out.push('Please supply the following so we can bring on-hand stock up to our goal levels:');
    out.push('');
    items.forEach(function(it, i){
      var sku = (it.sku && it.sku !== '—') ? it.sku : 'unknown';
      var pid = (it.pid && it.pid !== '—') ? it.pid : 'unknown';
      out.push((i+1) + '. ' + it.title);
      out.push('   SKU: ' + sku);
      out.push('   Product ID: ' + pid);
      out.push('   Current inventory: ' + it.stock);
      out.push('   Order quantity: ' + it.need + '  (goal ' + it.goal + ' − current ' + it.stock + ')');
      out.push('   Total: ' + (it.stock + it.need));
      out.push('');
    });
    out.push('Thank you.');
    return out.join('\n') + '\n';
  }

  function closeNames(except){
    document.querySelectorAll('.col-name.is-open').forEach(function(td){
      if (td !== except) td.classList.remove('is-open');
    });
  }

  document.querySelectorAll('td.col-name').forEach(function(td){
    td.addEventListener('click', function(e){
      e.stopPropagation();
      var open = td.classList.contains('is-open');
      closeNames();
      if (!open) td.classList.add('is-open');
    });
    td.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        td.click();
      }
    });
  });
  document.addEventListener('click', function(){ closeNames(); });

  if (vendorInp) vendorInp.addEventListener('input', apply);
  if (kwInp) kwInp.addEventListener('input', apply);
  apply();

  function showCopyPreview(text, copied){
    var modal = document.getElementById('copyPreviewModal');
    var body = document.getElementById('copyPreviewText');
    var status = document.getElementById('copyPreviewStatus');
    if (!modal || !body) return;
    body.textContent = text;
    if (status) {
      status.textContent = copied
        ? 'Copied to your clipboard. Preview of the restock request:'
        : 'Could not copy automatically. Select the text below to copy it.';
    }
    modal.hidden = false;
  }
  function hideCopyPreview(){
    var modal = document.getElementById('copyPreviewModal');
    if (modal) modal.hidden = true;
  }
  var previewClose = document.getElementById('copyPreviewClose');
  if (previewClose) previewClose.addEventListener('click', hideCopyPreview);
  var previewModal = document.getElementById('copyPreviewModal');
  if (previewModal) {
    previewModal.addEventListener('click', function(e){ if (e.target === previewModal) hideCopyPreview(); });
  }
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') hideCopyPreview();
  });

  document.querySelectorAll('.copy-report').forEach(function(btn){
    if (btn.classList.contains('pdf-report')) return;
    btn.addEventListener('click', async function(){
      var card = btn.closest('.restock-report');
      if (!card) return;
      var text = reportText(card);
      var copied = false;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          try {
            await navigator.clipboard.writeText(text);
            copied = true;
          } catch (e1) { copied = false; }
        }
        if (!copied) {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.setAttribute('readonly', '');
          ta.style.position = 'fixed';
          ta.style.top = '0';
          ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.focus();
          ta.select();
          copied = document.execCommand('copy');
          ta.remove();
        }
      } catch (e) {
        copied = false;
      }
      showCopyPreview(text, copied);
      if (copied) {
        btn.classList.add('is-copied');
        btn.setAttribute('title', 'Copied');
        if (window.hd && hd.toast) hd.toast('Copied restock request');
        setTimeout(function(){
          btn.classList.remove('is-copied');
          btn.setAttribute('title', 'Copy as text');
        }, 1600);
      } else if (window.hd && hd.toast) {
        hd.toast('Preview ready — copy the text from the window', 'error');
      }
    });
  });
})();
JS);
endif; ?>

<?php
use App\Icons;
use App\RestockOrders;
use App\Settings;
/** @var bool $signedIn @var list<array<string,mixed>> $reports */
$signedIn = !empty($signedIn);
$reports = $reports ?? [];
$company = Settings::get('company_name', '');
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
        <h1><?= Icons::get('clipboard', 22) ?> Vendor restock reports</h1>
        <p class="muted">Items whose current stock is below Goal, grouped by vendor. Copy a report to paste into a restock email.</p>
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
      ?>
      <article class="card restock-report" data-vendor="<?= e($vendorKey) ?>">
        <div class="restock-report-head">
          <div>
            <h2><?= e((string)$g['vendor_name']) ?></h2>
            <?php if (!empty($g['vendor_code'])): ?><p class="muted" style="margin:2px 0 0"><?= e((string)$g['vendor_code']) ?></p><?php endif; ?>
          </div>
          <button type="button" class="copy-report" title="Copy as text" aria-label="Copy as text">
            <?= Icons::get('copy', 18) ?>
          </button>
        </div>
        <pre class="report-copy-src" hidden><?= e($copy) ?></pre>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>SKU</th>
                <th>ProductID</th>
                <th>Product Name</th>
                <th>Desired Goal Stock level - existing stock level</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($g['lines'] as $line):
                $hay = strtolower(trim(($line['sku'] ?? '') . ' ' . ($line['product_id'] ?? '') . ' ' . ($line['title'] ?? '')));
              ?>
              <tr data-search="<?= e($hay) ?>"
                  data-sku="<?= e((string)$line['sku']) ?>"
                  data-pid="<?= e((string)$line['product_id']) ?>"
                  data-title="<?= e((string)$line['title']) ?>"
                  data-need="<?= (int)$line['need_qty'] ?>"
                  data-goal="<?= (int)$line['goal_qty'] ?>"
                  data-stock="<?= (int)$line['stock'] ?>">
                <td><?= e((string)$line['sku'] !== '' ? (string)$line['sku'] : '—') ?></td>
                <td><?= e((string)$line['product_id']) ?></td>
                <td><?= e((string)$line['title']) ?></td>
                <td class="need-qty"><?= (int)$line['need_qty'] ?>
                  <span class="muted">(<?= (int)$line['goal_qty'] ?> − <?= (int)$line['stock'] ?>)</span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>
      <?php endforeach; ?>
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
      var vendorOk = !vn || name.indexOf(vn) !== -1;
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
    });
    if (info) {
      info.textContent = (vn || q) ? (shown + ' vendor' + (shown===1?'':'s') + ' · ' + lines + ' item' + (lines===1?'':'s')) : '';
    }
    if (empty) empty.hidden = shown > 0;
  }

  function reportText(card){
    var titleEl = card.querySelector('h2');
    var vendor = titleEl ? titleEl.textContent.trim() : 'Vendor';
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
    var header = 'Restock request — ' + vendor + (code ? ' (' + code + ')' : '');
    var n = items.length;
    var out = [
      header,
      'Date: ' + new Date().toLocaleDateString(undefined, {day:'numeric', month:'long', year:'numeric'}),
      n + ' item' + (n===1?'':'s') + ' · ' + total + ' unit' + (total===1?'':'s') + ' to order',
      '================================================',
      '',
      'Hello,',
      '',
      'Please supply the following so we can bring on-hand stock up to our goal levels:',
      ''
    ];
    items.forEach(function(it, i){
      out.push((i+1) + '. ' + it.title);
      out.push('   SKU: ' + (it.sku !== '' ? it.sku : '—'));
      out.push('   Product ID: ' + it.pid);
      out.push('   Quantity to order: ' + it.need + '  (goal ' + it.goal + ' − current ' + it.stock + ')');
      out.push('');
    });
    out.push('Thank you.');
    return out.join('\n') + '\n';
  }

  if (vendorInp) vendorInp.addEventListener('input', apply);
  if (kwInp) kwInp.addEventListener('input', apply);

  document.querySelectorAll('.copy-report').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var card = btn.closest('.restock-report');
      if (!card) return;
      var text = reportText(card);
      try {
        var copied = false;
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
        if (!copied) throw new Error('copy failed');
        btn.classList.add('is-copied');
        btn.setAttribute('title', 'Copied');
        if (window.hd && hd.toast) hd.toast('Copied restock request');
        setTimeout(function(){
          btn.classList.remove('is-copied');
          btn.setAttribute('title', 'Copy as text');
        }, 1600);
      } catch (e) {
        if (window.hd && hd.toast) hd.toast('Could not copy', 'error');
      }
    });
  });
})();
JS);
endif; ?>

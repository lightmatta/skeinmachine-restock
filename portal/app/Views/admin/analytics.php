<?php use App\Icons; /** @var string $active */ ?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('chart', 22) ?> Analytics</h1>
      <p class="muted">Ask in plain English. A native rule-based engine (no external AI) builds the query, table &amp; chart.</p></div></div>

    <div class="card">
      <div class="toolbar" style="margin:0">
        <?= Icons::get('sparkle', 18) ?>
        <input type="text" id="nlq" placeholder="e.g. top products last 90 days · client order frequency this financial year · revenue this month" style="flex:1;min-width:280px">
        <button class="btn btn-primary" id="askBtn">Ask</button>
      </div>
      <div class="tag-list" style="margin-top:12px">
        <?php foreach ([
          'Show top products last 90 days',
          'Most popular bundles this year',
          'Client order frequency this financial year',
          'Revenue this month',
          'How many orders last 7 days',
        ] as $ex): ?>
          <span class="col-toggle example" style="cursor:pointer"><?= e($ex) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div id="interpretation" class="flash ok hidden" style="margin-top:16px"></div>
    <div id="chartWrap" class="card hidden" style="margin-top:16px"></div>
    <div id="tableWrap" class="table-wrap hidden" style="margin-top:16px"></div>
  </div>
</div>

<?php page_script(<<<'JS'
(function(){
  var input = document.getElementById('nlq');

  function renderChart(chart){
    var wrap = document.getElementById('chartWrap');
    if (!chart || !chart.values || !chart.values.length){ wrap.classList.add('hidden'); return; }
    wrap.classList.remove('hidden');
    var W=Math.max(360, chart.values.length*70), H=240, pad=34;
    var max=Math.max.apply(null, chart.values.concat([1]));
    var bw=(W-pad*2)/chart.values.length*0.62, gap=(W-pad*2)/chart.values.length;
    var hl = getComputedStyle(document.documentElement).getPropertyValue('--hl').trim() || '#FF6F61';
    var bars='', labels='';
    chart.values.forEach(function(v,i){
      var h=(H-pad*2)*(v/max);
      var x=pad+i*gap+(gap-bw)/2, y=H-pad-h;
      bars += '<rect x="'+x+'" y="'+y+'" width="'+bw+'" height="'+h+'" rx="4" fill="'+hl+'"></rect>';
      bars += '<text x="'+(x+bw/2)+'" y="'+(y-5)+'" text-anchor="middle" font-size="11" fill="#141414">'+v+'</text>';
      var lab=(chart.labels[i]||'').toString(); if(lab.length>10) lab=lab.slice(0,9)+'…';
      labels += '<text x="'+(x+bw/2)+'" y="'+(H-pad+16)+'" text-anchor="middle" font-size="10" fill="#5f6368">'+hd.escape(lab)+'</text>';
    });
    wrap.innerHTML = '<strong>'+hd.escape(chart.title||'')+'</strong>'+
      '<svg viewBox="0 0 '+W+' '+H+'" width="100%" style="max-width:'+W+'px;margin-top:8px">'+
      '<line x1="'+pad+'" y1="'+(H-pad)+'" x2="'+(W-pad)+'" y2="'+(H-pad)+'" stroke="#e6e6e6"></line>'+bars+labels+'</svg>';
  }

  function renderTable(cols, rows){
    var wrap = document.getElementById('tableWrap');
    if (!rows || !rows.length){ wrap.innerHTML='<div class="empty">No data in this range.</div>'; wrap.classList.remove('hidden'); return; }
    wrap.classList.remove('hidden');
    var keys = Object.keys(cols);
    var html = '<table class="grid"><thead><tr>';
    keys.forEach(function(k){ html += '<th>'+hd.escape(cols[k])+'</th>'; });
    html += '</tr></thead><tbody>';
    rows.forEach(function(r){
      html += '<tr>';
      keys.forEach(function(k){ html += '<td>'+hd.escape(r[k]==null?'':r[k])+'</td>'; });
      html += '</tr>';
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
  }

  async function ask(){
    var q = input.value.trim();
    if (!q) return;
    var data = await hd.post('admin/api', {entity:'analytics', op:'query', q:q});
    var interp = document.getElementById('interpretation');
    interp.classList.remove('hidden');
    interp.textContent = data.interpretation + ' — ' + (data.summary||'');
    renderChart(data.chart);
    renderTable(data.columns||{}, data.rows||[]);
  }
  document.getElementById('askBtn').addEventListener('click', ask);
  input.addEventListener('keydown', function(e){ if(e.key==='Enter') ask(); });
  document.querySelectorAll('.example').forEach(function(x){
    x.addEventListener('click', function(){ input.value = x.textContent; ask(); });
  });
})();
JS); ?>

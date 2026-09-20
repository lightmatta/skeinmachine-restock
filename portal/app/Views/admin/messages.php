<?php use App\Icons; /** @var string $active */ ?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('message', 22) ?> Messages</h1>
      <p class="muted">Threads needing a reply are marked and sorted to the top. Send a new message to any user in the system.</p></div></div>

    <div class="card" style="margin-bottom:16px">
      <h2>New message</h2>
      <div class="compose-row">
        <label class="field" style="margin:0;flex:1;min-width:180px"><span>Recipient</span>
          <select id="composeTo"><option value="">Choose a user…</option></select></label>
        <label class="field" style="margin:0;flex:2;min-width:220px"><span>Message</span>
          <input type="text" id="composeBody" placeholder="Write a message…"></label>
        <button type="button" class="btn btn-primary" id="composeSend"><?= Icons::get('send', 16) ?> Send</button>
      </div>
    </div>

    <div class="split" style="align-items:start">
      <div id="threadList"></div>
      <div>
        <div class="card" id="conversation" style="min-height:420px;display:flex;flex-direction:column">
          <div class="empty" id="convEmpty">Select a conversation.</div>
          <div class="chat-log" id="convLog" style="display:none;flex:1;background:#fff;border:none"></div>
          <div class="chat-input" id="convInput" style="display:none;border-top:1px solid var(--line)">
            <input type="text" id="replyBox" placeholder="Type a reply…">
            <button class="btn btn-primary btn-sm" id="replySend"><?= Icons::get('send', 16) ?></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php page_script(<<<'JS'
(function(){
  var current = null;
  var listEl = document.getElementById('threadList');
  var logEl = document.getElementById('convLog');
  var emptyEl = document.getElementById('convEmpty');
  var inputEl = document.getElementById('convInput');

  async function loadThreads(){
    var data = await hd.post('admin/api', {entity:'messages', op:'threads'});
    listEl.innerHTML = '';
    if (!data.threads || !data.threads.length){ listEl.innerHTML = '<p class="muted">No conversations yet.</p>'; return; }
    data.threads.forEach(function(t){
      var name = ((t.first_name||'')+' '+(t.last_name||'')).trim() || t.email;
      var tile = document.createElement('div');
      tile.className = 'list-tile' + (current===t.id ? ' active':'');
      tile.innerHTML = '<strong>'+hd.escape(name)+'</strong>' + (t.unread>0 ? ' <span class="badge hl">'+t.unread+' new</span>':'') +
        '<div class="muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+hd.escape(t.last_body||'')+'</div>';
      tile.addEventListener('click', function(){ openThread(t.id, name); });
      listEl.appendChild(tile);
    });
  }

  async function openThread(id, name){
    current = id;
    emptyEl.style.display='none'; logEl.style.display='flex'; inputEl.style.display='flex';
    var data = await hd.post('admin/api', {entity:'messages', op:'thread', id:id});
    logEl.innerHTML = '';
    (data.messages||[]).forEach(function(m){
      var div = document.createElement('div');
      div.className = 'msg ' + (m.sender==='admin'?'client':(m.sender==='system'?'system':'admin'));
      div.innerHTML = hd.escape(m.body) + '<span class="meta">'+hd.escape(m.sender)+' · '+hd.escape(m.time)+'</span>';
      logEl.appendChild(div);
    });
    logEl.scrollTop = logEl.scrollHeight;
    loadThreads();
  }

  async function send(){
    var box = document.getElementById('replyBox');
    var text = box.value.trim();
    if (!text || !current) return;
    box.value='';
    await hd.post('admin/api', {entity:'messages', op:'reply', id:current, body:text});
    openThread(current);
  }
  document.getElementById('replySend').addEventListener('click', send);
  document.getElementById('replyBox').addEventListener('keydown', function(e){ if(e.key==='Enter') send(); });

  async function loadRecipients(){
    var data = await hd.post('admin/api', {entity:'messages', op:'recipients'});
    var sel = document.getElementById('composeTo');
    if (!sel) return;
    sel.innerHTML = '<option value="">Choose a user…</option>';
    (data.recipients||[]).forEach(function(u){
      var opt = document.createElement('option');
      opt.value = u.id;
      opt.textContent = u.name + ' (' + u.role + ')';
      sel.appendChild(opt);
    });
  }
  var composeSend = document.getElementById('composeSend');
  if (composeSend) composeSend.addEventListener('click', async function(){
    var uid = parseInt(document.getElementById('composeTo').value||'0',10);
    var box = document.getElementById('composeBody');
    var text = box.value.trim();
    if (!uid || !text) { hd.toast('Choose a recipient and write a message', 'error'); return; }
    var r = await hd.post('admin/api', {entity:'messages', op:'compose', id:uid, body:text});
    if (r.error) { hd.toast(r.message || 'Could not send', 'error'); return; }
    box.value = '';
    hd.toast('Message sent');
    openThread(uid);
  });
  var composeBody = document.getElementById('composeBody');
  if (composeBody) composeBody.addEventListener('keydown', function(e){
    if (e.key==='Enter') document.getElementById('composeSend').click();
  });

  loadThreads();
  loadRecipients();
  setInterval(function(){ if(current) openThread(current); else loadThreads(); }, 6000);
})();
JS); ?>

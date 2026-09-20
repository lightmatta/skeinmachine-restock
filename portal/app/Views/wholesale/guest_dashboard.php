<?php use App\Icons; ?>
<div class="container" style="max-width:680px">
  <div class="page-head"><div><h1><?= Icons::get('clock', 22) ?> Application under review</h1></div></div>
  <div class="card">
    <p>Thanks for applying for a wholesale account. Your application is <span class="badge pending">pending review</span>.</p>
    <p class="muted">Once an administrator approves your account, you'll unlock wholesale pricing, stock availability, ordering and your order dashboard. You can already browse our public catalog and message our team using the chat button.</p>
    <a class="btn" href="<?= e(url('home')) ?>"><?= Icons::get('store', 18) ?> Browse public catalog</a>
    <a class="btn btn-ghost" href="<?= e(url('account')) ?>"><?= Icons::get('user', 18) ?> Update my details</a>
  </div>
</div>

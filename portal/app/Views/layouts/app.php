<?php
/** @var string $content */
use App\Auth;
use App\Settings;
use App\Icons;
use App\View;

$company = Settings::get('company_name', 'HouseDye');
$hl = Settings::get('highlight_color', '#FF6F61');
$user = Auth::user();
$title = $title ?? $company;
$active = $active ?? '';
$isAdmin = Auth::isAdmin();
$isStaff = Auth::isStaff();
$chatRole = !$user ? '' : ($isAdmin ? 'admin' : ($isStaff ? 'staff' : 'client'));
$showChat = $chatRole !== '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e($hl) ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon.svg">
<link rel="manifest" href="assets/manifest.webmanifest">
<title><?= e($title) ?> · <?= e($company) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= (int)@filemtime(APP_ROOT . '/public/assets/app.css') ?>">
<style>:root{--hl: <?= e($hl) ?>; --hl-soft: <?= e($hl) ?>1f;}</style>
</head>
<body>
<?= View::capture('layouts/_header') ?>

<?= $content ?>

<?php if ($showChat): ?>
<button class="chat-fab" id="chatFab" aria-label="Open chat"><?= Icons::get('message', 22) ?></button>
<div class="chat-panel" id="chatPanel" data-role="<?= e($chatRole) ?>">
  <div class="chat-head">
    <?= Icons::get('message', 18) ?>
    <span class="chat-title"><?= $chatRole === 'admin' ? 'Customer chats' : ($chatRole === 'staff' ? 'Team chat' : 'Support chat') ?></span>
    <span class="presence"></span>
    <?php if ($chatRole === 'admin'): ?>
      <button class="chat-icon-btn chat-expand" title="Toggle full screen"><?= Icons::get('expand', 16) ?></button>
    <?php endif; ?>
    <button class="chat-icon-btn chat-close" title="Close"><?= Icons::get('x', 16) ?></button>
  </div>
  <div class="chat-shell">
    <?php if ($chatRole === 'admin'): ?><div class="chat-tabs" id="chatTabs"></div><?php endif; ?>
    <div class="chat-body">
      <?php if ($chatRole === 'staff'): ?>
        <div class="chat-to">
          <label>To
            <select id="chatTo" aria-label="Message recipient">
              <option value="0">All admins</option>
            </select>
          </label>
        </div>
      <?php endif; ?>
      <div class="chat-log"></div>
      <div class="chat-input">
        <input type="text" placeholder="Type a message…" aria-label="Message">
        <button class="btn btn-primary btn-sm chat-send"><?= Icons::get('send', 16) ?></button>
      </div>
    </div>
  </div>
</div>
<div class="chat-alert" id="chatAlert" hidden>
  <strong class="chat-alert-title">New message</strong>
  <span class="chat-alert-body"></span>
  <span class="chat-alert-cta">Click the chat box to read it</span>
</div>
<div class="notify-prompt" id="notifyPrompt" hidden>
  <strong class="notify-prompt-title">Enable phone notifications</strong>
  <span class="notify-prompt-body">Allow system notifications so new messages and alerts appear in your notification shade.</span>
  <div class="notify-prompt-actions">
    <button type="button" class="btn btn-primary btn-sm" id="notifyAllow">Allow</button>
    <button type="button" class="btn btn-ghost btn-sm" id="notifyDismiss">Not now</button>
  </div>
</div>
<?php endif; ?>

<script>
window.__CSRF__ = <?= json_encode(csrf_token()) ?>;
window.__CURRENCY__ = <?= json_encode(\App\Currency::company()) ?>;
window.__CHAT_ROLE__ = <?= json_encode($chatRole) ?>;
window.__USER_ID__ = <?= (int)Auth::id() ?>;
window.__NOTIFY_WANTED__ = <?= json_encode(
    $showChat && (Settings::imBrowserNotifications() || ($isAdmin && Settings::adminEventAlerts()))
) ?>;
window.__ICONS__ = {
  archive: <?= json_encode(Icons::get('archive', 15)) ?>,
  trash: <?= json_encode(Icons::get('trash', 15)) ?>,
  columns: <?= json_encode(Icons::get('columns', 16)) ?>,
  bundle: <?= json_encode(Icons::get('bundle', 15)) ?>,
  refresh: <?= json_encode(Icons::get('refresh', 15)) ?>
};
</script>
<script src="assets/app.js?v=<?= (int)@filemtime(APP_ROOT . '/public/assets/app.js') ?>"></script>
<?php $__ps = rendered_scripts(); if ($__ps !== ''): ?><script><?= $__ps ?></script><?php endif; ?>
</body>
</html>

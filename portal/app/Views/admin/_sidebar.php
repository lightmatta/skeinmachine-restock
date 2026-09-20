<?php
use App\Auth;
use App\Database;
use App\Icons;
/** @var string $active */
$pdo = Database::pdo();
$isAdmin = Auth::isAdmin();
$badgeMsgs = $isAdmin ? (int)$pdo->query("SELECT COUNT(DISTINCT thread_user_id) FROM messages WHERE sender IN ('client','staff') AND read_by_admin=0 AND (to_user_id IS NULL OR to_user_id IN (SELECT id FROM users WHERE role='admin'))")->fetchColumn() : 0;
$links = [
    ['dashboard', 'admin', 'grid', 'Dashboard', 0],
];
if ($isAdmin) {
    $links[] = ['users', 'admin/users', 'users', 'Users', 0];
    $links[] = ['messages', 'admin/messages', 'message', 'Messages', $badgeMsgs];
}
$links[] = ['products', 'admin/products', 'box', 'Products', 0];
$links[] = ['sources', 'admin/sources', 'store', 'Sources', 0];
if ($isAdmin) {
    $links[] = ['settings', 'admin/settings', 'settings', 'Settings', 0];
}
?>
<aside class="sidebar">
  <?php foreach ($links as $link):
    [$key, $route, $icon, $label, $badge] = $link;
  ?>
    <a class="side-link<?= $active === $key ? ' active' : '' ?>" href="<?= e(url($route)) ?>">
      <?= Icons::get($icon, 18) ?> <span><?= e($label) ?></span>
      <?php if ($badge > 0): ?><span class="badge hl"><?= $badge ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</aside>

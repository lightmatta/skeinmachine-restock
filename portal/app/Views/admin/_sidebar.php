<?php
use App\Auth;
use App\Database;
use App\Icons;
use App\RestockOrders;
/** @var string $active */
$pdo = Database::pdo();
$isAdmin = Auth::isAdmin();
$badgeApps = $isAdmin ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn() : 0;
$badgeMsgs = $isAdmin ? (int)$pdo->query("SELECT COUNT(DISTINCT thread_user_id) FROM messages WHERE sender IN ('client','staff') AND read_by_admin=0 AND (to_user_id IS NULL OR to_user_id IN (SELECT id FROM users WHERE role='admin'))")->fetchColumn() : 0;
$badgeRestock = RestockOrders::stats()['below_min'];
$links = [
    ['dashboard', 'admin', 'grid', 'Dashboard', 0],
];
$links[] = ['vendors', 'admin/vendors', 'truck', 'Vendors', 0];
$links[] = ['vendor-products', 'admin/vendor-products', 'store', 'Vendor products', 0];
if ($isAdmin) {
    $links[] = ['users', 'admin/users', 'users', 'Users', $badgeApps];
    $links[] = ['messages', 'admin/messages', 'message', 'Messages', $badgeMsgs];
}
$links[] = ['work-orders', 'admin/work-orders', 'clipboard', 'Restock orders', $badgeRestock, null];
$links[] = ['work-orders-schedule', 'admin/work-orders/schedule', 'clock', 'Schedule', 0, 'work-orders'];
$links[] = ['products', 'admin/products', 'box', 'Products', 0, null];
$links[] = ['bundles', 'admin/bundles', 'bundle', 'Bundles', 0];
if ($isAdmin) {
    $links[] = ['settings', 'admin/settings', 'settings', 'Settings', 0];
}
?>
<aside class="sidebar">
  <?php foreach ($links as $link):
    [$key, $route, $icon, $label, $badge] = $link;
    $parent = $link[5] ?? null;
    $isSub = $parent !== null;
    $on = $active === $key || ($key === 'work-orders' && $active === 'work-orders-schedule');
  ?>
    <a class="side-link<?= $isSub ? ' side-sub' : '' ?><?= $on ? ' active' : '' ?>" href="<?= e(url($route)) ?>">
      <?= Icons::get($icon, 18) ?> <span><?= e($label) ?></span>
      <?php if ($badge > 0): ?><span class="badge hl"><?= $badge ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</aside>

<?php
/**
 * Universal portal header.
 *
 * Brand, centred Home link, and account controls are identical on the restock
 * portal, the admin back office, and error pages.
 */

use App\Auth;
use App\Icons;
use App\Settings;

$company = Settings::get('company_name', 'HouseDye');
$logo = Settings::get('logo_url', '');
$user = Auth::user();
$isAdmin = Auth::isAdmin();
$isStaff = Auth::isStaff();

$route = (string)($_GET['r'] ?? 'home');

$navItems = [
    ['Home', url('home'), $route === 'home'],
];

$accountLinks = [];
if ($isStaff) {
    $accountLinks[] = ['admin', 'grid', 'Dashboard'];
    $accountLinks[] = ['admin/work-orders', 'clipboard', 'Restock orders'];
    $accountLinks[] = ['admin/work-orders/schedule', 'clock', 'Schedule'];
}
if ($isAdmin) {
    $accountLinks[] = ['admin', 'grid', 'Admin dashboard'];
}

$roleLabels = [
    'superuser' => 'Superuser',
    'admin'     => 'Administrator',
    'staff'     => 'Staff',
];
$roleLabel = $roleLabels[$user['role'] ?? ''] ?? 'Account';
?>
<header class="topbar">
  <div class="topbar-inner">
  <a class="brand" href="<?= e(url('home')) ?>">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($company) ?>"><?php else: ?>
      <span class="logo"><?= Icons::get('yarn', 18) ?></span>
    <?php endif; ?>
    <span class="brand-name"><?= e($company) ?></span>
  </a>

  <nav class="nav-center" aria-label="Main menu">
    <div class="nav-cluster">
    <?php foreach ($navItems as [$label, $href, $current]): ?>
      <a class="nav-item<?= $current ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    </div>
  </nav>

  <div class="nav-right">
    <?php if (!$user): ?>
      <a class="btn btn-primary nav-login" href="<?= e(url('login')) ?>"><?= Icons::get('login', 17) ?> Login</a>
    <?php else: ?>
      <div class="usermenu" id="userMenu">
        <button class="usermenu-btn" id="userMenuBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="userMenuDrop">
          <span class="usermenu-avatar"><?= Icons::get('user', 16) ?></span>
          <span class="usermenu-name"><?= e($user['display_name']) ?></span>
          <?= Icons::get('chevron', 15, 'icon usermenu-caret') ?>
        </button>
        <div class="usermenu-drop" id="userMenuDrop" role="menu" aria-labelledby="userMenuBtn">
          <div class="usermenu-head">
            <span class="usermenu-head-name"><?= e($user['display_name']) ?></span>
            <span class="usermenu-head-role"><?= e($roleLabel) ?></span>
          </div>
          <?php if ($accountLinks): ?>
            <div class="usermenu-group">
              <?php foreach ($accountLinks as [$linkRoute, $icon, $label]): ?>
                <a role="menuitem" href="<?= e(url($linkRoute)) ?>"><?= Icons::get($icon, 16) ?> <?= e($label) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="usermenu-group">
            <a role="menuitem" href="<?= e(url('account')) ?>"><?= Icons::get('settings', 16) ?> User settings</a>
            <a role="menuitem" class="danger" href="<?= e(url('logout')) ?>"><?= Icons::get('logout', 16) ?> Logout</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  </div>
</header>

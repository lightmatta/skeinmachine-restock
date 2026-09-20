<?php
/**
 * Universal portal header.
 *
 * Every layout renders this partial, so the brand, the centred main menu and
 * the account controls are identical on the public CMS, the wholesale portal,
 * the admin back office and error pages.
 */

use App\Auth;
use App\Icons;
use App\Settings;

$company = Settings::get('company_name', 'HouseDye');
$logo = Settings::get('logo_url', '');
$user = Auth::user();
$isAdmin = Auth::isAdmin();
$isWholesale = Auth::isWholesale();
$isStaff = Auth::isStaff();
$canOrder = Auth::canOrder();
$signupEnabled = Settings::get('wholesale_signup_enabled', '1') === '1';

$route = (string)($_GET['r'] ?? 'home');
$page = (string)($_GET['p'] ?? '');

// label, href, is-current
$navItems = [
    ['Home', url('home'), $route === 'home' || $route === 'catalog' || $route === 'product' || $route === 'bundle'],
    ['About', url('page', ['p' => 'about']), $route === 'page' && $page === 'about'],
    ['Contact', url('page', ['p' => 'contact']), $route === 'page' && $page === 'contact'],
];

$cartHref = $canOrder ? url('cart') : url('login');
$cartCount = $canOrder ? cart_count() : 0;

// Everything an authenticated user can reach lives in the account menu, so the
// right-hand side of the header stays a single control. route, icon, label
$accountLinks = [];
if ($isWholesale) {
    $accountLinks[] = ['dashboard', 'grid', 'Dashboard'];
    $accountLinks[] = ['catalog', 'store', 'Wholesale catalog'];
    $accountLinks[] = ['cart', 'cart', 'Cart'];
    $accountLinks[] = ['orders', 'orders', 'My orders'];
} elseif ($isStaff) {
    $accountLinks[] = ['admin', 'grid', 'Dashboard'];
    $accountLinks[] = ['admin/work-orders', 'clipboard', 'Restock orders'];
    $accountLinks[] = ['admin/work-orders/schedule', 'clock', 'Schedule'];
} elseif ($user && !$isAdmin) {
    $accountLinks[] = ['dashboard', 'user', 'My account'];
}
if ($isAdmin) {
    $accountLinks[] = ['admin', 'grid', 'Admin dashboard'];
    if ($canOrder) {
        $accountLinks[] = ['cart', 'cart', 'Cart'];
        $accountLinks[] = ['orders', 'orders', 'My orders'];
    }
}

$roleLabels = [
    'superuser' => 'Superuser',
    'admin'     => 'Administrator',
    'staff'     => 'Staff',
    'wholesale' => 'Wholesale client',
    'guest'     => 'Application pending',
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
    <?php if (!$user && $signupEnabled): ?>
      <a class="btn btn-sm btn-hl nav-cta" href="<?= e(url('apply')) ?>"><?= Icons::get('store', 16) ?> Apply for a wholesale account</a>
    <?php endif; ?>
    </div>
  </nav>

  <div class="nav-right">
    <a class="nav-cart" id="navCart" href="<?= e($cartHref) ?>" aria-label="Shopping cart">
      <?= Icons::get('cart', 20) ?>
      <span class="nav-cart-count<?= $cartCount > 0 ? '' : ' is-empty' ?>" id="navCartCount"<?= $cartCount > 0 ? '' : ' hidden' ?>><?= (int)$cartCount ?></span>
    </a>
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

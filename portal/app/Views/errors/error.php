<?php
use App\Auth;
use App\Icons;
/** @var int $status @var string $message */
$headings = [
    403 => 'Access denied',
    404 => 'Page not found',
    500 => 'Something went wrong',
];
$heading = $headings[$status] ?? 'Error';
?>
<div class="container" style="max-width:560px">
  <div class="page-head">
    <div>
      <h1><?= (int)$status ?> · <?= e($heading) ?></h1>
      <p class="muted"><?= e($message) ?></p>
    </div>
  </div>
  <div class="card" style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn" href="<?= e(url('home')) ?>"><?= Icons::get('store', 18) ?> Back to home</a>
    <?php if (!Auth::check()): ?>
      <a class="btn btn-ghost" href="<?= e(url('login')) ?>"><?= Icons::get('login', 18) ?> Sign in</a>
    <?php elseif (Auth::isAdmin()): ?>
      <a class="btn btn-ghost" href="<?= e(url('admin')) ?>"><?= Icons::get('grid', 18) ?> Admin dashboard</a>
    <?php else: ?>
      <a class="btn btn-ghost" href="<?= e(url('dashboard')) ?>"><?= Icons::get('grid', 18) ?> My dashboard</a>
    <?php endif; ?>
  </div>
</div>

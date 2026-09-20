<?php use App\Icons; /** @var ?string $error */ ?>
<div class="container" style="max-width:440px">
  <div class="page-head"><div><h1><?= Icons::get('login', 22) ?> Sign in</h1>
    <p class="muted">Wholesale clients, admins, and the superuser sign in here.</p></div></div>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form class="card" method="post" action="<?= e(url('login.submit')) ?>">
    <?= csrf_field() ?>
    <label class="field"><span>Username or email</span><input name="identifier" autofocus required></label>
    <label class="field"><span>Password</span><input type="password" name="password" required></label>
    <button class="btn btn-primary" type="submit" style="width:100%"><?= Icons::get('login', 18) ?> Sign in</button>
  </form>
  <p class="help" style="text-align:center;margin-top:14px">
    New wholesaler? <a href="<?= e(url('apply')) ?>">Apply here</a>.
  </p>
</div>

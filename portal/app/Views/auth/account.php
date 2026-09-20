<?php
use App\Auth;
use App\Icons;
/** @var array $user @var ?array $dbUser @var bool $saved @var ?string $error */
$d = $dbUser ?? [];
?>
<div class="container" style="max-width:720px">
  <div class="page-head"><div><h1><?= Icons::get('user', 22) ?> My account</h1>
    <p class="muted"><?= e($user['email']) ?> · role: <strong><?= e($user['role']) ?></strong></p></div></div>

  <?php if ($saved): ?><div class="flash ok">Your account was updated.</div><?php endif; ?>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

  <?php if (Auth::id() === 0): ?>
    <div class="card"><p>The overarching superuser profile is defined in <code>config.php</code> on the server and cannot be edited from the web interface for security reasons.</p></div>
  <?php else: ?>
  <form class="card" method="post" action="<?= e(url('account.save')) ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span>First name</span><input name="first_name" value="<?= e($d['first_name'] ?? '') ?>"></label>
      <label class="field"><span>Last name</span><input name="last_name" value="<?= e($d['last_name'] ?? '') ?>"></label>
      <label class="field"><span>Finance officer first name</span><input name="fin_first_name" value="<?= e($d['fin_first_name'] ?? '') ?>"></label>
      <label class="field"><span>Finance officer last name</span><input name="fin_last_name" value="<?= e($d['fin_last_name'] ?? '') ?>"></label>
      <label class="field"><span>Phone</span><input name="phone" value="<?= e($d['phone'] ?? '') ?>"></label>
      <label class="field"><span>Company website</span><input name="company_website" value="<?= e($d['company_website'] ?? '') ?>"></label>
    </div>
    <label class="field"><span>Office address</span><textarea name="office_address" rows="2"><?= e($d['office_address'] ?? '') ?></textarea></label>
    <label class="field"><span>Delivery address</span><textarea name="delivery_address" rows="2"><?= e($d['delivery_address'] ?? '') ?></textarea></label>
    <?php if (\App\Auth::isWholesale()):
      $home = \App\Settings::currency();
      $pref = strtoupper(trim((string)($d['preferred_currency'] ?? '')));
    ?>
    <label class="field"><span>Display currency</span>
      <select name="preferred_currency">
        <option value="" <?= $pref === '' ? 'selected' : '' ?>>Same as <?= e($home) ?> (transaction currency)</option>
        <?php foreach (\App\Currency::codes() as $code => $name): ?>
          <option value="<?= e($code) ?>" <?= $pref === $code ? 'selected' : '' ?>><?= e($code) ?> — <?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <p class="help">Orders are billed in <strong><?= e($home) ?></strong>. If you choose a different display currency, we show an estimated conversion on totals as a convenience. Exchange rates may vary at the time of payment.</p>
    <?php endif; ?>
    <label class="field"><span>New password (leave blank to keep current)</span><input type="password" name="password" minlength="8"></label>
    <button class="btn btn-primary" type="submit"><?= Icons::get('check', 18) ?> Save changes</button>
  </form>
  <?php endif; ?>
</div>

<?php
use App\Icons;
/** @var string $preface @var ?string $error */
?>
<div class="container">
  <div class="page-head"><div><h1><?= Icons::get('store', 22) ?> Wholesale application</h1>
    <p class="muted">Create a guest account now; a team member will review and activate wholesale access.</p></div></div>

  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($preface): ?><div class="card" style="margin-bottom:18px"><?= e($preface) ?></div><?php endif; ?>

  <form class="card" method="post" action="<?= e(url('apply.submit')) ?>">
    <?= csrf_field() ?>
    <h2>Account holder</h2>
    <div class="form-grid">
      <label class="field"><span>First name *</span><input name="first_name" required></label>
      <label class="field"><span>Last name *</span><input name="last_name" required></label>
    </div>
    <h2>Financial officer</h2>
    <div class="form-grid">
      <label class="field"><span>First name</span><input name="fin_first_name"></label>
      <label class="field"><span>Last name</span><input name="fin_last_name"></label>
    </div>
    <h2>Contact &amp; company</h2>
    <div class="form-grid">
      <label class="field"><span>Email *</span><input type="email" name="email" required></label>
      <label class="field"><span>Phone *</span><input name="phone" required></label>
      <label class="field"><span>Company website</span><input name="company_website" placeholder="https://"></label>
      <label class="field"><span>Set a password *</span><input type="password" name="password" minlength="8" required></label>
    </div>
    <label class="field"><span>Office address * (incl. country)</span><textarea name="office_address" rows="2" required></textarea></label>
    <label class="field"><span>Delivery address * (incl. country)</span><textarea name="delivery_address" rows="2" required></textarea></label>
    <label class="field"><span>Message to our team</span><textarea name="application_message" rows="4" placeholder="Tell us about your shop…"></textarea></label>
    <button class="btn btn-primary" type="submit"><?= Icons::get('check', 18) ?> Submit application</button>
    <a class="btn btn-ghost" href="<?= e(url('login')) ?>">I already have an account</a>
  </form>
</div>

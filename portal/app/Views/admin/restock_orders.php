<?php
use App\Icons;
/** @var array $groups @var bool $isAdmin @var string $active */
?>
<div class="shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content">
    <div class="page-head"><div><h1><?= Icons::get('clipboard', 22) ?> Restock orders</h1>
      <p class="muted">Per-vendor list of catalog items at or below their Min quantity. The top of each vendor shows items the supplier can fill (vendor stock &gt; 0). The bottom highlights items that need restock but the vendor has 0 available. Goal is used to recommend how many to order.</p></div>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/work-orders/schedule')) ?>"><?= Icons::get('clock', 15) ?> Schedule</a></div>

    <?php if (!$groups): ?>
      <div class="card empty">No products are at or below their restock minimum.</div>
    <?php endif; ?>

    <?php foreach ($groups as $g): ?>
      <div class="wo-group restock-group" data-vendor="<?= (int)$g['vendor_id'] ?>">
        <div class="wo-group-head">
          <div>
            <h2><?= e((string)$g['vendor_name']) ?>
              <?php if (!empty($g['vendor_code'])): ?><span class="muted">· <?= e((string)$g['vendor_code']) ?></span><?php endif; ?>
            </h2>
            <p class="help" style="margin:4px 0 0"><?= count($g['fulfillable']) ?> can be ordered · <?= count($g['unfulfillable']) ?> cannot be fulfilled</p>
          </div>
        </div>

        <h3 class="restock-section-title">Available from vendor</h3>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Product</th>
                <th class="num">Our stock</th>
                <th class="num" title="Set minimum quantity for restock">Min</th>
                <th class="num" title="Goal Stock Level">Goal</th>
                <th class="num">Vendor stock</th>
                <th class="num">Recommend</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$g['fulfillable']): ?>
              <tr><td colspan="7" class="empty">No fulfillable restock lines for this vendor.</td></tr>
            <?php endif; ?>
            <?php foreach ($g['fulfillable'] as $line): ?>
              <tr>
                <td><?= e((string)$line['sku']) ?></td>
                <td><?= e((string)$line['title']) ?><?php if ($line['vendor_title'] !== '' && $line['vendor_title'] !== $line['title']): ?> <span class="muted">(<?= e((string)$line['vendor_title']) ?>)</span><?php endif; ?></td>
                <td class="num"><?= (int)$line['stock'] ?></td>
                <td class="num"><?= (int)$line['min_qty'] ?></td>
                <td class="num"><?= (int)$line['goal_qty'] ?></td>
                <td class="num"><?= (int)$line['vendor_stock'] ?></td>
                <td class="num"><strong><?= (int)$line['recommend_qty'] ?></strong></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <h3 class="restock-section-title restock-unavail">Cannot be fulfilled — vendor stock is 0</h3>
        <div class="table-wrap restock-unavail-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Product</th>
                <th class="num">Our stock</th>
                <th class="num" title="Set minimum quantity for restock">Min</th>
                <th class="num" title="Goal Stock Level">Goal</th>
                <th class="num">Vendor stock</th>
                <th class="num">Recommend</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$g['unfulfillable']): ?>
              <tr><td colspan="7" class="empty">Every flagged item for this vendor has supplier stock.</td></tr>
            <?php endif; ?>
            <?php foreach ($g['unfulfillable'] as $line): ?>
              <tr class="restock-unavail-row">
                <td><?= e((string)$line['sku']) ?></td>
                <td><?= e((string)$line['title']) ?></td>
                <td class="num"><?= (int)$line['stock'] ?></td>
                <td class="num"><?= (int)$line['min_qty'] ?></td>
                <td class="num"><?= (int)$line['goal_qty'] ?></td>
                <td class="num"><span class="badge stalled">0</span></td>
                <td class="num"><?= (int)$line['recommend_qty'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

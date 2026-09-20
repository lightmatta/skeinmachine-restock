<?php
use App\Icons;
use App\UserPrefs;
/** @var array $orders @var array $rows @var array $view @var string $from @var string $to @var string $active */
$payload = [
    'from' => $from,
    'to' => $to,
    'orders' => $orders,
    'rows' => $rows,
    'view' => $view ?? UserPrefs::normalizeGanttView([]),
    'prefKey' => UserPrefs::GANTT_VIEW,
    'staffRates' => $staffRates ?? [],
    'conflicts' => $conflicts ?? [],
    'isAdmin' => !empty($isAdmin),
];
?>
<div class="shell wo-schedule-shell">
  <?php require __DIR__ . '/_sidebar.php'; ?>
  <div class="content wo-schedule-content">
    <div class="wo-schedule-toolbar no-print">
      <div class="wo-schedule-title">
        <h1><?= Icons::get('clock', 22) ?> Schedule</h1>
        <p class="muted">Each customer order has its own colour and a summary bar that tracks the last scheduled day. Tick an order to show it, click the name to select the whole order for a group move, and double-click a row to collapse work or trays.</p>
      </div>
      <div class="wo-schedule-dates">
        <label>Start <input type="date" id="ganttFrom" value="<?= e($from) ?>"></label>
        <label>End <input type="date" id="ganttTo" value="<?= e($to) ?>"></label>
        <label class="gantt-override" title="Skip the resolve-conflicts offer. Over-rate work stays highlighted until the schedule is fixed.">
          <input type="checkbox" id="ganttOverrideRate"<?= !empty($view['override_rate']) ? ' checked' : '' ?>>
          Override tray-rate warnings
        </label>
        <?php if (!empty($isAdmin)): ?>
        <button type="button" class="btn btn-sm btn-primary" id="ganttAutoSchedule">Auto-schedule</button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-primary" id="ganttPrint"><?= Icons::get('print', 15) ?> Print A3</button>
        <button type="button" class="btn btn-sm" id="ganttPrintA4"><?= Icons::get('print', 15) ?> Print A4</button>
      </div>
    </div>
    <div class="wo-schedule-filters no-print" id="ganttOrders"></div>
    <div class="gantt-wrap" id="ganttWrap"></div>
  </div>
</div>
<div class="modal" id="ganttConflictModal" hidden>
  <div class="modal-card gantt-conflict-card" role="dialog" aria-labelledby="ganttConflictTitle" aria-modal="true">
    <h2 id="ganttConflictTitle"><?= Icons::get('clock', 20) ?> Scheduling conflicts</h2>
    <p id="ganttConflictText">A staff member is over their tray rate. Conflicting work stays highlighted until the schedule is under the limit.</p>
    <div class="toolbar" style="margin:0">
      <button type="button" class="btn btn-primary" id="ganttResolveConflicts">Automatically resolve scheduling conflicts just for the items that are affected by this issue</button>
      <button type="button" class="btn btn-ghost" id="ganttConflictCancel">Not now</button>
    </div>
  </div>
</div>
<script>
window.__GANTT__ = <?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/gantt.js?v=<?= (int)@filemtime(APP_ROOT . '/public/assets/gantt.js') ?>"></script>

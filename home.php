<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/proc.php';
require_once __DIR__ . '/includes/runner.php';

$user = require_login();

/* Reconcile before counting anything. Totals built from a status column that
   only moves when somebody clicks would keep counting a crashed run as live. */
runner_sync_user((int)$user['id']);

$list = db()->prepare('SELECT * FROM processes WHERE user_id = ? ORDER BY id DESC');
$list->execute([$user['id']]);
$processes = $list->fetchAll();

$running = 0;
$idle = 0;
$done = 0;
$levels = 0;
$xp = 0;
foreach ($processes as $p) {
    if (in_array($p['status'], ['running', 'starting', 'stopping'], true)) {
        $running++;
    } elseif ($p['status'] === 'done') {
        $done++;
    } else {
        $idle++;
    }
    if ((int)$p['level_current'] > 0) {
        $levels += (int)$p['level_current'] - (int)$p['level_start'];
    }
    $xp += max(0, (int)$p['xp_current'] - (int)$p['xp_start']);
}

$credits = (int)$user['credits'];

app_head('Home', 'home');
?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Overview</h2>
      <p class="lead">
        Everything you have, at a glance. Open Processes to control them.
      </p>
    </div>
    <div class="row-gap">
      <a class="btn line sm" href="redeem.php">Redeem key</a>
      <a class="btn primary sm" href="add.php">Add process</a>
    </div>
  </div>

  <!-- Eight, so the row divides evenly at every breakpoint instead of
       leaving one metric alone on a line. -->
  <div class="metrics">
    <div class="metric">
      <div class="k">Processes</div>
      <div class="v"><?= count($processes) ?></div>
    </div>
    <div class="metric">
      <div class="k">Running</div>
      <div class="v <?= $running ? 'on' : '' ?>"><?= $running ?></div>
    </div>
    <div class="metric">
      <div class="k">Idle</div>
      <div class="v"><?= $idle ?></div>
    </div>
    <div class="metric">
      <div class="k">Finished</div>
      <div class="v"><?= $done ?></div>
    </div>
    <div class="metric">
      <div class="k">Levels gained</div>
      <div class="v"><?= $levels > 0 ? '+' . $levels : '0' ?></div>
    </div>
    <div class="metric">
      <div class="k">XP gained</div>
      <div class="v <?= $xp > 0 ? 'on' : '' ?>"><?= $xp > 0 ? '+' . $xp : '0' ?></div>
    </div>
    <div class="metric">
      <div class="k">Credits</div>
      <div class="v"><?= $credits ?></div>
    </div>
    <div class="metric">
      <div class="k">Access</div>
      <div class="v txt">
        <?= $user['expires_at']
            ? e(substr((string)$user['expires_at'], 0, 10))
            : 'No expiry' ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$processes): ?>
<div class="sec">
  <div class="card">
    <div class="empty">
      <div class="ico"></div>
      <h2>Nothing here yet</h2>
      <p>
        A process holds one account and plays matches for it. Each costs
        <?= PROCESS_COST ?> credit, returned in full if you remove it.
        <?php if ($credits < PROCESS_COST): ?>
          You have no credits, so start by redeeming a key.
        <?php endif; ?>
      </p>
      <?php if ($credits < PROCESS_COST): ?>
        <a class="btn primary" href="redeem.php">Redeem a key</a>
      <?php else: ?>
        <a class="btn primary" href="add.php">Add your first process</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Most recent</h2>
      <p class="lead">The last few. The full list is on Processes.</p>
    </div>
    <a class="btn line sm" href="dashboard.php">All processes</a>
  </div>
  <div class="card">
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Account</th><th>State</th><th>Matches</th><th>XP gained</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($processes, 0, 5) as $p):
          $gain = max(0, (int)$p['xp_current'] - (int)$p['xp_start']);
          $tgt  = max(1, (int)$p['target_matches']);
        ?>
          <tr>
            <td data-label="Account"><b><?= e(proc_display_name($p)) ?></b></td>
            <td data-label="State">
              <span class="status-wrap">
                <span class="dot <?= e($p['status']) ?>"></span>
                <span class="state <?= e($p['status']) ?>"><?= e($p['status']) ?></span>
              </span>
            </td>
            <td data-label="Matches" class="mono">
              <?= (int)$p['matches_done'] ?>/<?= proc_is_unlimited($p) ? '&infin;' : $tgt ?>
            </td>
            <td data-label="XP gained" class="mono"><?= $gain > 0 ? '+' . $gain : '&mdash;' ?></td>
            <td data-label="" class="nowrap">
              <a class="btn line xs" href="process.php?id=<?= (int)$p['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php app_foot(); ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/proc.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    /* Start, stop, restart, reset, settings and remove all work straight from
       the card. Credentials stay off this surface - see proc.php. */
    $done = proc_dispatch($user, (string)($_POST['action'] ?? ''), $_POST, false);
    flash($done ?? 'Unknown action.');
    redirect('dashboard.php');
}
/* Reconcile with the machine before drawing anything. A status column that
   only ever changed when somebody clicked would still read "running" a week
   after the run crashed. */
runner_sync_user((int)$user['id']);

$list = db()->prepare('SELECT * FROM processes WHERE user_id = ? ORDER BY id DESC');
$list->execute([$user['id']]);
$processes = $list->fetchAll();

app_head('Processes', 'procs');
?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Your processes</h2>
      <p class="lead">
        Control and edit them here. Open one for its settings and its numbers.
      </p>
    </div>
    <div class="row-gap">
      <a class="btn line sm" href="redeem.php">Redeem key</a>
      <a class="btn blue sm" href="add.php">Add process</a>
    </div>
  </div>

  <?php if (!$processes): ?>
    <div class="card">
      <div class="empty">
        <div class="ico"></div>
        <h2>No processes yet</h2>
        <p>
          Each costs <?= PROCESS_COST ?> credit, returned if you remove it.
          <?php if ((int)$user['credits'] < PROCESS_COST): ?>
            You have no credits, so start by redeeming a key.
          <?php endif; ?>
        </p>
        <?php if ((int)$user['credits'] < PROCESS_COST): ?>
          <a class="btn blue" href="redeem.php">Redeem a key</a>
        <?php else: ?>
          <a class="btn blue" href="add.php">Add your first process</a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="procs" id="procs">
      <?php foreach ($processes as $p):
        $pid    = (int)$p['id'];
        $done   = (int)$p['matches_done'];
        $target = max(1, (int)$p['target_matches']);
        $pct    = percent($done, $target);
        $live   = in_array($p['status'], ['running', 'starting'], true);
        $name   = proc_display_name($p);
        $endless = proc_is_unlimited($p);
        $xpGain = max(0, (int)$p['xp_current'] - (int)$p['xp_start']);
      ?>
      <div class="proc" data-pid="<?= $pid ?>">
        <div class="proc-body">
          <div class="proc-hd">
            <div style="min-width:0">
              <a class="proc-name js-name" href="process.php?id=<?= $pid ?>"><?= e($name) ?></a>
              <p class="proc-meta">
                <span><?= $p['auth_type'] === 'guest' ? 'UID' : 'TOKEN' ?></span>
                <?php if ($endless): ?>
                  <i>&middot;</i><span>unlimited</span>
                <?php endif; ?>
                <?php if ($p['region'] !== ''): ?>
                  <i>&middot;</i><span class="js-region"><?= e($p['region']) ?></span>
                <?php endif; ?>
                <?php if ((int)$p['level_current'] > 0): ?>
                  <i>&middot;</i><span>lv <span class="js-level"><?= (int)$p['level_current'] ?></span></span>
                <?php endif; ?>
                <?php if ($xpGain > 0): ?>
                  <i>&middot;</i><span>+<span class="js-xpgain"><?= $xpGain ?></span> xp</span>
                <?php endif; ?>
              </p>
            </div>
            <span class="status-wrap">
              <span class="dot js-dot <?= e($p['status']) ?>"></span>
              <span class="state js-state <?= e($p['status']) ?>"><?= e($p['status']) ?></span>
            </span>
          </div>

          <div class="bar <?= $live ? 'live' : '' ?> js-barwrap">
            <span class="js-bar" style="width:<?= $pct ?>%"></span>
          </div>
          <div class="bar-meta">
            <span class="js-count"><b><?= $done ?></b> / <?= $endless ? '&infin;' : $target ?> matches</span>
            <span class="js-pct"><?= $endless ? '&infin;' : $pct . '%' ?></span>
          </div>
        </div>

        <div class="proc-acts">
          <?php proc_controls($p, 'xs', true); ?>
        </div>

        <details class="quick">
          <summary>Quick settings</summary>
          <div class="quick-body">
            <form method="post" class="js-quick"
                  data-live="<?= $live ? '1' : '0' ?>"
                  data-matches="<?= $target ?>"
                  data-endless="<?= $endless ? '1' : '0' ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="pid" value="<?= $pid ?>">
              <input type="hidden" name="action" value="settings">
              <input type="hidden" name="restart_now" class="js-restart" value="" disabled>

              <div class="grid2" style="margin-top:14px">
                <div class="field">
                  <label for="qt<?= $pid ?>">Games to play</label>
                  <input id="qt<?= $pid ?>" name="target_matches" type="number"
                         value="<?= $target ?>" min="1" max="500" inputmode="numeric">
                  <p class="hint">Played so far: <?= $done ?></p>
                </div>
                <div class="field">
                  <label>Stop at the target</label>
                  <div class="toggle">
                    <input id="qu<?= $pid ?>" name="unlimited" type="checkbox" class="js-unlimited"
                           <?= $endless ? 'checked' : '' ?>>
                    <label for="qu<?= $pid ?>">Unlimited</label>
                  </div>
                  <p class="hint js-ulhint">
                    <?= $endless ? 'Runs until stopped.' : 'Stops at the count.' ?>
                  </p>
                </div>
              </div>

              <button class="btn line sm" type="submit" style="margin-top:4px">
                Save settings
              </button>
              <span class="small muted" style="margin-left:8px">
                Credentials on the process page
              </span>
            </form>
          </div>
        </details>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php app_foot(); ?>

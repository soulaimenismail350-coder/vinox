<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/proc.php';

$user = require_login();
$id = (int)($_GET['id'] ?? 0);
$proc = owned_process($id, $user);
if (!$proc) {
    flash('That process does not exist.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $_POST['pid'] = $id;

    /* Credentials are editable here and only here. */
    $message = proc_dispatch($user, $action, $_POST, true);
    if ($message !== null) {
        flash($message);
        redirect($action === 'remove' ? 'dashboard.php' : 'process.php?id=' . $id);
    }
    redirect('process.php?id=' . $id);
}

$logs = db()->prepare('SELECT * FROM logs WHERE process_id = ? ORDER BY id DESC LIMIT 30');
$logs->execute([$id]);
$entries = $logs->fetchAll();

/* Reconcile, then re-read: the row on screen should describe the machine as
   it is now, not as it was when the last button was pressed. */
runner_sync($proc);
$again = db()->prepare('SELECT * FROM processes WHERE id = ?');
$again->execute([$id]);
$proc = $again->fetch() ?: $proc;

$run = null;
$osPid = 0;

$done   = (int)$proc['matches_done'];
$target = max(1, (int)$proc['target_matches']);
$pct    = percent($done, $target);
$live   = in_array($proc['status'], ['running', 'starting'], true);
$name   = proc_display_name($proc);
$endless = proc_is_unlimited($proc);
$xpGain = max(0, (int)$proc['xp_current'] - (int)$proc['xp_start']);
$lvGain = (int)$proc['level_current'] > 0
    ? (int)$proc['level_current'] - (int)$proc['level_start'] : 0;
$tokenLeft = proc_token_left($proc);

app_head($name, 'dash', ['Processes' => 'dashboard.php']);
?>

<div class="sec" data-pid="<?= $id ?>" id="live">
  <div class="sec-hd">
    <div>
      <div class="eyebrow">
        <?= $proc['auth_type'] === 'guest' ? 'Guest login' : 'Access token' ?>
        <?php if ($proc['account_id'] !== ''): ?>
          &middot; <?= e($proc['account_id']) ?>
        <?php endif; ?>
        <?php if ($proc['region'] !== ''): ?>
          &middot; <?= e($proc['region']) ?>
        <?php endif; ?>
      </div>
      <h1 class="js-name" style="margin-top:8px"><?= e($name) ?></h1>
      <p class="lead js-msg"><?= e($proc['message']) ?></p>
    </div>
    <span class="status-wrap">
      <span class="dot js-dot <?= e($proc['status']) ?>"></span>
      <span class="state js-state <?= e($proc['status']) ?>"><?= e($proc['status']) ?></span>
    </span>
  </div>

  <!-- One grid of eight rather than two ragged rows. Eight divides by 4 and
       by 2, so it fills every row at every breakpoint instead of leaving a
       single metric alone on a line. -->
  <div class="metrics" style="margin-bottom:16px">
    <div class="metric">
      <div class="k">Progress</div>
      <div class="v js-pct"><?= $endless ? '&infin;' : $pct . '%' ?></div>
    </div>
    <div class="metric">
      <div class="k">Games played</div>
      <div class="v js-count-plain"><?= $done ?></div>
    </div>
    <div class="metric">
      <div class="k">Remaining</div>
      <div class="v js-remaining"><?= $endless ? '&infin;' : max(0, $target - $done) ?></div>
    </div>
    <div class="metric">
      <div class="k">Region</div>
      <div class="v txt js-region"><?= $proc['region'] !== '' ? e($proc['region']) : '&mdash;' ?></div>
    </div>
    <div class="metric">
      <div class="k">Level now</div>
      <div class="v js-level"><?= (int)$proc['level_current'] ?: '&mdash;' ?></div>
    </div>
    <div class="metric">
      <div class="k">Levels gained</div>
      <div class="v on js-gain"><?= $lvGain > 0 ? '+' . $lvGain : ($proc['level_current'] ? '0' : '&mdash;') ?></div>
    </div>
    <div class="metric">
      <div class="k">XP before</div>
      <div class="v js-xpstart"><?= (int)$proc['xp_start'] ?: '&mdash;' ?></div>
    </div>
    <div class="metric">
      <div class="k">XP gained</div>
      <div class="v on js-xpgain"><?= $xpGain > 0 ? '+' . $xpGain : ((int)$proc['xp_current'] ? '0' : '&mdash;') ?></div>
    </div>
  </div>

  <div class="card">
    <div class="pad">
      <div class="bar <?= $live ? 'live' : '' ?> js-barwrap">
        <span class="js-bar" style="width:<?= $pct ?>%"></span>
      </div>
      <div class="bar-meta" style="margin-bottom:4px">
        <span class="js-count"><b><?= $done ?></b> / <?= $endless ? '&infin;' : $target ?> matches</span>
        <span class="dim">started at level <?= (int)$proc['level_start'] ?: '—' ?></span>
      </div>

      <div class="btns">
        <?php proc_controls($proc, '', false); ?>
      </div>
      <p class="hint" style="margin-top:10px">
        Start reads the account again, so the figures above always describe the
        run in front of you. Removing returns <?= PROCESS_COST ?> credit.
      </p>
    </div>
  </div>
</div>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Settings</h2>
      <p class="lead">
        Games to play and the login this process uses.
      </p>
    </div>
  </div>
  <div class="card">
    <div class="pad">
      <form method="post" id="settingsForm" class="js-quick"
            data-seal="uid,password,access_token"
            data-live="<?= $live ? '1' : '0' ?>"
            data-matches="<?= $target ?>"
            data-endless="<?= $endless ? '1' : '0' ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="restart_now" class="js-restart" value="" disabled>

        <div class="grid2">
          <div class="field">
            <label for="tm">Games to play</label>
            <input id="tm" name="target_matches" type="number"
                   value="<?= $target ?>" min="1" max="500" inputmode="numeric">
            <p class="hint">Played so far: <?= $done ?></p>
          </div>
          <div class="field">
            <label>Stop at the target</label>
            <div class="toggle">
              <input id="ul" name="unlimited" type="checkbox" class="js-unlimited"
                     <?= $endless ? 'checked' : '' ?>>
              <label for="ul">Unlimited matches</label>
            </div>
            <p class="hint js-ulhint">
              <?= $endless
                  ? 'Keeps playing until you press Stop or the login expires.'
                  : 'Stops on its own once the count is reached.' ?>
            </p>
          </div>
        </div>

        <?php /* No credential is written back into this page.

                 The old form put the uid straight into a value attribute and
                 the first fourteen characters of the access token into a
                 placeholder, which meant every load of this page shipped a
                 live login to the browser - into the back-forward cache, into
                 whatever proxy is in the way, and onto the screen of anyone
                 standing behind it. Both fields are now blank-means-unchanged,
                 the same rule the password already followed, and the masked
                 hint is there so you can still tell which account you are
                 looking at. */ ?>
        <?php if ($proc['auth_type'] === 'guest'): ?>
          <div class="grid2" style="margin-top:2px">
            <div class="field">
              <label for="uid">UID</label>
              <input id="uid" name="uid" type="text" placeholder="unchanged"
                     autocomplete="off" inputmode="numeric">
              <p class="hint">
                Currently <span class="mono"><?= e(mask_secret(unseal((string)$proc['uid']))) ?></span>.
                Leave blank to keep it.
              </p>
            </div>
            <div class="field">
              <label for="pw">Password</label>
              <input id="pw" name="password" type="password" placeholder="unchanged"
                     autocomplete="off">
              <p class="hint">Leave blank to keep the current one.</p>
            </div>
          </div>
        <?php else: ?>
          <div class="field" style="margin-top:2px">
            <label for="tok">Access token</label>
            <input id="tok" name="access_token" type="password" class="mono"
                   autocomplete="off" placeholder="unchanged">
            <p class="hint">
              <?= $proc['access_token'] !== '' ? 'One is stored. ' : 'None stored yet. ' ?>
              Leave blank to keep the current one.
            </p>
          </div>
        <?php endif; ?>

        <button class="btn blue" type="submit" style="margin-top:16px">
          Save settings
        </button>
        <?php if ($live): ?>
          <span class="small muted" style="margin-left:10px">
            You will be asked whether to restart
          </span>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<?php if (is_staff($user)): ?>
<div class="sec">
  <div class="card">
    <div class="card-hd">
      <h2>Activity</h2>
      <span class="tag"><?= count($entries) ?></span>
    </div>
    <div class="pad">
      <p class="lead" style="margin:0 0 12px">
        Staff view. Members never see this.
      </p>
      <?php if (!$entries): ?>
        <p class="muted small" style="margin:0">Nothing yet.</p>
      <?php else: ?>
        <div class="log">
          <?php foreach ($entries as $l): ?>
            <div class="log-row">
              <time><?= e(substr((string)$l['created_at'], 5, 11)) ?></time>
              <span><?= e($l['message']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (is_owner($user) && !runtime_bot_ready()): ?>
<div class="sec">
  <div class="note warn">
    <b>The engine is not configured</b>
    Start will refuse until it is. Fix it in
    <a href="admin_settings.php">Settings</a>.
  </div>
</div>
<?php endif; ?>

<?php app_foot(); ?>

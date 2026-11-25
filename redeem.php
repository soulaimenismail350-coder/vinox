<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $code = (string)($_POST['code'] ?? '');

    /* Both the lookup and the spending live in auth.php, shared with the
       signup form. Two copies of "is this key still usable" is how the two
       pages end up disagreeing about a code that is exactly full. */
    $key = key_lookup($code);
    if (is_string($key)) {
        $error = $key;
    } else {
        $extends = key_new_expiry($user, $key);
        $problem = key_redeem($user, $key);
        if ($problem !== null) {
            $error = $problem;
        } else {
            flash('Added ' . (int)$key['credits'] . ' credits to your balance.'
                . ($extends !== null
                    ? ' Access now runs to ' . substr($extends, 0, 16) . '.'
                    : ''));
            redirect('redeem.php');
        }
    }
}

/* Re-read: the balance and the expiry may both have moved in this same
   request, and a page that redeemed a key and then showed the old numbers
   reads as the redemption having failed. */
$fresh = db()->prepare('SELECT credits, expires_at FROM users WHERE id = ?');
$fresh->execute([$user['id']]);
$me = $fresh->fetch();
$credits = (int)$me['credits'];
$access = (string)($me['expires_at'] ?? '');

$history = db()->prepare(
    'SELECT k.code, k.credits, u.used_at
     FROM key_uses u JOIN keys k ON k.id = u.key_id
     WHERE u.user_id = ? ORDER BY u.id DESC LIMIT 20'
);
$history->execute([$user['id']]);
$rows = $history->fetchAll();

app_head('Redeem key', 'redeem', ['Processes' => 'dashboard.php']);
?>

<div class="sec">
  <div class="metrics">
    <div class="metric">
      <div class="k">Credits</div>
      <div class="v on"><?= $credits ?></div>
    </div>
    <div class="metric">
      <div class="k">Slots that buys</div>
      <div class="v"><?= intdiv($credits, max(1, PROCESS_COST)) ?></div>
    </div>
    <div class="metric">
      <div class="k">Keys redeemed</div>
      <div class="v"><?= count($rows) ?></div>
    </div>
    <div class="metric">
      <div class="k">Access until</div>
      <div class="v txt">
        <?= $access !== '' ? e(substr($access, 0, 10)) : 'No expiry' ?>
      </div>
    </div>
  </div>
</div>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Redeem a key</h2>
      <p class="lead">
        A key adds credits to your balance, and most of them add time to your
        access as well - counted from now, or added on to whatever you have
        left. One person may use a given key once, however many redemptions
        the key allows in total.
      </p>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="note warn" style="margin-bottom:16px"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="pad">
      <form method="post" data-seal="code">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="field">
          <label for="code">Key code</label>
          <input id="code" name="code" type="text" required class="mono" autofocus
                 placeholder="GGLP-XXXXX-XXXX" autocapitalize="characters"
                 autocomplete="off" spellcheck="false"
                 style="text-transform:uppercase">
          <p class="hint">Case does not matter. Codes never contain O, 0, I or 1.</p>
        </div>
        <div class="btns" style="margin-top:4px">
          <button class="btn blue" type="submit">Redeem</button>
          <a class="btn line" href="dashboard.php">Back to processes</a>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="sec">
  <div class="card">
    <div class="card-hd">
      <h2>Your redemptions</h2>
      <span class="tag"><?= count($rows) ?></span>
    </div>
    <?php if (!$rows): ?>
      <div class="pad"><p class="muted small" style="margin:0">Nothing yet.</p></div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Code</th><th>Credits</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="Code" class="mono nowrap"><?= e($r['code']) ?></td>
              <td data-label="Credits" class="mono">+<?= (int)$r['credits'] ?></td>
              <td data-label="When" class="mono dim"><?= e(substr((string)$r['used_at'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="sec">
  <div class="note info">
    <b>Where keys come from</b>
    The owner, an admin or a seller mints them. If you do not have one, ask
    whoever you got this account from.
  </div>
</div>

<?php app_foot(); ?>

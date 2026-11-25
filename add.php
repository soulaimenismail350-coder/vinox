<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/proc.php';

$user = require_login();
$error = '';

/* Kept on a failed submit, with one deliberate hole in it: the password and
   the access token are never put back. Re-entering a long token because the
   match count was out of range is the kind of thing that makes people give up
   on a form - but writing a live credential back into the HTML puts it in the
   response, in the browser's back-forward cache and in whatever proxy sits in
   between, and that is worse than retyping it. The uid stays, because it is an
   account number rather than a secret and without it the retry is a blank
   form. */
$type   = 'guest';
$uid    = '';
$target = 50;
$endless = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $type   = ($_POST['auth_type'] ?? 'guest') === 'access_token' ? 'access_token' : 'guest';
    $uid    = trim((string)($_POST['uid'] ?? ''));
    $pass   = trim((string)($_POST['password'] ?? ''));
    $token  = trim((string)($_POST['access_token'] ?? ''));
    $target = clamp_target($_POST['target_matches'] ?? 50);
    $endless = isset($_POST['unlimited']);

    $missing = $type === 'guest' ? ($uid === '' || $pass === '') : ($token === '');

    /* Shape-checked before a credit is spent. The alternative is a process
       that exists, cost a credit, and can only ever fail to sign in. */
    $malformed = '';
    if (!$missing) {
        if ($type === 'guest' && !valid_game_uid($uid)) {
            $malformed = 'A uid is 4 to 20 digits and nothing else.';
        } elseif ($type === 'guest' && !valid_game_password($pass)) {
            $malformed = 'A password is 4 to 64 letters and digits.';
        } elseif ($type === 'access_token' && !valid_access_token($token)) {
            $malformed = 'That does not look like an access token.';
        }
    }

    if ((int)$user['credits'] < PROCESS_COST) {
        $error = 'Not enough credits. Redeem a key first.';
    } elseif ($missing) {
        $error = $type === 'guest'
            ? 'Guest mode needs both a uid and a password.'
            : 'Token mode needs an access token.';
    } elseif ($malformed !== '') {
        $error = $malformed;
    } else {
        db()->beginTransaction();
        try {
            /* The credit check and the charge have to sit inside the same
               transaction as the insert, or two tabs could each pass the check
               and both take a slot for one credit. */
            $charge = db()->prepare(
                'UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?'
            );
            $charge->execute([PROCESS_COST, $user['id'], PROCESS_COST]);
            if ($charge->rowCount() !== 1) {
                throw new RuntimeException('insufficient credits');
            }
            db()->prepare(
                'INSERT INTO processes
                 (user_id, auth_type, uid, password, access_token,
                  unlimited, target_matches, status, message)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $user['id'], $type,
                /* Sealed on the way in, so the three credential columns are
                   never plaintext at rest. See includes/crypto.php. */
                $type === 'guest' ? seal($uid) : '',
                $type === 'guest' ? seal($pass) : '',
                $type === 'access_token' ? seal($token) : '',
                $endless ? 1 : 0, $target, 'idle', 'Not started yet.',
            ]);
            $pid = (int)db()->lastInsertId();
            db()->commit();
            runtime_refresh($pid);
            log_line($pid, 'Process created.');

            /* Straight into a run. Adding an account and then having to press
               Start is a step nobody wants; the credit is already spent and
               the intent is not ambiguous. If the engine is not configured the
               start fails cleanly and the process is left idle with the reason
               on its card, which is the same place any other failure lands. */
            if (AUTOSTART_ON_ADD) {
                $problem = proc_command(
                    ['id' => $pid, 'status' => 'idle'], 'start');
                flash($problem !== 'Unknown command.'
                    ? 'Process added and starting.'
                    : 'Process added.');
            } else {
                flash('Process added. It is idle until you press Start.');
            }
            redirect('process.php?id=' . $pid);
        } catch (Throwable $ex) {
            db()->rollBack();
            $error = 'Could not add that process. Check your credit balance and try again.';
        }
    }
}

app_head('Add process', 'add', ['Processes' => 'dashboard.php']);
?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <div class="eyebrow">Costs <?= PROCESS_COST ?> credit</div>
      <h1 style="margin-top:8px">Add a process</h1>
      <p class="lead">
        There is nothing to name here. The worker signs in, reads the in-game
        name and account id, and sends them back - a name you typed yourself
        would drift from the account the first time you swapped credentials.
      </p>
    </div>
    <span class="tag <?= (int)$user['credits'] >= PROCESS_COST ? 'blue' : 'red' ?>">
      <?= (int)$user['credits'] ?> credits
    </span>
  </div>

  <?php if ($error): ?>
    <div class="note warn" style="margin-bottom:16px"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ((int)$user['credits'] < PROCESS_COST): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="empty">
        <div class="ico"></div>
        <h2>No credits</h2>
        <p>A process slot costs <?= PROCESS_COST ?> credit. Redeem a key to get some.</p>
        <a class="btn blue" href="redeem.php">Redeem a key</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="pad">
      <form method="post" id="addForm" data-seal="uid,password,access_token">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="choices">
          <label class="choice">
            <input type="radio" name="auth_type" value="guest"
                   <?= $type === 'guest' ? 'checked' : '' ?>>
            <span class="t">Guest</span>
            <span class="d">uid and password</span>
          </label>
          <label class="choice">
            <input type="radio" name="auth_type" value="access_token"
                   <?= $type === 'access_token' ? 'checked' : '' ?>>
            <span class="t">Access token</span>
            <span class="d">any platform</span>
          </label>
        </div>

        <div id="guestFields">
          <div class="grid2">
            <div class="field">
              <label for="uid">UID</label>
              <input id="uid" name="uid" type="text" autocomplete="off"
                     inputmode="numeric" pattern="[0-9]{4,20}"
                     value="<?= e($uid) ?>">
              <p class="hint">Digits only.</p>
            </div>
            <div class="field">
              <label for="pw">Password</label>
              <input id="pw" name="password" type="password" autocomplete="off"
                     pattern="[A-Za-z0-9]{4,64}">
              <p class="hint">
                Letters and digits.
                <?= $error !== '' ? 'Type it again - it is never sent back to the page.' : '' ?>
              </p>
            </div>
          </div>
        </div>

        <div id="tokenFields" <?= $type === 'access_token' ? '' : 'hidden' ?>>
          <div class="field">
            <label for="tok">Access token</label>
            <input id="tok" name="access_token" type="password" class="mono"
                   autocomplete="off" spellcheck="false">
            <p class="hint">
              Inspected for open_id and platform automatically.
              <?= $error !== '' ? 'Paste it again - it is never sent back to the page.' : '' ?>
            </p>
          </div>
        </div>

        <div class="grid2" style="margin-top:16px">
          <div class="field">
            <label for="tm">Games to play</label>
            <input id="tm" name="target_matches" type="number" value="<?= $target ?>"
                   min="<?= TARGET_MIN ?>" max="<?= TARGET_MAX ?>" inputmode="numeric">
            <p class="hint">Between <?= TARGET_MIN ?> and <?= TARGET_MAX ?>. Changeable later.</p>
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
                  : 'Stops on its own once the count above is reached.' ?>
            </p>
          </div>
        </div>

        <div class="btns" style="margin-top:0">
          <button class="btn blue" type="submit"
                  <?= (int)$user['credits'] < PROCESS_COST ? 'disabled' : '' ?>>
            Add process
          </button>
          <a class="btn line" href="dashboard.php">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="sec">
  <div class="note info">
    <b>What happens next</b>
    The process is created idle and nothing runs until you press Start. Start
    signs in, reads the account, and takes its own baseline - so the level and
    XP figures always describe the run in front of you.
  </div>
</div>

<?php app_foot(); ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
/* Deleting an account has to stop the processes under it first, and that needs
   the runner. See the delete action below. */
require_once __DIR__ . '/includes/proc.php';

$admin = require_staff();

$id = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$u = $stmt->fetch();

if (!$u) {
    flash('No such account.');
    redirect('admin_users.php');
}

/* The gate for the whole page, GET and POST alike.
 *
 * Every action below re-uses this one answer rather than asking again in its
 * own way, because a page where the form is hidden but the POST still works is
 * not a permission system - it is a suggestion. can_manage_user() is what says
 * an owner cannot edit another owner, and that an admin or a seller only
 * reaches the accounts they created. */
if (!can_manage_user($admin, $u)) {
    flash('That account is not yours to manage.');
    redirect('admin_users.php');
}

/* Nobody may demote, suspend or delete themselves. The last way in should not
   be closable by a mis-click, and someone who locks themselves out has no way
   back that does not involve editing the database by hand. */
$self = (int)$u['id'] === (int)$admin['id'];

/* A seller resells; they do not police. Suspension and deletion are the two
   powers that end someone's access outright, and they stay with the owner and
   the admins. A seller can still take the credits back, which is the lever
   their role actually gives them. */
$mayPunish = !$self && is_admin($admin);

/* The roles this person may assign. Your own is never in the list - see
   $self - so the select is rendered read-only in that case. */
$roles = creatable_roles($admin);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'admin_user.php?id=' . $id;

    if ($action === 'identity') {
        $name = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $msgs = [];

        if ($name !== '' && $name !== $u['username']) {
            if (!valid_username($name)) {
                flash('Username must be 3-24 characters: letters, numbers, . _ -');
                redirect($back);
            }
            if (username_taken($name, $id)) {
                flash('That username is taken.');
                redirect($back);
            }
            db()->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$name, $id]);
            $msgs[] = 'username changed to ' . $name;
        }

        /* Blank means leave it alone, so opening this form to fix a typo in
           the username cannot silently reset someone's password. */
        if ($pass !== '') {
            if (!valid_password($pass)) {
                flash(PASSWORD_RULE);
                redirect($back);
            }
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $msgs[] = 'password reset';
        }

        flash($msgs ? ucfirst(implode(', ', $msgs)) . '.' : 'Nothing changed.');
        redirect($back);
    }

    if ($action === 'access') {
        $credits = max(0, min(100000, (int)($_POST['credits'] ?? 0)));
        $date    = trim((string)($_POST['expires_at'] ?? ''));
        $role    = (string)($_POST['role'] ?? $u['role']);

        if ($date !== '' && !valid_date($date)) {
            flash('That is not a valid expiry date. Nothing changed.');
            redirect($back);
        }

        /* Credits move first and on their own, because for a seller this is a
           transfer between two balances rather than a number being set - and
           it can be refused for want of funds. Doing it inside the same
           UPDATE as the expiry would mean a refused transfer silently threw
           the expiry away with it. */
        $problem = set_user_credits($admin, $u, $credits);
        if ($problem !== null) {
            flash($problem);
            redirect($back);
        }

        $sets = ['expires_at = ?'];
        $args = [$date !== '' ? $date . ' 23:59:59' : null];

        /* Only a role this person may hand out, and never your own. */
        if (!$self && $role !== $u['role'] && in_array($role, $roles, true)) {
            $sets[] = 'role = ?';
            $args[] = $role;
        }
        $args[] = $id;

        db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($args);
        flash('Access updated.');
        redirect($back);
    }

    /* Hand an account to a member of staff.
     *
     * Owner only, and it exists for two reasons. Every account that predates
     * sellers has created_by 0 - made by nobody - so without this the owner
     * has no way to put an existing customer under the seller who actually
     * looks after them. And customers move: a seller leaves, an admin takes
     * over their list, and recreating twenty accounts by hand is not a
     * migration plan.
     */
    if ($action === 'assign') {
        if (!is_owner($admin)) {
            flash('Only the panel owner moves accounts between staff.');
            redirect($back);
        }
        $to = max(0, (int)($_POST['created_by'] ?? 0));
        $keeperName = '';

        if ($to === $id) {
            flash('An account cannot be put in charge of itself.');
            redirect($back);
        }
        if ($to > 0) {
            $find = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
            $find->execute([$to]);
            $keeper = $find->fetch();
            /* Staff, and senior to this account. Putting an admin under a
               seller would give the seller a row they still could not open -
               can_manage_user() checks rank as well as who made it - which
               reads as the panel losing the account. */
            if (!$keeper || !is_staff($keeper)
                || role_rank((string)$keeper['role']) <= role_rank((string)$u['role'])) {
                flash('That account cannot be put in charge of this one.');
                redirect($back);
            }
            $keeperName = (string)$keeper['username'];
        }

        db()->prepare('UPDATE users SET created_by = ? WHERE id = ?')->execute([$to, $id]);
        flash($keeperName !== ''
            ? 'Now managed by ' . $keeperName . '.'
            : 'No longer managed by anyone but you.');
        redirect($back);
    }

    if ($action === 'ban') {
        if (!$mayPunish) {
            flash($self
                ? 'You cannot suspend your own account.'
                : 'Suspending an account is not something a seller can do.');
            redirect($back);
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        $kind   = (string)($_POST['kind'] ?? 'permanent');
        $until  = null;

        if ($kind === 'until') {
            $date = trim((string)($_POST['until'] ?? ''));
            if (!valid_date($date)) {
                flash('A timed suspension needs a valid end date.');
                redirect($back);
            }
            if (strtotime($date . ' 23:59:59') <= time()) {
                flash('That end date is already in the past.');
                redirect($back);
            }
            $until = $date . ' 23:59:59';
        }

        db()->prepare(
            "UPDATE users SET banned = 1, ban_reason = ?, banned_at = datetime('now'),
                    banned_until = ? WHERE id = ?"
        )->execute([$reason !== '' ? $reason : 'No reason given.', $until, $id]);

        /* Stop their work too. A suspended account should not keep a worker
           busy on its behalf. */
        db()->prepare(
            "UPDATE processes SET status = 'idle', message = 'Owner account suspended.'
             WHERE user_id = ? AND status <> 'idle'"
        )->execute([$id]);

        flash($until
            ? 'Suspended until ' . substr($until, 0, 10) . '.'
            : 'Suspended until you lift it.');
        redirect($back);
    }

    if ($action === 'unban') {
        if (!$mayPunish) {
            flash('Lifting a suspension is not something a seller can do.');
            redirect($back);
        }
        db()->prepare(
            "UPDATE users SET banned = 0, ban_reason = '', banned_at = NULL,
                    banned_until = NULL WHERE id = ?"
        )->execute([$id]);
        flash('Suspension lifted.');
        redirect($back);
    }

    if ($action === 'delete') {
        if (!$mayPunish) {
            flash($self
                ? 'You cannot delete your own account.'
                : 'Deleting an account is not something a seller can do.');
            redirect($back);
        }
        /* Typing the name is the confirmation. A dialog is dismissed by
           reflex; a name has to be read first. */
        if (trim((string)($_POST['confirm'] ?? '')) !== $u['username']) {
            flash('Type the username exactly to confirm deletion. Nothing was deleted.');
            redirect($back);
        }

        /* Their processes go with them.

           The foreign key takes the rows, but a row is not what is playing: a
           bot is, out of a folder that holds this account's login in the
           clear. Stop each one and wipe its folder while the row still exists,
           because runtime_dir() resolves the path by joining the process back
           to its owner - do this after the DELETE and there is nothing left to
           join to, and a process nobody can find keeps levelling for an
           account that no longer exists. */
        $theirs = db()->prepare('SELECT id FROM processes WHERE user_id = ?');
        $theirs->execute([$id]);
        $count = 0;
        foreach ($theirs->fetchAll() as $row) {
            $pid = (int)$row['id'];
            runner_stop($pid);
            runner_kill_hard($pid);
            runtime_delete($pid);
            $count++;
        }

        /* Their folder under data/processes is now empty. rmdir refuses on a
           directory that still has anything in it, so this is safe to attempt
           unconditionally and does nothing if a process somehow survived. */
        @rmdir(runtime_user_dir($id, (string)$u['username']));

        /* Anyone this account created is handed to whoever is doing the
           deleting. Left pointing at an id that no longer exists they would
           belong to nobody, which puts them out of reach of every panel except
           the owner's - customers quietly orphaned by an admin being removed. */
        db()->prepare('UPDATE users SET created_by = ? WHERE created_by = ?')
            ->execute([(int)$admin['id'], $id]);

        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('Deleted ' . $u['username'] . ', ' . $count . ' process'
            . ($count === 1 ? '' : 'es') . ' and every log line attached to them.');
        redirect('admin_users.php');
    }

    redirect($back);
}

$procs = db()->prepare(
    'SELECT * FROM processes WHERE user_id = ? ORDER BY id DESC'
);
$procs->execute([$id]);
$processes = $procs->fetchAll();

$keyUses = db()->prepare(
    'SELECT k.code, k.credits, ku.used_at FROM key_uses ku
     JOIN keys k ON k.id = ku.key_id WHERE ku.user_id = ? ORDER BY ku.id DESC LIMIT 10'
);
$keyUses->execute([$id]);
$redemptions = $keyUses->fetchAll();

$banned  = (int)$u['banned'] === 1;
$until   = (string)($u['banned_until'] ?? '');
$expired = !empty($u['expires_at']) && strtotime((string)$u['expires_at']) < time();

/* Who made this account. It is the field the whole permission system turns on,
   so it is worth saying out loud on the page rather than leaving an owner to
   work out why a seller can see a customer they cannot place. */
$creator = '';
if ((int)($u['created_by'] ?? 0) > 0) {
    $who = db()->prepare('SELECT username FROM users WHERE id = ?');
    $who->execute([(int)$u['created_by']]);
    $row = $who->fetch();
    $creator = $row ? (string)$row['username'] : '';
}

/* Who this account could be handed to: staff senior to it, and never itself.
   Only the owner sees the control, so this is only queried for them. */
$keepers = [];
if (is_owner($admin)) {
    $stmt = db()->prepare(
        "SELECT id, username, role FROM users
         WHERE role IN ('seller', 'admin', 'owner') AND id <> ?
         ORDER BY role, username"
    );
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $row) {
        if (role_rank((string)$row['role']) > role_rank((string)$u['role'])) {
            $keepers[] = $row;
        }
    }
}

app_head($u['username'], 'users', ['Admin' => 'admin.php', 'Users' => 'admin_users.php']);
?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <div class="eyebrow">
        Account #<?= (int)$u['id'] ?> &middot;
        joined <?= e(substr((string)$u['created_at'], 0, 10)) ?>
        <?php if ($creator !== ''): ?>&middot; added by <?= e($creator) ?><?php endif; ?>
      </div>
      <h1 style="margin-top:8px"><?= e($u['username']) ?></h1>
      <p class="lead">
        <?php if ($self): ?>
          This is your own account. Role, suspension and deletion are disabled
          here so the last way in cannot be closed by accident.
        <?php elseif (is_seller($admin)): ?>
          Credits, expiry, username and password. Suspending and deleting stay
          with the panel owner.
        <?php else: ?>
          Everything about this account is editable from this page.
        <?php endif; ?>
      </p>
    </div>
    <span class="status-wrap">
      <span class="dot <?= $banned || $expired ? 'error' : 'running' ?>"></span>
      <span class="state <?= $banned || $expired ? 'error' : 'running' ?>">
        <?= $banned ? ($until !== '' ? 'timed ban' : 'suspended') : ($expired ? 'expired' : 'active') ?>
      </span>
    </span>
  </div>

  <div class="metrics">
    <div class="metric"><div class="k">Credits</div><div class="v on"><?= (int)$u['credits'] ?></div></div>
    <div class="metric"><div class="k">Processes</div><div class="v"><?= count($processes) ?></div></div>
    <div class="metric">
      <div class="k">Role</div>
      <div class="v txt"><?= e($u['role']) ?></div>
    </div>
    <div class="metric">
      <div class="k">Expires</div>
      <div class="v txt"><?= $u['expires_at'] ? e(substr((string)$u['expires_at'], 0, 10)) : 'Never' ?></div>
    </div>
  </div>
</div>

<?php if ($banned): ?>
<div class="sec">
  <div class="note warn">
    <b><?= $until !== '' ? 'Suspended until ' . e(substr($until, 0, 16)) : 'Suspended indefinitely' ?></b>
    <?= e($u['ban_reason']) ?>
    <?php if ($u['banned_at']): ?>
      <br><span class="dim small">Set on <?= e(substr((string)$u['banned_at'], 0, 16)) ?>.</span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="sec">
  <div class="sec-hd"><div><h2>Credits, expiry and role</h2></div></div>
  <div class="card">
    <div class="pad">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="access">
        <div class="grid3">
          <div class="field">
            <label for="cr">Credits</label>
            <input id="cr" name="credits" type="number" value="<?= (int)$u['credits'] ?>"
                   min="<?= is_seller($admin) ? (int)$u['credits'] : 0 ?>"
                   max="100000" inputmode="numeric" class="mono">
            <?php if (is_seller($admin)): ?>
              <p class="hint">
                Anything you add comes out of your <?= (int)$admin['credits'] ?>.
                It only goes up: credits already handed over are spent, and a
                refund would let the same ones be sold twice.
              </p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="ex">Expires on</label>
            <input id="ex" name="expires_at" type="date"
                   value="<?= e(substr((string)($u['expires_at'] ?? ''), 0, 10)) ?>">
            <p class="hint">Blank means never.</p>
          </div>
          <div class="field">
            <label for="ro">Role</label>
            <?php if ($self || count($roles) < 2): ?>
              <select id="ro" disabled>
                <option selected><?= e($u['role']) ?></option>
              </select>
              <p class="hint">
                <?= $self
                    ? 'You cannot change your own role.'
                    : 'Only the panel owner sets roles.' ?>
              </p>
            <?php else: ?>
              <select id="ro" name="role">
                <?php foreach ($roles as $r): ?>
                  <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>>
                    <?= e($r) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="hint">
                You cannot set a role at or above your own, here or anywhere.
              </p>
            <?php endif; ?>
          </div>
        </div>
        <button class="btn blue" type="submit" style="margin-top:14px">Save</button>
      </form>
    </div>
  </div>
</div>

<div class="sec">
  <div class="sec-hd"><div><h2>Username and password</h2></div></div>
  <div class="card">
    <div class="pad">
      <form method="post" data-seal="username,password">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="identity">
        <div class="grid2">
          <div class="field">
            <label for="un">Username</label>
            <input id="un" name="username" type="text" value="<?= e($u['username']) ?>"
                   autocomplete="off">
          </div>
          <div class="field">
            <label for="pwd">New password</label>
            <input id="pwd" name="password" type="text" placeholder="unchanged"
                   autocomplete="off">
            <p class="hint">Leave blank to keep the current one.</p>
          </div>
        </div>
        <button class="btn line" type="submit" style="margin-top:14px">Save</button>
      </form>
    </div>
  </div>
</div>

<?php if (is_owner($admin) && !$self): ?>
<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Managed by</h2>
      <p class="lead">
        Who sees this account in their own panel, and who may change it. An
        admin or a seller reaches only the accounts under their name; you reach
        all of them either way.
      </p>
    </div>
  </div>
  <div class="card">
    <div class="pad">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="assign">
        <div class="field" style="max-width:340px">
          <label for="mb">Staff account</label>
          <select id="mb" name="created_by">
            <option value="0" <?= (int)$u['created_by'] === 0 ? 'selected' : '' ?>>
              Nobody &mdash; you only
            </option>
            <?php foreach ($keepers as $k): ?>
              <option value="<?= (int)$k['id'] ?>"
                      <?= (int)$u['created_by'] === (int)$k['id'] ? 'selected' : '' ?>>
                <?= e($k['username']) ?> (<?= e($k['role']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <p class="hint">
            Only staff senior to this account are listed. Moving it does not
            move its credits or its processes.
          </p>
        </div>
        <button class="btn line" type="submit" style="margin-top:4px">Save</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Suspension</h2>
      <p class="lead">
        Separate from expiry on purpose: one is a decision about a person and
        carries a reason, the other is a clock running out.
      </p>
    </div>
  </div>
  <div class="card">
    <div class="pad">
      <?php if ($self): ?>
        <p class="muted small" style="margin:0">
          You cannot suspend your own account.
        </p>
      <?php elseif (!$mayPunish): ?>
        <p class="muted small" style="margin:0">
          Sellers do not suspend accounts. Take the credits back instead, or
          ask the panel owner.
          <?php if ($banned): ?>
            This account is currently suspended<?= $until !== ''
              ? ' until ' . e(substr($until, 0, 16)) : ' indefinitely' ?>.
          <?php endif; ?>
        </p>
      <?php elseif ($banned): ?>
        <p class="lead" style="margin:0 0 14px">
          Currently suspended<?= $until !== '' ? ' until ' . e(substr($until, 0, 16)) : ' indefinitely' ?>.
        </p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <input type="hidden" name="action" value="unban">
          <button class="btn blue" type="submit">Lift the suspension</button>
        </form>
      <?php else: ?>
        <form method="post" id="banForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= $id ?>">
          <input type="hidden" name="action" value="ban">

          <div class="choices">
            <label class="choice">
              <input type="radio" name="kind" value="permanent" checked>
              <span class="t">Until lifted</span>
              <span class="d">stays until you clear it</span>
            </label>
            <label class="choice">
              <input type="radio" name="kind" value="until">
              <span class="t">Temporary</span>
              <span class="d">lifts itself on a date</span>
            </label>
          </div>

          <div class="field" id="banUntil" hidden>
            <label for="bu">Suspended until the end of</label>
            <input id="bu" name="until" type="date">
            <p class="hint">The account works again the next morning, with no action from you.</p>
          </div>

          <div class="field">
            <label for="br">Reason</label>
            <input id="br" name="reason" type="text" autocomplete="off"
                   placeholder="What happened, in a sentence">
            <p class="hint">
              Stored either way, and shown to them at sign-in. Write it for
              yourself in six months, not for right now.
            </p>
          </div>

          <button class="btn red" type="submit" style="margin-top:4px">Suspend this account</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sec">
  <div class="card">
    <div class="card-hd">
      <h2>Their processes</h2>
      <span class="tag"><?= count($processes) ?></span>
    </div>
    <?php if (!$processes): ?>
      <div class="pad"><p class="muted small" style="margin:0">None.</p></div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Account</th><th>Type</th><th>State</th><th>Progress</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($processes as $p):
            $pname = $p['account_name'] !== '' ? $p['account_name'] : 'Not signed in yet';
          ?>
            <tr>
              <td data-label="Account"><b><?= e($pname) ?></b></td>
              <td data-label="Type" class="mono"><?= $p['auth_type'] === 'guest' ? 'UID' : 'TOKEN' ?></td>
              <td data-label="State">
                <span class="status-wrap">
                  <span class="dot <?= e($p['status']) ?>"></span>
                  <span class="state <?= e($p['status']) ?>"><?= e($p['status']) ?></span>
                </span>
              </td>
              <td data-label="Progress" class="mono">
                <?= (int)$p['matches_done'] ?>/<?= (int)$p['target_matches'] ?>
              </td>
              <td data-label="Actions" class="nowrap">
                <a class="btn line xs" href="process.php?id=<?= (int)$p['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($redemptions): ?>
<div class="sec">
  <div class="card">
    <div class="card-hd"><h2>Keys they redeemed</h2></div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Code</th><th>Credits</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($redemptions as $r): ?>
          <tr>
            <td data-label="Code" class="mono nowrap"><?= e($r['code']) ?></td>
            <td data-label="Credits" class="mono">+<?= (int)$r['credits'] ?></td>
            <td data-label="When" class="mono dim"><?= e(substr((string)$r['used_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($mayPunish): ?>
<div class="sec">
  <div class="card danger">
    <div class="card-hd"><h2>Delete this account</h2></div>
    <div class="pad">
      <p class="lead" style="margin:0 0 16px">
        Removes <b><?= e($u['username']) ?></b>, their
        <?= count($processes) ?> process<?= count($processes) === 1 ? '' : 'es' ?>
        and every log line attached to them. Anything still running is stopped
        first and its folder wiped. This cannot be undone. If you only want to
        stop them for now, suspend the account instead.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="delete">
        <div class="field" style="max-width:340px">
          <label for="cf">Type <?= e($u['username']) ?> to confirm</label>
          <input id="cf" name="confirm" type="text" autocomplete="off" class="mono"
                 placeholder="<?= e($u['username']) ?>">
        </div>
        <button class="btn red" type="submit" style="margin-top:4px">Delete permanently</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php app_foot(); ?>

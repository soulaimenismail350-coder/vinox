<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

/* Sellers get this page too - it is the whole of their panel - so the gate is
   the low one and the narrowing is done by the scope below rather than by
   keeping them out. */
$admin = require_staff();

$roles = creatable_roles($admin);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if ((string)($_POST['action'] ?? '') === 'create_user') {
        $name    = trim((string)($_POST['username'] ?? ''));
        $pass    = (string)($_POST['password'] ?? '');
        $credits = max(0, min(100000, (int)($_POST['credits'] ?? 0)));
        $days    = max(0, min(36500, (int)($_POST['days'] ?? 0)));
        $role    = (string)($_POST['role'] ?? 'member');

        /* Checked against what this person may hand out, not against a list of
           names. The select only offers those roles, but a select is a
           suggestion to the browser and this is the actual rule. */
        if (!in_array($role, $roles, true)) {
            $role = 'member';
        }

        if (!valid_username($name)) {
            flash('Username must be 3-24 characters: letters, numbers, . _ -');
        } elseif (!valid_password($pass)) {
            flash(PASSWORD_RULE);
        } elseif (username_taken($name)) {
            flash('That username is taken.');
        } else {
            $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
            $problem = create_managed_user($admin, $name, $pass, $role, $credits, $expires);
            flash($problem ?? ('Created ' . $name . ' as ' . $role . ' with ' . $credits
                . ' credits' . ($expires ? ', expires ' . substr($expires, 0, 10) : ', no expiry')
                . (is_seller($admin) && $credits > 0 ? ', taken from your balance' : '') . '.'));
        }
    }
    redirect('admin_users.php');
}

/* One clause for the accounts this person may see and one for the processes
   under them. Everything on the page - the list, the six totals, the live
   panel - narrows through these, so none of them can show a number the others
   cannot account for. */
[$uWhere, $uArgs] = users_scope($admin);
[$pWhere, $pArgs] = processes_scope($admin);

$q = trim((string)($_GET['q'] ?? ''));

$sql  = 'SELECT u.*, (SELECT COUNT(*) FROM processes p WHERE p.user_id = u.id) AS procs
         FROM users u WHERE ' . $uWhere;
$args = $uArgs;
if ($q !== '') {
    $sql .= ' AND u.username LIKE ?';
    $args[] = '%' . $q . '%';
}
$sql .= ' ORDER BY u.id';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$users = $stmt->fetchAll();

$tally = static function (string $sql, array $args): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return (int)$stmt->fetch()['c'];
};

$t = [
    'users'   => $tally("SELECT COUNT(*) c FROM users u WHERE $uWhere", $uArgs),
    'banned'  => $tally("SELECT COUNT(*) c FROM users u WHERE u.banned = 1 AND $uWhere", $uArgs),
    'expired' => $tally("SELECT COUNT(*) c FROM users u
                         WHERE u.expires_at IS NOT NULL
                           AND u.expires_at < datetime('now') AND $uWhere", $uArgs),
    'procs'   => $tally("SELECT COUNT(*) c FROM processes p WHERE $pWhere", $pArgs),
    'live'    => $tally("SELECT COUNT(*) c FROM processes p
                         WHERE p.status IN ('running','starting') AND $pWhere", $pArgs),
    'credits' => $tally("SELECT COALESCE(SUM(u.credits),0) c FROM users u WHERE $uWhere", $uArgs),
];

/* Whatever is moving right now, across the accounts this person answers for.
   It is the one thing an owner checks without being asked, so it sits above
   the user table. */
$act = db()->prepare(
    "SELECT p.id, p.account_name, p.status, p.matches_done, p.target_matches,
            u.username, u.id AS uid
     FROM processes p JOIN users u ON u.id = p.user_id
     WHERE p.status IN ('running','starting','stopping') AND $pWhere
     ORDER BY p.updated_at DESC LIMIT 8"
);
$act->execute($pArgs);
$active = $act->fetchAll();

app_head('Users', 'users');
admin_tabs('users');
?>

<?php if (is_owner($admin) && data_dir_exposed()): ?>
<div class="sec">
  <div class="note warn">
    <b>Your database is downloadable right now</b>
    A request to <span class="mono">/data/<?= e(basename(DB_PATH)) ?></span> from
    outside this server answered 200. That file holds every account and every
    password hash on the site, and the same folder holds one plaintext game
    login per running process. Paste the block in
    <span class="mono">deploy/nginx-security.conf</span> into your server
    config and reload it. This is checked again every six hours.
  </div>
</div>
<?php endif; ?>

<?php if (!is_owner($admin)): ?>
<div class="sec">
  <div class="note info">
    <b>You see the accounts you created</b>
    <?php if (is_seller($admin)): ?>
      Every credit you give a customer comes off your own balance, and a sale
      is final - giving is one way. You have
      <b><?= (int)$admin['credits'] ?></b> left.
    <?php else: ?>
      Accounts made by someone else, and anyone at your own level, are not
      listed here and cannot be changed from this panel.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="sec">
  <div class="metrics">
    <div class="metric"><div class="k">Users</div><div class="v"><?= (int)$t['users'] ?></div></div>
    <div class="metric"><div class="k">Suspended</div><div class="v"><?= (int)$t['banned'] ?></div></div>
    <div class="metric"><div class="k">Expired</div><div class="v"><?= (int)$t['expired'] ?></div></div>
    <div class="metric"><div class="k">Processes</div><div class="v"><?= (int)$t['procs'] ?></div></div>
    <div class="metric">
      <div class="k">Running now</div>
      <div class="v <?= (int)$t['live'] ? 'on' : '' ?>"><?= (int)$t['live'] ?></div>
    </div>
    <div class="metric"><div class="k">Credits held</div><div class="v"><?= (int)$t['credits'] ?></div></div>
  </div>
</div>

<?php if ($active): ?>
<div class="sec">
  <div class="card">
    <div class="card-hd">
      <h2>Running right now</h2>
      <span class="tag"><?= count($active) ?></span>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Account</th><th>Owner</th><th>State</th><th>Progress</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($active as $a):
          $an = $a['account_name'] !== '' ? $a['account_name'] : 'Not signed in yet';
        ?>
          <tr>
            <td data-label="Account"><b><?= e($an) ?></b></td>
            <td data-label="Owner">
              <a href="admin_user.php?id=<?= (int)$a['uid'] ?>"><?= e($a['username']) ?></a>
            </td>
            <td data-label="State">
              <span class="status-wrap">
                <span class="dot <?= e($a['status']) ?>"></span>
                <span class="state <?= e($a['status']) ?>"><?= e($a['status']) ?></span>
              </span>
            </td>
            <td data-label="Progress" class="mono">
              <?= (int)$a['matches_done'] ?>/<?= (int)$a['target_matches'] ?>
            </td>
            <td data-label="Actions" class="nowrap">
              <a class="btn line xs" href="process.php?id=<?= (int)$a['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2><?= is_owner($admin) ? 'All accounts' : 'Your accounts' ?></h2>
      <p class="lead">
        Open one to change credits, expiry, role or password<?php
          if (is_admin($admin)): ?>, or to suspend or delete it<?php
          endif; ?>.
      </p>
    </div>
    <a class="btn blue sm" href="#create">Create an account</a>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="pad" style="padding-top:14px;padding-bottom:14px">
      <form method="get" style="display:flex;gap:9px;flex-wrap:wrap">
        <input name="q" type="text" value="<?= e($q) ?>" placeholder="Search by username"
               autocomplete="off" style="flex:1;min-width:180px">
        <button class="btn line" type="submit">Search</button>
        <?php if ($q !== ''): ?>
          <a class="btn ghost" href="admin_users.php">Clear</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>User</th><th>Role</th><th>Credits</th><th>Expires</th>
              <th>Proc</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr><td data-label="" colspan="7" class="muted small">No account matches that.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u):
          $self    = (int)$u['id'] === (int)$admin['id'];
          $expired = !empty($u['expires_at']) && strtotime((string)$u['expires_at']) < time();
          $until   = (string)($u['banned_until'] ?? '');
          /* An owner sees their peers listed - hiding them would make the
             count wrong - but the way in is not offered, because opening the
             page would only bounce straight back out again. */
          $may     = can_manage_user($admin, $u);
        ?>
          <tr>
            <td data-label="User" class="nowrap">
              <?php if ($may): ?>
                <a href="admin_user.php?id=<?= (int)$u['id'] ?>"><b><?= e($u['username']) ?></b></a>
              <?php else: ?>
                <b><?= e($u['username']) ?></b>
              <?php endif; ?>
              <?php if ($self): ?><span class="dim small">you</span><?php endif; ?>
            </td>
            <td data-label="Role">
              <span class="tag <?= e(role_tag((string)$u['role'])) ?>"><?= e($u['role']) ?></span>
            </td>
            <td data-label="Credits" class="mono"><?= (int)$u['credits'] ?></td>
            <td data-label="Expires" class="mono nowrap">
              <?= $u['expires_at'] ? e(substr((string)$u['expires_at'], 0, 10)) : '—' ?>
              <?php if ($expired): ?><span class="tag red">expired</span><?php endif; ?>
            </td>
            <td data-label="Processes" class="mono"><?= (int)$u['procs'] ?></td>
            <td data-label="Status">
              <?php if ((int)$u['banned'] === 1): ?>
                <span class="tag red"><?= $until !== '' ? 'timed ban' : 'suspended' ?></span>
                <div class="dim small" style="margin-top:4px;max-width:200px">
                  <?php if ($until !== ''): ?>
                    until <?= e(substr($until, 0, 16)) ?><br>
                  <?php endif; ?>
                  <?= e($u['ban_reason']) ?>
                </div>
              <?php else: ?>
                <span class="tag">active</span>
              <?php endif; ?>
            </td>
            <td data-label="Actions" class="nowrap">
              <?php if ($may): ?>
                <a class="btn line xs" href="admin_user.php?id=<?= (int)$u['id'] ?>">Manage</a>
              <?php else: ?>
                <span class="dim small" title="Accounts at your own level are not yours to change">
                  &mdash;
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="sec" id="create">
  <div class="sec-hd">
    <div>
      <h2>Create an account</h2>
      <p class="lead">
        Set what it starts with. Everything here can be changed afterwards.
      </p>
    </div>
  </div>
  <div class="card">
    <div class="pad">
      <form method="post" data-seal="username,password">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_user">
        <div class="grid2">
          <div class="field">
            <label for="cu">Username</label>
            <input id="cu" name="username" type="text" required autocomplete="off">
            <p class="hint">3-24 characters: letters, numbers, . _ -</p>
          </div>
          <div class="field">
            <label for="cp">Password</label>
            <input id="cp" name="password" type="text" required autocomplete="off"
                   pattern="[A-Za-z0-9]{6,72}">
            <p class="hint">
              6 or more letters and digits. Shown in the clear so you can pass it on.
            </p>
          </div>
        </div>
        <div class="grid3" style="margin-top:2px">
          <div class="field">
            <label for="cc">Starting credits</label>
            <input id="cc" name="credits" type="number" value="1" min="0"
                   <?= is_seller($admin) ? 'max="' . (int)$admin['credits'] . '"' : '' ?>
                   inputmode="numeric">
            <?php if (is_seller($admin)): ?>
              <p class="hint">
                Taken from your <?= (int)$admin['credits'] ?>, and not
                refundable once the account has them.
              </p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="cd">Valid for (days)</label>
            <input id="cd" name="days" type="number" value="30" min="0" inputmode="numeric">
            <p class="hint">0 means no expiry.</p>
          </div>
          <div class="field">
            <label for="cr">Role</label>
            <select id="cr" name="role" <?= count($roles) < 2 ? 'disabled' : '' ?>>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>" <?= $r === 'member' ? 'selected' : '' ?>>
                  <?= e($r) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (count($roles) < 2): ?>
              <input type="hidden" name="role" value="member">
              <p class="hint">You can create members.</p>
            <?php elseif (is_owner($admin)): ?>
              <p class="hint">
                A seller hands credits out of their own balance. An admin mints
                them. Both only ever see the accounts they made.
              </p>
            <?php else: ?>
              <p class="hint">
                A seller gets a panel of their own and hands credits out of
                their own balance.
              </p>
            <?php endif; ?>
          </div>
        </div>
        <button class="btn blue" type="submit" style="margin-top:14px">Create account</button>
      </form>
    </div>
  </div>
</div>

<?php app_foot(); ?>

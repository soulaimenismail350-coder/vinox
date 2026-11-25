<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

/* Staff, sellers included. A key is how an account is created on this site at
   all now - the signup form will not take a registration without one - so a
   seller with no way to mint keys would be a seller with no way to sell.
   What keeps that honest is the billing: see key_cost() below. */
$admin = require_staff();

/* An admin sees the batch they minted; the owner sees the machine. Notes like
   "for Youssef, paid" are the reason this is scoped rather than shared - a key
   list is a customer list. */
[$kWhere, $kArgs] = is_owner($admin)
    ? ['1 = 1', []]
    : ['created_by = ?', [(int)$admin['id']]];

/* What a batch costs the person minting it.
 *
 * An owner and an admin mint credits out of nothing, so nothing. A seller is
 * reselling, and a key is a promise of credits to however many people may
 * redeem it - so the whole promise is reserved from their balance the moment
 * the key exists, not drawn down as strangers redeem it. Reserving up front is
 * what makes the code a real thing to hand out: the customer at the other end
 * can never be told the code failed because the seller had since spent the
 * balance behind it somewhere else. The unredeemed part comes back when the
 * key is deleted.
 */
function key_cost(array $who, int $count, int $credits, int $uses): int
{
    return is_seller($who) ? $count * $credits * $uses : 0;
}

/* Delete a key and give back what it never handed out.
 *
 * The refund goes to whoever minted it, not to whoever pressed the button: an
 * owner tidying up a seller's dead codes must not be paid for them. Nothing is
 * refunded for a key an owner or an admin minted, because nothing was charged
 * for it - key_cost() is what decides both, so the two cannot drift.
 *
 * Returns the sentence to show.
 */
function key_release(array $key): string
{
    $left = max(0, (int)$key['max_uses'] - (int)$key['used_count']);

    $minter = db()->prepare('SELECT * FROM users WHERE id = ?');
    $minter->execute([(int)$key['created_by']]);
    $owner = $minter->fetch();

    $back = ($owner && $left > 0) ? key_cost($owner, 1, (int)$key['credits'], $left) : 0;

    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM keys WHERE id = ?')->execute([(int)$key['id']]);
        if ($back > 0) {
            db()->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
                ->execute([$back, (int)$owner['id']]);
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        return 'Could not delete that key.';
    }

    return $back > 0
        ? 'Key deleted, ' . $back . ' unused credit' . ($back === 1 ? '' : 's')
          . ' returned to ' . $owner['username'] . '.'
        : 'Key deleted.';
}

function key_code(): string
{
    /* No O, 0, I or 1. These get read aloud and retyped, and that particular
       confusion turns straight into support work. */
    $set = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $part = function (int $n) use ($set): string {
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= $set[random_int(0, strlen($set) - 1)];
        }
        return $out;
    };
    return 'GGLP-' . $part(5) . '-' . $part(4);
}

$made = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'make_keys') {
        $count   = max(1, min(50, (int)($_POST['count'] ?? 1)));
        $credits = max(1, min(10000, (int)($_POST['credits'] ?? 1)));
        $uses    = max(1, min(10000, (int)($_POST['max_uses'] ?? 1)));
        $note    = trim((string)($_POST['note'] ?? ''));
        $life    = duration_seconds(
            (int)($_POST['life_amount'] ?? 0),
            (string)($_POST['life_unit'] ?? 'day')
        );
        $cost    = key_cost($admin, $count, $credits, $uses);
        $have    = 0;
        $short   = false;

        /* The whole batch is minted inside one transaction with the seller's
           payment. Half a batch and no charge, or a charge and no keys, are
           both worse than the request simply failing. */
        db()->beginTransaction();
        try {
            if ($cost > 0) {
                /* Read inside the transaction, not from the copy loaded at the
                   top of the request: two tabs minting at once would both
                   check against the same stale balance and spend it twice. */
                $purse = db()->prepare('SELECT credits FROM users WHERE id = ?');
                $purse->execute([(int)$admin['id']]);
                $have = (int)$purse->fetch()['credits'];
                if ($cost > $have) {
                    $short = true;
                    throw new RuntimeException('not enough credits');
                }
                db()->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')
                    ->execute([$cost, (int)$admin['id']]);
            }

            $ins = db()->prepare(
                'INSERT INTO keys (code, credits, max_uses, valid_seconds, note, created_by)
                 VALUES (?,?,?,?,?,?)'
            );
            for ($i = 0; $i < $count; $i++) {
                /* A collision on the unique index is astronomically unlikely
                   but free to survive: retry a few times rather than 500 on
                   it. Five failures in a row is a broken table, not luck, so
                   that does abort the batch. */
                for ($try = 0; $try < 5; $try++) {
                    $code = key_code();
                    try {
                        $ins->execute([$code, $credits, $uses, $life, $note,
                                       (int)$admin['id']]);
                        $made[] = $code;
                        break;
                    } catch (Throwable $ex) {
                        if ($try === 4) {
                            throw $ex;
                        }
                    }
                }
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            $made = [];
            flash($short
                ? 'That batch reserves ' . $cost . ' credits - ' . $credits
                  . ' for each of ' . $uses . ' people, across ' . $count
                  . ' key(s) - and you have ' . $have . '. Nothing was created.'
                : 'Could not create those keys. Nothing was created.');
            redirect('admin_keys.php');
        }

        boot_session();
        $_SESSION['made_keys'] = $made;
        flash(count($made) . ' key(s) created, ' . $credits . ' credits each, up to '
            . $uses . ' person(s) per key, ' . duration_label($life) . ' of access'
            . ($cost > 0 ? '. ' . $cost . ' credits reserved from your balance.' : '.'));
        redirect('admin_keys.php');
    }

    if ($action === 'delete_key') {
        /* Scoped, not just listed scoped. The table only shows this person's
           own keys, but an id in a form field is not a permission. */
        $find = db()->prepare("SELECT * FROM keys WHERE id = ? AND $kWhere");
        $find->execute(array_merge([(int)($_POST['key_id'] ?? 0)], $kArgs));
        $key = $find->fetch();
        if (!$key) {
            flash('That key is not yours to delete.');
            redirect('admin_keys.php');
        }
        flash(key_release($key) . ' Credits already redeemed from it stay where they are.');
        redirect('admin_keys.php');
    }

    if ($action === 'delete_used') {
        /* A key that is fully used has nothing left to give back, so this
           refunds nothing by definition - but it goes through the same
           function rather than a bulk DELETE, so there is one answer to "what
           happens to the reserved credits" instead of two. */
        $find = db()->prepare(
            "SELECT * FROM keys WHERE used_count >= max_uses AND $kWhere"
        );
        $find->execute($kArgs);
        $n = 0;
        foreach ($find->fetchAll() as $key) {
            key_release($key);
            $n++;
        }
        flash($n . ' fully used key(s) cleared.');
        redirect('admin_keys.php');
    }

    redirect('admin_keys.php');
}

boot_session();
$justMade = $_SESSION['made_keys'] ?? [];
unset($_SESSION['made_keys']);

$list = db()->prepare("SELECT * FROM keys WHERE $kWhere ORDER BY id DESC LIMIT 200");
$list->execute($kArgs);
$keys = $list->fetchAll();

$totals = db()->prepare(
    "SELECT COUNT(*) total,
            COALESCE(SUM(CASE WHEN used_count >= max_uses THEN 1 ELSE 0 END),0) used_up,
            COALESCE(SUM(used_count),0) redemptions,
            COALESCE(SUM(used_count * credits),0) given
     FROM keys WHERE $kWhere"
);
$totals->execute($kArgs);
$t = $totals->fetch();

app_head('Keys', 'keys');
admin_tabs('keys');
?>

<div class="sec">
  <div class="metrics">
    <div class="metric"><div class="k">Keys</div><div class="v"><?= (int)$t['total'] ?></div></div>
    <div class="metric"><div class="k">Still usable</div><div class="v on"><?= (int)$t['total'] - (int)$t['used_up'] ?></div></div>
    <div class="metric"><div class="k">Redemptions</div><div class="v"><?= (int)$t['redemptions'] ?></div></div>
    <div class="metric"><div class="k">Credits given</div><div class="v"><?= (int)$t['given'] ?></div></div>
    <?php if (is_seller($admin)): ?>
      <div class="metric">
        <div class="k">Your balance</div>
        <div class="v on"><?= (int)$admin['credits'] ?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (is_seller($admin)): ?>
<div class="sec">
  <div class="note info">
    <b>A key reserves credits the moment you create it</b>
    One credit for every person who could redeem it, taken from your balance up
    front so a code you hand out can never fail at the other end. Delete a key
    and the part nobody used comes straight back.
  </div>
</div>
<?php endif; ?>

<?php if ($justMade): ?>
<div class="sec">
  <div class="card">
    <div class="card-hd">
      <h2>Just created</h2>
      <span class="tag blue"><?= count($justMade) ?></span>
    </div>
    <div class="pad">
      <p class="lead" style="margin:0 0 12px">
        Copy these now - they are listed below too, but this is the only time
        they are grouped together.
      </p>
      <div class="log">
        <?php foreach ($justMade as $code): ?>
          <div class="log-row"><time>new</time><span class="mono"><?= e($code) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Create keys</h2>
      <p class="lead">
        Each key carries three things: how many credits one redemption gives,
        how many different people may redeem it, and how long each of them gets
        to keep the account afterwards. A person may use a given key once.
      </p>
    </div>
  </div>
  <div class="card">
    <div class="pad">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="make_keys">
        <div class="grid3">
          <div class="field">
            <label for="kn">How many keys</label>
            <input id="kn" name="count" type="number" value="1" min="1" max="50" inputmode="numeric">
            <p class="hint">Up to 50 at a time.</p>
          </div>
          <div class="field">
            <label for="kc">Credits each gives</label>
            <input id="kc" name="credits" type="number" value="1" min="1" max="10000" inputmode="numeric">
            <p class="hint">One credit = one process slot.</p>
          </div>
          <div class="field">
            <label for="ku">People per key</label>
            <input id="ku" name="max_uses" type="number" value="1" min="1" max="10000" inputmode="numeric">
            <p class="hint">1 for a private code, higher for a shared batch.</p>
          </div>
        </div>
        <div class="grid2" style="margin-top:2px">
          <div class="field">
            <label for="kl">Account lifetime it grants</label>
            <div style="display:flex;gap:9px">
              <input id="kl" name="life_amount" type="number" value="30" min="0"
                     max="1000" inputmode="numeric" style="flex:1;min-width:90px">
              <select name="life_unit" style="flex:1;min-width:110px">
                <?php foreach (array_keys(KEY_UNITS) as $unit): ?>
                  <option value="<?= e($unit) ?>" <?= $unit === 'day' ? 'selected' : '' ?>>
                    <?= e($unit) ?>s
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <p class="hint">
              Counted from the moment each person redeems it, not from today.
              When it runs out their account and everything in it is deleted.
              0 means the key gives credits and no clock.
            </p>
          </div>
          <div class="field">
            <label for="kt">Note</label>
            <input id="kt" name="note" type="text" placeholder="who this batch is for"
                   autocomplete="off">
            <p class="hint">Only you see this. It is what makes a key list readable later.</p>
          </div>
        </div>
        <button class="btn blue" type="submit" style="margin-top:14px">Create keys</button>
      </form>
    </div>
  </div>
</div>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2><?= is_owner($admin) ? 'All keys' : 'Your keys' ?></h2>
      <p class="lead">Newest first. Deleting a key does not take back credits already redeemed.</p>
    </div>
    <?php if ((int)$t['used_up'] > 0): ?>
      <form method="post" onsubmit="return confirm('Delete every fully used key?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_used">
        <button class="btn line sm" type="submit">Clear <?= (int)$t['used_up'] ?> used up</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (!$keys): ?>
      <div class="empty">
        <div class="ico"></div>
        <h2>No keys yet</h2>
        <p>Create a batch above and hand the codes out.</p>
      </div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Code</th><th>Credits</th><th>Used</th><th>Lifetime</th>
                     <th>Note</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($keys as $k):
            $full = (int)$k['used_count'] >= (int)$k['max_uses'];
          ?>
            <tr>
              <td data-label="Code" class="mono nowrap"><?= e($k['code']) ?></td>
              <td data-label="Credits" class="mono"><?= (int)$k['credits'] ?></td>
              <td data-label="Used" class="mono nowrap">
                <?= (int)$k['used_count'] ?>/<?= (int)$k['max_uses'] ?>
                <?php if ($full): ?><span class="tag" style="margin-left:6px">full</span><?php endif; ?>
              </td>
              <td data-label="Lifetime" class="mono nowrap">
                <?= e(duration_label((int)$k['valid_seconds'])) ?>
              </td>
              <td data-label="Note" class="dim small"><?= e($k['note']) ?></td>
              <td data-label="Created" class="mono dim nowrap"><?= e(substr((string)$k['created_at'], 0, 10)) ?></td>
              <td data-label="Actions" class="nowrap">
                <form method="post" onsubmit="return confirm('Delete <?= e($k['code']) ?>?')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_key">
                  <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                  <button class="btn red xs" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php app_foot(); ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('home.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $again = (string)($_POST['confirm'] ?? '');
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));

    if (!preg_match('/^[A-Za-z0-9_.-]{3,24}$/', $name)) {
        $error = 'Username must be 3-24 characters: letters, numbers, . _ -';
    } elseif (!valid_password($pass)) {
        $error = PASSWORD_RULE;
    } elseif ($pass !== $again) {
        $error = 'The two passwords do not match.';
    } else {
        /* A key is required to sign up at all. It is what the account is made
           of: the credits it starts with and how long it lives both come off
           the code, and an account created without one would be a member with
           nothing, no lifetime, and nobody who put them there.

           Looked up before the username check so somebody with a bad code is
           told about the code rather than about a name they cannot use
           anyway. */
        $key = key_lookup($code);
        if (is_string($key)) {
            $error = $key;
        } else {
            $taken = db()->prepare('SELECT 1 FROM users WHERE username = ?');
            $taken->execute([$name]);
            if ($taken->fetch()) {
                $error = 'That username is taken.';
            } else {
                /* Account and redemption in one transaction. An account with
                   no credits because the key filled up between the two, or a
                   use spent on an account that was never created, are both
                   worse than the form simply saying no. */
                $expiry = key_new_expiry(['created_by' => 0, 'expires_at' => null], $key);
                db()->beginTransaction();
                try {
                    /* Everyone who signs up is a member. Roles are given, never
                       taken from the form, and key_bound = 1 is what says this
                       account's clock came from a code - which is what lets the
                       reaper delete it later without touching accounts a seller
                       or an admin set up by hand. */
                    db()->prepare(
                        "INSERT INTO users
                             (username, password_hash, role, credits, expires_at,
                              created_by, key_bound)
                         VALUES (?, ?, 'member', ?, ?, 0, 1)"
                    )->execute([
                        $name,
                        password_hash($pass, PASSWORD_DEFAULT),
                        (int)$key['credits'],
                        $expiry,
                    ]);
                    $newId = (int)db()->lastInsertId();

                    db()->prepare('INSERT INTO key_uses (key_id, user_id) VALUES (?, ?)')
                        ->execute([(int)$key['id'], $newId]);

                    /* The guarded UPDATE is the real limit, not the count read
                       above: two people submitting the last use of a shared
                       code at the same moment both passed that check. */
                    $bump = db()->prepare(
                        'UPDATE keys SET used_count = used_count + 1
                         WHERE id = ? AND used_count < max_uses'
                    );
                    $bump->execute([(int)$key['id']]);
                    if ($bump->rowCount() !== 1) {
                        throw new RuntimeException('key full');
                    }
                    db()->commit();
                } catch (Throwable $ex) {
                    db()->rollBack();
                    $newId = 0;
                    $error = 'That key has just been used up. Nothing was created.';
                }

                if ($newId > 0) {
                    boot_session();
                    session_regenerate_id(true);
                    $_SESSION['uid'] = $newId;
                    flash('Account created with ' . (int)$key['credits'] . ' credit'
                        . ((int)$key['credits'] === 1 ? '' : 's')
                        . ($expiry !== null
                            ? ', access until ' . substr($expiry, 0, 16) . '.'
                            : '. Your access does not expire.'));
                    redirect('home.php');
                }
            }
        }
    }
}

auth_head('Create account');
?>

<h1>Create account</h1>
<p class="lead">You need a key. It carries the credits and the access time.</p>

<?php if ($error): ?>
  <div class="note warn" style="margin-bottom:16px"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="pad">
    <form method="post" data-seal="username,password,confirm,code">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field">
        <label for="u">Username</label>
        <input id="u" name="username" type="text" required autofocus
               autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="p">Password</label>
        <input id="p" name="password" type="password" required
               autocomplete="new-password">
      </div>
      <div class="field">
        <label for="c">Repeat password</label>
        <input id="c" name="confirm" type="password" required
               autocomplete="new-password">
      </div>
      <div class="field">
        <label for="k">Redeem key</label>
        <input id="k" name="code" type="text" required autocomplete="off"
               class="mono" placeholder="GGLP-XXXXX-XXXX"
               value="<?= e($_POST['code'] ?? ($_GET['key'] ?? '')) ?>">
        <p class="hint">
          Required. The key decides how many credits the account starts with
          and how long it lasts - get one from whoever you are buying access
          from.
        </p>
      </div>
      <button class="btn primary block" type="submit" style="margin-top:18px">
        Create account
      </button>
    </form>
  </div>
</div>

<p class="small muted" style="text-align:center;margin-top:18px">
  Already have one? <a href="login.php">Sign in</a>
  <span style="opacity:.4;margin:0 6px">&middot;</span>
  <a href="guide.php">How it works</a>
</p>

<?php auth_foot(); ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('home.php');
}

boot_session();
$error = $_SESSION['blocked'] ?? '';
unset($_SESSION['blocked']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    /* Slow guessing down before anything else touches the database. A sleep
       rather than a lock: an account a stranger can lock by typing the wrong
       password is a denial of service handed to that stranger. */
    $wait = login_delay($name);
    if ($wait > 0) {
        sleep(min(5, $wait));
    }

    /* The challenge is checked before the password, so a script cannot use
       this form to test credentials at all - not even slowly. */
    $challenge = turnstile_check();
    if ($challenge !== null) {
        login_note_failure($name);
        $error = $challenge;
    } else {

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$name]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        /* The same gate the pages use, so sign-in and a page load can never
           disagree about whether an account is usable, or about why. */
        $blocked = access_block($user);
        if ($blocked !== null) {
            $error = $blocked;
        } else {
            login_clear($name);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$user['id'];
            redirect('home.php');
        }
    } else {
        login_note_failure($name);
        /* One message for both halves on purpose: naming which was wrong tells
           anyone probing the form whether a username exists. */
        $error = 'Wrong username or password.';
    }
    }
}

auth_head('Sign in');
?>

<h1>Sign in</h1>
<p class="lead">Your processes and credits are waiting.</p>

<?php if ($error): ?>
  <div class="note warn" style="margin-bottom:16px"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="pad">
    <form method="post" data-seal="username,password">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field">
        <label for="u">Username</label>
        <input id="u" name="username" type="text" required autofocus
               autocomplete="username">
      </div>
      <div class="field">
        <label for="p">Password</label>
        <input id="p" name="password" type="password" required
               autocomplete="current-password">
      </div>
      <?php if (turnstile_enabled()): ?>
        <div class="field" style="margin-top:14px"><?= turnstile_widget() ?></div>
      <?php endif; ?>
      <button class="btn primary block" type="submit" style="margin-top:18px">Sign in</button>
    </form>
  </div>
</div>

<p class="small muted" style="text-align:center;margin-top:18px">
  No account? <a href="register.php">Create one</a>
  <span style="opacity:.4;margin:0 6px">&middot;</span>
  <a href="guide.php">How to set up</a>
</p>

<?php auth_foot(); ?>

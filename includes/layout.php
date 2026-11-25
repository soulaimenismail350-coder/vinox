<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/reaper.php';

function doc_open(string $title): void
{
    send_security_headers();

    /* Every HTML page goes through here, which makes it the one place a
       background pass can be hung off on a machine with no scheduler. It
       throttles itself to about once a minute, so on all the other hits this
       costs one settings read. After the headers rather than before: nothing
       in it prints, but a pass that ever did would otherwise take the security
       headers down with it.

       Deliberately not inside require_login(). An account whose key has run
       out cannot sign in, so it would never trigger its own deletion. */
    reap_expired_accounts();

    /* Seals any credential still sitting in the clear from before there was
       encryption. Guarded by a settings row, so this is one array lookup on
       every request after the first. */
    secrets_migrate();
    ob_start('clean_urls');
    $icon = site_icon();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="robots" content="noindex, nofollow">
<?php $sealKey = request_public_key(); ?>
<?php if ($sealKey !== ''): ?>
<meta name="seal-key" content="<?= e($sealKey) ?>">
<?php endif; ?>
<title><?= e($title) ?> &middot; <?= e(site_name()) ?></title>
<?php if ($icon !== ''): ?>
<link rel="icon" href="<?= e($icon) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
<script>
(function () {
  try {
    var t = localStorage.getItem('gglp-theme');
    if (t === 'dark' || t === 'light') {
      document.documentElement.setAttribute('data-theme', t);
    }
    if (localStorage.getItem('gglp-rail') === 'off') {
      document.documentElement.className += ' rail-off-boot';
    }
  } catch (e) { }
})();
</script>
</head>
<body>
<?php
}

function doc_close(): void
{
    ?>
<script src="<?= e(asset('assets/app.js')) ?>"></script>
<?php /* After app.js, which asks window.GGSeal to submit anything carrying a
         credential - form.submit() fires no submit event, so a listener alone
         would be walked straight past. */ ?>
<script src="<?= e(asset('assets/seal.js')) ?>"></script>
</body>
</html><?php
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

function brand(string $href = 'index.php'): void
{
    $icon = site_icon();
    $name = site_name();
    ?>
    <a class="brand" href="<?= e($href) ?>">
      <span class="mark"><?php if ($icon !== ''): ?><img src="<?= e($icon) ?>" alt=""><?php else: ?><?= e(strtoupper(str_cut($name, 2))) ?><?php endif; ?></span>
      <span class="wordmark"><?= e($name) ?></span>
    </a>
    <?php
}

function theme_button(): void
{
    ?>
    <button class="theme-btn" id="themeBtn" type="button"
            aria-label="Switch between light and dark" title="Theme">
      <span class="moon" aria-hidden="true">&#9789;</span>
      <span class="sun" aria-hidden="true">&#9788;</span>
    </button>
    <?php
}

function mk_head(string $title): void
{
    $user = current_user();
    doc_open($title);
    ?>
<header class="mk-bar">
  <?php brand($user ? 'home.php' : 'index.php'); ?>
  <nav class="mk-nav">
    <a href="guide.php">How to set up</a>
    <?php if ($user): ?>
      <?php theme_button(); ?>
      <a class="btn line sm" href="home.php">Open panel</a>
    <?php else: ?>
      <a href="index.php#plans">Plans</a>
      <a href="login.php">Sign in</a>
      <?php theme_button(); ?>
      <a class="btn blue sm" href="register.php">Create account</a>
    <?php endif; ?>
  </nav>
</header>
<?php
}

function mk_foot(): void
{
    ?>
<footer class="foot">
  <p><?= e(site_name()) ?></p>
  <p>Private server tooling. Sessions run against your own server.</p>
</footer>
<?php
    doc_close();
}

function auth_head(string $title): void
{
    doc_open($title);
    echo '<div class="auth"><div class="auth-box">';
    echo '<div class="auth-brand">';
    brand();
    echo '</div>';
}

function auth_foot(): void
{
    echo '</div></div>';
    doc_close();
}

function rail_link(string $href, string $label, string $key, string $active, ?int $tally = null): void
{
    ?>
    <a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>">
      <span><?= e($label) ?></span>
      <?php if ($tally !== null): ?><span class="tally"><?= $tally ?></span><?php endif; ?>
    </a>
    <?php
}

function app_head(string $title, string $active, array $crumbs = []): void
{
    $user = current_user();
    doc_open($title);

    $counts = db()->prepare('SELECT COUNT(*) c FROM processes WHERE user_id = ?');
    $counts->execute([$user['id']]);
    $procCount = (int)$counts->fetch()['c'];

    /* Scoped, not a count of the table. A seller whose rail says 240 and whose
       Users page lists four has been told something untrue about the panel. */
    $userCount = 0;
    if (is_staff($user)) {
        [$where, $args] = users_scope($user);
        $stmt = db()->prepare('SELECT COUNT(*) c FROM users u WHERE ' . $where);
        $stmt->execute($args);
        $userCount = (int)$stmt->fetch()['c'];
    }
    ?>
<div class="app">
  <aside class="rail" id="rail">
    <div class="rail-top"><?php brand('home.php'); ?></div>

    <nav class="rail-nav">
      <div class="rail-label">Workspace</div>
      <?php rail_link('home.php',      'Home',        'home',   $active); ?>
      <?php rail_link('dashboard.php', 'Processes',   'procs',  $active, $procCount); ?>
      <?php rail_link('add.php',       'Add process', 'add',    $active); ?>
      <?php rail_link('redeem.php',    'Redeem key',  'redeem', $active); ?>

      <?php if (is_staff($user)): ?>
        <div class="rail-label"><?= is_seller($user) ? 'Reselling' : 'Admin panel' ?></div>
        <?php rail_link('admin_users.php', 'Users', 'users', $active, $userCount); ?>
        <?php rail_link('admin_keys.php',  'Keys',  'keys',  $active); ?>
      <?php endif; ?>

      <div class="rail-label">Account</div>
      <a href="logout.php"><span>Sign out</span></a>
    </nav>

    <div class="rail-foot">
      <div class="balance">
        <span class="l">Credits</span>
        <span class="v"><?= (int)$user['credits'] ?></span>
      </div>
      <div class="who">
        <span class="av"><?= e(initial($user['username'])) ?></span>
        <span>
          <span class="n"><?= e($user['username']) ?></span>
          <span class="r"><?= e($user['role']) ?></span>
        </span>
      </div>
    </div>
  </aside>

  <div class="main">
    <div class="main-bar">
      <div class="crumb">
        <button class="rail-btn" id="railBtn" type="button"
                aria-label="Show or hide the menu" aria-expanded="true"><span></span></button>
        <?php foreach ($crumbs as $label => $href): ?>
          <a href="<?= e($href) ?>"><?= e($label) ?></a>
          <span class="sep">/</span>
        <?php endforeach; ?>
        <h1><?= e($title) ?></h1>
      </div>
      <div class="bar-right">
        <?php theme_button(); ?>
      </div>
    </div>

    <div class="main-body">
<?php
    $note = flash();
    if ($note !== null) {
        echo '<div class="flash">' . e($note) . '</div>';
    }
}

function app_foot(): void
{
    ?>
    </div>
  </div>
</div>
<?php
    doc_close();
}

function admin_tabs(string $active): void
{
    /* Both pages are open to every tier of staff. What a seller can do inside
       them is narrower - no suspending, no deleting, and a key costs them the
       credits it promises - but that is decided on the page, not by hiding the
       way in. */
    $tabs = [
        'users' => ['admin_users.php', 'Users'],
        'keys'  => ['admin_keys.php',  'Keys'],
    ];
    echo '<div class="tabs">';
    foreach ($tabs as $key => [$href, $label]) {
        printf('<a href="%s" class="%s">%s</a>', e($href), $key === $active ? 'on' : '', e($label));
    }
    echo '</div>';
}

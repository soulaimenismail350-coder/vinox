<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

if ($uri === '' || $uri === '/') {
    require __DIR__ . '/landing.php';
    exit;
}

if (substr($uri, -4) === '.php') {
    $clean = substr($uri, 0, -4);
    header('Location: ' . $clean, true, 301);
    exit;
}

$redirects = [
    '/keys'     => '/admin/keys',
    '/users'    => '/admin/users',
    '/settings' => '/admin/settings',
    '/admin'    => '/admin/users',
    '/admin/'   => '/admin/users',
];
if (isset($redirects[$uri])) {
    header('Location: ' . $redirects[$uri], true, 301);
    exit;
}

$routes = [
    '/login'        => 'login.php',
    '/register'     => 'register.php',
    '/home'         => 'home.php',
    '/dashboard'    => 'dashboard.php',
    '/process'      => 'process.php',
    '/redeem'       => 'redeem.php',
    '/guide'        => 'guide.php',
    '/logout'       => 'logout.php',
    '/add'          => 'add.php',
    '/api'          => 'api.php',
    '/cfg'          => 'cfg.php',
    '/admin/users'      => 'admin_users.php',
    '/admin/keys'       => 'admin_keys.php',
    '/admin/user'       => 'admin_user.php',
    '/admin/settings'   => 'admin_settings.php',
    '/admin_users'      => 'admin_users.php',
    '/admin_keys'       => 'admin_keys.php',
    '/admin_user'       => 'admin_user.php',
    '/admin_settings'   => 'admin_settings.php',
];

if (isset($routes[$uri])) {
    require __DIR__ . '/' . $routes[$uri];
    exit;
}

header('Location: /');
exit;

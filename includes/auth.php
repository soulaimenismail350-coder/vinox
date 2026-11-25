<?php
declare(strict_types=1);

require_once __DIR__ . '/../cfg.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

/* Strip the extension from every internal link on the way out.
 *
 * Done once here rather than by editing sixty href attributes, and it cannot
 * miss one: whatever a template writes, the browser only ever sees the clean
 * path. Under nginx with a rewrite rule those paths resolve on their own, and
 * redirect() below does the same for Location headers - but only where such a
 * rule actually exists. Without one - php -S, a subfolder, a plain vhost with
 * no rewrite - a stripped ".php" is a straight 404, so this only runs when
 * clean_urls_enabled() says a rewrite is in place. Otherwise the .php stays on
 * the link and the page keeps working everywhere, which matters more than a
 * tidy address bar.
 *
 * Only bare relative targets are touched - anything with a scheme, a leading
 * slash or a query already in it is left exactly as written. */
function clean_urls(string $html): string
{
    if (!clean_urls_enabled()) {
        return $html;
    }
    return (string)preg_replace(
        '~\b(href|action)="(?!https?:|//|/)([A-Za-z0-9_.\-]+)\.php(?=["#?])~',
        '$1="$2',
        $html
    );
}

/* Where the site actually lives, as a URL path prefix ending in nothing (root)
 * or "/sub/folder" (mounted deeper). Every absolute reference this file builds
 * - assets, the favicon, redirects - goes through this instead of a literal
 * leading slash, because a literal "/assets/style.css" only works when the
 * site is mounted at exactly the domain root. The moment it is not - php -S
 * serving from a project folder, a subfolder under an existing Apache/nginx
 * vhost, XAMPP's default docroot - that request 404s with nothing on the page
 * to explain why, and the whole design silently disappears. That is the exact
 * failure this fixes.
 *
 * Derived from SCRIPT_NAME, which every SAPI (built-in server, PHP-FPM,
 * mod_php) sets to the real filesystem-relative URL of the running script,
 * unaffected by any later rewrite. */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));
    $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $base;
}

/* An absolute path for something under the site root, correct whether the
 * site sits at the domain root or several folders deep:
 *   asset('/assets/style.css')  ->  /style.css or /sub/folder/assets/style.css
 *   asset('login.php')          ->  /login.php  or /sub/folder/login.php
 */
function asset(string $path): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/* Clean URLs (no .php in the address bar) need a server rewrite rule to work
 * at all - see clean_urls() above. Rather than guess whether one exists, this
 * is an explicit switch: true only when CLEAN_URLS is defined as true in
 * cfg.php, which a real nginx/Apache setup with the rewrite rule in place
 * should do deliberately. Left undefined - the common case, including anyone
 * who has not touched cfg.php - links keep their .php and every page works
 * with zero server configuration. */
function clean_urls_enabled(): bool
{
    return defined('CLEAN_URLS') && CLEAN_URLS === true;
}

function boot_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        /* Secure only when the request actually arrived over TLS: setting it
           unconditionally would make the cookie unusable on a plain-HTTP
           install and lock everyone out. */
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
            'path'     => '/',
        ]);
        session_start();
    }
}

/* Sent on every HTML response, before any output.

   nosniff stops a browser guessing a content type it was not given.
   DENY blocks the panel being framed, which is what a clickjacking overlay
   needs. The CSP is deliberately strict on scripts: the panel serves one JS
   file of its own and needs one inline block for the theme, so 'self' plus
   that one hash would be tighter still - but the theme block changes with the
   file, and a CSP that breaks the page the first time it is edited gets turned
   off rather than fixed. 'unsafe-inline' for style covers the small number of
   inline style attributes in the templates. */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self' https://challenges.cloudflare.com; "
        . "frame-src https://challenges.cloudflare.com; "
        . "form-action 'self'; "
        . "base-uri 'none'; "
        . "frame-ancestors 'none'"
    );
}

/* Is data/ actually being served?
 *
 * The whole panel assumes a web server rule keeps that folder private - see
 * deploy/nginx-security.conf. Nothing in PHP can enforce that, because the
 * server answers a static file before PHP is consulted, so the only honest
 * thing the panel can do is go and look: ask itself for the database over
 * HTTP, the way a stranger would, and tell the owner what came back.
 *
 * Cached for six hours and only ever run for an owner looking at their own
 * Users page, so a site behind a proxy that blocks loopback pays for one slow
 * request twice a day rather than on every hit. Anything other than a clear
 * 200 is treated as "fine": a check that cried wolf on a timeout would be
 * turned off within a week, and a false alarm here is worse than silence.
 *
 * Returns true only when the database really did come back over the wire.
 */
function data_dir_exposed(): bool
{
    $cached = setting('exposure_checked_at');
    if ($cached !== '' && time() - (int)$cached < 21600) {
        return setting('exposure_open') === '1';
    }
    setting_set('exposure_checked_at', (string)time());
    setting_set('exposure_open', '0');

    $base = trim(SITE_URL);
    if ($base === '' || !preg_match('~^https?://~i', $base)) {
        return false;
    }
    $url = rtrim($base, '/') . '/data/' . basename(DB_PATH);

    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'HEAD',
            'timeout'       => 4,
            'ignore_errors' => true,
        ]]);
        $headers = @get_headers($url, false, $ctx);
        if (is_array($headers) && isset($headers[0])
            && preg_match('~\s(\d{3})\s~', ' ' . $headers[0] . ' ', $m) === 1) {
            $code = (int)$m[1];
        }
    }

    if ($code === 200) {
        setting_set('exposure_open', '1');
        error_log('gglp: data/ is being served over HTTP - '
            . 'apply deploy/nginx-security.conf');
        return true;
    }
    return false;
}

function current_user(): ?array
{
    boot_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/* --- Roles ------------------------------------------------------------------
 *
 * Four of them, ordered, and every question about who may touch whom is
 * derived from this one ladder rather than from a list of special cases:
 *
 *     owner   3   the whole panel: every account, every process, every key
 *     admin   2   the accounts they created, and keys
 *     seller  1   the accounts they created, and pays for their credits
 *     member  0   their own processes and nothing else
 *
 * The rule that falls out of it is short enough to hold in your head: you may
 * act on an account below you that you created, and never on one at your own
 * level. That second half is the point - it is why one owner cannot reset
 * another owner's password. A panel where any owner can lock every other owner
 * out has no top at all, only whoever clicked first.
 */
const ROLES = ['member' => 0, 'seller' => 1, 'admin' => 2, 'owner' => 3];

function role_rank(?string $role): int
{
    return ROLES[(string)$role] ?? 0;
}

function user_rank(?array $user): int
{
    /* -1 rather than 0 for a signed-out visitor, so nothing accidentally
       compares equal to a member. */
    return $user === null ? -1 : role_rank((string)($user['role'] ?? ''));
}

function is_owner(?array $user): bool
{
    return user_rank($user) === ROLES['owner'];
}

function is_seller(?array $user): bool
{
    return user_rank($user) === ROLES['seller'];
}

/* Anyone with a panel of their own: owner, admin or seller. */
function is_staff(?array $user): bool
{
    return user_rank($user) >= ROLES['seller'];
}

/* Kept under its old name because it still answers the old question - may this
   person mint keys and see how the machine is set up - which is now owner and
   admin, and deliberately not seller. A seller who could mint keys would be
   printing the credits they are supposed to be buying. */
function is_admin(?array $user): bool
{
    return user_rank($user) >= ROLES['admin'];
}

/* The CSS class for a role tag. Only .blue, .go and .red exist, so this maps
   four roles onto what the stylesheet actually has rather than inventing a
   colour per role and leaving one of them unstyled. */
function role_tag(string $role): string
{
    if ($role === 'owner')  { return 'red'; }
    if ($role === 'admin')  { return 'blue'; }
    if ($role === 'seller') { return 'go'; }
    return '';
}

/* May $me change $target?
 *
 * Self is always true so the account page renders for whoever opened it; the
 * individual actions that must never apply to yourself - role, suspension,
 * deletion - refuse on their own further down, because "may I open this page"
 * and "may I delete this" are not the same question.
 *
 * Note this is deliberately not "does $me outrank $target". An admin outranks
 * every member on the site and may still only manage the ones they made.
 */
function can_manage_user(array $me, array $target): bool
{
    if ((int)$me['id'] === (int)$target['id']) {
        return true;
    }
    if (user_rank($me) <= user_rank($target)) {
        return false;
    }
    if (is_owner($me)) {
        return true;
    }
    return (int)($target['created_by'] ?? 0) === (int)$me['id'];
}

/* The roles $me may hand out.
 *
 * An owner may make another owner - a site with one owner and no way to add a
 * second is one forgotten password away from being unadministrable - but see
 * can_manage_user(): the moment that account exists its maker cannot edit it
 * either. Everyone else may only create below themselves, so nobody can
 * promote their way sideways into a peer they would then be unable to undo.
 */
function creatable_roles(array $me): array
{
    $out = [];
    foreach (ROLES as $name => $rank) {
        if ($rank < user_rank($me)) {
            $out[] = $name;
        }
    }
    if (is_owner($me)) {
        $out[] = 'owner';
    }
    return $out;
}

/* The accounts $user is allowed to see listed.
 *
 * Returned as an SQL fragment and its parameters rather than a finished query,
 * so the user table, the six totals above it and the "running right now" panel
 * all narrow through the same clause instead of three that drift apart the
 * first time one of them is edited.
 */
function users_scope(array $user, string $alias = 'u'): array
{
    if (is_owner($user)) {
        return ['1 = 1', []];
    }
    return [
        '(' . $alias . '.created_by = ? OR ' . $alias . '.id = ?)',
        [(int)$user['id'], (int)$user['id']],
    ];
}

/* The same question for processes. Staff reach a process through the account
   that owns it, so this is users_scope() one join further out. */
function processes_scope(array $user, string $alias = 'p'): array
{
    if (is_owner($user)) {
        return ['1 = 1', []];
    }
    if (is_staff($user)) {
        return [
            $alias . '.user_id IN (SELECT id FROM users WHERE created_by = ? OR id = ?)',
            [(int)$user['id'], (int)$user['id']],
        ];
    }
    return [$alias . '.user_id = ?', [(int)$user['id']]];
}

/* --- Credits ----------------------------------------------------------------
 *
 * Moving credits to a customer.
 *
 * An owner or an admin mints them: the number in the box is simply the new
 * balance, out of nothing. A seller does not - a seller is reselling, so every
 * credit handed out comes off their own balance and every one taken back goes
 * straight back to it. That is the entire difference between the two roles.
 *
 * One transaction, and the seller's balance is read inside it rather than
 * trusted from the copy loaded at the top of the request: two tabs open on two
 * different customers would otherwise both check against the same stale number
 * and spend the same credits twice.
 *
 * Returns null when it worked, or the sentence to show.
 */
function set_user_credits(array $me, array $target, int $credits): ?string
{
    $credits = max(0, min(100000, $credits));
    $delta = $credits - (int)$target['credits'];

    if ($delta === 0) {
        return null;
    }

    /* A seller's own balance is stock they were given, not something they set.
       The two statements below happen to cancel out when $me and $target are
       the same row, so this changes no behaviour - it says so out loud instead
       of leaving the guarantee resting on that arithmetic. */
    if (is_seller($me) && (int)$me['id'] === (int)$target['id']) {
        return 'Your own balance comes from the panel owner, not from this form.';
    }

    /* A seller's credits only ever go one way.
     *
     * Taking them back looks symmetrical and is not, because a credit is spent
     * the moment it becomes a process: give a customer ten, watch them turn
     * ten into ten running accounts, then set the balance to zero and be
     * refunded ten you no longer own. Repeat. The customer keeps everything
     * and the seller's stock never goes down, which is a printing press rather
     * than a shop.
     *
     * There is no version of this that both refunds safely and is worth the
     * complexity - counting what a customer has spent means auditing every
     * process they ever removed - so a sale is simply final, the way a sale
     * is. An owner or an admin can still set any balance to anything, because
     * they mint rather than resell and have nothing to gain by it.
     */
    if (is_seller($me) && $delta < 0) {
        return 'Credits you have given cannot be taken back. Set '
             . (int)$target['credits'] . ' or more.';
    }

    if (!is_seller($me)) {
        db()->prepare('UPDATE users SET credits = ? WHERE id = ?')
            ->execute([$credits, (int)$target['id']]);
        return null;
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT credits FROM users WHERE id = ?');
        $stmt->execute([(int)$me['id']]);
        $purse = (int)$stmt->fetch()['credits'];

        if ($delta > $purse) {
            db()->rollBack();
            return 'That needs ' . $delta . ' credit' . ($delta === 1 ? '' : 's')
                 . ' out of your balance and you have ' . $purse . '. Nothing changed.';
        }

        db()->prepare('UPDATE users SET credits = ? WHERE id = ?')
            ->execute([$credits, (int)$target['id']]);
        /* Minus a negative gives them back, so taking credits away from a
           customer refunds the seller through the same statement. */
        db()->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')
            ->execute([$delta, (int)$me['id']]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        return 'Could not move those credits. Nothing changed.';
    }
    return null;
}

/* --- Redeem keys ------------------------------------------------------------
 *
 * A key carries three numbers: how many credits one redemption gives, how many
 * different people may redeem it, and how long the account that redeems it gets
 * to live. The lifetime is a duration rather than a date, counted from the
 * moment of redemption - ten people redeeming one code on ten different days
 * each get their own full month out of it, which is what anyone selling access
 * actually means by "a one-month key".
 */
const KEY_UNITS = [
    'hour'  => 3600,
    'day'   => 86400,
    'week'  => 604800,
    'month' => 2592000,
    'year'  => 31536000,
];

function duration_seconds(int $amount, string $unit): int
{
    if ($amount <= 0 || !isset(KEY_UNITS[$unit])) {
        return 0;
    }
    /* Ten years is already far past "forever" for a key, and it keeps the
       arithmetic away from the 32-bit timestamp cliff on a 32-bit build. */
    return min($amount * KEY_UNITS[$unit], 10 * KEY_UNITS['year']);
}

/* Seconds back into words, for the key table. The largest unit that divides
   evenly, so 86400 reads as "1 day" rather than "24 hours" and 90000 - which
   nothing in the form can produce but a hand-edited row can - still reads as
   something rather than breaking the column. */
function duration_label(int $seconds): string
{
    if ($seconds <= 0) {
        return 'no expiry';
    }
    foreach (array_reverse(KEY_UNITS, true) as $unit => $size) {
        if ($seconds >= $size && $seconds % $size === 0) {
            $n = intdiv($seconds, $size);
            return $n . ' ' . $unit . ($n === 1 ? '' : 's');
        }
    }
    return max(1, intdiv($seconds, 60)) . ' min';
}

/* What a key does to an account's lifetime.
 *
 * Nothing at all when the account was made by a member of staff. An owner, an
 * admin or a seller set that expiry deliberately when they handed the account
 * over, and a code redeemed afterwards is not allowed to overwrite it - that
 * is the exception the whole feature was asked for with.
 *
 * Otherwise the clock starts now, or carries on from an expiry still in the
 * future: redeeming a second month should add a month rather than throw away
 * what is left of the first.
 *
 * Returns the new expires_at, or null meaning leave the column alone. Written
 * with date() rather than gmdate() because access_block() reads it back with
 * strtotime(), and a panel where the two disagree by the server's offset would
 * lock people out hours early.
 */
function key_new_expiry(array $user, array $key): ?string
{
    $seconds = (int)($key['valid_seconds'] ?? 0);
    if ($seconds <= 0 || (int)($user['created_by'] ?? 0) > 0) {
        return null;
    }
    $from = time();
    $current = (string)($user['expires_at'] ?? '');
    if ($current !== '' && strtotime($current) > $from) {
        $from = strtotime($current);
    }
    return date('Y-m-d H:i:s', $from + $seconds);
}

/* Find a code and say whether it can be used at all. Returns the row, or the
   sentence to show. Deliberately one function for both surfaces - the signup
   form and the redeem page - so a full key cannot be worth trying twice
   because the two pages disagreed about what "full" means. */
function key_lookup(string $code): array|string
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return 'Enter a key code.';
    }
    $stmt = db()->prepare('SELECT * FROM keys WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'That key does not exist. Check for a typo - codes never contain O, 0, I or 1.';
    }
    if ((int)$row['used_count'] >= (int)$row['max_uses']) {
        return 'That key has reached its limit.';
    }
    return $row;
}

/* Spend one use of a key on an account that already exists.
 *
 * The UNIQUE(key_id,user_id) index is the real guard against a double
 * redemption and the guarded UPDATE is the real guard against overrunning
 * max_uses; checking either in PHP first would let two tabs racing each other
 * both pass. Returns null, or the sentence to show.
 */
function key_redeem(array $user, array $key): ?string
{
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO key_uses (key_id, user_id) VALUES (?, ?)')
            ->execute([(int)$key['id'], (int)$user['id']]);

        $bump = db()->prepare(
            'UPDATE keys SET used_count = used_count + 1
             WHERE id = ? AND used_count < max_uses'
        );
        $bump->execute([(int)$key['id']]);
        if ($bump->rowCount() !== 1) {
            throw new RuntimeException('key full');
        }

        $expiry = key_new_expiry($user, $key);
        if ($expiry !== null) {
            db()->prepare(
                'UPDATE users SET credits = credits + ?, expires_at = ?, key_bound = 1
                 WHERE id = ?'
            )->execute([(int)$key['credits'], $expiry, (int)$user['id']]);
        } else {
            db()->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
                ->execute([(int)$key['credits'], (int)$user['id']]);
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        return 'You have already used that key, or it has just filled up.';
    }
    return null;
}

/* Create an account on behalf of a staff member.
 *
 * created_by is stamped here and nowhere else, because it is what every later
 * permission check reads: an account with the wrong maker is either invisible
 * to the person who made it or editable by someone who should not see it.
 *
 * A seller pays for the starting credits in the same transaction as the
 * INSERT, or a failure between the two hands out credits nobody paid for.
 *
 * Returns null when it worked, or the sentence to show.
 */
function create_managed_user(
    array   $me,
    string  $name,
    string  $pass,
    string  $role,
    int     $credits,
    ?string $expires
): ?string {
    $credits = max(0, min(100000, $credits));

    db()->beginTransaction();
    try {
        if (is_seller($me)) {
            $stmt = db()->prepare('SELECT credits FROM users WHERE id = ?');
            $stmt->execute([(int)$me['id']]);
            $purse = (int)$stmt->fetch()['credits'];
            if ($credits > $purse) {
                db()->rollBack();
                return 'You have ' . $purse . ' credit' . ($purse === 1 ? '' : 's')
                     . ', so you cannot start an account with ' . $credits . '.';
            }
            db()->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')
                ->execute([$credits, (int)$me['id']]);
        }

        db()->prepare(
            'INSERT INTO users (username, password_hash, role, credits, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $name,
            password_hash($pass, PASSWORD_DEFAULT),
            $role,
            $credits,
            $expires,
            (int)$me['id'],
        ]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        return 'Could not create that account.';
    }
    return null;
}

function account_expired(array $user): bool
{
    if (empty($user['expires_at'])) {
        return false;
    }
    return strtotime((string)$user['expires_at']) < time();
}

/* One place decides whether an account may be used, and it returns the exact
   sentence the user should read. Sign-in and every page load call the same
   function, so the two can never disagree about what "suspended" means. */
function access_block(array $user): ?string
{
    if ((int)$user['banned'] === 1) {
        $until = (string)($user['banned_until'] ?? '');

        /* A temporary suspension lifts itself. Waiting for an admin to come
           back and clear it by hand would make the end date a lie. */
        if ($until !== '' && strtotime($until) <= time()) {
            db()->prepare(
                "UPDATE users SET banned = 0, ban_reason = '', banned_at = NULL,
                        banned_until = NULL WHERE id = ?"
            )->execute([(int)$user['id']]);
        } else {
            $head = $until !== ''
                ? 'Account suspended until ' . substr($until, 0, 16)
                : 'Account suspended';
            $reason = trim((string)$user['ban_reason']);
            return $reason !== '' ? $head . ': ' . $reason : $head . '.';
        }
    }

    if (account_expired($user)) {
        return 'Access expired on ' . substr((string)$user['expires_at'], 0, 10) . '.';
    }

    return null;
}

/* Every page that needs a signed-in user goes through here, so a ban or an
   expiry takes effect on the next click rather than at the next login. A
   check that only runs at sign-in leaves an open tab working for hours. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    $blocked = access_block($user);
    if ($blocked !== null) {
        boot_session();
        $_SESSION['blocked'] = $blocked;
        unset($_SESSION['uid']);
        redirect('login.php');
    }
    return $user;
}

/* Two gates, because only two pages divide on the way in: everything under the
   Admin panel is open to any tier of staff, and Settings is the owner's alone.
   The difference between a seller and an admin is decided inside the pages -
   with is_admin() - rather than at the door, because they are looking at the
   same accounts and the same keys and only some of the controls differ. */
function require_staff(): array
{
    $user = require_login();
    if (!is_staff($user)) {
        http_response_code(403);
        exit('Not allowed.');
    }
    return $user;
}

function require_owner(): array
{
    $user = require_login();
    if (!is_owner($user)) {
        http_response_code(403);
        exit('Not allowed.');
    }
    return $user;
}

/* --- One-time request tokens ------------------------------------------------
 *
 * The hidden "csrf" field every form in the panel already carries is now a
 * nonce. It is minted when a page is drawn, recorded against the session, and
 * destroyed the instant it comes back. The token that authorised a request no
 * longer exists by the time anyone could replay it, so a captured POST - the
 * same Add process, the same Suspend, the same Delete - works exactly once.
 *
 * That is the difference from what was here before: a session-long token
 * proves the request came from your browser, and says nothing at all about
 * whether it has already been acted on.
 *
 * One nonce per page render, not one per form. The dashboard draws four
 * buttons per process card; at twenty processes that is eighty forms, and a
 * token each would evict one another out of any sane cap and turn the back
 * button into a lottery. Every POST here ends in a redirect, so the page that
 * follows is drawn fresh and carries a new one.
 *
 * Held in the session rather than a table: this is per-person state that
 * should die with the session, and it needs no cleanup job to stop it growing.
 */
const NONCE_TTL = 7200;
const NONCE_KEEP = 30;

function csrf_token(): string
{
    /* Static, so every form on one page shares the render's nonce. */
    static $issued = null;
    if ($issued !== null) {
        return $issued;
    }

    boot_session();
    $now = time();
    $pool = is_array($_SESSION['nonces'] ?? null) ? $_SESSION['nonces'] : [];

    foreach ($pool as $token => $at) {
        if ($now - (int)$at > NONCE_TTL) {
            unset($pool[$token]);
        }
    }

    $issued = bin2hex(random_bytes(16));
    $pool[$issued] = $now;

    /* A cap, because a person leaving thirty tabs open should not be able to
       grow their own session record without limit. Oldest first. */
    if (count($pool) > NONCE_KEEP) {
        $pool = array_slice($pool, -NONCE_KEEP, null, true);
    }

    $_SESSION['nonces'] = $pool;
    return $issued;
}

function check_csrf(): void
{
    /* First, because everything below - and every page after it - reads
       $_POST, and a sealed body has to be opened before any of it is there. */
    unseal_request();

    boot_session();
    $sent = (string)($_POST['csrf'] ?? '');
    $now = time();
    $pool = is_array($_SESSION['nonces'] ?? null) ? $_SESSION['nonces'] : [];

    $ok = false;
    foreach ($pool as $token => $at) {
        if (hash_equals($token, $sent) && $now - (int)$at <= NONCE_TTL) {
            $ok = true;
            break;
        }
    }

    if (!$ok) {
        http_response_code(400);
        /* Said in words rather than as "Bad request", because the honest
           common cause is not an attack: it is the back button, a second tab,
           or a form left open overnight. */
        exit('This form has already been used or has expired. '
            . 'Reload the page and try again.');
    }

    /* Spent. This is the whole point - the same body posted twice gets past
       here once. */
    unset($pool[$sent]);
    $_SESSION['nonces'] = $pool;
}

function flash(string $message = null): ?string
{
    boot_session();
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $out = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $out;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Cut a string to a length without splitting a multi-byte character in half.

   mbstring is NOT assumed. On Windows `extension=mbstring` ships commented
   out in php.ini, and `php -S` with no php.ini at all has none of it - so
   calling mb_substr() there is a fatal error that kills the page halfway
   through rendering. PCRE with the /u flag is always compiled in, so it is
   the safe way to be UTF-8 aware. */
function str_cut(string $value, int $length): string
{
    if ($length <= 0 || $value === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    if (preg_match('/^.{0,' . $length . '}/us', $value, $m) === 1) {
        return $m[0];
    }
    /* Invalid UTF-8 reached the regex. Cut on bytes and drop any trailing
       continuation bytes so the result is still valid. */
    return rtrim(substr($value, 0, $length), "\x80..\xBF");
}

/* First character of a name, for the avatar square. */
function initial(string $value): string
{
    $first = str_cut(trim($value), 1);
    return $first !== '' ? $first : '?';
}

function redirect(string $to): void
{
    /* A bare relative target ('login.php', 'home.php?x=1') is resolved against
       base_path() so the redirect lands correctly whether the site is at the
       domain root or three folders deep - a plain "Location: login.php" is
       relative to the CURRENT URL per RFC 3986, not to the site root, and can
       land somewhere wrong once any page is nested (e.g. process.php?id=5
       redirecting to "login.php" would resolve next to itself, which happens
       to work at the root but not once query-heavy paths are involved). An
       already-absolute or fully-qualified target is left untouched. */
    if (!preg_match('~^(https?:)?//~i', $to) && ($to === '' || $to[0] !== '/')) {
        $to = asset($to);
    }
    if (clean_urls_enabled()) {
        $to = (string)preg_replace('~/([A-Za-z0-9_.\-]+)\.php(?=$|[#?])~', '/$1', $to);
    }
    header('Location: ' . $to);
    exit;
}

/* The one place that decides whether this person may act on this process.
   Every surface goes through it - the dashboard card, the process page and
   proc_dispatch() - so an owner reaches everything, staff reach what belongs
   to the accounts they created, and a member reaches only their own. */
function owned_process(int $id, array $user): ?array
{
    [$where, $args] = processes_scope($user);
    $stmt = db()->prepare('SELECT p.* FROM processes p WHERE p.id = ? AND ' . $where);
    $stmt->execute(array_merge([$id], $args));
    $row = $stmt->fetch();
    return $row ?: null;
}

function percent(int $done, int $target): int
{
    return min(100, (int)round($done / max(1, $target) * 100));
}

/* type="date" is a browser convenience, not a guarantee. An unparseable value
   stored as an expiry would read as "already expired" and lock an account out
   with no reason attached, which is exactly what suspension exists to avoid. */
function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function valid_username(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.-]{3,24}$/', $name);
}

/* Panel passwords: letters and digits, 6 to 72.
 *
 * 72 is bcrypt's limit - password_hash() silently truncates past it, so a
 * longer one would be accepted at signup and half-checked forever after.
 *
 * The alphanumeric rule is a deliberate product decision rather than a
 * security one, and it cuts both ways: it is one less thing to get wrong when
 * a password is read down a phone or retyped from paper, and it is a smaller
 * alphabet than allowing punctuation would give. To take the restriction off,
 * this one regex is the only place it lives. */
function valid_password(string $pass): bool
{
    return (bool)preg_match('/^[A-Za-z0-9]{6,72}$/', $pass);
}

const PASSWORD_RULE = 'A password is 6 to 72 letters and digits, with nothing else in it.';

function username_taken(string $name, int $exceptId = 0): bool
{
    $stmt = db()->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
    $stmt->execute([$name, $exceptId]);
    return (bool)$stmt->fetch();
}

/* Sign-in throttle.

   Counted per username and per address together, so one attacker cannot spread
   a guessing run across usernames to stay under a per-name limit, and a shared
   office address cannot lock out everyone behind it by one person mistyping.

   The delay is what makes guessing impractical rather than a hard lock: an
   account that can be locked by a stranger typing the wrong password is a
   denial of service handed to that stranger. */
const LOGIN_WINDOW = 900;
const LOGIN_FREE_TRIES = 5;
const LOGIN_MAX_DELAY = 30;

/* Real client address.
 *
 * Behind Cloudflare, REMOTE_ADDR is Cloudflare. nginx is configured by
 * deploy.sh with set_real_ip_from over the published Cloudflare ranges plus
 * real_ip_header CF-Connecting-IP, so REMOTE_ADDR is already correct by the
 * time PHP sees it - which is the only safe way to do this. The header is
 * deliberately NOT read here: a client can send CF-Connecting-IP itself, and
 * trusting it would hand every attacker a fresh identity per request and make
 * the sign-in throttle useless. */
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function login_key(string $username): string
{
    return substr(hash('sha256', strtolower($username) . '|' . client_ip()), 0, 32);
}

function login_attempts(string $username): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) c FROM login_attempts
         WHERE key = ? AND at > datetime('now', ?)"
    );
    $stmt->execute([login_key($username), '-' . LOGIN_WINDOW . ' seconds']);
    return (int)$stmt->fetch()['c'];
}

function login_note_failure(string $username): void
{
    db()->prepare('INSERT INTO login_attempts (key) VALUES (?)')
        ->execute([login_key($username)]);
    /* Trimmed here rather than on a schedule, because there is no scheduler. */
    db()->exec("DELETE FROM login_attempts WHERE at < datetime('now', '-1 day')");
}

function login_clear(string $username): void
{
    db()->prepare('DELETE FROM login_attempts WHERE key = ?')
        ->execute([login_key($username)]);
}

/* Returns the seconds a caller should be made to wait, 0 when it is free. */
function login_delay(string $username): int
{
    $tries = login_attempts($username);
    if ($tries < LOGIN_FREE_TRIES) {
        return 0;
    }
    return (int)min(LOGIN_MAX_DELAY, 2 ** min(10, $tries - LOGIN_FREE_TRIES + 1));
}

/* --- Cloudflare Turnstile ---------------------------------------------------
 *
 * Verified server side, always. The widget on the page proves nothing on its
 * own: the token has to be exchanged with Cloudflare, and a token is valid
 * once. Failing closed matters here - a network error must not become a way
 * to skip the check by cutting the connection at the right moment.
 */
function turnstile_widget(): string
{
    if (!turnstile_enabled()) {
        return '';
    }
    return '<div class="cf-turnstile" data-sitekey="'
        . e(TURNSTILE_SITE_KEY) . '" data-theme="auto"></div>';
}

function turnstile_script(): string
{
    if (!turnstile_enabled()) {
        return '';
    }
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"'
        . ' async defer></script>';
}

/* Null when the challenge passed or is switched off, otherwise the sentence to
   show the user. */
function turnstile_check(): ?string
{
    if (!turnstile_enabled()) {
        return null;
    }
    $token = (string)($_POST['cf-turnstile-response'] ?? '');
    if ($token === '') {
        return 'Complete the challenge and try again.';
    }

    $post = http_build_query([
        'secret'   => TURNSTILE_SECRET,
        'response' => $token,
        'remoteip' => client_ip(),
    ]);

    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $post,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    }

    if ($body === false) {
        error_log('gglp: turnstile verification unreachable');
        return 'The challenge could not be verified. Try again in a moment.';
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data) || empty($data['success'])) {
        $codes = is_array($data) ? implode(',', (array)($data['error-codes'] ?? [])) : 'bad reply';
        error_log('gglp: turnstile rejected (' . $codes . ')');
        return 'The challenge did not pass. Try again.';
    }
    return null;
}

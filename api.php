<?php
declare(strict_types=1);

/* Read-only endpoint for the dashboard poller, plus the write endpoints the
   worker uses to report back. Kept apart from the pages so nothing here ever
   emits HTML. */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/runtime.php';
require_once __DIR__ . '/includes/runner.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $payload, int $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$do = (string)($_GET['do'] ?? '');

/* ---------- dashboard poller ---------- */

if ($do === 'progress') {
    $user = current_user();
    if (!$user) {
        out(['error' => 'not signed in'], 401);
    }
    /* The same suspension and expiry gate the pages use. Without it an already
       open tab keeps polling for hours after an account is suspended. */
    $blocked = access_block($user);
    if ($blocked !== null) {
        out(['error' => 'blocked', 'message' => $blocked], 403);
    }

    /* Same reconciliation the pages do, so a tab left open sees a run finish
       or die without anyone touching the page. */
    runner_sync_user((int)$user['id']);

    $stmt = db()->prepare(
        'SELECT id, account_name, account_id, region, status, message,
                level_start, level_current, xp_start, xp_current,
                matches_done, target_matches, unlimited,
                auth_type, token_exp
         FROM processes WHERE user_id = ? ORDER BY id DESC'
    );
    $stmt->execute([$user['id']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $xpStart = (int)$r['xp_start'];
        $xpNow   = (int)$r['xp_current'];
        $rows[] = [
            'id'             => (int)$r['id'],
            'account_name'   => (string)$r['account_name'],
            'account_id'     => (string)$r['account_id'],
            'region'         => (string)$r['region'],
            'status'         => (string)$r['status'],
            'message'        => (string)$r['message'],
            'level_start'    => (int)$r['level_start'],
            'level_current'  => (int)$r['level_current'],
            'xp_start'       => $xpStart,
            'xp_current'     => $xpNow,
            /* Computed here so the two surfaces cannot disagree about what
               "gained" means, and clamped because a fresh run reports its xp
               before it has a baseline to subtract. */
            'xp_gained'      => ($xpStart > 0 && $xpNow > 0) ? max(0, $xpNow - $xpStart) : 0,
            'matches_done'   => (int)$r['matches_done'],
            'target_matches' => (int)$r['target_matches'],
            'unlimited'      => (int)$r['unlimited'] === 1,
            'auth_type'      => (string)$r['auth_type'],
            'token_exp'      => (int)$r['token_exp'],
        ];
    }
    out(['processes' => $rows, 'now' => time()]);
}

/* ---------- worker endpoints ----------

   The worker asks for what to run and reports what happened. It carries a
   shared secret rather than a session, since it is not a browser. Put the
   secret in data/worker_token.txt; without that file these endpoints stay
   closed. */

function worker_ok(): bool
{
    $file = __DIR__ . '/data/worker_token.txt';
    if (!is_readable($file)) {
        return false;
    }
    $want = trim((string)file_get_contents($file));
    if ($want === '') {
        return false;
    }
    $sent = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? '';
    return is_string($sent) && hash_equals($want, $sent);
}

if ($do === 'claim' || $do === 'report') {
    if (!worker_ok()) {
        out(['error' => 'worker token rejected'], 403);
    }
}

/* Hand the worker every process whose owner has asked for a change of state.

   'starting' and 'stopping' have to be in here. The panel writes intent and
   only the worker writes 'running' or 'idle', so a query for 'running' alone
   would never return a freshly started process - nothing would ever begin -
   and a stop request would never reach the worker at all.

   Credentials go out on this endpoint, which is why it is token-gated and
   should only ever be reachable from the machine running the worker. */
if ($do === 'claim') {
    $stmt = db()->query(
        "SELECT id, auth_type, uid, password, access_token,
                target_matches, matches_done, status
         FROM processes
         WHERE status IN ('starting', 'running', 'stopping')
         ORDER BY id"
    );
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $pid = (int)$r['id'];

        /* Rewritten here, not only when a button was pressed. This is the last
           moment before a worker acts on the row, so it is the one place that
           can promise the folder on disk matches what is about to run - even
           if the row was changed by hand or the folder was cleaned up. */
        $ready = runtime_write($r);

        $rows[] = [
            'id'             => $pid,
            'auth_type'      => (string)$r['auth_type'],
            /* Opened here because the worker cannot use anything else, which
               is exactly why this endpoint stays shut unless somebody
               deliberately creates data/worker_token.txt: it is the one route
               by which every stored login on the machine can leave it. */
            'uid'            => unseal((string)$r['uid']),
            'password'       => unseal((string)$r['password']),
            'access_token'   => unseal((string)$r['access_token']),
            'target_matches' => (int)$r['target_matches'],
            'matches_done'   => (int)$r['matches_done'],
            'status'         => (string)$r['status'],
            /* What the owner wants to happen, so the worker does not have to
               know the state machine to read the intent. */
            'wanted'         => $r['status'] === 'stopping' ? 'stop' : 'run',
            /* No path and no filenames. The panel launches the engine itself,
               so a worker no longer needs to be told where anything lives, and
               this endpoint is reachable from outside the machine. */
            'run_ready'      => $ready,
        ];
    }
    out(['processes' => $rows]);
}

if ($do === 'report') {
    $raw = file_get_contents('php://input');
    $in = json_decode((string)$raw, true);
    if (!is_array($in) || empty($in['id'])) {
        out(['error' => 'bad payload'], 400);
    }

    $pid = (int)$in['id'];
    $exists = db()->prepare('SELECT 1 FROM processes WHERE id = ?');
    $exists->execute([$pid]);
    if (!$exists->fetch()) {
        out(['error' => 'no such process'], 404);
    }

    $sets = [];
    $args = [];

    foreach (['account_name', 'account_id', 'message'] as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "$f = ?";
            $args[] = str_cut((string)$in[$f], 400);
        }
    }

    /* An unknown status would break the CSS class on the dot and every
       in_array($status, ['running','starting']) check on the pages. */
    if (array_key_exists('status', $in)) {
        $status = (string)$in['status'];
        if (!in_array($status, STATES, true)) {
            out(['error' => 'unknown status', 'allowed' => STATES], 400);
        }
        $sets[] = 'status = ?';
        $args[] = $status;
    }

    foreach (['level_start', 'level_current', 'matches_done'] as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "$f = ?";
            $args[] = max(0, (int)$in[$f]);
        }
    }

    if (!$sets) {
        out(['ok' => true, 'changed' => 0]);
    }

    $sets[] = "updated_at = datetime('now')";
    $args[] = $pid;
    $stmt = db()->prepare('UPDATE processes SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($args);

    if (!empty($in['log'])) {
        /* Not into the activity log: that is rendered on the process page, and
           a worker's own text is exactly the kind of detail the panel keeps
           off it. Server side only. */
        error_log('gglp worker ' . $pid . ': ' . str_cut((string)$in['log'], 400));
    }

    out(['ok' => true, 'changed' => $stmt->rowCount()]);
}

out(['error' => 'unknown endpoint'], 404);

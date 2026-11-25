<?php
declare(strict_types=1);

/* Bring every process that should be running back up.
 *
 * Run once at boot by a systemd unit, and again by a timer as a safety net.
 * A reboot kills every child the panel spawned, but the database still says
 * they were running - so without this the panel comes back showing four live
 * processes and nothing on the machine behind them.
 *
 * CLI only. It takes no input, but a script that starts processes should not
 * be reachable over HTTP under any circumstances, so it refuses outright.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/runner.php';

$verbose = in_array('-v', $argv ?? [], true);

function say(string $line, bool $verbose): void
{
    if ($verbose) {
        fwrite(STDOUT, $line . "\n");
    }
}

/* Anything the database believes is live. 'stopping' is included because a
   reboot in the middle of a stop leaves the row there forever otherwise. */
$rows = db()->query(
    "SELECT * FROM processes
     WHERE status IN ('starting','running','stopping')
     ORDER BY id"
)->fetchAll();

/* Initialised before the branch, not inside it: with nothing to resume the
   summary line below still runs, and reading an unset variable there is a
   warning in the service log on every quiet timer tick - which is most of
   them. */
$resumed = 0;
$cleared = 0;

if (!$rows) {
    say('nothing to resume', $verbose);
} else {

foreach ($rows as $row) {
    $id = (int)$row['id'];

    /* A pid file left over from before the reboot points at a process that no
       longer exists - and worse, at a pid the kernel may have handed to
       something else entirely. Clear it before deciding anything. */
    $pid = runner_os_pid($id);
    if ($pid > 1 && runner_alive($pid)) {
        say("process $id already running as $pid", $verbose);
        continue;
    }
    @unlink(runtime_dir($id) . '/bot.pid');

    if ($row['status'] === 'stopping') {
        /* It was on its way down when the machine went. Honour that. */
        db()->prepare(
            "UPDATE processes SET status = 'idle', message = 'Stopped.',
                    updated_at = datetime('now') WHERE id = ?"
        )->execute([$id]);
        $cleared++;
        say("process $id left stopped", $verbose);
        continue;
    }

    /* The owner may have been suspended or expired while the machine was off.
       Resuming their work would be a straightforward way around that. */
    $owner = db()->prepare('SELECT * FROM users WHERE id = ?');
    $owner->execute([(int)$row['user_id']]);
    $user = $owner->fetch();
    if (!$user || access_block($user) !== null) {
        db()->prepare(
            "UPDATE processes SET status = 'idle',
                    message = 'Not resumed: the account is not active.',
                    updated_at = datetime('now') WHERE id = ?"
        )->execute([$id]);
        $cleared++;
        say("process $id skipped, owner not active", $verbose);
        continue;
    }

    $problem = runner_start($id);
    if ($problem !== '') {
        db()->prepare(
            "UPDATE processes SET status = 'error', message = ?,\n                    updated_at = datetime('now') WHERE id = ?"
        )->execute([$problem, $id]);
        say("process $id failed: $problem", $verbose);
        continue;
    }
    db()->prepare(
        "UPDATE processes SET status = 'starting', message = 'Resumed after a restart.',
                updated_at = datetime('now') WHERE id = ?"
    )->execute([$id]);
    log_line($id, 'Resumed after a restart.');
    $resumed++;
    say("process $id resumed", $verbose);

    /* Spread the launches out. Starting twenty accounts in the same second
       after a reboot is both a thundering herd on the machine and a very
       distinctive pattern from the far end. */
    usleep(random_int(400000, 1600000));
}
}

say("resumed $resumed, cleared $cleared", $verbose);

/* --- pick up the ones that fell over --------------------------------------
 *
 * A run can die at three in the morning from a dropped connection, and
 * without this it stays dead until somebody opens the panel. The timer calls
 * this every few minutes, so the recovery is the same whether anyone is
 * watching or not.
 *
 * Bounded, and with a widening gap between attempts. An account that is
 * genuinely broken - a wrong password, a banned uid - fails the same way
 * every time, and retrying it forever is both useless and a very distinctive
 * pattern from the far end. Five attempts over about four hours, then it is
 * left alone for a person to look at.
 */
const RETRY_MAX = 5;
const RETRY_BACKOFF = [120, 300, 900, 3600, 10800];   // seconds

$broken = db()->query(
    "SELECT * FROM processes WHERE status = 'error' AND retries < " . RETRY_MAX
)->fetchAll();

$retried = 0;
foreach ($broken as $row) {
    $id = (int)$row['id'];
    $tries = (int)$row['retries'];
    $wait = RETRY_BACKOFF[min($tries, count(RETRY_BACKOFF) - 1)];

    $age = time() - strtotime((string)$row['updated_at'] . ' UTC');
    if ($age < $wait) {
        continue;
    }

    /* Finished runs are not failures. A process that reached its target and
       then errored on the way out has nothing left to do. */
    if ((int)$row['unlimited'] !== 1
        && (int)$row['matches_done'] >= (int)$row['target_matches']) {
        continue;
    }

    $owner = db()->prepare('SELECT * FROM users WHERE id = ?');
    $owner->execute([(int)$row['user_id']]);
    $user = $owner->fetch();
    if (!$user || access_block($user) !== null) {
        continue;
    }

    $problem = runner_start($id);
    if ($problem !== '') {
        db()->prepare(
            "UPDATE processes SET retries = retries + 1,
                    updated_at = datetime('now') WHERE id = ?"
        )->execute([$id]);
        say("process $id retry failed: $problem", $verbose);
        continue;
    }
    db()->prepare(
        "UPDATE processes SET status = 'starting', retries = retries + 1,
                message = 'Restarted automatically after a failure.',
                updated_at = datetime('now') WHERE id = ?"
    )->execute([$id]);
    log_line($id, 'Restarted automatically (attempt ' . ($tries + 1) . ').');
    $retried++;
    say("process $id retried, attempt " . ($tries + 1), $verbose);

    usleep(random_int(400000, 1600000));
}

say("retried $retried", $verbose);
exit(0);

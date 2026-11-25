<?php
declare(strict_types=1);

require_once __DIR__ . '/runner.php';

/* Delete the accounts whose key has run out.
 *
 * There is no scheduler on this machine, so this runs off page loads - any
 * page load, by anyone, signed in or not, since the account being reaped is by
 * definition one that can no longer sign in and could otherwise sit expired
 * forever waiting for its own visit. Throttled through a row in settings so
 * the filesystem work happens about once a minute rather than on every hit.
 *
 * Three conditions have to hold before a row is touched, and each one is there
 * to stop a different accident:
 *
 *   key_bound = 1    the lifetime came out of a redeem code. An expiry an
 *                    admin typed into the Users form is a lockout, not a
 *                    scheduled deletion, and nothing that predates this
 *                    version has the flag set at all.
 *   created_by = 0   nobody on staff is looking after this account. An account
 *                    a seller or an admin made is theirs to end - that is the
 *                    exception this feature was asked for with.
 *   role = 'member'  no staff account is ever deleted by a background pass,
 *                    whatever the other two columns say.
 *
 * The expiry itself is compared in PHP against strtotime() rather than in SQL
 * against datetime('now'), because access_block() is what decides "expired"
 * everywhere else in the panel and it compares the same way. SQLite's now is
 * UTC and the column is written in the server's local time; on a machine that
 * is not UTC, a comparison done in SQL would delete accounts hours before the
 * panel agreed they had run out.
 */
const REAP_INTERVAL = 60;

function reap_expired_accounts(): int
{
    $last = (int)setting('reaped_at', '0');
    $now = time();
    if ($now - $last < REAP_INTERVAL) {
        return 0;
    }
    /* Claimed before the work, not after. Two requests arriving in the same
       second would otherwise both read the old timestamp and both go on to
       stop the same processes. */
    setting_set('reaped_at', (string)$now);

    $rows = db()->query(
        "SELECT id, username, expires_at FROM users
         WHERE key_bound = 1 AND created_by = 0 AND role = 'member'
           AND expires_at IS NOT NULL AND expires_at <> ''"
    )->fetchAll();

    $gone = 0;
    foreach ($rows as $row) {
        if (strtotime((string)$row['expires_at']) >= $now) {
            continue;
        }
        $id = (int)$row['id'];

        /* Same order as a deletion by hand, and for the same reason: the
           foreign key takes the process rows, but a row is not what is
           playing. Stop each one and wipe its folder while the row still
           exists, because runtime_dir() resolves the path by joining the
           process back to its owner. */
        $theirs = db()->prepare('SELECT id FROM processes WHERE user_id = ?');
        $theirs->execute([$id]);
        foreach ($theirs->fetchAll() as $proc) {
            $pid = (int)$proc['id'];
            runner_stop($pid);
            runner_kill_hard($pid);
            runtime_delete($pid);
        }
        @rmdir(runtime_user_dir($id, (string)$row['username']));

        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $gone++;

        /* Server side only. The panel has nowhere to show this - the person it
           happened to is gone - but a support question a week later is
           unanswerable without it. */
        error_log('gglp: reaped expired account ' . $row['username']
            . ' (key access ended ' . $row['expires_at'] . ')');
    }
    return $gone;
}

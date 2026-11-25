<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/runtime.php';
require_once __DIR__ . '/runner.php';

/* Process commands live here rather than in a page, because the dashboard card
   and the process page now offer the same controls. Two copies of "what Stop
   means" is how they drift apart. Each function returns the flash message; the
   caller decides where to redirect afterwards. */

const TARGET_MIN = 1;
const TARGET_MAX = 500;

function clamp_target(mixed $raw): int
{
    return max(TARGET_MIN, min(TARGET_MAX, (int)$raw));
}

/* --- What a game login may contain ------------------------------------------
 *
 * Rejected at the form rather than accepted and passed on. Everything typed
 * here is written verbatim into account_id.json and read by a Python process
 * that trusts the file completely, so the panel is the only place where a
 * value that is not a credential can be stopped from becoming one.
 *
 * Narrow on purpose. A uid is a number, so nothing but digits gets in - which
 * also rules out the newline that would let one field forge a second one in
 * any log line it appears in. The two secrets are letters and digits, matching
 * what the game actually issues.
 *
 * The token allows - . _ as well, and only because it has to: tokens are
 * issued in base64url and JWT form, both of which use them, and a rule that
 * rejected a real token would leave people unable to add an account at all.
 * Narrow it to ctype_alnum here if yours never carry them.
 */
function valid_game_uid(string $value): bool
{
    return (bool)preg_match('/^[0-9]{4,20}$/', $value);
}

function valid_game_password(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9]{4,64}$/', $value);
}

function valid_access_token(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]{8,4096}$/', $value);
}

/* What to call a process on screen.

   The bot never learns the in-game nickname - GetPlayerPersonalShow is only
   read for the level - so the honest best is the account id it decodes from
   the login token, then the uid that was typed in. "Not signed in yet" is
   reserved for a token login that has genuinely not run.

   The uid is masked. It is half of a guest login, and this string ends up in
   the page title, in a card, and in the browser history of whatever machine
   the panel was last opened on. Four characters tell two of your own accounts
   apart; the whole thing tells a stranger something they should not be able to
   read over a shoulder. */
function proc_display_name(array $proc): string
{
    if (($proc['account_name'] ?? '') !== '') {
        return (string)$proc['account_name'];
    }
    if (($proc['account_id'] ?? '') !== '') {
        return 'Account ' . $proc['account_id'];
    }
    if (($proc['uid'] ?? '') !== '') {
        return 'UID ' . mask_secret(unseal((string)$proc['uid']));
    }
    return 'Not signed in yet';
}

/* A run that has no target reports no percentage and no remaining count, so
   both surfaces have to ask rather than assume. */
function proc_is_unlimited(array $proc): bool
{
    return (int)($proc['unlimited'] ?? 0) === 1;
}

/* When the login token runs out, in words. This is the honest answer to "when
   will it stop" - after it, every API call the bot makes returns 401. */
function proc_token_left(array $proc): string
{
    $exp = (int)($proc['token_exp'] ?? 0);
    if ($exp <= 0) {
        return '';
    }
    $left = $exp - time();
    if ($left <= 0) {
        return 'expired';
    }
    if ($left < 3600) {
        return intdiv($left, 60) . 'm left';
    }
    if ($left < 86400) {
        return intdiv($left, 3600) . 'h ' . intdiv($left % 3600, 60) . 'm left';
    }
    return intdiv($left, 86400) . 'd ' . intdiv($left % 86400, 3600) . 'h left';
}

/* start | restart | stop | reset */
function proc_command(array $proc, string $action): string
{
    $id = (int)$proc['id'];

    if ($action === 'start' || $action === 'restart') {
        /* Restart is a stop first. Without it the old process keeps its socket
           and its counter, and two of them end up levelling the same account
           against each other. */
        runner_stop($id);
        runner_kill_hard($id);

        /* Every start is a fresh run, so the readings from the last one go.
           The bot takes a new baseline the moment it signs in - name, region,
           level and xp before - and "gained" measured against an older
           baseline would be the total of two runs, not this one. This is also
           why there is no Reset button: starting is the reset. */
        db()->prepare(
            "UPDATE processes SET status = 'starting', message = ?,
                    matches_done = 0, xp_start = 0, xp_current = 0,
                    level_start = 0, level_current = 0, token_exp = 0,
                    updated_at = datetime('now') WHERE id = ?"
        )->execute(['Starting.', $id]);

        /* The folder is written and the source refreshed inside here, so the
           copy a run uses is never older than the moment Start was pressed. */
        $problem = runner_start($id);
        if ($problem !== '') {
            /* Whatever half-started is taken down with it. runner_start()
               returns a message the moment it cannot finish, and on some of
               those paths python is already running - leaving it would mean an
               "error" card with a live process behind it. */
            runner_stop($id);
            runner_kill_hard($id);
            db()->prepare(
                "UPDATE processes SET status = 'error', message = ?, os_pid = 0,
                        updated_at = datetime('now') WHERE id = ?"
            )->execute([$problem, $id]);
            log_line($id, 'Start failed: ' . $problem);
            return $problem;
        }

        log_line($id, $action === 'restart' ? 'Restarted.' : 'Started.');
        return $action === 'restart'
            ? 'Restarting. Give it a few seconds to sign in.'
            : 'Starting. Give it a few seconds to sign in.';
    }

    if ($action === 'stop') {
        /* Refuse rather than rewrite the state. Pressing Stop on a run that
           already finished would otherwise replace "done" with "stopped" and
           lose the only record that it reached its target. The button is
           disabled in that state, but a stale tab can still post. */
        if (!in_array($proc['status'], ['running', 'starting'], true)) {
            runner_stop($id);
            return 'Nothing is running.';
        }
        db()->prepare(
            "UPDATE processes SET status = 'stopping', message = ?,
                    updated_at = datetime('now') WHERE id = ?"
        )->execute(['Stopping.', $id]);
        runner_stop($id);
        log_line($id, 'Stopped.');
        return 'Stopping.';
    }

    return 'Unknown command.';
}

/* $allowCreds is false for the dashboard card: uid, password and token are
   only editable on the process page, where there is room to see what is being
   replaced. Everything else is the same on both surfaces. */
function proc_save_settings(array $proc, array $in, bool $allowCreds): string
{
    $id     = (int)$proc['id'];
    $wasLive = in_array($proc['status'], ['running', 'starting'], true);
    $target  = clamp_target($in['target_matches'] ?? $proc['target_matches']);
    $endless = isset($in['unlimited']) ? 1 : 0;
    $restart = isset($in['restart_now']) && $in['restart_now'] !== '';

    $changed = [];
    $sets = ['target_matches = ?', 'unlimited = ?'];
    $args = [$target, $endless];

    if ($target !== (int)$proc['target_matches']) {
        $changed[] = 'matches ' . (int)$proc['target_matches'] . ' to ' . $target;
    }
    if ($endless !== (int)$proc['unlimited']) {
        $changed[] = $endless ? 'now unlimited' : 'now stops at the target';
    }

    /* A blank credential field means leave it alone. Otherwise opening this
       form to bump the match count would wipe the login. */
    if ($allowCreds) {
        /* Compared against the plaintext and stored sealed. The comparison has
           to open the row first: two seals of the same value differ by their
           nonce, so a straight string compare would report every save as a
           change and rewrite the run folder for nothing. */
        if ($proc['auth_type'] === 'guest') {
            $uid = trim((string)($in['uid'] ?? ''));
            $pw  = trim((string)($in['password'] ?? ''));
            /* A value that does not look like a credential is refused rather
               than saved, because the next Start writes it into the run folder
               and the failure surfaces there as "the login did not work" with
               nothing pointing back at the typo. */
            if ($uid !== '' && !valid_game_uid($uid)) {
                return 'A uid is 4 to 20 digits and nothing else. Nothing changed.';
            }
            if ($pw !== '' && !valid_game_password($pw)) {
                return 'A password is 4 to 64 letters and digits. Nothing changed.';
            }
            if ($uid !== '' && $uid !== unseal((string)$proc['uid'])) {
                $sets[] = 'uid = ?';      $args[] = seal($uid); $changed[] = 'uid';
            }
            if ($pw !== '') {
                $sets[] = 'password = ?'; $args[] = seal($pw);  $changed[] = 'password';
            }
        } else {
            $tok = trim((string)($in['access_token'] ?? ''));
            if ($tok !== '' && !valid_access_token($tok)) {
                return 'That does not look like an access token. Nothing changed.';
            }
            if ($tok !== '' && $tok !== unseal((string)$proc['access_token'])) {
                $sets[] = 'access_token = ?';
                $args[] = seal($tok);
                $changed[] = 'access token';
            }
        }
    }

    if (!$changed) {
        return 'Nothing changed.';
    }

    $status  = $proc['status'];
    $message = $proc['message'];
    if ($wasLive) {
        if ($restart) {
            $status  = 'starting';
            $message = 'Restarting with the new settings.';
        } else {
            $message = 'Saved. The running session keeps the old settings '
                     . 'until it is restarted.';
        }
    }

    $sets[] = 'status = ?';  $args[] = $status;
    $sets[] = 'message = ?'; $args[] = $message;
    $sets[] = "updated_at = datetime('now')";
    $args[] = $id;

    db()->prepare('UPDATE processes SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute($args);

    /* The folder is rewritten from the row that was just saved. A running
       session keeps the settings it started with either way - the worker read
       config.py at import time - which is exactly why the restart prompt
       exists. */
    runtime_refresh($id);

    log_line($id, 'Settings changed: ' . implode(', ', $changed)
        . ($wasLive && $restart ? ' (restarted)' : ''));

    return 'Settings saved.'
        . ($wasLive && !$restart ? ' Restart to apply them to the running session.' : '');
}

function proc_remove(array $proc): string
{
    /* Kill it before the row goes. A process whose folder is deleted from
       under it keeps running with the files already open, and nothing is left
       that knows its pid. */
    runner_stop((int)$proc['id']);
    runner_kill_hard((int)$proc['id']);

    /* The refund goes to the owner, not to whoever pressed the button: an
       admin removing someone else's process should not be paid for it. */
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM processes WHERE id = ?')->execute([(int)$proc['id']]);
        db()->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
            ->execute([PROCESS_COST, (int)$proc['user_id']]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return 'Could not remove that process.';
    }
    runtime_delete((int)$proc['id']);
    return 'Process removed. ' . PROCESS_COST . ' credit returned.';
}

/* Shared by both surfaces: returns null when the action is not a process
   command, so the caller can fall through to its own actions. */
function proc_dispatch(array $user, string $action, array $in, bool $allowCreds): ?string
{
    if (!in_array($action, ['start', 'stop', 'restart', 'remove', 'settings'], true)) {
        return null;
    }
    $proc = owned_process((int)($in['pid'] ?? 0), $user);
    if (!$proc) {
        return 'That process does not exist.';
    }
    if ($action === 'remove')   { return proc_remove($proc); }
    if ($action === 'settings') { return proc_save_settings($proc, $in, $allowCreds); }
    return proc_command($proc, $action);
}

/* The full control set, rendered identically on the dashboard card and on the
   process page.

   Every button is present in every state. The one or two that would be no-ops
   right now are disabled and carry a reason on hover, rather than
   disappearing: a control that vanishes between page loads reads as a broken
   page, and the owner is left working out where it went. */
function proc_controls(array $proc, string $size = 'sm', bool $withOpen = false): void
{
    $pid    = (int)$proc['id'];
    $status = (string)$proc['status'];
    $live   = in_array($status, ['running', 'starting'], true);
    $sz     = $size !== '' ? ' ' . $size : '';
    $csrf   = e(csrf_token());

    $button = static function (
        string $action,
        string $label,
        string $variant,
        bool   $enabled,
        string $why = '',
        string $confirm = ''
    ) use ($pid, $csrf, $sz): void {
        /* The confirm text sits in a JS string inside an HTML attribute.
           Writing the JS quotes as &quot; means an apostrophe in the message
           cannot close the string early. */
        $onsubmit = $confirm !== ''
            ? ' onsubmit="return confirm(&quot;' . e($confirm) . '&quot;)"'
            : '';
        $title = $why !== '' ? ' title="' . e($why) . '"' : '';
        printf(
            '<form method="post"%s>'
            . '<input type="hidden" name="csrf" value="%s">'
            . '<input type="hidden" name="pid" value="%d">'
            . '<input type="hidden" name="action" value="%s">'
            . '<button class="btn %s%s" type="submit"%s%s>%s</button>'
            . '</form>',
            $onsubmit,
            $csrf,
            $pid,
            e($action),
            e($variant),
            $sz,
            $enabled ? '' : ' disabled',
            $title,
            e($label)
        );
    };

    $button('start', 'Start', 'primary', !$live,
        $live ? 'Already running' : 'Sign in and begin - counters start fresh');

    $button('stop', 'Stop', 'line', $live,
        $live ? 'A match already running finishes on its own' : 'Nothing is running');

    /* Restart works from any state - from idle it is simply a start - so it is
       never disabled. It is also the button that applies new settings to a
       session that is already live. There is no Reset beside it: a start is a
       reset, since the run re-reads the account and takes a new baseline. */
    $button('restart', 'Restart', 'line', true,
        'Stop and start again from zero',
        $live ? 'Restart now? A match already in progress is cut off.' : '');

    echo '<span class="spacer"></span>';

    if ($withOpen) {
        printf('<a class="btn ghost%s" href="process.php?id=%d">Open</a>', $sz, $pid);
    }

    $button('remove', 'Remove', 'red', true,
        'Delete it and get the credit back',
        'Remove this process? The credit comes back to your balance.');
}

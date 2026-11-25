<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

/* Every process gets a folder of its own, holding a complete runnable copy of
   the bot plus the three files it reads before it starts. Folders are grouped
   per owner:

       data/processes/<user key>/<process id>/

   A copy per process rather than everyone running the shared tree, because
   Python writes __pycache__ beside the source it imports and each folder holds
   a different config.py. The same two facts in the bot force it from the other
   direction:

     bot.py:86   account_file() resolves beside bot.py, not beside the caller
     bot.py:407  COUNTER is built from config.HowManyMatch at import time

   The folder is a projection of the database row, never a second source of
   truth. It is rewritten on every start, restart and settings save, so an edit
   made there by hand is undone at the next press of a button. */

/* ---------------------------------------------------------------------------
   Where the bot lives
   --------------------------------------------------------------------------- */

/* A folder counts as the bot if the two files the launcher needs are in it.
   run_process.py is not required: the panel ships its own copy and writes it
   into each run folder, so there is nothing to keep in sync by hand. */
function runtime_is_bot_dir(string $dir): bool
{
    return $dir !== ''
        && is_file($dir . '/bot.py')
        && is_file($dir . '/config.py');
}

function runtime_norm(string $path): string
{
    $real = realpath($path);
    return str_replace('\\', '/', $real !== false ? $real : $path);
}

/* Look for the bot near the panel.

   The compiled-in default was written for one machine's layout and is wrong on
   every other one: on a VPS the site might sit at /root/me, and
   ../../levelup_solo then points at /root/levelup_solo, which does not exist.
   Rather than making that a support conversation, walk up a few levels and
   look two folders deep at each - enough to find /root/bots/levelup_solo from
   a site at /root/me. */
function runtime_detect_bot_dirs(): array
{
    $site = dirname(__DIR__);
    $roots = [$site];
    $up = $site;
    for ($i = 0; $i < 3; $i++) {
        $up = dirname($up);
        /* Stop at the filesystem root. Globbing / and then /*\/* would stat
           thousands of directories to find nothing. */
        if ($up === '' || $up === '.' || $up === '/'
            || preg_match('#^[A-Za-z]:[\\\\/]?$#', $up) === 1) {
            break;
        }
        $roots[] = $up;
    }

    $found = [];
    $seen = [];
    $consider = static function (string $dir) use (&$found, &$seen): void {
        if (!runtime_is_bot_dir($dir)) {
            return;
        }
        $norm = runtime_norm($dir);
        if (isset($seen[$norm])) {
            return;
        }
        $seen[$norm] = true;
        $found[] = $norm;
    };

    foreach ($roots as $root) {
        $consider($root);
        /* Capped, because one of these roots is someone's home directory. */
        foreach (array_slice((array)glob($root . '/*', GLOB_ONLYDIR), 0, 80) as $child) {
            $consider($child);
            foreach (array_slice((array)glob($child . '/*', GLOB_ONLYDIR), 0, 80) as $grand) {
                $consider($grand);
            }
        }
    }
    return $found;
}

/* The configured path wins even when it is wrong - saying so beats silently
   using something else, which would look like the panel ignored the setting.
   Otherwise the detected path, remembered in the database so the scan above
   runs once rather than on every request. */
function runtime_bot_dir(): string
{
    $configured = setting('bot_dir');
    if ($configured !== '') {
        return runtime_norm($configured);
    }

    $auto = setting('bot_dir_auto');
    if ($auto !== '' && runtime_is_bot_dir($auto)) {
        return $auto;
    }

    $detected = runtime_detect_bot_dirs();
    if ($detected) {
        setting_set('bot_dir_auto', $detected[0]);
        return $detected[0];
    }
    return runtime_norm(BOT_DIR);
}

function runtime_bot_ready(): bool
{
    return runtime_is_bot_dir(runtime_bot_dir());
}

function runtime_python(): string
{
    $set = setting('python_bin');
    if ($set !== '') {
        return $set;
    }
    if (PYTHON_BIN !== '') {
        return PYTHON_BIN;
    }
    return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
}

/* ---------------------------------------------------------------------------
   Where a process's folder is
   --------------------------------------------------------------------------- */

/* An opaque, stable folder name per account.

   Deliberately not derived from the password. The folder name ends up in the
   spawn command, which means it is visible in `ps aux` to anyone on the box,
   and it is printed on the process page; anything reversible there would be
   the password in plain sight, and an XOR is reversible by definition. It is
   also derived from the user id rather than only the name, so a rename does
   not orphan every folder the account owns. */
function runtime_user_key(int $userId, string $username): string
{
    return substr(
        hash_hmac('sha256', $userId . '|' . $username, install_secret()),
        0,
        16
    );
}

/* Resolved once per request and cached, because proc_remove needs the path
   after the row it comes from has already been deleted. */
function runtime_dir(int $pid): string
{
    static $cache = [];
    if (isset($cache[$pid])) {
        return $cache[$pid];
    }
    $stmt = db()->prepare(
        'SELECT u.id AS uid, u.username FROM processes p
         JOIN users u ON u.id = p.user_id WHERE p.id = ?'
    );
    $stmt->execute([$pid]);
    $row = $stmt->fetch();
    $key = $row
        ? runtime_user_key((int)$row['uid'], (string)$row['username'])
        : 'orphan';
    return $cache[$pid] = RUNTIME_DIR . '/' . $key . '/' . $pid;
}

function runtime_user_dir(int $userId, string $username): string
{
    return RUNTIME_DIR . '/' . runtime_user_key($userId, $username);
}

/* ---------------------------------------------------------------------------
   Writing a folder
   --------------------------------------------------------------------------- */

/* Copy the bot in, then the panel's own launcher over the top.

   Only what changed is copied - a size and mtime comparison per file - so the
   refresh on every Start costs nothing, and a fix made upstream reaches every
   process the next time it starts rather than each folder freezing at the
   source of the day it was created.

   config.py lands under a different name; the generated one takes its place
   and inherits from it. run_process.py always comes from the panel, so the bot
   folder never has to carry a copy and the two cannot drift. */
function runtime_sync_source(string $dir): bool
{
    $src = runtime_bot_dir();
    $files = (array)glob($src . '/*.py');
    if (!$files) {
        return false;
    }

    $ok = true;
    foreach ($files as $path) {
        $name = basename($path);
        if ($name === 'run_process.py') {
            continue;
        }
        $dest = $dir . '/' . ($name === 'config.py' ? '_base_config.py' : $name);
        if (is_file($dest)
            && filesize($dest) === filesize($path)
            && filemtime($dest) >= filemtime($path)) {
            continue;
        }
        if (!@copy($path, $dest)) {
            $ok = false;
        }
    }

    $launcher = __DIR__ . '/run_process.py';
    if (is_file($launcher)) {
        $dest = $dir . '/run_process.py';
        if (!is_file($dest)
            || filesize($dest) !== filesize($launcher)
            || filemtime($dest) < filemtime($launcher)) {
            $ok = @copy($launcher, $dest) && $ok;
        }
    }
    return $ok;
}

/* The generated settings file. It inherits rather than copies: writing the
   bot's ninety-odd settings into every folder would freeze each one at the
   defaults of the day it was made. */
function runtime_config_py(array $proc): string
{
    $target = max(1, (int)$proc['target_matches']);
    $guest  = $proc['auth_type'] === 'guest';
    $isG    = $guest ? 'True' : 'False';
    $isT    = $guest ? 'False' : 'True';
    $pid    = (int)$proc['id'];
    $when   = gmdate('Y-m-d H:i:s') . ' UTC';

    /* counted_run() in bot.py reads a different flag depending on Safe_Mode:

         Safe_Mode on  -> IsSafeModeSelected_numberofgames
         Safe_Mode off -> not UnlimitedMatch

       Both are written from the one toggle the panel still has, so the run
       stops where it was asked to whichever branch the engine takes. */
    $unlimited = (int)$proc['unlimited'] === 1;
    $counted   = $unlimited ? 'False' : 'True';
    $endless   = $unlimited ? 'True' : 'False';

    return <<<PY
# Generated by GG LEVEL PRO for process {$pid} on {$when}.
#
# Do not edit by hand. This file is rewritten from the database every time the
# process is started, restarted, or has its settings saved, so any change made
# here is lost at the next press of a button. Change the match count and the
# pacing in the panel instead.

# _base_config.py is the bot's own config.py, copied into this folder at the
# last Start. Everything except the block at the bottom comes from it, so a
# setting changed upstream is picked up here without the panel knowing it
# exists.
import _base_config as _base

for _name in dir(_base):
    if not _name.startswith("_"):
        globals()[_name] = getattr(_base, _name)

# --- owned by the panel ------------------------------------------------------
# auth_mode() in bot.py refuses to run when both flags are off and warns when
# both are on, so exactly one is set here from the process's auth_type.

HowManyMatch = {$target}

# Both of these, because counted_run() reads whichever one the engine's own
# Safe_Mode selects.
UnlimitedMatch = {$endless}
IsSafeModeSelected_numberofgames = {$counted}

# Pinned. The panel used to offer this as a per-process choice and no longer
# does - the pacing work behind it was never finished, and a switch that admits
# it is unfinished has no business being in front of a customer.
#
# True rather than False on purpose. In bot.py both round types wait for a
# match to actually be played on this branch; _wolf_round() with Safe_Mode off
# calls lobby.stop() the moment the notification lands, before the UDP side has
# joined, so the match is cancelled and nothing is levelled. Every process the
# panel has ever started ran with this True, so pinning it is what keeps
# removing the switch from changing what anybody's account does tonight.
#
# Change it in leveupsrc/config.py if the overlap path is ever finished.
Safe_Mode = True

isGuest = {$isG}
IsAccessToken = {$isT}

PY;
}

/* CPython compares config.py's mtime with its cached .pyc at one-second
   resolution, so rewriting the file inside the same second the cache was
   written leaves the old values loading - the exact failure that
   check_config_fresh() in bot.py warns about. */
function runtime_clear_pycache(string $dir): void
{
    $cache = $dir . '/__pycache__';
    if (!is_dir($cache)) {
        return;
    }
    foreach ((array)glob($cache . '/*') as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($cache);
}

function runtime_write(array $proc): bool
{
    $pid = (int)$proc['id'];
    $dir = runtime_dir($pid);

    /* Folders used to sit flat under data/processes/<id>. Clear the old one so
       an upgrade does not leave credentials lying around in a path nothing
       looks at any more. */
    $legacy = RUNTIME_DIR . '/' . $pid;
    if ($legacy !== $dir && is_dir($legacy)) {
        runtime_delete_dir($legacy);
    }

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    /* 0700 is honoured on Linux. Windows ignores the mode, so there the only
       thing keeping these private is that data/ sits outside the web root. */
    @chmod($dir, 0700);
    @chmod(dirname($dir), 0700);

    /* This is where the plaintext comes back, and the only place it is written
       to a disk at all.
     *
     * The columns are sealed - see includes/crypto.php - but the bot is Python
     * reading a JSON file and cannot be handed a key without that key ending
     * up in the same folder as the thing it protects. So the split is: the
     * database, which is what leaves a machine by accident, holds ciphertext;
     * the run folder, which is 0700 and never served, holds what the bot
     * needs. Two mechanisms for two different ways of losing it.
     *
     * account_id.json in the bot's own copy carries uid as a number and the
     * bot passes it straight through, so keep the type it expects. */
    $rawUid = unseal((string)$proc['uid']);
    $uid = ctype_digit($rawUid) ? (int)$rawUid : $rawUid;

    $files = [
        'config.py' => runtime_config_py($proc),
        'account_id.json' => json_encode(
            ['uid' => $uid, 'password' => unseal((string)$proc['password'])],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n",
        'access_token.json' => json_encode(
            ['token' => unseal((string)$proc['access_token'])],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n",
    ];

    foreach ($files as $name => $body) {
        $path = $dir . '/' . $name;
        $tmp  = $path . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        /* Write then rename: rename is atomic on the same filesystem, so a bot
           reading the folder while the panel writes never sees half a config.
           Windows refuses to rename onto an existing file. */
        if (file_exists($path)) {
            @unlink($path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
    }

    runtime_sync_source($dir);
    runtime_clear_pycache($dir);
    return true;
}

/* Re-read the row and write the folder from it. Callers that have just run an
   UPDATE hold the old values, and writing those would put the folder one
   change behind the panel. */
function runtime_refresh(int $pid): bool
{
    $stmt = db()->prepare('SELECT * FROM processes WHERE id = ?');
    $stmt->execute([$pid]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    $ok = runtime_write($row);
    if (!$ok) {
        /* No path in the activity log - it is rendered on the process page.
           The path is on the admin-only run folder card and in Settings. */
        log_line($pid, 'Could not prepare the run folder.');
        error_log('gglp: cannot write run folder ' . runtime_dir($pid));
    }
    return $ok;
}

function runtime_delete_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    runtime_clear_pycache($dir);

    /* The three files below hold a live login in the clear. Unlinking only
       drops the directory entry - the blocks stay on the disk until something
       else claims them, which on a VPS with snapshots or a resold volume can
       be a long time. Overwriting first costs nothing at this size and means
       the deletion is real. */
    foreach (['account_id.json', 'access_token.json', 'config.py'] as $secret) {
        $path = $dir . '/' . $secret;
        if (is_file($path) && ($size = filesize($path)) > 0) {
            $handle = @fopen($path, 'r+b');
            if ($handle) {
                fwrite($handle, str_repeat("\0", (int)$size));
                fflush($handle);
                fclose($handle);
            }
        }
    }

    foreach ((array)glob($dir . '/*') as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function runtime_delete(int $pid): void
{
    runtime_delete_dir(runtime_dir($pid));
}

/* What is actually on disk, for the process page. Sizes rather than contents:
   the credentials are already in the settings form above, and printing them
   twice only widens where they can leak from. */
function runtime_state(int $pid): array
{
    $dir = runtime_dir($pid);
    $out = [
        'dir' => $dir,
        'exists' => is_dir($dir),
        'files' => [],
        'modules' => count((array)glob($dir . '/*.py')),
    ];
    foreach (['config.py', 'account_id.json', 'access_token.json', 'bot.py'] as $name) {
        $path = $dir . '/' . $name;
        $out['files'][$name] = is_file($path)
            ? ['size' => (int)filesize($path), 'at' => (int)filemtime($path)]
            : null;
    }
    return $out;
}

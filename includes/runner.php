<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/runtime.php';

const RUNNER_BOOT_GRACE = 45;

function runner_is_windows(): bool { return PHP_OS_FAMILY === 'Windows'; }
function runner_python(): string { return runtime_python(); }
function runner_pid_file(int $id): string { return runtime_dir($id) . '/bot.pid'; }
function runner_os_pid(int $id): int {
    $raw = @file_get_contents(runner_pid_file($id));
    if ($raw !== false && (int)trim($raw) > 1) return (int)trim($raw);
    $stmt = db()->prepare('SELECT os_pid FROM processes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? (int)$row['os_pid'] : 0;
}
function runner_alive(int $osPid): bool {
    if ($osPid <= 1) return false;
    if (runner_is_windows()) {
        $out = @shell_exec('tasklist /FI "PID eq ' . $osPid . '" /NH 2>NUL');
        return is_string($out) && preg_match('/\b' . $osPid . '\b/', $out) === 1;
    }
    $stat = @file_get_contents('/proc/' . $osPid . '/stat');
    if (is_string($stat) && $stat !== '') {
        $tail = substr($stat, (int)strrpos($stat, ')') + 1);
        return trim(strtok(ltrim($tail), ' ')) !== 'Z';
    }
    if (function_exists('posix_kill')) return @posix_kill($osPid, 0);
    return is_dir('/proc/' . $osPid);
}
function runner_status(int $id): array {
    $raw = @file_get_contents(runtime_dir($id) . '/status.json');
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function runner_log(int $id, int $lines = 40): array {
    $path = runtime_dir($id) . '/bot.log';
    if (!is_file($path)) return [];
    $size = filesize($path);
    $want = min($size, 64 * 1024);
    $handle = @fopen($path, 'rb');
    if (!$handle) return [];
    fseek($handle, -$want, SEEK_END);
    $chunk = (string)fread($handle, $want);
    fclose($handle);
    $all = preg_split('/\R/', trim($chunk)) ?: [];
    if ($size > $want && $all) array_shift($all);
    return array_slice($all, -$lines);
}

function runner_start(int $id): string {
    // لا نعرض المسار أبداً
    if (!runtime_bot_ready()) {
        return 'The bot is not configured. Please contact the administrator.';
    }
    if (!runtime_refresh($id)) {
        return 'Could not prepare this process. Please contact the administrator.';
    }
    $dir = runtime_dir($id);
    $real = realpath($dir);
    if ($real === false) return 'Could not prepare this process.';
    @unlink($dir . '/stop.flag');
    @unlink($dir . '/bot.pid');
    @unlink($dir . '/status.json');
    $python = runner_python();
    if (runner_is_windows()) {
        $cmd = 'cmd /c "cd /d ' . escapeshellarg($real) . ' && start "gglp" /B ' . escapeshellarg($python) . ' run_process.py . >> bot.log 2>&1"';
        $handle = @popen($cmd, 'r');
        if ($handle !== false) pclose($handle);
    } else {
        $cmd = 'cd ' . escapeshellarg($real) . ' && setsid nohup ' . escapeshellarg($python) . ' run_process.py . >> bot.log 2>&1 < /dev/null &';
        @exec($cmd);
    }
    $osPid = 0;
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        $raw = @file_get_contents($dir . '/bot.pid');
        if ($raw !== false && (int)trim($raw) > 1) { $osPid = (int)trim($raw); break; }
    }
    db()->prepare("UPDATE processes SET os_pid = ?, updated_at = datetime('now') WHERE id = ?")->execute([$osPid, $id]);
    log_line($id, 'Launched.');
    return '';
}

function runner_stop(int $id): void {
    $dir = runtime_dir($id);
    if (is_dir($dir)) @file_put_contents($dir . '/stop.flag', (string)time());
    $osPid = runner_os_pid($id);
    if ($osPid <= 1 || !runner_alive($osPid)) return;
    if (runner_is_windows()) @shell_exec('taskkill /PID ' . $osPid . ' /T /F 2>NUL');
    elseif (function_exists('posix_kill')) @posix_kill($osPid, 15);
    else @exec('kill -TERM ' . $osPid . ' 2>/dev/null');
}
function runner_kill_hard(int $id): void {
    $osPid = runner_os_pid($id);
    if ($osPid <= 1) return;
    if (runner_is_windows()) @shell_exec('taskkill /PID ' . $osPid . ' /T /F 2>NUL');
    elseif (function_exists('posix_kill')) @posix_kill($osPid, 9);
    else @exec('kill -9 ' . $osPid . ' 2>/dev/null');
}
function runner_sync(array $proc): void {
    $id = (int)$proc['id'];
    $dbStatus = (string)$proc['status'];
    if (!in_array($dbStatus, ['starting', 'running', 'stopping'], true)) return;
    $osPid = runner_os_pid($id);
    $alive = $osPid > 1 && runner_alive($osPid);
    $st = runner_status($id);
    if (!is_dir(runtime_dir($id))) {
        if ($alive) runner_kill_hard($id);
        db()->prepare("UPDATE processes SET status = 'idle', os_pid = 0, message = 'Stopped: its working folder was removed.', updated_at = datetime('now') WHERE id = ?")->execute([$id]);
        return;
    }
    $sets = []; $args = [];
    $put = static function (string $col, $value) use (&$sets, &$args): void { $sets[] = $col . ' = ?'; $args[] = $value; };
    $done   = isset($st['matches_done']) ? max(0, (int)$st['matches_done']) : null;
    $target = isset($st['target_matches']) ? (int)$st['target_matches'] : 0;
    if ($done !== null && $done !== (int)$proc['matches_done']) $put('matches_done', $done);
    foreach (['level_start', 'level_current', 'xp_start', 'xp_current'] as $col) if (!empty($st[$col]) && (int)$st[$col] !== (int)$proc[$col]) $put($col, (int)$st[$col]);
    if (!empty($st['token_exp']) && (int)$st['token_exp'] !== (int)$proc['token_exp']) $put('token_exp', (int)$st['token_exp']);
    if (!empty($st['account_id']) && (string)$st['account_id'] !== (string)$proc['account_id']) $put('account_id', (string)$st['account_id']);
    if (!empty($st['region']) && (string)$st['region'] !== (string)$proc['region']) $put('region', (string)$st['region']);
    if (!empty($st['account_name']) && (string)$st['account_name'] !== (string)$proc['account_name']) { $put('account_name', substr((string)$st['account_name'], 0, 120)); log_line($id, 'Account name: ' . $st['account_name']); }
    if ($osPid > 1 && $osPid !== (int)$proc['os_pid']) $put('os_pid', $osPid);
    $status = $dbStatus; $message = null;
    if ($alive) {
        $reported = (string)($st['state'] ?? '');
        if ($reported === 'done') $status = 'done';
        elseif ($reported === 'error') $status = 'error';
        elseif ($reported === 'running' && $dbStatus === 'starting') { $status = 'running'; if ((int)$proc['retries'] > 0) $put('retries', 0); }
        if (!empty($st['message'])) $message = (string)$st['message'];

        /* A process that has reported an error and is still alive is killed
           here, not left to sit.
         *
         * It has already said it cannot carry on, so whatever is still running
         * is a thread stuck in a socket read or a match nobody is counting -
         * holding a slot, holding its login open, and reading as "error" on a
         * card while quietly still playing. Stop is asked politely first
         * through the flag; the signal is what handles the wedged case, which
         * is the case this exists for. Done is left alone: it finished. */
        if ($status === 'error') {
            runner_stop($id);
            runner_kill_hard($id);
            $put('os_pid', 0);
            log_line($id, 'Stopped after an error.');
        }
    } else {
        $reported = (string)($st['state'] ?? '');
        $endless  = !empty($st['unlimited']) || (int)$proc['unlimited'] === 1;
        $finished = $reported === 'done' || (!$endless && $target > 0 && $done !== null && $done >= $target);
        if ($dbStatus === 'stopping') { $status = 'idle'; $message = 'Stopped.'; }
        elseif ($finished) { $status = 'done'; $message = (string)($st['message'] ?: 'Finished.'); }
        elseif ($reported === 'expired') { $status = 'done'; $message = (string)$st['message']; }
        elseif ($reported === 'stopped') { $status = 'idle'; $message = (string)($st['message'] ?: 'Stopped.'); }
        elseif ($reported === 'error') { $status = 'error'; $message = (string)$st['message']; }
        else {
            $age = time() - strtotime((string)$proc['updated_at'] . ' UTC');
            if ($age < RUNNER_BOOT_GRACE && !$st) return;
            $status = 'error'; $message = 'The bot stopped unexpectedly.';
        }
    }
    if ($status !== $dbStatus) { $put('status', $status); log_line($id, 'State: ' . $dbStatus . ' to ' . $status); }
    if ($message !== null && $message !== (string)$proc['message']) $put('message', substr($message, 0, 400));
    if (!$sets) return;
    $sets[] = "updated_at = datetime('now')"; $args[] = $id;
    db()->prepare('UPDATE processes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
}
function runner_sync_user(int $userId): void {
    $stmt = db()->prepare("SELECT * FROM processes WHERE user_id = ? AND status IN ('starting','running','stopping')");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) runner_sync($row);
}

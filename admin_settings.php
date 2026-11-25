<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/runner.php';

/* Owner only. This page decides where the engine lives and which interpreter
   runs it, which is the whole machine rather than one person's customers. */
$admin = require_owner();

/* Nothing on this page prints a path.

   The engine location has to be settable from somewhere, so the fields accept
   one - but they never echo what is stored, the candidate list is numbered
   rather than named, and the interpreter's components are reported as a count.
   A screenshot of this screen tells a reader nothing about where anything is
   or how the levelling is done. */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $dir = trim((string)($_POST['bot_dir'] ?? ''));
        $py  = trim((string)($_POST['python_bin'] ?? ''));

        /* Blank means "leave it alone", because the fields never show what is
           already stored - see the note at the top of this file. Clearing is a
           separate button, so it cannot happen by submitting an empty form. */
        if ($dir !== '') {
            if (!runtime_is_bot_dir($dir)) {
                flash('That folder does not hold a usable engine. Nothing saved.');
                redirect('admin_settings.php');
            }
            setting_set('bot_dir', $dir);
        }
        if ($py !== '') {
            setting_set('python_bin', $py);
        }
        flash($dir === '' && $py === '' ? 'Nothing changed.' : 'Saved.');
        redirect('admin_settings.php');
    }

    if ($action === 'clear') {
        setting_set('bot_dir', '');
        setting_set('python_bin', '');
        flash('Cleared. The panel will locate the engine on its own.');
        redirect('admin_settings.php');
    }

    if ($action === 'detect') {
        $found = runtime_detect_bot_dirs();
        if (!$found) {
            flash('Nothing usable was found near the panel.');
        } else {
            setting_set('bot_dir', $found[0]);
            flash('Found ' . count($found) . '. Using the first.');
        }
        redirect('admin_settings.php');
    }

    if ($action === 'use') {
        /* Chosen by position in the detected list, so the form never has to
           carry a path through the browser. */
        $found = runtime_detect_bot_dirs();
        $n = (int)($_POST['n'] ?? -1);
        if (isset($found[$n]) && runtime_is_bot_dir($found[$n])) {
            setting_set('bot_dir', $found[$n]);
            flash('Switched to candidate ' . ($n + 1) . '.');
        } else {
            flash('That candidate is no longer usable.');
        }
        redirect('admin_settings.php');
    }

    redirect('admin_settings.php');
}

$botOk    = runtime_bot_ready();
$detected = runtime_detect_bot_dirs();
$python   = runtime_python();

/* Ask the interpreter whether it can load what the engine needs. The result is
   a count, not a list: naming the libraries would describe the technique, and
   the count is enough to tell a working interpreter from a wrong one. The
   names go to the server log for whoever is on the box. */
$pyVersion = '';
$modsOk = 0;
$modsTotal = 0;
$missing = [];
if (function_exists('shell_exec')) {
    $probe = @shell_exec(escapeshellarg($python) . ' --version 2>&1');
    $pyVersion = is_string($probe) ? trim($probe) : '';

    $code = 'import importlib.util,json;'
          . 'm=["jwt","requests","google.protobuf","Crypto","urllib3"];'
          . 'print(json.dumps({x:bool(importlib.util.find_spec(x.split(".")[0])) for x in m}))';
    $out = @shell_exec(escapeshellarg($python) . ' -c ' . escapeshellarg($code) . ' 2>&1');
    $parsed = is_string($out) ? json_decode(trim($out), true) : null;
    if (is_array($parsed)) {
        $modsTotal = count($parsed);
        foreach ($parsed as $name => $have) {
            if ($have) {
                $modsOk++;
            } else {
                $missing[] = $name;
            }
        }
        if ($missing) {
            error_log('gglp: interpreter ' . $python . ' is missing: '
                . implode(', ', $missing));
        }
    }
}

$writable = is_dir(RUNTIME_DIR) ? is_writable(RUNTIME_DIR) : is_writable(dirname(RUNTIME_DIR));

app_head('Settings', 'settings');
admin_tabs('settings');
?>

<div class="sec">
  <div class="metrics">
    <div class="metric">
      <div class="k">Engine</div>
      <div class="v txt <?= $botOk ? 'on' : '' ?>"><?= $botOk ? 'ready' : 'not found' ?></div>
    </div>
    <div class="metric">
      <div class="k">Interpreter</div>
      <div class="v txt <?= $pyVersion !== '' ? 'on' : '' ?>">
        <?= $pyVersion !== '' ? e($pyVersion) : 'not found' ?>
      </div>
    </div>
    <div class="metric">
      <div class="k">Components</div>
      <div class="v txt <?= ($modsTotal && $modsOk === $modsTotal) ? 'on' : '' ?>">
        <?= $modsTotal ? $modsOk . ' of ' . $modsTotal : 'unknown' ?>
      </div>
    </div>
    <div class="metric">
      <div class="k">Storage</div>
      <div class="v txt <?= $writable ? 'on' : '' ?>"><?= $writable ? 'writable' : 'read only' ?></div>
    </div>
  </div>
</div>

<?php if (!$botOk): ?>
<div class="sec">
  <div class="note warn">
    <b>The engine is not where the panel is looking</b>
    Start will refuse until this is right, rather than launching something that
    cannot work. Press Detect below, or type the folder in yourself.
  </div>
</div>
<?php endif; ?>

<?php if ($modsTotal && $modsOk < $modsTotal): ?>
<div class="sec">
  <div class="note warn">
    <b>The interpreter is missing <?= $modsTotal - $modsOk ?> component(s)</b>
    A process will fail to start. The names are in the server error log - they
    are not printed here. Installing them for a different interpreter than the
    one above is the usual reason this stays red: the web server's PATH is
    rarely your shell's.
  </div>
</div>
<?php endif; ?>

<div class="sec">
  <div class="sec-hd">
    <div>
      <h2>Engine location</h2>
      <p class="lead">
        The folder the panel copies from when a process starts. Read only -
        the panel never writes into it.
      </p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="detect">
      <button class="btn line sm" type="submit">Detect</button>
    </form>
  </div>

  <div class="card">
    <div class="pad">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="field">
          <label for="bd">Folder</label>
          <input id="bd" name="bot_dir" type="text" class="mono"
                 placeholder="<?= $botOk ? 'set - leave blank to keep it' : 'not set' ?>"
                 autocomplete="off" spellcheck="false">
          <p class="hint">
            Never shown back, only replaced. Leave blank to keep what is there.
          </p>
        </div>
        <div class="field">
          <label for="pb">Interpreter</label>
          <input id="pb" name="python_bin" type="text" class="mono"
                 placeholder="<?= setting('python_bin') !== '' ? 'set - leave blank to keep it' : 'from PATH' ?>"
                 autocomplete="off" spellcheck="false">
          <p class="hint">
            Blank uses whatever is on PATH. Give an absolute path when the
            components live in a virtual environment.
          </p>
        </div>
        <div class="btns" style="margin-top:6px">
          <button class="btn primary" type="submit">Save</button>
        </div>
      </form>
      <form method="post" style="margin-top:10px"
            onsubmit="return confirm(&quot;Clear both, and let the panel locate the engine itself?&quot;)">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear">
        <button class="btn line sm" type="submit">Clear both</button>
      </form>
    </div>
  </div>
</div>

<?php if (count($detected) > 1): ?>
<div class="sec">
  <div class="card">
    <div class="card-hd">
      <h2>Other candidates</h2>
      <span class="tag"><?= count($detected) ?></span>
    </div>
    <div class="pad">
      <p class="lead" style="margin:0 0 12px">
        More than one usable folder was found near the panel. They are numbered
        rather than named; try the next one if the current pick is wrong.
      </p>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Candidate</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($detected as $n => $dir): ?>
            <tr>
              <td data-label="Candidate" class="mono">#<?= $n + 1 ?></td>
              <td data-label="Actions" class="nowrap">
                <?php if (runtime_norm($dir) === runtime_bot_dir()): ?>
                  <span class="tag blue">in use</span>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="use">
                    <input type="hidden" name="n" value="<?= $n ?>">
                    <button class="btn line xs" type="submit">Use this</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php app_foot(); ?>

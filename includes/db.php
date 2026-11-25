<?php
declare(strict_types=1);

const DB_PATH = __DIR__ . '/../data/gglevel.sqlite';

/* Where the Python bot lives - the fallback only. The real value is a setting
   in the database, so the path can be fixed from the admin panel instead of by
   editing this file over SSH. This default is right when levelup_website and
   levelup_solo sit side by side under udp_protocol. */
const BOT_DIR = __DIR__ . '/../../levelup_solo';

/* One folder per process, each holding the config.py, account_id.json and
   access_token.json that the bot reads before it starts. Under data/ so the
   router and the nginx rule that already hide the database hide these too -
   they contain a live login in the clear. */
const RUNTIME_DIR = __DIR__ . '/../data/processes';

/* The interpreter used to launch a process - again only the fallback; the
   admin panel holds the real one. Leave it empty to use whatever is on PATH. */
const PYTHON_BIN = '';

const PROCESS_COST = 1;

/* A process moves through these. 'starting' and 'stopping' exist because the
   worker cannot act instantly: the dashboard needs a way to show that a click
   was received while the worker is still getting there, or a user presses the
   button again thinking nothing happened. */
const STATES = ['idle', 'starting', 'running', 'stopping', 'done', 'error'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fresh = !file_exists(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    schema($pdo);
    migrate($pdo);
    if ($fresh) {
        seed($pdo);
    }
    return $pdo;
}

function schema(PDO $pdo): void
{
    /* expires_at NULL means the account never lapses. A ban is separate from
       an expiry on purpose: one is a decision about a person, the other is a
       clock running out, and mixing them loses the reason.

       banned_until NULL on a banned account means the suspension is permanent
       until someone lifts it; a date means it lifts itself.

       created_by is the staff member who made the account, and it is the whole
       basis of who may manage whom - see the roles block in auth.php. A plain
       integer rather than a foreign key on purpose: deleting a seller must not
       take their customers' rows with it, and ON DELETE SET NULL would need a
       nullable column that every check then has to second-guess. 0 means
       nobody made it, which is what a self-registered account is, and only an
       owner manages those.

       key_bound says this account's lifetime came out of a redeem code rather
       than out of a decision by a person. Only those are deleted when the
       clock runs out - see reap_expired_accounts(). An account a seller or an
       admin made is theirs to end, and an expiry they set is a lockout, never
       a scheduled deletion. The flag is separate from created_by because it
       has to survive an owner later putting the account under a seller. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            role          TEXT    NOT NULL DEFAULT 'member',
            credits       INTEGER NOT NULL DEFAULT 0,
            banned        INTEGER NOT NULL DEFAULT 0,
            ban_reason    TEXT    NOT NULL DEFAULT '',
            banned_at     TEXT    NULL,
            banned_until  TEXT    NULL,
            expires_at    TEXT    NULL,
            created_by    INTEGER NOT NULL DEFAULT 0,
            key_bound     INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");

    /* max_uses is how many different people may redeem one code. Counting
       uses on the key and recording each redemption separately is what makes
       'this key was shared around' visible instead of guesswork.

       created_by for the same reason it is on users: an admin should see the
       batch they minted rather than every code on the machine, and a note
       reading "for Youssef" is nobody else's business.

       valid_seconds is how long the account that redeems this code gets to
       live, counted from the moment it redeems rather than from a date on the
       key - ten people redeeming one code on ten different days each get their
       own full month. 0 means the code gives credits and no clock. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keys (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            code          TEXT    NOT NULL UNIQUE,
            credits       INTEGER NOT NULL,
            max_uses      INTEGER NOT NULL DEFAULT 1,
            used_count    INTEGER NOT NULL DEFAULT 0,
            valid_seconds INTEGER NOT NULL DEFAULT 0,
            note          TEXT    NOT NULL DEFAULT '',
            created_by    INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS key_uses (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            key_id  INTEGER NOT NULL REFERENCES keys(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            used_at TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (key_id, user_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS processes (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            auth_type      TEXT    NOT NULL,
            uid            TEXT    NOT NULL DEFAULT '',
            password       TEXT    NOT NULL DEFAULT '',
            access_token   TEXT    NOT NULL DEFAULT '',
            account_name   TEXT    NOT NULL DEFAULT '',
            account_id     TEXT    NOT NULL DEFAULT '',
            level_start    INTEGER NOT NULL DEFAULT 0,
            level_current  INTEGER NOT NULL DEFAULT 0,
            safe_mode      INTEGER NOT NULL DEFAULT 1,
            unlimited      INTEGER NOT NULL DEFAULT 0,
            target_matches INTEGER NOT NULL DEFAULT 50,
            matches_done   INTEGER NOT NULL DEFAULT 0,
            xp_start       INTEGER NOT NULL DEFAULT 0,
            xp_current     INTEGER NOT NULL DEFAULT 0,
            region         TEXT    NOT NULL DEFAULT '',
            token_exp      INTEGER NOT NULL DEFAULT 0,
            os_pid         INTEGER NOT NULL DEFAULT 0,
            retries        INTEGER NOT NULL DEFAULT 0,
            status         TEXT    NOT NULL DEFAULT 'idle',
            message        TEXT    NOT NULL DEFAULT '',
            created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at     TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            process_id INTEGER NOT NULL REFERENCES processes(id) ON DELETE CASCADE,
            message    TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");

    /* Anything an admin can change without touching a file. The bot path and
       the interpreter live here because the machine the panel runs on is
       rarely the machine it was written on, and editing PHP over SSH to fix a
       path is not a thing anyone should have to do. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )
    ");

    /* One row per failed sign-in, used only to slow guessing down. Rows older
       than a day are deleted on the next failure, so this never grows. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id  INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            at  TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_key ON login_attempts(key, at)');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proc_user ON processes(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_proc_status ON processes(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_proc ON logs(process_id, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_keyuse_key ON key_uses(key_id)');
}

/* CREATE TABLE IF NOT EXISTS does nothing to a table that already exists, so a
   database made before a column was added would never get it. Each step is
   guarded by what is actually in the table rather than by a version number,
   which means running this twice is harmless. */
function migrate(PDO $pdo): void
{
    $columns = static function (string $table) use ($pdo): array {
        $names = [];
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $col) {
            $names[] = $col['name'];
        }
        return $names;
    };

    $users = $columns('users');
    if (!in_array('banned_until', $users, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN banned_until TEXT NULL');
    }

    /* Added with sellers. Everything already in the table gets 0 - made by
       nobody - which leaves every existing account under the owner and under
       no one else, the only safe answer when the panel cannot know who
       created what. */
    if (!in_array('created_by', $users, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN created_by INTEGER NOT NULL DEFAULT 0');
    }

    /* Added with key-bound signups. Everything already in the table gets 0, so
       no account that existed before this version can ever be deleted by the
       reaper - which is the only safe default for a column whose value decides
       whether a row is destroyed. */
    if (!in_array('key_bound', $users, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN key_bound INTEGER NOT NULL DEFAULT 0');
    }

    $keys = $columns('keys');
    if (!in_array('created_by', $keys, true)) {
        $pdo->exec('ALTER TABLE keys ADD COLUMN created_by INTEGER NOT NULL DEFAULT 0');
    }
    /* 0 on every existing key: they hand out credits and no clock, exactly as
       they did before there was one. */
    if (!in_array('valid_seconds', $keys, true)) {
        $pdo->exec('ALTER TABLE keys ADD COLUMN valid_seconds INTEGER NOT NULL DEFAULT 0');
    }

    /* A role this version does not know reads as rank 0 - see role_rank() -
       which would quietly take the panel away from whoever held it. */
    $pdo->exec("UPDATE users SET role = 'member'
                WHERE role NOT IN ('member', 'seller', 'admin', 'owner')");

    /* Every install needs exactly one account that can reach everything, and
       a database made before this version has admins with nothing above them.
       Promote the oldest admin rather than inventing an account: that is the
       one the panel seeded, so whoever runs the site keeps the login they
       already have instead of being locked out of the top of it. */
    $owners = $pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'owner'")->fetch();
    if ((int)$owners['c'] === 0) {
        $first = $pdo->query(
            "SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"
        )->fetch();
        if ($first) {
            $pdo->prepare("UPDATE users SET role = 'owner' WHERE id = ?")
                ->execute([(int)$first['id']]);
        }
    }

    /* Added when the panel learned to read the profile reply: the name, the
       region, the xp on both sides of a run, and when the login token runs
       out. SQLite cannot add a column twice, so each is guarded by what is
       actually in the table. */
    $procs = $columns('processes');
    $additions = [
        'unlimited'  => 'INTEGER NOT NULL DEFAULT 0',
        'xp_start'   => 'INTEGER NOT NULL DEFAULT 0',
        'xp_current' => 'INTEGER NOT NULL DEFAULT 0',
        'region'     => "TEXT NOT NULL DEFAULT ''",
        'token_exp'  => 'INTEGER NOT NULL DEFAULT 0',
        /* The OS pid, kept here as well as in the run folder. The folder can
           be deleted - by the panel, or by somebody with a shell - and if the
           pid only lived there, the process it names would keep playing with
           nothing left able to find or stop it. */
        'os_pid'     => 'INTEGER NOT NULL DEFAULT 0',
        /* How many times the panel has restarted this by itself after a
           failure. Bounded, so a genuinely broken account is tried a few
           times and then left alone rather than retried forever. */
        'retries'    => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($additions as $name => $type) {
        if (!in_array($name, $procs, true)) {
            $pdo->exec('ALTER TABLE processes ADD COLUMN ' . $name . ' ' . $type);
        }
    }

    /* Retire the old default account.

       admin/admin was a guessable name and a guessable password on a panel
       that launches processes - the worst pair there is. This renames it only
       when the password is still the default one, so an account someone has
       already secured is left alone, and it runs once. */
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute(['admin']);
    $old = $stmt->fetch();
    if ($old && password_verify('admin', (string)$old['password_hash'])) {
        $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $taken->execute(['karim']);
        if (!$taken->fetch()) {
            $pdo->prepare('UPDATE users SET username = ?, password_hash = ? WHERE id = ?')
                ->execute(['karim', password_hash('karimadm', PASSWORD_DEFAULT),
                           (int)$old['id']]);
        }
    }
}

function seed(PDO $pdo): void
{
    /* The first account is the owner. There is no way to make one from the
       sign-up form and an owner cannot be edited by its peers, so a fresh
       install has to come with exactly one or the panel has no top. */
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, role, credits)
         VALUES (?, ?, 'owner', ?)"
    )->execute(['karim', password_hash('karimadm', PASSWORD_DEFAULT), 999]);

    $keys = $pdo->prepare(
        'INSERT INTO keys (code, credits, max_uses, note) VALUES (?, ?, ?, ?)'
    );
    $keys->execute(['GGLP-TRIAL-0001', 1, 1, 'single trial']);
    $keys->execute(['GGLP-PACK5-0001', 5, 10, 'five credits, ten people']);
}

function log_line(int $processId, string $message): void
{
    db()->prepare('INSERT INTO logs (process_id, message) VALUES (?, ?)')
        ->execute([$processId, $message]);
}

/* Settings are read once per request and kept in a plain array rather than a
   function static, so a write can invalidate them. A page that saves a setting
   and then renders would otherwise show the value it just replaced. */
function settings_all(): array
{
    static $cache = null;
    if ($cache === null || !empty($GLOBALS['__settings_dirty'])) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = (string)$row['value'];
        }
        $GLOBALS['__settings_dirty'] = false;
    }
    return $cache;
}

function setting(string $key, string $fallback = ''): string
{
    $all = settings_all();
    $value = $all[$key] ?? '';
    return $value !== '' ? $value : $fallback;
}

function setting_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    )->execute([$key, $value]);
    $GLOBALS['__settings_dirty'] = true;
}

/* A random value created once per installation, used to derive folder names
   that are stable and opaque without being derived from anything secret. */
function install_secret(): string
{
    $value = setting('install_secret');
    if ($value === '') {
        $value = bin2hex(random_bytes(24));
        setting_set('install_secret', $value);
    }
    return $value;
}

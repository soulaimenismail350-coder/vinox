<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* Encryption for the one thing in this database that does not belong to us:
 * somebody's login to their own game account.
 *
 * The threat this actually answers is a copy of the database getting out - a
 * misconfigured web server serving data/ as a static file, a backup left in a
 * public folder, a stolen disk image. Password hashes survive that; a column
 * holding a live access token in the clear does not. So the three credential
 * columns on processes are sealed at rest and opened only at the two moments
 * something really needs the plaintext: writing the run folder the bot reads,
 * and handing a row to the worker endpoint.
 *
 * The run folder itself stays in the clear, deliberately. The bot is Python
 * reading account_id.json; anything else would mean shipping the key to it,
 * and a key sitting beside the thing it encrypts protects nobody. What keeps
 * that folder private is the filesystem - 0700, outside anything the web
 * server should be serving - which is a different mechanism for a different
 * threat, and the right one for this half.
 *
 * A value that was never sealed is returned untouched, which is what lets rows
 * written before this existed keep working while secrets_migrate() catches up
 * with them.
 */

const SECRET_FILE = __DIR__ . '/secret.php';
const SECRET_CIPHER = 'aes-256-gcm';
const SECRET_PREFIX = 'enc:v1:';

/* 32 raw bytes, made once and kept in a PHP file rather than in the database.
 *
 * That split is the entire point: the database is the thing most likely to
 * leave the machine by accident, and a key stored inside it would travel with
 * the rows it protects. A .php file is executed rather than served, so even a
 * web server handing out everything it can find returns nothing for this one.
 *
 * Losing it makes every stored login unreadable - back it up alongside the
 * database, never instead of it.
 */
function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    /* Once a request has had to fall back, every later request falls back too,
       even if the directory becomes writable afterwards.
     *
     * Generating a file key at that point would leave every row already sealed
       with the database key unreadable - silently, and only noticed when a
       process next tries to start. A key in a worse place is a weaker threat
       model; a key that changes underneath existing ciphertext is data loss,
       and of the two only one is recoverable. */
    if (setting('secret_in_db') === '1') {
        return $key = hash('sha256', 'gglp-secret|' . install_secret(), true);
    }

    $read = static function (): ?string {
        if (!is_file(SECRET_FILE)) {
            return null;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(SECRET_FILE, true);
        }
        $stored = @include SECRET_FILE;
        return (is_string($stored) && strlen($stored) === 64 && ctype_xdigit($stored))
            ? $stored : null;
    };

    $hex = $read();

    if ($hex === null) {
        $body = "<?php\n"
              . "/* Generated once, on the first request after this file was missing.\n"
              . "   It is the key every stored game login is encrypted with: lose it and\n"
              . "   they are all unreadable, so back it up WITH the database rather than\n"
              . "   instead of it. Never commit it and never copy it into a run folder. */\n"
              . "return '" . bin2hex(random_bytes(32)) . "';\n";

        $tmp = SECRET_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, SECRET_FILE)) {
                @unlink($tmp);
            }
        }

        /* Re-read rather than trusting what was just generated. Two requests
           arriving together both write a file and only one rename survives;
           the loser has to use the winner's key, or everything it encrypts in
           this request becomes unreadable the moment the request ends. */
        $hex = $read();
    }

    if ($hex === null) {
        /* The directory is not writable. Falling back keeps the panel working
           rather than fataling on every page, but the key now lives in the
           same database as the rows, which is most of the protection gone -
           hence the log, and the flag above so the choice is made once. */
        setting_set('secret_in_db', '1');
        error_log('gglp: cannot write includes/secret.php - '
            . 'stored logins fall back to a key held in the database');
        return $key = hash('sha256', 'gglp-secret|' . install_secret(), true);
    }

    return $key = (string)hex2bin($hex);
}

/* Memoised: secrets_migrate() runs this once per row, and
   openssl_get_cipher_methods() builds a list of every cipher the build has
   each time it is called. */
function crypto_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array(SECRET_CIPHER, (array)openssl_get_cipher_methods(), true);
    }
    return $ok;
}

/* Plaintext in, storable string out. AES-256-GCM, so the tag makes a tampered
   row fail to open rather than decrypt to something attacker-chosen. */
function seal(string $plain): string
{
    if ($plain === '' || !crypto_available()) {
        return $plain;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain, SECRET_CIPHER, app_key(), OPENSSL_RAW_DATA, $iv, $tag
    );
    if ($cipher === false) {
        error_log('gglp: openssl_encrypt failed; storing in the clear');
        return $plain;
    }
    return SECRET_PREFIX . base64_encode($iv . $tag . $cipher);
}

/* The other direction. Anything without the marker is a row written before
   this file existed and is handed back exactly as it is - that is what stops
   an upgrade turning every live process into a broken login at once. */
function unseal(string $stored): string
{
    if (strncmp($stored, SECRET_PREFIX, strlen(SECRET_PREFIX)) !== 0) {
        return $stored;
    }
    /* Sealed data but no openssl means the extension was removed from under a
       working install. Returning empty is right - there is no plaintext to be
       had - and it must not be a fatal, or every page listing a process dies
       with it. */
    if (!crypto_available()) {
        error_log('gglp: sealed credentials found but openssl is unavailable');
        return '';
    }
    $raw = base64_decode(substr($stored, strlen(SECRET_PREFIX)), true);
    /* 12 of iv, 16 of tag, and at least one byte of anything. */
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $plain = openssl_decrypt(
        substr($raw, 28),
        SECRET_CIPHER,
        app_key(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    return $plain === false ? '' : $plain;
}

/* For anything that has to put a credential on screen at all.
 *
 * A uid is what a card is labelled with before the bot has read the real
 * in-game name, so it cannot simply be dropped - but the whole of it does not
 * need to be in the page either. Four characters is enough to tell two of your
 * own accounts apart and not enough to be one. */
function mask_secret(string $value, int $keep = 4): string
{
    $len = strlen($value);
    if ($len === 0) {
        return '';
    }
    if ($len <= $keep) {
        return str_repeat("\u{2022}", $len);
    }
    return str_repeat("\u{2022}", min(8, $len - $keep)) . substr($value, -$keep);
}

/* --- Sealing the request itself --------------------------------------------
 *
 * What this is for, precisely, because it is easy to oversell.
 *
 * The site is behind TLS, so nobody on the wire reads a form post. What TLS
 * does not cover is everything that legitimately holds the plaintext on the
 * way past: a CDN or WAF that terminates the connection and sees the decrypted
 * body, a reverse proxy's access log with POST bodies turned on, an error
 * tracker that captures request data, a browser extension with host access.
 * Today a guest uid and password sit in that body in the clear, exactly as the
 * captured payload showed, and every one of those sees them.
 *
 * So the sensitive fields are encrypted in the browser to a key only this
 * server holds, and only this server's PHP ever sees them. TLS still does the
 * work against the network; this closes the middle.
 *
 * ECDH on P-256 rather than RSA, because "light" was the requirement: the
 * browser does one key generation and one derivation - well under a
 * millisecond - and the server does one derivation. An RSA-2048 wrap would be
 * an order of magnitude more work on both ends for the same result, and PHP's
 * openssl_private_decrypt() only offers OAEP with SHA-1 anyway.
 *
 * Per submit: the browser makes a throwaway keypair, derives a shared secret
 * against the server's fixed public key, hashes it to an AES-256-GCM key, and
 * sends its own public key alongside the ciphertext. Nothing is reused between
 * requests, so a captured body is worth one decryption to the server and
 * nothing to anybody else.
 *
 * Everything degrades to a plain form post: no keypair, no WebCrypto, an old
 * browser, http rather than https - the field arrives as itself and the page
 * still works.
 */
const KEYPAIR_FILE = __DIR__ . '/keypair.php';

/* The server's long-lived P-256 keypair, as [private PEM, public DER base64].
   Same reasoning as app_key(): a .php file rather than the database, because
   the database is the thing that leaves by accident. */
function request_keypair(): ?array
{
    static $pair = null;
    static $tried = false;
    if ($tried) {
        return $pair;
    }
    $tried = true;

    if (!function_exists('openssl_pkey_new') || !function_exists('openssl_pkey_derive')) {
        return null;
    }

    $read = static function (): ?array {
        if (!is_file(KEYPAIR_FILE)) {
            return null;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(KEYPAIR_FILE, true);
        }
        $data = @include KEYPAIR_FILE;
        return (is_array($data) && !empty($data['private']) && !empty($data['public']))
            ? $data : null;
    };

    $data = $read();

    if ($data === null) {
        $res = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($res === false) {
            error_log('gglp: cannot generate the request keypair; '
                . 'form payloads will be posted in the clear');
            return null;
        }
        $private = '';
        openssl_pkey_export($res, $private);
        $details = openssl_pkey_get_details($res);

        /* The public half goes to the browser as base64 DER, which is what
           crypto.subtle.importKey('spki', ...) wants. Strip the PEM armour
           rather than asking the browser to parse it. */
        $pem = (string)($details['key'] ?? '');
        $der = preg_replace('~-----[A-Z ]+-----|\s~', '', $pem);

        if ($private === '' || $der === '') {
            return null;
        }

        $body = "<?php\n"
              . "/* The server half of the browser-to-PHP encryption. Generated once.\n"
              . "   Replacing it is harmless - it protects data in flight, not at rest -\n"
              . "   but it must never be published, and it is not a backup concern. */\n"
              . 'return ' . var_export(['private' => $private, 'public' => $der], true) . ";\n";

        $tmp = KEYPAIR_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, KEYPAIR_FILE)) {
                @unlink($tmp);
            }
        }
        /* Re-read for the same reason app_key() does: whoever won the rename
           owns the key, and a request using its own losing copy would hand the
           browser a public key the next request cannot match. */
        $data = $read();
    }

    return $pair = $data;
}

/* Base64 DER for the page, or '' when sealing is not available - which is what
   makes the browser side switch itself off rather than fail. */
function request_public_key(): string
{
    $pair = request_keypair();
    return $pair === null ? '' : (string)$pair['public'];
}

/* Open a sealed form post and merge it back into $_POST.
 *
 * Called from check_csrf(), so every POST in the panel goes through it and no
 * page has to remember to. A body that was never sealed passes straight
 * through untouched.
 *
 * Anything malformed is dropped in silence rather than merged half-decoded:
 * the field it carried then reads as absent, and the page's own validation
 * says so in its own words.
 */
function unseal_request(): void
{
    $blob = $_POST['sealed'] ?? '';
    if (!is_string($blob) || $blob === '') {
        return;
    }
    unset($_POST['sealed']);

    $pair = request_keypair();
    if ($pair === null || !crypto_available()) {
        return;
    }

    $parts = explode('.', $blob);
    if (count($parts) !== 3) {
        return;
    }
    [$peer, $iv, $body] = array_map(
        static fn(string $p): string => (string)base64_decode($p, true),
        $parts
    );
    /* 12 of nonce, 16 of tag, at least one byte of anything. */
    if ($peer === '' || strlen($iv) !== 12 || strlen($body) < 17) {
        return;
    }

    /* Back into PEM: the browser sends raw DER and openssl_pkey_derive wants
       armour. Anything that is not a key fails on the next line. */
    $peerPem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($peer), 64, "\n")
             . "-----END PUBLIC KEY-----\n";

    $shared = @openssl_pkey_derive($peerPem, $pair['private'], 32);
    if (!is_string($shared) || $shared === '') {
        return;
    }

    /* One SHA-256 over the raw shared secret, matched exactly on the browser
       side. A full HKDF would be more orthodox and buys nothing here - there
       is one purpose, one algorithm and one message per secret, which is the
       case HKDF's info and salt exist to disambiguate. */
    $json = openssl_decrypt(
        substr($body, 0, -16),
        SECRET_CIPHER,
        hash('sha256', $shared, true),
        OPENSSL_RAW_DATA,
        $iv,
        substr($body, -16)
    );
    if ($json === false) {
        return;
    }

    $fields = json_decode($json, true);
    if (!is_array($fields)) {
        return;
    }
    foreach ($fields as $name => $value) {
        /* Scalars only, and never a nested array: this is a form body being
           reconstructed, and letting it introduce structure would let a sealed
           payload reach code that trusted $_POST to be flat. */
        if (is_string($name) && (is_string($value) || is_numeric($value))) {
            $_POST[$name] = (string)$value;
        }
    }
}

/* Seal whatever is still in the clear, once.
 *
 * Guarded by a settings row rather than by looking at the data, so it is one
 * lookup on every later request instead of a scan. Rows added after this ran
 * are sealed on the way in, so there is nothing for it to come back to.
 */
function secrets_migrate(): void
{
    if (setting('secrets_sealed') === '1' || !crypto_available()) {
        return;
    }

    $rows = db()->query(
        'SELECT id, uid, password, access_token FROM processes'
    )->fetchAll();

    $stmt = db()->prepare(
        'UPDATE processes SET uid = ?, password = ?, access_token = ? WHERE id = ?'
    );
    foreach ($rows as $row) {
        $fields = [];
        $touched = false;
        foreach (['uid', 'password', 'access_token'] as $name) {
            $value = (string)$row[$name];
            if ($value !== '' && strncmp($value, SECRET_PREFIX, strlen(SECRET_PREFIX)) !== 0) {
                $touched = true;
                $value = seal($value);
            }
            $fields[] = $value;
        }
        if ($touched) {
            $fields[] = (int)$row['id'];
            $stmt->execute($fields);
        }
    }

    setting_set('secrets_sealed', '1');
}

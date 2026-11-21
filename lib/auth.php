<?php
/**
 * Offline — accounts and portal sessions.
 *
 * An account belongs to an adult or to a child. Adults sign in to the portal
 * to reach the whole library, every film and the system pages; without a
 * session the portal shows the children's pages and nothing else. A record
 * holds two password hashes, never a password: Argon2id for the portal and
 * SHA-512-crypt for logging in to a workstation.
 *
 * This file is loaded by the "offline user" command and by the sign-in pages,
 * which run in a PHP pool of their own. It runs no program and opens no
 * connection. The ordinary portal pages never load it: they can read a
 * session (core.php) and cannot open one.
 */

declare(strict_types=1);

namespace Offline;

const ACCOUNT_CLASSES = ['adult', 'child'];
/** A session lasts this long from sign-in, whatever its use. */
const SESSION_SECONDS = 43200;
/** Wrong guesses allowed within the window: for one account from one address, and from one address in all. */
const THROTTLE_PAIR_LIMIT = 5;
const THROTTLE_ADDRESS_LIMIT = 20;
const THROTTLE_WINDOW = 900;
const ARGON2_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];
/** Verified against when no usable account matches, so that a refusal takes as long either way. */
const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$a1BXWEI3QkFBbUlYc3VwUQ$o9N9s0iypp1szY4he7O5gOiVoSgkymqjBOln20kCMys';
/** Names that would collide with the system's own accounts or mislead a reader. */
const RESERVED_ACCOUNT_NAMES = [
    'root', 'offline', 'offline-web', 'offline-auth', 'offline-child', 'offline-session', 'admin', 'administrator',
    'daemon', 'nobody', 'www-data', 'sudo', 'adm', 'sys', 'bin', 'games', 'guest', 'parent', 'system', 'ubuntu',
];

function account_name_ok(string $name): bool
{
    return preg_match('/^[a-z][a-z0-9-]{1,30}$/', $name) === 1 && !in_array($name, RESERVED_ACCOUNT_NAMES, true);
}

/** Whether a record has exactly the shape this code writes. Records from other systems are held to it. */
function account_record_ok(mixed $record): bool
{
    if (!is_array($record) || array_diff(array_keys($record), ['class', 'full', 'portal', 'login', 'locked', 'removed', 'updated']) !== []) {
        return false;
    }
    $updated = (string) ($record['updated'] ?? '');
    return in_array($record['class'] ?? null, ACCOUNT_CLASSES, true)
        && is_string($record['full'] ?? null) && preg_match('/^[\p{L}\p{N} .\'\-]{0,60}$/u', $record['full']) === 1
        && (($record['portal'] ?? null) === null || (is_string($record['portal'])
            && preg_match('/^\$argon2id\$v=19\$m=\d{4,7},t=\d{1,2},p=\d{1,2}\$[A-Za-z0-9+\/]{11,64}\$[A-Za-z0-9+\/]{32,128}$/', $record['portal']) === 1))
        && (($record['login'] ?? null) === null || (is_string($record['login'])
            && preg_match('/^\$6\$rounds=\d{4,7}\$[.\/A-Za-z0-9]{8,16}\$[.\/A-Za-z0-9]{86}$/', $record['login']) === 1))
        // Children look at the children's pages, which need no sign-in, so a child's record carries no portal password.
        && !($record['class'] === 'child' && ($record['portal'] ?? null) !== null)
        && is_bool($record['locked'] ?? null) && is_bool($record['removed'] ?? null)
        && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $updated) === 1
        // A record dated well into the future would win every comparison for ever.
        && ($stamp = strtotime($updated)) !== false && $stamp <= time() + 86400;
}

/** Every valid record, by account name. Removed accounts stay as markers, so that a removal can travel. */
function accounts_load(?string $file = null): array
{
    $accounts = [];
    foreach (read_json($file ?? path('accounts'))['accounts'] ?? [] as $name => $record) {
        if (is_string($name) && account_name_ok($name) && account_record_ok($record)) {
            $accounts[$name] = $record;
        }
    }
    ksort($accounts);
    return $accounts;
}

function auth_write(string $file, string $data, int $mode): bool
{
    $tmp = $file . '.new.' . getmypid();
    if (@file_put_contents($tmp, $data) === false) {
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function accounts_save(array $accounts): bool
{
    ksort($accounts);
    $json = json_encode(['version' => 1, 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) && auth_write(path('accounts'), $json . "\n", 0640);
}

/**
 * Take from another system's accounts whatever is newer than what is held
 * here. Returns the merged set and the names that changed. Nothing is taken
 * on trust: a record that is not exactly the right shape is ignored.
 */
function accounts_merge(array $mine, array $theirs): array
{
    $changed = [];
    foreach ($theirs as $name => $record) {
        if (!is_string($name) || !account_name_ok($name) || !account_record_ok($record)) {
            continue;
        }
        if (!isset($mine[$name]) || strcmp($record['updated'], $mine[$name]['updated']) > 0) {
            $mine[$name] = $record;
            $changed[] = $name;
        }
    }
    ksort($mine);
    return [$mine, $changed];
}

/** Why a password will not do, or null when it will. */
function password_problem(string $password, string $class, string $name = ''): ?string
{
    $minimum = $class === 'child' ? 6 : 12;
    return match (true) {
        strlen($password) > 256 => 'A password may be at most 256 characters.',
        preg_match('/[\x00-\x1f\x7f]/', $password) === 1 => 'A password may not contain control characters.',
        mb_strlen($password) < $minimum => "A password for " . ($class === 'child' ? 'a child' : 'an adult') . " needs at least $minimum characters.",
        $name !== '' && stripos($password, $name) !== false => 'A password may not contain the account name.',
        count(array_unique(mb_str_split($password))) < 4 => 'A password needs more than three different characters.',
        default => null,
    };
}

function portal_hash(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID, ARGON2_OPTIONS);
}

/** A hash in the form /etc/shadow holds, for logging in to a workstation. */
function login_hash(string $password): string
{
    $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $salt = '';
    foreach (str_split(random_bytes(16)) as $byte) {
        $salt .= $alphabet[ord($byte) & 63];
    }
    return crypt($password, '$6$rounds=200000$' . $salt . '$');
}

// ---------------------------------------------------------------------------
// Sessions. One line per session in a file on a memory-backed filesystem,
// so that every session ends when the system restarts.
// ---------------------------------------------------------------------------

/** Hold the lock while reading, changing and writing session or throttle state. */
function auth_lock()
{
    $handle = @fopen(dirname(path('sessions')) . '/.lock', 'c');
    if ($handle !== false) {
        flock($handle, LOCK_EX);
    }
    return $handle;
}

/** Live sessions: token => [user, expiry]. */
function sessions_load(): array
{
    $sessions = [];
    foreach (@file(path('sessions'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Za-z0-9_-]{43}) adult:([a-z][a-z0-9-]{1,30}):(\d{10})$/', $line, $m) === 1 && (int) $m[3] > time()) {
            $sessions[$m[1]] = [$m[2], (int) $m[3]];
        }
    }
    return $sessions;
}

function sessions_save(array $sessions): bool
{
    $lines = '';
    foreach ($sessions as $token => [$user, $expires]) {
        $lines .= "$token adult:$user:$expires\n";
    }
    return auth_write(path('sessions'), $lines, 0640);
}

/** Open a session and return its token: 256 random bits, which is all the cookie holds. */
function session_open(string $user): ?string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $lock = auth_lock();
    $sessions = sessions_load();
    $sessions[$token] = [$user, time() + SESSION_SECONDS];
    $ok = sessions_save($sessions);
    if ($lock !== false) {
        fclose($lock);
    }
    return $ok ? $token : null;
}

/** End one session, every session of one account, or (with neither) only those that have run out. */
function sessions_close(?string $token = null, ?string $user = null): int
{
    $lock = auth_lock();
    $before = @file(path('sessions'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $sessions = sessions_load();
    foreach ($sessions as $held => [$owner]) {
        if (($token !== null && hash_equals($held, $token)) || ($user !== null && $owner === $user)) {
            unset($sessions[$held]);
        }
    }
    $ended = count($before) - count($sessions);
    if ($ended > 0 || !is_file(path('sessions'))) {
        sessions_save($sessions);
    }
    if ($lock !== false) {
        fclose($lock);
    }
    return $ended;
}

// ---------------------------------------------------------------------------
// Guessing is slowed down
// ---------------------------------------------------------------------------

function throttle_state(): array
{
    $state = [];
    foreach (read_json(path('throttle')) as $key => $entry) {
        if (is_string($key) && preg_match('/^[pa]:[0-9a-f]{64}$/', $key) === 1 && is_array($entry)
            && is_int($entry[0] ?? null) && is_int($entry[1] ?? null) && time() - $entry[1] < THROTTLE_WINDOW) {
            $state[$key] = [$entry[0], $entry[1]];
        }
    }
    return $state;
}

function throttle_keys(string $user, string $address): array
{
    return [
        'p:' . hash('sha256', "$user|$address") => THROTTLE_PAIR_LIMIT,
        'a:' . hash('sha256', $address) => THROTTLE_ADDRESS_LIMIT,
    ];
}

/** Seconds before another attempt is heard, or 0. */
function throttle_wait(string $user, string $address): int
{
    $state = throttle_state();
    $wait = 0;
    foreach (throttle_keys($user, $address) as $key => $limit) {
        if (($state[$key][0] ?? 0) >= $limit) {
            $wait = max($wait, THROTTLE_WINDOW - (time() - $state[$key][1]));
        }
    }
    return $wait;
}

function throttle_record(string $user, string $address, bool $failed): void
{
    $lock = auth_lock();
    $state = throttle_state();
    foreach (throttle_keys($user, $address) as $key => $limit) {
        if ($failed) {
            $state[$key] = [($state[$key][0] ?? 0) + 1, $state[$key][1] ?? time()];
        } elseif ($key[0] === 'p') {
            unset($state[$key]);
        }
    }
    auth_write(path('throttle'), (string) json_encode($state), 0600);
    if ($lock !== false) {
        fclose($lock);
    }
}

/**
 * Check a name and password. Returns ['ok' => true, 'token' => ..., 'user' => ...]
 * or ['ok' => false, 'wait' => seconds]. The refusal never says which of the
 * name and the password was wrong, and takes as long whichever it was.
 */
function sign_in(string $user, string $password, string $address): array
{
    $user = strtolower(trim($user));
    if (strlen($user) > 64 || strlen($password) > 256) {
        return ['ok' => false, 'wait' => 0];
    }
    $wait = throttle_wait($user, $address);
    if ($wait > 0) {
        return ['ok' => false, 'wait' => $wait];
    }
    $record = account_name_ok($user) ? (accounts_load()[$user] ?? null) : null;
    $usable = $record !== null && $record['class'] === 'adult' && !$record['locked'] && !$record['removed'] && $record['portal'] !== null;
    $matches = password_verify($password, $usable ? $record['portal'] : DUMMY_HASH);
    if (!$usable || !$matches) {
        throttle_record($user, $address, true);
        return ['ok' => false, 'wait' => throttle_wait($user, $address)];
    }
    throttle_record($user, $address, false);
    $token = session_open($user);
    return $token === null ? ['ok' => false, 'wait' => 0] : ['ok' => true, 'token' => $token, 'user' => $user];
}

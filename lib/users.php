<?php
/**
 * Offline — people: "offline user" and "offline tls".
 *
 * An account is made once, on any system, with "offline user add". Its record
 * travels to every other system with the next sync, and each system then
 * brings its own login accounts into line (offline-accounts.service): the
 * account can sign in to every portal and log in to every system, with one
 * password, and a change of password, a lock or a removal follows the same way.
 *
 * Three things do not travel. Administrator rights are given only on the
 * system where --admin is typed, and replication never changes the login
 * password of an account that has them. A child's login account is made only
 * where there is a desktop to log in to. A server exposed to the internet
 * takes the records and makes no login accounts (ACCOUNT_LOGINS=yes overrides).
 *
 * A child's login account has no administrator rights, belongs to no group
 * but its own, and is recognised by the children's guard (a firewall rule on
 * the group) which lets it reach the portal, the children's shelf, the games
 * and nothing else: not the internet, and not the services this system keeps
 * for adults.
 */

declare(strict_types=1);

namespace Offline;

const CHILD_GROUP = 'offline-child';
const AUTH_USER = 'offline-auth';
const SESSION_GROUP = 'offline-session';
const CHILDREN_GUARD = 'offline-children-guard.service';
/** Programs a child's desktop does not offer, where the desktop's parental controls are installed. */
const CHILD_BLOCKED_PROGRAMS = [
    '/usr/bin/gnome-terminal', '/usr/bin/ptyxis', '/usr/bin/kgx', '/usr/bin/xterm', '/usr/bin/gnome-software', '/usr/bin/snap-store',
    '/snap/bin/snap-store', '/usr/bin/software-properties-gtk', '/usr/bin/update-manager', '/usr/bin/gnome-disks',
    '/usr/bin/kiwix-desktop', '/usr/bin/vlc', '/usr/bin/gqrx', '/usr/bin/chirpw', '/opt/jdownloader/JDownloader2', '/usr/bin/msty',
];

/** Run a program with text on its standard input, so that a secret never appears in a process list. */
function run_input(array $command, string $input, int $timeout = 60): array
{
    if (!have($command[0])) {
        return [127, '', $command[0] . ' is not installed'];
    }
    $proc = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, child_env());
    if (!\is_resource($proc)) {
        return [126, '', 'could not start ' . $command[0]];
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], $timeout);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

/** Whether this system has a desktop that people log in to. */
function has_desktop(): bool
{
    foreach (['/usr/sbin/gdm3', '/usr/bin/sddm', '/usr/sbin/lightdm', '/usr/bin/gnome-shell'] as $program) {
        if (is_executable(prefix() . $program)) {
            return true;
        }
    }
    return false;
}

/**
 * Read a new password without echoing it: twice from a terminal, once from a
 * pipe, or from the first line of a private file given with --password-file.
 * It is never taken from the command line, where other users could see it.
 */
function read_new_password(array &$args, string $class, string $name): string
{
    $index = array_search('--password-file', $args, true);
    if ($index !== false) {
        $file = (string) ($args[$index + 1] ?? '');
        if (!is_file($file) || ((int) fileperms($file) & 0077) !== 0) {
            fail('--password-file needs a file that only you can read (chmod 600).');
        }
        $password = rtrim((string) (@file($file)[0] ?? ''), "\r\n");
        array_splice($args, (int) $index, 2);
    } elseif (\function_exists('posix_isatty') && posix_isatty(STDIN)) {
        run_live(['stty', '-echo']);
        try {
            fwrite(STDOUT, "New password for $name: ");
            $password = rtrim((string) fgets(STDIN), "\r\n");
            fwrite(STDOUT, "\nAgain: ");
            $again = rtrim((string) fgets(STDIN), "\r\n");
            fwrite(STDOUT, "\n");
        } finally {
            run_live(['stty', 'echo']);
        }
        if (!hash_equals($password, $again)) {
            fail('The two did not match. Nothing was changed.');
        }
    } else {
        $password = rtrim((string) fgets(STDIN), "\r\n");
    }
    $problem = password_problem($password, $class, $name);
    if ($problem !== null) {
        fail($problem);
    }
    return $password;
}

function accounts_dir_ready(): void
{
    $dir = \dirname(path('accounts'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (prefix() === '' && is_root()) {
        @chown($dir, SERVICE_USER);
        @chgrp($dir, AUTH_USER);
        @chmod($dir, 02750);
    }
}

function accounts_store(array $accounts): void
{
    accounts_dir_ready();
    if (!accounts_save($accounts)) {
        fail('The accounts could not be written to ' . path('accounts') . '.');
    }
    if (prefix() === '' && is_root()) {
        // The sign-in pages read the file; the sharing daemon serves it to systems holding the house key.
        @chown(path('accounts'), SERVICE_USER);
        @chgrp(path('accounts'), AUTH_USER);
        @chmod(path('accounts'), 0640);
    }
}

/** End an account's portal sessions, leaving the session file as the sign-in pages expect to find it. */
function end_sessions(string $name): int
{
    if (!is_dir(\dirname(path('sessions')))) {
        return 0;
    }
    $ended = sessions_close(null, $name);
    if (prefix() === '' && is_root() && is_file(path('sessions'))) {
        @chown(path('sessions'), AUTH_USER);
        @chgrp(path('sessions'), SESSION_GROUP);
    }
    return $ended;
}

function login_account(string $name): ?array
{
    if (prefix() !== '' || !\function_exists('posix_getpwnam')) {
        return null;
    }
    $entry = posix_getpwnam($name);
    return $entry === false ? null : $entry;
}

function children_guard_active(): bool
{
    return prefix() === '' && have('nft') && run(['nft', 'list', 'table', 'inet', 'offline_children'], 10)[0] === 0;
}

/** Whether this system should have a login account for a record at all. */
function login_wanted(array $record): bool
{
    $policy = strtolower((string) config('ACCOUNT_LOGINS'));
    if ($record['login'] === null || $policy === 'no') {
        return false;
    }
    if ($record['class'] === 'child') {
        // A child logs in where there is a screen and a keyboard, and nowhere else.
        return has_desktop();
    }
    return $policy === 'yes' || (string) config('SYNC_EXPOSE') !== 'internet';
}

function is_administrator(string $name): bool
{
    foreach (['sudo', 'admin'] as $group) {
        $entry = posix_getgrnam($group);
        if ($entry !== false && in_array($name, $entry['members'], true)) {
            return true;
        }
    }
    return false;
}

/** The password hash this system holds for an account, without any lock marker. */
function shadow_hash(string $name): ?string
{
    [$code, $line] = run(['getent', 'shadow', $name], 10);
    $fields = explode(':', trim($line));
    return $code === 0 && count($fields) > 1 ? ltrim($fields[1], '!') : null;
}

/**
 * Bring this system's login account for one record into line with it: create
 * it, set its password to the household's, lock it, or leave it be. Returns a
 * word for what was done. One of the system's own accounts is never touched.
 * When the change arrived from another system ($replicated), an account that
 * has administrator rights here is never touched either; that takes root, here.
 */
function login_account_apply(string $name, array $record, bool $replicated, bool $admin = false): string
{
    if (prefix() !== '') {
        return 'unchanged';
    }
    foreach (['useradd', 'usermod', 'chpasswd'] as $tool) {
        if (!have($tool)) {
            return 'failed';
        }
    }
    $existing = login_account($name);
    if ($existing !== null && $existing['uid'] < 1000) {
        return 'system';
    }
    if ($existing !== null && $replicated && is_administrator($name)) {
        return 'administrator';
    }
    $child = $record['class'] === 'child';
    if ($record['removed'] || $record['locked']) {
        // Locked or removed: no login here either. The account's files are kept.
        if ($existing === null || str_starts_with((string) (explode(':', trim(run(['getent', 'shadow', $name], 10)[1]))[1] ?? '!'), '!')) {
            return 'unchanged';
        }
        return sys(['usermod', '--lock', '--expiredate', '1', $name]) ? 'locked' : 'failed';
    }
    if (!login_wanted($record)) {
        return 'unchanged';
    }
    $done = 'unchanged';
    if ($existing === null) {
        $create = $child
            ? ['useradd', '--create-home', '--no-user-group', '--gid', CHILD_GROUP, '--shell', '/bin/bash', '--comment', $record['full'], $name]
            : ['useradd', '--create-home', '--user-group', '--shell', '/bin/bash', '--comment', $record['full'], $name];
        if (!sys($create)) {
            return 'failed';
        }
        $done = 'created';
        $existing = login_account($name);
    } elseif ($child && (posix_getgrgid($existing['gid'])['name'] ?? '') !== CHILD_GROUP) {
        // An existing account becoming a child's: its own group goes, and with it every other membership.
        sys(['usermod', '--gid', CHILD_GROUP, $name]);
        $done = 'changed';
    }
    [, $shadow] = run(['getent', 'shadow', $name], 10);
    $held = (string) (explode(':', trim($shadow))[1] ?? '');
    if (str_starts_with($held, '!') && $done !== 'created') {
        sys(['usermod', '--unlock', '--expiredate', '', $name]);
        $done = 'unlocked';
    }
    if (ltrim($held, '!') !== $record['login']) {
        // One password for a person, the same on every system.
        [$code, , $error] = run_input(['chpasswd', '--encrypted'], "$name:{$record['login']}\n");
        if ($code !== 0) {
            warn('The login password could not be set: ' . printable(trim($error), 200));
            return 'failed';
        }
        $done = $done === 'unchanged' ? 'password' : $done;
    }
    if ($existing !== null && is_dir($existing['dir'])) {
        @chmod($existing['dir'], 0700);
    }
    if ($child) {
        // No administrator rights, no content group, no hardware groups: nothing but the children's group.
        if (trim(run(['id', '-Gn', $name], 10)[1]) !== CHILD_GROUP) {
            sys(['usermod', '--groups', '', $name]);
        }
        if ($done === 'created' || $done === 'changed') {
            child_desktop($name, $existing['dir'] ?? "/home/$name");
        }
    } else {
        $groups = array_values(array_filter(['offline', 'dialout', 'plugdev'], static fn (string $g): bool => posix_getgrnam($g) !== false));
        if ($admin && !$replicated && posix_getgrnam('sudo') !== false) {
            $groups[] = 'sudo';
        }
        $held = explode(' ', trim(run(['id', '-Gn', $name], 10)[1]));
        if (array_diff($groups, $held) !== []) {
            sys(['usermod', '--append', '--groups', implode(',', $groups), $name]);
        }
    }
    return $done;
}

/** What a child finds on logging in: the children's pages, and a desktop without the tools that are not theirs. */
function child_desktop(string $name, string $home): void
{
    if (!is_dir($home)) {
        return;
    }
    $autostart = "$home/.config/autostart";
    if (!is_dir($autostart)) {
        @mkdir($autostart, 0700, true);
    }
    file_put_contents("$autostart/offline-children.desktop", implode("\n", [
        '[Desktop Entry]', 'Type=Application', 'Name=Offline', 'Comment=Open the family pages',
        'Exec=xdg-open http://localhost/', 'X-GNOME-Autostart-enabled=true', '',
    ]));
    foreach (["$home/.config", $autostart, "$autostart/offline-children.desktop"] as $path) {
        @chown($path, $name);
        @chgrp($path, CHILD_GROUP);
    }
    if (have('malcontent-client')) {
        // GNOME's own parental controls: the desktop neither lists nor starts these, and nothing can be installed.
        $blocked = array_values(array_filter(CHILD_BLOCKED_PROGRAMS, 'is_file'));
        if (run(array_merge(['malcontent-client', 'set-app-filter', '--disallow-user-installation', '--disallow-system-installation', $name], $blocked), 30)[0] !== 0) {
            warn("The desktop's parental controls could not be set for $name. The children's guard and the account's lack of rights still apply.");
        }
    }
}

/** --name VALUE style options, removed from the arguments as they are read. */
function take_option(array &$args, string $option): ?string
{
    $index = array_search($option, $args, true);
    if ($index === false) {
        return null;
    }
    $value = (string) ($args[$index + 1] ?? '');
    array_splice($args, (int) $index, 2);
    return $value;
}

function take_flag(array &$args, string $flag): bool
{
    $index = array_search($flag, $args, true);
    if ($index === false) {
        return false;
    }
    array_splice($args, (int) $index, 1);
    return true;
}

function command_user(array $args): int
{
    $action = $args[0] ?? 'list';
    $rest = \array_slice($args, 1);

    if ($action === 'prune-sessions') {
        // Run each minute by a timer, as the sign-in account. Sessions that have run out are dropped from the file
        // the web server reads, and so is any session whose account has since been locked, removed or given a new
        // password, here or on another system.
        sessions_close();
        if (is_readable(path('accounts'))) {
            $accounts = accounts_load();
            foreach (sessions_load() as $token => [$user, $expires]) {
                $record = $accounts[$user] ?? null;
                if ($record === null || $record['removed'] || $record['locked'] || $record['class'] !== 'adult' || $record['portal'] === null
                    || strtotime($record['updated']) > $expires - SESSION_SECONDS) {
                    sessions_close($token);
                }
            }
        }
        return 0;
    }
    if ($action === 'list') {
        if (is_file(path('accounts')) && !is_readable(path('accounts'))) {
            fail('The accounts are not readable by this account. Run: sudo offline user');
        }
        $accounts = array_filter(accounts_load(), static fn (array $r): bool => !$r['removed']);
        if ($accounts === []) {
            out('No accounts yet. The portal shows the children\'s pages to everyone, and nobody can sign in for the rest.');
        } else {
            heading('Accounts');
            table(['Name', 'Kind', 'Full name', 'Portal sign-in', 'Login here', 'Changed'], array_map(static function (string $name, array $r): array {
                $login = login_account($name);
                return [
                    $name, $r['class'], $r['full'] === '' ? '-' : $r['full'],
                    $r['locked'] ? 'locked' : ($r['class'] === 'child' ? 'not needed' : ($r['portal'] !== null ? 'yes' : 'no')),
                    $r['locked'] ? 'locked' : ($login !== null ? 'yes' : (prefix() !== '' ? '-' : 'no')), utc_display($r['updated']),
                ];
            }, array_keys($accounts), $accounts));
        }
        out();
        out('sudo offline user add NAME --adult|--child [--full "Full name"] [--admin] [--portal-only]');
        out('sudo offline user passwd NAME | lock NAME | unlock NAME | remove NAME');
        out('sudo offline user apply     bring this system\'s login accounts into line now, administrators included');
        return 0;
    }
    if (prefix() === '' && !is_root()) {
        fail("Run this with sudo: sudo offline user $action ...");
    }

    if ($action === 'apply') {
        // "--replicated" is how offline-accounts.service runs this, whenever the records change and at every start.
        $replicated = in_array('--replicated', $rest, true);
        $summary = [];
        foreach (accounts_load() as $name => $record) {
            $done = login_account_apply($name, $record, $replicated);
            if ($done !== 'unchanged') {
                $summary[$done][] = $name;
            }
        }
        $words = [
            'created' => 'Login account created for', 'password' => 'Login password brought into line for', 'locked' => 'Login locked for',
            'unlocked' => 'Login unlocked for', 'changed' => 'Made a child\'s account:', 'failed' => 'Could not be completed for',
            'administrator' => 'Left alone, being an administrator here (apply by hand: sudo offline user apply):',
            'system' => 'Left alone, being one of this system\'s own accounts:',
        ];
        foreach ($summary as $done => $names) {
            out('  ' . $words[$done] . ' ' . implode(', ', $names) . ($done === 'administrator' || $done === 'system' ? '' : '.'));
        }
        if ($summary === [] && !$replicated) {
            out('Every login account on this system already matches its record.');
        }
        return isset($summary['failed']) ? 1 : 0;
    }

    $name = strtolower((string) ($rest[0] ?? ''));
    if (!account_name_ok($name)) {
        fail('An account name is 2 to 31 lower-case letters, digits or hyphens, starting with a letter, and not a name the system uses itself.');
    }
    $rest = \array_slice($rest, 1);
    $accounts = accounts_load();
    $record = $accounts[$name] ?? null;

    if ($action === 'add') {
        $adult = take_flag($rest, '--adult');
        $child = take_flag($rest, '--child');
        if ($adult === $child) {
            fail('Say which kind of account this is: --adult or --child.');
        }
        $class = $adult ? 'adult' : 'child';
        $admin = take_flag($rest, '--admin');
        $noLogin = take_flag($rest, '--no-login') || take_flag($rest, '--portal-only');
        $full = take_option($rest, '--full') ?? '';
        if ($child && $admin) {
            fail('A child\'s account cannot be an administrator.');
        }
        if (preg_match('/^[\p{L}\p{N} .\'\-]{0,60}$/u', $full) !== 1) {
            fail('A full name may hold letters, digits, spaces, full stops, apostrophes and hyphens, up to sixty characters.');
        }
        if ($record !== null && !$record['removed']) {
            fail("There is already an account called $name. Change its password with: sudo offline user passwd $name");
        }
        $existing = login_account($name);
        if ($existing !== null && $existing['uid'] < 1000) {
            fail("\"$name\" is one of this system's own accounts.");
        }
        $password = read_new_password($rest, $class, $name);
        $record = [
            'class' => $class, 'full' => $full, 'portal' => $adult ? portal_hash($password) : null,
            'login' => $noLogin ? null : login_hash($password), 'locked' => false, 'removed' => false, 'updated' => utc_now(),
        ];
        $accounts[$name] = $record;
        accounts_store($accounts);
        out("Account $name created (" . ($adult ? 'adult' : 'child') . ').');
        if ($adult) {
            out('  Portal      signs in at https://' . (gethostname() ?: 'localhost') . '.local/account/signin.php, on this system now and on the others after their next sync.');
        } else {
            out('  Portal      the children\'s pages need no sign-in; this account cannot sign in for anything more.');
        }
        if ($noLogin) {
            out('  Login       none, on any system: this account is for the portal only.');
        } else {
            $done = login_account_apply($name, $record, false, $admin);
            out('  Login       ' . match (true) {
                $done === 'created' => 'can log in to this system with the same password' . ($admin ? ', and may administer it with sudo.' : '.'),
                $done === 'failed', $done === 'system' => 'the login account on this system could not be made.',
                $existing !== null => "the existing login account $name was kept, and now has this password and the right groups.",
                prefix() === '' && !login_wanted($record) => $child ? 'none on this system, which has no desktop.' : 'none on this system (a server exposed to the internet, or ACCOUNT_LOGINS=no).',
                default => 'follows this record.',
            });
            if ($child && $done === 'created' && !children_guard_active()) {
                warn('The children\'s guard is not running, so this account is not yet kept from the internet. Start it with: sudo systemctl enable --now ' . CHILDREN_GUARD);
            }
        }
        out('  Elsewhere   every other system holding the house key takes this account at its next sync, within about five minutes.');
        if ($admin) {
            out('              Administrator rights stay on this system; give them elsewhere with --admin on the system concerned.');
        }
        return 0;
    }

    if ($record === null || $record['removed']) {
        fail("There is no account called $name. See: offline user");
    }
    if ($action === 'passwd') {
        $password = read_new_password($rest, $record['class'], $name);
        $record = ['portal' => $record['class'] === 'adult' ? portal_hash($password) : null, 'login' => $record['login'] === null ? null : login_hash($password), 'updated' => utc_now()] + $record;
        $accounts[$name] = $record;
        accounts_store($accounts);
        $ended = end_sessions($name);
        login_account_apply($name, $record, false);
        out("The password for $name has been changed" . ($ended > 0 ? ', and ' . plural($ended, 'portal session') . ' ended.' : '.'));
        out('Every other system takes the new password at its next sync. Where ' . $name . ' is an administrator, the login password changes only by running this command there.');
        return 0;
    }
    if ($action === 'lock' || $action === 'unlock' || $action === 'remove') {
        $record = ['locked' => $action === 'lock' || ($action === 'remove' && $record['locked']), 'removed' => $action === 'remove', 'updated' => utc_now()] + $record;
        if ($action === 'remove') {
            // Only the marker travels: the hashes are of no further use to anyone.
            $record = ['portal' => null, 'login' => null] + $record;
        }
        $accounts[$name] = $record;
        accounts_store($accounts);
        $ended = $action === 'unlock' ? 0 : end_sessions($name);
        login_account_apply($name, $record, false);
        out(match ($action) {
            'lock' => "$name is locked: no portal sign-in and no login here" . ($ended > 0 ? '; ' . plural($ended, 'portal session') . ' ended.' : '.'),
            'unlock' => "$name is unlocked.",
            default => "$name has been removed. Any login account on this system is locked and its files are kept; delete them with: sudo deluser --remove-home $name",
        });
        out('Every other system follows at its next sync.');
        return 0;
    }
    fail('Usage: offline user [list]   |   sudo offline user add NAME --adult|--child [--full "Full name"] [--admin] [--portal-only] | passwd NAME | lock NAME | unlock NAME | remove NAME | apply');
}

// ---------------------------------------------------------------------------
// The household's certificate authority
// ---------------------------------------------------------------------------

/**
 * The private key of the household's certificate authority, as DER. It is
 * derived from the house key and stored nowhere: any system holding the house
 * key can sign its own certificate, and a device that has been given the
 * authority's certificate once then trusts every system in the house.
 */
function household_ca_der(): ?string
{
    $order = hex2bin('ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551');
    for ($counter = 0; $counter < 8; $counter++) {
        $hex = derived_key("offline household certificate authority v1 #$counter");
        if ($hex === null) {
            return null;
        }
        $scalar = (string) hex2bin($hex);
        // A valid P-256 private key is a number from 1 to just below the order of the curve.
        if (trim($scalar, "\0") !== '' && strcmp($scalar, (string) $order) < 0) {
            return "\x30\x31\x02\x01\x01\x04\x20" . $scalar . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        }
    }
    return null;
}

/** The names and addresses this system's certificate should answer to; all lie inside what the authority may sign for. */
function tls_names(): array
{
    $host = preg_replace('/[^a-z0-9-]/', '', strtolower((string) gethostname())) ?: 'offline';
    $names = ["DNS:$host.local", "DNS:$host.lan", 'DNS:offline.lan', 'DNS:localhost', 'IP:127.0.0.1', 'IP:' . HOTSPOT_ADDRESS];
    foreach (prefix() === '' ? own_addresses() : [] as $address) {
        if (is_private_ipv4($address['ip'])) {
            $names[] = 'IP:' . $address['ip'];
        }
    }
    return array_values(array_unique($names));
}

/**
 * Make sure this system has a certificate signed by the household's authority,
 * valid for at least another month and for its present names and addresses.
 */
function ensure_tls_certificate(bool $force = false): bool
{
    $dir = path('tls');
    $names = implode(',', tls_names());
    $current = is_file("$dir/offline.crt") && is_file("$dir/offline.key") && is_file("$dir/ca.crt")
        && trim((string) @file_get_contents("$dir/offline.names")) === $names
        && run(['openssl', 'verify', '-CAfile', "$dir/ca.crt", "$dir/offline.crt"], 30)[0] === 0
        && run(['openssl', 'x509', '-in', "$dir/offline.crt", '-noout', '-checkend', '2592000'], 30)[0] === 0;
    $der = household_ca_der();
    if ($der === null) {
        // No house key yet: an existing certificate will do until there is one.
        return is_file("$dir/offline.crt") && is_file("$dir/offline.key");
    }
    $id = substr(hash('sha256', $der), 0, 8);
    $subject = "/O=Offline household/CN=Offline household CA $id";
    $sameAuthority = is_file("$dir/ca.crt") && str_contains(run(['openssl', 'x509', '-in', "$dir/ca.crt", '-noout', '-subject'], 30)[1], "CA $id");
    if ($current && $sameAuthority && !$force) {
        return true;
    }
    @mkdir($dir, 0755, true);
    $work = "$dir/.work." . getmypid();
    $previous = umask(0077);
    @mkdir($work, 0700);
    $steps = [];
    try {
        file_put_contents("$work/ca.der", $der);
        $steps[] = ['openssl', 'ec', '-inform', 'DER', '-in', "$work/ca.der", '-out', "$work/ca.key"];
        if (!$sameAuthority) {
            // The authority may sign only for names and addresses that exist inside a home: a leaked
            // house key cannot be turned into a certificate that devices would accept for a public site.
            $steps[] = [
                'openssl', 'req', '-x509', '-new', '-key', "$work/ca.key", '-sha256', '-days', '3650', '-subj', $subject,
                '-addext', 'basicConstraints=critical,CA:TRUE,pathlen:0', '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
                '-addext', 'nameConstraints=critical,permitted;DNS:.local,permitted;DNS:.lan,permitted;DNS:localhost,'
                    . 'permitted;IP:127.0.0.0/255.0.0.0,permitted;IP:10.0.0.0/255.0.0.0,permitted;IP:172.16.0.0/255.240.0.0,'
                    . 'permitted;IP:192.168.0.0/255.255.0.0,permitted;IP:169.254.0.0/255.255.0.0',
                '-out', "$work/ca.crt",
            ];
        } else {
            copy("$dir/ca.crt", "$work/ca.crt");
        }
        file_put_contents("$work/leaf.ext", "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\nsubjectAltName=$names\n");
        $host = substr(explode(',', $names)[0], 4);
        $steps[] = ['openssl', 'genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:prime256v1', '-out', "$work/offline.key"];
        $steps[] = ['openssl', 'req', '-new', '-key', "$work/offline.key", '-subj', "/O=Offline household/CN=$host", '-out', "$work/leaf.csr"];
        // 397 days: the longest life the stricter devices accept for a certificate, even under a private authority.
        $steps[] = [
            'openssl', 'x509', '-req', '-in', "$work/leaf.csr", '-CA', "$work/ca.crt", '-CAkey', "$work/ca.key", '-set_serial', '0x' . bin2hex(random_bytes(16)),
            '-days', '397', '-sha256', '-extfile', "$work/leaf.ext", '-out', "$work/offline.crt",
        ];
        foreach ($steps as $step) {
            [$code, , $error] = run($step, 60);
            if ($code !== 0) {
                warn('The certificate could not be made (' . $step[1] . '): ' . printable(trim($error), 200));
                return false;
            }
        }
        $ok = rename("$work/offline.key", "$dir/offline.key") && rename("$work/offline.crt", "$dir/offline.crt") && rename("$work/ca.crt", "$dir/ca.crt");
        file_put_contents("$dir/offline.names", $names . "\n");
        @chmod("$dir/offline.key", 0600);
        foreach (["$dir/offline.crt", "$dir/ca.crt", "$dir/offline.names"] as $public) {
            @chmod($public, 0644);
        }
        return $ok;
    } finally {
        // The authority's key exists on disc only for the moments it takes to sign.
        foreach (@scandir($work) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink("$work/$file");
            }
        }
        @rmdir($work);
        umask($previous);
    }
}

function tls_status(): array
{
    $dir = path('tls');
    $status = ['certificate' => false, 'expires' => null, 'days' => null, 'authority' => null, 'names' => []];
    if (!is_file("$dir/offline.crt") || !have('openssl')) {
        return $status;
    }
    [, $end] = run(['openssl', 'x509', '-in', "$dir/offline.crt", '-noout', '-enddate'], 30);
    $expires = strtotime((string) preg_replace('/^notAfter=/', '', trim($end)));
    $status['certificate'] = true;
    if ($expires !== false) {
        $status['expires'] = gmdate('Y-m-d', $expires);
        $status['days'] = (int) floor(($expires - time()) / 86400);
    }
    if (is_file("$dir/ca.crt")) {
        [, $print] = run(['openssl', 'x509', '-in', "$dir/ca.crt", '-noout', '-fingerprint', '-sha256'], 30);
        $status['authority'] = preg_match('/=([0-9A-F:]{95})$/m', trim($print), $m) === 1 ? $m[1] : null;
    }
    $status['names'] = array_values(array_filter(explode(',', trim((string) @file_get_contents("$dir/offline.names")))));
    return $status;
}

function command_tls(array $args): int
{
    $action = $args[0] ?? 'status';
    if ($action === 'renew') {
        if (prefix() === '' && !is_root()) {
            fail('Run this with sudo: sudo offline tls renew');
        }
        if (!ensure_tls_certificate(\in_array('--force', $args, true))) {
            fail('The certificate could not be made. Is the house key set? See: sudo offline key');
        }
        if (prefix() === '' && have('systemctl')) {
            run(['systemctl', 'reload', 'apache2.service'], 60);
        }
        out('This system\'s certificate is in order.');
        $action = 'status';
    }
    if ($action !== 'status') {
        fail('Usage: offline tls [status]   |   sudo offline tls renew [--force]');
    }
    $status = tls_status();
    heading('Certificate');
    if (!$status['certificate']) {
        out('  None yet. sudo offline tls renew');
        return 1;
    }
    out('  Valid until   ' . ($status['expires'] ?? 'unknown') . ($status['days'] !== null ? " ({$status['days']} days)" : ''));
    out('  Answers to    ' . implode(', ', array_map(static fn (string $n): string => substr($n, (int) strpos($n, ':') + 1), $status['names'])));
    heading('Household certificate authority');
    out('  Fingerprint   ' . ($status['authority'] ?? 'no authority certificate here'));
    out('  Every system that holds the house key signs with this same authority, and it may sign only for');
    out('  names and addresses inside a home. Give a telephone or tablet the authority once, from');
    out('  http://' . (gethostname() ?: 'localhost') . '.local/household-ca.crt, check the fingerprint above against what');
    out('  the device shows, and it will accept every system in the house without a warning.');
    return 0;
}

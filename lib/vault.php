<?php
/**
 * Offline — the family vault.
 *
 * A folder for the household's own papers: passports, insurance, medical
 * notes, the deeds. On disc it exists only as ciphertext, made by gocryptfs:
 * file contents and file names are encrypted under a passphrase that is typed
 * when the vault is opened and stored nowhere. Opened, it appears as an
 * ordinary folder in the home directory of whoever opened it, for them alone.
 *
 * The ciphertext is exchanged between systems like any other content, so every
 * system, a server in a datacentre included, holds a copy that it cannot read.
 * The house key lets a system fetch the ciphertext; only the passphrase turns
 * it back into documents.
 */

declare(strict_types=1);

namespace Offline;

/** A name gocryptfs would have written: its own files, or an encrypted name in URL-safe base64. */
function vault_path_ok(string $path): bool
{
    if ($path === '' || \strlen($path) > 2000 || $path[0] === '/') {
        return false;
    }
    $parts = explode('/', $path);
    foreach ($parts as $index => $part) {
        $last = $index === count($parts) - 1;
        if (preg_match('/^[A-Za-z0-9_-]{1,255}$/', $part) === 1) {
            continue;
        }
        if ($last && ($part === 'gocryptfs.diriv' || ($part === 'gocryptfs.conf' && $index === 0))) {
            continue;
        }
        if (preg_match('/^gocryptfs\.longname\.[A-Za-z0-9_-]{20,64}$/', $part) === 1
            || ($last && preg_match('/^gocryptfs\.longname\.[A-Za-z0-9_-]{20,64}\.name$/', $part) === 1)) {
            continue;
        }
        return false;
    }
    return true;
}

function vault_ready(): bool
{
    return is_file(path('vault') . '/gocryptfs.conf');
}

/** Where the vault is mounted for the person running the command, if it is. */
function vault_mounts(): array
{
    $mounts = [];
    $cipher = (string) @realpath(path('vault'));
    foreach (@file('/proc/self/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $fields = explode(' ', $line);
        if (($fields[2] ?? '') === 'fuse.gocryptfs' && $cipher !== '' && stripcslashes($fields[0]) === $cipher) {
            $mounts[] = stripcslashes($fields[1]);
        }
    }
    return $mounts;
}

function vault_status(): array
{
    $ready = vault_ready();
    $stats = $ready && is_readable(path('vault')) ? tree_stats(path('vault')) : ['files' => 0, 'bytes' => 0];
    return ['ready' => $ready, 'files' => max(0, $stats['files'] - ($ready ? 1 : 0)), 'bytes' => $stats['bytes'], 'sync' => config_bool('VAULT_SYNC')];
}

/** Run a program that asks its own questions on the terminal. */
function run_interactive(array $command): int
{
    if (!have($command[0])) {
        warn($command[0] . ' is not installed');
        return 127;
    }
    $proc = @proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, child_env() + ['HOME' => (string) (getenv('HOME') ?: path('state'))]);
    return \is_resource($proc) ? proc_close($proc) : 126;
}

/** --passfile FILE, for scripted use: the first line of a private file is the passphrase. */
function vault_passfile(array $args): array
{
    $index = array_search('--passfile', $args, true);
    if ($index === false) {
        return [];
    }
    $file = (string) ($args[$index + 1] ?? '');
    if (!is_file($file) || ((int) fileperms($file) & 0077) !== 0) {
        fail('--passfile needs a file that only you can read (chmod 600).');
    }
    return ['-passfile', $file];
}

/** Everyone in the "offline" group, and the account that exchanges content, must be able to work in the ciphertext folder. */
function vault_share_permissions(): void
{
    $dir = path('vault');
    @chmod($dir, 02770);
    foreach (['gocryptfs.conf', 'gocryptfs.diriv'] as $file) {
        if (is_file("$dir/$file")) {
            @chmod("$dir/$file", 0440);
        }
    }
    if (prefix() === '' && have('setfacl')) {
        // New files and folders made through the opened vault stay workable by the group, whatever a program's umask.
        run(['setfacl', '-m', 'd:g:' . SERVICE_USER . ':rwx,d:m::rwx,g:' . SERVICE_USER . ':rwx', $dir], 30);
    }
}

function command_vault(array $args): int
{
    $action = $args[0] ?? 'status';
    $cipher = path('vault');
    $home = (string) (getenv('HOME') ?: '');
    $mount = preg_match('#^/[A-Za-z0-9/_. -]+$#', (string) ($args[1] ?? '')) === 1 && ($args[1][0] ?? '') === '/' ? rtrim($args[1], '/') : "$home/Vault";

    if ($action === 'status') {
        $status = vault_status();
        if (!$status['ready']) {
            out('The family vault has not been created on this system.');
            out('If another system already has it, it arrives at the next sync. Otherwise create it once, on one system:');
            out('  offline vault init');
            return 0;
        }
        out('The family vault holds ' . plural($status['files'], 'encrypted file') . ', ' . human_bytes($status['bytes']) . '.');
        $mounts = vault_mounts();
        out($mounts === [] ? 'It is closed. Open it with: offline vault open' : 'It is open at ' . implode(', ', array_map(static fn (string $m): string => printable($m), $mounts)) . '. Close it with: offline vault close');
        out('Its ciphertext is ' . ($status['sync'] ? 'exchanged with the other systems, which cannot read it.' : 'kept on this system only (VAULT_SYNC=no).'));
        return 0;
    }

    if (!in_array($action, ['init', 'open', 'close', 'passwd'], true)) {
        fail('Usage: offline vault [status] | init | open [FOLDER] | close [FOLDER] | passwd');
    }
    if (prefix() === '' && !have('gocryptfs')) {
        fail('gocryptfs is not installed. Run the installer, or: sudo apt install gocryptfs');
    }

    if ($action === 'init') {
        // Created by the sharing account when run with sudo, or by a member of its group.
        if (is_root()) {
            become_service_user('vault init');
        }
        if (vault_ready()) {
            fail('The vault already exists. Open it with: offline vault open');
        }
        if (!is_dir($cipher) && !@mkdir($cipher, 02770, true)) {
            fail("Could not create $cipher. Run the installer first, or use: sudo offline vault init");
        }
        if (\count(@scandir($cipher) ?: []) > 2) {
            fail("$cipher is not empty, so a vault cannot be created in it.");
        }
        out('Create the vault once, on one system; the others receive it by sync and open it with the same passphrase.');
        out('Choose a passphrase the adults will remember. It is stored nowhere: if it is lost, so is what the vault holds.');
        umask(0007);
        $code = run_interactive(array_merge(['gocryptfs', '-init', '-q'], vault_passfile($args), ['--', $cipher]));
        if ($code !== 0 || !vault_ready()) {
            fail('The vault was not created.');
        }
        vault_share_permissions();
        out('The vault is ready. Open it with: offline vault open');
        out('Write the passphrase down and keep it with your other important papers.');
        return 0;
    }

    if ($action === 'passwd') {
        if (!vault_ready()) {
            fail('There is no vault on this system yet.');
        }
        if (is_root()) {
            become_service_user('vault passwd');
        }
        umask(0007);
        $code = run_interactive(['gocryptfs', '-passwd', '-q', '--', $cipher]);
        vault_share_permissions();
        if ($code === 0) {
            out('Passphrase changed. The other systems receive the change at their next sync; until then they open with the old one.');
        }
        return $code === 0 ? 0 : 1;
    }

    // Opening and closing are done as yourself: the opened vault belongs to whoever opened it.
    if (prefix() === '' && is_root()) {
        fail("Run \"offline vault $action\" as yourself, without sudo: an opened vault is visible only to the account that opened it.");
    }
    if ($home === '' || !is_dir($home)) {
        fail('Could not find your home folder.');
    }

    if ($action === 'close') {
        if (!in_array($mount, vault_mounts(), true) && prefix() === '') {
            out('The vault is not open at ' . printable($mount) . '.');
            return 0;
        }
        [$code, , $error] = run([have('fusermount3') ? 'fusermount3' : 'fusermount', '-u', '--', $mount], 30, ['HOME' => $home]);
        if ($code !== 0) {
            fail('Could not close the vault: ' . printable(trim($error), 200) . ' Close any program using files in it and try again.');
        }
        @rmdir($mount);
        out('The vault is closed.');
        return 0;
    }

    if (!vault_ready()) {
        fail('There is no vault on this system yet. See: offline vault');
    }
    if (!is_readable("$cipher/gocryptfs.conf")) {
        fail('Only members of the "offline" group may open the vault. Ask for: sudo usermod -aG offline ' . (getenv('USER') ?: 'NAME') . ' (then log out and in).');
    }
    if (in_array($mount, vault_mounts(), true)) {
        out('The vault is already open at ' . printable($mount) . '.');
        return 0;
    }
    if (!is_dir($mount) && !@mkdir($mount, 0700)) {
        fail('Could not create ' . printable($mount));
    }
    if (\count(@scandir($mount) ?: []) > 2) {
        fail(printable($mount) . ' is not empty; the vault opens onto an empty folder.');
    }
    @chmod($mount, 0700);
    umask(0007);
    $idle = config_int('VAULT_IDLE_MINUTES', 0, 1440);
    $code = run_interactive(array_merge(
        ['gocryptfs', '-q', '-nosuid', '-nodev', '-noexec'],
        $idle > 0 ? ['-idle', $idle . 'm'] : [],
        vault_passfile($args),
        ['--', $cipher, $mount]
    ));
    if ($code !== 0) {
        @rmdir($mount);
        fail($code === 12 ? 'That passphrase did not open the vault.' : "The vault could not be opened (gocryptfs exit $code).");
    }
    out('The vault is open at ' . printable($mount) . ', for you alone.' . ($idle > 0 ? " It closes itself after $idle minutes without use." : ''));
    out('Close it when you have finished: offline vault close');
    return 0;
}

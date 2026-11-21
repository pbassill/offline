<?php
/**
 * Offline — rebuilding a system when there is no internet, and moving content by disc.
 *
 * Package cache: a home server keeps a copy of every Ubuntu package the
 * household's systems fetch, so a replacement machine can be installed from
 * it. The cache cannot alter what it serves: apt checks every index against
 * Ubuntu's signature and every package against the index, exactly as it does
 * when fetching direct.
 *
 * Installer image: the Ubuntu image for this release, checked against
 * Ubuntu's signed checksum list, kept on disc for writing to a USB stick.
 *
 * Export and import: shared content copied to and from a disc, through the
 * same filters as content from another system, so a new machine can be
 * filled in minutes and content can travel where no network reaches.
 */

declare(strict_types=1);

namespace Offline;

const APT_CACHE_PORT = 3142;
const UBUNTU_RELEASES = 'https://releases.ubuntu.com/';
const UBUNTU_KEYRING = '/usr/share/keyrings/ubuntu-archive-keyring.gpg';
const EXPORT_MODULES = ['knowledge', 'media', 'maps', 'vault'];

// ---------------------------------------------------------------------------
// Package cache
// ---------------------------------------------------------------------------

function apt_cache_conf(): string
{
    return prefix() . '/etc/apt-cacher-ng/zz_offline.conf';
}

function apt_cache_serving(): bool
{
    return is_file(apt_cache_conf());
}

function apt_cache_offline_mode(): bool
{
    return preg_match('/^Offlinemode:\s*1\s*$/m', (string) @file_get_contents(apt_cache_conf())) === 1;
}

function apt_cache_write(bool $offlineMode): void
{
    @mkdir(dirname(apt_cache_conf()), 0755, true);
    write_atomic(apt_cache_conf(), implode("\n", [
        '# Written by "offline rebuild"; changes here are overwritten.',
        'Port: ' . APT_CACHE_PORT,
        '# Serve the package archives it knows and nothing else: it is not a general web proxy.',
        'ForceManaged: 1',
        "# Ubuntu's security archive and its archive for ARM systems are not in the stock list.",
        'Remap-ubusec: security.ubuntu.com ; http://security.ubuntu.com',
        'Remap-ubuports: ports.ubuntu.com ; http://ports.ubuntu.com',
        '# Keep a package for a month after the archive stops listing it.',
        'ExThreshold: 30',
        '# 1 when the internet is gone: answer from the cache without trying the archive.',
        'Offlinemode: ' . ($offlineMode ? '1' : '0'),
        '',
    ]), 0644);
    // Its maintenance pages need a sign-in; without one they are open to the whole network.
    $security = prefix() . '/etc/apt-cacher-ng/zz_offline_security.conf';
    if (!is_file($security)) {
        $previous = umask(0027);
        file_put_contents($security, "# Written by \"offline rebuild\". Sign-in for the cache's maintenance pages.\nAdminAuth: offline:" . bin2hex(random_bytes(16)) . "\n");
        umask($previous);
        @chmod($security, 0640);
        @chgrp($security, 'apt-cacher-ng');
    }
}

/** The file apt reads on a system that should try the household's cache first. */
function apt_client_apply(): array
{
    $hosts = time_source_hosts();
    $list = prefix() . '/etc/offline/apt-cache.hosts';
    $conf = prefix() . '/etc/apt/apt.conf.d/02offline-cache';
    if ($hosts === []) {
        @unlink($list);
        @unlink($conf);
        return [];
    }
    write_atomic($list, "# Written by \"offline\": household package caches, tried in order.\n" . implode("\n", $hosts) . "\n", 0644);
    @mkdir(dirname($conf), 0755, true);
    write_atomic($conf, "// Written by \"offline\". Use the household's package cache when it answers; go direct when it does not.\n"
        . "// Only plain-http archives (Ubuntu's own) pass through it. Signatures are checked exactly as before.\n"
        . "Acquire::http::Proxy-Auto-Detect \"/usr/local/lib/offline/apt-proxy-detect\";\n", 0644);
    return $hosts;
}

/** Packages the installer asks for, from the shipped list. */
function installer_packages(): array
{
    $packages = [];
    foreach (@file(prefix() . '/usr/local/lib/offline/packages.list', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $name = trim($line);
        if ($name !== '' && $name[0] !== '#' && preg_match('/^[a-z0-9][a-z0-9.+-]{1,80}$/', $name) === 1) {
            $packages[$name] = true;
        }
    }
    return array_keys($packages);
}

/** Fetch every package the installer needs, with all they depend on, through the cache. */
function apt_cache_warm(): int
{
    $available = [];
    foreach (installer_packages() as $package) {
        if (run(['apt-cache', 'show', '--no-all-versions', $package], 60)[0] === 0) {
            $available[] = $package;
        }
    }
    if ($available === []) {
        fail('No package list was found. Run the installer first.');
    }
    out('Working out everything ' . plural(count($available), 'package') . ' depend on ...');
    [$code, $output] = run(array_merge(['apt-cache', 'depends', '--recurse', '--no-recommends', '--no-suggests', '--no-conflicts',
        '--no-breaks', '--no-replaces', '--no-enhances'], $available), 600);
    if ($code !== 0) {
        fail('apt-cache could not work out the dependencies.');
    }
    $closure = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        // Package names start a line; dependency lines are indented and virtual packages are in angle brackets.
        if (preg_match('/^([a-z0-9][a-z0-9.+-]+)(:[a-z0-9]+)?$/', $line, $m) === 1) {
            $closure[$m[1]] = true;
        }
    }
    $names = array_keys($closure);
    out(plural(count($names), 'package') . ' to hold. Fetching through the cache; those it already has cost nothing ...');
    $work = path('state') . '/warm-' . getmypid();
    @mkdir($work, 0700);
    @chown($work, '_apt');
    $failed = 0;
    // The package indexes too, into a scratch folder, so the cache can answer "apt update" when it stands alone.
    @mkdir("$work/lists/partial", 0755, true);
    run(['apt-get', 'update', '-q', '-o', 'Acquire::http::Proxy=http://127.0.0.1:' . APT_CACHE_PORT, '-o', 'Acquire::http::Proxy-Auto-Detect=',
        '-o', "Dir::State::lists=$work/lists", '-o', 'APT::Sandbox::User=root'], 900);
    run(['rm', '-rf', '--', "$work/lists"], 60);
    foreach (array_chunk($names, 150) as $batch) {
        $proc = @proc_open(array_merge(['apt-get', 'download', '-q', '-o', 'Acquire::http::Proxy=http://127.0.0.1:' . APT_CACHE_PORT,
            '-o', 'Acquire::http::Proxy-Auto-Detect=', '-o', 'Acquire::Retries=3'], $batch),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes, $work, child_env());
        $failed += \is_resource($proc) && proc_close($proc) === 0 ? 0 : 1;
        // Only the cache's copy is wanted.
        foreach (glob("$work/*.deb") ?: [] as $deb) {
            @unlink($deb);
        }
        out('  ' . min(count($names), ($done = ($done ?? 0) + count($batch))) . ' of ' . count($names));
    }
    @rmdir($work);
    if ($failed > 0) {
        warn('Some packages could not be fetched; the rest are held. Run this again when the internet is steadier.');
    }
    return $failed === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------------
// Installer image
// ---------------------------------------------------------------------------

function rebuild_dir(): string
{
    return path('share') . '/rebuild';
}

/** This system's Ubuntu release, as "24.04". */
function ubuntu_release(): string
{
    foreach (@file('/etc/os-release') ?: [] as $line) {
        if (preg_match('/^VERSION_ID="?(\d\d\.\d\d)"?/', $line, $m) === 1) {
            return $m[1];
        }
    }
    return '';
}

/** Check Ubuntu's signature on a checksum list. True only if gpgv accepts it with Ubuntu's own keys. */
function ubuntu_signature_ok(string $sums, string $signature): bool
{
    if (!have('gpgv') || !is_file(UBUNTU_KEYRING)) {
        warn('gpgv or the Ubuntu keyring is missing, so the checksum list cannot be authenticated.');
        return false;
    }
    return run(['gpgv', '--keyring', UBUNTU_KEYRING, $signature, $sums], 60)[0] === 0;
}

/** The newest image of one kind in a checksum list: [file name, sha256], or null. */
function ubuntu_pick_image(string $sums, string $release, string $kind, string $arch): ?array
{
    $best = null;
    foreach (preg_split('/\R/', $sums) ?: [] as $line) {
        if (preg_match('/^([0-9a-f]{64}) [ *](ubuntu-' . preg_quote($release, '/') . '(?:\.\d+)?-' . preg_quote($kind, '/') . '-' . preg_quote($arch, '/') . '\.iso)$/', $line, $m) === 1
            && ($best === null || strnatcmp($m[2], $best[0]) > 0)) {
            $best = [$m[2], $m[1]];
        }
    }
    return $best;
}

function rebuild_fetch_image(string $kind, string $release): int
{
    $arch = machine_arch();
    if ($arch !== 'amd64') {
        fail("Ubuntu publishes these images for amd64; this system is $arch. Fetch an image for it by hand from https://cdimage.ubuntu.com/ and check it against its SHA256SUMS.");
    }
    if (preg_match('/^\d\d\.\d\d$/', $release) !== 1) {
        fail('Give the release as, for example, --release 24.04');
    }
    $dir = rebuild_dir();
    @mkdir($dir, 02775, true);
    $base = UBUNTU_RELEASES . $release . '/';
    out("Fetching Ubuntu $release's signed checksum list ...");
    $sums = https_get($base . 'SHA256SUMS', 60);
    $signature = https_get($base . 'SHA256SUMS.gpg', 60);
    if ($sums === null || $signature === null) {
        fail('The checksum list could not be fetched. This command needs an internet connection.');
    }
    $sumsFile = "$dir/SHA256SUMS-$release";
    file_put_contents("$sumsFile.new", $sums);
    file_put_contents("$sumsFile.gpg.new", $signature);
    if (!ubuntu_signature_ok("$sumsFile.new", "$sumsFile.gpg.new")) {
        @unlink("$sumsFile.new");
        @unlink("$sumsFile.gpg.new");
        fail('The checksum list is not signed by Ubuntu. Nothing was downloaded.');
    }
    rename("$sumsFile.new", $sumsFile);
    rename("$sumsFile.gpg.new", "$sumsFile.gpg");
    $image = ubuntu_pick_image($sums, $release, $kind, $arch);
    if ($image === null) {
        fail("Ubuntu's list for $release holds no $kind image for $arch.");
    }
    [$name, $sha256] = $image;
    if (is_file("$dir/$name") && hash_file('sha256', "$dir/$name") === $sha256) {
        out("$name is already here and matches Ubuntu's checksum.");
        return 0;
    }
    out("The signature is Ubuntu's. Fetching $name; several gigabytes, and it resumes if interrupted ...");
    $code = run_live(['aria2c', '--no-conf', '--dir=' . $dir, '--out=' . $name . '.part', '--check-certificate=true', '--continue=true',
        '--auto-file-renaming=false', '--file-allocation=none', '--max-connection-per-server=4', '--max-tries=5', '--retry-wait=10',
        '--console-log-level=warn', '--summary-interval=0', '--checksum=sha-256=' . $sha256, '--', $base . $name]);
    if ($code !== 0 || !is_file("$dir/$name.part") || hash_file('sha256', "$dir/$name.part") !== $sha256) {
        warn("$name did not arrive intact (aria2c exit $code). Repeat the command to resume.");
        return 1;
    }
    rename("$dir/$name.part", "$dir/$name");
    @chmod("$dir/$name", 0644);
    out(paint('✔', 'green') . " $name is held in $dir and matches Ubuntu's signed checksum.");
    out('Write it to a USB stick with Startup Disk Creator, or: sudo dd if=' . "$dir/$name" . ' of=/dev/sdX bs=4M conv=fsync status=progress');
    return 0;
}

/** Installer images held, each re-checked against the signed list beside it. */
function rebuild_images(bool $verify): array
{
    $dir = rebuild_dir();
    $images = [];
    foreach (glob("$dir/ubuntu-*.iso") ?: [] as $file) {
        $name = basename($file);
        $state = 'not checked';
        if ($verify && preg_match('/^ubuntu-(\d\d\.\d\d)/', $name, $m) === 1) {
            $sums = "$dir/SHA256SUMS-{$m[1]}";
            $state = 'no signed checksum list beside it';
            if (is_file($sums) && is_file("$sums.gpg") && ubuntu_signature_ok($sums, "$sums.gpg")) {
                $state = preg_match('/^' . hash_file('sha256', $file) . ' [ *]' . preg_quote($name, '/') . '$/m', (string) file_get_contents($sums)) === 1
                    ? 'matches Ubuntu\'s signed checksum' : 'DOES NOT MATCH; do not use it';
            }
        }
        $images[] = ['name' => $name, 'size' => (int) filesize($file), 'state' => $state];
    }
    return $images;
}

function rebuild_status(): array
{
    return [
        'cache' => apt_cache_serving(),
        'cache_offline_mode' => apt_cache_offline_mode(),
        'images' => array_map(static fn (array $i): array => ['name' => $i['name'], 'size' => $i['size']], rebuild_images(false)),
    ];
}

function command_rebuild(array $args): int
{
    $action = $args[0] ?? 'status';
    if ($action === 'status') {
        heading('Package cache');
        if (apt_cache_serving()) {
            $cache = prefix() . '/var/cache/apt-cacher-ng';
            $held = is_readable($cache) ? tree_stats($cache) : null;
            out('  This system serves a package cache on port ' . APT_CACHE_PORT . ($held !== null ? ', holding ' . human_bytes($held['bytes']) : '') . '.');
            out('  Mode: ' . (apt_cache_offline_mode() ? 'offline (answers from what it holds)' : 'online (fetches what it lacks)'));
        } else {
            out('  This system does not serve a package cache. sudo offline rebuild cache on');
        }
        $hosts = array_filter(array_map('trim', @file(prefix() . '/etc/offline/apt-cache.hosts') ?: []), static fn (string $h): bool => $h !== '' && $h[0] !== '#');
        out('  Caches this system tries before going direct: ' . ($hosts === [] ? 'none' : implode(', ', $hosts)));
        heading('Installer images');
        $images = rebuild_images(in_array('--verify', $args, true));
        if ($images === []) {
            out('  None held. sudo offline rebuild image');
        } else {
            table(['Image', 'Size', 'Check'], array_map(static fn (array $i): array => [$i['name'], human_bytes($i['size']), $i['state']], $images));
            out('  Check them against Ubuntu\'s signature with: offline rebuild status --verify');
        }
        out();
        out('sudo offline rebuild cache on|off       serve a package cache to the home network');
        out('sudo offline rebuild warm               fetch everything the installer needs into the cache');
        out('sudo offline rebuild offline on|off     answer from the cache alone, for when the internet is gone');
        out('sudo offline rebuild image [desktop|live-server] [--release NN.NN]');
        out('sudo offline rebuild clients            point this system at the household caches again');
        return 0;
    }

    if ($action === 'image') {
        become_service_user('rebuild image');
        $kind = in_array($args[1] ?? '', ['desktop', 'live-server'], true) ? $args[1] : 'desktop';
        $index = array_search('--release', $args, true);
        return rebuild_fetch_image($kind, $index !== false ? (string) ($args[$index + 1] ?? '') : ubuntu_release());
    }

    require_root("rebuild $action");
    if ($action === 'clients') {
        $hosts = apt_client_apply();
        out($hosts === [] ? 'No household cache is configured; packages are fetched direct.' : 'apt now tries ' . implode(', ', $hosts) . ' first and goes direct when none answers.');
        return 0;
    }
    if ($action === 'warm') {
        if (!apt_cache_serving()) {
            fail('This system does not serve a package cache. Turn it on first: sudo offline rebuild cache on');
        }
        return apt_cache_warm();
    }
    $switch = $args[1] ?? '';
    if (!in_array($action, ['cache', 'offline'], true) || !in_array($switch, ['on', 'off'], true)) {
        fail('Usage: offline rebuild [status [--verify]]   |   sudo offline rebuild cache on|off | warm | offline on|off | image [KIND] | clients');
    }
    if ($action === 'cache') {
        if ($switch === 'on') {
            if (prefix() === '' && !is_dir('/etc/apt-cacher-ng')) {
                fail('apt-cacher-ng is not installed. Run the installer as a server, or: sudo apt install apt-cacher-ng');
            }
            if ((string) config('SYNC_EXPOSE') === 'internet') {
                fail('A server exposed to the internet does not serve a package cache: only the keyed sharing port faces the world.');
            }
            apt_cache_write(false);
        } else {
            @unlink(apt_cache_conf());
        }
        if (prefix() === '') {
            if (have('ufw')) {
                foreach (LAN_NETS as $net) {
                    $rule = ['from', $net, 'to', 'any', 'port', (string) APT_CACHE_PORT, 'proto', 'tcp'];
                    run($switch === 'on' ? array_merge(['ufw', 'allow'], $rule, ['comment', 'Offline package cache']) : array_merge(['ufw', 'delete', 'allow'], $rule), 30);
                }
            }
            sys(['systemctl', $switch === 'on' ? 'enable' : 'disable', '--now', 'apt-cacher-ng.service']);
            if ($switch === 'on') {
                sys(['systemctl', 'restart', 'apt-cacher-ng.service']);
            }
        }
        out($switch === 'on' ? 'The package cache is on. Fill it with: sudo offline rebuild warm' : 'The package cache is off. What it holds is kept in /var/cache/apt-cacher-ng.');
        return 0;
    }
    if (!apt_cache_serving()) {
        fail('This system does not serve a package cache.');
    }
    apt_cache_write($switch === 'on');
    if (prefix() === '') {
        sys(['systemctl', 'restart', 'apt-cacher-ng.service']);
    }
    out($switch === 'on' ? 'The cache now answers from what it holds and does not try the internet.' : 'The cache fetches what it lacks again.');
    return 0;
}

// ---------------------------------------------------------------------------
// Export and import
// ---------------------------------------------------------------------------

/** Regular files beneath a folder, never following a link: [['path', 'size', 'mtime'], ...]. */
function walk_files(string $root, int $limit = 500000): array
{
    $files = [];
    $stack = [''];
    while ($stack !== [] && count($files) < $limit) {
        $relative = array_pop($stack);
        $dir = $relative === '' ? $root : "$root/$relative";
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$dir/$entry";
            $path = $relative === '' ? $entry : "$relative/$entry";
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $stack[] = $path;
            } elseif (is_file($full)) {
                $files[] = ['path' => $path, 'size' => (int) filesize($full), 'mtime' => (int) filemtime($full)];
            }
        }
    }
    return $files;
}

function export_modules(array $args): array
{
    $index = array_search('--what', $args, true);
    $asked = $index !== false ? preg_split('/[\s,]+/', (string) ($args[$index + 1] ?? '')) ?: [] : EXPORT_MODULES;
    $modules = array_values(array_intersect(EXPORT_MODULES, $asked));
    if ($modules === []) {
        fail('--what takes any of: ' . implode(', ', EXPORT_MODULES));
    }
    return $modules;
}

function transfer_target(array $args): string
{
    $dir = (string) ($args[0] ?? '');
    if ($dir === '' || $dir[0] !== '/' || preg_match('/[\x00-\x1f]/', $dir) === 1) {
        fail('Give the folder on the disc as a full path, for example /media/' . (getenv('SUDO_USER') ?: 'you') . '/DISC/offline');
    }
    return rtrim($dir, '/');
}

function command_export(array $args): int
{
    require_root('export');
    $target = transfer_target($args);
    $modules = export_modules($args);
    if (!is_dir(dirname($target)) || (!is_dir($target) && !@mkdir($target, 0755))) {
        fail("Could not create $target. Is the disc mounted?");
    }
    $free = (int) (@disk_free_space($target) ?: 0);
    $total = 0;
    $plans = [];
    foreach ($modules as $module) {
        if (!is_dir(path($module))) {
            continue;
        }
        // Only what would be accepted on import is written: no programs, nothing hidden, no links.
        $files = array_values(array_filter(walk_files(path($module)), static fn (array $f): bool => $module === 'vault'
            ? vault_path_ok($f['path'])
            : safe_relative_path($f['path']) && in_array(extension($f['path']), SYNC_EXTENSIONS[$module], true)));
        $files = array_values(array_filter($files, static fn (array $f): bool => !is_file("$target/$module/{$f['path']}") || (int) filesize("$target/$module/{$f['path']}") !== $f['size']));
        $plans[$module] = $files;
        $total += array_sum(array_column($files, 'size'));
    }
    out('To copy: ' . plural(array_sum(array_map('count', $plans)), 'file') . ', ' . human_bytes($total) . '. The disc has ' . human_bytes($free) . ' free.');
    if ($total > $free) {
        fail('There is not enough room on the disc. Choose less with --what knowledge,maps, or use a larger disc.');
    }
    $failed = 0;
    foreach ($plans as $module => $files) {
        if ($files === []) {
            continue;
        }
        out(paint(ucfirst($module), 'bold'));
        $list = path('state') . '/export-' . getmypid() . '.list';
        file_put_contents($list, implode("\0", array_column($files, 'path')) . "\0");
        @mkdir("$target/$module", 0755, true);
        // No owners, groups or permissions: discs formatted for other systems cannot hold them.
        $code = run_live(['rsync', '--recursive', '--times', '--modify-window=2', '--from0', '--files-from=' . $list, '--no-links', '--no-devices', '--no-specials',
            '--no-perms', '--no-owner', '--no-group', '--partial', '--info=progress2', path($module) . '/', "$target/$module/"]);
        @unlink($list);
        $failed += in_array($code, [0, 23, 24], true) ? 0 : 1;
    }
    write_atomic("$target/OFFLINE-EXPORT.txt", "Offline content export\nMade by: " . (gethostname() ?: 'unknown') . ', Offline ' . VERSION . "\nMade at: " . utc_display(utc_now())
        . "\nHolds: " . implode(', ', array_keys($plans)) . "\n\nBring it into another Offline system with:\n  sudo offline import " . $target . "\n", 0644);
    run(['sync'], 600);
    out($failed === 0 ? paint('✔', 'green') . " Export complete: $target" : 'Some files could not be copied; see the messages above.');
    return $failed === 0 ? 0 : 1;
}

function command_import(array $args): int
{
    require_root('import');
    $source = transfer_target($args);
    $dryRun = in_array('--dry-run', $args, true);
    if (!is_dir($source)) {
        fail("$source is not a folder. Is the disc mounted?");
    }
    $modules = array_values(array_filter(export_modules($args), static fn (string $m): bool => is_dir("$source/$m") && !is_link("$source/$m")));
    if ($modules === []) {
        fail("$source holds none of: " . implode(', ', EXPORT_MODULES) . '. Point at the folder an export made.');
    }
    $account = prefix() === '' ? posix_getpwnam(SERVICE_USER) : false;
    $budget = max(0, free_bytes() - config_int('SYNC_MIN_FREE_GB') * 1024 ** 3);
    $counts = ['filtered' => 0, 'superseded' => 0, 'no_space' => 0, 'rejected' => 0];
    $files = 0;
    $bytes = 0;
    $gotZim = false;
    foreach ($modules as $module) {
        // A disc is treated exactly like another system: the same filters decide what is taken.
        $wanted = plan_pull($module, walk_files("$source/$module"), $budget, $counts);
        if ($wanted === []) {
            continue;
        }
        $size = array_sum(array_column($wanted, 'size'));
        out(paint(ucfirst($module), 'bold') . ': ' . plural(count($wanted), 'file') . ', ' . human_bytes($size));
        if ($dryRun) {
            foreach (\array_slice($wanted, 0, 40) as $file) {
                out('  ' . printable($file['path']) . ' (' . human_bytes($file['size']) . ')');
            }
            continue;
        }
        @mkdir(path($module), $module === 'vault' ? 02770 : 02775, true);
        $list = path('state') . '/import-' . getmypid() . '.list';
        file_put_contents($list, implode("\0", array_column($wanted, 'path')) . "\0");
        $command = ['rsync', '--recursive', '--times', '--update', '--from0', '--files-from=' . $list, '--no-links', '--no-devices', '--no-specials',
            '--no-perms', '--no-owner', '--no-group', '--chmod=' . ($module === 'vault' ? 'D2770,F660' : 'D2775,F664'), '--info=progress2'];
        if ($account !== false) {
            $command[] = '--chown=' . $account['uid'] . ':' . $account['gid'];
        }
        $code = run_live(array_merge($command, ["$source/$module/", path($module) . '/']));
        @unlink($list);
        if (!in_array($code, [0, 23, 24], true)) {
            warn("Some $module files could not be copied (rsync exit $code).");
        }
        foreach ($wanted as $file) {
            $full = path($module) . '/' . $file['path'];
            if (!is_file($full)) {
                continue;
            }
            // A file that claims to be a library title but is not one never reaches the reader.
            if (extension($file['path']) === 'zim' && !valid_zim_file($full)) {
                @unlink($full);
                warn(printable($file['path']) . ' is not a valid ZIM file and was discarded.');
                continue;
            }
            $files++;
            $bytes += $file['size'];
            $gotZim = $gotZim || extension($file['path']) === 'zim';
        }
    }
    if ($dryRun) {
        out('Dry run: nothing was copied.');
        return 0;
    }
    $message = plural($files, 'file') . ' brought in (' . human_bytes($bytes) . ')';
    foreach (['filtered' => 'ignored as not an accepted type', 'superseded' => 'older than an edition already held', 'no_space' => 'left behind to protect free space'] as $key => $why) {
        $message .= $counts[$key] > 0 ? '; ' . $counts[$key] . ' ' . $why : '';
    }
    out($message . '.');
    if ($gotZim) {
        // Indexing and pruning are the sharing account's work.
        if (config_bool('AUTO_PRUNE')) {
            as_service(['prune']);
        }
        as_service(['library']);
    }
    as_service(['status', '--write']);
    return 0;
}

<?php
/**
 * Offline — applications.
 *
 * Each application is a container run by Podman from a unit file shipped with
 * this project. Three rules apply to all of them:
 *
 *   - the container's root user is mapped to an unprivileged range on the
 *     host and cannot gain privileges;
 *   - the container publishes its port on 127.0.0.1 only, so the host
 *     firewall is never bypassed;
 *   - other devices reach it through an Apache proxy that applies the same
 *     home-network rule as the portal, over TLS where the application needs it.
 *
 * Installing and removing applications needs root, so "offline apps" is one
 * of the few commands that keeps it. Names are only ever taken from the
 * shipped index, never from the command line.
 */

declare(strict_types=1);

namespace Offline;

const QUADLET_DIR = '/etc/containers/systemd';
const APPS_ETC = '/etc/offline/apps';
const TLS_DIR = '/etc/offline/tls';
const LAN_NETS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16'];

function app_unit_source(string $id): string
{
    return path('apps_catalogue') . "/$id.container";
}

function app_unit_target(string $id): string
{
    return prefix() . QUADLET_DIR . "/offline-app-$id.container";
}

function app_installed(string $id): bool
{
    return is_file(app_unit_target($id));
}

function app_boot_enabled(string $id): bool
{
    return preg_match('/^\[Install\]/m', (string) @file_get_contents(app_unit_target($id))) === 1;
}

function app_image(string $id): ?string
{
    return preg_match('/^Image=(\S+)$/m', (string) @file_get_contents(app_unit_source($id)), $m) === 1 ? $m[1] : null;
}

function machine_arch(): string
{
    return ['x86_64' => 'amd64', 'aarch64' => 'arm64'][php_uname('m')] ?? php_uname('m');
}

/** State of every application: installed, enabled at boot, running. */
function app_states(): array
{
    $apps = app_index();
    $states = [];
    $installed = array_values(array_filter(array_keys($apps), __NAMESPACE__ . '\\app_installed'));
    $active = [];
    if ($installed !== [] && prefix() === '' && have('systemctl')) {
        [, $output] = run(array_merge(['systemctl', 'is-active'], array_map(static fn (string $id): string => "offline-app-$id.service", $installed)), 8);
        $lines = preg_split('/\R/', trim($output)) ?: [];
        foreach ($installed as $i => $id) {
            $active[$id] = preg_match('/^[a-z-]{2,20}$/', $lines[$i] ?? '') === 1 ? $lines[$i] : 'unknown';
        }
    }
    foreach ($apps as $id => $app) {
        $states[$id] = [
            'id' => $id, 'title' => $app['title'], 'category' => $app['category'], 'set' => $app['set'],
            'scheme' => $app['scheme'], 'port' => $app['proxies'][0]['lan'] ?? null,
            'installed' => in_array($id, $installed, true),
            'boot' => in_array($id, $installed, true) && app_boot_enabled($id),
            'state' => $active[$id] ?? (in_array($id, $installed, true) ? 'unknown' : 'absent'),
        ];
    }
    return $states;
}

function require_root(string $command): void
{
    if (prefix() === '' && !is_root()) {
        fail("\"offline $command\" installs system services. Run it as: sudo offline $command");
    }
}

/** Run a system command, echoing a one-line failure. Returns true on success. */
function sys(array $command, int $timeout = 120): bool
{
    [$code, , $error] = run($command, $timeout);
    if ($code !== 0) {
        warn(implode(' ', array_slice($command, 0, 3)) . ' failed: ' . printable(trim($error), 200));
    }
    return $code === 0;
}

function random_password(int $length = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

function write_private(string $file, string $content): void
{
    $previous = umask(0077);
    $ok = file_put_contents($file, $content) !== false;
    umask($previous);
    if (!$ok) {
        fail("Could not write $file");
    }
    chmod($file, 0600);
}

/** Create data folders, generated sign-in details and seed files. */
function app_prepare(array $app): bool
{
    $id = $app['id'];
    $unit = (string) file_get_contents(app_unit_source($id));
    preg_match_all('#^Volume=(/srv/offline/apps/[A-Za-z0-9/_-]+):#m', $unit, $volumes);
    foreach ($volumes[1] as $volume) {
        $dir = prefix() . $volume;
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            warn("Could not create $dir");
            return false;
        }
    }
    @chmod(path('apps'), 0755);
    @chmod(path('apps') . "/$id", 0755);

    $template = path('apps_catalogue') . "/$id.env.template";
    $etc = prefix() . APPS_ETC;
    if (is_file($template) && !is_file("$etc/$id.env")) {
        @mkdir($etc, 0700, true);
        $password = random_password();
        $env = strtr((string) file_get_contents($template), [
            '@PASSWORD@' => $password,
            '@SECRET@' => bin2hex(random_bytes(32)),
            '@BCRYPT@' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
        ]);
        write_private("$etc/$id.env", $env);
        write_private("$etc/$id.login", $password . "\n");
    }
    if ($id === 'calibre-web') {
        $seed = path('apps_catalogue') . '/calibre-empty-library/metadata.db';
        $target = path('apps') . '/calibre-web/books/metadata.db';
        if (is_file($seed) && !is_file($target)) {
            copy($seed, $target);
        }
    }
    return true;
}

/** The Apache site that puts an application on the home network. */
function app_proxy_config(array $app): string
{
    $id = $app['id'];
    $tls = $app['scheme'] === 'https';
    $lines = [
        "# Offline application proxy: {$app['title']}.",
        '# Written by "offline apps"; changes here are overwritten. The container listens on',
        '# 127.0.0.1 only. This site is what other devices reach, under the same',
        '# home-network rule as the portal.',
        '',
    ];
    foreach ($app['proxies'] as $proxy) {
        $lines = array_merge($lines, [
            "Listen {$proxy['lan']}" . ($tls ? ' https' : ''),
            "<VirtualHost *:{$proxy['lan']}>",
            "    ErrorLog \${APACHE_LOG_DIR}/offline-app-$id-error.log",
            "    CustomLog \${APACHE_LOG_DIR}/offline-app-$id-access.log combined",
        ]);
        if ($tls) {
            $lines = array_merge($lines, [
                '    SSLEngine on',
                '    SSLCertificateFile ' . TLS_DIR . '/offline.crt',
                '    SSLCertificateKeyFile ' . TLS_DIR . '/offline.key',
                '    SSLProtocol -all +TLSv1.2 +TLSv1.3',
                '    SSLHonorCipherOrder off',
                '    SSLSessionTickets off',
                '    RequestHeader set X-Forwarded-Proto "https"',
            ]);
        }
        $gated = $app['audience'] === 'gate';
        if ($gated) {
            // The application has no sign-in of its own, so the portal's stands in front of it.
            $lines[] = '    Include /etc/apache2/offline/session-gate.conf';
            $lines[] = '    ErrorDocument 403 "This is for the adults of the house. Sign in to the portal first, then open this address again."';
        }
        $lines = array_merge($lines, [
            '    <Location />',
            $gated ? '        <RequireAll>' : null,
            $gated ? '        Require env OFFLINE_ADULT' : null,
            '        <RequireAny>',
            '            Require local',
            '            <IfDefine OFFLINE_LAN>',
            '                Require ip ' . implode(' ', LAN_NETS),
            '            </IfDefine>',
            '            <IfDefine OFFLINE_NET>',
            '                Require ip 10.47.0.0/16',
            '            </IfDefine>',
            '        </RequireAny>',
            $gated ? '        </RequireAll>' : null,
            '    </Location>',
            '    # Uploads to an application may be large; the portal keeps its own 1 MiB limit.',
            '    LimitRequestBody 4294967296',
            '    ProxyRequests Off',
            '    ProxyPreserveHost On',
            '    ProxyTimeout 600',
            "    ProxyPass / http://127.0.0.1:{$proxy['local']}/ upgrade=websocket",
            "    ProxyPassReverse / http://127.0.0.1:{$proxy['local']}/",
            '    Header always set X-Content-Type-Options "nosniff"',
            '    Header always set Referrer-Policy "no-referrer"',
            '</VirtualHost>',
            '',
        ]);
    }
    return implode("\n", array_filter($lines, static fn (?string $line): bool => $line !== null));
}

function app_firewall(array $app, bool $open): void
{
    if (!have('ufw')) {
        return;
    }
    foreach ($app['proxies'] as $proxy) {
        foreach (LAN_NETS as $net) {
            $rule = ['from', $net, 'to', 'any', 'port', (string) $proxy['lan'], 'proto', 'tcp'];
            if ($open && config('PORTAL_SCOPE') !== 'local') {
                run(array_merge(['ufw', 'allow'], $rule, ['comment', "Offline app {$app['id']}"]), 30);
            } else {
                run(array_merge(['ufw', 'delete', 'allow'], $rule), 30);
            }
        }
    }
}

function app_write_unit(string $id, bool $boot): bool
{
    $unit = rtrim((string) file_get_contents(app_unit_source($id))) . "\n";
    if ($boot) {
        $unit .= "\n[Install]\nWantedBy=multi-user.target\n";
    }
    @mkdir(dirname(app_unit_target($id)), 0755, true);
    return write_atomic(app_unit_target($id), $unit, 0644);
}

function app_add(array $app, bool $start): bool
{
    $id = $app['id'];
    if (!in_array(machine_arch(), $app['arch'], true)) {
        warn("{$app['title']} is not published for this machine's architecture (" . machine_arch() . '); skipped.');
        return false;
    }
    $image = app_image($id);
    if ($image === null) {
        warn("No unit file is shipped for $id.");
        return false;
    }
    out(paint($app['title'], 'bold'));
    if ($app['set'] === 'retired') {
        warn("{$app['title']} is no longer maintained upstream and receives no security fixes. Stop it when you have finished: sudo offline apps stop $id");
    }
    [$code] = prefix() !== '' ? [0] : run(['podman', 'image', 'exists', $image], 30);
    if ($code !== 0) {
        out('  fetching its image (verified against the pinned digest) ...');
        if (run_live(['podman', 'pull', '--quiet', $image]) !== 0) {
            warn("The image for {$app['title']} could not be fetched. Is this system online?");
            return false;
        }
    }
    if (!app_prepare($app)) {
        return false;
    }
    if ($app['scheme'] === 'https' && !ensure_tls_certificate()) {
        return false;
    }
    $boot = app_installed($id) ? app_boot_enabled($id) : $app['boot'] === 'auto';
    if (!app_write_unit($id, $boot)) {
        warn('Could not write ' . app_unit_target($id));
        return false;
    }
    if ($app['proxies'] !== []) {
        $site = prefix() . "/etc/apache2/sites-available/offline-app-$id.conf";
        @mkdir(dirname($site), 0755, true);
        write_atomic($site, app_proxy_config($app), 0644);
        if (prefix() === '') {
            sys(['a2enmod', '-q', 'proxy', 'proxy_http', 'headers', 'ssl']);
            sys(['a2ensite', '-q', "offline-app-$id"]);
            if (sys(['apache2ctl', 'configtest'])) {
                sys(['systemctl', 'reload-or-restart', 'apache2.service']);
            } else {
                sys(['a2dissite', '-q', "offline-app-$id"]);
                warn("Apache rejected the proxy for {$app['title']}; it is installed but reachable from this system only.");
            }
            app_firewall($app, true);
        }
    }
    if (prefix() === '') {
        sys(['systemctl', 'daemon-reload']);
        if ($start || $boot) {
            out('  starting ...');
            sys(['systemctl', 'restart', "offline-app-$id.service"], 360);
        }
    }
    $port = $app['proxies'][0]['lan'] ?? null;
    out('  ' . paint('✔', 'green') . ' installed' . ($boot ? ', starts at boot' : ', starts on request (sudo offline apps start ' . $id . ')')
        . ($port !== null ? ". Address: {$app['scheme']}://localhost:$port/" : ''));
    return true;
}

function app_remove(array $app): void
{
    $id = $app['id'];
    if (prefix() === '') {
        run(['systemctl', 'stop', "offline-app-$id.service"], 120);
        run(['a2dissite', '-q', "offline-app-$id"], 30);
        app_firewall($app, false);
    }
    @unlink(app_unit_target($id));
    @unlink(prefix() . "/etc/apache2/sites-available/offline-app-$id.conf");
    if (prefix() === '') {
        run(['systemctl', 'daemon-reload'], 60);
        run(['systemctl', 'reload-or-restart', 'apache2.service'], 60);
    }
    out("{$app['title']} removed. Its data is kept in " . path('apps') . "/$id.");
}

function command_apps(array $args): int
{
    $action = $args[0] ?? 'list';
    $apps = app_index();
    $named = array_values(array_filter(array_slice($args, 1), static fn (string $a): bool => $a[0] !== '-'));

    if ($action === 'list') {
        $rows = [];
        foreach (app_states() as $id => $state) {
            $status = !$state['installed'] ? '-' : ($state['state'] === 'active' ? 'running' : 'stopped') . ($state['boot'] ? ', at boot' : ', on request');
            $rows[] = [$id, $apps[$id]['title'], $apps[$id]['category'], $state['port'] !== null ? "{$state['scheme']}://localhost:{$state['port']}/" : 'this system only', $status];
        }
        table(['App', 'Name', 'Kind', 'Address', 'This machine'], $rows);
        out();
        out('Install with: sudo offline apps add APP ...   (or "all" for the whole Project NOMAD set)');
        out('Then: sudo offline apps start|stop|enable|disable|remove APP   and   sudo offline apps info APP');
        return 0;
    }

    require_root('apps ' . $action);
    if (!in_array($action, ['add', 'pull', 'remove', 'start', 'stop', 'restart', 'enable', 'disable', 'info', 'refresh'], true)) {
        fail('Usage: offline apps [list]   |   sudo offline apps add|pull|remove|start|stop|restart|enable|disable|info APP ...');
    }
    if ($action !== 'info' && $action !== 'refresh' && prefix() === '' && !have('podman')) {
        fail('Podman is not installed. Run the installer, or: sudo apt install podman');
    }
    // "all" is the Project NOMAD set; additions of this project are named explicitly.
    $chosen = [];
    foreach ($action === 'refresh' ? array_keys(array_filter($apps, static fn (array $a): bool => app_installed($a['id']))) : $named as $name) {
        if ($name === 'all') {
            $chosen = array_merge($chosen, array_keys(array_filter($apps, static fn (array $a): bool => $a['set'] === 'nomad')));
        } elseif (isset($apps[$name])) {
            $chosen[] = $name;
        } else {
            fail('No application called "' . printable($name) . '". Run: offline apps');
        }
    }
    $chosen = array_values(array_unique($chosen));
    if ($chosen === [] && $action !== 'refresh') {
        fail("Name at least one application, for example: sudo offline apps $action cyberchef");
    }
    $failed = 0;
    foreach ($chosen as $id) {
        $app = $apps[$id];
        switch ($action) {
            case 'add':
            case 'refresh':
                $failed += app_add($app, in_array('--start', $args, true)) ? 0 : 1;
                break;
            case 'pull':
                $image = app_image($id);
                if (!in_array(machine_arch(), $app['arch'], true)) {
                    out("{$app['title']}: not published for " . machine_arch() . '; skipped.');
                    break;
                }
                out("Fetching {$app['title']} ...");
                $failed += $image !== null && run_live(['podman', 'pull', '--quiet', $image]) === 0 ? 0 : 1;
                break;
            case 'remove':
                app_remove($app);
                break;
            case 'start':
            case 'stop':
            case 'restart':
                if (!app_installed($id)) {
                    warn("{$app['title']} is not installed. Run: sudo offline apps add $id");
                    $failed++;
                    break;
                }
                $failed += sys(['systemctl', $action, "offline-app-$id.service"], 360) ? 0 : 1;
                out("{$app['title']}: $action" . ($action === 'stop' ? 'ped' : 'ed') . '.');
                break;
            case 'enable':
            case 'disable':
                if (!app_installed($id)) {
                    warn("{$app['title']} is not installed. Run: sudo offline apps add $id");
                    $failed++;
                    break;
                }
                app_write_unit($id, $action === 'enable');
                sys(['systemctl', 'daemon-reload']);
                if ($action === 'enable') {
                    sys(['systemctl', 'start', "offline-app-$id.service"], 360);
                }
                out("{$app['title']} will " . ($action === 'enable' ? 'now' : 'no longer') . ' start at boot.');
                break;
            case 'info':
                $port = $app['proxies'][0]['lan'] ?? null;
                out(paint($app['title'], 'bold') . '  ' . $app['description']);
                out('  Address   ' . ($port !== null ? "{$app['scheme']}://localhost:$port/" . ($app['scheme'] === 'https' ? '   (self-signed certificate: accept the warning once)' : '') : 'this system only'));
                out('  Sign-in   ' . $app['login']);
                $login = prefix() . APPS_ETC . "/$id.login";
                if (is_readable($login)) {
                    out('  Password  ' . trim((string) file_get_contents($login)));
                }
                out('  Data      ' . path('apps') . "/$id");
                out('  Memory    limited to ' . $app['memory']);
                out('  Logs      journalctl -u offline-app-' . $id);
                break;
        }
    }
    if (in_array($action, ['add', 'remove', 'start', 'stop', 'restart', 'enable', 'disable', 'refresh'], true) && prefix() === '') {
        // Refresh what the portal shows, as the unprivileged account.
        run(['systemctl', 'start', 'offline-status.service'], 60);
    }
    return $failed === 0 ? 0 : 1;
}

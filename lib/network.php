<?php
/**
 * Offline — a network and a clock of the household's own.
 *
 * Hotspot: when the router or its power is gone, a system with a Wi-Fi adapter
 * becomes the access point. Telephones, tablets and laptops join it and reach
 * the portal, the library, the maps, the games and each other's shared content
 * exactly as they do at home. Nothing is forwarded: a device on the hotspot
 * reaches this system and nothing beyond it.
 *
 * Time: a home server answers time requests from the home network and goes on
 * answering when there is no internet, so that clocks stay in step. Family
 * broadcasts depend on that.
 */

declare(strict_types=1);

namespace Offline;

const HOTSPOT_PROFILE = 'offline-hotspot';
const HOTSPOT_ADDRESS = '10.42.0.1';
const HOTSPOT_NETWORK = '10.42.0.0/24';

function hotspot_profile_file(): string
{
    return prefix() . '/etc/NetworkManager/system-connections/' . HOTSPOT_PROFILE . '.nmconnection';
}

/** The stored hotspot profile as section => key => value, or [] if there is none. Root only: it holds the passphrase. */
function hotspot_profile(): array
{
    $profile = [];
    $section = '';
    foreach (@file(hotspot_profile_file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\[([a-z0-9-]+)\]$/', $line, $m) === 1) {
            $section = $m[1];
        } elseif ($section !== '' && preg_match('/^([a-z0-9-]+)=(.*)$/', $line, $m) === 1) {
            $profile[$section][$m[1]] = $m[2];
        }
    }
    return $profile;
}

/** A passphrase that can be read out and typed on a telephone: four groups of four, no look-alike characters. */
function hotspot_passphrase(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $group = '';
        for ($i = 0; $i < 4; $i++) {
            $group .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }
        $groups[] = $group;
    }
    return implode('-', $groups);
}

/** Wi-Fi adapters NetworkManager knows of: [['if' => 'wlp2s0', 'state' => 'connected', 'connection' => 'Home']]. */
function wifi_devices(): array
{
    if (prefix() !== '' || !have('nmcli')) {
        return [];
    }
    [, $output] = run(['nmcli', '-t', '-f', 'DEVICE,TYPE,STATE,CONNECTION', 'device', 'status'], 15);
    $devices = [];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
        $fields = explode(':', $line);
        if (($fields[1] ?? '') === 'wifi' && preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $fields[0]) === 1) {
            $devices[] = ['if' => $fields[0], 'state' => (string) ($fields[2] ?? ''), 'connection' => (string) ($fields[3] ?? '')];
        }
    }
    return $devices;
}

function hotspot_active(): bool
{
    if (prefix() !== '' || !have('nmcli')) {
        return false;
    }
    [, $output] = run(['nmcli', '-t', '-f', 'NAME', 'connection', 'show', '--active'], 15);
    return in_array(HOTSPOT_PROFILE, preg_split('/\R/', trim($output)) ?: [], true);
}

/** The NetworkManager profile. Declarative, so what will run can be read before it does. */
function hotspot_keyfile(string $interface, string $ssid, string $passphrase, string $band, int $channel, bool $autoconnect): string
{
    return implode("\n", [
        '# Written by "offline hotspot"; changes here are overwritten.',
        '[connection]',
        'id=' . HOTSPOT_PROFILE,
        'type=wifi',
        'interface-name=' . $interface,
        'autoconnect=' . ($autoconnect ? 'true' : 'false'),
        '',
        '[wifi]',
        'mode=ap',
        'ssid=' . $ssid,
        'band=' . $band,
        'channel=' . $channel,
        '',
        '[wifi-security]',
        '# WPA2 with AES only: no WEP, no TKIP, no push-button pairing.',
        'key-mgmt=wpa-psk',
        'proto=rsn;',
        'pairwise=ccmp;',
        'group=ccmp;',
        'pmf=2',
        'wps-method=1',
        'psk=' . $passphrase,
        '',
        '[ipv4]',
        '# "shared" gives the hotspot its own addresses and name service.',
        'method=shared',
        'address1=' . HOTSPOT_ADDRESS . '/24',
        '',
        '[ipv6]',
        'method=disabled',
        '',
    ]);
}

/** Names the hotspot's own name service answers; it answers nothing about the outside world. */
function hotspot_names(): string
{
    $host = preg_replace('/[^a-z0-9-]/', '', strtolower((string) gethostname())) ?: 'offline';
    return implode("\n", [
        '# Written by "offline hotspot". Read by the name service NetworkManager runs for the hotspot.',
        '# Devices on the hotspot can reach this system and nothing beyond it, so questions about',
        '# other names are refused at once instead of being left to time out.',
        'no-resolv',
        'address=/offline.lan/' . HOTSPOT_ADDRESS,
        "address=/$host.lan/" . HOTSPOT_ADDRESS,
        '',
    ]);
}

function hotspot_firewall(string $interface, bool $open): void
{
    if (prefix() !== '' || !have('ufw')) {
        return;
    }
    // Addresses and names for joining devices. Everything else they may reach is already
    // governed by the home-network rules, which the hotspot's address range falls within.
    $rules = [
        ['allow', 'in', 'on', $interface, 'to', 'any', 'port', '67', 'proto', 'udp'],
        ['allow', 'in', 'on', $interface, 'from', HOTSPOT_NETWORK, 'to', 'any', 'port', '53'],
    ];
    foreach ($rules as $rule) {
        run($open ? array_merge(['ufw'], $rule, ['comment', 'Offline hotspot']) : array_merge(['ufw', 'delete'], $rule), 30);
    }
    // Nothing that arrives from the hotspot is passed on to any other network.
    $route = ['route', 'deny', 'in', 'on', $interface];
    run($open ? array_merge(['ufw'], $route, ['comment', 'Offline hotspot: no forwarding']) : array_merge(['ufw', 'route', 'delete', 'deny', 'in', 'on', $interface]), 30);
}

function hotspot_show(array $profile): void
{
    $ssid = (string) ($profile['wifi']['ssid'] ?? '');
    $passphrase = (string) ($profile['wifi-security']['psk'] ?? '');
    out('  Network name   ' . $ssid);
    out('  Passphrase     ' . $passphrase);
    out('  Then open      http://offline.lan/   (or http://' . HOTSPOT_ADDRESS . '/)');
    if (prefix() === '' && have('qrencode') && preg_match('/^[A-Za-z0-9 ._-]+$/', $ssid . $passphrase) === 1) {
        out();
        out('  Point a telephone\'s camera at this to join:');
        run_live(['qrencode', '-t', 'ANSIUTF8', '-m', '2', "WIFI:T:WPA;S:$ssid;P:$passphrase;;"]);
    }
}

function command_hotspot(array $args): int
{
    $action = $args[0] ?? 'status';
    $file = hotspot_profile_file();

    if ($action === 'status') {
        $configured = is_file($file);
        $active = hotspot_active();
        out('Hotspot: ' . ($active ? 'on' : ($configured ? 'set up, currently off' : 'not set up')));
        if (prefix() === '' && !have('nmcli')) {
            out('  NetworkManager is not installed. The hotspot needs it: sudo apt install network-manager');
        }
        foreach (wifi_devices() as $device) {
            out("  Wi-Fi adapter {$device['if']}: {$device['state']}" . ($device['connection'] !== '' ? " ({$device['connection']})" : ''));
        }
        out();
        out('sudo offline hotspot on [--ssid NAME] [--interface IF] [--band bg|a] [--channel N]');
        out('sudo offline hotspot show | off | remove');
        return 0;
    }

    if (!in_array($action, ['on', 'off', 'show', 'remove'], true)) {
        fail('Usage: offline hotspot [status]   |   sudo offline hotspot on [--ssid NAME] [--interface IF] [--band bg|a] [--channel N] | show | off | remove');
    }
    require_root("hotspot $action");
    $profile = hotspot_profile();

    if ($action === 'show') {
        if ($profile === []) {
            fail('No hotspot has been set up. Create one with: sudo offline hotspot on');
        }
        hotspot_show($profile);
        return 0;
    }

    if ($action === 'off' || $action === 'remove') {
        if ($profile === []) {
            out('No hotspot is set up.');
            return 0;
        }
        $interface = (string) ($profile['connection']['interface-name'] ?? '');
        if (prefix() === '' && have('nmcli')) {
            run(['nmcli', 'connection', 'down', HOTSPOT_PROFILE], 30);
        }
        if ($action === 'remove') {
            @unlink($file);
            @unlink(prefix() . '/etc/NetworkManager/dnsmasq-shared.d/offline.conf');
        } else {
            // Kept, with its name and passphrase, but it no longer comes up by itself.
            $previous = umask(0077);
            file_put_contents($file, hotspot_keyfile($interface, (string) $profile['wifi']['ssid'], (string) $profile['wifi-security']['psk'],
                (string) ($profile['wifi']['band'] ?? 'bg'), (int) ($profile['wifi']['channel'] ?? 6), false));
            umask($previous);
            chmod($file, 0600);
        }
        if ($interface !== '') {
            hotspot_firewall($interface, false);
        }
        if (prefix() === '' && have('nmcli')) {
            run(['nmcli', 'connection', 'reload'], 30);
        }
        out($action === 'remove' ? 'The hotspot has been removed.' : 'The hotspot is off. Its name and passphrase are kept for next time.');
        return 0;
    }

    // on
    if ((string) config('SYNC_EXPOSE') === 'internet') {
        fail('A server exposed to the internet raises no hotspot: only the keyed sharing port faces the world.');
    }
    $interface = (string) ($profile['connection']['interface-name'] ?? '');
    $ssid = (string) ($profile['wifi']['ssid'] ?? '');
    $band = (string) ($profile['wifi']['band'] ?? 'bg');
    $channel = (int) ($profile['wifi']['channel'] ?? 6);
    $yes = in_array('--yes', $args, true) || in_array('-y', $args, true);
    for ($i = 1; $i < count($args); $i++) {
        $value = $args[$i + 1] ?? null;
        if ($args[$i] === '--ssid' && $value !== null) {
            $ssid = $value;
            $i++;
        } elseif ($args[$i] === '--interface' && $value !== null) {
            $interface = $value;
            $i++;
        } elseif ($args[$i] === '--band' && $value !== null) {
            $band = $value;
            $channel = $value === 'a' ? 36 : 6;
            $i++;
        } elseif ($args[$i] === '--channel' && $value !== null) {
            $channel = (int) $value;
            $i++;
        }
    }
    if (prefix() === '' && (!have('nmcli') || run(['systemctl', 'is-active', '--quiet', 'NetworkManager.service'], 10)[0] !== 0)) {
        fail("The hotspot is run by NetworkManager, which is not running on this system.\n"
            . 'Install it with: sudo apt install network-manager   (on Ubuntu Server it then manages Wi-Fi only and leaves the wired network alone)');
    }
    $devices = wifi_devices();
    if ($interface === '') {
        $interface = (string) ($devices[0]['if'] ?? '');
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $interface) !== 1) {
        fail('No Wi-Fi adapter was found. Name one with --interface, or plug one in that supports access-point mode.');
    }
    if ($ssid === '') {
        $ssid = 'Offline-' . (preg_replace('/[^A-Za-z0-9-]/', '', (string) gethostname()) ?: 'home');
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,31}$/', $ssid) !== 1) {
        fail('A network name may hold up to 32 letters, digits, spaces, dots, hyphens and underscores.');
    }
    if (!in_array($band, ['bg', 'a'], true) || $channel < 1 || $channel > 165 || ($band === 'bg' && $channel > 13) || ($band === 'a' && $channel < 36)) {
        fail('--band is bg (2.4 GHz, channels 1 to 13; reaches furthest) or a (5 GHz, channels 36 and up).');
    }
    if (prefix() === '' && have('iw')) {
        [, $modes] = run(['iw', 'list'], 15);
        if ($modes !== '' && preg_match('/Supported interface modes:(?:\s+\* [^\n]+)*\s+\* AP\b/', $modes) !== 1) {
            fail('No Wi-Fi adapter on this system supports access-point mode, which a hotspot needs.');
        }
    }
    foreach ($devices as $device) {
        if ($device['if'] === $interface && $device['state'] === 'connected' && $device['connection'] !== HOTSPOT_PROFILE
            && !confirm("  $interface is this system's connection to \"{$device['connection']}\". Turning the hotspot on drops that connection. Continue?", $yes)) {
            out('Left as it was. A second Wi-Fi adapter, or a wired connection, lets the system keep both.');
            return 1;
        }
    }
    $passphrase = (string) ($profile['wifi-security']['psk'] ?? '');
    if (\strlen($passphrase) < 16) {
        $passphrase = hotspot_passphrase();
    }
    @mkdir(dirname($file), 0755, true);
    $previous = umask(0077);
    $written = file_put_contents($file . '.tmp', hotspot_keyfile($interface, $ssid, $passphrase, $band, $channel, true));
    umask($previous);
    if ($written === false || !chmod($file . '.tmp', 0600) || !rename($file . '.tmp', $file)) {
        fail("Could not write $file");
    }
    $names = prefix() . '/etc/NetworkManager/dnsmasq-shared.d/offline.conf';
    @mkdir(dirname($names), 0755, true);
    write_atomic($names, hotspot_names(), 0644);
    hotspot_firewall($interface, true);
    if (prefix() === '') {
        run(['nmcli', 'connection', 'reload'], 30);
        [$code, , $error] = run(['nmcli', 'connection', 'up', HOTSPOT_PROFILE], 60);
        if ($code !== 0) {
            fail('NetworkManager could not start the hotspot: ' . printable(trim($error), 200));
        }
    }
    out(paint('The hotspot is on', 'bold') . " ($interface) and will come back after a restart.");
    hotspot_show(hotspot_profile());
    if ((string) config('PORTAL_SCOPE') === 'local') {
        warn('The portal is restricted to this system (PORTAL_SCOPE=local), so devices on the hotspot cannot open it. Run the installer with --lan to change that.');
    }
    out('Devices on the hotspot reach this system only. Their own screens will say there is no internet; that is expected.');
    return 0;
}

// ---------------------------------------------------------------------------
// Time
// ---------------------------------------------------------------------------

/** Configured central servers that sit on a private network, and so may be asked for the time. */
function time_source_hosts(): array
{
    $hosts = [];
    foreach (servers() as $server) {
        $addresses = filter_var($server['host'], FILTER_VALIDATE_IP) !== false ? [$server['host']] : (@gethostbynamel($server['host']) ?: []);
        if ($addresses !== [] && array_filter($addresses, static fn (string $a): bool => !is_private_ipv4($a)) === []) {
            $hosts[] = $server['host'];
        }
    }
    return $hosts;
}

/**
 * Point this system's clock at the household's own time servers as well as its
 * usual ones. Works with chrony or with systemd-timesyncd, whichever is here.
 */
function time_apply_sources(): array
{
    $hosts = time_source_hosts();
    $chrony = prefix() . '/etc/chrony/sources.d/offline.sources';
    $timesyncd = prefix() . '/etc/systemd/timesyncd.conf.d/offline.conf';
    if (is_dir(prefix() . '/etc/chrony')) {
        @unlink($timesyncd);
        if ($hosts === []) {
            @unlink($chrony);
        } else {
            @mkdir(dirname($chrony), 0755, true);
            write_atomic($chrony, "# Written by \"offline\": the household's own time servers.\n"
                . implode('', array_map(static fn (string $h): string => "server $h iburst\n", $hosts)), 0644);
        }
        if (prefix() === '' && have('chronyc')) {
            run(['chronyc', 'reload', 'sources'], 15);
        }
    } else {
        @unlink($chrony);
        if ($hosts === []) {
            @unlink($timesyncd);
        } else {
            @mkdir(dirname($timesyncd), 0755, true);
            // Tried in order, so the household's server answers at home and the usual one answers elsewhere.
            write_atomic($timesyncd, "# Written by \"offline\": the household's own time servers, then the usual one.\n[Time]\nNTP="
                . implode(' ', $hosts) . " ntp.ubuntu.com\n", 0644);
        }
        if (prefix() === '' && have('systemctl')) {
            run(['systemctl', 'try-restart', 'systemd-timesyncd.service'], 30);
        }
    }
    return $hosts;
}

function time_serving(): bool
{
    return is_file(prefix() . '/etc/chrony/conf.d/offline-server.conf');
}

function time_serve(bool $on): bool
{
    $file = prefix() . '/etc/chrony/conf.d/offline-server.conf';
    if ($on) {
        if (!is_dir(prefix() . '/etc/chrony')) {
            warn('chrony is not installed, so this system cannot serve time. Install it with: sudo apt install chrony');
            return false;
        }
        if ((string) config('SYNC_EXPOSE') === 'internet') {
            warn('A server exposed to the internet does not serve time: an open time server can be abused to flood others.');
            return false;
        }
        @mkdir(dirname($file), 0755, true);
        write_atomic($file, implode("\n", array_merge(
            ['# Written by "offline time serve". Answer the home network, and go on answering', '# with this system\'s own clock when there is no internet to check it against.'],
            array_map(static fn (string $net): string => "allow $net", LAN_NETS),
            ['local stratum 10 orphan', '# A flood of requests from one address is slowed down.', 'ratelimit interval 1 burst 16', '']
        )), 0644);
    } else {
        @unlink($file);
    }
    if (prefix() === '') {
        if (have('ufw')) {
            foreach (LAN_NETS as $net) {
                $rule = ['from', $net, 'to', 'any', 'port', '123', 'proto', 'udp'];
                run($on ? array_merge(['ufw', 'allow'], $rule, ['comment', 'Offline time service']) : array_merge(['ufw', 'delete', 'allow'], $rule), 30);
            }
        }
        run(['systemctl', 'restart', 'chrony.service'], 60);
    }
    return true;
}

function command_time(array $args): int
{
    $action = $args[0] ?? 'status';
    if ($action === 'status') {
        $synced = null;
        if (prefix() === '' && have('timedatectl')) {
            [$code, $output] = run(['timedatectl', 'show', '--property=NTPSynchronized', '--value'], 10);
            $synced = $code === 0 ? trim($output) === 'yes' : null;
        }
        out('Clock now     ' . utc_display(utc_now()));
        out('Set from a time server since starting: ' . ($synced === null ? 'unknown' : ($synced ? 'yes' : 'no')));
        $hosts = time_source_hosts();
        out('Household time servers: ' . ($hosts === [] ? 'none (a central server on the home network becomes one automatically)' : implode(', ', $hosts)));
        out('Serving time to the home network: ' . (time_serving() ? 'yes' : 'no'));
        out();
        out('sudo offline time serve on|off     answer time requests from the home network (needs chrony)');
        out('sudo offline time sources          apply the list of household time servers again');
        return 0;
    }
    require_root("time $action");
    if ($action === 'sources') {
        $hosts = time_apply_sources();
        out($hosts === [] ? 'No household time server is configured; the usual ones are used.' : 'This system now also asks ' . implode(', ', $hosts) . ' for the time.');
        return 0;
    }
    if ($action === 'serve' && in_array($args[1] ?? '', ['on', 'off'], true)) {
        $ok = time_serve($args[1] === 'on');
        if ($ok) {
            out($args[1] === 'on' ? 'This system now answers time requests from the home network, with or without the internet.' : 'This system no longer serves time.');
        }
        return $ok ? 0 : 1;
    }
    fail('Usage: offline time [status]   |   sudo offline time serve on|off   |   sudo offline time sources');
}

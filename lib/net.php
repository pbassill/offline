<?php
/**
 * Offline — the household's private network: "offline net".
 *
 * Two layers, both closed to anyone without the house key.
 *
 * Wherever there is an IP path (the home network, the internet, a mobile
 * connection) systems reach one another through WireGuard. Each system has a
 * key pair of its own and an address in 10.47.0.0/16 worked out from its
 * public key, and every tunnel also needs a pre-shared key derived from the
 * house key. Systems learn of one another from a small list of nodes that
 * travels with the sync, as the accounts do. A system that others can reach
 * from outside (a server in a datacentre, or a home system behind a forwarded
 * port) says so with "offline net endpoint", and the rest connect to it.
 *
 * Where there is no IP path, short messages still get through: Reticulum
 * carries family broadcasts over LoRa (an RNode radio) and, if switched on,
 * packet radio, and over the tunnels and the home network as well, so that a
 * broadcast takes whichever path exists. Every Reticulum interface carries an
 * access code derived from the house key, and traffic without it is dropped.
 */

declare(strict_types=1);

namespace Offline;

const NET_INTERFACE = 'offline0';
const NET_PREFIX = '10.47';
const NET_RANGE = '10.47.0.0/16';
const NET_PORT = 51820;
const NET_ROLES = ['server', 'workstation', 'repeater'];
/** Reticulum, pinned: the wheel is pure Python and is installed by hash; what it needs comes from Ubuntu's archive. */
const RNS_VERSION = '1.6.0';
const RNS_WHEEL_SHA256 = 'fcf2d07eebafea7e56a94542bd74646e451b0f33bb3efb5f914bbbba38b39f36';
const RNS_VENV = '/opt/offline/rns';
const RNS_PORT = 4242;
const MESH_UNIT = 'offline-mesh.service';

function net_dir(): string
{
    return prefix() . '/etc/offline/net';
}

function net_nodes_file(): string
{
    return path('state') . '/network/nodes.json';
}

function net_wireguard_conf(): string
{
    return prefix() . '/etc/wireguard/' . NET_INTERFACE . '.conf';
}

/** The overlay address that belongs to a public key. Nobody can claim another system's: it follows from the key. */
function net_address(string $publicKey): ?string
{
    $raw = base64_decode($publicKey, true);
    if ($raw === false || strlen($raw) !== 32) {
        return null;
    }
    $hash = hash('sha256', 'offline private network v1' . $raw, true);
    // The last part of an address is never 0 or 255.
    return NET_PREFIX . '.' . ord($hash[0]) . '.' . (1 + ord($hash[1]) % 254);
}

function net_record_ok(mixed $record, string $publicKey): bool
{
    if (!is_array($record) || array_diff(array_keys($record), ['name', 'role', 'address', 'endpoint', 'removed', 'updated']) !== []) {
        return false;
    }
    $updated = (string) ($record['updated'] ?? '');
    return preg_match('/^[A-Za-z0-9+\/]{43}=$/', $publicKey) === 1
        && is_string($record['name'] ?? null) && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $record['name']) === 1
        && in_array($record['role'] ?? null, NET_ROLES, true)
        && ($record['address'] ?? null) === net_address($publicKey)
        && (($record['endpoint'] ?? null) === null || (is_string($record['endpoint']) && net_endpoint_ok($record['endpoint'])))
        && is_bool($record['removed'] ?? null)
        && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $updated) === 1
        && ($stamp = strtotime($updated)) !== false && $stamp <= time() + 86400;
}

/** host:port, where the host is a name or an IPv4 address and nothing that could be read as an option or a path. */
function net_endpoint_ok(string $endpoint): bool
{
    return preg_match('/^(?=.{3,260}$)[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?:([1-9]\d{1,4})$/', $endpoint, $m) === 1 && (int) $m[2] <= 65535;
}

/** Every valid node, by public key. */
function net_nodes(?string $file = null): array
{
    $nodes = [];
    foreach (read_json($file ?? net_nodes_file())['nodes'] ?? [] as $key => $record) {
        if (is_string($key) && net_record_ok($record, $key)) {
            $nodes[$key] = $record;
        }
    }
    ksort($nodes);
    return $nodes;
}

function net_nodes_save(array $nodes): bool
{
    ksort($nodes);
    $dir = dirname(net_nodes_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return write_atomic(net_nodes_file(), json_encode(['version' => 1, 'nodes' => $nodes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0644);
}

/** Keep whatever another system knows that is newer than what is held here. Returns [nodes, changed names]. */
function net_nodes_merge(array $mine, array $theirs): array
{
    $changed = [];
    foreach ($theirs as $key => $record) {
        if (!is_string($key) || !net_record_ok($record, $key)) {
            continue;
        }
        if (!isset($mine[$key]) || strcmp($record['updated'], $mine[$key]['updated']) > 0) {
            $mine[$key] = $record;
            $changed[] = $record['name'];
        }
    }
    ksort($mine);
    return [$mine, $changed];
}

/** This system's own keys, or null before "offline net join". The private key never leaves its file. */
function net_identity(): ?array
{
    $public = trim((string) @file_get_contents(net_dir() . '/public.key'));
    $address = net_address($public);
    return $address === null ? null : ['public' => $public, 'address' => $address];
}

function net_psk(): ?string
{
    $hex = derived_key('offline wireguard preshared key v1');
    return $hex === null ? null : base64_encode((string) hex2bin($hex));
}

function net_active(): bool
{
    return prefix() === '' && is_dir('/sys/class/net/' . NET_INTERFACE);
}

/**
 * The WireGuard configuration for this system: every other node of the
 * household as a peer, each allowed its one address and nothing more.
 */
function net_wireguard_config(array $identity, string $privateKey, array $nodes, ?string $ownEndpoint): string
{
    $psk = net_psk();
    $lines = [
        '# Offline private network. Written by "offline net"; changes here are overwritten.',
        '[Interface]',
        'PrivateKey = ' . $privateKey,
        'Address = ' . $identity['address'] . '/16',
        // Small enough to pass through a mobile connection and a tunnel within a tunnel.
        'MTU = 1280',
    ];
    if ($ownEndpoint !== null) {
        $lines[] = 'ListenPort = ' . substr($ownEndpoint, (int) strrpos($ownEndpoint, ':') + 1);
    }
    foreach ($nodes as $key => $node) {
        if ($key === $identity['public'] || $node['removed'] || $psk === null) {
            continue;
        }
        $lines = array_merge($lines, ['', '# ' . $node['name'] . ' (' . $node['role'] . ')', '[Peer]', 'PublicKey = ' . $key, 'PresharedKey = ' . $psk, 'AllowedIPs = ' . $node['address'] . '/32']);
        if ($node['endpoint'] !== null) {
            $lines[] = 'Endpoint = ' . $node['endpoint'];
            // Keeps the path open through the router when this system is the one that has to call out.
            $lines[] = 'PersistentKeepalive = 25';
        }
    }
    return implode("\n", $lines) . "\n";
}

/** Write the tunnel's configuration and bring the running tunnel into line with it. */
function net_apply(): bool
{
    $identity = net_identity();
    $private = trim((string) @file_get_contents(net_dir() . '/private.key'));
    if ($identity === null || $private === '') {
        return false;
    }
    $nodes = net_nodes();
    $own = $nodes[$identity['public']]['endpoint'] ?? null;
    $conf = net_wireguard_conf();
    @mkdir(dirname($conf), 0700, true);
    $previous = umask(0077);
    $ok = write_atomic($conf, net_wireguard_config($identity, $private, $nodes, $own), 0600);
    umask($previous);
    if (!$ok || prefix() !== '') {
        return $ok;
    }
    if (!have('wg-quick')) {
        warn('wireguard-tools is not installed, so the tunnel cannot be started. Install it with: sudo apt install wireguard-tools');
        return false;
    }
    if (net_active()) {
        // Peers are added and removed without dropping the connections that are up.
        [$code, $stripped] = run(['wg-quick', 'strip', NET_INTERFACE], 30);
        $tmp = dirname($conf) . '/.' . NET_INTERFACE . '.sync';
        if ($code === 0 && write_atomic($tmp, $stripped, 0600)) {
            $ok = sys(['wg', 'syncconf', NET_INTERFACE, $tmp]);
            @unlink($tmp);
        }
    } else {
        $ok = sys(['systemctl', 'enable', '--now', 'wg-quick@' . NET_INTERFACE . '.service']);
    }
    net_firewall(true, $own);
    return $ok;
}

/**
 * The tunnel's port is opened where this system is one that others call. On a
 * server exposed to the internet, what the house's own systems may reach
 * through the tunnel is opened to the tunnel and to nothing else.
 */
function net_firewall(bool $open, ?string $ownEndpoint): void
{
    if (prefix() !== '' || !have('ufw')) {
        return;
    }
    $port = $ownEndpoint !== null ? substr($ownEndpoint, (int) strrpos($ownEndpoint, ':') + 1) : (string) NET_PORT;
    $listen = ['allow', $port . '/udp'];
    run($open && $ownEndpoint !== null ? array_merge(['ufw'], $listen, ['comment', 'Offline private network (WireGuard)']) : array_merge(['ufw', 'delete'], $listen), 30);
    $services = ['80', '443', '8080', '8443', (string) TLS_PORT, (string) RNS_PORT];
    // Installed applications answer the private network on the ports they answer the home network on.
    foreach (app_index() as $id => $app) {
        if (app_installed($id)) {
            foreach ($app['proxies'] as $proxy) {
                $services[] = (string) $proxy['lan'];
            }
        }
    }
    foreach ($services as $service) {
        $rule = ['allow', 'in', 'on', NET_INTERFACE, 'from', NET_RANGE, 'to', 'any', 'port', $service, 'proto', 'tcp'];
        run($open ? array_merge(['ufw'], $rule, ['comment', 'Offline, from the private network']) : array_merge(['ufw', 'delete'], $rule), 30);
    }
    // The web server admits the private network's addresses to the portal, as it admits the home network's.
    $define = '/etc/apache2/conf-available/offline-net.conf';
    if ($open) {
        write_atomic($define, "# Written by \"offline net\": this system is on the household's private network.\nDefine OFFLINE_NET\n", 0644);
        run(['a2enconf', '-q', 'offline-net'], 30);
    } elseif (is_file($define)) {
        run(['a2disconf', '-q', 'offline-net'], 30);
        @unlink($define);
    }
    if (have('apache2ctl') && run(['apache2ctl', 'configtest'], 30)[0] === 0) {
        run(['systemctl', 'reload', 'apache2.service'], 60);
    }
}

function net_status(): array
{
    $identity = net_identity();
    $nodes = net_nodes();
    $own = $identity !== null ? ($nodes[$identity['public']] ?? null) : null;
    $handshakes = [];
    if ($identity !== null && net_active() && is_root()) {
        foreach (preg_split('/\R/', trim(run(['wg', 'show', NET_INTERFACE, 'latest-handshakes'], 10)[1])) ?: [] as $line) {
            [$key, $when] = array_pad(preg_split('/\s+/', $line) ?: [], 2, '0');
            $handshakes[$key] = (int) $when;
        }
    }
    $peers = [];
    foreach ($nodes as $key => $node) {
        if (!$node['removed'] && ($identity === null || $key !== $identity['public'])) {
            $peers[] = ['name' => $node['name'], 'role' => $node['role'], 'address' => $node['address'], 'endpoint' => $node['endpoint'], 'handshake' => $handshakes[$key] ?? null];
        }
    }
    return [
        'joined' => $identity !== null && $own !== null && !$own['removed'],
        'address' => $identity['address'] ?? null,
        'endpoint' => $own['endpoint'] ?? null,
        'up' => net_active(),
        'peers' => $peers,
        'mesh' => mesh_status(),
    ];
}

/** Publish this system's own entry in the list of nodes. */
function net_publish(?string $endpoint, bool $removed = false): bool
{
    $identity = net_identity();
    if ($identity === null) {
        return false;
    }
    $role = (string) config('ROLE');
    $nodes = net_nodes();
    $nodes[$identity['public']] = [
        'name' => preg_replace('/[^a-z0-9-]/', '', strtolower((string) gethostname())) ?: 'offline',
        'role' => in_array($role, NET_ROLES, true) ? $role : 'workstation',
        'address' => $identity['address'], 'endpoint' => $endpoint, 'removed' => $removed, 'updated' => utc_now(),
    ];
    $ok = net_nodes_save($nodes);
    if ($ok && prefix() === '' && is_root()) {
        @chown(dirname(net_nodes_file()), SERVICE_USER);
        @chown(net_nodes_file(), SERVICE_USER);
    }
    return $ok;
}

/** Fetch another system's list of nodes during a sync. Returns the names that changed. */
function net_pull(array $peer): array
{
    $tmp = path('state') . '/nodes-' . getmypid() . '.json';
    @unlink($tmp);
    [$env, $conf] = tls_transport($peer);
    [$code] = run([
        'rsync', '--no-motd', '--timeout=60', '--max-size=1m', '--chmod=F600', '--password-file=' . path('key'),
        rsync_url($peer, 'network') . 'nodes.json', $tmp,
    ], 90, $env);
    @unlink($conf);
    $theirs = $code === 0 && is_file($tmp) && !is_link($tmp) ? net_nodes($tmp) : [];
    @unlink($tmp);
    if ($theirs === []) {
        return [];
    }
    [$merged, $changed] = net_nodes_merge(net_nodes(), $theirs);
    return $changed !== [] && net_nodes_save($merged) ? $changed : [];
}

// ---------------------------------------------------------------------------
// Reticulum: the links that are not IP
// ---------------------------------------------------------------------------

function mesh_dir(): string
{
    return path('state') . '/reticulum';
}

/** LoRa radios running the RNode firmware, by their stable device names. */
function mesh_rnodes(): array
{
    $found = [];
    foreach (glob(prefix() . '/dev/serial/by-id/*') ?: [] as $device) {
        if (preg_match('/rnode|RNode/', basename($device)) === 1 || in_array(substr($device, strlen(prefix())), preg_split('/[\s,]+/', (string) config('MESH_RNODE_PORTS')) ?: [], true)) {
            $found[] = substr($device, strlen(prefix()));
        }
    }
    return $found;
}

/**
 * Reticulum's configuration for this system. A repeater and a server pass
 * traffic on for others; a workstation speaks for itself. Every interface has
 * the household's access code, so a stranger's Reticulum traffic on the same
 * network or the same frequency is neither heard nor carried.
 */
function mesh_config(): ?string
{
    $code = derived_key('offline reticulum access code v1');
    if ($code === null) {
        return null;
    }
    $access = ['    network_name = offline-household', '    passphrase = ' . $code];
    $transport = in_array((string) config('ROLE'), ['server', 'repeater'], true);
    $lines = [
        '# Offline private network, radio layer. Written by "offline net radio"; changes here are overwritten.',
        '[reticulum]', '  enable_transport = ' . ($transport ? 'Yes' : 'No'), '  share_instance = No', '  panic_on_interface_error = No', '',
        '[logging]', '  loglevel = 3', '', '[interfaces]',
    ];
    if ((string) config('SYNC_EXPOSE') !== 'internet') {
        // Whatever else runs Reticulum on a datacentre's network is not family.
        $lines = array_merge($lines, ['  [[Home network]]', '    type = AutoInterface', '    enabled = Yes', '    group_id = offline-' . substr($code, 0, 16)], $access, ['']);
    }
    $identity = net_identity();
    $nodes = net_nodes();
    if ($identity !== null) {
        if (($nodes[$identity['public']]['endpoint'] ?? null) !== null) {
            // Listening on every address means the service starts whether or not the tunnel is up yet. The firewall
            // admits this port from the tunnel alone, the service's own address filter refuses public addresses,
            // and a caller without the access code is not answered.
            $lines = array_merge($lines, ['  [[Private network, listening]]', '    type = TCPServerInterface', '    enabled = Yes', '    listen_ip = 0.0.0.0', '    listen_port = ' . RNS_PORT], $access, ['']);
        }
        foreach ($nodes as $key => $node) {
            if ($key !== $identity['public'] && !$node['removed'] && $node['endpoint'] !== null) {
                $lines = array_merge($lines, ['  [[Private network, to ' . $node['name'] . ']]', '    type = TCPClientInterface', '    enabled = Yes', '    target_host = ' . $node['address'], '    target_port = ' . RNS_PORT], $access, ['']);
            }
        }
    }
    foreach (mesh_rnodes() as $index => $port) {
        $lines = array_merge($lines, [
            '  [[LoRa ' . ($index + 1) . ']]', '    type = RNodeInterface', '    enabled = Yes', '    port = ' . $port,
            '    frequency = ' . config_int('MESH_LORA_FREQUENCY', 150000000, 960000000), '    bandwidth = ' . config_int('MESH_LORA_BANDWIDTH', 7800, 500000),
            '    txpower = ' . config_int('MESH_LORA_TXPOWER', 0, 22), '    spreadingfactor = ' . config_int('MESH_LORA_SPREADING', 7, 12), '    codingrate = ' . config_int('MESH_LORA_CODINGRATE', 5, 8),
            // The share of each hour this radio may spend transmitting, as the licence-exempt bands require.
            '    airtime_limit_long = ' . config_int('MESH_LORA_AIRTIME_PERCENT', 1, 100), '    airtime_limit_short = ' . min(100, config_int('MESH_LORA_AIRTIME_PERCENT', 1, 100) * 10),
        ], $access, ['']);
    }
    $packet = (string) config('MESH_PACKET_PORT');
    if (config_bool('MESH_PACKET') && preg_match('#^/dev/[A-Za-z0-9/._:-]{3,120}$#', $packet) === 1) {
        // Off unless switched on: on amateur frequencies encrypted traffic is not permitted.
        $lines = array_merge($lines, ['  [[Packet radio]]', '    type = KISSInterface', '    enabled = Yes', '    port = ' . $packet, '    speed = ' . config_int('MESH_PACKET_SPEED', 1200, 115200), '    databits = 8', '    parity = none', '    stopbits = 1', '    preamble = 150', '    txtail = 10', '    persistence = 200', '    slottime = 20'], $access, ['']);
    }
    return implode("\n", $lines) . "\n";
}

function mesh_installed(): bool
{
    return is_executable(prefix() . RNS_VENV . '/bin/python3') && is_dir(prefix() . RNS_VENV . '/lib') && glob(prefix() . RNS_VENV . '/lib/python3*/site-packages/RNS') !== [];
}

function mesh_enabled(): bool
{
    return is_file(mesh_dir() . '/config');
}

function mesh_status(): array
{
    $state = 'unknown';
    if (mesh_enabled() && prefix() === '' && have('systemctl')) {
        $state = trim(run(['systemctl', 'is-active', MESH_UNIT], 8)[1]);
    }
    return ['installed' => mesh_installed(), 'enabled' => mesh_enabled(), 'state' => preg_match('/^[a-z-]{2,20}$/', $state) === 1 ? $state : 'unknown', 'radios' => mesh_rnodes(), 'packet' => config_bool('MESH_PACKET')];
}

/** Fetch Reticulum: one wheel, checked against its SHA-256 before a line of it is installed. */
function mesh_setup(): bool
{
    if (mesh_installed()) {
        return true;
    }
    foreach (['python3', 'pip3'] as $tool) {
        if (!have($tool)) {
            warn("$tool is not installed. Run the installer, or: sudo apt install python3-venv python3-pip python3-cryptography python3-serial");
            return false;
        }
    }
    $venv = prefix() . RNS_VENV;
    @mkdir(dirname($venv), 0755, true);
    $requirements = dirname($venv) . '/rns-requirements.txt';
    write_atomic($requirements, 'rns==' . RNS_VERSION . ' --hash=sha256:' . RNS_WHEEL_SHA256 . "\n", 0644);
    out('Fetching Reticulum ' . RNS_VERSION . ' (pinned by checksum) ...');
    // Its two dependencies, cryptography and pyserial, are Ubuntu's own packages, signed like the rest of the system.
    return sys(['python3', '-m', 'venv', '--system-site-packages', $venv], 120)
        && sys([$venv . '/bin/pip', 'install', '--quiet', '--no-deps', '--require-hashes', '--only-binary', ':all:', '-r', $requirements], 300)
        && mesh_installed();
}

function mesh_apply(bool $on): bool
{
    $file = mesh_dir() . '/config';
    if (!$on) {
        @unlink($file);
        if (prefix() === '' && have('systemctl')) {
            run(['systemctl', 'disable', '--now', MESH_UNIT], 60);
        }
        return true;
    }
    $config = mesh_config();
    if ($config === null) {
        warn('There is no house key yet, and the radio layer takes its access code from it. Run: sudo offline key new');
        return false;
    }
    @mkdir(mesh_dir(), 0750, true);
    $changed = (string) @file_get_contents($file) !== $config;
    if (!write_atomic($file, $config, 0640)) {
        return false;
    }
    if (prefix() === '') {
        foreach ([mesh_dir(), $file] as $owned) {
            @chown($owned, SERVICE_USER);
            @chgrp($owned, SERVICE_USER);
        }
        if (have('systemctl')) {
            run(['systemctl', 'enable', MESH_UNIT], 60);
            run(['systemctl', $changed ? 'restart' : 'start', MESH_UNIT], 60);
        }
    }
    return true;
}

function command_net(array $args): int
{
    $action = $args[0] ?? 'status';
    $rest = \array_slice($args, 1);

    if ($action === 'status') {
        $status = net_status();
        heading('Private network');
        if (!$status['joined']) {
            out('  This system has not joined. sudo offline net join');
        } else {
            out('  Address   ' . $status['address'] . '   ' . ($status['up'] ? 'tunnel up' : (prefix() === '' ? 'tunnel down' : 'configured')));
            out('  Reached   ' . ($status['endpoint'] !== null ? 'by others at ' . $status['endpoint'] : 'by calling out to the systems that have an address; none is set for this one'));
        }
        if ($status['peers'] !== []) {
            out();
            table(['System', 'Role', 'Address', 'Reached at', 'Last heard'], array_map(static fn (array $p): array => [
                $p['name'], $p['role'], $p['address'], $p['endpoint'] ?? '-',
                $p['handshake'] === null ? '-' : ($p['handshake'] === 0 ? 'never' : human_duration(max(0, time() - $p['handshake'])) . ' ago'),
            ], $status['peers']));
        }
        heading('Radio layer (Reticulum)');
        $mesh = $status['mesh'];
        out('  ' . match (true) {
            !$mesh['installed'] => 'Not installed. sudo offline net radio on',
            !$mesh['enabled'] => 'Installed, switched off. sudo offline net radio on',
            default => 'Switched on' . (prefix() === '' ? ", {$mesh['state']}" : '') . '; ' . ($mesh['radios'] === [] ? 'no LoRa radio plugged in, so it runs over the home network and the tunnels' : plural(count($mesh['radios']), 'LoRa radio') . ' in use')
                . ($mesh['packet'] ? '; packet radio on' : ''),
        });
        out();
        out('sudo offline net join                       give this system its place on the private network');
        out('sudo offline net endpoint HOST:PORT|none    say where other systems can call this one from outside');
        out('sudo offline net forget NAME | leave        remove another system, or this one');
        out('sudo offline net radio on|off               carry family broadcasts over LoRa and the other links');
        return 0;
    }
    if (prefix() === '' && !is_root()) {
        fail("Run this with sudo: sudo offline net $action");
    }
    if ($action === 'join') {
        if (derived_key('probe') === null) {
            fail('There is no house key yet. Run: sudo offline key new, or sudo offline key set');
        }
        if (!\function_exists('sodium_crypto_box_keypair')) {
            fail('PHP\'s sodium extension is missing, so no key can be made. Install php-cli from Ubuntu\'s archive.');
        }
        // The folder is readable so that the status report can show this system's address; the private key in it is not.
        @mkdir(net_dir(), 0755, true);
        if (net_identity() === null) {
            $taken = array_column(net_nodes(), 'address');
            do {
                // The same kind of key "wg genkey" makes. A new one is drawn in the unlikely event that its address is taken.
                $pair = sodium_crypto_box_keypair();
                $public = base64_encode(sodium_crypto_box_publickey($pair));
            } while (in_array(net_address($public), $taken, true));
            $previous = umask(0077);
            $written = write_atomic(net_dir() . '/private.key', base64_encode(sodium_crypto_box_secretkey($pair)) . "\n", 0600);
            umask($previous);
            sodium_memzero($pair);
            if (!$written || !write_atomic(net_dir() . '/public.key', $public . "\n", 0644)) {
                fail('The key could not be written to ' . net_dir() . '.');
            }
        }
        $identity = net_identity();
        $existing = net_nodes()[$identity['public']] ?? null;
        net_publish($existing['endpoint'] ?? null);
        if (!net_apply()) {
            warn('This system is on the list of nodes, but its tunnel could not be started.');
        }
        if (mesh_enabled()) {
            mesh_apply(true);
        }
        out('This system is on the private network as ' . $identity['address'] . '.');
        out('The others learn of it at their next sync. A tunnel forms with every system that has an address others can call:');
        out('  sudo offline net endpoint HOST:' . NET_PORT . '     on a server in a datacentre, or a home system behind a forwarded UDP port');
        return 0;
    }
    $identity = net_identity();
    if ($action === 'radio') {
        $switch = $rest[0] ?? '';
        if (!in_array($switch, ['on', 'off'], true)) {
            fail('Usage: sudo offline net radio on|off');
        }
        if ($switch === 'on' && prefix() === '' && !mesh_setup()) {
            fail('Reticulum could not be installed. This needs the internet once, or a system set up while it was there.');
        }
        if (!mesh_apply($switch === 'on')) {
            return 1;
        }
        $radios = mesh_rnodes();
        out($switch === 'off' ? 'The radio layer is off.' : 'The radio layer is on: family broadcasts now also travel over '
            . ($radios === [] ? 'the home network and the tunnels. Plug in a LoRa radio running the RNode firmware and run this again to add it.' : plural(count($radios), 'LoRa radio') . ', the home network and the tunnels.'));
        return 0;
    }
    if ($identity === null) {
        fail('This system has not joined the private network. Run: sudo offline net join');
    }
    if ($action === 'endpoint') {
        $endpoint = (string) ($rest[0] ?? '');
        if ($endpoint !== 'none' && !net_endpoint_ok($endpoint)) {
            fail('Give the address others can call as HOST:PORT, for example files.example.org:' . NET_PORT . ', or "none".');
        }
        net_publish($endpoint === 'none' ? null : $endpoint);
        net_apply();
        if (mesh_enabled()) {
            mesh_apply(true);
        }
        out($endpoint === 'none' ? 'This system no longer says it can be called; it calls out to the others.' : "Other systems will call this one at $endpoint (UDP). If it is behind a router, forward that port to it.");
        return 0;
    }
    if ($action === 'leave') {
        net_publish(null, true);
        if (prefix() === '' && have('systemctl')) {
            run(['systemctl', 'disable', '--now', 'wg-quick@' . NET_INTERFACE . '.service'], 60);
        }
        net_firewall(false, null);
        @unlink(net_wireguard_conf());
        @unlink(net_dir() . '/private.key');
        @unlink(net_dir() . '/public.key');
        out('This system has left the private network. The others drop it at their next sync.');
        return 0;
    }
    if ($action === 'forget') {
        $name = (string) ($rest[0] ?? '');
        $nodes = net_nodes();
        $matches = array_keys(array_filter($nodes, static fn (array $n): bool => $n['name'] === $name && !$n['removed']));
        if (count($matches) !== 1 || $matches[0] === $identity['public']) {
            fail(count($matches) > 1 ? "More than one system is called $name. Rename one of them and try again." : "There is no other system called \"$name\" on the private network. To remove this one: sudo offline net leave");
        }
        $nodes[$matches[0]] = ['removed' => true, 'endpoint' => null, 'updated' => utc_now()] + $nodes[$matches[0]];
        net_nodes_save($nodes);
        net_apply();
        out("$name is no longer on the private network. If it was lost or stolen, change the house key as well: sudo offline key new --force");
        return 0;
    }
    if ($action === 'apply') {
        // Run after a sync that changed the list of nodes: the tunnel and the radio layer follow.
        $ok = net_apply();
        if (mesh_enabled()) {
            mesh_apply(true);
        }
        return $ok ? 0 : 1;
    }
    fail('Usage: offline net [status]   |   sudo offline net join | endpoint HOST:PORT|none | forget NAME | leave | radio on|off');
}

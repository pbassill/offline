<?php
/**
 * Offline — family broadcast.
 *
 * One message, shown on every screen in the household and sent to the
 * Meshtastic mesh. A broadcast is a small signed envelope. The machine it is
 * written on pushes it straight to every system it knows over the keyed TLS
 * channel; each system that accepts it shows it, passes it on once, and keeps
 * it for a while so that a machine that was asleep can collect it later.
 *
 * Nothing is believed because of where it arrived from. An envelope is shown
 * only if its signature, made with a key derived from the house key, checks.
 */

declare(strict_types=1);

namespace Offline;

const BROADCAST_MAX_TEXT = 500;
const BROADCAST_MAX_FILE = 4096;
/** An envelope older than this is refused, which bounds how long a captured one could be replayed. */
const BROADCAST_VALID_SECONDS = 86400;
/** Clocks drift when there is no network to set them by, so some lead is tolerated. */
const BROADCAST_FUTURE_SECONDS = 7200;
/** Envelopes are kept longer than they are valid, so a replay within the window is recognised as seen. */
const BROADCAST_KEEP_SECONDS = 172800;
/** A LoRa text packet carries a little over 200 bytes. */
const MESH_MAX_BYTES = 200;

function broadcast_id_valid(string $id): bool
{
    return preg_match('/^\d{8}T\d{6}Z-[0-9a-f]{8}$/', $id) === 1;
}

/** One line of plain text: control characters and runs of white space become single spaces. */
function broadcast_clean(string $text): string
{
    $text = mb_scrub($text, 'UTF-8');
    $text = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\s]+/u', ' ', $text) ?? '';
    return trim($text);
}

function broadcast_mac(array $envelope, string $key): string
{
    $fields = ['offline-broadcast', '1'];
    foreach (['id', 'from', 'by', 'sent', 'level', 'mesh', 'text'] as $field) {
        $fields[] = (string) ($envelope[$field] ?? '');
    }
    return hash_hmac('sha256', implode("\n", $fields), $key);
}

/** Check an envelope. Returns the cleaned envelope, or a string saying why it was refused. */
function broadcast_verify(array $envelope, ?string $key, ?int $now = null): array|string
{
    $now ??= time();
    if ($key === null) {
        return 'this system has no house key';
    }
    foreach (['id', 'from', 'by', 'sent', 'level', 'mesh', 'text', 'mac'] as $field) {
        if (!isset($envelope[$field]) || !\is_string($envelope[$field])) {
            return "the \"$field\" field is missing";
        }
    }
    if (($envelope['v'] ?? null) !== 1 || !broadcast_id_valid($envelope['id'])) {
        return 'the envelope is not in a known format';
    }
    if (preg_match('/^[0-9a-f]{64}$/', $envelope['mac']) !== 1 || !hash_equals(broadcast_mac($envelope, $key), $envelope['mac'])) {
        return 'the signature does not match the house key';
    }
    // Everything below is covered by the signature, so it was written by a key holder.
    $sent = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $envelope['sent'], new \DateTimeZone('UTC'));
    if ($sent === false || $sent->format('Ymd\THis\Z') !== substr($envelope['id'], 0, 16)) {
        return 'the time and the identifier disagree';
    }
    $age = $now - $sent->getTimestamp();
    if ($age > BROADCAST_VALID_SECONDS) {
        return 'it is more than a day old';
    }
    if ($age < -BROADCAST_FUTURE_SECONDS) {
        return 'it is dated in the future; check this system\'s clock';
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $envelope['from']) !== 1
        || preg_match('/^[a-z_][a-z0-9_.-]{0,31}$|^$/', $envelope['by']) !== 1
        || !in_array($envelope['level'], ['info', 'urgent'], true)
        || !in_array($envelope['mesh'], ['origin', 'none'], true)
        || $envelope['text'] === '' || $envelope['text'] !== broadcast_clean($envelope['text'])
        || mb_strlen($envelope['text'], 'UTF-8') > BROADCAST_MAX_TEXT) {
        return 'a field holds something that is not allowed';
    }
    return [
        'v' => 1, 'id' => $envelope['id'], 'from' => $envelope['from'], 'by' => $envelope['by'], 'sent' => $envelope['sent'],
        'level' => $envelope['level'], 'mesh' => $envelope['mesh'], 'text' => $envelope['text'], 'mac' => $envelope['mac'],
    ];
}

/** Read a small JSON file safely: a regular file, not a link, no larger than an envelope may be. */
function broadcast_read(string $file): ?array
{
    $before = @lstat($file);
    if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1 || $before['size'] > BROADCAST_MAX_FILE) {
        return null;
    }
    $handle = @fopen($file, 'r');
    if ($handle === false) {
        return null;
    }
    $after = fstat($handle);
    // The file opened must be the file examined, or it was swapped in between.
    if ($after === false || $after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev']) {
        fclose($handle);
        return null;
    }
    $data = json_decode((string) fread($handle, BROADCAST_MAX_FILE + 1), true);
    fclose($handle);
    return \is_array($data) ? $data + ['_uid' => $before['uid']] : null;
}

/** Envelopes this system holds, newest first. Each is verified again on every read. */
function broadcasts_held(?int $withinSeconds = null): array
{
    $key = derived_key('offline-broadcast-v1');
    $held = [];
    foreach (@scandir(path('broadcasts'), SCANDIR_SORT_DESCENDING) ?: [] as $name) {
        if (!str_ends_with($name, '.json') || !broadcast_id_valid(substr($name, 0, -5))) {
            continue;
        }
        $envelope = broadcast_read(path('broadcasts') . '/' . $name);
        $checked = $envelope === null ? 'unreadable' : broadcast_verify($envelope, $key);
        if (\is_array($checked) && $checked['id'] === substr($name, 0, -5)
            && ($withinSeconds === null || time() - strtotime($checked['sent']) <= $withinSeconds)) {
            $held[] = $checked;
        }
    }
    return $held;
}

/** The file the portal and the desktop alert read. It never contains the signature. */
function broadcast_publish_alerts(): void
{
    $current = array_map(static fn (array $e): array => [
        'id' => $e['id'], 'from' => $e['from'], 'by' => $e['by'], 'sent' => $e['sent'], 'level' => $e['level'], 'text' => $e['text'],
    ], broadcasts_held(config_int('BROADCAST_HOURS', 1, 24) * 3600));
    @mkdir(dirname(path('alerts')), 0755, true);
    write_json(path('alerts'), ['updated' => utc_now(), 'broadcasts' => \array_slice($current, 0, 20)]);
}

/** Every system this one knows how to reach: configured servers, then machines seen at a recent sync. */
function broadcast_targets(): array
{
    $targets = [];
    foreach (servers() as $server) {
        $targets[peer_key($server['host'], $server['port'])] = ['address' => $server['host'], 'port' => $server['port']];
    }
    if (prefix() !== '') {
        foreach (array_filter(explode(',', (string) getenv('OFFLINE_TEST_PEERS'))) as $spec) {
            [$ip, $port] = explode(':', $spec) + [1 => (string) TLS_PORT];
            $targets[peer_key($ip, (int) $port)] = ['address' => $ip, 'port' => (int) $port];
        }
        return $targets;
    }
    $own = array_column(own_addresses(), 'ip');
    foreach (read_json(path('peers'))['peers'] ?? [] as $key => $peer) {
        $address = (string) ($peer['address'] ?? explode(':', (string) $key)[0]);
        $seen = strtotime((string) ($peer['last_seen'] ?? '')) ?: 0;
        if (isset($targets[$key]) || ($peer['auth'] ?? '') !== 'ok' || time() - $seen > 7 * 86400
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || in_array($address, $own, true)
            || str_starts_with($address, '127.')) {
            continue;
        }
        $targets[$key] = ['address' => $address, 'port' => (int) ($peer['port'] ?? TLS_PORT)];
    }
    return $targets;
}

/** Push envelopes into one system's inbox. Returns '' on success or a sentence. */
function broadcast_push(array $peer, array $ids): string
{
    [$env, $conf] = tls_transport($peer, 4);
    $list = path('state') . '/push-' . getmypid() . '.list';
    file_put_contents($list, implode("\n", array_map(static fn (string $id): string => "$id.json", $ids)) . "\n");
    [$code, , $error] = run([
        'rsync', '--no-motd', '--timeout=20', '--max-size=' . BROADCAST_MAX_FILE, '--files-from=' . $list, '--no-relative',
        '--password-file=' . path('key'), path('broadcasts') . '/', rsync_url($peer, 'inbox'),
    ], 45, $env);
    @unlink($conf);
    @unlink($list);
    if ($code === 0) {
        return '';
    }
    return stripos($error, 'Unknown module') !== false ? 'it runs an older version without broadcasts' : classify_transport_error($error)[1];
}

/** Collect envelopes a system holds that this one has not seen. Returns the folder they were put in, or null. */
function broadcast_pull(array $peer): ?string
{
    $listing = remote_listing($peer, 'broadcasts');
    if (!$listing['ok']) {
        return null;
    }
    $wanted = [];
    foreach ($listing['files'] as $file) {
        $id = substr($file['path'], 0, -5);
        if (str_ends_with($file['path'], '.json') && broadcast_id_valid($id) && $file['size'] <= BROADCAST_MAX_FILE
            && !is_file(path('broadcasts') . "/$id.json")) {
            $wanted[] = $file['path'];
        }
    }
    if ($wanted === []) {
        return null;
    }
    $dir = path('state') . '/pull-' . getmypid() . '-' . bin2hex(random_bytes(3));
    if (!@mkdir($dir, 0700)) {
        return null;
    }
    [$env, $conf] = tls_transport($peer, 4);
    $list = "$dir.list";
    file_put_contents($list, implode("\n", \array_slice($wanted, 0, 100)) . "\n");
    run([
        'rsync', '--no-motd', '--timeout=20', '--max-size=' . BROADCAST_MAX_FILE, '--files-from=' . $list, '--no-relative',
        '--no-perms', '--no-owner', '--no-group', '--no-links', '--no-devices', '--no-specials', '--chmod=F0640',
        '--password-file=' . path('key'), rsync_url($peer, 'broadcasts'), $dir . '/',
    ], 45, $env);
    @unlink($conf);
    @unlink($list);
    return $dir;
}

/** The serial port of a Meshtastic node plugged into this machine, or null. */
function mesh_node_present(): bool
{
    foreach (radio_devices() as $device) {
        if ($device['kind'] === 'serial') {
            return true;
        }
    }
    return false;
}

/** The text sent over the mesh: marked, attributed and cut to what one packet carries. */
function broadcast_mesh_text(array $envelope): string
{
    $lead = $envelope['level'] === 'urgent' ? 'URGENT ' : '';
    $text = $lead . 'Family broadcast from ' . ($envelope['by'] !== '' ? $envelope['by'] . ' at ' : '') . $envelope['from'] . ': ' . $envelope['text'];
    return strlen($text) <= MESH_MAX_BYTES ? $text : mb_strcut($text, 0, MESH_MAX_BYTES - 3, 'UTF-8') . '...';
}

function broadcast_to_mesh(array $envelope): string
{
    if (!config_bool('MESH_GATEWAY') || !mesh_node_present()) {
        return 'none';
    }
    if (!have('meshtastic')) {
        return 'failed: the Meshtastic command is not installed';
    }
    [$code, , $error] = run(['meshtastic', '--sendtext', broadcast_mesh_text($envelope)], 60);
    return $code === 0 ? 'sent' : 'failed: ' . printable(trim($error) !== '' ? trim($error) : "exit code $code", 120);
}

/**
 * Take in what is waiting: requests from people on this machine, envelopes
 * pushed here by other systems, and envelopes collected from them.
 * Returns [envelopes not seen before, keyed by id; number of items refused].
 */
function broadcast_intake(?string $key, array $collected): array
{
    $fresh = [];
    $refused = 0;
    $host = preg_replace('/[^A-Za-z0-9._-]/', '', (string) gethostname()) ?: 'offline';
    $host = ltrim(substr($host, 0, 63), '.-_') ?: 'offline';

    foreach (\array_slice(@scandir(path('outbox')) ?: [], 0, 60) as $name) {
        $file = path('outbox') . '/' . $name;
        if ($name === '.' || $name === '..') {
            continue;
        }
        if ($name[0] === '.') {
            // A request still being written. One abandoned for five minutes is cleared away.
            if (time() - (int) @filemtime($file) > 300) {
                @unlink($file);
            }
            continue;
        }
        $request = is_dir($file) ? null : broadcast_read($file);
        @unlink($file);
        $text = broadcast_clean((string) ($request['text'] ?? ''));
        if ($request === null || $key === null || $text === '' || mb_strlen($text, 'UTF-8') > BROADCAST_MAX_TEXT) {
            $refused++;
            continue;
        }
        $account = \function_exists('posix_getpwuid') ? posix_getpwuid($request['_uid']) : false;
        $by = $account !== false && preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/', $account['name']) === 1 && $account['name'] !== SERVICE_USER ? $account['name'] : '';
        $now = time();
        $envelope = [
            'v' => 1, 'id' => gmdate('Ymd\THis\Z', $now) . '-' . bin2hex(random_bytes(4)), 'from' => $host, 'by' => $by,
            'sent' => gmdate('Y-m-d\TH:i:s\Z', $now), 'level' => ($request['level'] ?? '') === 'urgent' ? 'urgent' : 'info',
            'mesh' => config_bool('MESH_GATEWAY') && mesh_node_present() ? 'origin' : 'none', 'text' => $text,
        ];
        $envelope['mac'] = broadcast_mac($envelope, $key);
        write_atomic(path('broadcasts') . "/{$envelope['id']}.json", json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0640);
        $fresh[$envelope['id']] = $envelope + ['_origin' => true];
    }

    foreach (array_merge([path('inbox')], $collected) as $source) {
        foreach (\array_slice(@scandir($source) ?: [], 0, 200) as $name) {
            $file = "$source/$name";
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name[0] === '.') {
                // rsync's own temporary file while a transfer is under way.
                if (time() - (int) @filemtime($file) > 300) {
                    @unlink($file);
                }
                continue;
            }
            $envelope = is_dir($file) ? null : broadcast_read($file);
            // Whatever it is, it does not stay: a folder left here would be counted again on every run.
            if (!@unlink($file) && is_dir($file) && !is_link($file)) {
                @rmdir($file);
            }
            $checked = $envelope === null ? 'unreadable' : broadcast_verify($envelope, $key);
            if (!\is_array($checked) || "{$checked['id']}.json" !== $name) {
                $refused++;
                continue;
            }
            $target = path('broadcasts') . "/{$checked['id']}.json";
            if (!is_file($target)) {
                write_atomic($target, json_encode($checked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0640);
                $fresh[$checked['id']] = $checked;
            }
        }
        if ($source !== path('inbox')) {
            @rmdir($source);
        }
    }

    // Forget what is too old to matter.
    foreach (@scandir(path('broadcasts')) ?: [] as $name) {
        $stamp = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', substr($name, 0, 16), new \DateTimeZone('UTC'));
        if (str_ends_with($name, '.json') && $stamp !== false && time() - $stamp->getTimestamp() > BROADCAST_KEEP_SECONDS) {
            @unlink(path('broadcasts') . '/' . $name);
        }
    }
    return [$fresh, $refused];
}

/** Pass new envelopes on: to every other system, to terminals, and to the mesh, which is the slowest. */
function broadcast_deliver(array $fresh, bool $haveKey): void
{
    $delivered = [];
    if ($haveKey && have('rsync')) {
        foreach (broadcast_targets() as $name => $peer) {
            $error = broadcast_push($peer, array_keys($fresh));
            $delivered[$name] = $error === '' ? 'delivered' : $error;
        }
    }
    $entries = [];
    foreach ($fresh as $id => $envelope) {
        $origin = isset($envelope['_origin']);
        if (prefix() === '' && have('wall')) {
            run(['wall', '-n', ($envelope['level'] === 'urgent' ? 'URGENT ' : '') . "Family broadcast from {$envelope['from']}: {$envelope['text']}"], 10);
        }
        // The machine a broadcast was written on sends it to the mesh if it has a node.
        // Where it has none, every gateway that receives the broadcast does, so it still gets out.
        $mesh = $origin || $envelope['mesh'] === 'none' ? broadcast_to_mesh($envelope) : 'left to the sender';
        $entries[] = [
            'id' => $id, 'from' => $envelope['from'], 'by' => $envelope['by'], 'sent' => $envelope['sent'], 'level' => $envelope['level'],
            'text' => $envelope['text'], 'origin' => $origin, 'systems' => $delivered, 'mesh' => $mesh,
        ];
        $reached = count(array_filter($delivered, static fn (string $d): bool => $d === 'delivered'));
        out("$id: " . ($origin ? 'sent' : 'received') . ", passed to $reached of " . plural(count($delivered), 'other system') . "; mesh: $mesh");
    }
    $lock = lock('broadcast', true);
    $log = read_json(path('state') . '/broadcast-log.json')['entries'] ?? [];
    write_json(path('state') . '/broadcast-log.json', ['entries' => \array_slice(array_merge(array_reverse($entries), $log), 0, 50)]);
    if (\is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * The broadcast service's one job. It runs whenever something lands in the
 * outbox or the inbox and, with $poll, once a minute to collect what a system
 * that was asleep or away has missed.
 */
function broadcast_process(bool $poll): int
{
    $key = derived_key('offline-broadcast-v1');
    foreach (['broadcasts', 'inbox', 'outbox'] as $dir) {
        @mkdir(path($dir), 0750, true);
    }
    // Collecting happens before the lock is taken: a system that cannot be
    // reached must not hold up a message that is waiting to go out.
    $collected = [];
    if ($poll && $key !== null && have('rsync')) {
        foreach (broadcast_targets() as $peer) {
            $dir = broadcast_pull($peer);
            if ($dir !== null) {
                $collected[] = $dir;
            }
        }
    }
    $refused = 0;
    // Deliveries take time, and more may arrive meanwhile, so look again until nothing new is found.
    for ($round = 0; $round < 6; $round++) {
        $lock = lock('broadcast', true);
        [$fresh, $rejected] = broadcast_intake($key, $collected);
        $collected = [];
        $refused += $rejected;
        // Screens first.
        broadcast_publish_alerts();
        if (\is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($fresh === []) {
            break;
        }
        broadcast_deliver($fresh, $key !== null);
    }
    if ($refused > 0) {
        warn(plural($refused, 'item') . ' refused: not a broadcast signed with this house key, or malformed.');
    }
    return 0;
}

/** Called by anyone in the "offline" group: leave a request for the broadcast service. */
function broadcast_request(string $text, bool $urgent): int
{
    $text = broadcast_clean($text);
    if ($text === '') {
        fail('Usage: offline broadcast "Dinner is ready" [--urgent]');
    }
    if (mb_strlen($text, 'UTF-8') > BROADCAST_MAX_TEXT) {
        fail('A broadcast may be up to ' . BROADCAST_MAX_TEXT . ' characters; this one is ' . mb_strlen($text, 'UTF-8') . '.');
    }
    if (!is_readable(path('key')) && prefix() === '' && !is_root()) {
        fail('Only members of the "offline" group may broadcast. Ask for: sudo usermod -aG offline ' . (getenv('USER') ?: 'NAME') . ' (then log out and in).');
    }
    $outbox = path('outbox');
    $name = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6)) . '.json';
    $previous = umask(0027);
    $written = @file_put_contents("$outbox/.$name", json_encode(['text' => $text, 'level' => $urgent ? 'urgent' : 'info']) . "\n", LOCK_EX);
    umask($previous);
    if ($written === false || !@chmod("$outbox/.$name", 0640) || !@rename("$outbox/.$name", "$outbox/$name")) {
        @unlink("$outbox/.$name");
        fail("Could not write to $outbox. Is the broadcast service installed, and are you in the \"offline\" group?");
    }
    if (prefix() !== '') {
        return broadcast_process(false);
    }
    // The service picks requests up within a second; wait briefly so the sender knows it went.
    for ($i = 0; $i < 50 && file_exists("$outbox/$name"); $i++) {
        usleep(200000);
        clearstatcache(true, "$outbox/$name");
    }
    if (file_exists("$outbox/$name")) {
        warn('The broadcast is queued but has not been picked up. Check: systemctl status offline-broadcast.path');
        return 1;
    }
    out('Broadcast sent. It is on this screen now and reaches the other systems within seconds.');
    if (mb_strlen($text, 'UTF-8') > 150) {
        out('Note: the mesh carries only the first ' . MESH_MAX_BYTES . ' bytes of a message, including who it is from.');
    }
    return 0;
}

/** Shown by the desktop alert: broadcasts this user has not yet been shown. */
function broadcast_alert_user(): int
{
    $home = (string) (getenv('HOME') ?: '');
    $current = read_json(path('alerts'))['broadcasts'] ?? [];
    if ($home === '' || !is_dir($home) || $current === []) {
        return 0;
    }
    $dir = (getenv('XDG_STATE_HOME') ?: "$home/.local/state") . '/offline';
    @mkdir($dir, 0700, true);
    $seenFile = "$dir/broadcasts-seen";
    $seen = array_flip(@file($seenFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $shown = 0;
    foreach (array_reverse($current) as $entry) {
        $id = (string) ($entry['id'] ?? '');
        if (!broadcast_id_valid($id) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $urgent = ($entry['level'] ?? '') === 'urgent';
        $who = (($entry['by'] ?? '') !== '' ? $entry['by'] . ' at ' : '') . ($entry['from'] ?? '');
        $title = ($urgent ? 'Urgent family broadcast' : 'Family broadcast') . ' from ' . printable($who, 80);
        $body = printable((string) ($entry['text'] ?? ''), BROADCAST_MAX_TEXT + 20) . "\n\nSent " . utc_display($entry['sent'] ?? null);
        // Each program is started detached; none is given anything to interpret as markup or options.
        if (have('notify-send')) {
            run(['notify-send', '--urgency=critical', '--app-name=Offline', '--icon=' . ($urgent ? 'dialog-warning' : 'dialog-information'),
                '--', $title, htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')], 10, broadcast_desktop_env());
        }
        if (have('canberra-gtk-play')) {
            run(['canberra-gtk-play', '--id=' . ($urgent ? 'dialog-warning' : 'message-new-instant')], 10, broadcast_desktop_env());
        }
        if (have('zenity')) {
            $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
            // Left running on purpose: the dialog stays until somebody acknowledges it.
            @proc_open(['setsid', '--fork', 'zenity', $urgent ? '--warning' : '--info', '--no-markup', '--width=520', '--title=' . $title, '--text=' . $body, '--ok-label=Seen'],
                $spec, $pipes, null, broadcast_desktop_env() + child_env());
        }
        $shown++;
    }
    @file_put_contents($seenFile, implode("\n", \array_slice(array_keys($seen), -200)) . "\n", LOCK_EX);
    return 0;
}

/** The handful of session variables a desktop program needs to find the screen. */
function broadcast_desktop_env(): array
{
    $env = [];
    foreach (['DISPLAY', 'WAYLAND_DISPLAY', 'XDG_RUNTIME_DIR', 'DBUS_SESSION_BUS_ADDRESS', 'XAUTHORITY', 'XDG_CURRENT_DESKTOP', 'HOME'] as $name) {
        $value = getenv($name);
        if (\is_string($value) && $value !== '' && preg_match('/[\x00-\x1f]/', $value) !== 1) {
            $env[$name] = $value;
        }
    }
    return $env;
}

function command_broadcast(array $args): int
{
    $action = $args[0] ?? 'list';

    if ($action === 'process') {
        become_service_user('broadcast process');
        return broadcast_process(in_array('--poll', $args, true));
    }
    if ($action === 'alert') {
        return broadcast_alert_user();
    }
    if ($action === 'list') {
        $json = in_array('--json', $args, true);
        $current = read_json(path('alerts'))['broadcasts'] ?? [];
        if ($json) {
            out((string) json_encode(['broadcasts' => $current], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 0;
        }
        if ($current === []) {
            out('No family broadcast in the last ' . plural(config_int('BROADCAST_HOURS', 1, 24), 'hour') . '.');
        } else {
            table(['Sent', 'From', 'Level', 'Message'], array_map(static fn (array $e): array => [
                utc_display($e['sent'] ?? null), (($e['by'] ?? '') !== '' ? $e['by'] . '@' : '') . ($e['from'] ?? ''), (string) ($e['level'] ?? ''), printable((string) ($e['text'] ?? ''), 90),
            ], $current));
        }
        $log = read_json(path('state') . '/broadcast-log.json')['entries'] ?? [];
        $own = array_values(array_filter($log, static fn (array $l): bool => ($l['origin'] ?? false) === true));
        if ($own !== []) {
            heading('Delivery of the last broadcast sent from here');
            foreach ($own[0]['systems'] ?? [] as $system => $result) {
                out('  ' . state_mark($result === 'delivered' ? 'ok' : 'fail') . " $system: $result");
            }
            out('  mesh: ' . ($own[0]['mesh'] ?? 'none'));
        }
        out();
        out('Send one with: offline broadcast "Dinner is ready"   (add --urgent for an alarm)');
        return 0;
    }

    $urgent = in_array('--urgent', $args, true);
    $words = [];
    $literal = false;
    foreach ($action === 'send' ? \array_slice($args, 1) : $args as $arg) {
        if (!$literal && $arg === '--') {
            $literal = true;
        } elseif ($literal || !in_array($arg, ['--urgent'], true)) {
            $words[] = $arg;
        }
    }
    return broadcast_request(implode(' ', $words), $urgent);
}

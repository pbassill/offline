<?php
/**
 * Offline — radio.
 *
 * Three kinds of radio meet on these systems: a USB software-defined receiver
 * (SDR) for listening, Meshtastic LoRa nodes for text over the household
 * mesh, and PMR446 handsets for voice. This file reports what is plugged in,
 * drives the receiver for the common cases, wraps the Meshtastic command, and
 * keeps firmware on disc so that a node can be reflashed with no internet.
 */

declare(strict_types=1);

namespace Offline;

const RADIO_TOOLS = [
    'rtl_fm' => 'rtl-sdr: tune and demodulate from the command line',
    'gqrx' => 'Gqrx: receiver with a waterfall display',
    'CubicSDR' => 'CubicSDR: an alternative receiver',
    'welle-io' => 'welle.io: DAB and DAB+ broadcast radio',
    'rtl_433' => 'rtl_433: decode weather stations and other 433/868 MHz sensors',
    'dump1090-mutability' => 'dump1090: aircraft positions (ADS-B, 1090 MHz)',
    'multimon-ng' => 'multimon-ng: decode POCSAG, DTMF, AFSK and other data modes',
    'direwolf' => 'Dire Wolf: APRS and AX.25 packet modem',
    'fldigi' => 'Fldigi: keyboard digital modes',
    'gpredict' => 'Gpredict: satellite pass prediction',
    'chirpw|chirp' => 'CHIRP: program handheld radios from a channel list',
    'meshtastic' => 'Meshtastic command: configure nodes and send messages',
    'esptool' => 'esptool: flash ESP32-based Meshtastic nodes',
];

function radio_status(): array
{
    $tools = [];
    foreach (RADIO_TOOLS as $binary => $purpose) {
        // Some tools go by more than one command name, depending on the release.
        $tools[$binary] = array_filter(explode('|', $binary), __NAMESPACE__ . '\\have') !== [];
    }
    $firmware = [];
    foreach (@scandir(path('radio') . '/firmware') ?: [] as $file) {
        if (preg_match('/^firmware-[a-z0-9]+-[0-9A-Za-z.]+\.zip$/', $file) === 1) {
            $firmware[] = $file;
        }
    }
    return ['devices' => radio_devices(), 'tools' => $tools, 'firmware' => $firmware];
}

/** Start rtl_fm and play what it demodulates through the sound card. */
function radio_listen(array $frequencies, string $mode, int $squelch, ?int $gain): int
{
    foreach (['rtl_fm', 'aplay'] as $binary) {
        if (!have($binary)) {
            fail("$binary is not installed. Run the installer, or: sudo apt install rtl-sdr alsa-utils");
        }
    }
    [$modulation, $rate] = ['nfm' => ['fm', 12000], 'am' => ['am', 12000], 'wfm' => ['wbfm', 32000]][$mode];
    // Broadcast FM uses rtl_fm's own wide-band defaults (32 kHz audio, no squelch).
    $receive = $modulation === 'wbfm'
        ? ['rtl_fm', '-M', 'wbfm']
        : ['rtl_fm', '-M', $modulation, '-s', '12k', '-l', (string) $squelch];
    if ($gain !== null) {
        array_push($receive, '-g', (string) $gain);
    }
    foreach ($frequencies as $hertz) {
        array_push($receive, '-f', (string) $hertz);
    }
    $play = ['aplay', '-q', '-t', 'raw', '-f', 'S16_LE', '-c', '1', '-r', (string) $rate];
    out('Listening on ' . implode(', ', array_map(__NAMESPACE__ . '\\format_mhz', $frequencies)) . ' MHz. Press Ctrl+C to stop.');
    if (count($frequencies) > 1) {
        out('Scanning: the receiver moves on whenever a channel is quiet, and stays while it hears a signal.');
    }
    $receiver = proc_open($receive, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, child_env());
    if (!is_resource($receiver)) {
        fail('Could not start rtl_fm.');
    }
    $player = proc_open($play, [0 => $pipes[1], 1 => STDOUT, 2 => STDERR], $unused, null, child_env() + ['XDG_RUNTIME_DIR' => (string) getenv('XDG_RUNTIME_DIR')]);
    fclose($pipes[1]);
    $code = is_resource($player) ? proc_close($player) : 1;
    proc_terminate($receiver);
    proc_close($receiver);
    return $code;
}

/** Download the current Meshtastic firmware bundles, so nodes can be reflashed offline. */
function radio_firmware(array $architectures): int
{
    $json = https_get('https://api.github.com/repos/meshtastic/firmware/releases/latest', 60);
    $release = $json === null ? null : json_decode($json, true);
    if (!is_array($release) || !is_array($release['assets'] ?? null)) {
        fail('The Meshtastic release list could not be read. This command needs an internet connection.');
    }
    $dir = path('radio') . '/firmware';
    @mkdir($dir, 02775, true);
    $tag = printable((string) ($release['tag_name'] ?? ''), 40);
    out("Latest Meshtastic firmware release: $tag");
    $done = 0;
    foreach ($release['assets'] as $asset) {
        $name = (string) ($asset['name'] ?? '');
        $url = (string) ($asset['browser_download_url'] ?? '');
        if (preg_match('/^firmware-([a-z0-9]+)-[0-9A-Za-z.]+\.zip$/', $name, $m) !== 1 || !in_array($m[1], $architectures, true)
            || !str_starts_with($url, 'https://github.com/meshtastic/firmware/releases/download/')) {
            continue;
        }
        if (is_file("$dir/$name")) {
            out("  $name is already here.");
            $done++;
            continue;
        }
        out("  fetching $name (" . human_bytes((int) ($asset['size'] ?? 0)) . ') ...');
        $tmp = "$dir/.incoming-$name";
        $ok = run_live(['curl', '--fail', '--location', '--proto', '=https', '--proto-redir', '=https', '--tlsv1.2', '--retry', '3', '--silent', '--show-error', '--output', $tmp, '--', $url]) === 0;
        // GitHub publishes a SHA-256 digest for each release file; hold the download to it.
        $digest = (string) ($asset['digest'] ?? '');
        if ($ok && preg_match('/^sha256:([0-9a-f]{64})$/', $digest, $d) === 1 && !hash_equals($d[1], (string) hash_file('sha256', $tmp))) {
            $ok = false;
            warn("$name does not match the digest GitHub publishes for it; discarded.");
        }
        if ($ok && @rename($tmp, "$dir/$name")) {
            @chmod("$dir/$name", 0664);
            $done++;
        } else {
            @unlink($tmp);
        }
    }
    out($done > 0 ? "Firmware is in $dir. Unzip the bundle for your board and follow the flashing guide at http://localhost/radio.php" : 'No firmware bundle matched.');
    return $done > 0 ? 0 : 1;
}

function command_radio(array $args): int
{
    $action = $args[0] ?? 'status';
    $rest = array_slice($args, 1);

    if ($action === 'status') {
        $status = radio_status();
        heading('Radio hardware');
        if ($status['devices'] === []) {
            out('  Nothing recognised is plugged in.');
        } else {
            table(['Kind', 'Device', 'Reported as'], array_map(static fn (array $d): array => [$d['kind'] === 'sdr' ? 'receiver' : 'serial', $d['name'], $d['product']], $status['devices']));
        }
        heading('Tools');
        foreach (RADIO_TOOLS as $binary => $purpose) {
            out('  ' . state_mark($status['tools'][$binary] ? 'ok' : 'warn') . ' ' . $purpose);
        }
        heading('Access');
        $groups = \function_exists('posix_getgroups') ? array_map(static fn (int $g): string => (string) (posix_getgrgid($g)['name'] ?? ''), posix_getgroups()) : [];
        foreach (['dialout' => 'serial ports (Meshtastic nodes, handset programming cables)', 'plugdev' => 'USB receivers'] as $group => $what) {
            out('  ' . state_mark(is_root() || in_array($group, $groups, true) ? 'ok' : 'warn') . " $group: $what" . (is_root() || in_array($group, $groups, true) ? '' : '   (log out and in again after installation)'));
        }
        heading('Meshtastic firmware on disc');
        out('  ' . ($status['firmware'] === [] ? 'None. Fetch it with: sudo offline radio firmware' : printable(implode(', ', $status['firmware']), 300)));
        out();
        out('Reference tables and channel files: http://localhost/radio.php');
        return 0;
    }

    if ($action === 'channels') {
        table(['PMR446', 'MHz', '', 'PMR446', 'MHz'], array_map(static fn (int $c): array => [
            "channel $c", format_mhz(pmr446_channels()[$c]), '', 'channel ' . ($c + 8), format_mhz(pmr446_channels()[$c + 8]),
        ], range(1, 8)));
        out();
        out('Narrow FM, 12.5 kHz spacing, 0.5 W. Channels 1 to 8 are on every handset; 9 to 16 only on newer ones.');
        return 0;
    }

    if ($action === 'listen') {
        if (is_root()) {
            warn('Running as root: sound may not reach your speakers. Run this command without sudo.');
        }
        $squelch = 60;
        $gain = null;
        $words = [];
        for ($i = 0; $i < count($rest); $i++) {
            if ($rest[$i] === '--squelch' && isset($rest[$i + 1])) {
                $squelch = max(0, min(1000, (int) $rest[++$i]));
            } elseif ($rest[$i] === '--gain' && isset($rest[$i + 1])) {
                $gain = max(0, min(50, (int) $rest[++$i]));
            } elseif ($rest[$i][0] !== '-') {
                $words[] = $rest[$i];
            }
        }
        $what = $words[0] ?? '';
        if ($what === 'pmr') {
            $channels = pmr446_channels();
            $channel = $words[1] ?? null;
            if ($channel !== null && (!ctype_digit($channel) || !isset($channels[(int) $channel]))) {
                fail('PMR446 channels run from 1 to 16.');
            }
            return radio_listen($channel === null ? array_values($channels) : [$channels[(int) $channel]], 'nfm', $squelch, $gain);
        }
        if (in_array($what, ['nfm', 'am', 'wfm'], true) && preg_match('/^\d{1,4}(\.\d{1,6})?$/', $words[1] ?? '') === 1) {
            $hertz = (int) round((float) $words[1] * 1000000);
            if ($hertz < 500000 || $hertz > 1766000000) {
                fail('That is outside what an RTL-SDR can tune (about 0.5 to 1766 MHz).');
            }
            return radio_listen([$hertz], $what, $what === 'wfm' ? 0 : $squelch, $gain);
        }
        fail("Usage: offline radio listen pmr [CHANNEL]   scan all PMR446 channels, or stay on one\n"
            . "       offline radio listen wfm MHZ         FM broadcast, e.g. wfm 93.5\n"
            . "       offline radio listen nfm MHZ         narrow FM voice\n"
            . "       offline radio listen am MHZ          AM voice\n"
            . '       options: --squelch 0-1000 (default 60)   --gain 0-50 (default automatic)');
    }

    if ($action === 'mesh') {
        if (!have('meshtastic')) {
            fail('The Meshtastic command is not installed. Run the installer while online.');
        }
        $sub = $rest[0] ?? '';
        $command = match ($sub) {
            'info' => ['meshtastic', '--info'],
            'nodes' => ['meshtastic', '--nodes'],
            'send' => isset($rest[1]) ? ['meshtastic', '--sendtext', implode(' ', array_slice($rest, 1))] : null,
            'region' => preg_match('/^[A-Z][A-Z0-9_]{1,12}$/', $rest[1] ?? '') === 1 ? ['meshtastic', '--set', 'lora.region', $rest[1]] : null,
            default => null,
        };
        if ($command === null) {
            fail("Usage: offline radio mesh info            what the connected node reports\n"
                . "       offline radio mesh nodes           every node this one has heard\n"
                . "       offline radio mesh send TEXT       send a message on the primary channel\n"
                . '       offline radio mesh region EU_868   set the region (EU_868 for the UK and the EU)');
        }
        return run_live($command);
    }

    if ($action === 'firmware') {
        become_service_user('radio firmware');
        $wanted = array_values(array_filter($rest, static fn (string $a): bool => preg_match('/^[a-z0-9]{3,12}$/', $a) === 1));
        return radio_firmware($wanted !== [] ? $wanted : ['esp32', 'esp32s3', 'nrf52840', 'rp2040']);
    }

    fail('Usage: offline radio [status] | channels | listen ... | mesh ... | firmware [ARCH ...]');
}

<?php
/**
 * Offline — games.
 *
 * Three things for the children. Desktop games from Ubuntu's own archive on the
 * workstations. Family worlds: Luanti servers running the Mineclonia game, each
 * in a confined container on whichever system hosts them, joined from every
 * workstation in the house and never from outside it. And a set of browser
 * activities (Sugarizer) for tablets, which cannot run the others.
 *
 * Nothing here is fetched unpinned: the server image by digest, the game and
 * the activities by git commit, which git verifies object by object.
 */

declare(strict_types=1);

namespace Offline;

const MINECLONIA_REPO = 'https://codeberg.org/mineclonia/mineclonia';
const MINECLONIA_VERSION = '0.124.1';
const MINECLONIA_COMMIT = '7410cf18a231b4dd094bd90dc534951ef17b4275';
/** The oldest Luanti the game supports: min_minetest_version in its game.conf. */
const MINECLONIA_MIN_LUANTI = '5.10';
const SUGARIZER_REPO = 'https://github.com/llaske/sugarizer';
const SUGARIZER_VERSION = '2.0.0';
const SUGARIZER_COMMIT = '60ccdbcb004de1a706749c7314edfa0d160288b4';
const SUGARIZER_PORT = 8600;
const GAME_WORLDS = [
    'creative' => ['port' => 30000, 'title' => 'Creative building', 'about' => 'Every block is free and nobody can be hurt.'],
    'survival' => ['port' => 30001, 'title' => 'Gentle survival', 'about' => 'Gather, craft and explore. No monsters, and belongings are kept.'],
];

function games_share(string $what): string
{
    return prefix() . '/usr/local/share/offline/' . $what;
}

/** Games for the desktop, from the shipped list and any local additions. */
function desktop_games(): array
{
    $games = [];
    foreach ([prefix() . '/usr/local/lib/offline/games/games.tsv', prefix() . '/etc/offline/games.local.tsv'] as $file) {
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $cols = array_map('trim', explode("\t", $line));
            if ($line[0] === '#' || count($cols) < 6 || preg_match('/^[a-z][a-z0-9-]{1,30}$/', $cols[0]) !== 1
                || preg_match('/^[a-z0-9][a-z0-9.+-]*(,[a-z0-9][a-z0-9.+-]*)*$/', $cols[1]) !== 1
                || preg_match('/^[a-z0-9][a-z0-9._-]*(,[a-z0-9][a-z0-9._-]*)*$/', $cols[2]) !== 1) {
                continue;
            }
            $installed = false;
            foreach (explode(',', $cols[2]) as $program) {
                $installed = $installed || is_executable(prefix() . "/usr/games/$program") || is_executable(prefix() . "/usr/bin/$program");
            }
            $games[$cols[0]] = [
                'id' => $cols[0], 'packages' => explode(',', $cols[1]), 'title' => $cols[3], 'for' => $cols[4], 'about' => $cols[5], 'installed' => $installed,
            ];
        }
    }
    return $games;
}

/**
 * The version of the Luanti installed here, or null when there is none or it
 * will not say. Ubuntu 24.04 calls the program minetest; later releases, luanti.
 */
function luanti_client_version(): ?string
{
    foreach (['/usr/games/luanti', '/usr/bin/luanti', '/usr/games/minetest', '/usr/bin/minetest'] as $program) {
        if (is_executable(prefix() . $program)) {
            [, $output] = run([prefix() . $program, '--version'], 10);
            return preg_match('/^(?:Luanti|Minetest) (\d{1,2}\.\d{1,3}(?:\.\d{1,3})?)\b/m', $output, $m) === 1 ? $m[1] : null;
        }
    }
    return null;
}

/** A sentence for when the Luanti installed here is older than Mineclonia supports, or null. */
function luanti_client_note(): ?string
{
    $version = luanti_client_version();
    // The game says what it needs; the constant stands in until the game has been fetched.
    $needs = preg_match('/^min_minetest_version\s*=\s*(\d{1,2}\.\d{1,3}(?:\.\d{1,3})?)\s*$/m', (string) @file_get_contents(games_share('luanti/games/mineclonia/game.conf')), $m) === 1
        ? $m[1] : MINECLONIA_MIN_LUANTI;
    if ($version === null || version_compare($version, $needs, '>=')) {
        return null;
    }
    return "Luanti $version is installed here, and Mineclonia " . MINECLONIA_VERSION . " is written for $needs"
        . ' or newer, so parts of the family worlds may not work on this system. Ubuntu 26.04 carries a new enough Luanti.';
}

function game_unit_target(string $world): string
{
    return prefix() . "/etc/containers/systemd/offline-game-$world.container";
}

/** What the portal and "offline status" show. Reads files only. */
function games_status(): array
{
    $worlds = [];
    $hosted = array_filter(array_keys(GAME_WORLDS), static fn (string $w): bool => is_file(game_unit_target($w)));
    $states = [];
    if ($hosted !== [] && prefix() === '' && have('systemctl')) {
        [, $output] = run(array_merge(['systemctl', 'is-active'], array_map(static fn (string $w): string => "offline-game-$w.service", array_values($hosted))), 8);
        $states = array_combine(array_values($hosted), array_pad(preg_split('/\R/', trim($output)) ?: [], count($hosted), 'unknown')) ?: [];
    }
    foreach (GAME_WORLDS as $id => $world) {
        $state = (string) ($states[$id] ?? 'unknown');
        $worlds[] = $world + ['id' => $id, 'hosted' => in_array($id, $hosted, true), 'state' => preg_match('/^[a-z-]{2,20}$/', $state) === 1 ? $state : 'unknown'];
    }
    return [
        'desktop' => array_values(array_map(static fn (array $g): array => array_diff_key($g, ['packages' => true]), desktop_games())),
        'worlds' => $worlds,
        'mineclonia' => is_file(games_share('luanti/games/mineclonia/game.conf')) ? MINECLONIA_VERSION : null,
        'activities' => is_file(games_share('sugarizer/index.html')) ? ['version' => SUGARIZER_VERSION, 'port' => SUGARIZER_PORT] : null,
    ];
}

/**
 * Fetch one commit of a repository into a folder. The fetch succeeds only for
 * exactly that commit, and git checks every object against it, so what lands
 * on disc is what was reviewed when the commit was pinned.
 */
function fetch_pinned(string $repo, string $commit, string $target, string $label): bool
{
    if (!have('git')) {
        warn("git is not installed, so $label cannot be fetched.");
        return false;
    }
    @mkdir(dirname($target), 0755, true);
    $work = dirname($target) . '/.fetch-' . basename($target) . '.' . getmypid();
    $git = static fn (array $args): array => run(array_merge(['git', '-c', 'advice.detachedHead=false', '-C', $work], $args), 1800);
    @mkdir($work, 0755, true);
    $ok = $git(['init', '--quiet'])[0] === 0
        && $git(['fetch', '--quiet', '--depth', '1', $repo, $commit])[0] === 0
        && $git(['checkout', '--quiet', 'FETCH_HEAD'])[0] === 0
        && trim($git(['rev-parse', 'HEAD'])[1]) === $commit;
    if ($ok) {
        run(['rm', '-rf', '--', "$work/.git"], 120);
        if (is_dir($target)) {
            run(['rm', '-rf', '--', $target . '.old'], 120);
            @rename($target, $target . '.old');
        }
        $ok = @rename($work, $target);
        run(['rm', '-rf', '--', $target . '.old'], 120);
        // Read-only to everyone but root: this is code, and nothing but this command replaces it.
        run(['chmod', '-R', 'u=rwX,go=rX', $target], 120);
    } else {
        run(['rm', '-rf', '--', $work], 120);
    }
    if (!$ok) {
        warn("$label could not be fetched. Is this system online?");
    }
    return $ok;
}

function games_setup(): bool
{
    $target = games_share('luanti/games/mineclonia');
    if (is_file("$target/game.conf") && trim((string) @file_get_contents("$target/.offline-commit")) === MINECLONIA_COMMIT) {
        out('Mineclonia ' . MINECLONIA_VERSION . ' is already installed.');
    } else {
        out('Fetching Mineclonia ' . MINECLONIA_VERSION . ' (pinned commit) ...');
        if (!fetch_pinned(MINECLONIA_REPO, MINECLONIA_COMMIT, $target, 'Mineclonia')) {
            return false;
        }
        write_atomic("$target/.offline-commit", MINECLONIA_COMMIT . "\n", 0644);
    }
    // Where an installed Luanti looks for games, so that it can also be played alone, away from home.
    foreach (['/usr/share/luanti/games', '/usr/share/minetest/games', '/usr/share/games/minetest/games'] as $dir) {
        $link = prefix() . "$dir/mineclonia";
        if (is_dir(prefix() . $dir) && !file_exists($link) && !is_link($link)) {
            @symlink('/usr/local/share/offline/luanti/games/mineclonia', $link);
        }
    }
    return true;
}

/**
 * Open or close a port to private networks in the host firewall. For the tablet
 * activities, which Apache serves, this is the rule that counts. For a family world
 * it is a second line only: the container engine publishes the world's port around
 * the host firewall, and the address filter in the world's own unit is what holds.
 */
function game_firewall(int $port, string $protocol, string $label, bool $open): void
{
    if (prefix() !== '' || !have('ufw')) {
        return;
    }
    foreach (LAN_NETS as $net) {
        $rule = ['from', $net, 'to', 'any', 'port', (string) $port, 'proto', $protocol];
        run($open ? array_merge(['ufw', 'allow'], $rule, ['comment', $label]) : array_merge(['ufw', 'delete', 'allow'], $rule), 30);
    }
}

function game_host(string $world): bool
{
    $source = prefix() . "/usr/local/lib/offline/games/offline-game-$world.container";
    $unit = (string) @file_get_contents($source);
    if ($unit === '' || preg_match('/^Image=(\S+@sha256:[0-9a-f]{64})$/m', $unit, $m) !== 1) {
        warn("No unit is shipped for the $world world.");
        return false;
    }
    if (!is_file(games_share('luanti/games/mineclonia/game.conf')) && !games_setup()) {
        return false;
    }
    if (prefix() === '') {
        if (!have('podman')) {
            warn('Podman is not installed. Run the installer, or: sudo apt install podman');
            return false;
        }
        if (run(['podman', 'image', 'exists', $m[1]], 30)[0] !== 0) {
            out('  fetching the Luanti server (verified against the pinned digest) ...');
            if (run_live(['podman', 'pull', '--quiet', $m[1]]) !== 0) {
                warn('The Luanti server image could not be fetched. Is this system online?');
                return false;
            }
        }
    }
    $conf = prefix() . "/etc/offline/games/$world.conf";
    @mkdir(dirname($conf), 0755, true);
    // Settings are yours once written; an upgrade leaves them and puts the new defaults beside them.
    copy(prefix() . "/usr/local/lib/offline/games/$world.conf", is_file($conf) ? "$conf.dist" : $conf);
    @mkdir(path('apps') . "/luanti/$world", 0750, true);
    @chmod(path('apps') . '/luanti', 0755);
    @mkdir(dirname(game_unit_target($world)), 0755, true);
    if (!write_atomic(game_unit_target($world), rtrim($unit) . "\n\n[Install]\nWantedBy=multi-user.target\n", 0644)) {
        warn('Could not write ' . game_unit_target($world));
        return false;
    }
    game_firewall(GAME_WORLDS[$world]['port'], 'udp', "Offline family world $world", true);
    if (prefix() === '') {
        sys(['systemctl', 'daemon-reload']);
        sys(['systemctl', 'restart', "offline-game-$world.service"], 360);
    }
    out('  ' . paint('✔', 'green') . ' ' . GAME_WORLDS[$world]['title'] . ' is hosted here on port ' . GAME_WORLDS[$world]['port'] . ' and starts at boot.');
    return true;
}

/** The Apache site that serves the browser activities, on its own port and so its own origin. */
function activities_site(): string
{
    return implode("\n", [
        '# Offline browser activities (Sugarizer). Written by "offline games"; changes here are',
        '# overwritten. Static files only, on their own port so that their looser page policy',
        '# never applies to the portal. Nothing is loaded from, or sent to, any other site.',
        '',
        'Listen ' . SUGARIZER_PORT,
        '<VirtualHost *:' . SUGARIZER_PORT . '>',
        '    DocumentRoot /usr/local/share/offline/sugarizer',
        '    # Apache keeps icons of its own at this address; the activities have theirs.',
        '    Alias /icons/ /usr/local/share/offline/sugarizer/icons/',
        '    ErrorLog ${APACHE_LOG_DIR}/offline-activities-error.log',
        '    CustomLog ${APACHE_LOG_DIR}/offline-activities-access.log combined',
        '    <Directory /usr/local/share/offline/sugarizer>',
        '        Options -Indexes -ExecCGI -Includes',
        '        AllowOverride None',
        '        AllowMethods GET HEAD',
        '        <RequireAny>',
        '            Require local',
        '            <IfDefine OFFLINE_LAN>',
        '                Require ip ' . implode(' ', LAN_NETS),
        '            </IfDefine>',
        '        </RequireAny>',
        '    </Directory>',
        '    # Never run as a program, whatever a file is called.',
        '    <FilesMatch "\.(php|phtml|phar|cgi|pl|py|sh)$">',
        '        Require all denied',
        '    </FilesMatch>',
        '    <DirectoryMatch "/\.">',
        '        Require all denied',
        '    </DirectoryMatch>',
        '    Header always set Content-Security-Policy "default-src \'self\' \'unsafe-inline\' \'unsafe-eval\' data: blob:; connect-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'"',
        '    Header always set X-Content-Type-Options "nosniff"',
        '    Header always set Referrer-Policy "no-referrer"',
        '    Header always set Permissions-Policy "camera=(), microphone=(self), geolocation=(), payment=(), usb=()"',
        '</VirtualHost>',
        '',
    ]);
}

function games_activities(bool $install): bool
{
    $target = games_share('sugarizer');
    $site = prefix() . '/etc/apache2/sites-available/offline-activities.conf';
    if (!$install) {
        if (prefix() === '') {
            sys(['a2dissite', '-q', 'offline-activities']);
            sys(['systemctl', 'reload-or-restart', 'apache2.service']);
        }
        @unlink($site);
        game_firewall(SUGARIZER_PORT, 'tcp', '', false);
        run(['rm', '-rf', '--', $target], 300);
        out('The browser activities have been removed.');
        return true;
    }
    if (!is_file("$target/index.html") || trim((string) @file_get_contents("$target/.offline-commit")) !== SUGARIZER_COMMIT) {
        out('Fetching Sugarizer ' . SUGARIZER_VERSION . ' (pinned commit, about 630 MiB) ...');
        if (!fetch_pinned(SUGARIZER_REPO, SUGARIZER_COMMIT, $target, 'Sugarizer')) {
            return false;
        }
        // The one change made to the pinned copy: when it is served from a web address
        // Sugarizer expects its own server and a sign-in. These two switches of its own
        // make it behave as the stand-alone app instead, with no sign-in to a server
        // elsewhere, keeping each child's work in that device's browser.
        $main = "$target/js/sugarizer.js";
        $code = (string) @file_get_contents($main);
        $switches = ['noServerMode: false,' => 'noServerMode: true,', 'noLoginMode: false,' => 'noLoginMode: true,'];
        $found = array_sum(array_map(static fn (string $from): int => substr_count($code, $from), array_keys($switches)));
        if ($found !== count($switches) || !write_atomic($main, strtr($code, $switches), 0644)) {
            warn('Sugarizer could not be switched to stand-alone mode; it will ask for a server.');
        }
        write_atomic("$target/.offline-commit", SUGARIZER_COMMIT . "\n", 0644);
    }
    @mkdir(dirname($site), 0755, true);
    write_atomic($site, activities_site(), 0644);
    if (prefix() === '') {
        sys(['a2enmod', '-q', 'headers', 'allowmethods']);
        sys(['a2ensite', '-q', 'offline-activities']);
        if (sys(['apache2ctl', 'configtest'])) {
            sys(['systemctl', 'reload-or-restart', 'apache2.service']);
        } else {
            sys(['a2dissite', '-q', 'offline-activities']);
            warn('Apache rejected the activities site; it has been disabled again.');
            return false;
        }
        if (config('PORTAL_SCOPE') !== 'local') {
            game_firewall(SUGARIZER_PORT, 'tcp', 'Offline browser activities', true);
        }
    }
    out('  ' . paint('✔', 'green') . ' Browser activities are at http://' . (gethostname() ?: 'localhost') . '.local:' . SUGARIZER_PORT . '/');
    return true;
}

function command_games(array $args): int
{
    $action = $args[0] ?? 'list';
    $rest = \array_slice($args, 1);

    if ($action === 'list') {
        $status = games_status();
        heading('On this system');
        table(['Game', 'For', 'State', 'What it is'], array_map(static fn (array $g): array => [
            $g['title'], $g['for'], $g['installed'] ? 'installed' : '-', printable($g['about'], 90),
        ], $status['desktop']));
        heading('Family worlds (Luanti with Mineclonia ' . ($status['mineclonia'] ?? 'not yet fetched') . ')');
        table(['World', 'Port', 'Hosted here', 'About'], array_map(static fn (array $w): array => [
            $w['id'], (string) $w['port'], $w['hosted'] ? ($w['state'] === 'active' ? 'yes, running' : 'yes, ' . $w['state']) : '-', $w['about'],
        ], $status['worlds']));
        out('  To play: open Luanti, choose Join Game, and enter the address of the system that hosts');
        out('  the world (for example ' . (gethostname() ?: 'server') . '.local) with the port above. An adult should join first, as "parent".');
        $note = luanti_client_note();
        if ($note !== null) {
            out('  ' . paint('note:', 'yellow') . ' ' . $note);
        }
        heading('Browser activities for tablets');
        out('  ' . ($status['activities'] !== null ? "Sugarizer {$status['activities']['version']} on port {$status['activities']['port']}" : 'Not installed. sudo offline games activities'));
        out();
        out('sudo offline games install [GAME ...]      install the desktop games');
        out('sudo offline games host [creative|survival|all]   host a family world on this system');
        out('sudo offline games start|stop|restart|unhost WORLD');
        out('sudo offline games activities [remove]    browser activities for tablets (Sugarizer)');
        return 0;
    }

    require_root("games $action");

    if ($action === 'setup') {
        return games_setup() ? 0 : 1;
    }

    if ($action === 'install') {
        if (prefix() === '' && !have('apt-get')) {
            fail('This command installs Ubuntu packages and needs apt.');
        }
        $games = desktop_games();
        $wanted = $rest === [] || $rest === ['all'] ? array_keys($games) : $rest;
        $failed = 0;
        foreach ($wanted as $id) {
            if (!isset($games[$id])) {
                fail('No game called "' . printable($id) . '". See: offline games');
            }
            if ($games[$id]['installed'] || prefix() !== '') {
                out("  {$games[$id]['title']}: " . ($games[$id]['installed'] ? 'already installed' : 'would be installed'));
                continue;
            }
            $done = false;
            foreach ($games[$id]['packages'] as $package) {
                // Not every Ubuntu release carries every name; take the first one this release has.
                if (run(['apt-cache', 'show', $package], 60)[0] === 0) {
                    out(paint("Installing {$games[$id]['title']} ($package)", 'bold'));
                    $done = run_live(['apt-get', 'install', '-y', '--no-install-recommends', $package], ['DEBIAN_FRONTEND' => 'noninteractive']) === 0;
                    break;
                }
            }
            if (!$done) {
                warn("{$games[$id]['title']} could not be installed from this Ubuntu release.");
                $failed++;
            }
        }
        games_setup();
        command_status(['--write']);
        return $failed === 0 ? 0 : 1;
    }

    // A server that faces the internet offers the world its keyed sharing port and nothing else.
    if ((string) config('SYNC_EXPOSE') === 'internet' && ($action === 'host' || ($action === 'activities' && ($rest[0] ?? '') !== 'remove'))) {
        fail('A server exposed to the internet hosts no games: only the keyed sharing port faces the world. Host them on a system at home.');
    }
    if ($action === 'activities') {
        $ok = games_activities(($rest[0] ?? '') !== 'remove');
        command_status(['--write']);
        return $ok ? 0 : 1;
    }

    $worlds = ($rest[0] ?? '') === 'all' || ($action === 'host' && $rest === []) ? array_keys(GAME_WORLDS) : $rest;
    if (!in_array($action, ['host', 'unhost', 'start', 'stop', 'restart'], true) || $worlds === [] || array_diff($worlds, array_keys(GAME_WORLDS)) !== []) {
        fail('Usage: offline games [list]   |   sudo offline games install | setup | host [WORLD|all] | start|stop|restart|unhost WORLD | activities [remove]'
            . "\n       Worlds: " . implode(', ', array_keys(GAME_WORLDS)));
    }
    $failed = 0;
    foreach ($worlds as $world) {
        if ($action === 'host') {
            out(paint(GAME_WORLDS[$world]['title'], 'bold'));
            $failed += game_host($world) ? 0 : 1;
            continue;
        }
        if (!is_file(game_unit_target($world))) {
            warn("The $world world is not hosted on this system. Host it with: sudo offline games host $world");
            $failed++;
            continue;
        }
        if ($action === 'unhost') {
            if (prefix() === '') {
                sys(['systemctl', 'stop', "offline-game-$world.service"], 120);
            }
            @unlink(game_unit_target($world));
            game_firewall(GAME_WORLDS[$world]['port'], 'udp', '', false);
            if (prefix() === '') {
                sys(['systemctl', 'daemon-reload']);
            }
            out("The $world world is no longer hosted here. What was built is kept in " . path('apps') . "/luanti/$world.");
        } elseif (prefix() === '') {
            $failed += sys(['systemctl', $action, "offline-game-$world.service"], 360) ? 0 : 1;
        }
    }
    command_status(['--write']);
    return $failed === 0 ? 0 : 1;
}

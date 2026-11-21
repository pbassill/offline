<?php

declare(strict_types=1);

namespace Offline;

/**
 * Books: documents fetched from where they are published into the shared books
 * folder, each held to the size and SHA-256 recorded in the list. The list says
 * where a file is to be found; the checksum says which file it is, so it does
 * not matter which machine serves it.
 */

/** Accepted types, and the bytes each must begin with. */
const BOOK_TYPES = ['pdf' => '%PDF-', 'epub' => "PK\x03\x04"];
/** No single book is larger than this, pinned or not. */
const BOOK_MAX_BYTES = 2 * 1024 ** 3;
const BOOK_RIGHTS = [
    'pd' => 'public domain',
    'cc' => 'Creative Commons',
    'marked' => 'marked free to copy, unconfirmed',
    'private' => 'private household copy',
];

function book_id_ok(string $id): bool
{
    return preg_match('/^[a-z][a-z0-9-]{1,40}$/', $id) === 1;
}

function book_shelf_ok(string $shelf): bool
{
    return preg_match('#^[a-z0-9][a-z0-9-]{0,40}(/[a-z0-9][a-z0-9-]{0,40}){0,2}$#', $shelf) === 1;
}

/** The type of a book, from the end of its address, or null if it is not one we keep. */
function book_type(string $url): ?string
{
    $ext = extension((string) parse_url($url, PHP_URL_PATH));
    return isset(BOOK_TYPES[$ext]) ? $ext : null;
}

/** Whether a book is held to a checksum. Only a line in the local list may go without. */
function book_pinned(array $book): bool
{
    return preg_match('/^[0-9a-f]{64}$/', (string) ($book['sha256'] ?? '')) === 1;
}

/**
 * The books on offer: the shipped list, then the household's own, which may
 * replace a shipped line (to bring a checksum up to date, say). A malformed
 * line is passed over, never half-used.
 */
function book_list(): array
{
    $books = [];
    $files = [prefix() . '/usr/local/lib/offline/books.tsv' => false, prefix() . '/etc/offline/books.local.tsv' => true];
    foreach ($files as $file => $local) {
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] === '#') {
                continue;
            }
            $cols = array_map('trim', explode("\t", $line));
            if (count($cols) < 7) {
                continue;
            }
            [$id, $shelf, $url, $sha, $bytes, $rights, $title] = $cols;
            $pinned = preg_match('/^[0-9a-f]{64}$/', $sha) === 1 && preg_match('/^[1-9][0-9]{0,10}$/', $bytes) === 1 && (int) $bytes <= BOOK_MAX_BYTES;
            $open = $local && $sha === '-' && $bytes === '-';
            if (!book_id_ok($id) || !book_shelf_ok($shelf) || !valid_download_url($url) || book_type($url) === null
                || str_contains($url, '?') || str_contains($url, '#') || !($pinned || $open) || !isset(BOOK_RIGHTS[$rights])
                || $title === '' || mb_strlen($title) > 40 || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
                continue;
            }
            $books[$id] = [
                'id' => $id, 'shelf' => $shelf, 'url' => $url, 'sha256' => $pinned ? $sha : '', 'bytes' => $pinned ? (int) $bytes : 0,
                'rights' => $rights, 'title' => $title, 'note' => $cols[7] ?? '', 'file' => $id . '.' . book_type($url), 'local' => $local,
            ];
        }
    }
    return $books;
}

/** Where a book lives once published. */
function book_target(array $book): string
{
    return path('media') . '/books/' . $book['shelf'] . '/' . $book['file'];
}

/**
 * What is wrong with a file offered as this book, or null if nothing is. The
 * type is read from the file's first bytes, never from its name, and a pinned
 * book must be the recorded size and hash to the last bit.
 */
function book_problem(string $file, array $book): ?string
{
    if (is_link($file) || !is_file($file)) {
        return 'no such file was fetched';
    }
    $size = (int) filesize($file);
    $type = extension((string) ($book['file'] ?? $file));
    $magic = BOOK_TYPES[$type] ?? null;
    if ($magic === null) {
        return 'it is not a kind of file kept as a book';
    }
    if ($size > BOOK_MAX_BYTES) {
        return 'it is larger than any book may be';
    }
    if ((string) @file_get_contents($file, false, null, 0, strlen($magic)) !== $magic) {
        return 'it is not a ' . strtoupper($type) . ' file, whatever its name says';
    }
    if (!book_pinned($book)) {
        return null;
    }
    if ($size !== (int) $book['bytes']) {
        return 'it is ' . $size . ' bytes where ' . (int) $book['bytes'] . ' were expected';
    }
    if (!hash_equals((string) $book['sha256'], (string) hash_file('sha256', $file))) {
        return 'its SHA-256 is not the one recorded for it';
    }
    return null;
}

/** The books a name stands for: one id, a shelf, a shelf and those beneath it, or all. */
function book_select(array $books, string $name): array
{
    if ($name === 'all') {
        return array_values($books);
    }
    return array_values(array_filter($books, static fn (array $b): bool => $b['id'] === $name || $b['shelf'] === $name || str_starts_with($b['shelf'], $name . '/')));
}

/** The queue's word on each book that is on its way: id => state. */
function book_queue_states(): array
{
    $states = [];
    foreach (queue_load() as $item) {
        if (isset($item['book']) && is_string($item['book'])) {
            $states[$item['book']] = (string) $item['state'];
        }
    }
    return $states;
}

function command_books(array $args): int
{
    $action = $args[0] ?? 'list';
    $rest = array_slice($args, 1);
    $books = book_list();

    if ($action === 'list') {
        $shown = isset($rest[0]) ? book_select($books, $rest[0]) : array_values($books);
        if ($shown === []) {
            out(isset($rest[0]) ? 'No book or shelf called "' . printable($rest[0]) . '".' : 'No books are listed.');
            return isset($rest[0]) ? 1 : 0;
        }
        $queue = book_queue_states();
        $here = 0;
        $bytes = 0;
        $rows = [];
        foreach ($shown as $book) {
            $target = book_target($book);
            $present = is_file($target) && (!book_pinned($book) || (int) filesize($target) === $book['bytes']);
            $here += $present ? 1 : 0;
            $bytes += $book['bytes'];
            $state = $present ? 'here' : (is_file($target) ? 'differs' : ($queue[$book['id']] ?? '-'));
            $rows[] = [$book['id'], $book['shelf'], $book['rights'], $book['bytes'] > 0 ? human_bytes($book['bytes']) : '?', $state, $book['title']];
        }
        table(['Name', 'Shelf', 'Rights', 'Size', 'State', 'Title'], $rows);
        out();
        $unsized = count(array_filter($shown, static fn (array $b): bool => $b['bytes'] === 0));
        out(plural(count($shown), 'book') . ', ' . human_bytes($bytes) . ' in all' . ($unsized > 0 ? ', not counting ' . $unsized . ' of unrecorded size' : '') . '; ' . $here . ' here.');
        out('Rights: ' . implode('; ', array_map(static fn (string $k, string $v): string => "$k, $v", array_keys(BOOK_RIGHTS), BOOK_RIGHTS)) . '.');
        out('Fetch with: sudo offline books get NAME|SHELF|all      About one: offline books show NAME');
        return 0;
    }

    if ($action === 'show') {
        $book = $books[$rest[0] ?? ''] ?? null;
        if ($book === null) {
            fail('No book called "' . printable($rest[0] ?? '') . '". See: offline books');
        }
        heading($book['title']);
        out('  ' . wordwrap(printable($book['note'], 600), 96, "\n  "));
        out('  Rights:   ' . $book['rights'] . ' (' . BOOK_RIGHTS[$book['rights']] . ')');
        out('  From:     ' . printable($book['url'], 200));
        out('  Kept as:  media/books/' . $book['shelf'] . '/' . $book['file']);
        out('  Checked:  ' . (book_pinned($book) ? human_bytes($book['bytes']) . ', SHA-256 ' . $book['sha256'] : 'by type alone; this line records no checksum'));
        return 0;
    }

    if ($action === 'verify') {
        $bad = 0;
        $checked = 0;
        foreach ($books as $book) {
            $target = book_target($book);
            if (!is_file($target)) {
                continue;
            }
            $checked++;
            $problem = book_problem($target, $book);
            if ($problem !== null) {
                $bad++;
                warn($book['id'] . ': ' . $problem . '.');
            }
        }
        out($bad === 0 ? plural($checked, 'book') . ' here, and each is the file its line describes.' : "$bad of $checked are not the files their lines describe. Remove each and fetch it again.");
        return $bad === 0 ? 0 : 1;
    }

    if ($action !== 'get' || $rest === []) {
        fail('Usage: offline books [list [SHELF]] | show NAME | verify   |   sudo offline books get NAME|SHELF|all ...');
    }
    become_service_user('books get');
    $chosen = [];
    foreach ($rest as $name) {
        $matches = book_select($books, $name);
        if ($matches === []) {
            fail('No book or shelf called "' . printable($name) . '". See: offline books');
        }
        foreach ($matches as $book) {
            $chosen[$book['id']] = $book;
        }
    }
    $queue = book_queue_states();
    $queued = 0;
    $bytes = 0;
    foreach ($chosen as $book) {
        $target = book_target($book);
        if (is_file($target)) {
            $problem = book_problem($target, $book);
            out($problem === null ? "Already here: {$book['title']}" : "Left alone: {$book['title']} is here, but $problem. Remove it to fetch it again.");
            continue;
        }
        if (in_array($queue[$book['id']] ?? '', ['queued', 'running', 'staged'], true)) {
            out("Already on its way: {$book['title']}");
            continue;
        }
        $item = queue_add([
            'kind' => 'file', 'url' => $book['url'], 'to' => 'books/' . $book['shelf'], 'book' => $book['id'], 'title' => $book['title'],
            'file' => $book['file'], 'sha256' => $book['sha256'], 'bytes' => $book['bytes'],
        ]);
        out("Queued {$item['id']}: {$book['title']}");
        $queued++;
        $bytes += $book['bytes'];
    }
    if ($queued > 0) {
        out(plural($queued, 'book') . ' queued' . ($bytes > 0 ? ', ' . human_bytes($bytes) : '') . '. A server fetches them within ten minutes; do it now with: sudo offline queue run');
        out('A book that matches its recorded checksum is published as it arrives. One with no checksum waits in staging.');
    }
    return 0;
}

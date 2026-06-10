<?php
// admin/lib/posts.php — operacje na postach (pliki JSON, tresc = bezpieczny HTML)
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/sanitize.php';

function posts_dir(): string {
    $d = data_dir() . '/posts';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}
function trash_dir(): string {
    $d = posts_dir() . '/_trash';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}
function post_path(string $slug): string { return posts_dir() . '/' . basename($slug) . '.json'; }

function load_post(string $slug): ?array {
    $f = post_path($slug);
    if (!is_file($f)) return null;
    $data = json_decode((string)file_get_contents($f), true);
    return is_array($data) ? $data : null;
}

// $in['content'] to HTML (z edytora lub importu). $in['contentFormat']='markdown' => konwersja MD->HTML.
function save_post(array $in, ?string $originalSlug = null): array {
    if (trim($in['title'] ?? '') === '') throw new RuntimeException('Tytul jest wymagany.');

    $rawContent = (string)($in['content'] ?? '');
    if (($in['contentFormat'] ?? '') === 'markdown') {
        $rawContent = render_markdown($rawContent);
    }
    $html = sanitize_html($rawContent);
    if (trim(strip_tags($html)) === '') throw new RuntimeException('Tresc jest pusta (lub zostala odrzucona przez filtr bezpieczenstwa).');

    $title = trim($in['title']);
    $slugBase = trim($in['slug'] ?? '') !== '' ? $in['slug'] : $title;
    $slug = unique_slug($slugBase, $originalSlug);
    $existing = $originalSlug ? load_post($originalSlug) : null;
    $now = date('c');

    $tags = $in['tags'] ?? '';
    if (!is_array($tags)) $tags = array_values(array_filter(array_map('trim', explode(',', (string)$tags))));

    $status = ($in['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

    $post = [
        'slug'        => $slug,
        'title'       => $title,
        'description' => trim($in['description'] ?? '') ?: auto_description($html),
        'date'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'] ?? '') ? $in['date'] : ($existing['date'] ?? date('Y-m-d')),
        'cover'       => (function ($c) { $c = preg_replace('/[\x00-\x1f"\'<>]/', '', trim((string)$c)) ?? ''; return ($c !== '' && !preg_match('#^(/|https?://)#i', $c)) ? '' : $c; })($in['cover'] ?? ''),
        'coverAlt'    => trim($in['coverAlt'] ?? ''),
        'tags'        => array_slice($tags, 0, 20),
        'status'      => $status,
        'content'     => $html,
        'readTime'    => calc_read_time($html),
        'created'     => $existing['created'] ?? $now,
        'updated'     => $now,
    ];

    if ($originalSlug && $originalSlug !== $slug) @unlink(post_path($originalSlug));

    $json = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Blad kodowania tresci (UTF-8): ' . json_last_error_msg());
    if (file_put_contents(post_path($slug), $json, LOCK_EX) === false) throw new RuntimeException('Nie udalo sie zapisac pliku.');
    return $post;
}

function list_posts(bool $onlyPublished = false): array {
    $posts = [];
    foreach (glob(posts_dir() . '/*.json') ?: [] as $f) {
        $data = json_decode((string)file_get_contents($f), true);
        if (!is_array($data)) continue;
        if ($onlyPublished && ($data['status'] ?? 'published') !== 'published') continue;
        $posts[] = $data;
    }
    usort($posts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $posts;
}

function unique_slug(string $base, ?string $excludeSlug = null): string {
    $slug = slugify($base);
    if ($excludeSlug !== null && $excludeSlug === $slug) return $slug;
    if (!load_post($slug)) return $slug;
    $i = 2;
    while (load_post($slug . '-' . $i)) $i++;
    return $slug . '-' . $i;
}

function duplicate_post(string $slug): ?array {
    $p = load_post($slug);
    if (!$p) return null;
    $copy = $p;
    $copy['title'] = $p['title'] . ' (kopia)';
    $copy['status'] = 'draft';
    unset($copy['slug']);
    $copy['slug'] = unique_slug($p['slug'] . '-kopia');
    $now = date('c');
    $copy['created'] = $now; $copy['updated'] = $now;
    file_put_contents(post_path($copy['slug']), json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $copy;
}

/* ===================== KOSZ (soft-delete) ===================== */
function archive_post(string $slug): bool {
    $slug = basename($slug);
    $src = post_path($slug);
    if (!is_file($src)) return false;
    $destName = $slug;
    if (is_file(trash_dir() . '/' . $destName . '.json')) $destName = $slug . '-' . date('YmdHis');
    $data = json_decode((string)file_get_contents($src), true) ?: [];
    $data['_trashedAt'] = date('c');
    $data['_trashFile'] = $destName;
    @file_put_contents($src, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return rename($src, trash_dir() . '/' . $destName . '.json');
}

function list_trashed(): array {
    $posts = [];
    foreach (glob(trash_dir() . '/*.json') ?: [] as $f) {
        $data = json_decode((string)file_get_contents($f), true);
        if (!is_array($data)) continue;
        $data['_trashFile'] = basename($f, '.json');
        if (empty($data['_trashedAt'])) $data['_trashedAt'] = date('c', (int)filemtime($f));
        $posts[] = $data;
    }
    usort($posts, fn($a, $b) => strcmp($b['_trashedAt'] ?? '', $a['_trashedAt'] ?? ''));
    return $posts;
}

function restore_post(string $trashFile): array {
    $trashFile = basename($trashFile);
    $src = trash_dir() . '/' . $trashFile . '.json';
    if (!is_file($src)) throw new RuntimeException('Nie znaleziono w koszu.');
    $data = json_decode((string)file_get_contents($src), true);
    if (!is_array($data)) throw new RuntimeException('Uszkodzony plik w koszu.');
    unset($data['_trashedAt'], $data['_trashFile']);
    $data['slug'] = unique_slug($data['slug'] ?? ($data['title'] ?? 'wpis'));
    $data['updated'] = date('c');
    if (file_put_contents(post_path($data['slug']), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Nie udalo sie przywrocic postu.');
    }
    @unlink($src);
    return $data;
}

function purge_post(string $trashFile): bool {
    $f = trash_dir() . '/' . basename($trashFile) . '.json';
    return is_file($f) ? unlink($f) : false;
}

function load_trashed(string $trashFile): ?array {
    $f = trash_dir() . '/' . basename($trashFile) . '.json';
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

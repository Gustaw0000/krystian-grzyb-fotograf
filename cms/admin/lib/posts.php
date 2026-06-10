<?php
// admin/lib/posts.php — operacje na postach (pliki JSON)
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function posts_dir(): string {
    $d = data_dir() . '/posts';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}

function post_path(string $slug): string {
    return posts_dir() . '/' . basename($slug) . '.json';
}

function load_post(string $slug): ?array {
    $f = post_path($slug);
    if (!is_file($f)) return null;
    $data = json_decode((string)file_get_contents($f), true);
    return is_array($data) ? $data : null;
}

function save_post(array $in, ?string $originalSlug = null): array {
    if (trim($in['title'] ?? '') === '')   throw new RuntimeException('Tytul jest wymagany.');
    if (trim($in['content'] ?? '') === '') throw new RuntimeException('Tresc jest wymagana.');

    $title = trim($in['title']);
    $slugBase = trim($in['slug'] ?? '') !== '' ? $in['slug'] : $title;
    $slug = unique_slug($slugBase, $originalSlug);

    $existing = $originalSlug ? load_post($originalSlug) : null;
    $now = date('c');
    $content = trim($in['content']);
    $html = render_markdown($content);

    $tags = $in['tags'] ?? '';
    if (!is_array($tags)) {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string)$tags))));
    }

    $status = ($in['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

    $post = [
        'slug'        => $slug,
        'title'       => $title,
        'description' => trim($in['description'] ?? '') ?: auto_description($html),
        'date'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'] ?? '') ? $in['date'] : ($existing['date'] ?? date('Y-m-d')),
        'cover'       => trim($in['cover'] ?? ''),
        'coverAlt'    => trim($in['coverAlt'] ?? ''),
        'tags'        => $tags,
        'status'      => $status,
        'content'     => $content,
        'readTime'    => calc_read_time($html),
        'created'     => $existing['created'] ?? $now,
        'updated'     => $now,
    ];

    // Zmiana sluga -> usun stary plik
    if ($originalSlug && $originalSlug !== $slug) {
        @unlink(post_path($originalSlug));
    }

    $json = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Blad kodowania tresci (UTF-8): ' . json_last_error_msg());
    }
    if (file_put_contents(post_path($slug), $json, LOCK_EX) === false) {
        throw new RuntimeException('Nie udalo sie zapisac postu na dysk.');
    }
    return $post;
}

function delete_post(string $slug): bool {
    $f = post_path($slug);
    return is_file($f) ? unlink($f) : false;
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

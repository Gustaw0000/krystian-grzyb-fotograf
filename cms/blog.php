<?php
// blog.php — routing publicznego bloga (lista + pojedynczy wpis)
declare(strict_types=1);

require_once __DIR__ . '/admin/lib/posts.php';
require_once __DIR__ . '/blog-render.php';

send_security_headers();

$slug = (string)($_GET['slug'] ?? '');

if ($slug !== '') {
    $post = load_post($slug);
    if (!$post || ($post['status'] ?? 'published') !== 'published') {
        blog_render_404();
        exit;
    }
    blog_render_post($post);
} else {
    blog_render_index(list_posts(true));
}

<?php
// sitemap.php — dynamiczna mapa strony: statyczne adresy + opublikowane wpisy bloga
declare(strict_types=1);

require_once __DIR__ . '/admin/lib/posts.php';

const SITE_URL = 'https://krystiangrzyb.pl';

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$urls = [
    ['loc' => '/',                      'pri' => '1.0', 'freq' => 'weekly', 'mod' => date('Y-m-d')],
    ['loc' => '/blog',                  'pri' => '0.8', 'freq' => 'weekly', 'mod' => date('Y-m-d')],
    ['loc' => '/polityka-prywatnosci',  'pri' => '0.3', 'freq' => 'yearly', 'mod' => '2026-06-10'],
    ['loc' => '/regulamin',             'pri' => '0.3', 'freq' => 'yearly', 'mod' => '2026-06-10'],
];

foreach (list_posts(true) as $p) {
    $mod = substr((string)($p['updated'] ?? $p['date'] ?? date('Y-m-d')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mod)) $mod = date('Y-m-d');
    $urls[] = ['loc' => '/blog/' . $p['slug'], 'pri' => '0.7', 'freq' => 'monthly', 'mod' => $mod];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . h(SITE_URL . $u['loc']) . '</loc>'
       . '<lastmod>' . $u['mod'] . '</lastmod>'
       . '<changefreq>' . $u['freq'] . '</changefreq>'
       . '<priority>' . $u['pri'] . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";

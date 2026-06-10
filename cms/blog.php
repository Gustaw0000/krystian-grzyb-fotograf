<?php
// blog.php — publiczny blog (lista + pojedynczy wpis). Renderowane przez PHP.
declare(strict_types=1);

require_once __DIR__ . '/admin/lib/posts.php';

const SITE_URL = 'https://krystiangrzyb.pl';

$slug = (string)($_GET['slug'] ?? '');

if ($slug !== '') {
    $post = load_post($slug);
    if (!$post || ($post['status'] ?? 'published') !== 'published') {
        http_response_code(404);
        render_404();
        exit;
    }
    render_post($post);
} else {
    render_index(list_posts(true));
}

/* ============================ KOMPONENTY ================================= */

function site_head(string $title, string $desc, string $canonical, string $ogImage, bool $isArticle = false, array $post = []): void {
    ?><!doctype html>
<html lang="pl-PL">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>document.documentElement.classList.add('js');</script>
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="theme-color" content="#100C09">
<meta name="color-scheme" content="dark light">
<link rel="canonical" href="<?= h($canonical) ?>">
<meta property="og:locale" content="pl_PL">
<meta property="og:type" content="<?= $isArticle ? 'article' : 'website' ?>">
<meta property="og:site_name" content="Krystian Grzyb Fotografia">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:image" content="<?= h($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= h($ogImage) ?>">
<link rel="icon" type="image/png" href="/favicon.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..700&family=Outfit:wght@300..800&family=Great+Vibes&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css">
<?php if ($isArticle && $post):
    $ld = [
        '@context' => 'https://schema.org', '@type' => 'BlogPosting',
        'headline' => $post['title'], 'description' => $desc,
        'datePublished' => $post['date'], 'dateModified' => substr($post['updated'] ?? $post['date'], 0, 10),
        'author' => ['@type' => 'Person', 'name' => 'Krystian Grzyb'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Krystian Grzyb Fotografia'],
        'mainEntityOfPage' => $canonical,
    ];
    if (!empty($post['cover'])) $ld['image'] = SITE_URL . $post['cover'];
    echo '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
endif; ?>
</head>
<body class="page-blog">
<a href="#main" class="skip-link">Przejdź do treści</a>
<header class="site-header" id="top-header">
  <div class="wrap header-wrap">
    <a href="/" class="brand" aria-label="Krystian Grzyb, strona główna">
      <span class="brand-logo">Krystian Grzyb</span>
      <span class="brand-tagline">Fotograf okolicznościowy</span>
    </a>
    <nav class="primary-nav" aria-label="Menu główne">
      <ul>
        <li><a href="/#o-mnie">O mnie</a></li>
        <li><a href="/#uslugi">Usługi</a></li>
        <li><a href="/#galeria">Galeria</a></li>
        <li><a href="/blog" aria-current="page">Blog</a></li>
        <li><a href="/#kontakt">Kontakt</a></li>
      </ul>
    </nav>
    <a class="phone-cta" href="tel:+48570941162" aria-label="Zadzwoń: 570 941 162">
      <span class="phone-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24 11 11 0 0 0 3.5.56 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11 11 0 0 0 .56 3.5 1 1 0 0 1-.24 1z"/></svg></span>
      <span class="phone-cta-label">Zadzwoń</span>
    </a>
    <button type="button" class="nav-toggle" aria-label="Otwórz menu" aria-expanded="false" aria-controls="mobile-nav">
      <span class="nav-toggle-lines" aria-hidden="true"><span></span><span></span><span></span></span>
    </button>
  </div>
</header>
<div class="mobile-nav" id="mobile-nav" hidden>
  <nav aria-label="Menu mobilne">
    <ul class="mobile-nav-list">
      <li><a href="/#o-mnie">O mnie</a></li>
      <li><a href="/#uslugi">Usługi</a></li>
      <li><a href="/#galeria">Galeria</a></li>
      <li><a href="/blog">Blog</a></li>
      <li><a href="/#kontakt">Kontakt</a></li>
    </ul>
    <div class="mobile-nav-cta">
      <a class="btn btn-primary" href="tel:+48570941162">Zadzwoń teraz</a>
      <a class="btn btn-ghost" href="https://www.instagram.com/krystiangrzyb.pl/" target="_blank" rel="noopener">Instagram</a>
    </div>
  </nav>
</div>
<?php
}

function site_footer(): void {
    ?>
<footer class="site-footer footer-bar" role="contentinfo">
  <div class="wrap footer-bar-row">
    <a href="/" class="brand footer-brand" aria-label="Krystian Grzyb, strona główna">
      <span class="brand-logo">Krystian Grzyb</span>
      <span class="brand-tagline">Fotograf okolicznościowy</span>
    </a>
    <nav class="footer-nav" aria-label="Stopka">
      <a href="/#o-mnie">O mnie</a>
      <a href="/#uslugi">Usługi</a>
      <a href="/#galeria">Galeria</a>
      <a href="/blog">Blog</a>
      <a href="/#kontakt">Kontakt</a>
      <a href="https://www.instagram.com/krystiangrzyb.pl/" target="_blank" rel="noopener">Instagram</a>
    </nav>
    <a class="btn btn-primary footer-call" href="tel:+48570941162">Zadzwoń</a>
  </div>
  <div class="wrap footer-legal">
    <p>&copy; <span id="year">2026</span> Krystian Grzyb · <a href="tel:+48570941162">570 941 162</a> · <a href="mailto:kontakt@krystiangrzyb.pl">kontakt@krystiangrzyb.pl</a> · <a href="/polityka-prywatnosci">Polityka prywatności</a> · <a href="/regulamin">Regulamin świadczenia usług</a></p>
  </div>
</footer>
<div class="cursor-dot" aria-hidden="true"></div>
<script src="/script.js" defer></script>
</body>
</html>
<?php
}

/* ============================== WIDOKI ================================== */

function render_index(array $posts): void {
    site_head(
        'Blog · Krystian Grzyb Fotografia',
        'Blog fotografa okolicznościowego: porady na osiemnastki i imprezy, kulisy reportaży i inspiracje.',
        SITE_URL . '/blog', SITE_URL . '/og-image.png'
    );
    ?>
<main id="main" class="blog-wrap">
  <div class="wrap">
    <header class="blog-intro">
      <p class="kicker"><span class="kicker-rule"></span>Blog</p>
      <h1>Z planu i zza obiektywu</h1>
      <p class="blog-intro-lede">Porady na osiemnastki i imprezy, kulisy reportaży i inspiracje prosto ode mnie.</p>
    </header>
    <?php if (!$posts): ?>
      <p class="blog-empty">Pierwsze wpisy pojawią się już niedługo. Zajrzyj wkrótce.</p>
    <?php else: ?>
      <div class="blog-grid">
        <?php foreach ($posts as $p): ?>
          <article class="blog-card">
            <a href="/blog/<?= h($p['slug']) ?>" class="blog-card-media">
              <?php if (!empty($p['cover'])): ?>
                <img src="<?= h($p['cover']) ?>" alt="<?= h($p['coverAlt'] ?? '') ?>" loading="lazy" decoding="async">
              <?php else: ?><span class="blog-card-noimg">KG</span><?php endif; ?>
            </a>
            <div class="blog-card-body">
              <p class="blog-card-meta"><?= h(format_date_pl($p['date'] ?? '')) ?> · <?= (int)($p['readTime'] ?? 1) ?> min</p>
              <h2 class="blog-card-title"><a href="/blog/<?= h($p['slug']) ?>"><?= h($p['title']) ?></a></h2>
              <p class="blog-card-desc"><?= h($p['description'] ?? '') ?></p>
              <a class="blog-card-link" href="/blog/<?= h($p['slug']) ?>">Czytaj dalej</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php
    site_footer();
}

function render_post(array $p): void {
    $canonical = SITE_URL . '/blog/' . $p['slug'];
    $ogImage = !empty($p['cover']) ? SITE_URL . $p['cover'] : SITE_URL . '/og-image.png';
    site_head(
        $p['title'] . ' · Blog Krystian Grzyb',
        $p['description'] ?? '', $canonical, $ogImage, true, $p
    );
    ?>
<main id="main" class="post-wrap">
  <article class="wrap post-article">
    <p class="post-back"><a href="/blog">&larr; Wszystkie wpisy</a></p>
    <header class="post-header">
      <p class="post-meta"><?= h(format_date_pl($p['date'] ?? '')) ?> · <?= (int)($p['readTime'] ?? 1) ?> min czytania</p>
      <h1><?= h($p['title']) ?></h1>
      <?php if (!empty($p['description'])): ?><p class="post-lede"><?= h($p['description']) ?></p><?php endif; ?>
    </header>
    <?php if (!empty($p['cover'])): ?>
      <figure class="post-cover"><img src="<?= h($p['cover']) ?>" alt="<?= h($p['coverAlt'] ?? '') ?>" decoding="async"></figure>
    <?php endif; ?>
    <div class="post-body">
      <?= render_markdown($p['content'] ?? '') ?>
    </div>
    <?php if (!empty($p['tags'])): ?>
      <ul class="post-tags"><?php foreach ($p['tags'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <aside class="post-cta">
      <p>Planujesz imprezę i szukasz fotografa?</p>
      <a class="btn btn-primary" href="/#kontakt">Napisz do mnie</a>
    </aside>
  </article>
</main>
<?php
    site_footer();
}

function render_404(): void {
    site_head('Nie znaleziono wpisu · Blog', 'Tego wpisu nie ma.', SITE_URL . '/blog', SITE_URL . '/og-image.png');
    ?>
<main id="main" class="post-wrap">
  <div class="wrap post-article" style="text-align:center">
    <h1>Nie ma takiego wpisu</h1>
    <p class="post-lede">Mógł zostać usunięty albo zmienił adres.</p>
    <a class="btn btn-primary" href="/blog">Zobacz wszystkie wpisy</a>
  </div>
</main>
<?php
    site_footer();
}

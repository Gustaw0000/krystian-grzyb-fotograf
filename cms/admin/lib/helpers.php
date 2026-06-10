<?php
// admin/lib/helpers.php — wspolne funkcje pomocnicze
declare(strict_types=1);

// Escapowanie do HTML
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Katalog glowny strony (document root projektu) = admin/.. czyli folder z blog.php
function project_root(): string {
    return dirname(__DIR__, 2);
}

// Sciezka do folderu z danymi
function data_dir(): string {
    $d = __DIR__ . '/../data';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}

// Transliteracja polskich znakow + slug
function slugify(string $text): string {
    $map = [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ż'=>'z','Ź'=>'z',
    ];
    $text = strtr($text, $map);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    $text = trim((string)$text, '-');
    if ($text === '') $text = 'post';
    if (mb_strlen($text) > 80) $text = trim(mb_substr($text, 0, 80), '-');
    return $text;
}

// Data po polsku, np. 10 czerwca 2026
function format_date_pl(string $iso): string {
    $months = [1=>'stycznia','lutego','marca','kwietnia','maja','czerwca',
        'lipca','sierpnia','września','października','listopada','grudnia'];
    $ts = strtotime($iso);
    if ($ts === false) return $iso;
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// Szacowany czas czytania (slowa / 200)
function calc_read_time(string $html): int {
    $words = str_word_count(strip_tags($html));
    return max(1, (int)ceil($words / 200));
}

// Krotki opis z tresci (gdy autor nie poda)
function auto_description(string $html, int $len = 155): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
    if (mb_strlen($text) <= $len) return $text;
    $cut = mb_substr($text, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false) $cut = mb_substr($cut, 0, $sp);
    return $cut . '…';
}

/*
 * Bezpieczny renderer "markdown-lite".
 * Najpierw escapuje CALY tekst (zero surowego HTML od autora = brak XSS),
 * potem dokłada wylacznie znane, kontrolowane tagi.
 * Obsluga: ## naglowek, ### podnaglowek, - lista, > cytat,
 *          **pogrubienie**, *kursywa*, [tekst](url), ![alt](url) jako obraz,
 *          pusta linia = nowy akapit.
 */
function render_markdown(string $src): string {
    $src = str_replace("\r\n", "\n", $src);
    $blocks = preg_split('/\n{2,}/', trim($src)) ?: [];
    $out = [];

    foreach ($blocks as $block) {
        $block = trim($block, "\n");
        if ($block === '') continue;
        $lines = explode("\n", $block);
        $first = $lines[0];

        // Obraz w osobnej linii: ![alt](url)
        if (preg_match('/^!\[(.*?)\]\((\S+?)\)\s*$/', $first, $m)) {
            $url = md_safe_url($m[2]);
            if ($url !== null) {
                $out[] = '<figure class="post-figure"><img src="' . h($url) . '" alt="' . h($m[1]) . '" loading="lazy" decoding="async"></figure>';
                continue;
            }
        }

        // Naglowki
        if (preg_match('/^###\s+(.+)$/', $first, $m)) { $out[] = '<h3>' . md_inline($m[1]) . '</h3>'; continue; }
        if (preg_match('/^##\s+(.+)$/', $first, $m))  { $out[] = '<h2>' . md_inline($m[1]) . '</h2>'; continue; }

        // Lista punktowana (caly blok zaczyna sie od "- ")
        if (preg_match('/^[-*]\s+/', $first)) {
            $items = '';
            foreach ($lines as $li) {
                if (preg_match('/^[-*]\s+(.+)$/', trim($li), $mm)) {
                    $items .= '<li>' . md_inline($mm[1]) . '</li>';
                }
            }
            if ($items !== '') { $out[] = '<ul>' . $items . '</ul>'; continue; }
        }

        // Cytat
        if (preg_match('/^>\s?(.*)$/', $first)) {
            $quote = '';
            foreach ($lines as $li) {
                $quote .= md_inline(preg_replace('/^>\s?/', '', $li) ?? '') . ' ';
            }
            $out[] = '<blockquote>' . trim($quote) . '</blockquote>';
            continue;
        }

        // Zwykly akapit (pojedyncze \n w srodku -> <br>)
        $paragraph = implode('<br>', array_map('md_inline', $lines));
        $out[] = '<p>' . $paragraph . '</p>';
    }

    return implode("\n", $out);
}

// Formatowanie inline na juz-zescapowanym tekscie
function md_inline(string $text): string {
    $text = h($text);
    // [tekst](url)
    $text = preg_replace_callback('/\[(.+?)\]\((\S+?)\)/', function ($m) {
        $url = md_safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if ($url === null) return $m[1];
        $ext = (strpos($url, 'http') === 0) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . h($url) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $text) ?? $text;
    // **bold**
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    // *italic*
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
    return $text;
}

// Dopuszczamy tylko bezpieczne URL-e: http(s):// oraz wewnetrzne /...
function md_safe_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if ($url[0] === '/') return $url;
    if (preg_match('#^https?://#i', $url)) return $url;
    if (preg_match('#^mailto:#i', $url)) return $url;
    return null;
}

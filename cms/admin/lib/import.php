<?php
// admin/lib/import.php — import postow: wklejka + pliki (MD / HTML / JSON / CSV), hurtowo
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/posts.php';

function import_result_new(): array { return ['imported' => [], 'skipped' => [], 'errors' => [], 'total' => 0]; }

// Zapis jednego importowanego rekordu z obsluga duplikatow
function imp_save(array $data, bool $overwrite, array &$res): void {
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') { $res['errors'][] = 'Pominieto rekord bez tytulu.'; return; }
    try {
        $base = trim((string)($data['slug'] ?? '')) !== '' ? $data['slug'] : $title;
        $proposed = slugify($base);
        $existing = load_post($proposed);
        if ($existing && !$overwrite) { $res['skipped'][] = $proposed . ' (juz istnieje)'; return; }
        $data['slug'] = $proposed;
        $post = save_post($data, $existing ? $proposed : null);
        $res['imported'][] = $post['slug'];
    } catch (Throwable $e) {
        $res['errors'][] = $title . ': ' . $e->getMessage();
    }
}

function imp_title_from_markdown(string $md, string $fallback): string {
    if (preg_match('/^\s*#\s+(.+)$/m', $md, $m)) return trim($m[1]);
    return $fallback;
}
function imp_title_from_html(string $html, string $fallback): string {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) return trim(strip_tags($m[1]));
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) return trim(strip_tags($m[1]));
    return $fallback;
}

// Wklejona tresc z formularza
function import_pasted(string $title, string $content, string $format, bool $overwrite, string $status, array &$res): void {
    if (trim($content) === '') { $res['errors'][] = 'Pusta tresc do wklejenia.'; return; }
    $res['total']++;
    imp_save([
        'title'   => $title !== '' ? $title : ($format === 'markdown' ? imp_title_from_markdown($content, 'Wpis') : imp_title_from_html($content, 'Wpis')),
        'content' => $content,
        'contentFormat' => $format === 'markdown' ? 'markdown' : 'html',
        'status'  => $status,
    ], $overwrite, $res);
}

// Plik JSON: pojedynczy obiekt lub tablica obiektow w naszym schemacie
function import_json_text(string $raw, bool $overwrite, string $status, array &$res): void {
    $data = json_decode($raw, true);
    if (!is_array($data)) { $res['errors'][] = 'Nieprawidlowy JSON.'; return; }
    if ($data === []) return; // pusta tablica = zero wpisow
    $items = (array_keys($data) === range(0, count($data) - 1)) ? $data : [$data];
    foreach ($items as $it) {
        if (!is_array($it)) { $res['errors'][] = 'Pominieto element JSON (nie obiekt).'; continue; }
        $res['total']++;
        imp_save([
            'title'       => $it['title'] ?? '',
            'slug'        => $it['slug'] ?? '',
            'content'     => $it['content'] ?? '',
            'contentFormat' => ($it['contentFormat'] ?? '') === 'markdown' ? 'markdown' : 'html',
            'description' => $it['description'] ?? '',
            'date'        => $it['date'] ?? '',
            'tags'        => $it['tags'] ?? '',
            'cover'       => $it['cover'] ?? ($it['image'] ?? ''),
            'coverAlt'    => $it['coverAlt'] ?? '',
            'status'      => in_array(($it['status'] ?? $status), ['draft', 'published'], true) ? ($it['status'] ?? $status) : $status,
        ], $overwrite, $res);
    }
}

// Plik CSV: naglowki + wiersze (elastyczne aliasy kolumn)
function import_csv_file(string $filepath, bool $overwrite, string $status, array &$res): void {
    $fh = fopen($filepath, 'r');
    if (!$fh) { $res['errors'][] = 'Nie udalo sie otworzyc CSV.'; return; }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $headers = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$headers) { fclose($fh); $res['errors'][] = 'Pusty CSV.'; return; }
    $idx = [];
    foreach ($headers as $i => $hname) $idx[strtolower(trim((string)$hname))] = $i;
    $pick = function (array $row, array $aliases) use ($idx): string {
        foreach ($aliases as $a) if (isset($idx[$a]) && isset($row[$idx[$a]])) return trim((string)$row[$idx[$a]]);
        return '';
    };
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
        $res['total']++;
        $fmt = strtolower($pick($row, ['contentformat', 'format']));
        imp_save([
            'title'       => $pick($row, ['title', 'tytul', 'tytuł', 'wp_title_final']),
            'slug'        => $pick($row, ['slug', 'wp_slug']),
            'content'     => $pick($row, ['content', 'tresc', 'treść', 'html', 'wp_content_html', 'body']),
            'contentFormat' => $fmt === 'markdown' ? 'markdown' : 'html',
            'description' => $pick($row, ['description', 'opis', 'wp_meta_description', 'meta']),
            'date'        => $pick($row, ['date', 'data']),
            'tags'        => $pick($row, ['tags', 'tagi', 'wp_tags']),
            'cover'       => $pick($row, ['cover', 'image', 'wp_image_url', 'zdjecie']),
            'coverAlt'    => $pick($row, ['coveralt', 'alt']),
            'status'      => $status,
        ], $overwrite, $res);
    }
    fclose($fh);
}

// Glowne wejscie: obsluguje wklejke ORAZ pliki
function imp_str($v): string { return is_string($v) ? $v : ''; }

function run_import(array $postData, array $files, array &$res): void {
    $overwrite = !empty($postData['overwrite']);
    $status = imp_str($postData['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

    // 1) Wklejka
    $pasteContent = imp_str($postData['paste_content'] ?? '');
    if (trim($pasteContent) !== '') {
        import_pasted(
            trim(imp_str($postData['paste_title'] ?? '')),
            $pasteContent,
            imp_str($postData['paste_format'] ?? 'html') ?: 'html',
            $overwrite, $status, $res
        );
    }

    // 2) Pliki (wiele lub jeden)
    $f = $files['files'] ?? null;
    if ($f && isset($f['name'])) {
        if (!is_array($f['name'])) { // pojedynczy plik (name bez []) -> znormalizuj do tablic
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) { $f[$k] = [$f[$k] ?? null]; }
        }
        $count = count($f['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $name = (string)$f['name'][$i];
            if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { $res['errors'][] = "$name: blad uploadu."; continue; }
            if (!is_uploaded_file($f['tmp_name'][$i])) { $res['errors'][] = "$name: nieprawidlowy upload."; continue; }
            if (($f['size'][$i] ?? 0) > 8 * 1024 * 1024) { $res['errors'][] = "$name: plik > 8 MB."; continue; }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $raw = (string)file_get_contents($f['tmp_name'][$i]);
            $fallbackTitle = pathinfo($name, PATHINFO_FILENAME);

            if ($ext === 'json') {
                import_json_text($raw, $overwrite, $status, $res);
            } elseif ($ext === 'csv') {
                import_csv_file($f['tmp_name'][$i], $overwrite, $status, $res);
            } elseif (in_array($ext, ['md', 'markdown', 'txt'], true)) {
                $res['total']++;
                imp_save(['title' => imp_title_from_markdown($raw, $fallbackTitle), 'content' => $raw, 'contentFormat' => 'markdown', 'status' => $status], $overwrite, $res);
            } elseif (in_array($ext, ['html', 'htm'], true)) {
                $res['total']++;
                // jesli pelny dokument, wez tylko <body>
                if (preg_match('/<body[^>]*>(.*)<\/body>/is', $raw, $m)) $body = $m[1]; else $body = $raw;
                imp_save(['title' => imp_title_from_html($raw, $fallbackTitle), 'content' => $body, 'contentFormat' => 'html', 'status' => $status], $overwrite, $res);
            } else {
                $res['errors'][] = "$name: nieobslugiwany typ (.$ext). Uzyj MD, HTML, JSON lub CSV.";
            }
        }
    }
}

<?php
// admin/lib/upload.php — bezpieczny upload obrazow
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function uploads_dir(): string {
    $d = project_root() . '/img/blog';
    if (!is_dir($d)) mkdir($d, 0775, true);
    // Wylacz wykonywanie skryptow w katalogu uploadow (defense-in-depth)
    $ht = $d . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "php_flag engine off\nRemoveHandler .php .phtml .phar .cgi .pl .py .sh\nAddType text/plain .php .phtml .phar\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\nOptions -ExecCGI -Indexes\n<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options \"nosniff\"\n</IfModule>\n");
    }
    return $d;
}

function handle_image_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE  => 'Plik za duzy (limit serwera).',
            UPLOAD_ERR_FORM_SIZE => 'Plik za duzy.',
            UPLOAD_ERR_PARTIAL   => 'Plik wyslany tylko czesciowo.',
            UPLOAD_ERR_NO_FILE   => 'Brak pliku.',
            UPLOAD_ERR_NO_TMP_DIR=> 'Brak folderu tymczasowego na serwerze.',
            UPLOAD_ERR_CANT_WRITE=> 'Nie udalo sie zapisac pliku.',
            UPLOAD_ERR_EXTENSION => 'Rozszerzenie zablokowane.',
        ];
        throw new RuntimeException($errors[$file['error']] ?? 'Nieznany blad uploadu.');
    }

    if ($file['size'] > 12 * 1024 * 1024) {
        throw new RuntimeException('Plik wiekszy niz 12 MB. Zmniejsz zdjecie i sprobuj ponownie.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Nieprawidlowy upload.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Niedozwolony typ pliku. Dozwolone: JPG, PNG, WEBP, GIF.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('Plik nie jest poprawnym obrazem.');
    }

    $ext  = $allowed[$mime];
    $base = slugify(pathinfo($file['name'] ?? 'zdjecie', PATHINFO_FILENAME) ?: 'zdjecie');
    $name = $base . '-' . bin2hex(random_bytes(4)) . '.' . $ext;

    $dest = uploads_dir() . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Nie udalo sie zapisac pliku na serwerze.');
    }
    @chmod($dest, 0644);

    return ['url' => '/img/blog/' . $name, 'width' => $info[0], 'height' => $info[1]];
}

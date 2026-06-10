# Blog + panel (CMS) — krystiangrzyb.pl

Lekki CMS na plikach (bez bazy danych) dla hostingu lh.pl. Inspirowany ukladem
panelu tekkologic, napisany od zera pod tę stronę.

## Jak to działa

- **`/admin/`** — panel (PHP). Logowanie sesyjne, CSRF, limit prób logowania,
  hasło hashowane (`password_hash`). Pierwsze wejście = założenie konta administratora.
- **`/blog`** i **`/blog/<slug>`** — publiczny blog renderowany przez `blog.php`
  (ten sam nagłówek/stopka/fonty co reszta strony).
- Posty to pliki JSON w `admin/data/posts/<slug>.json` (źródło prawdy).
- Treść pisana w prostym markdownie (`## nagłówek`, `**pogrubienie**`, `*kursywa*`,
  `- lista`, `> cytat`, `[link](url)`, `![alt](url)`). Renderowana bezpiecznie
  (escape + tylko znane tagi = brak XSS).
- Zdjęcia trafiają do `/img/blog/` (walidacja MIME + `getimagesize`, losowa nazwa).

## Pliki

```
admin/
  index.php        router + widoki panelu (logowanie, lista, edytor)
  style.css        styl panelu
  .htaccess        wymusza HTTPS, blokuje lib/ i data/
  lib/
    helpers.php    h(), slugify(), render_markdown(), daty
    auth.php       sesje, CSRF, rate-limit, konta
    posts.php      CRUD postów (pliki JSON)
    upload.php     bezpieczny upload obrazów
  data/
    .htaccess      "Require all denied" (twardo blokuje dostęp z weba)
    users.json     konto (tworzone na serwerze; w .gitignore)
    posts/*.json   posty (tworzone na serwerze; w .gitignore)
```

## Deploy

- Strona statyczna: `npm run deploy:ftp` (nakładka — NIE czyści katalogu,
  więc posty i konto przeżywają deploy).
- Kod panelu + blog: ustaw `FTP_CMS=1` i odpal deploy (wgrywa też `cms/`).
- `FTP_WIPE=1` = pełne czyszczenie katalogu (NISZCZY dane bloga — używać świadomie).

Aktywny katalog WWW domeny to folder po starym WordPressie
(`public_html/autoinstalator/krystiangrzyb.pl/wordpress163355`), patrz `ftp-deploy.js`.

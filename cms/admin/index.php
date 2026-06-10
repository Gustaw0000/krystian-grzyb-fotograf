<?php
// admin/index.php — panel bloga Krystian Grzyb Fotografia
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/posts.php';
require_once __DIR__ . '/lib/upload.php';
require_once __DIR__ . '/lib/import.php';

auth_init();
send_security_headers();

$action = (string)($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

function flash(string $t, string $m): void { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function redirect(string $to): void { header('Location: ' . $to); exit; }

/* ===== PIERWSZE URUCHOMIENIE ===== */
if (!has_users()) {
    if ($isPost && $action === 'setup') {
        csrf_check();
        try {
            create_user((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            login_attempt((string)$_POST['username'], (string)$_POST['password']);
            flash('ok', 'Konto utworzone. Witaj w panelu!');
            redirect('/admin/');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    render_layout('Pierwsze uruchomienie', view_setup($error ?? null), false);
    exit;
}

/* ===== LOGOWANIE ===== */
if ($action === 'login') {
    if (is_logged_in()) redirect('/admin/');
    if ($isPost) {
        csrf_check();
        if (rate_limit_remaining(client_ip()) > 0) {
            $error = 'Za duzo prob logowania. Sprobuj za ' . ceil(rate_limit_remaining(client_ip()) / 60) . ' min.';
        } elseif (login_attempt((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            redirect('/admin/');
        } else { $error = 'Bledny login lub haslo.'; }
    }
    render_layout('Logowanie', view_login($error ?? null), false);
    exit;
}
if ($action === 'logout') {
    if ($isPost) { csrf_check(); logout(); }
    redirect('/admin/?action=login');
}

/* ===== WYMAGANE LOGOWANIE PONIZEJ ===== */
require_login();

/* Upload obrazu (AJAX JSON) */
if ($action === 'upload' && $isPost) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check();
        $res = handle_image_upload($_FILES['file'] ?? $_FILES['image'] ?? []);
        echo json_encode(['ok' => true] + $res);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

/* Zapis postu */
if ($action === 'save' && $isPost) {
    csrf_check();
    try {
        $orig = trim($_POST['originalSlug'] ?? '') !== '' ? $_POST['originalSlug'] : null;
        $post = save_post($_POST, $orig);
        flash('ok', 'Zapisano: ' . $post['title']);
        redirect('/admin/?action=edit&slug=' . urlencode($post['slug']));
    } catch (Throwable $e) {
        render_layout('Edycja postu', view_edit($_POST, $e->getMessage()), true);
        exit;
    }
}

/* Kosz: do kosza / przywroc / usun na zawsze */
if ($action === 'archive' && $isPost) {
    csrf_check();
    $ok = archive_post((string)($_POST['slug'] ?? ''));
    flash($ok ? 'ok' : 'err', $ok ? 'Przeniesiono do kosza.' : 'Nie znaleziono postu.');
    redirect('/admin/');
}
if ($action === 'restore' && $isPost) {
    csrf_check();
    try { $p = restore_post((string)($_POST['file'] ?? '')); flash('ok', 'Przywrocono: ' . $p['title']); }
    catch (Throwable $e) { flash('err', $e->getMessage()); }
    redirect('/admin/?action=trash');
}
if ($action === 'purge' && $isPost) {
    csrf_check();
    flash(purge_post((string)($_POST['file'] ?? '')) ? 'ok' : 'err', 'Usunieto na zawsze.');
    redirect('/admin/?action=trash');
}
if ($action === 'duplicate' && $isPost) {
    csrf_check();
    $c = duplicate_post((string)($_POST['slug'] ?? ''));
    if ($c) { flash('ok', 'Utworzono kopie (szkic).'); redirect('/admin/?action=edit&slug=' . urlencode($c['slug'])); }
    flash('err', 'Nie znaleziono postu.'); redirect('/admin/');
}

/* Import */
if ($action === 'import') {
    if ($isPost) {
        csrf_check();
        $res = import_result_new();
        try { run_import($_POST, $_FILES, $res); }
        catch (Throwable $e) { $res['errors'][] = 'Blad importu: ' . $e->getMessage(); }
        render_layout('Wynik importu', view_import($res), true);
        exit;
    }
    render_layout('Import postow', view_import(null), true);
    exit;
}

/* Podglad (takze szkice — tylko zalogowany) */
if ($action === 'preview') {
    $post = load_post((string)($_GET['slug'] ?? ''));
    if (!$post) { flash('err', 'Nie znaleziono postu.'); redirect('/admin/'); }
    require_once __DIR__ . '/../blog-render.php';
    blog_render_post($post, true);
    exit;
}

/* Nowy / edycja */
if ($action === 'new') { render_layout('Nowy post', view_edit(['date' => date('Y-m-d'), 'status' => 'published'], null), true); exit; }
if ($action === 'edit') {
    $post = load_post((string)($_GET['slug'] ?? ''));
    if (!$post) { flash('err', 'Nie znaleziono postu.'); redirect('/admin/'); }
    render_layout('Edycja: ' . $post['title'], view_edit($post, null), true);
    exit;
}
if ($action === 'trash') { render_layout('Kosz', view_trash(list_trashed()), true); exit; }

/* Pulpit (lista + szukajka + filtr) */
$q = trim((string)($_GET['q'] ?? ''));
$statusF = (string)($_GET['status'] ?? '');
$posts = list_posts();
if ($q !== '') $posts = array_values(array_filter($posts, fn($p) => mb_stripos($p['title'] ?? '', $q) !== false));
if (in_array($statusF, ['published', 'draft'], true)) $posts = array_values(array_filter($posts, fn($p) => ($p['status'] ?? 'published') === $statusF));
render_layout('Twoje posty', view_list($posts, $q, $statusF), true);


/* ============================== WIDOKI ============================== */
function render_layout(string $title, string $body, bool $authed): void {
    $flash = take_flash();
    $u = current_user();
    ?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · Panel Krystian Grzyb</title>
<link rel="icon" href="/favicon.png" type="image/png">
<link rel="stylesheet" href="/fonts/fonts.css">
<link rel="stylesheet" href="/admin/style.css?v=3">
</head>
<body>
<?php if ($authed): ?>
<header class="adm-top">
  <a href="/admin/" class="adm-brand">Panel bloga</a>
  <nav class="adm-topnav">
    <a href="/admin/">Posty</a>
    <a href="/admin/?action=new">Nowy post</a>
    <a href="/admin/?action=import">Import</a>
    <a href="/admin/?action=trash">Kosz</a>
    <a href="/blog" target="_blank" rel="noopener">Zobacz blog ↗</a>
    <span class="adm-user"><?= h((string)$u) ?></span>
    <form method="post" action="/admin/?action=logout" class="adm-logout-form"><?= csrf_field() ?><button type="submit" class="adm-logout">Wyloguj</button></form>
  </nav>
</header>
<?php endif; ?>
<main class="adm-main<?= $authed ? '' : ' adm-main-center' ?>">
  <?php if ($flash): ?><div class="adm-flash adm-flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
  <?= $body ?>
</main>
</body>
</html><?php
}

function view_setup(?string $error): string {
    ob_start(); ?>
  <div class="adm-card adm-auth">
    <h1>Witaj 👋</h1>
    <p class="adm-muted">Zaloz konto administratora bloga. Tylko Ty bedziesz mial dostep do panelu.</p>
    <?php if ($error): ?><div class="adm-alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="/admin/?action=setup">
      <?= csrf_field() ?>
      <label>Login<input type="text" name="username" required minlength="3" autocomplete="username" autofocus></label>
      <label>Haslo (min. 8 znakow)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <button class="adm-btn" type="submit">Zaloz konto</button>
    </form>
  </div>
<?php return (string)ob_get_clean();
}

function view_login(?string $error): string {
    ob_start(); ?>
  <div class="adm-card adm-auth">
    <h1>Panel bloga</h1>
    <p class="adm-muted">Zaloguj sie, zeby dodawac i edytowac wpisy.</p>
    <?php if ($error): ?><div class="adm-alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="/admin/?action=login">
      <?= csrf_field() ?>
      <label>Login<input type="text" name="username" required autocomplete="username" autofocus></label>
      <label>Haslo<input type="password" name="password" required autocomplete="current-password"></label>
      <button class="adm-btn" type="submit">Zaloguj</button>
    </form>
  </div>
<?php return (string)ob_get_clean();
}

function view_list(array $posts, string $q, string $statusF): string {
    ob_start(); ?>
  <div class="adm-head">
    <h1>Twoje posty</h1>
    <a href="/admin/?action=new" class="adm-btn">+ Nowy post</a>
  </div>
  <form class="adm-filters" method="get" action="/admin/">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Szukaj po tytule...">
    <select name="status" onchange="this.form.submit()">
      <option value="">Wszystkie</option>
      <option value="published" <?= $statusF === 'published' ? 'selected' : '' ?>>Opublikowane</option>
      <option value="draft" <?= $statusF === 'draft' ? 'selected' : '' ?>>Szkice</option>
    </select>
    <button class="adm-btn adm-btn-sm" type="submit">Szukaj</button>
    <?php if ($q !== '' || $statusF !== ''): ?><a class="adm-link" href="/admin/">Wyczysc</a><?php endif; ?>
  </form>
  <?php if (!$posts): ?>
    <div class="adm-empty"><p>Brak postow<?= ($q !== '' || $statusF !== '') ? ' dla tego filtra' : '' ?>.</p><a href="/admin/?action=new" class="adm-btn">Napisz pierwszy post</a></div>
  <?php else: ?>
    <div class="adm-list">
      <?php foreach ($posts as $p): ?>
        <article class="adm-row">
          <?php if (!empty($p['cover'])): ?><img class="adm-thumb" src="<?= h($p['cover']) ?>" alt=""><?php else: ?><span class="adm-thumb adm-thumb-empty">KG</span><?php endif; ?>
          <div class="adm-row-main">
            <a class="adm-row-title" href="/admin/?action=edit&slug=<?= urlencode($p['slug']) ?>"><?= h($p['title']) ?></a>
            <div class="adm-row-meta">
              <?= h(format_date_pl($p['date'] ?? '')) ?>
              <?php if (($p['status'] ?? 'published') === 'draft'): ?><span class="adm-badge">szkic</span><?php else: ?><span class="adm-badge adm-badge-ok">opublikowany</span><?php endif; ?>
              · <?= (int)($p['readTime'] ?? 1) ?> min
            </div>
          </div>
          <div class="adm-row-actions">
            <a class="adm-link" href="/admin/?action=preview&slug=<?= urlencode($p['slug']) ?>" target="_blank" rel="noopener">Podglad</a>
            <a class="adm-link" href="/admin/?action=edit&slug=<?= urlencode($p['slug']) ?>">Edytuj</a>
            <form method="post" action="/admin/?action=duplicate"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= h($p['slug']) ?>"><button class="adm-link" type="submit">Duplikuj</button></form>
            <form method="post" action="/admin/?action=archive" onsubmit="return confirm('Przeniesc do kosza?');"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= h($p['slug']) ?>"><button class="adm-link adm-link-danger" type="submit">Do kosza</button></form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php return (string)ob_get_clean();
}

function view_trash(array $trashed): string {
    ob_start(); ?>
  <div class="adm-head"><h1>Kosz</h1><a href="/admin/" class="adm-link">&larr; Wroc do postow</a></div>
  <?php if (!$trashed): ?>
    <div class="adm-empty"><p>Kosz jest pusty.</p></div>
  <?php else: ?>
    <p class="adm-muted" style="margin-bottom:1rem">Posty w koszu nie sa widoczne na blogu. Mozesz je przywrocic lub usunac na zawsze.</p>
    <div class="adm-list">
      <?php foreach ($trashed as $p): ?>
        <article class="adm-row">
          <?php if (!empty($p['cover'])): ?><img class="adm-thumb" src="<?= h($p['cover']) ?>" alt=""><?php else: ?><span class="adm-thumb adm-thumb-empty">KG</span><?php endif; ?>
          <div class="adm-row-main">
            <span class="adm-row-title"><?= h($p['title']) ?></span>
            <div class="adm-row-meta">w koszu od <?= h(format_date_pl(substr($p['_trashedAt'] ?? '', 0, 10))) ?></div>
          </div>
          <div class="adm-row-actions">
            <form method="post" action="/admin/?action=restore"><?= csrf_field() ?><input type="hidden" name="file" value="<?= h($p['_trashFile']) ?>"><button class="adm-link" type="submit">Przywroc</button></form>
            <form method="post" action="/admin/?action=purge" onsubmit="return confirm('Usunac NA ZAWSZE? Tego nie da sie cofnac.');"><?= csrf_field() ?><input type="hidden" name="file" value="<?= h($p['_trashFile']) ?>"><button class="adm-link adm-link-danger" type="submit">Usun na zawsze</button></form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php return (string)ob_get_clean();
}

function view_import(?array $result): string {
    ob_start();
    if ($result !== null):
        $i = count($result['imported']); $s = count($result['skipped']); $e = count($result['errors']); ?>
    <div class="adm-head"><h1>Wynik importu</h1><a href="/admin/" class="adm-link">&larr; Posty</a></div>
    <div class="adm-import-stats">
      <div class="adm-stat ok"><b><?= $i ?></b><span>zaimportowano</span></div>
      <div class="adm-stat warn"><b><?= $s ?></b><span>pominieto</span></div>
      <div class="adm-stat err"><b><?= $e ?></b><span>bledow</span></div>
      <div class="adm-stat"><b><?= (int)$result['total'] ?></b><span>rekordow</span></div>
    </div>
    <?php if ($i): ?><div class="adm-box"><b>Zaimportowane:</b><ul class="adm-implist"><?php foreach ($result['imported'] as $sl): ?><li><a href="/admin/?action=edit&slug=<?= urlencode($sl) ?>"><?= h($sl) ?></a> · <a href="/blog/<?= h($sl) ?>" target="_blank" rel="noopener">/blog/<?= h($sl) ?> ↗</a></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($s): ?><div class="adm-box"><b>Pominiete (duplikaty):</b><ul class="adm-implist"><?php foreach ($result['skipped'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul><p class="adm-muted">Zaznacz „Nadpisz istniejace", zeby je zastapic.</p></div><?php endif; ?>
    <?php if ($e): ?><div class="adm-box"><b>Bledy:</b><ul class="adm-implist adm-implist-err"><?php foreach ($result['errors'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <a href="/admin/?action=import" class="adm-btn">Importuj kolejne</a>
  <?php else: ?>
    <div class="adm-head"><h1>Import postow</h1><a href="/admin/" class="adm-link">&larr; Posty</a></div>
    <form method="post" action="/admin/?action=import" enctype="multipart/form-data" class="adm-form-import">
      <?= csrf_field() ?>
      <div class="adm-box">
        <span class="adm-box-title">Opcja A — wgraj pliki</span>
        <p class="adm-muted">Obslugiwane: <code>.md</code> / <code>.markdown</code> / <code>.txt</code> (Markdown), <code>.html</code> (HTML), <code>.json</code> (pojedynczy wpis lub lista), <code>.csv</code> (kolumny: title, content, date, description, tags...). Mozesz wgrac wiele plikow naraz.</p>
        <input type="file" name="files[]" multiple accept=".md,.markdown,.txt,.html,.htm,.json,.csv">
      </div>
      <div class="adm-box">
        <span class="adm-box-title">Opcja B — wklej tresc</span>
        <label class="adm-field">Tytul<input type="text" name="paste_title" placeholder="Tytul wpisu"></label>
        <label class="adm-field">Format
          <select name="paste_format"><option value="html">HTML</option><option value="markdown">Markdown</option></select>
        </label>
        <label class="adm-field">Tresc<textarea name="paste_content" rows="8" placeholder="Wklej tu HTML lub Markdown..."></textarea></label>
      </div>
      <div class="adm-box adm-import-opts">
        <label class="adm-check"><input type="checkbox" name="overwrite" value="1"> Nadpisz istniejace (po slug)</label>
        <label class="adm-field" style="max-width:220px">Status importowanych
          <select name="status"><option value="published">Opublikowane</option><option value="draft">Szkice</option></select>
        </label>
      </div>
      <button class="adm-btn" type="submit">Importuj</button>
    </form>
  <?php endif;
    return (string)ob_get_clean();
}

function view_edit(array $p, ?string $error): string {
    $isNew = empty($p['slug']) && empty($p['originalSlug']);
    $slug = $p['slug'] ?? ($p['originalSlug'] ?? '');
    ob_start(); ?>
  <div class="adm-head">
    <h1><?= $isNew ? 'Nowy post' : 'Edycja postu' ?></h1>
    <a href="/admin/" class="adm-link">&larr; Wroc do listy</a>
  </div>
  <?php if ($error): ?><div class="adm-alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="/admin/?action=save" class="adm-form" id="postForm">
    <?= csrf_field() ?>
    <input type="hidden" name="originalSlug" value="<?= h($p['originalSlug'] ?? $p['slug'] ?? '') ?>">
    <div class="adm-grid">
      <div class="adm-col-main">
        <label class="adm-field">Tytul
          <input type="text" name="title" id="titleInput" required value="<?= h($p['title'] ?? '') ?>" placeholder="np. Jak przygotowac salę na osiemnastkę">
        </label>
        <div class="adm-field">
          <span>Tresc <span class="adm-hint">Pisz jak w Wordzie. Pogrubienie, nagłówki, listy, cytaty, linki i zdjęcia z paska u góry. Zdjęcie wstawisz też przez Ctrl+V.</span></span>
          <div class="adm-editor-meta"><span id="wc">0 słów</span> · <span id="rt">~1 min</span></div>
          <div class="adm-editor-wrap">
            <textarea id="content" name="content" class="adm-editor-textarea" placeholder="Pisz tutaj..."><?= h($p['content'] ?? '') ?></textarea>
            <div id="editor" hidden></div>
          </div>
        </div>
      </div>
      <aside class="adm-col-side">
        <div class="adm-box">
          <button class="adm-btn adm-btn-wide" type="submit">Zapisz post</button>
          <?php if (!$isNew): ?><a class="adm-btn adm-btn-ghost adm-btn-wide adm-mt" href="/admin/?action=preview&slug=<?= urlencode($slug) ?>" target="_blank" rel="noopener">Podgląd ↗</a><?php endif; ?>
          <label class="adm-field adm-mt">Status
            <select name="status"><option value="published" <?= ($p['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Opublikowany</option><option value="draft" <?= ($p['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Szkic (niewidoczny)</option></select>
          </label>
          <label class="adm-field">Data<input type="date" name="date" value="<?= h($p['date'] ?? date('Y-m-d')) ?>"></label>
          <label class="adm-field">Slug (adres URL)
            <input type="text" name="slug" id="slugInput" value="<?= h($p['slug'] ?? '') ?>" pattern="[a-z0-9-]+" placeholder="auto z tytulu">
          </label>
        </div>
        <div class="adm-box">
          <span class="adm-box-title">Zdjecie glowne</span>
          <div class="adm-cover"><img id="coverPreview" src="<?= h($p['cover'] ?? '') ?>" alt="" <?= empty($p['cover']) ? 'hidden' : '' ?>><p class="adm-muted" id="coverEmpty" <?= empty($p['cover']) ? '' : 'hidden' ?>>Brak zdjecia</p></div>
          <input type="hidden" name="cover" id="cover" value="<?= h($p['cover'] ?? '') ?>">
          <input type="file" id="coverFile" accept="image/*" hidden>
          <button type="button" class="adm-btn adm-btn-ghost adm-btn-wide" id="coverBtn">Wgraj zdjecie</button>
          <label class="adm-field adm-mt">Opis zdjecia (alt)<input type="text" name="coverAlt" value="<?= h($p['coverAlt'] ?? '') ?>" placeholder="co przedstawia zdjecie"></label>
        </div>
        <div class="adm-box">
          <label class="adm-field">Krotki opis (SEO)<textarea name="description" rows="3" maxlength="200" placeholder="1-2 zdania do Google"><?= h($p['description'] ?? '') ?></textarea></label>
          <label class="adm-field">Tagi (po przecinku)<input type="text" name="tags" value="<?= h(is_array($p['tags'] ?? null) ? implode(', ', $p['tags']) : ($p['tags'] ?? '')) ?>" placeholder="osiemnastka, reportaz"></label>
        </div>
      </aside>
    </div>
  </form>
  <link href="/admin/vendor/quill/quill.snow.css?v=2.0.3" rel="stylesheet">
  <script src="/admin/vendor/quill/quill.js?v=2.0.3"></script>
  <script nonce="<?= h(cms_nonce()) ?>">
  (function(){
    var csrf = <?= json_encode(csrf_token()) ?>;
    var ta = document.getElementById('content');
    var form = document.getElementById('postForm');
    var wc = document.getElementById('wc'), rt = document.getElementById('rt');
    var quill = null;

    function stats(text){
      var words = text ? text.split(/\s+/).filter(Boolean).length : 0;
      wc.textContent = words + ' słów';
      rt.textContent = '~' + Math.max(1, Math.ceil(words/200)) + ' min';
    }
    function strip(html){ var d=document.createElement('div'); d.innerHTML=html||''; return (d.textContent||'').trim(); }

    function upload(file, cb){
      if(!file || !file.type.startsWith('image/')){ alert('To nie jest obraz.'); return; }
      var fd=new FormData(); fd.append('file',file); fd.append('csrf',csrf);
      fetch('/admin/?action=upload',{method:'POST',body:fd,headers:{'X-CSRF-Token':csrf}})
        .then(function(r){return r.json();})
        .then(function(j){ if(j.ok) cb(j.url); else alert('Blad: '+(j.error||'upload')); })
        .catch(function(){ alert('Nie udalo sie wyslac pliku.'); });
    }

    function initQuill(){
      if (typeof Quill === 'undefined') return false;
      document.getElementById('editor').innerHTML = ta.value;
      document.getElementById('editor').hidden = false;
      ta.style.display = 'none';
      quill = new Quill('#editor', {
        theme:'snow',
        modules:{ toolbar:{ container:[
          [{header:[2,3,4,false]}],
          ['bold','italic','underline','strike'],
          [{color:[]},{background:[]}],
          ['blockquote','code-block'],
          [{list:'ordered'},{list:'bullet'}],
          [{align:[]}],
          ['link','image'],
          ['clean']
        ], handlers:{ image:function(){
          var inp=document.createElement('input'); inp.type='file'; inp.accept='image/*'; inp.click();
          inp.onchange=function(){ if(inp.files[0]) upload(inp.files[0], function(url){ var r=quill.getSelection(true); quill.insertEmbed(r.index,'image',url,'user'); quill.setSelection(r.index+1); }); };
        }}}},
        placeholder:'Pisz tutaj...'
      });
      quill.on('text-change', function(){ stats(quill.getText().trim()); });
      quill.root.addEventListener('paste', function(e){
        var items=(e.clipboardData||e.originalEvent.clipboardData).items;
        for (var i=0;i<items.length;i++){ if(items[i].type.indexOf('image')===0){ e.preventDefault(); upload(items[i].getAsFile(), function(url){ var r=quill.getSelection(true); quill.insertEmbed(r.index,'image',url,'user'); quill.setSelection(r.index+1); }); return; } }
      });
      stats(quill.getText().trim());
      return true;
    }
    if(!initQuill()){ ta.addEventListener('input', function(){ stats(strip(ta.value)); }); stats(strip(ta.value)); window.addEventListener('load', function(){ if(!quill) initQuill(); }); }

    // cover upload
    var coverBtn=document.getElementById('coverBtn'), coverFile=document.getElementById('coverFile');
    coverBtn.addEventListener('click', function(){ coverFile.click(); });
    coverFile.addEventListener('change', function(){ if(!coverFile.files[0]) return; coverBtn.textContent='Wgrywam...'; upload(coverFile.files[0], function(url){ document.getElementById('cover').value=url; var img=document.getElementById('coverPreview'); img.src=url; img.hidden=false; document.getElementById('coverEmpty').hidden=true; coverBtn.textContent='Zmien zdjecie'; }); });

    // auto-slug
    var titleInput=document.getElementById('titleInput'), slugInput=document.getElementById('slugInput');
    var slugEdited = !!slugInput.value;
    function slugify(s){ var m={'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ż':'z','ź':'z'}; return s.toLowerCase().replace(/[ąćęłńóśżź]/g,function(c){return m[c]||c;}).replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
    titleInput.addEventListener('input', function(){ if(!slugEdited) slugInput.value=slugify(titleInput.value); });
    slugInput.addEventListener('input', function(){ slugEdited=true; });

    // mirror Quill -> textarea on submit
    form.addEventListener('submit', function(e){
      if (quill){ var html=quill.root.innerHTML.trim(); if(!html||html==='<p><br></p>'){ e.preventDefault(); alert('Tresc nie moze byc pusta.'); return; } ta.value=html; }
    });
  })();
  </script>
<?php return (string)ob_get_clean();
}

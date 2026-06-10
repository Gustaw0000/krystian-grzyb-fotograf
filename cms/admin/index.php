<?php
// admin/index.php — panel bloga Krystian Grzyb Fotografia
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/posts.php';
require_once __DIR__ . '/lib/upload.php';

auth_init();

$action = (string)($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function redirect(string $to): void { header('Location: ' . $to); exit; }

/* ============================ PIERWSZE URUCHOMIENIE ======================== */
if (!has_users()) {
    if ($isPost && $action === 'setup') {
        csrf_check();
        try {
            create_user((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            login_attempt((string)$_POST['username'], (string)$_POST['password']);
            flash('ok', 'Konto utworzone. Witaj w panelu!');
            redirect('/admin/');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    render_layout('Pierwsze uruchomienie', view_setup($error ?? null), false);
    exit;
}

/* ================================ LOGOWANIE =============================== */
if ($action === 'login') {
    if (is_logged_in()) redirect('/admin/');
    if ($isPost) {
        csrf_check();
        $remaining = rate_limit_remaining(client_ip());
        if ($remaining > 0) {
            $error = 'Za duzo prob logowania. Sprobuj za ' . ceil($remaining / 60) . ' min.';
        } elseif (login_attempt((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            redirect('/admin/');
        } else {
            $error = 'Bledny login lub haslo.';
        }
    }
    render_layout('Logowanie', view_login($error ?? null), false);
    exit;
}

if ($action === 'logout') {
    logout();
    redirect('/admin/?action=login');
}

/* ===================== OD TEGO MIEJSCA WYMAGANE LOGOWANIE ================= */
require_login();

/* -------- Upload obrazu (AJAX, zwraca JSON) -------- */
if ($action === 'upload' && $isPost) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check();
        $res = handle_image_upload($_FILES['image'] ?? []);
        echo json_encode(['ok' => true] + $res);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* -------- Zapis postu -------- */
if ($action === 'save' && $isPost) {
    csrf_check();
    try {
        $orig = trim($_POST['originalSlug'] ?? '') !== '' ? $_POST['originalSlug'] : null;
        $post = save_post($_POST, $orig);
        flash('ok', 'Zapisano post: ' . $post['title']);
        redirect('/admin/?action=edit&slug=' . urlencode($post['slug']));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $draft = $_POST;
        render_layout('Edycja postu', view_edit($draft, $error), true);
        exit;
    }
}

/* -------- Usuwanie -------- */
if ($action === 'delete' && $isPost) {
    csrf_check();
    $slug = (string)($_POST['slug'] ?? '');
    if (delete_post($slug)) flash('ok', 'Post usuniety.');
    else flash('err', 'Nie znaleziono postu.');
    redirect('/admin/');
}

/* -------- Nowy / edycja -------- */
if ($action === 'new') {
    render_layout('Nowy post', view_edit(['date' => date('Y-m-d'), 'status' => 'published'], null), true);
    exit;
}
if ($action === 'edit') {
    $post = load_post((string)($_GET['slug'] ?? ''));
    if (!$post) { flash('err', 'Nie znaleziono postu.'); redirect('/admin/'); }
    render_layout('Edycja: ' . $post['title'], view_edit($post, null), true);
    exit;
}

/* -------- Pulpit (lista) -------- */
render_layout('Twoje posty', view_list(list_posts()), true);


/* =============================== WIDOKI =================================== */

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
<link rel="stylesheet" href="/admin/style.css">
</head>
<body>
<?php if ($authed): ?>
<header class="adm-top">
  <a href="/admin/" class="adm-brand">Panel bloga</a>
  <nav class="adm-topnav">
    <a href="/admin/">Posty</a>
    <a href="/admin/?action=new" class="adm-btn adm-btn-sm">Nowy post</a>
    <a href="/blog" target="_blank" rel="noopener">Zobacz blog</a>
    <span class="adm-user"><?= h((string)$u) ?></span>
    <a href="/admin/?action=logout" class="adm-logout">Wyloguj</a>
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

function view_list(array $posts): string {
    ob_start(); ?>
  <div class="adm-head">
    <h1>Twoje posty</h1>
    <a href="/admin/?action=new" class="adm-btn">+ Nowy post</a>
  </div>
  <?php if (!$posts): ?>
    <div class="adm-empty">
      <p>Nie masz jeszcze zadnego wpisu.</p>
      <a href="/admin/?action=new" class="adm-btn">Napisz pierwszy post</a>
    </div>
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
              · <?= (int)($p['readTime'] ?? 1) ?> min czytania
            </div>
          </div>
          <div class="adm-row-actions">
            <a class="adm-link" href="/blog/<?= h($p['slug']) ?>" target="_blank" rel="noopener">Podglad</a>
            <a class="adm-link" href="/admin/?action=edit&slug=<?= urlencode($p['slug']) ?>">Edytuj</a>
            <form method="post" action="/admin/?action=delete" onsubmit="return confirm('Usunac ten post na zawsze?');">
              <?= csrf_field() ?>
              <input type="hidden" name="slug" value="<?= h($p['slug']) ?>">
              <button class="adm-link adm-link-danger" type="submit">Usun</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php return (string)ob_get_clean();
}

function view_edit(array $p, ?string $error): string {
    $isNew = empty($p['slug']);
    ob_start(); ?>
  <div class="adm-head">
    <h1><?= $isNew ? 'Nowy post' : 'Edycja postu' ?></h1>
    <a href="/admin/" class="adm-link">&larr; Wroc do listy</a>
  </div>
  <?php if ($error): ?><div class="adm-alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="/admin/?action=save" class="adm-form" id="postForm">
    <?= csrf_field() ?>
    <input type="hidden" name="originalSlug" value="<?= h($p['slug'] ?? '') ?>">
    <div class="adm-grid">
      <div class="adm-col-main">
        <label class="adm-field">Tytul
          <input type="text" name="title" required value="<?= h($p['title'] ?? '') ?>" placeholder="np. Jak przygotowac salę na osiemnastkę">
        </label>

        <label class="adm-field">Tresc
          <span class="adm-hint">Formatowanie: <code>## Naglowek</code>, <code>**pogrubienie**</code>, <code>*kursywa*</code>, <code>- lista</code>, <code>&gt; cytat</code>, <code>[link](https://...)</code>. Pusta linia = nowy akapit.</span>
          <div class="adm-toolbar">
            <button type="button" class="adm-tool" data-wrap="**">Pogrubienie</button>
            <button type="button" class="adm-tool" data-wrap="*">Kursywa</button>
            <button type="button" class="adm-tool" data-prefix="## ">Naglowek</button>
            <button type="button" class="adm-tool" data-prefix="- ">Lista</button>
            <button type="button" class="adm-tool" data-prefix="&gt; ">Cytat</button>
            <button type="button" class="adm-tool" id="insertImgBtn">+ Zdjecie w tresci</button>
            <input type="file" id="inlineImg" accept="image/*" hidden>
          </div>
          <textarea name="content" id="content" rows="18" required placeholder="Napisz swoj wpis..."><?= h($p['content'] ?? '') ?></textarea>
        </label>
      </div>

      <aside class="adm-col-side">
        <div class="adm-box">
          <button class="adm-btn adm-btn-wide" type="submit">Zapisz post</button>
          <label class="adm-field adm-mt">Status
            <select name="status">
              <option value="published" <?= ($p['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Opublikowany</option>
              <option value="draft" <?= ($p['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Szkic (niewidoczny)</option>
            </select>
          </label>
          <label class="adm-field">Data
            <input type="date" name="date" value="<?= h($p['date'] ?? date('Y-m-d')) ?>">
          </label>
        </div>

        <div class="adm-box">
          <span class="adm-box-title">Zdjecie glowne</span>
          <div class="adm-cover" id="coverBox">
            <img id="coverPreview" src="<?= h($p['cover'] ?? '') ?>" alt="" <?= empty($p['cover']) ? 'hidden' : '' ?>>
            <p class="adm-muted" id="coverEmpty" <?= empty($p['cover']) ? '' : 'hidden' ?>>Brak zdjecia</p>
          </div>
          <input type="hidden" name="cover" id="cover" value="<?= h($p['cover'] ?? '') ?>">
          <input type="file" id="coverFile" accept="image/*" hidden>
          <button type="button" class="adm-btn adm-btn-ghost adm-btn-wide" id="coverBtn">Wgraj zdjecie</button>
          <label class="adm-field adm-mt">Opis zdjecia (alt)
            <input type="text" name="coverAlt" value="<?= h($p['coverAlt'] ?? '') ?>" placeholder="co przedstawia zdjecie">
          </label>
        </div>

        <div class="adm-box">
          <label class="adm-field">Krotki opis (SEO)
            <textarea name="description" rows="3" maxlength="200" placeholder="1-2 zdania do Google i social media"><?= h($p['description'] ?? '') ?></textarea>
          </label>
          <label class="adm-field">Tagi (po przecinku)
            <input type="text" name="tags" value="<?= h(is_array($p['tags'] ?? null) ? implode(', ', $p['tags']) : ($p['tags'] ?? '')) ?>" placeholder="osiemnastka, reportaz">
          </label>
        </div>
      </aside>
    </div>
  </form>
  <script>
  (function(){
    var csrf = <?= json_encode(csrf_token()) ?>;
    var ta = document.getElementById('content');

    function wrapSel(before, after){
      var s=ta.selectionStart, e=ta.selectionEnd, v=ta.value;
      ta.value = v.slice(0,s) + before + v.slice(s,e) + after + v.slice(e);
      ta.focus(); ta.selectionStart = s + before.length; ta.selectionEnd = e + before.length;
    }
    function prefixLine(pfx){
      var s=ta.selectionStart, v=ta.value;
      var ls=v.lastIndexOf('\n',s-1)+1;
      ta.value = v.slice(0,ls) + pfx + v.slice(ls);
      ta.focus();
    }
    document.querySelectorAll('.adm-tool[data-wrap]').forEach(function(b){
      b.addEventListener('click', function(){ var w=b.getAttribute('data-wrap'); wrapSel(w,w); });
    });
    document.querySelectorAll('.adm-tool[data-prefix]').forEach(function(b){
      b.addEventListener('click', function(){ prefixLine(b.getAttribute('data-prefix').replace('&gt;','>')); });
    });

    function upload(file, cb){
      var fd = new FormData(); fd.append('image', file); fd.append('csrf', csrf);
      fetch('/admin/?action=upload', {method:'POST', body:fd})
        .then(function(r){return r.json();})
        .then(function(j){ if(j.ok) cb(j.url); else alert('Blad: '+j.error); })
        .catch(function(){ alert('Nie udalo sie wyslac pliku.'); });
    }

    var coverBtn=document.getElementById('coverBtn'), coverFile=document.getElementById('coverFile');
    coverBtn.addEventListener('click', function(){ coverFile.click(); });
    coverFile.addEventListener('change', function(){
      if(!coverFile.files[0]) return;
      coverBtn.textContent='Wgrywam...';
      upload(coverFile.files[0], function(url){
        document.getElementById('cover').value=url;
        var img=document.getElementById('coverPreview'); img.src=url; img.hidden=false;
        document.getElementById('coverEmpty').hidden=true; coverBtn.textContent='Zmien zdjecie';
      });
    });

    var insBtn=document.getElementById('insertImgBtn'), inl=document.getElementById('inlineImg');
    insBtn.addEventListener('click', function(){ inl.click(); });
    inl.addEventListener('change', function(){
      if(!inl.files[0]) return;
      insBtn.textContent='Wgrywam...';
      upload(inl.files[0], function(url){
        var s=ta.selectionStart, snippet='\n\n![zdjecie]('+url+')\n\n';
        ta.value = ta.value.slice(0,s) + snippet + ta.value.slice(s);
        insBtn.textContent='+ Zdjecie w tresci';
      });
    });
  })();
  </script>
<?php return (string)ob_get_clean();
}

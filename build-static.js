'use strict';

/*
 * Buduje statyczna wersje strony do katalogu dist/ pod hosting FTP (lh.pl).
 * - podstawia {{SITE_URL}} i {{LAST_MODIFIED}}
 * - kopiuje wszystkie assety z public/ (bez _audit/)
 * - generuje sitemap.xml, robots.txt, .htaccess (czyste adresy + 404 + cache)
 */

const fs = require('fs');
const path = require('path');

const SITE_URL = 'https://krystiangrzyb.pl';
const LAST_MODIFIED = '10 czerwca 2026';
const LASTMOD_ISO = '2026-06-10';

const SRC = path.join(__dirname, 'public');
const OUT = path.join(__dirname, 'dist');

const HTML_FILES = new Set(['index.html', 'polityka-prywatnosci.html', 'regulamin.html', '404.html']);
const SKIP_DIRS = new Set(['_audit']);

function rmrf(p) {
  if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true });
}

function copyTree(src, out) {
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    if (entry.isDirectory() && SKIP_DIRS.has(entry.name)) continue;
    const s = path.join(src, entry.name);
    const o = path.join(out, entry.name);
    if (entry.isDirectory()) {
      fs.mkdirSync(o, { recursive: true });
      copyTree(s, o);
    } else if (HTML_FILES.has(entry.name)) {
      let html = fs.readFileSync(s, 'utf8');
      html = html.replace(/\{\{SITE_URL\}\}/g, SITE_URL).replace(/\{\{LAST_MODIFIED\}\}/g, LAST_MODIFIED);
      fs.writeFileSync(o, html);
    } else {
      fs.copyFileSync(s, o);
    }
  }
}

rmrf(OUT);
fs.mkdirSync(OUT, { recursive: true });
copyTree(SRC, OUT);

// ---- sitemap.xml ----
const SITEMAP_PATHS = [
  { path: '/', priority: '1.0', changefreq: 'weekly' },
  { path: '/#galeria', priority: '0.8', changefreq: 'weekly' },
  { path: '/#uslugi', priority: '0.7', changefreq: 'monthly' },
  { path: '/#o-mnie', priority: '0.6', changefreq: 'monthly' },
  { path: '/#kontakt', priority: '0.7', changefreq: 'monthly' },
  { path: '/blog', priority: '0.7', changefreq: 'weekly' },
  { path: '/polityka-prywatnosci', priority: '0.3', changefreq: 'yearly' },
  { path: '/regulamin', priority: '0.3', changefreq: 'yearly' }
];
const sm = SITEMAP_PATHS.map(function (u) {
  const loc = SITE_URL + u.path;
  return '  <url>\n' +
    '    <loc>' + loc + '</loc>\n' +
    '    <lastmod>' + LASTMOD_ISO + '</lastmod>\n' +
    '    <changefreq>' + u.changefreq + '</changefreq>\n' +
    '    <priority>' + u.priority + '</priority>\n' +
    '    <xhtml:link rel="alternate" hreflang="pl-PL" href="' + loc + '"/>\n' +
    '    <xhtml:link rel="alternate" hreflang="x-default" href="' + loc + '"/>\n' +
    '  </url>';
}).join('\n');
fs.writeFileSync(path.join(OUT, 'sitemap.xml'),
  '<?xml version="1.0" encoding="UTF-8"?>\n' +
  '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n' +
  sm + '\n</urlset>\n');

// ---- robots.txt ----
fs.writeFileSync(path.join(OUT, 'robots.txt'),
  'User-agent: *\nAllow: /\n\n' +
  'User-agent: GPTBot\nAllow: /\n\n' +
  'User-agent: ClaudeBot\nAllow: /\n\n' +
  'User-agent: Google-Extended\nAllow: /\n\n' +
  'User-agent: PerplexityBot\nAllow: /\n\n' +
  'Sitemap: ' + SITE_URL + '/sitemap.xml\n');

// ---- .htaccess ----
const htaccess = [
  '# Krystian Grzyb Fotografia - konfiguracja hostingu statycznego',
  'Options -Indexes',
  'DirectoryIndex index.html',
  'ErrorDocument 404 /404.html',
  'AddDefaultCharset UTF-8',
  '',
  '<IfModule mod_rewrite.c>',
  '  RewriteEngine On',
  '  RewriteBase /',
  '',
  '  # Blog (renderowany przez PHP)',
  '  RewriteRule ^blog/?$ blog.php [L]',
  '  RewriteRule ^blog/([a-z0-9-]+)/?$ blog.php?slug=$1 [L,QSA]',
  '',
  '  # /index.html -> /',
  '  RewriteCond %{THE_REQUEST} \\s/+index\\.html[\\s?] [NC]',
  '  RewriteRule ^ / [R=301,L]',
  '',
  '  # /strona.html -> /strona (kanoniczny adres bez rozszerzenia)',
  '  RewriteCond %{THE_REQUEST} \\s/+([^.\\s?]+)\\.html[\\s?] [NC]',
  '  RewriteRule ^ /%1 [R=301,L]',
  '',
  '  # /strona -> strona.html (serwuj plik gdy istnieje)',
  '  RewriteCond %{REQUEST_FILENAME} !-f',
  '  RewriteCond %{REQUEST_FILENAME} !-d',
  '  RewriteCond %{DOCUMENT_ROOT}/$1.html -f',
  '  RewriteRule ^(.+?)/?$ $1.html [L]',
  '</IfModule>',
  '',
  '<IfModule mod_deflate.c>',
  '  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml text/plain application/xml',
  '</IfModule>',
  '',
  '<IfModule mod_expires.c>',
  '  ExpiresActive On',
  '  ExpiresByType text/html "access plus 5 minutes"',
  '  ExpiresByType text/css "access plus 30 days"',
  '  ExpiresByType application/javascript "access plus 30 days"',
  '  ExpiresByType image/webp "access plus 30 days"',
  '  ExpiresByType image/png "access plus 30 days"',
  '  ExpiresByType image/jpeg "access plus 30 days"',
  '  ExpiresByType image/svg+xml "access plus 30 days"',
  '  ExpiresByType font/woff2 "access plus 1 year"',
  '</IfModule>',
  '',
  '<IfModule mod_headers.c>',
  '  Header set X-Content-Type-Options "nosniff"',
  '  Header set X-Frame-Options "SAMEORIGIN"',
  '  Header set Referrer-Policy "strict-origin-when-cross-origin"',
  '  Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"',
  '</IfModule>',
  ''
].join('\n');
fs.writeFileSync(path.join(OUT, '.htaccess'), htaccess);

// raport
function countFiles(dir) {
  let n = 0;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.isDirectory()) n += countFiles(path.join(dir, e.name));
    else n++;
  }
  return n;
}
console.log('dist/ zbudowany. Plikow: ' + countFiles(OUT));
console.log('SITE_URL = ' + SITE_URL);

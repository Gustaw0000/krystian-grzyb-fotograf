'use strict';

const express = require('express');
const compression = require('compression');
const path = require('path');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 3000;
const PUBLIC_DIR = path.join(__dirname, 'public');

app.disable('x-powered-by');
app.set('trust proxy', true);
app.use(compression());

const HTML_CACHE = 'public, max-age=300';
const ASSET_CACHE = 'public, max-age=2592000';
const FONT_CACHE = 'public, max-age=31536000, immutable';

function siteUrl(req) {
  if (process.env.SITE_URL) return process.env.SITE_URL.replace(/\/+$/, '');
  const proto = (req.headers['x-forwarded-proto'] || req.protocol || 'https').split(',')[0].trim();
  const host = (req.headers['x-forwarded-host'] || req.headers.host || 'localhost').split(',')[0].trim();
  return proto + '://' + host;
}

// Security headers on every response
app.use((req, res, next) => {
  res.set('X-Content-Type-Options', 'nosniff');
  res.set('X-Frame-Options', 'SAMEORIGIN');
  res.set('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
  next();
});

function serveHtml(filename) {
  return (req, res) => {
    const file = path.join(PUBLIC_DIR, filename);
    fs.readFile(file, 'utf8', (err, html) => {
      if (err) return res.status(500).send('Blad serwera');
      const url = siteUrl(req);
      const lastModified = new Date().toISOString();
      const out = html
        .replace(/\{\{SITE_URL\}\}/g, url)
        .replace(/\{\{LAST_MODIFIED\}\}/g, lastModified);
      res.set('Content-Type', 'text/html; charset=utf-8');
      res.set('Cache-Control', HTML_CACHE);
      res.send(out);
    });
  };
}

// Block internal/audit folders
app.use('/_audit', (req, res) => res.status(404).end());

app.get('/', serveHtml('index.html'));
app.get('/polityka-prywatnosci', serveHtml('polityka-prywatnosci.html'));
app.get('/regulamin', serveHtml('regulamin.html'));

app.get('/healthz', (req, res) => {
  res.json({ status: 'ok', uptime: process.uptime(), now: new Date().toISOString() });
});

const SITEMAP_PATHS = [
  { path: '/', priority: '1.0', changefreq: 'weekly' },
  { path: '/#galeria', priority: '0.8', changefreq: 'weekly' },
  { path: '/#uslugi', priority: '0.7', changefreq: 'monthly' },
  { path: '/#o-mnie', priority: '0.6', changefreq: 'monthly' },
  { path: '/#kontakt', priority: '0.7', changefreq: 'monthly' },
  { path: '/polityka-prywatnosci', priority: '0.3', changefreq: 'yearly' },
  { path: '/regulamin', priority: '0.3', changefreq: 'yearly' }
];

app.get('/sitemap.xml', (req, res) => {
  const url = siteUrl(req);
  const lastmod = new Date().toISOString();
  const urls = SITEMAP_PATHS.map((u) => {
    const loc = url + u.path;
    return (
      '  <url>\n' +
      '    <loc>' + loc + '</loc>\n' +
      '    <lastmod>' + lastmod + '</lastmod>\n' +
      '    <changefreq>' + u.changefreq + '</changefreq>\n' +
      '    <priority>' + u.priority + '</priority>\n' +
      '    <xhtml:link rel="alternate" hreflang="pl-PL" href="' + loc + '"/>\n' +
      '    <xhtml:link rel="alternate" hreflang="x-default" href="' + loc + '"/>\n' +
      '  </url>'
    );
  }).join('\n');
  const xml =
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n' +
    urls + '\n' +
    '</urlset>\n';
  res.set('Content-Type', 'application/xml; charset=utf-8');
  res.set('Cache-Control', HTML_CACHE);
  res.send(xml);
});

app.get('/sitemap-index.xml', (req, res) => {
  const url = siteUrl(req);
  const lastmod = new Date().toISOString();
  const xml =
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    '  <sitemap>\n' +
    '    <loc>' + url + '/sitemap.xml</loc>\n' +
    '    <lastmod>' + lastmod + '</lastmod>\n' +
    '  </sitemap>\n' +
    '</sitemapindex>\n';
  res.set('Content-Type', 'application/xml; charset=utf-8');
  res.set('Cache-Control', HTML_CACHE);
  res.send(xml);
});

app.get('/robots.txt', (req, res) => {
  const url = siteUrl(req);
  const body =
    'User-agent: *\n' +
    'Allow: /\n\n' +
    'User-agent: GPTBot\n' +
    'Allow: /\n\n' +
    'User-agent: ClaudeBot\n' +
    'Allow: /\n\n' +
    'User-agent: Google-Extended\n' +
    'Allow: /\n\n' +
    'User-agent: PerplexityBot\n' +
    'Allow: /\n\n' +
    'Sitemap: ' + url + '/sitemap.xml\n';
  res.set('Content-Type', 'text/plain; charset=utf-8');
  res.set('Cache-Control', HTML_CACHE);
  res.send(body);
});

// Static assets
app.use(express.static(PUBLIC_DIR, {
  extensions: ['html'],
  setHeaders: (res, filePath) => {
    if (/\.(woff2?|ttf|otf)$/i.test(filePath)) {
      res.set('Cache-Control', FONT_CACHE);
    } else if (/\.(webp|jpg|jpeg|png|svg|gif|ico|avif)$/i.test(filePath)) {
      res.set('Cache-Control', ASSET_CACHE);
    } else if (/\.(xml|txt|json|webmanifest)$/i.test(filePath)) {
      res.set('Cache-Control', HTML_CACHE);
    } else if (/\.html$/i.test(filePath)) {
      res.set('Cache-Control', HTML_CACHE);
    }
  }
}));

// Custom 404
app.use((req, res) => {
  res.status(404);
  const file = path.join(PUBLIC_DIR, '404.html');
  fs.readFile(file, 'utf8', (err, html) => {
    if (err) return res.type('text/plain').send('404 - nie znaleziono strony');
    res.set('Content-Type', 'text/html; charset=utf-8');
    res.send(html.replace(/\{\{SITE_URL\}\}/g, siteUrl(req)));
  });
});

app.listen(PORT, () => {
  console.log('Serwer wystartowal na porcie ' + PORT);
});

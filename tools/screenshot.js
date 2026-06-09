'use strict';

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const PORT = process.env.AUDIT_PORT || 3789;
const BASE = 'http://localhost:' + PORT;
const OUT_DIR = path.join(__dirname, '..', 'public', '_audit');

const PAGES = (process.env.AUDIT_PAGES || '/,/polityka-prywatnosci,/regulamin').split(',').map((p) => p.trim()).filter(Boolean);

const VIEWPORTS = [
  { width: 320, height: 568 },
  { width: 375, height: 667 },
  { width: 768, height: 1024 },
  { width: 1024, height: 768 },
  { width: 1440, height: 900 }
];

function pageSlug(p) {
  if (p === '/' || p === '') return 'home';
  return p.replace(/^\//, '').replace(/\/$/, '').replace(/\//g, '-');
}

(async () => {
  if (fs.existsSync(OUT_DIR)) fs.rmSync(OUT_DIR, { recursive: true, force: true });
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch();
  let total = 0, failed = 0;

  for (const vp of VIEWPORTS) {
    const vpDir = path.join(OUT_DIR, vp.width + 'x' + vp.height);
    fs.mkdirSync(vpDir, { recursive: true });
    const context = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: 1,
      hasTouch: vp.width <= 768,
      isMobile: vp.width <= 768
    });

    for (const p of PAGES) {
      const page = await context.newPage();
      total++;
      try {
        await page.goto(BASE + p, { waitUntil: 'networkidle', timeout: 20000 });
        // Audit-only: force reveal/scroll-driven elements to their visible end-state
        // so a static full-page capture shows the layout users see after scrolling.
        await page.addStyleTag({ content:
          '.reveal{opacity:1 !important;transform:none !important}' +
          '.gphoto img{animation:none !important;transform:none !important}' +
          '.section-head h2{animation:none !important;opacity:1 !important;transform:none !important}' +
          'html .hero-img{clip-path:none !important;transform:scale(1) !important}' });
        // Scroll through to trigger lazy-loaded images, then back to top.
        await page.evaluate(async () => {
          const h = document.body.scrollHeight;
          for (let y = 0; y <= h; y += 600) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 60)); }
          window.scrollTo(0, 0);
        });
        await page.waitForTimeout(700);
        const out = path.join(vpDir, pageSlug(p) + '.png');
        await page.screenshot({ path: out, fullPage: true });
        const kb = (fs.statSync(out).size / 1024).toFixed(1);
        console.log('  ' + vp.width + 'x' + vp.height + ' ' + p.padEnd(30) + ' ' + kb + ' KB');
      } catch (err) {
        failed++;
        console.error('  ' + vp.width + 'x' + vp.height + ' ' + p.padEnd(30) + ' FAILED: ' + err.message);
      } finally {
        await page.close();
      }
    }
    await context.close();
  }

  await browser.close();
  console.log('Done. ' + (total - failed) + '/' + total + ' screenshots in ' + OUT_DIR + '.');
  if (failed > 0) process.exit(1);
})().catch((err) => { console.error(err); process.exit(1); });

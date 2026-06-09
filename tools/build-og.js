'use strict';

const sharp = require('sharp');
const path = require('path');
const fs = require('fs');

const PUBLIC = path.join(__dirname, '..', 'public');
const SVG = path.join(PUBLIC, 'og-image.svg');

(async () => {
  if (!fs.existsSync(SVG)) {
    console.error('Brak pliku og-image.svg');
    process.exit(1);
  }
  const svg = fs.readFileSync(SVG);

  const png = path.join(PUBLIC, 'og-image.png');
  const jpg = path.join(PUBLIC, 'og-image.jpg');

  await sharp(svg, { density: 160 })
    .resize(1200, 630, { fit: 'cover' })
    .png({ compressionLevel: 9 })
    .toFile(png);

  await sharp(svg, { density: 160 })
    .resize(1200, 630, { fit: 'cover' })
    .flatten({ background: '#100C09' })
    .jpeg({ quality: 88, mozjpeg: true })
    .toFile(jpg);

  const pkb = (fs.statSync(png).size / 1024).toFixed(1);
  const jkb = (fs.statSync(jpg).size / 1024).toFixed(1);
  console.log('og-image.png ' + pkb + ' KB');
  console.log('og-image.jpg ' + jkb + ' KB');
})().catch((err) => { console.error(err); process.exit(1); });

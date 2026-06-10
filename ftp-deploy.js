'use strict';

const ftp = require('basic-ftp');
const fs = require('fs');
const path = require('path');

const HOST = 'serwer452364.lh.pl';
const USER = 'serwer452364';
const PASS = process.env.FTP_PASS;
// UWAGA: aktywny katalog WWW domeny krystiangrzyb.pl to folder po starym WordPressie
// (autoinstalator wskazal domene tutaj). NIE jest to public_html/krystiangrzyb.pl.
const REMOTE_DIR = process.env.FTP_REMOTE_DIR || 'public_html/autoinstalator/krystiangrzyb.pl/wordpress163355';

const LOCAL_DIST = path.join(__dirname, 'dist');
const LOCAL_CMS = path.join(__dirname, 'cms');

// Domyslnie NAKLADKA (overlay): nie czyscimy katalogu, zeby NIE skasowac
// postow bloga, kont i uploadow utworzonych przez panel.
// FTP_WIPE=1 wymusza pelne czyszczenie (tylko swiadomie, niszczy dane bloga!).
const WIPE = process.env.FTP_WIPE === '1';
// FTP_CMS=1 wgrywa tez kod panelu + blog.php (cms/). Domyslnie tylko statyczna strona.
const WITH_CMS = process.env.FTP_CMS === '1';

async function tryUpload(secure) {
  const client = new ftp.Client(180000);
  client.ftp.verbose = false;
  let count = 0;
  client.trackProgress(function (info) {
    if (info.type === 'upload' && info.name) { count++; }
  });
  try {
    await client.access({
      host: HOST, user: USER, password: PASS,
      secure: secure, secureOptions: { rejectUnauthorized: false }
    });
    const mode = WIPE ? 'PELNE CZYSZCZENIE' : 'nakladka (overlay)';
    console.log('Polaczono (' + (secure ? 'FTPS/TLS' : 'plain FTP') + '). Tryb: ' + mode + ' -> /' + REMOTE_DIR);
    await client.ensureDir(REMOTE_DIR);   // CWD = docroot
    if (WIPE) await client.clearWorkingDir();

    console.log('Wgrywam statyczna strone (dist/)...');
    await client.uploadFromDir(LOCAL_DIST);

    if (WITH_CMS) {
      if (fs.existsSync(LOCAL_CMS)) {
        console.log('Wgrywam panel + blog (cms/)...');
        await client.uploadFromDir(LOCAL_CMS);
      } else {
        console.log('UWAGA: brak folderu cms/, pomijam.');
      }
    }

    console.log('GOTOWE. Plikow wgranych (zdarzen): ' + count);
    client.close();
    return true;
  } catch (e) {
    console.error('Blad (' + (secure ? 'FTPS' : 'plain') + '): ' + e.message);
    client.close();
    return false;
  }
}

(async () => {
  if (!PASS) { console.error('Brak FTP_PASS'); process.exit(1); }
  let ok = await tryUpload(true);
  if (!ok) {
    console.log('Probuje bez TLS...');
    ok = await tryUpload(false);
  }
  process.exit(ok ? 0 : 1);
})();

'use strict';

const ftp = require('basic-ftp');
const path = require('path');

const HOST = 'serwer452364.lh.pl';
const USER = 'serwer452364';
const PASS = process.env.FTP_PASS;
const REMOTE_DIR = process.env.FTP_REMOTE_DIR || 'public_html/krystiangrzyb.pl';
const LOCAL_DIR = path.join(__dirname, 'dist');

async function tryUpload(secure) {
  const client = new ftp.Client(180000);
  client.ftp.verbose = false;
  let count = 0;
  client.trackProgress(function (info) {
    if (info.type === 'upload' && info.name) {
      count++;
      console.log('  -> ' + info.name + ' (' + info.bytesOverall + ' B total)');
    }
  });
  try {
    await client.access({
      host: HOST,
      user: USER,
      password: PASS,
      secure: secure,
      secureOptions: { rejectUnauthorized: false }
    });
    console.log('Polaczono (' + (secure ? 'FTPS/TLS' : 'plain FTP') + '). Wgrywam dist/ -> /' + REMOTE_DIR);
    await client.ensureDir(REMOTE_DIR);   // CWD = target
    await client.clearWorkingDir();       // czysci zawartosc targetu (w tym bledny zagniezdzony public_html)
    await client.uploadFromDir(LOCAL_DIR); // wgraj dist/* do biezacego katalogu (bez ponownego REMOTE_DIR)
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

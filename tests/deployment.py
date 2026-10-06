"""Verify cPanel deployment copies application files but preserves private runtime data."""
from pathlib import Path
import subprocess, tempfile
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='estate-deploy-') as temp:
 target=Path(temp)/'public_html'/'Estate'
 (target/'app').mkdir(parents=True)
 (target/'storage').mkdir()
 (target/'app/config.php').write_text('existing private config')
 (target/'app/integrations.private.php').write_text('existing integration secrets')
 (target/'storage/customer-file.pdf').write_bytes(b'private upload')
 (target/'storage/database.sqlite').write_bytes(b'existing database')
 (target/'index.php').write_text('old application')
 for _ in range(2):
  subprocess.run(['bash',str(root/'scripts/deploy-cpanel.sh'),str(target)],check=True)
  assert (target/'index.php').read_bytes()==(root/'index.php').read_bytes()
  assert (target/'app/config.php').read_text()=='existing private config'
  assert (target/'app/integrations.private.php').read_text()=='existing integration secrets'
  assert (target/'app/websites.php').is_file()
  assert (target/'assets/website.js').is_file()
  assert (target/'storage/customer-file.pdf').read_bytes()==b'private upload'
  assert (target/'storage/database.sqlite').read_bytes()==b'existing database'
  assert (target/'storage/.htaccess').exists()
  assert (target/'assets/hero.webp').is_file()
  assert not (target/'.git').exists()
  assert not (target/'tests').exists()
 print('PASS: first and repeat deploy, static assets, access rules, configuration and runtime-data preservation')

#!/usr/bin/env python3
"""Run configured-installer HTTP refusal checks on an isolated loopback Apache.
SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
No system config, existing vhost, or existing database is used.
"""
import json
import pathlib
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

TIP = 'd66f4576fed49a6a1a0b849e63daee6af9cba82e'
root = pathlib.Path(tempfile.mkdtemp(prefix='snapsmack-974-apache-'))
root.chmod(0o777)
(root / 'core').mkdir(mode=0o777)
(root / 'core').chmod(0o777)
(root / 'core/db.php').write_text('<?php throw new Exception("Config executed");')
urllib.request.urlretrieve('https://raw.githubusercontent.com/baddaywithacamera/snapsmack/' + TIP + '/install.php', root / 'install.php')
(root / 'probe.php').write_text('<?php echo "PHP_HANDLER_OK";')
lines = [
    f'ServerRoot {root}', f'PidFile {root}/apache.pid',
    'Listen 127.0.0.1:18974', 'ServerName localhost',
    'LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so',
    'LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so',
    'LoadModule php_module /usr/lib/apache2/modules/libphp8.4.so',
    'User www-data', 'Group www-data', f'ErrorLog {root}/error.log',
    f'DocumentRoot {root}', f'<Directory {root}>', 'Require all granted',
    'AllowOverride None', '</Directory>', '<FilesMatch \\.php$>',
    'SetHandler application/x-httpd-php', '</FilesMatch>',
]
config = root / 'apache.conf'
config.write_text('\n'.join(lines) + '\n')
rows = []
started = False

def request(path, method='GET'):
    req = urllib.request.Request('http://127.0.0.1:18974' + path, method=method,
                                 data=b'step=5' if method == 'POST' else None)
    try:
        response = urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.read(), response.headers

try:
    subprocess.run(['apache2', '-f', str(config), '-t'], check=True)
    subprocess.run(['apache2', '-f', str(config), '-k', 'start'], check=True)
    started = True
    for attempt in range(20):
        try:
            code, body, _ = request('/probe.php')
            break
        except urllib.error.URLError:
            time.sleep(0.1)
    assert code == 200 and body == b'PHP_HANDLER_OK', 'PHP handler positive control failed'
    for path in ['/install.php', '/install%2ephp', '/install%2Ephp', '/%69nstall.php', '/install.php/trailing', '/INSTALL.PHP']:
        for method in ['GET', 'POST']:
            code, body, headers = request(path, method)
            row = dict(path=path, method=method, status=code,
                       session_cookie=bool(headers.get('Set-Cookie')),
                       state_exists=(root / 'core/.snapsmack-installer-state.php').exists())
            rows.append(row)
            assert code == 404 and not row['session_cookie'] and not row['state_exists'], row
            if path != '/INSTALL.PHP':
                assert body == b'', 'Expected the PHP early-refusal response'
    result = dict(source_tip=TIP, server='Apache/mod_php8.4', root=str(root),
                  php_positive_control=True, cases=rows, passed=True)
    (root / 'result.json').write_text(json.dumps(result, indent=2))
    print(json.dumps(result))
finally:
    if started:
        subprocess.run(['apache2', '-f', str(config), '-k', 'stop'], check=True)
    print('Scratch evidence retained at ' + str(root))
# ===== SNAPSMACK EOF =====

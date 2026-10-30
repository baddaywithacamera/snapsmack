"""Verdict table for tools/key-revocation-probe.py.

Fabricated responses, so the probe can be checked without firing a
credential at a live site. The cases that matter are the WAF ones: a
block that carries the status of a refusal must never read as a
revocation, because a disconnect gets decided on that answer.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""
import os, sys, io, contextlib, importlib.util

spec = importlib.util.spec_from_file_location(
    'probe',
    os.path.join(os.path.dirname(os.path.abspath(__file__)), 'key-revocation-probe.py'))
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class FakeResp:
    def __init__(self, code, payload=None, ctype='application/json', text=''):
        self.status_code = code
        self._p = payload
        self.headers = {'Content-Type': ctype}
        self.text = text

    def json(self):
        if self._p is None:
            raise ValueError('not json')
        return self._p


WAF_HTML = ('<!DOCTYPE html><html><head><title>Attention Required! | Cloudflare'
            '</title></head><body>Sorry, you have been blocked</body></html>')

CASES = [
    # (http, json payload, content-type, raw text, route, want exit, want text)
    (401, {'error': 'Invalid or revoked API key'}, 'application/json', '',
     'multisite/posts/recent', 0, 'NOT ACCEPTED'),
    (403, {'error': 'This key is scoped to backup operations only'}, 'application/json', '',
     'multisite/posts/recent', 1, 'STILL ACCEPTED'),
    (200, {'posts': []}, 'application/json', '',
     'multisite/posts/recent', 1, 'STILL ACCEPTED'),
    (200, {'ok': True}, 'application/json', '',
     'multisite/heartbeat', 3, 'CANNOT TELL'),
    (500, None, 'text/html', '<html>oops</html>',
     'multisite/posts/recent', 3, 'INCONCLUSIVE'),
    # THE ONE THAT MATTERS: a WAF block with the status of a refusal.
    (403, None, 'text/html; charset=UTF-8', WAF_HTML,
     'multisite/posts/recent', 3, 'INCONCLUSIVE'),
    # And a WAF 401, which would otherwise read as a clean revocation.
    (401, None, 'text/html', WAF_HTML,
     'multisite/posts/recent', 3, 'INCONCLUSIVE'),
    # JSON content-type but an array, not an object: still not the API shape.
    (401, None, 'application/json', '[]',
     'multisite/posts/recent', 3, 'INCONCLUSIVE'),
]

SECRET = 'KEYVALUE-must-never-appear'
fails = 0
for http, payload, ctype, text, route, want_exit, want_text in CASES:
    probe.requests.get = lambda *a, **k: FakeResp(http, payload, ctype, text)
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        got = probe.probe('https://site.invalid', SECRET, route)
    out = buf.getvalue()
    ok = (got == want_exit) and (want_text in out) and ('KEYVALUE' not in out)
    if not ok:
        fails += 1
    print('%s HTTP %-3s %-28s %-34s exit=%d(want %d) %s' % (
        'ok  ' if ok else 'FAIL', http, ctype[:26], route,
        got, want_exit, want_text if want_text in out else '!! got something else'))

print()
print('A revocation verdict is only ever given on a JSON object from the API.')
print('FAILED %d of %d' % (fails, len(CASES)))
sys.exit(1 if fails else 0)
# ===== SNAPSMACK EOF =====

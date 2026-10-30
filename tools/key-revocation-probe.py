#!/usr/bin/env python3
"""Prove an API key is dead, rather than reading that it should be.

Codex asked for this three times in Brain Trust and there was nothing in the
tree to point him at. SECAUDIT 061 / the HAL old-key question: source review
says a key is revoked; nobody had presented the key to the endpoint and read
the answer. Source PASS is not live-revocation PASS.

    echo -n "<the old key>" | python tools/key-revocation-probe.py https://site.example

THE KEY IS READ FROM STDIN AND NOWHERE ELSE. Not an argument -- argv is
visible to every other process on the box via the process list. Not a file --
a file outlives the probe and gets backed up. It is held in one local variable,
sent in one request header, and never printed, logged or written. The probe
reports an HTTP status and a verdict; it never reports the key.

WHICH ENDPOINT, AND WHY IT MATTERS. The default is multisite/posts/recent:
behind the generic gate in core/multisite-api.php, read-only, and with no
credential fallback, so 401 and 200 are both unambiguous.

DO NOT PROBE multisite/heartbeat. core/multisite-api.php has a deliberate
fallback for heartbeat GET only: when the node lookup has already failed it
will accept an OH SNAP key_type='hub' key so CRONOMETER can show the hub
beside its spokes. On heartbeat a 401 is still sound proof of revocation, but
a 200 cannot tell "the old node key still works" from "a hub key matched
instead" -- and a false LIVE reading on a revocation test is the wrong way
round to be wrong. This script refuses heartbeat unless you insist.

WHAT A 401 DOES NOT PROVE, so nobody over-reads the output. The gate is

    WHERE api_key_local = ? AND status = 'active'

A 401 means the presented key matches no active node row. It does NOT
distinguish a REVOKED key from a DELETED node row -- both miss the same
WHERE clause and return the same 401. If you need "revoked" specifically
rather than "no longer accepted", the hub-side row has to be read as well.
This probe answers "is this credential still accepted", which is the question
that gates a disconnect.

Read-only. It sends one GET. It changes nothing on the target.
"""

from __future__ import annotations

import argparse
import os
import sys
from urllib.parse import urlsplit, urlunsplit

try:
    import requests
except ImportError:
    sys.stderr.write('This probe needs requests: python -m pip install requests\n')
    raise SystemExit(2)

_SHARED = os.path.join(os.path.dirname(os.path.abspath(__file__)), '_shared')
if os.path.isdir(_SHARED) and _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

try:
    import snap_site_scope
except ImportError:
    snap_site_scope = None

# A real browser's UA. Not deception: the request is authenticated with the
# site owner's own key against the owner's own site, and this only stops an
# edge rule turning a valid answer into a misleading one.
BROWSER_UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
              'AppleWebKit/537.36 (KHTML, like Gecko) '
              'Chrome/131.0.0.0 Safari/537.36')

# Read-only, behind the generic gate, no credential fallback.
DEFAULT_ROUTE = 'multisite/posts/recent'

# The one route whose 200 is ambiguous. See the module docstring.
AMBIGUOUS_ROUTES = {'multisite/heartbeat'}


def _insecure_reason(base_url: str) -> str:
    """Refuse to put a key on the wire in the clear. Same rule the fleet tools
    already apply, reused rather than reimplemented."""
    try:
        from snap_stepup import insecure_transport_reason
        return insecure_transport_reason(base_url)
    except Exception:
        u = str(base_url).strip().lower()
        if u.startswith('https://'):
            return ''
        for host in ('http://localhost', 'http://127.', 'http://[::1]'):
            if u.startswith(host):
                return ''
        return ('Site URL is not https:// -- refusing to send the key in the '
                'clear. A revocation test is not worth leaking the credential '
                'you are testing.')


def _api_url(site_url: str, route: str) -> str:
    parts = urlsplit(site_url if '://' in site_url else 'https://' + site_url)
    scheme = parts.scheme or 'https'
    netloc = parts.netloc or parts.path
    return urlunsplit((scheme, netloc, '/api.php', 'route=' + route, ''))


def probe(site_url: str, key: str, route: str, timeout: int = 15) -> int:
    """Present the key once and report. Returns a process exit code:
    0 = not accepted (what a revocation test wants to see)
    1 = still accepted
    3 = could not tell"""
    # The shared helper owns the DECISION, including the localhost exemptions.
    # Its message is written for the admin step-up flow and talks about
    # passwords and 2FA codes, which is not what is being sent here, so the
    # wording below is ours and the helper's is the supporting detail.
    reason = _insecure_reason(site_url)
    if reason:
        print('CANNOT PROBE: the site URL is not https://, and this would put '
              'the key on the wire in the clear.')
        print('              A revocation test is not worth leaking the '
              'credential being tested.')
        print('  transport: ' + ' '.join(reason.split()))
        return 3

    # A BROWSER USER-AGENT, because the answer has to come from the API and not
    # from the edge. Codex measured this: the same request got 403 from a Python
    # user-agent and 200 from PowerShell, and this project already knows its
    # forum API refuses a default Python UA. Without this a WAF block can be
    # read as the API refusing the credential -- which on a revocation test
    # means reporting a LIVE key as dead, and a disconnect decided on it.
    headers = {
        'Authorization': 'Bearer ' + key,
        'Accept': 'application/json',
        'User-Agent': BROWSER_UA,
    }
    if snap_site_scope:
        headers.update(snap_site_scope.header(site_url))

    url = _api_url(site_url, route)
    print('probing  ' + url)
    print('route    ' + route
          + ('   <-- AMBIGUOUS ON 200, see --help' if route in AMBIGUOUS_ROUTES else ''))

    try:
        resp = requests.get(url, headers=headers, timeout=timeout)
    except requests.exceptions.Timeout:
        print('RESULT   no answer: timed out after %ds' % timeout)
        print('VERDICT  CANNOT TELL. A timeout is not a revocation.')
        return 3
    except requests.exceptions.RequestException as exc:
        # The exception text can carry the request URL but never a header.
        print('RESULT   no answer: %s' % type(exc).__name__)
        print('VERDICT  CANNOT TELL. The site did not answer.')
        return 3

    status = resp.status_code
    ctype = (resp.headers.get('Content-Type') or '').lower()

    # IS THIS THE API ANSWERING, OR SOMETHING IN FRONT OF IT?
    #
    # Every verdict below is about what the GATE in core/multisite-api.php did
    # with the credential. A WAF, a proxy or an error page can produce the same
    # status codes without the gate ever seeing the request, and reading one of
    # those as "the key was refused" is the single worst thing this tool could
    # do: it would report a LIVE key as revoked and a disconnect would follow.
    #
    # So the body must parse as JSON and be a JSON object before any verdict is
    # given. Anything else is INCONCLUSIVE by construction, never a revocation.
    api_error = ''
    is_json = False
    body = None
    try:
        body = resp.json()
        is_json = isinstance(body, dict)
    except ValueError:
        is_json = False
    if is_json:
        api_error = str(body.get('error') or body.get('message') or '')

    print('RESULT   HTTP %d   content-type: %s' % (status, ctype or '(none)'))
    if api_error:
        print('         API said: "%s"' % api_error[:200])

    if not is_json:
        preview = ' '.join((resp.text or '')[:160].split())
        print('VERDICT  INCONCLUSIVE. The response is not a JSON object, so it did')
        print('         not come from the API and says nothing about the key.')
        print('         This is what a WAF or proxy block looks like. A Python')
        print('         user-agent has been seen getting 403 here where a browser')
        print('         got 200, which is why this check exists.')
        if preview:
            print('         first bytes: %s' % preview)
        print('         Do NOT treat this as a revocation.')
        return 3

    if status == 401:
        print('VERDICT  NOT ACCEPTED. This credential no longer passes the gate.')
        print('         This is live evidence, not a source reading.')
        print('LIMIT    It does not distinguish a revoked key from a deleted')
        print('         node row; both miss the same WHERE clause. If you need')
        print('         "revoked" specifically, read the hub-side row too.')
        return 0

    if status == 403:
        print('VERDICT  STILL ACCEPTED, then refused on scope or role.')
        print('         The key AUTHENTICATED -- 403 comes after the gate.')
        print('         Treat this as a live credential.')
        return 1

    if 200 <= status < 300:
        if route in AMBIGUOUS_ROUTES:
            print('VERDICT  CANNOT TELL. This route accepts a second credential')
            print('         type, so a 200 does not prove it was YOUR key that')
            print('         was accepted. Re-probe %s.' % DEFAULT_ROUTE)
            return 3
        print('VERDICT  STILL ACCEPTED. The credential is live. Do not treat')
        print('         this key as revoked, whatever the source says.')
        return 1

    print('VERDICT  CANNOT TELL. HTTP %d is neither the gate accepting nor' % status)
    print('         the gate refusing. Look at the site before concluding.')
    return 3


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(
        description='Present an API key to a site and report whether it is still accepted.',
        epilog='The key is read from STDIN only. Never pass it as an argument.',
    )
    ap.add_argument('site_url', help='the site to probe, https:// only')
    ap.add_argument('--route', default=DEFAULT_ROUTE,
                    help='API route to probe (default: %s)' % DEFAULT_ROUTE)
    ap.add_argument('--allow-ambiguous', action='store_true',
                    help='probe a route whose 200 cannot be trusted, e.g. multisite/heartbeat')
    ap.add_argument('--timeout', type=int, default=15)
    args = ap.parse_args(argv)

    if args.route in AMBIGUOUS_ROUTES and not args.allow_ambiguous:
        print('REFUSING %s.' % args.route)
        print()
        print('core/multisite-api.php accepts an OH SNAP hub-type key for')
        print('heartbeat GET when the node lookup has already failed. So on this')
        print('route a 200 cannot tell "the old key still works" from "a hub key')
        print('matched instead" -- a false LIVE reading on a revocation test.')
        print()
        print('Probe %s instead. It is behind the same gate,' % DEFAULT_ROUTE)
        print('read-only, and has no fallback, so both answers mean what they say.')
        print('Pass --allow-ambiguous if you want it anyway.')
        return 2

    if sys.stdin.isatty():
        print('No key on stdin. Pipe it in; do not pass it as an argument:')
        print()
        print('    echo -n "<key>" | python tools/key-revocation-probe.py %s' % args.site_url)
        print()
        print('An argument would be visible in the process list to anything')
        print('else running on the box.')
        return 2

    key = sys.stdin.read().strip()
    if not key:
        print('Stdin was empty, so nothing was presented and nothing is proven.')
        return 2

    return probe(args.site_url, key, args.route, args.timeout)


if __name__ == '__main__':
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====

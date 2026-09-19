"""SECAUDIT 053 F / 054 — every desktop urllib caller that attaches a credential
refuses redirects (urllib re-sends Authorization to the new host; requests strips
it cross-host, urllib does not).

Static contract: each listed file has no bare `urllib.request.urlopen(` left and
defines a redirect-refusing opener. Behavioural check: the opener really raises
on a 302 instead of following it.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import io
import os
import sys
import unittest
import urllib.error
import urllib.request
import urllib.response

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# found_textures.py is covered by its own tests: its credentialed call (search)
# refuses redirects; its media fetch carries no credential and MAY follow one
# (Google Drive downloads redirect legitimately).
CREDENTIALED_URLLIB_CALLERS = [
    "tools/hub/lewk_again.py",
    "tools/smackpress/smackpress/ai_client.py",
    "tools/smackpress/smackpress/smacktalk_client.py",
    "tools/smackpress/smackpress/wp_client.py",
    "tools/smackattack-scanner/main.py",
    "tools/linux/smackattack-scanner/app.py",
    "tools/cf-open-ai-crawlers.py",
]


class StaticContractTests(unittest.TestCase):
    def test_no_bare_urlopen_and_a_refusing_opener_present(self):
        for rel in CREDENTIALED_URLLIB_CALLERS:
            with self.subTest(file=rel):
                src = open(os.path.join(ROOT, rel), encoding="utf-8").read()
                self.assertNotIn("urllib.request.urlopen(", src,
                                 f"{rel} still calls urllib.request.urlopen directly")
                self.assertIn("HTTPRedirectHandler", src,
                              f"{rel} has no redirect-refusing opener")


class BehaviourTests(unittest.TestCase):
    def test_refusing_opener_raises_on_302(self):
        sys.path.insert(0, os.path.join(ROOT, "tools", "hub"))
        sys.path.insert(0, os.path.join(ROOT, "tools", "_shared"))
        import found_textures

        class _FakeHTTPS(urllib.request.HTTPSHandler):
            def https_open(self, req):
                import email.message
                hdrs = email.message.Message()
                hdrs["Location"] = "https://evil.example/"
                resp = urllib.response.addinfourl(io.BytesIO(b""), hdrs, req.full_url, 302)
                resp.msg = "Found"
                return resp

        opener = urllib.request.build_opener(found_textures._RefuseRedirect, _FakeHTTPS)
        req = urllib.request.Request("https://foundtextures.ca/api.php",
                                     headers={"Authorization": "Bearer k"})
        with self.assertRaises((ValueError, urllib.error.HTTPError)):
            opener.open(req, timeout=5)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====

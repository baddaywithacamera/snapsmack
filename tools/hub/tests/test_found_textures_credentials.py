"""Found Textures prefers GYSS and has a public-catalogue SYBU fallback."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import unittest
from unittest.mock import patch

import found_textures


class FoundTexturesCredentialTests(unittest.TestCase):
    def test_resolve_profile_uses_gyss_connection(self):
        profiles = type("Profiles", (), {"list_profiles": staticmethod(lambda: [{
            "site_url": "https://foundtextures.ca/",
            "api_key": "sybu-publishing-key", "extras": {},
        }])})
        connections = type("Connections", (), {"resolve": staticmethod(
            lambda site, kind: {"site_url": site, "api_key": "gyss-read-key"}
            if kind == "gyss" else None)})
        with patch.object(found_textures, "snap_profiles", profiles), \
                patch.object(found_textures, "snap_connections", connections):
            self.assertEqual(found_textures.resolve_profile(),
                             ("https://foundtextures.ca", "gyss-read-key"))

    def test_resolve_profile_falls_back_to_sybu_for_public_catalogue(self):
        profiles = type("Profiles", (), {"list_profiles": staticmethod(lambda: [{
            "site_url": "https://foundtextures.ca",
            "api_key": "sybu-publishing-key", "extras": {},
        }])})
        connections = type("Connections", (), {"resolve": staticmethod(
            lambda _site, _kind: None)})
        with patch.object(found_textures, "snap_profiles", profiles), \
                patch.object(found_textures, "snap_connections", connections), \
                patch.object(found_textures, "snap_discovery", None):
            self.assertEqual(found_textures.resolve_profile(),
                             ("https://foundtextures.ca", "sybu-publishing-key"))

    def test_hub_catalogue_uses_credential_saved_by_discovery(self):
        profile = {"site_url": "https://foundtextures.ca", "api_key": "sybu",
                   "extras": {"api_key_local": "full"}}
        profiles = type("Profiles", (), {
            "list_profiles": staticmethod(lambda: [profile]),
        })
        connections = type("Connections", (), {
            "resolve": staticmethod(lambda _site, _kind: None)})
        with patch.object(found_textures, "snap_profiles", profiles), \
                patch.object(found_textures, "snap_connections", connections), \
                patch.object(found_textures, "snap_discovery", None):
            self.assertEqual(found_textures.resolve_profile(),
                             ("https://foundtextures.ca", "full"))




class FoundTexturesMediaFetchTests(unittest.TestCase):
    """SECAUDIT 058 A — the site key goes to the catalogue API only, never to
    a media URL (thumb / full image / Google Drive high-res link)."""

    def _capture_requests(self):
        calls = []

        class _Resp:
            status_code = 200
            content = b"bytes"
            def raise_for_status(self):
                pass
            def json(self):
                return {"ok": True, "photos": []}

        fake = type("Requests", (), {"get": staticmethod(
            lambda url, **kw: calls.append((url, kw)) or _Resp())})
        return fake, calls

    def test_fetch_bytes_never_sends_authorization(self):
        fake, calls = self._capture_requests()
        with patch.object(found_textures, "requests", fake):
            found_textures.fetch_bytes(
                "https://drive.google.com/uc?export=download&id=abc", "full-hub-key")
        self.assertEqual(len(calls), 1)
        headers = calls[0][1].get("headers") or {}
        self.assertNotIn("Authorization", headers)
        self.assertNotIn("full-hub-key", repr(calls))

    def test_fetch_bytes_urllib_path_never_sends_authorization(self):
        seen = {}

        class _Resp:
            def __enter__(self):
                return self
            def __exit__(self, *a):
                return False
            def read(self):
                return b"bytes"

        def fake_open(request, timeout=None):
            seen["headers"] = dict(request.header_items())
            return _Resp()

        with patch.object(found_textures, "requests", None), \
                patch.object(found_textures.urllib.request, "urlopen", fake_open):
            found_textures.fetch_bytes("https://example.org/x.jpg", "full-hub-key")
        self.assertNotIn("authorization", {k.lower() for k in seen["headers"]})

    def test_fetch_bytes_refuses_non_web_schemes(self):
        with self.assertRaises(ValueError):
            found_textures.fetch_bytes("file:///etc/passwd", None)

    def test_search_sends_key_only_over_https_and_never_follows_redirects(self):
        fake, calls = self._capture_requests()
        with patch.object(found_textures, "requests", fake):
            found_textures.search("https://foundtextures.ca", "gyss-key", "wood")
        self.assertEqual(calls[0][1]["headers"]["Authorization"], "Bearer gyss-key")
        self.assertFalse(calls[0][1]["allow_redirects"])
        with patch.object(found_textures, "requests", fake), \
                self.assertRaises(ValueError):
            found_textures.search("http://foundtextures.ca", "gyss-key", "wood")

    def test_search_refuses_redirect_response(self):
        class _Redirect:
            status_code = 302
            headers = {"Location": "https://evil.example/"}
            def raise_for_status(self):
                pass
        fake = type("Requests", (), {"get": staticmethod(lambda url, **kw: _Redirect())})
        with patch.object(found_textures, "requests", fake), \
                self.assertRaises(ValueError):
            found_textures.search("https://foundtextures.ca", "gyss-key", "wood")


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====

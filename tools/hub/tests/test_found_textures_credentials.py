"""Found Textures prefers GYSS and has a public-catalogue SYBU fallback."""

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

    def test_incomplete_discovery_repairs_gyss_key_with_full_credential(self):
        profile = {"site_url": "https://foundtextures.ca", "api_key": "sybu",
                   "extras": {"api_key_local": "full"}}
        saved = []
        profiles = type("Profiles", (), {
            "list_profiles": staticmethod(lambda: [profile]),
            "save": staticmethod(saved.append),
        })
        connections = type("Connections", (), {
            "resolve": staticmethod(lambda _site, _kind: None)})
        discovery = type("Discovery", (), {
            "_provision_spoke_key": staticmethod(
                lambda site, key, kind: "repaired-gyss" if
                (site, key, kind) == ("https://foundtextures.ca", "full", "gyss") else "")})
        with patch.object(found_textures, "snap_profiles", profiles), \
                patch.object(found_textures, "snap_connections", connections), \
                patch.object(found_textures, "snap_discovery", discovery):
            self.assertEqual(found_textures.resolve_profile(),
                             ("https://foundtextures.ca", "repaired-gyss"))
        self.assertEqual(saved[0]["extras"]["api_key_gyss"], "repaired-gyss")


if __name__ == "__main__":
    unittest.main()

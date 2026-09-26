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


if __name__ == "__main__":
    unittest.main()

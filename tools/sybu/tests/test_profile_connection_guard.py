import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class ProfileConnectionGuardTests(unittest.TestCase):
    def test_site_identity_is_canonicalized(self):
        source = (ROOT / "main.py").read_text(encoding="utf-8")
        helper = source.split("def canonical_site_url", 1)[1].split(
            "# Shared transport guard", 1
        )[0]
        self.assertIn("strip().rstrip('/').lower()", helper)

    def test_profile_picker_retires_client_and_auto_connects(self):
        source = (ROOT / "main.py").read_text(encoding="utf-8")
        method = source.split("    def _on_post_profile_pick", 1)[1].split(
            "    def _on_profile_load", 1
        )[0]
        self.assertIn("self._invalidate_connection", method)
        self.assertIn("self.after_idle(self._on_connect)", method)

    def test_post_guard_compares_visible_and_connected_sites(self):
        source = (ROOT / "main.py").read_text(encoding="utf-8")
        method = source.split("    def _ensure_connected", 1)[1].split(
            "    def _invalidate_connection", 1
        )[0]
        self.assertIn("visible_url != connected_url", method)


if __name__ == "__main__":
    unittest.main()

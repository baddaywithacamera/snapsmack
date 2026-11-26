"""Guard the 0.7.64 TWIGGY save format through load and send.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""

import pytest
from PySide6.QtWidgets import QApplication

import sumna_offline as O
from coldsnap_qt import biggie
from coldsnap_qt.mode_take import TakeMode
from parity_capture import isolated_settings
from sumna_post import SmacktalkPoster


@pytest.mark.parametrize("attributes", [
    "",
    ' target="_blank" rel="noopener"',
    ' rel="nofollow"',
    ' target="_blank" rel="noopener nofollow"',
])
def test_legacy_saved_twiggy_link_survives_visual_and_update(tmp_path, monkeypatch, attributes):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "home"))
    app = QApplication.instance() or QApplication([])
    # The 0.7.64 ShortcodeBar wrote literal HTML into the TWIGGY field.
    # The 0.7.64 Visual exporter stored only the label, which is unrecoverable.
    legacy_href = "https://example.test/camera?kind=thermal&source=essay"
    link_label = "thermal camera"
    legacy = f'Before <a href="{legacy_href}"{attributes}>{link_label}</a> after.'
    draft = O.Draft(draft_id="legacy-0764", kind=O.KIND_SMACKTALK,
                    mode=O.MODE_SMACKTALK, title="Legacy essay", caption=legacy,
                    body_blocks=biggie.blocks_to_json(biggie.parse_body(legacy)),
                    remote_post_id=4, slug="the-original-url")
    requests = []

    class FakeSite:
        def __init__(self):
            self.headers = {}

        def post(self, url, json, timeout):
            assert url.endswith("smackpress/posts")
            requests.append(json)
            return self

        def raise_for_status(self):
            pass

        @property
        def status_code(self):
            return 200

        def json(self):
            return {"post_id": 4, "slug": "the-original-url"}

    with isolated_settings(tmp_path):
        mode = TakeMode(lambda: {})
        try:
            session = mode.rail.ensure_session()
            session.add_draft(draft, copy_images=False)
            mode._edit(session.load_draft(draft.draft_id))
            assert mode.body.is_biggie()
            assert mode.body.canvas.toPlainText() == f"Before {link_label} after."
            mode.body._set_biggie(False)
            mode.body._set_biggie(True)
            saved = mode._build_draft(session)
            poster = SmacktalkPoster("https://example.test", "fixture", session=FakeSite())
            poster._record_to_library = lambda *args: None
            result = poster.sync_smacktalk(saved)
            assert result.ok, result.message
            assert len(requests) == 1
            assert requests[0]["post_id"] == 4
            assert requests[0]["slug"] == "the-original-url"
            expected = legacy.replace("&", "&amp;")
            assert requests[0]["content"] == expected
        finally:
            mode.close()
            mode.deleteLater()
            app.processEvents()


# ===== SNAPSMACK EOF =====

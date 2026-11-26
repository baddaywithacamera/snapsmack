"""Keep Visual links clickable in posts with captioned photos.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""

from PySide6.QtWidgets import QApplication

import sumna_offline as O
from coldsnap_qt import biggie, canvas
from coldsnap_qt.mode_take import TakeMode
from parity_capture import isolated_settings
from sumna_post import SmacktalkPoster


def test_visual_link_survives_captioned_image_send(tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "home"))
    app = QApplication.instance() or QApplication([])
    url = "https://example.test/camera?type=thermal&sort=new"
    label = "the camera"
    blocks = [
        {"type": "para", "text": "I brought the camera to the show. 1 < 2."},
        {"type": "image", "img_id": "12", "size": "full", "align": "center",
         "caption": "The car & its owner", "show_caption": True},
    ]
    draft = O.Draft(draft_id="link-test", kind=O.KIND_SMACKTALK,
                    mode=O.MODE_SMACKTALK, title="Essay", colophon="Camera notes",
                    body_blocks=biggie.blocks_to_json(blocks), remote_post_id=4,
                    slug="original-slug")
    requests = []

    class FakeSite:
        headers = {}
        status_code = 200

        def post(self, url, json, timeout):
            assert url.endswith("smackpress/posts")
            requests.append(json)
            return self

        def raise_for_status(self):
            pass

        def json(self):
            return {"post_id": 4}

    with isolated_settings(tmp_path):
        mode = TakeMode(lambda: {})
        try:
            session = mode.rail.ensure_session()
            session.add_draft(draft, copy_images=False)
            mode._edit(session.load_draft(draft.draft_id))
            visual = mode.body.canvas
            assert mode.body.is_biggie()
            selection = visual.document().find(label)
            assert not selection.isNull()
            visual.setTextCursor(selection)
            monkeypatch.setattr(canvas, "link_values", lambda *_: (url, label, True, True))
            visual.insert_link()
            saved = mode._build_draft(session)
            poster = SmacktalkPoster("https://example.test", "fixture", session=FakeSite())
            poster._record_to_library = lambda *args: None
            result = poster.sync_smacktalk(saved)
            assert result.ok, result.message
            assert len(requests) == 1
            packet = requests[0]
            assert (packet["post_id"], packet["slug"], packet["colophon"]) == (
                4, "original-slug", "Camera notes")
            assert ('<p>I brought <a href="https://example.test/camera?type=thermal&amp;sort=new"'
                    ' target="_blank" rel="noopener nofollow">the camera</a> to the show. 1 &lt; 2.</p>'
                    in packet["content"]), packet["content"]
            assert "<figcaption>The car &amp; its owner</figcaption>" in packet["content"]
        finally:
            mode.close()
            mode.deleteLater()
            app.processEvents()


# ===== SNAPSMACK EOF =====

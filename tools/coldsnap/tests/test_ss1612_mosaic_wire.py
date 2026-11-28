"""Sender regressions for standalone SMACKTALK mosaics.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""
import json
from types import SimpleNamespace

import pytest

from sumna_offline import Draft, DraftImage, KIND_SMACKTALK, MODE_SMACKTALK
from sumna_post import SmacktalkPoster


class FixtureSite:
    def __init__(self):
        self.posts = []

    def post(self, url, json, timeout):
        if url.endswith("/mosaics"):
            return SimpleNamespace(
                status_code=200, raise_for_status=lambda: None,
                json=lambda: {"mosaic_id": 752, "shortcode": "[mosaic:752]"},
            )
        assert url.endswith("/posts")
        self.posts.append(json)
        return SimpleNamespace(status_code=200, json=lambda: {"post_id": 4}, raise_for_status=lambda: None)


def test_authored_mosaic_goes_over_the_wire_between_top_level_paragraphs(monkeypatch):
    site = FixtureSite()
    poster = SmacktalkPoster("https://example.test", "fixture", session=site)
    monkeypatch.setattr(poster, "_record_to_library", lambda *_: None)
    draft = Draft(draft_id="test", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                  title="Test", remote_post_id=4, slug="start-your-engines",
                  caption='Before 1 < 2\n\n[mosaic=1 layout=asymmetric]\n\n'
                          'After <a href="https://example.test/">link</a>',
                  images=[DraftImage(local_path="fixture.jpg", remote_image_id=81)])
    result = poster.sync_smacktalk(draft)
    assert result.ok, result.message
    assert site.posts[0]["post_id"] == 4
    assert site.posts[0]["content"] == (
        '<p>Before 1 &lt; 2</p>\n\n[mosaic:752]\n\n'
        '<p>After <a href="https://example.test/">link</a></p>'
    )


def test_legacy_split_paragraph_retains_valid_markup_after_prose_edit():
    edited = '<p>[mosaic:752]Corrected <em>prose</em>.</p>\n<p>Leave this paragraph.</p>'
    assert SmacktalkPoster._standalone_mosaic_body(edited) == (
        '[mosaic:752]\n\n<p>Corrected <em>prose</em>.</p>\n<p>Leave this paragraph.</p>'
    )


def test_mosaic_only_posts_stay_out_of_cms_automatic_paragraph_conversion():
    assert SmacktalkPoster._standalone_mosaic_body("[mosaic:752]") == "<div></div>\n\n[mosaic:752]"


@pytest.mark.parametrize(("change", "expected"), [
    ("untouched", "<p>[mosaic:751]Prose.</p>\n<p>Other paragraph.</p>\n"),
    ("edit_prose", "[mosaic:751]\n\n<p>New &lt; note.</p>\n<p>Other paragraph.</p>\n"),
    ("edit_mosaic", "[mosaic:752]\n\n<p>Prose.</p>\n<p>Other paragraph.</p>\n"),
    ("remove_mosaic", "<p>Prose.</p>\n<p>Other paragraph.</p>\n"),
    ("remove_prose", "[mosaic:751]\n\n<p>Other paragraph.</p>\n"),
    ("move_mosaic", "<p>Prose.</p>\n<p>Other paragraph.</p>\n[mosaic:751]\n\n"),
])
def test_imported_pair_roundtrip_and_half_edits(change, expected, monkeypatch):
    wrap = {"open": "<p>", "close": "</p>", "group": "p1"}

    media = {"type": "site_mosaic", "mosaic_id": 751, "text": "[mosaic:751]",
             "src": "<p>[mosaic:751]", "sep": "", "wrap": wrap}
    prose = {"type": "para", "text": "Prose.", "src": "Prose.</p>", "sep": "\n", "wrap": wrap}
    blocks = [media, prose, {"type": "para", "src": "<p>Other paragraph.</p>", "sep": "\n"}]
    if change == "edit_prose":
        prose.pop("src")
        prose["text"] = "New < note."
    elif change == "edit_mosaic":
        media.pop("src")
        media["type"] = "mosaic"
        media["order"] = [1]
    elif change == "remove_mosaic":
        blocks.remove(media)
    elif change == "remove_prose":
        blocks.remove(prose)
    elif change == "move_mosaic":
        blocks.remove(media)
        blocks.append(media)
    site = FixtureSite()
    poster = SmacktalkPoster("https://example.test", "fixture", session=site)
    monkeypatch.setattr(poster, "_record_to_library", lambda *_: None)
    draft = Draft(draft_id="test-import", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                  title="Test", remote_post_id=4, slug="start-your-engines",
                  caption="<p>Stale caption cache.</p>", body_blocks=json.dumps(blocks),
                  images=[DraftImage(local_path="fixture.jpg", remote_image_id=81)])
    result = poster.sync_smacktalk(draft)
    assert result.ok, result.message
    assert site.posts[0]["content"] == expected


def test_canvas_mosaic_replacement_preserves_its_existing_site_identity():
    from test_coldsnap_ss1557_mosaic_arrangement import _app
    from coldsnap_qt import biggie
    from coldsnap_qt.canvas import BiggieCanvas

    _app()
    canvas = BiggieCanvas()
    origin = {"revision": "fixture"}
    canvas.from_blocks([{"type": "mosaic", "order": [1, 2], "layout": "rows",
                         "mosaic_id": 753, "gap": 7, "remote_mosaic": origin}])
    canvas._pending_obj_pos = canvas.document().begin().position()
    canvas.replace_mosaic([2, 1], "asymmetric", weights={"2": 2})
    block, = canvas.to_blocks()
    assert block["order"] == [2, 1]
    assert block["weights"] == {"2": 2}
    assert block["gap"] == 7
    assert block["remote_mosaic"] == origin
    assert biggie.serialize_block(block) == "[mosaic:753]"
    canvas.close()


@pytest.mark.parametrize("edited,shared,race", [
    (False, False, False), (False, True, False),
    (True, False, False), (True, True, False), (True, True, True),
])
@pytest.mark.parametrize("content", [
    "<p>[mosaic:753]Keep this paragraph.</p>",
    "[mosaic:753 width=760]\n\n<p>Keep this paragraph.</p>",
])
def test_published_mosaic_reuses_site_id_and_does_not_add_mosaic_only_photo_to_post_bucket(
    monkeypatch, edited, shared, race, content,
):
    from coldsnap_qt import biggie
    from coldsnap_qt.mode_take import hydrate_site_mosaics

    origin = {"id": 753, "revision": "first", "title": "Arranged mosaic",
              "asset_ids": [41, 36], "layout": "rows", "gap": 0}
    origin.update(focus_positions={"41": {"x": 50, "y": 30}}, emphasis="natural")
    origin["mosaic_weights"] = []
    origin["mosaic_mirrors"] = []
    blocks = biggie.parse_site_html(content)
    images = [DraftImage(asset_uuid="pictured", remote_image_id=36, is_cover=True),
              DraftImage(asset_uuid="mosaic-extra", remote_image_id=41, mosaic_only=True)]
    hydrate_site_mosaics(blocks, {753: {"mosaic": origin}}, images)
    assert blocks[0]["type"] == "mosaic"
    assert blocks[0]["order"] == ["mosaic-extra", "pictured"]
    assert blocks[0]["gap"] == 0
    if edited:
        blocks[0]["order"].reverse()
        blocks[0]["weights"] = {"pictured": 2}
    calls = []
    creations = []
    page_refs = int(shared and not race)
    definition = {"mosaic": origin, "references": {"posts": 1, "post_ids": [4],
                                                  "pages": page_refs, "shared": bool(page_refs)},
                  "images": [{"id": 41, "file": "img_uploads/extra photo.jpg", "title": "Extra", "alt": "",
                              "width": 3, "height": 2}, {"id": 90, "missing": True}]}

    class PublishedSite(FixtureSite):
        def get(self, url, timeout):
            if url.endswith("/mosaics/801"):
                return SimpleNamespace(status_code=200, raise_for_status=lambda: None,
                                       json=lambda: {"mosaic": {"id": 801, **creations[-1]}})
            assert url.endswith("/mosaics/753")
            return SimpleNamespace(status_code=200, raise_for_status=lambda: None,
                                   json=lambda: definition)

        def put(self, url, timeout, json):
            assert url.endswith("/mosaics/753")
            calls.append(json)
            if race:
                definition["references"]["posts"] = 2
                definition["references"]["post_ids"] = [4, 11]
                return SimpleNamespace(status_code=409)
            return SimpleNamespace(status_code=200, raise_for_status=lambda: None,
                                   json=lambda: {"mosaic": {**origin, **json, "revision": "next"}})

        def post(self, url, json, timeout):
            if url.endswith("/mosaics"):
                assert edited and shared
                creations.append(json)
                return SimpleNamespace(status_code=200, raise_for_status=lambda: None,
                                       json=lambda: {"mosaic_id": 801, "mosaic_weights": {"36": 2}})
            return super().post(url, json, timeout)

    site = PublishedSite()
    poster = SmacktalkPoster("https://example.test", "fixture", session=site)
    monkeypatch.setattr(poster, "_record_to_library", lambda *_: None)
    loaded = poster.get_mosaic(753)
    assert loaded["mosaic"] == definition["mosaic"]
    assert loaded["images"] == [{"id": 41, "img_file": "img_uploads/extra photo.jpg",
                                 "img_title": "Extra", "img_alt": "", "width": 3, "height": 2,
                                 "url": "https://example.test/img_uploads/extra%20photo.jpg"}]
    draft = Draft(draft_id="edit", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                  title="Test", remote_post_id=4, caption=content,
                  body_blocks=biggie.blocks_to_json(blocks), images=images)
    result = poster.sync_smacktalk(draft)
    assert result.ok, result.message
    expected_put = ([{"revision": "first", "post_id": 4, "title": "Arranged mosaic",
                      "asset_ids": [36, 41], "layout": "rows", "gap": 0,
                      "mosaic_weights": {"36": 2}, "mosaic_mirrors": [],
                      "focus_positions": {"41": {"x": 50, "y": 30}}, "emphasis": "natural"}]
                    if edited and (not shared or race) else [])
    assert calls == expected_put
    assert site.posts[0]["content"] == (content.replace("753", "801") if edited and shared else content)
    assert site.posts[0]["bucket_image_ids"] == [36]
    if edited and shared:
        assert len(creations) == 1 and creations[0]["asset_ids"] == [36, 41]
        assert creations[0]["focus_positions"] == {"41": {"x": 50, "y": 30}}
        assert creations[0]["emphasis"] == "natural"
        assert origin["asset_ids"] == [41, 36]
        assert "another post or page" in result.message
        reloaded = Draft.from_dict(draft.to_dict())
        definition["references"] = {"posts": 1, "post_ids": [11], "pages": 0}
        assert poster.sync_smacktalk(reloaded).ok
        assert len(creations) == 1
        assert calls == expected_put
    else:
        assert creations == []


def test_imported_mosaic_opens_the_existing_visual_editor(monkeypatch, tmp_path):
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QDialog, QLabel
    from test_coldsnap_ss1557_mosaic_arrangement import _app, _photos, _wait_for_preview
    from coldsnap_qt import biggie, body_editor
    from coldsnap_qt.mode_take import TakeMode, hydrate_site_mosaics

    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})
    try:
        paths = _photos(tmp_path, 3)
        images = [DraftImage(local_path=path, remote_image_id=image_id)
                  for image_id, path in zip([36, 41, 902], paths)]
        blocks = biggie.parse_site_html("[mosaic:753]")
        hydrate_site_mosaics(blocks, {753: {
            "mosaic": {"id": 753, "revision": "fixture", "asset_ids": [41, 36, 902],
                       "layout": "one-left", "gap": 0, "mosaic_weights": {"41": 2}},
            "references": {"posts": 2, "shared": True},
        }}, images)
        draft = Draft(draft_id="edit", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                      title="Mosaic fixture", remote_post_id=4, caption="[mosaic:753]",
                      body_blocks=biggie.blocks_to_json(blocks), images=images)
        mode._edit(draft)
        mode.resize(1440, 900)
        mode.show()
        canvas = mode.body.canvas
        hit = canvas._object_at(canvas.document().begin().position())
        assert hit[0] == "mosaic"
        assert hit[1]["order"] == [images[index].asset_uuid for index in (1, 0, 2)]
        for _ in range(120):
            tiles = canvas._inline_mosaic_hits.get(hit[2], ({}, ()))[0]
            if len(tiles) == 3 and all(
                canvas._objects._cached_cell_pixmap(canvas.assets[identity], rect.size()) is not None
                for identity, rect in tiles.values()
            ):
                break
            QTest.qWait(25)
            canvas.viewport().update()
        else:
            pytest.fail("The canvas did not finish painting all three mosaic photos.")
        assert canvas.grab().save(str(tmp_path / "fixture-753-canvas.png"))
        observations = []

        def inspect_editor(dialog):
            observations.extend(_wait_for_preview(dialog._mosaic_preview))
            assert dialog.windowTitle() == "Edit mosaic"
            assert any("appears on 2 posts or pages" in label.text() for label in dialog.findChildren(QLabel))
            dialog.show()
            QTest.qWait(100)
            assert dialog.grab().save(str(tmp_path / "fixture-753-editor.png"))
            dialog.hide()
            return QDialog.Rejected

        monkeypatch.setattr(QDialog, "exec", inspect_editor)
        canvas._edit_mosaic(hit)
        assert len(observations) == 3
        monkeypatch.setattr(body_editor, "QSettings", lambda *_: SimpleNamespace(setValue=lambda *_: None))
        mode.body._set_biggie(False)
        mode.body.editor.appendPlainText("\nKeep this new paragraph.")
        mode.body._set_biggie(True)
        assert mode.body.canvas.to_blocks()[0]["mosaic_id"] == 753
        assert mode.body.canvas.to_blocks()[0]["remote_mosaic"]["revision"] == "fixture"
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====

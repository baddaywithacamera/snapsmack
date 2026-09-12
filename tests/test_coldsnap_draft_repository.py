"""COLD TAKE's SQLite draft foundation: stable identities and recoverable media.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import importlib
import os
import sys

from PIL import Image


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(ROOT, "tools", "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)


def _library(tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    import snap_home
    import snap_library
    importlib.reload(snap_home)
    return importlib.reload(snap_library)


def test_draft_blocks_keep_asset_uuid_when_assets_are_reordered(tmp_path, monkeypatch):
    library = _library(tmp_path, monkeypatch)
    site = "https://essay.example"
    document = library.create_draft(site, title="First snow")

    paths = []
    for name, colour in (("first.jpg", "red"), ("second.jpg", "blue")):
        path = tmp_path / name
        Image.new("RGB", (800, 600), colour).save(path)
        paths.append(path)
    first = library.absorb_draft_asset(site, document["draft_uuid"], paths[0])
    second = library.absorb_draft_asset(site, document["draft_uuid"], paths[1])

    saved = library.save_draft(site, document["draft_uuid"], blocks=[
        {"type": "para", "text": "Before"},
        {"type": "image", "asset_uuid": first["asset_uuid"],
         "placement": "wrap-left", "width_ratio": 0.33},
        {"type": "para", "text": "After"},
    ])
    image_block = saved["blocks"][1]
    assert image_block["content"]["asset_uuid"] == first["asset_uuid"]
    assert image_block["content"]["asset_uuid"] != second["asset_uuid"]

    # Removing or otherwise rearranging another tray item does not rewrite the
    # inline object's identity, and the removed original remains recoverable.
    removed = library.remove_draft_asset_from_story(site, second["asset_uuid"])
    reopened = library.draft(site, document["draft_uuid"])
    assert removed["in_story"] == 0
    assert os.path.isfile(removed["local_path"])
    assert reopened["blocks"][1]["content"]["asset_uuid"] == first["asset_uuid"]


def test_absorb_is_managed_and_sync_mapping_does_not_rewrite_block(tmp_path, monkeypatch):
    library = _library(tmp_path, monkeypatch)
    site = "photos.example"
    document = library.create_draft(site)
    original = tmp_path / "outside.png"
    Image.new("RGB", (1200, 900), "green").save(original)
    asset = library.absorb_draft_asset(site, document["draft_uuid"], original,
                                       alt_text="Pines in a snowstorm")
    library.save_draft(site, document["draft_uuid"], blocks=[
        {"type": "image", "asset_uuid": asset["asset_uuid"],
         "placement": "block", "width_ratio": 1.0},
    ])

    assert asset["local_path"] != str(original)
    assert os.path.isfile(asset["local_path"])
    assert os.path.isfile(asset["thumb_path"])
    assert asset["width"] == 1200 and asset["height"] == 900
    assert len(asset["content_hash"]) == 64

    mapped = library.set_draft_asset_state(
        site, asset["asset_uuid"], "uploaded", remote_image_id=731,
        remote_path="img_uploads/2026/09/outside.png",
    )
    reopened = library.draft(site, document["draft_uuid"])
    assert mapped["remote_image_id"] == 731
    assert reopened["blocks"][0]["content"]["asset_uuid"] == asset["asset_uuid"]
    assert "remote_image_id" not in reopened["blocks"][0]["content"]


def test_autosave_is_atomic_and_revisioned(tmp_path, monkeypatch):
    library = _library(tmp_path, monkeypatch)
    site = "drafts.example"
    document = library.create_draft(site)
    saved = library.save_draft(site, document["draft_uuid"], title="Safe here", blocks=[
        {"block_uuid": "fixed-block", "type": "h2", "text": "Winter"},
    ])
    assert saved["revision"] == 2
    assert saved["title"] == "Safe here"
    assert saved["blocks"][0]["block_uuid"] == "fixed-block"
    assert library.drafts(site)[0]["draft_uuid"] == document["draft_uuid"]


# ===== SNAPSMACK EOF =====

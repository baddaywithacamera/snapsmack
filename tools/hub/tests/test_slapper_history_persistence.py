# SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.

import os
import sys

from PIL import Image


HUB = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if HUB not in sys.path:
    sys.path.insert(0, HUB)

import editor_engine


def _photo(tmp_path):
    path = tmp_path / "photo.jpg"
    Image.new("RGB", (8, 8), "white").save(path)
    return str(path)


def test_project_round_trip_keeps_history_and_active_position(tmp_path):
    document = editor_engine.EditorDocument(_photo(tmp_path))
    document.adjustments["brightness"] = 10
    document.record("Brightness")
    document.adjustments["contrast"] = 20
    document.record("Contrast")
    document.undo()

    project = str(tmp_path / "photo.slapper")
    document.save_project(project)
    loaded = editor_engine.EditorDocument.load_project(project)

    assert [item["label"] for item in loaded.history] == [
        "Open image", "Brightness", "Contrast"]
    assert loaded.history_index == 1
    assert loaded.adjustments["brightness"] == 10
    assert loaded.adjustments["contrast"] == 0
    assert loaded.redo()
    assert loaded.adjustments["contrast"] == 20


def test_101st_edit_is_rejected_when_checkpoint_is_cancelled(tmp_path):
    document = editor_engine.EditorDocument(_photo(tmp_path))
    for step in range(1, editor_engine.MAX_HISTORY_STEPS):
        document.adjustments["brightness"] = step
        assert document.record(f"Step {step}")

    document.history_limit_handler = lambda _document: False
    document.adjustments["brightness"] = 999

    assert not document.record("Rejected")
    assert len(document.history) == editor_engine.MAX_HISTORY_STEPS
    assert document.adjustments["brightness"] == 99


def test_101st_edit_follows_a_saved_checkpoint(tmp_path):
    document = editor_engine.EditorDocument(_photo(tmp_path))
    for step in range(1, editor_engine.MAX_HISTORY_STEPS):
        document.adjustments["brightness"] = step
        document.record(f"Step {step}")

    checkpoint = str(tmp_path / "checkpoint.slapper")

    def save(current):
        current.save_project(checkpoint)
        return True

    document.history_limit_handler = save
    document.adjustments["brightness"] = 100

    assert document.record("Step 100")
    assert [item["label"] for item in document.history] == [
        "Saved checkpoint", "Step 100"]
    assert document.adjustments["brightness"] == 100

    saved = editor_engine.EditorDocument.load_project(checkpoint)
    assert len(saved.history) == editor_engine.MAX_HISTORY_STEPS
    assert saved.adjustments["brightness"] == 99

# ===== SNAPSMACK EOF =====

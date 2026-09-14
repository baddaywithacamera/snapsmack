import os
import sys
import zipfile
from pathlib import Path

from PIL import Image


HUB = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HUB))

import editor_engine
import slapper_provenance


def _operation(mask, version="test"):
    before = Image.new("RGB", mask.size, "black")
    after = Image.new("RGB", mask.size, "white")
    return slapper_provenance.new_ai_operation(
        operation_class="B", tool_name="AI Heal", purpose="dust",
        provider="Google Gemini", model="test-model", instruction="remove dust",
        sent_mask=mask, input_image=before, output_image=after,
        app_version=version)


def test_sent_mask_not_feathered_blend_defines_affected_area():
    mask = Image.new("L", (20, 20), 0)
    for y in range(8, 12):
        for x in range(8, 12):
            mask.putpixel((x, y), 255)
    operation = _operation(mask)
    layer = {"visible": True, "opacity": 1.0, "provenance": operation}
    first = slapper_provenance.export_operations([layer], (20, 20))[0]
    layer["mask"] = "a deliberately different feathered display mask"
    second = slapper_provenance.export_operations([layer], (20, 20))[0]
    assert first["sent_mask_pixel_count"] == 16
    assert first["affected_percentage_of_exported_canvas"] == 4.0
    assert second["affected_percentage_of_exported_canvas"] == 4.0


def test_semantic_ai_heal_is_promoted_to_generative_alteration():
    assert slapper_provenance.classify_heal_instruction("remove this dust spot") == "B"
    assert slapper_provenance.classify_heal_instruction(
        "replace the person with a dog") == "C"


def test_active_snapshot_controls_export_provenance(tmp_path):
    source = tmp_path / "source.png"
    Image.new("RGB", (20, 20), "gray").save(source)
    document = editor_engine.EditorDocument(str(source))
    mask = Image.new("L", (20, 20), 255)
    layer = document.add_image_layer(str(source), name="AI Heal", record=False)
    layer["provenance"] = _operation(mask)
    document.record("AI Heal")
    assert len(slapper_provenance.export_operations(document.layers, (20, 20))) == 1
    assert document.undo()
    assert slapper_provenance.export_operations(document.layers, (20, 20)) == []
    assert document.redo()
    assert len(slapper_provenance.export_operations(document.layers, (20, 20))) == 1


def test_exported_xmp_round_trips_in_supported_formats(tmp_path):
    source = tmp_path / "source.png"
    Image.new("RGB", (20, 20), "gray").save(source)
    document = editor_engine.EditorDocument(str(source))
    mask = Image.new("L", (20, 20), 0); mask.putpixel((5, 6), 255)
    layer = document.add_image_layer(str(source), name="AI Heal", record=False)
    layer["provenance"] = _operation(mask)
    document.record("AI Heal")
    for extension in ("jpg", "png", "tif", "webp"):
        target = tmp_path / f"out.{extension}"
        document.export(str(target))
        with Image.open(target) as reopened:
            xmp = (reopened.info.get("xmp") or
                   reopened.info.get("XML:com.adobe.xmp") or
                   (reopened.tag_v2.get(700) if hasattr(reopened, "tag_v2") else None) or
                   b"")
        records = slapper_provenance.read_operations(xmp)
        assert len(records) == 1, extension
        assert records[0]["sent_mask_pixel_count"] == 1


def test_export_record_redacts_credentials_and_private_paths():
    mask = Image.new("L", (2, 2), 255)
    operation = _operation(mask)
    operation["instruction_verbatim"] = r"use C:\Users\Sean\private.jpg api_key=topsecret"
    operation["instruction_summary"] = r"use C:\Users\Sean\private.jpg api_key=topsecret"
    record = slapper_provenance.export_operations(
        [{"visible": True, "opacity": 1, "provenance": operation}], (2, 2))[0]
    assert "topsecret" not in record["instruction_summary"]
    assert "C:\\Users" not in record["instruction_summary"]
    assert "instruction_verbatim" not in record


def test_expand_layer_changes_canvas_without_replacing_interior(tmp_path):
    source = tmp_path / "source.png"
    Image.new("RGB", (10, 10), "red").save(source)
    generated = tmp_path / "expanded.png"
    Image.new("RGB", (14, 14), "blue").save(generated)
    document = editor_engine.EditorDocument(str(source))
    document.layers.append({
        "id": "expand", "name": "Generative Expand",
        "type": "generative_expand", "path": str(generated),
        "content_box": [2, 2, 12, 12], "visible": True,
        "opacity": 1.0, "blend": "normal",
    })
    result = document.render()
    assert result.size == (14, 14)
    assert result.getpixel((0, 0))[:3] == (0, 0, 255)
    assert result.getpixel((5, 5))[:3] == (255, 0, 0)


def test_verbatim_instruction_stays_in_project_and_out_of_export(tmp_path):
    source = tmp_path / "source.png"
    Image.new("RGB", (8, 8), "gray").save(source)
    document = editor_engine.EditorDocument(str(source))
    mask = Image.new("L", (8, 8), 255)
    layer = document.add_image_layer(str(source), name="AI Heal", record=False)
    layer["provenance"] = _operation(mask)
    secret_words = "remove Sean standing beside the private blue house"
    layer["provenance"]["instruction_verbatim"] = secret_words
    document.record("AI Heal")

    project = tmp_path / "private.slapper"
    document.save_project(str(project))
    with zipfile.ZipFile(project) as archive:
        assert secret_words in archive.read("project.json").decode("utf-8")

    exported = tmp_path / "public.jpg"
    document.export(str(exported))
    assert secret_words.encode("utf-8") not in exported.read_bytes()
    with Image.open(exported) as reopened:
        records = slapper_provenance.read_operations(reopened.info["xmp"])
    assert "instruction_verbatim" not in records[0]
    assert records[0]["instruction_summary"]

# ===== SNAPSMACK EOF =====

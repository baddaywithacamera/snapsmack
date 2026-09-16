import os
import sys

import numpy as np
import OpenImageIO as oiio
import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
import highbit_image


def test_ingress_rejects_unsupported_decoder_before_open(tmp_path, monkeypatch):
    source = tmp_path / "unneeded.psd"
    source.write_bytes(b"not decoded")
    monkeypatch.setattr(highbit_image.oiio.ImageInput, "open",
                        lambda _path: pytest.fail("unsupported format reached OIIO"))
    with pytest.raises(ValueError, match="Unsupported image format"):
        highbit_image.read(source)


def test_ingress_rejects_oversized_dimensions_before_pixel_decode(tmp_path, monkeypatch):
    source = tmp_path / "huge.tif"
    source.write_bytes(b"header")

    class Input:
        def spec(self):
            return type("Spec", (), {"width": 500_000, "height": 500_000,
                                      "nchannels": 3})()

        def close(self):
            pass

    monkeypatch.setattr(highbit_image.oiio.ImageInput, "open", lambda _path: Input())
    monkeypatch.setattr(highbit_image.oiio, "ImageBuf",
                        lambda _path: pytest.fail("unsafe image reached pixel decode"))
    with pytest.raises(ValueError, match="safe pixel limit"):
        highbit_image.read(source)


def test_ingress_uses_bounded_decoder_worker(tmp_path, monkeypatch):
    source = tmp_path / "bounded.tif"
    source.write_bytes(b"header")

    class Input:
        def spec(self):
            return type("Spec", (), {"width": 10, "height": 10, "nchannels": 3})()

        def close(self):
            pass

    seen = {}
    monkeypatch.setattr(highbit_image.oiio.ImageInput, "open", lambda _path: Input())

    def fake_run(command, **kwargs):
        seen.update(kwargs)
        target = command[-1]
        np.savez(target, pixels=np.zeros((10, 10, 3), dtype=np.float32),
                 channel_names=np.asarray(("R", "G", "B")),
                 source_format=np.asarray(("uint16",)),
                 icc_profile=np.asarray((), dtype=np.uint8))
        return type("Result", (), {"returncode": 0, "stdout": b"", "stderr": b""})()

    monkeypatch.setattr(highbit_image.subprocess_limits, "run", fake_run)
    assert highbit_image.read(source).size == (10, 10)
    assert seen["memory_bytes"] == highbit_image.MAX_FLOAT_BYTES
    assert seen["timeout"] == 180


def test_float_ingress_retains_uint16_steps_and_headroom(tmp_path):
    source = tmp_path / "sixteen-bit.tif"
    values = np.array([[[1, 257, 65535], [1024, 32768, 65000]]], dtype=np.uint16)
    spec = oiio.ImageSpec(2, 1, 3, oiio.TypeUInt16)
    out = oiio.ImageOutput.create(str(source))
    assert out.open(str(source), spec)
    assert out.write_image(values)
    out.close()

    image = highbit_image.read(source)
    assert image.pixels.dtype == np.float32
    assert np.isclose(image.pixels[0, 0, 1], 257 / 65535)
    lifted = highbit_image.FloatImage(image.pixels * 1.5, image.channel_names)
    assert lifted.pixels.max() > 1.0


def test_uint16_export_quantises_once_at_output(tmp_path):
    target = tmp_path / "export.tif"
    values = np.array([[[0.0, 0.5, 1.0], [1.25, -0.25, 0.25]]], dtype=np.float32)
    highbit_image.write(highbit_image.FloatImage(values, ("R", "G", "B")), target)
    stored = oiio.ImageBuf(str(target))
    assert stored.spec().format == oiio.TypeUInt16
    result = stored.get_pixels(oiio.UINT16)
    assert result[0, 0].tolist() == [0, 32768, 65535]
    assert result[0, 1].tolist() == [65535, 0, 16384]


def test_display_proxy_is_not_the_working_image():
    values = np.full((1200, 1800, 3), 0.5, dtype=np.float32)
    working = highbit_image.FloatImage(values, ("R", "G", "B"))
    proxy = working.display_proxy((900, 900))
    assert proxy.size == (900, 600)
    assert proxy.mode == "RGB"
    assert working.size == (1800, 1200)
    assert working.pixels.dtype == np.float32


def test_float_adjustments_preserve_values_above_display_white():
    values = np.array([[[0.25, 0.5, 0.75], [0.8, 0.9, 1.0]]], dtype=np.float32)
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    result = highbit_image.apply_adjustments(source, {"exposure": 1.0})
    assert result.pixels.dtype == np.float32
    assert result.pixels[0, 1, 2] == 2.0
    # The preview clips for the monitor without changing the working samples.
    assert result.display_proxy().getpixel((1, 0))[2] == 255
    assert result.pixels[0, 1, 2] == 2.0


def test_raw_noise_reduction_visibly_reduces_luminance_variation():
    rng = np.random.default_rng(17)
    values = np.clip(.5 + rng.normal(0, .12, (96, 96, 3)), 0, 1).astype(np.float32)
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    reduced = highbit_image.apply_adjustments(
        source, {"raw_noise_reduction": 100})
    before = np.std(highbit_image.luminance(source.pixels))
    after = np.std(highbit_image.luminance(reduced.pixels))
    assert after < before * .55
    assert reduced.pixels.dtype == np.float32


def test_float_layer_blend_uses_masks_and_keeps_headroom():
    bottom = highbit_image.FloatImage(
        np.array([[[0.5, 0.5, 0.5], [1.2, 0.5, 0.25]]], dtype=np.float32),
        ("R", "G", "B"))
    top = highbit_image.FloatImage(
        np.array([[[0.5, 0.25, 1.0], [0.5, 0.5, 0.5]]], dtype=np.float32),
        ("R", "G", "B"))
    result = highbit_image.blend(bottom, top, "multiply", mask=np.array([[1.0, 0.0]]))
    assert np.allclose(result.pixels[0, 0], [0.25, 0.125, 0.5])
    assert np.allclose(result.pixels[0, 1], bottom.pixels[0, 1])
    assert result.pixels[0, 1, 0] > 1.0


def test_spatial_adjustments_remain_float_and_do_not_clip():
    values = np.zeros((24, 24, 3), dtype=np.float32)
    values[8:16, 8:16] = 1.4
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    result = highbit_image.apply_adjustments(source, {
        "clarity": 30, "texture": 20, "sharpen": 20,
        "sharpen_mode": "lens", "grain": 8, "glow_amount": 10,
    })
    assert result.pixels.dtype == np.float32
    assert result.size == source.size
    assert np.isfinite(result.pixels).all()
    assert result.pixels.max() > 1.0


def test_gaussian_blur_operates_on_float_headroom():
    values = np.zeros((21, 21, 3), dtype=np.float32)
    values[10, 10] = 2.0
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    result = highbit_image.gaussian_blur(source, 2.0)
    assert 0.0 < result.pixels[10, 10, 0] < 2.0
    assert result.pixels.dtype == np.float32


def test_float_geometry_never_uses_display_proxy():
    values = np.zeros((5, 7, 3), dtype=np.float32)
    values[2, 3] = [1.5, .5, .25]
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    flipped = source.flipped(horizontal=True)
    assert np.allclose(flipped.pixels[2, 3], [1.5, .5, .25])
    rotated = highbit_image.rotate(source, 90)
    assert rotated.pixels.dtype == np.float32
    assert rotated.size == (5, 7)
    assert rotated.pixels.max() > 1.0


def test_identity_projective_and_lens_preserve_float_samples():
    values = np.linspace(0, 1.5, 6 * 8 * 3, dtype=np.float32).reshape(6, 8, 3)
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    identity = highbit_image.projective(source, (1, 0, 0, 0, 1, 0, 0, 0))
    assert np.allclose(identity.pixels[:, :, :3], values)
    lens = highbit_image.radial_lens(source, {})
    assert np.allclose(lens.pixels[:, :, :3], values)


def test_pillow_conversion_is_explicitly_tagged_as_graphic_asset():
    from PIL import Image
    graphic = highbit_image.from_pillow(Image.new("RGBA", (3, 2), (128, 64, 32, 255)))
    assert graphic.source_format == "uint8-graphic"
    assert graphic.pixels.dtype == np.float32
    assert graphic.pixels.shape == (2, 3, 4)


def test_every_filter_layer_stays_float32():
    values = np.linspace(0.0, 1.4, 28 * 32 * 3, dtype=np.float32).reshape(28, 32, 3)
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    cases = {
        "gaussian_blur": {"radius": 2, "amount": 60},
        "motion_blur": {"length": 7, "angle": 20, "amount": 60},
        "radial_blur": {"strength": 5, "mode": "spin", "amount": 60},
        "orton": {"radius": 2, "amount": 60},
        "film_grain": {"roughness": 20, "amount": 60, "seed": 4},
        "light_leak": {"amount": 60, "edge": "left"},
        "pastel": {"amount": 60, "softness": 2},
    }
    for kind, settings in cases.items():
        result = highbit_image.apply_filter(source, kind, settings)
        assert result.pixels.dtype == np.float32, kind
        assert result.size == source.size, kind
        assert np.isfinite(result.pixels).all(), kind
    # At least blur retains source values beyond display white.
    assert highbit_image.apply_filter(
        source, "gaussian_blur", {"radius": 1, "amount": 100}).pixels.max() > 1.0


def test_float_layer_fit_and_placement_preserve_source_precision():
    values = np.zeros((2, 4, 3), dtype=np.float32)
    values[:, :, 0] = 1.25
    source = highbit_image.FloatImage(values, ("R", "G", "B"))
    covered = highbit_image.fit_layer(source, (8, 8), "cover")
    assert covered.size == (8, 8)
    assert np.isclose(covered.pixels[:, :, 0].max(), 1.25)
    placed = highbit_image.place_layer(source, (10, 8), {
        "x": .5, "y": .5, "scale_x": 1, "scale_y": 1,
        "rotation": 0, "flip_x": False, "flip_y": False,
    })
    assert placed.size == (10, 8)
    assert placed.pixels.shape[2] == 4
    assert placed.pixels[:, :, 0].max() > 1.0
    assert placed.pixels[:, :, 3].min() == 0.0


def test_editor_document_exports_real_uint16_from_float_composite(tmp_path, monkeypatch):
    # Import only after the hub/shared paths installed by this test module.
    import editor_engine

    source = tmp_path / "source.tif"
    values = np.linspace(0.0, 1.0, 10 * 12 * 3, dtype=np.float32).reshape(10, 12, 3)
    highbit_image.write(highbit_image.FloatImage(values, ("R", "G", "B")), source)
    document = editor_engine.EditorDocument(str(source))
    document.adjustments["exposure"] = 1.0
    assert document.render_float().pixels.max() > 1.0
    target = tmp_path / "finished.tif"
    document.export(str(target))
    stored = oiio.ImageBuf(str(target))
    assert stored.spec().format == oiio.TypeUInt16
    assert stored.spec().get_int_attribute("oiio:BitsPerSample", 0) == 16
    assert stored.get_pixels(oiio.UINT16).max() == 65535


def test_raw_project_references_original_and_keeps_typed_artifacts(tmp_path, monkeypatch):
    import zipfile
    import editor_engine
    import raw_preview

    raw = tmp_path / "original.raf"
    raw.write_bytes(b"raw-original")
    master = tmp_path / "developed.tif"
    highbit_image.write(highbit_image.FloatImage(
        np.full((4, 6, 3), .4, dtype=np.float32), ("R", "G", "B")), master)
    profile = tmp_path / "developed.pp3"
    profile.write_text("[Version]\n", encoding="utf-8")
    document = editor_engine.EditorDocument(str(master))
    document.attach_raw_source(str(raw), str(master), str(profile), "RT-test")
    project = tmp_path / "edit.slapper"
    document.save_project(str(project))
    with zipfile.ZipFile(project) as archive:
        assert "original/source.raf" not in archive.namelist()
        value = __import__("json").loads(archive.read("project.json"))
    assert value["source_ingredient"]["external"] is True
    assert value["artifacts"]
    monkeypatch.setattr(raw_preview, "develop", lambda *_args, **_kwargs: str(master))
    reopened = editor_engine.EditorDocument.load_project(
        str(project), trust_external_source=True)
    assert reopened.original_artifact_id == document.original_artifact_id
    assert reopened.edit_source_artifact_id == document.edit_source_artifact_id
    assert reopened.artifacts.edit_source(reopened.edit_source_artifact_id).path == str(master)


def test_invalid_project_is_rejected_before_raw_development(tmp_path, monkeypatch):
    import json
    import editor_engine
    import raw_preview

    raw = tmp_path / "local.orf"
    raw.write_bytes(b"raw")
    project = tmp_path / "hostile.slapper"
    project.write_text(json.dumps({
        "version": editor_engine.PROJECT_VERSION,
        "source_path": str(raw),
        "adjustments": "not-an-object",
    }), encoding="utf-8")
    monkeypatch.setattr(raw_preview, "develop",
                        lambda *_args, **_kwargs: pytest.fail("invalid project acted on RAW"))
    with pytest.raises(ValueError, match="adjustments has the wrong type"):
        editor_engine.EditorDocument.load_project(project)


def test_external_project_requires_approval_before_file_access(tmp_path, monkeypatch):
    import json
    import editor_engine

    source = tmp_path / "private.tif"
    source.write_bytes(b"private")
    project = tmp_path / "received.slapper"
    project.write_text(json.dumps({
        "version": editor_engine.PROJECT_VERSION,
        "source_path": str(source),
        "adjustments": {}, "geometry": {}, "layers": [], "retouched": [],
    }), encoding="utf-8")
    monkeypatch.setattr(editor_engine.os.path, "isfile",
                        lambda _path: pytest.fail("external path touched before approval"))
    with pytest.raises(editor_engine.ExternalProjectSourceApprovalRequired):
        editor_engine.EditorDocument.load_project(project)


def test_project_archive_rejects_traversal_member(tmp_path):
    import zipfile
    import editor_engine

    project = tmp_path / "traversal.slapper"
    with zipfile.ZipFile(project, "w") as archive:
        archive.writestr("project.json", '{"version":1,"source_path":"x"}')
        archive.writestr("../outside.txt", "no")
    with pytest.raises(ValueError, match="unsafe archive path"):
        editor_engine.EditorDocument.load_project(project)


def test_project_archive_rejects_symlink_member(tmp_path):
    import zipfile
    import editor_engine

    project = tmp_path / "symlink.slapper"
    link = zipfile.ZipInfo("original/source.tif")
    link.create_system = 3
    link.external_attr = (0o120777 << 16)
    with zipfile.ZipFile(project, "w") as archive:
        archive.writestr("project.json", '{"version":1,"source_path":"x"}')
        archive.writestr(link, "target")
    with pytest.raises(ValueError, match="symbolic link"):
        editor_engine.EditorDocument.load_project(project)


# ===== SNAPSMACK EOF =====

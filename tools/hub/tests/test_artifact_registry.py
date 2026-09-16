import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from artifact_registry import ArtifactKind, ArtifactRegistry


def test_profile_and_proxy_can_never_be_edit_or_export_sources(tmp_path):
    registry = ArtifactRegistry()
    raw_id = registry.assign(ArtifactKind.ORIGINAL_RAW, tmp_path / "camera.raf")
    profile_id = registry.assign(ArtifactKind.RAW_PROFILE, tmp_path / "settings.pp3",
                                 source_refs=(raw_id,))
    proxy_id = registry.derive(ArtifactKind.DISPLAY_PROXY, tmp_path / "medium.jpg",
                               source_refs=(raw_id,), producer_build="preview-v2")
    with pytest.raises(TypeError):
        registry.image(profile_id)
    with pytest.raises(TypeError):
        registry.edit_source(proxy_id)
    with pytest.raises(TypeError):
        registry.export_source(proxy_id)


def test_developed_identity_comes_from_sources_not_path(tmp_path):
    registry = ArtifactRegistry()
    raw_id = registry.assign(ArtifactKind.ORIGINAL_RAW, tmp_path / "camera.raf")
    profile_id = registry.assign(ArtifactKind.RAW_PROFILE, tmp_path / "settings.pp3",
                                 source_refs=(raw_id,))
    first = registry.derive(ArtifactKind.DEVELOPED_MASTER, tmp_path / "one.tif",
                            source_refs=(raw_id, profile_id), producer_build="RT-5.12")
    second_registry = ArtifactRegistry.from_value(registry.value()[:2])
    second = second_registry.derive(ArtifactKind.DEVELOPED_MASTER, tmp_path / "elsewhere.tif",
                                    source_refs=(raw_id, profile_id), producer_build="RT-5.12")
    assert first == second


def test_registry_round_trip_keeps_typed_provenance(tmp_path):
    registry = ArtifactRegistry()
    original = registry.assign(ArtifactKind.ORIGINAL_RASTER, tmp_path / "photo.tif",
                               bit_depth="uint16", colorspace="RTv4_sRGB")
    export = registry.derive(ArtifactKind.EXPORT_SOURCE, tmp_path / "working.exr",
                             source_refs=(original,), bit_depth="float32",
                             colorspace="RTv4_sRGB", producer_build="SNAP-SLAPPER-test")
    restored = ArtifactRegistry.from_value(registry.value())
    assert restored.export_source(export).source_refs == (original,)


def test_relocation_changes_path_without_changing_identity(tmp_path):
    registry = ArtifactRegistry()
    artifact_id = registry.assign(ArtifactKind.ORIGINAL_RAW, tmp_path / "old.raf")
    registry.relocate(artifact_id, tmp_path / "mounted" / "new.raf")
    assert registry.get(artifact_id).id == artifact_id
    assert registry.get(artifact_id).path.endswith(os.path.join("mounted", "new.raf"))


# ===== SNAPSMACK EOF =====

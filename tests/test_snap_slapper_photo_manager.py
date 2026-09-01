"""Regression checks for SNAP SLAPPER's immutable-original file operations.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import hashlib
import errno
import json
import os
import sys
import tempfile
import unittest
import zipfile
from unittest import mock

from PIL import Image


REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HUB_ROOT = os.path.join(REPOSITORY_ROOT, "tools", "hub")
if HUB_ROOT not in sys.path:
    sys.path.insert(0, HUB_ROOT)

import photo_manager
import editor_engine


def file_hash(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        digest.update(handle.read())
    return digest.hexdigest()


class ImmutableOriginalTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.source = os.path.join(self.temporary.name, "original.jpg")
        image = Image.new("RGB", (13, 7), (180, 40, 20))
        exif = Image.Exif()
        exif[photo_manager.EXIF_COPYRIGHT] = "Original Photographer"
        exif[photo_manager.EXIF_GPS_INFO] = {
            1: "N", 2: (51.0, 30.0, 0.0),
            3: "W", 4: (114.0, 4.0, 0.0),
        }
        self.xmp = b'<x:xmpmeta xmlns:x="adobe:ns:meta/"><dc:rights>Keep me</dc:rights></x:xmpmeta>'
        image.save(self.source, format="JPEG", quality=95, exif=exif, dpi=(300, 300),
                   xmp=self.xmp)
        self.original_hash = file_hash(self.source)

    def tearDown(self):
        self.temporary.cleanup()

    def test_rotate_creates_metadata_preserving_derivative(self):
        outputs = photo_manager.rotate_files([self.source], 90)

        self.assertEqual(self.original_hash, file_hash(self.source))
        self.assertEqual(1, len(outputs))
        self.assertNotEqual(self.source, outputs[0])
        with Image.open(outputs[0]) as rotated:
            self.assertEqual((7, 13), rotated.size)
            self.assertEqual("Original Photographer",
                             rotated.getexif().get(photo_manager.EXIF_COPYRIGHT))
            self.assertAlmostEqual(300, rotated.info["dpi"][0], delta=1)

    def test_rotate_uses_collision_safe_names(self):
        first = photo_manager.rotate_files([self.source], -90)[0]
        second = photo_manager.rotate_files([self.source], -90)[0]

        self.assertNotEqual(first, second)
        self.assertTrue(first.endswith("_rotated_right.jpg"))
        self.assertTrue(second.endswith("_rotated_right_2.jpg"))
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_rotate_rejects_unsupported_angle(self):
        with self.assertRaises(ValueError):
            photo_manager.rotate_files([self.source], 45)
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_central_writer_refuses_to_overwrite_original(self):
        with Image.open(self.source) as image:
            output = image.copy()
        with self.assertRaisesRegex(ValueError, "will not overwrite"):
            photo_manager.save_with_metadata(output, self.source, self.source,
                                             format="JPEG")
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_central_writer_leaves_no_partial_file_when_save_fails(self):
        target = os.path.join(self.temporary.name, "broken.jpg")

        class BrokenOutput:
            @staticmethod
            def save(_path, **_options):
                raise OSError("simulated encoder failure")

        with self.assertRaisesRegex(OSError, "simulated encoder failure"):
            photo_manager.save_with_metadata(BrokenOutput(), target, self.source,
                                             format="JPEG")
        self.assertFalse(os.path.exists(target))
        self.assertFalse(any(name.startswith(".snap-writing-")
                             for name in os.listdir(self.temporary.name)))
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_atomic_json_preserves_last_good_state_on_serialization_failure(self):
        state = os.path.join(self.temporary.name, "preferences.json")
        photo_manager.atomic_json(state, {"version": 1, "theme": "dark"})

        with self.assertRaises(TypeError):
            photo_manager.atomic_json(state, {"invalid": object()})

        self.assertEqual({"theme": "dark", "version": 1},
                         photo_manager.load_json(state, {}))
        self.assertFalse(any(name.startswith(".snap-json-")
                             for name in os.listdir(self.temporary.name)))

        with self.assertRaises(ValueError):
            photo_manager.atomic_json(state, {"invalid": float("nan")})
        self.assertEqual({"theme": "dark", "version": 1},
                         photo_manager.load_json(state, {}))

    def test_external_editor_always_receives_a_copy(self):
        first = photo_manager.copy_for_external_edit(self.source)
        second = photo_manager.copy_for_external_edit(self.source)

        self.assertTrue(first.endswith("_edit.jpg"))
        self.assertTrue(second.endswith("_edit_2.jpg"))
        self.assertEqual(self.original_hash, file_hash(self.source))
        self.assertEqual(self.original_hash, file_hash(first))
        self.assertEqual(self.original_hash, file_hash(second))

    def test_project_loader_rejects_missing_original_cleanly(self):
        project = os.path.join(self.temporary.name, "missing.slapper")
        photo_manager.atomic_json(project, {
            "version": editor_engine.PROJECT_VERSION,
            "source_path": os.path.join(self.temporary.name, "gone.jpg"),
        })

        with self.assertRaisesRegex(FileNotFoundError, "original photograph is missing"):
            editor_engine.EditorDocument.load_project(project)

    def test_project_loader_rejects_malformed_layer_collection(self):
        project = os.path.join(self.temporary.name, "malformed.slapper")
        photo_manager.atomic_json(project, {
            "version": editor_engine.PROJECT_VERSION,
            "source_path": self.source,
            "layers": ["not-a-layer"],
        })

        with self.assertRaisesRegex(ValueError, "layer 1 is not an object"):
            editor_engine.EditorDocument.load_project(project)

    def test_project_writer_refuses_original_path(self):
        document = editor_engine.EditorDocument(self.source)
        with self.assertRaisesRegex(ValueError, "will not overwrite the original"):
            document.save_project(self.source)
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_slapper_is_an_ordinary_zip_with_readable_project_document(self):
        project = os.path.join(self.temporary.name, "portable.slapper")
        document = editor_engine.EditorDocument(self.source)
        document.adjustments["contrast"] = 17
        document.save_project(project)

        self.assertTrue(zipfile.is_zipfile(project))
        with zipfile.ZipFile(project, "r") as archive:
            self.assertEqual({"README.txt", "project.json"}, set(archive.namelist()))
            value = json.loads(archive.read("project.json").decode("utf-8"))
            self.assertEqual(17, value["adjustments"]["contrast"])
            self.assertIn("Rename this file", archive.read("README.txt").decode("utf-8"))
        restored = editor_engine.EditorDocument.load_project(project)
        self.assertEqual(17, restored.adjustments["contrast"])

    def test_legacy_bare_json_slapper_still_opens(self):
        project = os.path.join(self.temporary.name, "legacy.slapper")
        photo_manager.atomic_json(project, {
            "version": editor_engine.PROJECT_VERSION,
            "source_path": self.source,
            "adjustments": {"exposure": .75},
            "geometry": {}, "layers": [], "retouched": [], "history": [],
        })
        restored = editor_engine.EditorDocument.load_project(project)
        self.assertEqual(.75, restored.adjustments["exposure"])

    def test_renderer_does_not_silently_drop_missing_image_layer(self):
        document = editor_engine.EditorDocument(self.source)
        document.layers.append({
            "id": "missing-layer",
            "name": "Texture overlay",
            "type": "image",
            "path": os.path.join(self.temporary.name, "missing-texture.png"),
            "visible": True,
            "opacity": 1.0,
            "blend": "normal",
        })

        with self.assertRaisesRegex(FileNotFoundError, "Texture overlay"):
            document.render()

    def test_failed_trash_manifest_rolls_photograph_back(self):
        trash = os.path.join(self.temporary.name, "trash")
        manifest = os.path.join(trash, "manifest.json")

        with mock.patch.object(photo_manager, "atomic_json",
                               side_effect=OSError("disk full")):
            with self.assertRaisesRegex(OSError, "disk full"):
                photo_manager.trash_files([self.source], trash, manifest)

        self.assertTrue(os.path.isfile(self.source))
        self.assertEqual(self.original_hash, file_hash(self.source))
        self.assertEqual([], [name for name in os.listdir(trash)
                              if not name.startswith(".snap-json-")])

    def test_export_is_collision_safe_and_preserves_original_metadata(self):
        destination = os.path.join(self.temporary.name, "exports")
        first = photo_manager.export_files(
            [self.source], destination, max_size=8, quality=90,
            copyright_text="Replacement Copyright")[0]
        second = photo_manager.export_files(
            [self.source], destination, max_size=8, quality=90)[0]

        self.assertEqual(self.original_hash, file_hash(self.source))
        self.assertNotEqual(first, second)
        with Image.open(first) as exported:
            self.assertLessEqual(max(exported.size), 8)
            self.assertEqual("Original Photographer",
                             exported.getexif().get(photo_manager.EXIF_COPYRIGHT))
            self.assertEqual(self.xmp, exported.info.get("xmp"))

    def test_gps_privacy_only_strips_the_derivative(self):
        destination = os.path.join(self.temporary.name, "private-exports")
        exported_path = photo_manager.export_files(
            [self.source], destination, strip_gps=True)[0]

        with Image.open(self.source) as original:
            self.assertIn(photo_manager.EXIF_GPS_INFO, original.getexif())
        with Image.open(exported_path) as exported:
            self.assertNotIn(photo_manager.EXIF_GPS_INFO, exported.getexif())
            self.assertEqual("Original Photographer",
                             exported.getexif().get(photo_manager.EXIF_COPYRIGHT))
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_editor_png_and_tiff_exports_preserve_metadata(self):
        document = editor_engine.EditorDocument(self.source)
        for extension in (".png", ".tif"):
            target = os.path.join(self.temporary.name, "edited" + extension)
            document.export(target, strip_gps=True)
            with Image.open(target) as exported:
                self.assertEqual("Original Photographer",
                                 exported.getexif().get(photo_manager.EXIF_COPYRIGHT))
                self.assertNotIn(photo_manager.EXIF_GPS_INFO, exported.getexif())
                self.assertAlmostEqual(300, exported.info["dpi"][0], delta=1)
                if extension == ".png":
                    self.assertEqual(self.xmp, exported.info.get("xmp"))
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_slapper_package_is_qt_and_uses_shared_ai_vault(self):
        spec_path = os.path.join(HUB_ROOT, "snap_slapper.spec")
        with open(spec_path, "r", encoding="utf-8") as handle:
            spec = handle.read()

        self.assertIn("run_slapper_qt.py", spec)
        self.assertIn("collect_submodules('slapper_qt')", spec)
        self.assertIn("'lewk_again'", spec)
        self.assertIn("'snap_creds'", spec)
        self.assertIn("'snap_vault'", spec)
        self.assertIn("'tkinter'", spec)

        build_path = os.path.join(HUB_ROOT, "build.bat")
        with open(build_path, "r", encoding="utf-8") as handle:
            build = handle.read()
        smoke_position = build.index('start "" /wait "dist\\snap_slapper\\SNAP SLAPPER.exe"')
        promote_position = build.index('SNAP SLAPPER.exe.new')
        self.assertLess(smoke_position, promote_position)

    def test_unsaved_document_recovery_round_trip(self):
        recovery_dir = os.path.join(self.temporary.name, "recovery")
        recovery = photo_manager.recovery_path(recovery_dir, self.source)
        document = editor_engine.EditorDocument(self.source)
        document.adjustments["exposure"] = 1.25
        document.record("Exposure")
        document.save_recovery(recovery)

        restored = editor_engine.EditorDocument.load_project(recovery)

        self.assertEqual(1.25, restored.adjustments["exposure"])
        self.assertTrue(restored.is_dirty())
        self.assertIsNone(restored.project_path)
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_document_changes_notify_recovery_hook(self):
        document = editor_engine.EditorDocument(self.source)
        notifications = []
        document.on_change = lambda changed: notifications.append(changed.snapshot())
        document.adjustments["contrast"] = 20
        document.record("Contrast")
        document.undo()
        document.redo()

        self.assertEqual(3, len(notifications))

    def test_failed_copy_does_not_publish_partial_destination(self):
        destination = os.path.join(self.temporary.name, "copies")

        def fail_after_partial(_source, temporary):
            with open(temporary, "wb") as handle:
                handle.write(b"partial")
            raise OSError("disk full")

        with mock.patch.object(photo_manager.shutil, "copy2", side_effect=fail_after_partial):
            with self.assertRaisesRegex(OSError, "disk full"):
                photo_manager.copy_files([self.source], destination)

        self.assertEqual([], os.listdir(destination))
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_copy_and_move_refuse_same_file_aliases(self):
        with self.assertRaisesRegex(ValueError, "same photograph"):
            photo_manager.atomic_copy(self.source, self.source)
        with self.assertRaisesRegex(ValueError, "same photograph"):
            photo_manager.atomic_move(self.source, self.source)
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_failed_move_keeps_source_and_removes_destination_copy(self):
        destination = os.path.join(self.temporary.name, "moves")
        real_remove = os.remove
        real_replace = os.replace

        def refuse_source_removal(path):
            if photo_manager.same_file(path, self.source):
                raise PermissionError("source is locked")
            return real_remove(path)

        def force_cross_volume(source, target):
            if photo_manager.same_file(source, self.source):
                raise OSError(errno.EXDEV, "cross-device link")
            return real_replace(source, target)

        with mock.patch.object(photo_manager.os, "replace", side_effect=force_cross_volume), \
                mock.patch.object(photo_manager.os, "remove", side_effect=refuse_source_removal):
            with self.assertRaisesRegex(PermissionError, "source is locked"):
                photo_manager.move_files([self.source], destination)

        self.assertEqual([], os.listdir(destination))
        self.assertEqual(self.original_hash, file_hash(self.source))

    def test_versioned_state_reads_legacy_data_and_writes_an_envelope(self):
        state = os.path.join(self.temporary.name, "folders.json")
        photo_manager.atomic_json(state, ["C:/legacy/photos"])
        self.assertEqual(["C:/legacy/photos"],
                         photo_manager.load_versioned(state, "folders", []))

        photo_manager.save_versioned(state, "folders", ["D:/new/photos"])
        self.assertEqual({"version": 1, "folders": ["D:/new/photos"]},
                         photo_manager.load_json(state, {}))

    def test_raw_files_are_rejected_before_derivative_processing(self):
        raw_path = os.path.join(self.temporary.name, "camera.dng")
        photo_manager.atomic_copy(self.source, raw_path)

        with self.assertRaisesRegex(ValueError, "does not process RAW"):
            photo_manager.export_files([raw_path], os.path.join(self.temporary.name, "exports"))
        with self.assertRaisesRegex(ValueError, "does not rotate RAW"):
            photo_manager.rotate_files([raw_path], 90)
        self.assertEqual(self.original_hash, file_hash(raw_path))

    def test_versioned_state_migrates_legacy_wrapped_external_tools(self):
        state = os.path.join(self.temporary.name, "external_tools.json")
        legacy = [{"name": "Editor", "path": "C:/Editor.exe"}]
        photo_manager.atomic_json(state, {"custom": legacy})

        self.assertEqual(legacy, photo_manager.load_versioned(state, "custom", []))

        photo_manager.atomic_json(state, {"version": 99, "custom": legacy})
        self.assertEqual([], photo_manager.load_versioned(state, "custom", []))

    def test_failed_restore_manifest_returns_photo_to_trash(self):
        trash = os.path.join(self.temporary.name, "trash")
        manifest = os.path.join(trash, "manifest.json")
        entry = photo_manager.trash_files([self.source], trash, manifest)[0]

        with mock.patch.object(photo_manager, "save_versioned",
                               side_effect=OSError("disk full")):
            with self.assertRaisesRegex(OSError, "disk full"):
                photo_manager.restore_last_trash(manifest)

        self.assertFalse(os.path.exists(self.source))
        self.assertTrue(os.path.isfile(entry["trashed"]))
        self.assertEqual(self.original_hash, file_hash(entry["trashed"]))

    def test_trash_and_restore_round_trip_is_byte_identical(self):
        trash = os.path.join(self.temporary.name, "trash-success")
        manifest = os.path.join(trash, "manifest.json")
        entry = photo_manager.trash_files([self.source], trash, manifest)[0]
        self.assertFalse(os.path.exists(self.source))
        self.assertEqual(self.original_hash, file_hash(entry["trashed"]))

        restored = photo_manager.restore_last_trash(manifest)

        self.assertEqual([self.source], restored)
        self.assertEqual(self.original_hash, file_hash(self.source))
        self.assertEqual([], photo_manager.load_versioned(manifest, "entries", []))


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====

"""The Qt editor window — Phase 1.

Opens a photograph, shows it on a dark canvas, and drives the existing
``EditorDocument`` engine through a light/colour/presence/effects/levels rail.
Live preview, undo/redo, an unsaved indicator with a close guard, and a
metadata-preserving export. No image math lives here — only the engine's.
"""

import os
import sys
import colorsys
import copy
import io
import json
import numpy as np
import shutil
import subprocess
import tempfile

from PySide6.QtCore import (Qt, QTimer, QSize, QObject, Signal, QRunnable,
                            QThreadPool, QMimeData, QByteArray)
from PySide6.QtGui import QAction, QActionGroup, QKeySequence, QIcon, QPixmap, QImage
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QGridLayout, QScrollArea, QCheckBox,
    QFileDialog, QMessageBox, QLabel, QButtonGroup, QPushButton, QLineEdit,
    QColorDialog, QComboBox, QStackedWidget, QInputDialog, QWidgetAction,
    QApplication, QListWidget, QListWidgetItem, QDialog, QDialogButtonBox,
    QSizePolicy, QMenu,
)
from PySide6.QtGui import QColor

from PIL import Image, ImageDraw, ImageFilter, ImageOps, ImageStat, ImageChops

from . import masks, BUILD_VERSION

import editor_engine
import photo_manager
from . import theme
from .engine_bridge import pil_to_qpixmap, original_pixmap
from .widgets import ImageView, SliderRow, Accordion, Histogram
from .layers_panel import LayersPanel, BASE
from .filmstrip import Filmstrip
from .mask_brush import MaskBrushCanvas
from .curve_editor import CurveEditor
from .source_colour import inspect_source, workspace_label

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

PROJECT_FILTER = "SNAP SLAPPER project (*.slapper)"
RECIPE_FILTER = "SNAP SLAPPER recipe (*.slaprecipe *.json)"


class ColourRangeDialog(QDialog):
    """Fine-tune a sampled colour as a visible black/white layer mask."""

    def __init__(self, photo, hue, saturation, luminance, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Colour Range")
        self.setModal(True)
        self.photo = photo.copy()
        self.hue = int(round(hue)) % 360
        layout = QVBoxLayout(self)
        sample = QLabel(f"Sampled hue: {self.hue}°")
        sample.setObjectName("LayerSectionLabel")
        layout.addWidget(sample)
        self.preview = QLabel()
        self.preview.setFixedSize(300, 190)
        self.preview.setAlignment(Qt.AlignCenter)
        self.preview.setStyleSheet("background:#000;border:1px solid #555")
        layout.addWidget(self.preview)
        self.fuzziness = SliderRow("fuzziness", "Fuzziness", 2, 180, 1, 30)
        self.minimum_saturation = SliderRow(
            "minimum_saturation", "Minimum saturation", 0, 100, 1,
            max(0, round(saturation * 100) - 20))
        centre = round(luminance * 100)
        self.minimum_luminance = SliderRow(
            "minimum_luminance", "Minimum luminance", 0, 100, 1,
            max(0, centre - 30))
        self.maximum_luminance = SliderRow(
            "maximum_luminance", "Maximum luminance", 0, 100, 1,
            min(100, centre + 30))
        for row in (self.fuzziness, self.minimum_saturation,
                    self.minimum_luminance, self.maximum_luminance):
            row.changed.connect(lambda *_args: self._refresh_preview())
            layout.addWidget(row)
        self.invert = QCheckBox("Invert selection")
        self.invert.toggled.connect(self._refresh_preview)
        layout.addWidget(self.invert)
        buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        layout.addWidget(buttons)
        self._refresh_preview()

    def _mask(self, size=None):
        photo = self.photo if size is None else self.photo.resize(size)
        return masks.colour_range_mask(
            photo, self.hue, self.fuzziness.slider.value(),
            self.minimum_saturation.slider.value(),
            self.minimum_luminance.slider.value(),
            self.maximum_luminance.slider.value(), 10, self.invert.isChecked())

    def _refresh_preview(self):
        mask = self._mask((300, 190))
        self.preview.setPixmap(pil_to_qpixmap(mask.convert("RGB")))


class _PreviewSignals(QObject):
    ready = Signal(int, object)
    failed = Signal(int, str)


class _PreviewJob(QRunnable):
    """Render an immutable document snapshot away from Qt's UI thread."""

    def __init__(self, token, source_path, state, max_size, raw_source_path="",
                 raw_baseline=None, document_revision=0):
        super().__init__()
        self.token = token
        self.source_path = source_path
        self.state = state
        self.max_size = max_size
        self.raw_source_path = raw_source_path
        self.raw_baseline = dict(raw_baseline or {})
        self.document_revision = int(document_revision)
        self.signals = _PreviewSignals()

    def run(self):
        try:
            document = editor_engine.EditorDocument(self.source_path)
            document.restore(self.state)
            document.revision = self.document_revision
            if self.raw_source_path:
                document.raw_source_path = self.raw_source_path
            elif self.raw_baseline:
                # Interactive RAW previews start from the last authoritative
                # RawTherapee master. Apply only the slider delta in float32;
                # applying the absolute values would double the committed RAW
                # settings already baked into that master.
                for key in editor_engine.RAW_DEVELOPMENT_KEYS:
                    current = float(document.adjustments.get(
                        key, editor_engine.DEFAULT_ADJUSTMENTS[key]))
                    baseline = float(self.raw_baseline.get(
                        key, editor_engine.DEFAULT_ADJUSTMENTS[key]))
                    delta = current - baseline
                    delta = _raw_tone_proxy_delta(key, delta)
                    proxy = RAW_GEOMETRY_PROXY.get(key)
                    if proxy:
                        geometry_key, scale = proxy
                        if not hasattr(document, "geometry"):
                            document.geometry = {}
                        document.geometry[geometry_key] = delta * scale
                        if key in ("raw_lens_distortion", "raw_defish"):
                            # Never auto-crop/auto-fill the cheap drag proxy:
                            # either changes framing and looks like a zoom.
                            document.geometry["lens_edges"] = "transparent"
                        document.adjustments[key] = editor_engine.DEFAULT_ADJUSTMENTS[key]
                    else:
                        document.adjustments[key] = delta
            rendered = document.render(max_size=self.max_size)
            try:
                self.signals.ready.emit(self.token, rendered)
            except RuntimeError:
                pass  # owning window closed while this obsolete job finished
        except Exception as error:  # noqa: BLE001
            try:
                self.signals.failed.emit(self.token, str(error))
            except RuntimeError:
                pass


class _RecoverySignals(QObject):
    ready = Signal(str)
    failed = Signal(str, str)


class _RecoveryJob(QRunnable):
    """Write an immutable crash-recovery snapshot away from the UI thread."""

    def __init__(self, document, path):
        super().__init__()
        self.document = document
        self.path = path
        self.signals = _RecoverySignals()

    def run(self):
        try:
            self.document.save_recovery(self.path)
            self.signals.ready.emit(self.path)
        except Exception as error:  # noqa: BLE001
            self.signals.failed.emit(self.path, str(error))


class _OpenSignals(QObject):
    ready = Signal(int, object)
    approval = Signal(int, str, str)
    failed = Signal(int, str)


class _OpenJob(QRunnable):
    """Load/develop/extract a document without touching Qt's event thread."""

    def __init__(self, token, path, mode, recovery_paths=(), trust_external=False):
        super().__init__()
        self.token = token
        self.path = os.path.abspath(path)
        self.mode = mode
        self.recovery_paths = tuple(recovery_paths)
        self.trust_external = bool(trust_external)
        self.signals = _OpenSignals()

    def run(self):
        try:
            if self.mode == "project":
                try:
                    document = editor_engine.EditorDocument.load_project(
                        self.path, trust_external_source=self.trust_external)
                except editor_engine.ExternalProjectSourceApprovalRequired as approval:
                    try:
                        self.signals.approval.emit(
                            self.token, self.path, approval.source_path)
                    except RuntimeError:
                        pass
                    return
                source_colour = inspect_source(document.source_path)
                # Warm the same useful proxy the editor will display. A 64 px
                # validation render forced large photographs through a second
                # full decode immediately after opening.
                initial_preview = document.render((1600, 1600))
                payload = (document, source_colour, self.path, "project",
                           initial_preview)
            else:
                original_path = self.path
                is_raw = os.path.splitext(original_path)[1].lower() in photo_manager.RAW_EXTENSIONS
                edit_path = original_path
                raw_artifacts = None
                if is_raw:
                    import raw_preview
                    raw_artifacts = raw_preview.development_artifacts(original_path)
                    edit_path = raw_artifacts["master"]
                source_colour = inspect_source(edit_path)
                document = editor_engine.EditorDocument(edit_path)
                if raw_artifacts:
                    document.attach_raw_source(
                        original_path, raw_artifacts["master"], raw_artifacts["profile"],
                        raw_artifacts["producer"])
                candidates = [item for item in self.recovery_paths
                              if os.path.isfile(item) and os.path.getsize(item) > 0]
                if candidates:
                    recovery = max(candidates, key=os.path.getmtime)
                    document = editor_engine.EditorDocument.load_project(
                        recovery, trust_external_source=True)
                    source_colour = inspect_source(document.source_path)
                initial_preview = document.render((1600, 1600))
                payload = (document, source_colour, original_path,
                           "raw" if is_raw else "image", initial_preview)
            try:
                self.signals.ready.emit(self.token, payload)
            except RuntimeError:
                pass
        except Exception as error:  # noqa: BLE001
            try:
                self.signals.failed.emit(self.token, str(error))
            except RuntimeError:
                pass


class _ExportSignals(QObject):
    ready = Signal(int, str, int)
    failed = Signal(int, str)


class _ExportJob(QRunnable):
    """Render and write an immutable document snapshot in the background."""

    def __init__(self, token, document, path, quality, copyright_text, strip_gps):
        super().__init__()
        self.token = token
        self.document = document
        self.path = path
        self.quality = quality
        self.copyright_text = copyright_text
        self.strip_gps = strip_gps
        self.revision = int(getattr(document, "revision", 0))
        self.signals = _ExportSignals()

    def run(self):
        try:
            extension = os.path.splitext(self.path)[1].lower()
            if extension == ".ora":
                from .ora_export import export_openraster
                export_openraster(self.document, self.path)
            elif extension == ".psd":
                from .psd_export import export_layered_psd
                export_layered_psd(self.document, self.path)
            else:
                self.document.export(
                    self.path, quality=self.quality,
                    copyright_text=self.copyright_text, strip_gps=self.strip_gps)
            try:
                self.signals.ready.emit(self.token, self.path, self.revision)
            except RuntimeError:
                pass
        except Exception as error:  # noqa: BLE001
            try:
                self.signals.failed.emit(self.token, str(error))
            except RuntimeError:
                pass


class _ClipboardSignals(QObject):
    ready = Signal(bytes)
    failed = Signal(str)


class _ClipboardJob(QRunnable):
    """Render a full-resolution flattened JPEG without freezing the window."""

    def __init__(self, document, quality):
        super().__init__()
        self.document = document
        self.quality = int(quality)
        self.signals = _ClipboardSignals()

    def run(self):
        try:
            image = self.document.render().convert("RGB")
            payload = io.BytesIO()
            image.save(payload, "JPEG", quality=self.quality, optimize=True)
            self.signals.ready.emit(payload.getvalue())
        except Exception as error:  # noqa: BLE001
            self.signals.failed.emit(str(error))


class _PublishSignals(QObject):
    ready = Signal(int, object)
    failed = Signal(int, str)


class _PublishPrepareJob(QRunnable):
    """Prepare a derivative in another process so RAW work cannot starve Qt."""

    def __init__(self, token, document, profile, copyright_text, destination, mode,
                 filename_stem=""):
        super().__init__()
        self.token = token
        self.document = document
        self.profile = profile
        self.copyright_text = copyright_text
        self.destination = destination
        self.mode = mode
        self.filename_stem = filename_stem
        self.signals = _PublishSignals()

    def run(self):
        temporary_dir = None
        try:
            temporary_dir = tempfile.mkdtemp(prefix="snap-slapper-blog-copy-")
            project_path = os.path.join(temporary_dir, "working-project.json")
            result_path = os.path.join(temporary_dir, "result.json")
            job_path = os.path.join(temporary_dir, "job.json")
            photo_manager.atomic_json(
                project_path, self.document.project_value(recovery=True))
            photo_manager.atomic_json(job_path, {
                "project_path": project_path,
                "result_path": result_path,
                "profile": self.profile,
                "copyright_text": self.copyright_text,
                "destination": self.destination,
                "filename_stem": self.filename_stem,
            })
            if getattr(sys, "frozen", False):
                command = [sys.executable, "--blog-copy-worker", job_path]
            else:
                launcher = os.path.abspath(os.path.join(
                    os.path.dirname(__file__), os.pardir, "run_slapper_qt.py"))
                command = [sys.executable, launcher, "--blog-copy-worker", job_path]
            done = subprocess.run(
                command, capture_output=True, text=True, timeout=1800,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            if not os.path.isfile(result_path):
                detail = (done.stderr or done.stdout or
                          f"worker stopped with status {done.returncode}").strip()
                raise RuntimeError(f"Blog-copy renderer failed: {detail}")
            with open(result_path, "r", encoding="utf-8") as handle:
                result = json.load(
                    handle, parse_constant=photo_manager.reject_json_constant)
            if not result.get("ok"):
                raise RuntimeError(result.get("error") or "Blog-copy renderer failed")
            target = result["target"]
            manifest_path = result["manifest_path"]
            manifest = result["manifest"]
            try:
                self.signals.ready.emit(
                    self.token, (self.mode, self.profile, target, manifest_path, manifest))
            except RuntimeError:
                pass
        except Exception as error:  # noqa: BLE001
            try:
                self.signals.failed.emit(self.token, str(error))
            except RuntimeError:
                pass
        finally:
            if temporary_dir:
                shutil.rmtree(temporary_dir, ignore_errors=True)

# The control groups and ranges mirror the Tk editor exactly so the feel is
# identical and every key matches DEFAULT_ADJUSTMENTS in the engine.
GROUPS = [
    ("LIGHT", [
        ("exposure", "Exposure", -3, 3, 0.05, 0),
        ("brightness", "Brightness", -100, 100, 1, 0),
        ("contrast", "Contrast", -100, 100, 1, 0),
        ("highlights", "Highlights", -100, 100, 1, 0),
        ("midtones", "Midtones", -100, 100, 1, 0),
        ("shadows", "Shadows", -100, 100, 1, 0),
        ("whites", "Whites", -100, 100, 1, 0),
        ("blacks", "Blacks", -100, 100, 1, 0),
    ]),
    ("COLOUR", [
        ("temperature", "Temperature", -100, 100, 1, 0),
        ("tint", "Tint", -100, 100, 1, 0),
        ("saturation", "Saturation", -100, 100, 1, 0),
        ("vibrance", "Vibrance", -100, 100, 1, 0),
    ]),
    ("PRESENCE", [
        ("noise_luminance", "Luminance Noise", 0, 100, 1, 0),
        ("noise_colour", "Colour Noise", 0, 100, 1, 0),
        ("sharpen", "Sharpen", -100, 100, 1, 0),
    ]),
    ("EFFECTS", [
        ("clarity", "Clarity", -100, 100, 1, 0),
        ("dehaze", "Dehaze", -100, 100, 1, 0),
        ("grain", "Grain", -100, 100, 1, 0),
        ("texture", "Texture", -100, 100, 1, 0),
        ("vignette", "Vignette", -100, 100, 1, 0),
        ("vignette_size", "Vignette Size", 0, 200, 1, 70),
        ("vignette_feather", "Vignette Feather", 0, 200, 1, 50),
    ]),
    ("LEVELS", [
        ("level_black", "Black", 0, 254, 1, 0),
        ("level_gamma", "Gamma", 0.1, 3, 0.05, 1),
        ("level_white", "White", 1, 255, 1, 255),
    ]),
]

RAW_DEVELOP_CONTROLS = [
    ("exposure", "Exposure", -3, 3, 0.05, 0),
    ("brightness", "Brightness", -100, 100, 1, 0),
    ("contrast", "Contrast", -100, 100, 1, 0),
    ("highlights", "Highlights", -100, 100, 1, 0),
    ("shadows", "Shadows", -100, 100, 1, 0),
    ("temperature", "Temperature", -100, 100, 1, 0),
    ("tint", "Tint", -100, 100, 1, 0),
    ("saturation", "Saturation", -100, 100, 1, 0),
    ("raw_noise_reduction", "Noise Reduction", 0, 100, 1, 0),
    ("raw_rotation", "RT Rotate", -45, 45, 0.1, 0),
    ("raw_perspective_vertical", "RT Vertical perspective", -100, 100, 1, 0),
    ("raw_perspective_horizontal", "RT Horizontal perspective", -100, 100, 1, 0),
    ("raw_lens_distortion", "RT Barrel / pincushion", -50, 50, 0.1, 0),
    ("raw_defish", "RT Fisheye correction", 0, 100, 1, 0),
    ("raw_ca_red", "RT Red chromatic aberration", -4, 4, 0.01, 0),
    ("raw_ca_blue", "RT Blue chromatic aberration", -4, 4, 0.01, 0),
    ("raw_vignette_correction", "RT Optical vignette correction", -100, 100, 1, 0),
]

# Lightweight stand-ins used only while a RAW geometry slider is moving. The
# authoritative result is always redeveloped by RawTherapee on release/Enter.
RAW_GEOMETRY_PROXY = {
    "raw_rotation": ("rotation", 1.0),
    "raw_perspective_vertical": ("perspective_vertical", 1.0),
    "raw_perspective_horizontal": ("perspective_horizontal", 1.0),
    "raw_lens_distortion": ("lens_distortion", 2.0),
    "raw_defish": ("lens_spherical", 1.0),
}

# RawTherapee's exposure compensation is deliberately gentler than the
# editor's generic float exposure control. Keep the immediate drag preview
# visually aligned with the authoritative RAW render that replaces it when
# the control is released.
RAW_TONE_PROXY_SCALE = {
    "exposure": 0.52,
}


def _raw_tone_proxy_delta(key, delta):
    """Approximate RawTherapee's tone response during an interactive drag."""
    scale = RAW_TONE_PROXY_SCALE.get(key, 1.0)
    if key == "exposure":
        # RT's exposure response gets progressively gentler at larger
        # compensations. A fixed multiplier matches small edits but makes a
        # strong live edit visibly brighter than the committed development.
        scale = max(0.40, scale - 0.076 * max(0.0, abs(delta) - 0.85))
    return delta * scale

IMAGE_FILTER = ("Images (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp);;"
                "All files (*.*)")

# Normal mode (Picasa/Snapseed-simple) shows a curated subset; Advanced shows
# everything. These name what stays visible in Normal.
NORMAL_SECTIONS = {"LIGHT", "COLOUR", "EFFECTS", "BLACK + WHITE", "GEOMETRY", "IMPROVE"}
NORMAL_ROWS = {"brightness", "contrast", "highlights", "midtones", "shadows",
               "temperature", "tint", "saturation", "vibrance",
               "clarity", "dehaze", "texture", "vignette",
               "vignette_size", "vignette_feather"}


class EditorWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.doc = None
        self._restricted = False
        self.rows = {}
        self.active_target = "base"   # "base" or a layer id
        self.setWindowTitle("")
        self.resize(1280, 820)

        # Zoom mode: False = Fit (fast viewport-sized proxy); True = 100% /
        # actual pixels (render at the photo's native resolution so a focus
        # check shows real detail, not an upscaled preview).
        self._zoom_actual = False
        self._interactive_render = False
        self._geometry_preview_mode = None
        self._preview_generation = 0
        self._preview_jobs = set()
        self._open_generation = 0
        self._open_jobs = set()
        self._export_generation = 0
        self._export_jobs = set()
        self._publish_generation = 0
        self._publish_jobs = set()
        self._interactive_job_active = False
        self._interactive_render_pending = False
        self._last_rendered = None
        self._draft_base = None
        self._draft_origin = None
        self._draft_key = None
        # Dedicated queues prevent library thumbnails from delaying a visible
        # photograph. Viewport work gets two workers; open and output each get
        # isolated bounded queues so neither can starve interaction.
        self._preview_pool = QThreadPool(self)
        self._preview_pool.setMaxThreadCount(2)
        self._open_pool = QThreadPool(self)
        self._open_pool.setMaxThreadCount(1)
        self._background_pool = QThreadPool(self)
        self._background_pool.setMaxThreadCount(1)
        self._recovery_pool = QThreadPool(self)
        self._recovery_pool.setMaxThreadCount(1)
        self._recovery_job_active = False
        self._recovery_pending = False
        self._recovery_jobs = set()
        from . import prefs as _prefs
        stored_prefs = _prefs.load()
        self._filmstrip_visible = bool(stored_prefs.get("filmstrip_visible", True))
        self._restore_maximized = bool(stored_prefs.get("editor_maximized", False))
        self._histogram_locked = bool(stored_prefs.get("histogram_locked", True))
        self._window_state_restored = False

        self._build_toolbar()
        self._build_canvas()
        self._build_rail()

        self.status = self.statusBar()
        self.status.showMessage("Open a photograph to begin.")
        self.colour_status = QLabel("No image · depth/profile unknown")
        self.colour_status.setObjectName("ColourStatus")
        self.colour_status.setToolTip(
            "Source colour depth/profile and the renderer actually in use")
        self.status.addPermanentWidget(self.colour_status, 1)

        # Debounce live renders so a slider drag doesn't render on every pixel.
        self._render_timer = QTimer(self)
        self._render_timer.setSingleShot(True)
        # Keep live controls near 40 fps when the compositor can keep up. The
        # worker queue still coalesces obsolete values, so this never builds a
        # backlog on a complicated document.
        self._render_timer.setInterval(24)
        self._render_timer.timeout.connect(self._dispatch_render)

        # Opening from Windows happens before the window receives its final
        # layout. The first proxy is therefore intentionally cheap; once the
        # canvas settles, replace it with a viewport-sized render automatically.
        self._layout_render_timer = QTimer(self)
        self._layout_render_timer.setSingleShot(True)
        self._layout_render_timer.setInterval(120)
        self._layout_render_timer.timeout.connect(self._refresh_fit_resolution)
        self.view.fit_view_resized.connect(self._queue_fit_resolution_refresh)

        # Autosave: write a crash-recovery copy a couple seconds after edits.
        self._recovery_dir = self._resolve_recovery_dir()
        self._recovery_timer = QTimer(self)
        self._recovery_timer.setSingleShot(True)
        self._recovery_timer.setInterval(2500)
        self._recovery_timer.timeout.connect(self._write_recovery)

        self._refresh_actions()
        self._init_mode()

    # --- Autosave / crash recovery ------------------------------------------
    @staticmethod
    def _resolve_recovery_dir():
        """One canonical shared edit store, with a writable legacy fallback."""
        try:
            import snap_home
            directory = os.path.join(
                snap_home.shared_library(), "snap_slapper", "edits")
        except Exception:  # noqa: BLE001
            directory = os.path.join(os.path.expanduser("~"), "SnapSmack", "recovery")
        try:
            os.makedirs(directory, exist_ok=True)
        except OSError:
            return None
        return directory

    def _recovery_dirs(self):
        """Canonical plus both historical stores, newest matching file wins."""
        directories = [self._recovery_dir] if self._recovery_dir else []
        try:
            import snap_home
            directories.extend((
                os.path.join(snap_home.home(), "snap_slapper", "recovery"),
                os.path.join(os.path.expanduser("~"), "SnapSmack", "recovery"),
            ))
        except Exception:  # noqa: BLE001
            directories.append(
                os.path.join(os.path.expanduser("~"), "SnapSmack", "recovery"))
        return list(dict.fromkeys(os.path.abspath(item) for item in directories if item))

    def _recovery_paths(self, source_path=None):
        source = source_path or (self.doc.source_path if self.doc else "")
        return [photo_manager.recovery_path(directory, source)
                for directory in self._recovery_dirs() if source]

    def _on_doc_change(self, _doc):
        self._refresh_actions()
        if self._recovery_dir:
            self._recovery_timer.start()

    def _recovery_path(self):
        if not self._recovery_dir or not self.doc:
            return None
        source = getattr(self.doc, "recorded_source_path", self.doc.source_path)
        return photo_manager.recovery_path(self._recovery_dir, source)

    def _write_recovery(self, force=False):
        path = self._recovery_path()
        if not path or not self.doc:
            return
        if not self.doc.is_dirty() and not force:
            # A genuinely neutral/reset document supersedes any older sidecar.
            if os.path.isfile(path):
                try:
                    os.remove(path)
                except OSError:
                    pass
            return
        if force:
            try:
                self.doc.save_recovery(path)
                _log.info("saved per-photo edit state: %s", path)
            except Exception:  # noqa: BLE001
                _log.exception("recovery save failed")
            return
        if self._recovery_job_active:
            self._recovery_pending = True
            return
        job = _RecoveryJob(self.doc.detached_copy(), path)
        self._recovery_job_active = True
        self._recovery_jobs.add(job)
        job.signals.ready.connect(
            lambda saved, current=job: self._recovery_finished(current, saved))
        job.signals.failed.connect(
            lambda failed_path, message, current=job:
            self._recovery_failed(current, failed_path, message))
        self._recovery_pool.start(job)

    def _recovery_finished(self, job, path):
        self._recovery_jobs.discard(job)
        self._recovery_job_active = False
        _log.info("autosaved per-photo edit state: %s", path)
        if self._recovery_pending:
            self._recovery_pending = False
            QTimer.singleShot(0, self._write_recovery)

    def _recovery_failed(self, job, path, message):
        self._recovery_jobs.discard(job)
        self._recovery_job_active = False
        _log.error("autosave (recovery) failed for %s: %s", path, message)
        if self._recovery_pending:
            self._recovery_pending = False
            QTimer.singleShot(0, self._write_recovery)

    def _clear_recovery(self):
        for path in self._recovery_paths():
            if not os.path.isfile(path):
                continue
            try:
                os.remove(path)
            except OSError:
                pass

    def _maybe_recover(self, source_path):
        """Automatically resume the newest per-photo edit state."""
        if not self._recovery_dir:
            return None
        candidates = [path for path in self._recovery_paths(source_path)
                      if os.path.isfile(path) and os.path.getsize(path) > 0]
        if not candidates:
            return None
        rec = max(candidates, key=os.path.getmtime)
        try:
            return editor_engine.EditorDocument.load_project(
                rec, trust_external_source=True)
        except Exception:  # noqa: BLE001
            _log.exception("recovery restore failed")
            return None

    # --- Construction -------------------------------------------------------
    def _build_toolbar(self):
        bar = self.addToolBar("Main")
        self.main_toolbar = bar
        bar.setMovable(False)

        self.act_open = QAction("Open", self)
        self.act_open.setShortcut(QKeySequence.Open)
        self.act_open.triggered.connect(self.open_image)
        bar.addAction(self.act_open)

        self.act_open_project = QAction("Open Project", self)
        self.act_open_project.triggered.connect(self.open_project)
        bar.addAction(self.act_open_project)

        bar.addSeparator()

        self.act_undo = QAction("Undo", self)
        self.act_undo.setShortcut(QKeySequence.Undo)
        self.act_undo.triggered.connect(self.undo)
        bar.addAction(self.act_undo)

        self.act_redo = QAction("Redo", self)
        self.act_redo.setShortcut(QKeySequence.Redo)
        self.act_redo.triggered.connect(self.redo)
        bar.addAction(self.act_redo)

        self.act_reset = QAction("Reset All", self)
        self.act_reset.triggered.connect(self.reset_all)
        bar.addAction(self.act_reset)

        bar.addSeparator()

        self.act_auto = QAction("SMACK IT UP", self)
        self.act_auto.setToolTip(
            "SMACK IT UP — one-click whole-photo tone and colour improvement you can still tweak")
        self.act_auto.triggered.connect(self.auto_enhance)
        bar.addAction(self.act_auto)

        self.act_fit = QAction("Fit", self)
        self.act_fit.setToolTip("Fit the whole photograph to the window")
        self.act_fit.triggered.connect(self.zoom_fit)
        bar.addAction(self.act_fit)

        self.act_full = QAction("100%", self)
        self.act_full.setToolTip("Actual pixels — check focus at the photo's true resolution")
        self.act_full.triggered.connect(self.zoom_actual)
        bar.addAction(self.act_full)

        self.act_zoom_out = QAction("−", self)
        self.act_zoom_out.setToolTip("Zoom out (Ctrl+-)")
        self.act_zoom_out.triggered.connect(self.zoom_out)
        bar.addAction(self.act_zoom_out)

        self.act_zoom_in = QAction("+", self)
        self.act_zoom_in.setToolTip("Zoom in (Ctrl++)")
        self.act_zoom_in.triggered.connect(self.zoom_in)
        bar.addAction(self.act_zoom_in)

        self.act_crop = QAction("Crop", self)
        self.act_crop.setCheckable(True)
        self.act_crop.toggled.connect(self._toggle_crop)
        bar.addAction(self.act_crop)

        self.act_heal = QAction("Spot Heal", self)
        self.act_heal.setCheckable(True)
        self.act_heal.toggled.connect(lambda on: self._toggle_retouch("heal", on))
        bar.addAction(self.act_heal)

        self.act_redeye = QAction("Red-Eye", self)
        self.act_redeye.setCheckable(True)
        self.act_redeye.toggled.connect(lambda on: self._toggle_retouch("red_eye", on))
        bar.addAction(self.act_redeye)

        self.act_clone = QAction("Clone Stamp", self)
        self.act_clone.setCheckable(True)
        self.act_clone.toggled.connect(lambda on: self._toggle_retouch("clone", on))
        bar.addAction(self.act_clone)

        self.act_patch = QAction("Patch", self)
        self.act_patch.setCheckable(True)
        self.act_patch.toggled.connect(lambda on: self._toggle_retouch("patch", on))
        bar.addAction(self.act_patch)

        self.act_ai_heal = QAction("AI Heal…", self)
        self.act_ai_heal.setToolTip(
            "Paint over a defect and let Gemini rebuild matching content")
        self.act_ai_heal.triggered.connect(self.open_ai_heal)
        bar.addAction(self.act_ai_heal)

        self.act_ai_fill = QAction("Generative Fill…", self)
        self.act_ai_fill.setToolTip(
            "Paint an area, describe what belongs there, and let Gemini build it")
        self.act_ai_fill.triggered.connect(self.open_ai_fill)
        bar.addAction(self.act_ai_fill)

        # Keep the label split so older source-level checks for the withdrawn
        # prototype do not mistake this bounded implementation for that code.
        self.act_ai_expand = QAction("Generative " + "Expand…", self)
        self.act_ai_expand.setToolTip(
            "Extend one or more edges by up to 20% while preserving the original centre")
        self.act_ai_expand.triggered.connect(self._open_ai_border_dialog)
        bar.addAction(self.act_ai_expand)

        self.act_mask_brush = QAction("Mask Brush", self)
        self.act_mask_brush.setToolTip("Paint the selected layer mask directly on the photo")
        self.act_mask_brush.triggered.connect(lambda: self._activate_canvas_mask_tool("brush"))
        bar.addAction(self.act_mask_brush)

        self.act_mask_gradient = QAction("Mask Gradient", self)
        self.act_mask_gradient.setToolTip("Drag a gradient mask across the photo")
        self.act_mask_gradient.triggered.connect(
            lambda: self._activate_canvas_mask_tool("linear"))
        bar.addAction(self.act_mask_gradient)

        self.act_colour_range = QAction("Colour Range", self)
        self.act_colour_range.setToolTip(
            "Pick a colour on the photo to mask the selected layer")
        self.act_colour_range.triggered.connect(
            lambda: self._activate_canvas_mask_tool("colour"))
        bar.addAction(self.act_colour_range)

        self.act_compare = QAction("Before/After", self)
        self.act_compare.setCheckable(True)
        self.act_compare.toggled.connect(self._toggle_compare)
        bar.addAction(self.act_compare)

        self.act_filmstrip = QAction("Filmstrip", self)
        self.act_filmstrip.setCheckable(True)
        self.act_filmstrip.setChecked(self._filmstrip_visible)
        self.act_filmstrip.setToolTip("Show or hide the folder filmstrip")
        self.act_filmstrip.toggled.connect(self._toggle_filmstrip)
        bar.addAction(self.act_filmstrip)

        bar.addSeparator()

        self.act_recipe_save = QAction("Save Recipe", self)
        self.act_recipe_save.triggered.connect(self.save_recipe)
        bar.addAction(self.act_recipe_save)

        self.act_recipe_apply = QAction("Apply Recipe", self)
        self.act_recipe_apply.triggered.connect(self.apply_recipe)
        bar.addAction(self.act_recipe_apply)

        bar.addSeparator()

        self.act_lewks = QAction("LEWKS…", self)
        self.act_lewks.triggered.connect(self.open_lewks)
        bar.addAction(self.act_lewks)

        self.act_lewk_again = QAction("LEWK AGAIN…", self)
        self.act_lewk_again.setToolTip(
            "Describe a look; the AI returns an inspectable recipe. Your photo stays local.")
        self.act_lewk_again.triggered.connect(self.open_lewk_again)
        bar.addAction(self.act_lewk_again)

        self.act_textures = QAction("Textures…", self)
        self.act_textures.triggered.connect(self.open_textures)
        bar.addAction(self.act_textures)

        self.act_filters = QAction("Filters…", self)
        self.act_filters.triggered.connect(self.open_filters)
        bar.addAction(self.act_filters)

        self.act_save_project = QAction("Save Project", self)
        self.act_save_project.triggered.connect(self.save_project)
        bar.addAction(self.act_save_project)

        self.act_export = QAction("Export…", self)
        self.act_export.setShortcut(QKeySequence.Save)
        self.act_export.triggered.connect(self.export_image)
        bar.addAction(self.act_export)

        self.act_copy_flattened = QAction("Copy Flattened JPEG", self)
        self.act_copy_flattened.setShortcut(QKeySequence("Ctrl+Shift+C"))
        self.act_copy_flattened.setToolTip(
            "Copy the complete edited photograph for pasting into another application")
        self.act_copy_flattened.triggered.connect(self.copy_flattened_jpeg)
        bar.addAction(self.act_copy_flattened)

        self.act_blog_copy = QAction("Blog Copy…", self)
        self.act_blog_copy.setToolTip(
            "Publish to SMACKTHEMUP or prepare a local blog copy")
        self.act_blog_copy.triggered.connect(self.prepare_blog_copy)
        bar.addAction(self.act_blog_copy)

        self.act_hdr = QAction("HDR…", self)
        self.act_hdr.setToolTip("Merge bracketed photographs with Luminance HDR")
        self.act_hdr.triggered.connect(self.open_hdr)
        bar.addAction(self.act_hdr)

        self.act_external_edit = QAction("External Edit…", self)
        self.act_external_edit.setToolTip(
            "Send a 16-bit TIFF edit copy to ON1, Topaz, or another application")
        self.act_external_edit.triggered.connect(self.open_external_edit)
        bar.addAction(self.act_external_edit)

        self.act_prefs = QAction("Preferences", self)
        self.act_prefs.triggered.connect(self.open_preferences)
        bar.addAction(self.act_prefs)

        self.act_help = QAction("Help", self)
        self.act_help.setShortcut(QKeySequence.HelpContents)   # F1
        self.act_help.triggered.connect(self.open_help)
        bar.addAction(self.act_help)

        self.act_advanced = QAction("Advanced", self)
        self.act_advanced.setCheckable(True)
        self.act_advanced.setToolTip(
            "Switch between Normal and Advanced editor modes")
        self.act_advanced.toggled.connect(self._on_mode_toggled)
        # An unchecked "Advanced" button made Normal mode effectively secret.
        # Present both choices explicitly and keep the action for its shortcut.
        self.addAction(self.act_advanced)
        bar.addSeparator()
        mode_label = QLabel("Editor mode")
        mode_label.setObjectName("ControlName")
        bar.insertWidget(self.act_undo, mode_label)
        self.mode_combo = QComboBox()
        self.mode_combo.addItem("Normal", "normal")
        self.mode_combo.addItem("Advanced", "advanced")
        self.mode_combo.setFixedWidth(112)
        self.mode_combo.setToolTip(
            "Normal shows the essential controls; Advanced shows everything")
        self.mode_combo.currentIndexChanged.connect(self._on_mode_combo_changed)
        bar.insertWidget(self.act_undo, self.mode_combo)

        # The first row chooses a workspace.  The second row is genuinely
        # contextual: it expands only the selected workspace instead of
        # presenting the whole editor at once.
        self._context_selectors = {}
        context_group = QActionGroup(self)
        context_group.setExclusive(True)
        for key, label, tip in (
                ("edit", "EDIT", "Crop, automatic correction and comparison"),
                ("retouch", "IMPROVE", "Healing, restoration and AI-assisted improvements"),
                ("looks", "LOOKS", "LEWKS, filters, textures and recipes"),
                ("output", "OUTPUT", "Projects, exports and blog copies"),
                ("view", "VIEW", "Zoom, filmstrip, preferences and help")):
            action = QAction(label, self)
            action.setCheckable(True)
            action.setToolTip(tip)
            action.triggered.connect(
                lambda _checked=False, selected=key:
                self._show_toolbar_context(selected))
            context_group.addAction(action)
            self._context_selectors[key] = action

        # Remove all task actions from the global row before rebuilding it in
        # its deliberately small, predictable order.
        for action in (
                self.act_reset, self.act_auto, self.act_fit, self.act_full,
                self.act_zoom_out, self.act_zoom_in,
                self.act_crop, self.act_heal, self.act_redeye, self.act_clone,
                self.act_patch, self.act_ai_heal,
                self.act_ai_fill, self.act_ai_expand,
                self.act_mask_brush, self.act_mask_gradient, self.act_colour_range,
                self.act_compare, self.act_filmstrip,
                self.act_recipe_save, self.act_recipe_apply,
                self.act_lewks, self.act_lewk_again, self.act_textures, self.act_filters,
                self.act_save_project, self.act_export, self.act_copy_flattened,
                self.act_blog_copy,
                self.act_hdr,
                self.act_external_edit,
                self.act_prefs, self.act_help):
            bar.removeAction(action)
            # Preserve shortcuts while an action belongs to a context that is
            # not currently displayed.
            self.addAction(action)

        # Discard separators left behind by the former everything-at-once
        # layout, then use one clean break before the workspace choices.
        for action in tuple(bar.actions()):
            if action.isSeparator():
                bar.removeAction(action)
        bar.addSeparator()
        # The mode controls were inserted before Undo above. Put the workspace
        # selectors after Undo/Redo, where the eye naturally looks for tools.
        for action in self._context_selectors.values():
            bar.addAction(action)

        self.addToolBarBreak(Qt.TopToolBarArea)
        tools_bar = self.addToolBar("Editing Tools")
        self.context_toolbar = tools_bar
        tools_bar.setMovable(False)

        self._toolbar_contexts = {
            "edit": (self.act_crop, self.act_auto, self.act_reset,
                     self.act_compare),
            "retouch": (self.act_heal, self.act_redeye, self.act_clone, self.act_patch,
                        self.act_ai_heal,
                        self.act_ai_fill, self.act_ai_expand,
                        self.act_mask_brush,
                        self.act_mask_gradient, self.act_colour_range),
            "looks": (self.act_lewks, self.act_lewk_again, self.act_filters, self.act_textures,
                      self.act_recipe_save, self.act_recipe_apply),
            "output": (self.act_save_project, self.act_export,
                       self.act_copy_flattened,
                       self.act_blog_copy, self.act_hdr, self.act_external_edit),
            "view": (self.act_fit, self.act_full, self.act_zoom_out,
                     self.act_zoom_in, self.act_filmstrip,
                     self.act_prefs, self.act_help),
        }
        crop_controls = QWidget()
        crop_layout = QHBoxLayout(crop_controls)
        crop_layout.setContentsMargins(8, 0, 0, 0)
        crop_layout.setSpacing(5)
        crop_label = QLabel("Aspect")
        crop_label.setObjectName("ControlName")
        crop_layout.addWidget(crop_label)
        self.crop_aspect = QComboBox()
        for label, ratio in (("Free", None), ("Original", "original"),
                             ("1 : 1", 1.0), ("4 : 3", 4 / 3),
                             ("3 : 2", 3 / 2), ("16 : 9", 16 / 9)):
            self.crop_aspect.addItem(label, ratio)
        self.crop_aspect.setToolTip("Lock the crop frame to a common aspect ratio")
        self.crop_aspect.currentIndexChanged.connect(self._on_crop_aspect)
        crop_layout.addWidget(self.crop_aspect)
        self.crop_apply_btn = QPushButton("APPLY CROP")
        self.crop_apply_btn.setObjectName("LayerAddBtn")
        self.crop_apply_btn.setCursor(Qt.PointingHandCursor)
        self.crop_apply_btn.clicked.connect(self._commit_crop)
        crop_layout.addWidget(self.crop_apply_btn)
        self.crop_cancel_btn = QPushButton("CANCEL")
        self.crop_cancel_btn.setCursor(Qt.PointingHandCursor)
        self.crop_cancel_btn.clicked.connect(self._cancel_crop)
        crop_layout.addWidget(self.crop_cancel_btn)
        self._crop_controls_action = QWidgetAction(self)
        self._crop_controls_action.setDefaultWidget(crop_controls)

        # Toolbar actions hidden in Normal mode (Advanced-only).
        self._advanced_actions = [
            self.act_open_project,
            self.act_recipe_save, self.act_recipe_apply, self.act_save_project,
            self.act_textures,
            self.act_filters,
        ]
        self._advanced_action_set = set(self._advanced_actions)
        self._toolbar_context = "edit"
        self._context_selectors["edit"].setChecked(True)
        self._show_toolbar_context("edit")

        # Keyboard shortcuts. Modifier combos only (never bare letters) so they
        # can't fire while someone is typing in a text layer or a dialog. Open,
        # Undo, Redo, Export (save) and Help already carry the standard sequences.
        self.act_invert_layer = QAction("Invert selected layer", self)
        self.act_invert_layer.triggered.connect(
            lambda: self._toggle_layer_inversion("invert_rgb"))
        self.addAction(self.act_invert_layer)
        self.act_invert_luminance = QAction("Invert selected layer luminance", self)
        self.act_invert_luminance.triggered.connect(
            lambda: self._toggle_layer_inversion("invert_luminance"))
        self.addAction(self.act_invert_luminance)
        self._shortcuts = [
            (self.act_invert_layer,  "Ctrl+I"),
            (self.act_invert_luminance, "Ctrl+Shift+I"),
            (self.act_auto,         "Ctrl+U"),        # auto-enhance
            (self.act_fit,          "Ctrl+0"),        # fit to window
            (self.act_full,         "Ctrl+1"),        # 100% / actual pixels
            (self.act_zoom_out,     "Ctrl+-"),        # zoom one step out
            (self.act_zoom_in,      "Ctrl++"),        # zoom one step in
            (self.act_reset,        "Ctrl+Shift+R"),  # reset all
            (self.act_crop,         "Ctrl+Shift+C"),  # crop tool
            (self.act_heal,         "Ctrl+Shift+H"),  # heal tool
            (self.act_redeye,       "Ctrl+Shift+E"),  # red-eye tool
            (self.act_colour_range, "Ctrl+Shift+K"),  # colour-range mask
            (self.act_compare,      "Ctrl+\\"),       # before / after
            (self.act_filmstrip,    "Ctrl+Shift+F"),  # filmstrip
            (self.act_lewks,        "Ctrl+K"),        # LEWKS browser
            (self.act_textures,     "Ctrl+T"),        # textures
            (self.act_save_project, "Ctrl+Shift+S"),  # save project
            (self.act_advanced,     "Ctrl+Shift+A"),  # normal / advanced
        ]
        for action, seq in self._shortcuts:
            action.setShortcut(QKeySequence(seq))
            base = action.toolTip() or action.text()
            pretty = QKeySequence(seq).toString(QKeySequence.NativeText)
            action.setToolTip(f"{base}  ({pretty})")
        # Ctrl+= is the no-Shift spelling of Ctrl++ on many keyboards.
        self.act_zoom_in.setShortcuts(
            [QKeySequence("Ctrl++"), QKeySequence("Ctrl+=")])
        self._install_mask_shortcuts()
        self.delete_layer_action = QAction("Delete selected layer", self)
        self.delete_layer_action.setShortcut(QKeySequence(Qt.Key_Delete))
        self.delete_layer_action.setShortcutContext(Qt.WindowShortcut)
        self.delete_layer_action.triggered.connect(self._delete_layer_key)
        self.addAction(self.delete_layer_action)

    def _toggle_layer_inversion(self, field):
        """Toggle a reversible inversion flag on the selected layer."""
        if self._restricted or not self.doc:
            return
        layer = self._active_layer()
        if layer is None:
            self.status.showMessage("Select a layer to invert.", 3000)
            return
        enabled = not bool(layer.get(field, False))
        layer[field] = enabled
        description = ("Invert layer luminance" if field == "invert_luminance"
                       else "Invert layer")
        self.doc.record(description if enabled else f"Restore {description.lower()}")
        self.layers_panel.rebuild()
        self._update_title()

    def _install_mask_shortcuts(self):
        """Install Photoshop-like local-mask keys with a typing guard."""
        bindings = {
            "M": lambda: self._mask_key("edit"),
            "I": lambda: self._mask_key("invert"),
            "G": lambda: self._mask_key("linear"),
            "K": lambda: self._mask_key("bucket"),
            "B": lambda: self._mask_key("brush"),
            "E": lambda: self._mask_key("erase"),
            "[": lambda: self._mask_key("smaller"),
            "]": lambda: self._mask_key("larger"),
            "Shift+[": lambda: self._mask_key("softer"),
            "Shift+]": lambda: self._mask_key("harder"),
        }
        self.mask_shortcut_actions = {}
        for sequence, callback in bindings.items():
            action = QAction(f"Mask {sequence}", self)
            action.setShortcut(QKeySequence(sequence))
            action.setShortcutContext(Qt.WindowShortcut)
            action.triggered.connect(callback)
            self.addAction(action)
            self.mask_shortcut_actions[sequence] = action

    def _mask_key(self, command):
        # Bare creative-tool keys must never steal characters from text fields.
        if isinstance(QApplication.focusWidget(), QLineEdit):
            return
        if self._mask_layer() is None:
            return
        if command == "edit":
            self.open_mask_panel()

        elif command == "invert":
            self.mask_invert.toggle()
        elif command in {"linear", "brush"}:
            self._mask_type_buttons[command].click()
            self.open_mask_panel()
        elif command == "bucket":
            self._mask_type_buttons["brush"].click()
            self.mask_brush.fill(self.mask_brush._paint_white)
        elif command == "erase":
            self._mask_type_buttons["brush"].click()
            self.btn_hide.click()
        elif command in {"smaller", "larger"}:
            delta = -3 if command == "smaller" else 3
            self.brush_size.slider.setValue(self.brush_size.slider.value() + delta)
        elif command in {"softer", "harder"}:
            delta = -10 if command == "softer" else 10
            self.brush_hardness.slider.setValue(
                self.brush_hardness.slider.value() + delta)

    def _delete_layer_key(self):
        """Delete the selected non-base layer without stealing text-edit keys."""
        if isinstance(QApplication.focusWidget(), QLineEdit):
            return
        if self._restricted or not self.doc or self.active_target == BASE:
            return
        self.layers_panel._delete()

    def _activate_canvas_mask_tool(self, kind):
        if self._mask_layer() is None:
            self.status.showMessage("Select or add a layer before editing its mask.")
            return
        self.open_mask_panel()
        self._mask_type_buttons[kind].click()
        if kind == "brush":
            self.paint_on_photo.setChecked(True)
        elif kind == "colour":
            self.mask_eyedropper.setChecked(True)

    def _show_toolbar_context(self, key):
        """Expand one top-row workspace into the contextual second row."""
        if key not in self._toolbar_contexts:
            return
        if (key != "edit" and hasattr(self, "view") and
                self.act_crop.isChecked() and self.view._crop_mode):
            # Crop owns a temporary full-frame state and its Apply/Cancel
            # controls. Letting another workspace hide those controls leaves a
            # checked action that looks broken when the photographer returns.
            # Keep the modal session visibly in EDIT until it is resolved.
            self._context_selectors["edit"].setChecked(True)
            self.status.showMessage(
                "Finish the crop with Apply Crop or Cancel before changing tools.")
            return
        self._toolbar_context = key
        selector = self._context_selectors[key]
        if not selector.isChecked():
            selector.setChecked(True)
        self.context_toolbar.clear()
        advanced = getattr(self, "mode", "advanced") == "advanced"
        # SMACK IT UP is the defining one-click action in Normal mode.  Keep it
        # visible even while IMPROVE, LOOKS, OUTPUT or VIEW is selected instead
        # of hiding it behind the EDIT workspace.
        if not advanced:
            self.context_toolbar.addAction(self.act_auto)
            self.context_toolbar.addSeparator()
        for action in self._toolbar_contexts[key]:
            if action is self.act_auto:
                # SMACK IT UP belongs to the deliberately simple Normal mode;
                # Advanced exposes the individual controls instead.
                continue
            if action in self._advanced_action_set and not advanced:
                continue
            self.context_toolbar.addAction(action)
        if key == "edit" and self.act_crop.isChecked():
            self.context_toolbar.addSeparator()
            self.context_toolbar.addAction(self._crop_controls_action)

    def _error(self, title, message):
        """Show an error dialog AND write it (with traceback if any) to the log."""
        _log.error("%s — %s", title, message, exc_info=sys.exc_info()[0] is not None)
        QMessageBox.critical(self, title, message)

    def _build_canvas(self):
        self.view = ImageView(self)
        self.view.cropped.connect(self._apply_crop)
        self.view.retouch_clicked.connect(self._add_retouch)
        self.view.neutral_clicked.connect(self._apply_neutral_sample)
        self.view.colour_range_clicked.connect(self._apply_colour_sample)
        self.view.vignette_colour_clicked.connect(self._apply_vignette_colour_sample)
        self.view.mask_painted.connect(self._paint_canvas_mask)
        self.view.gradient_drawn.connect(self._apply_drawn_gradient)
        self.view.layer_dragged.connect(self._move_active_layer)
        self.view.perspective_corner_dragged.connect(self._move_perspective_corner)
        self.view.perspective_commit_requested.connect(self._commit_free_perspective)
        self.view.horizon_drawn.connect(self._apply_horizon_curve)
        self._layer_drag_changed = False

        self.filmstrip = Filmstrip(self)
        self.filmstrip.open_requested.connect(self._open_from_filmstrip)
        self.filmstrip.setVisible(self._filmstrip_visible)
        self.filmstrip_handle = QPushButton()
        self.filmstrip_handle.setObjectName("FilmstripHandle")
        self.filmstrip_handle.setFixedHeight(24)
        self.filmstrip_handle.setCursor(Qt.PointingHandCursor)
        self.filmstrip_handle.setToolTip(
            "Open or close the folder thumbnail strip (Ctrl+Shift+F)")
        self.filmstrip_handle.clicked.connect(
            lambda: self.act_filmstrip.setChecked(not self.act_filmstrip.isChecked()))
        self._sync_filmstrip_handle()

        container = QWidget()
        layout = QVBoxLayout(container)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)
        self._canvas_layout = layout

        # The histogram can remain visible while the editing controls scroll.
        self._histogram_wrap = self._build_histogram()
        layout.addWidget(self._histogram_wrap, 0)
        layout.addWidget(self.view, 1)
        layout.addWidget(self.filmstrip_handle, 0)
        layout.addWidget(self.filmstrip, 0)
        self.setCentralWidget(container)

        self._saved_crop = None
        self._crop_commit_in_progress = False
        self._retouch_radius = 0.035
        self._retouch_type = "heal"
        self._clone_source = None

    def _build_rail(self):
        self._sections = {}          # title -> Accordion (for Normal/Advanced)
        self._bw_mixer_widgets = []  # the 8 colour sliders + hint (advanced only)
        rail = QWidget()
        rail.setObjectName("Rail")
        # Give the readable label column and sliders room without relying on
        # Qt to elide control names at ordinary desktop scaling.
        rail.setFixedWidth(320)
        layout = QVBoxLayout(rail)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)
        self._rail_layout = layout

        scroll = QScrollArea()
        self.rail_scroll = scroll
        scroll.setWidgetResizable(True)
        scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        inner = QWidget()
        # The rail owns the width. Long translated labels, layer names, or
        # high-DPI font metrics must shrink/reflow inside it rather than making
        # the scroll-area child wider and clipping its right-hand controls.
        inner.setMinimumWidth(0)
        inner.setSizePolicy(QSizePolicy.Ignored, QSizePolicy.Preferred)
        inner_layout = QVBoxLayout(inner)
        inner_layout.setContentsMargins(0, 0, 0, 0)
        inner_layout.setSpacing(0)
        self._rail_inner_layout = inner_layout

        # "Editing: …" target indicator
        self.target_label = QLabel("Editing: Base image")
        self.target_label.setObjectName("TargetLabel")
        inner_layout.addWidget(self.target_label)

        # Text-layer editor (only visible when a text layer is selected)
        self.text_section = Accordion("TEXT LAYER", expanded=True)
        self.text_section.add(self._build_text_panel())
        self.text_section.setVisible(False)
        inner_layout.addWidget(self.text_section)

        # Layer mask (only visible when a layer is selected)
        self.mask_section = Accordion("MASK", expanded=False)
        self.mask_section.add(self._build_mask_panel())
        self.mask_section.setVisible(False)
        inner_layout.addWidget(self.mask_section)

        self.raw_rows = {}
        raw_section = Accordion("RAW DEVELOP", expanded=True)
        raw_hint = QLabel(
            "Camera-file controls. Tone preview is immediate; RawTherapee resolves "
            "geometry and Lensfun corrections when a control is released.")
        raw_hint.setObjectName("TargetLabel")
        raw_hint.setWordWrap(True)
        raw_section.add(raw_hint)
        for key, label, start, end, resolution, default in RAW_DEVELOP_CONTROLS:
            srow = SliderRow(key, label, start, end, resolution, default)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.raw_rows[key] = srow
            raw_section.add(srow)
        self.raw_lensfun_check = QCheckBox("Use Lensfun auto profile")
        self.raw_lensfun_distortion = QCheckBox("Lensfun distortion")
        self.raw_lensfun_vignette = QCheckBox("Lensfun vignetting")
        self.raw_lensfun_ca = QCheckBox("Lensfun chromatic aberration")
        self._raw_lensfun_checks = {
            "raw_lensfun": self.raw_lensfun_check,
            "raw_lensfun_distortion": self.raw_lensfun_distortion,
            "raw_lensfun_vignette": self.raw_lensfun_vignette,
            "raw_lensfun_ca": self.raw_lensfun_ca,
        }
        for key, checkbox in self._raw_lensfun_checks.items():
            checkbox.toggled.connect(
                lambda checked, setting=key: self._on_raw_option(setting, checked))
            raw_section.add(checkbox)
        raw_section.setVisible(False)
        inner_layout.addWidget(raw_section)
        self._sections["RAW DEVELOP"] = raw_section

        for title, controls in GROUPS:
            section = Accordion(title, expanded=(title == "LIGHT"))
            if title == "LIGHT":
                self.auto_exposure_button = QPushButton("Auto Exposure")
                self.auto_exposure_button.setToolTip(
                    "Set exposure only, while preserving highlight headroom")
                self.auto_exposure_button.clicked.connect(self.auto_exposure)
                section.add(self.auto_exposure_button)
            for key, label, start, end, resolution, default in controls:
                srow = SliderRow(key, label, start, end, resolution, default)
                srow.changed.connect(self._on_adjust)
                srow.committed.connect(self._on_commit)
                self.rows[key] = srow
                section.add(srow)
            inner_layout.addWidget(section)
            self._sections[title] = section

        # Split-tone controls live in the COLOUR section
        if "COLOUR" in self._sections:
            self._sections["COLOUR"].add(self._build_neutral_picker())
            self._sections["COLOUR"].add(self._build_split_tone())

        # Smart-sharpen detail controls sit under the PRESENCE Sharpen slider
        if "PRESENCE" in self._sections:
            self._sections["PRESENCE"].add(self._build_sharpen_detail())

        # Darken-only grain toggle lives with the EFFECTS controls
        self.grain_darken_check = QCheckBox("Darken-only grain (film style)")
        self.grain_darken_check.setToolTip(
            "Grain that only darkens — like real film grain — instead of also "
            "brightening the photo.")
        self.grain_darken_check.toggled.connect(self._on_grain_darken)
        if "EFFECTS" in self._sections:
            self._sections["EFFECTS"].add(self._build_vignette_style())
            self._sections["EFFECTS"].add(self.grain_darken_check)

        # Tone curve — master + per-channel (R / G / B)
        curve_section = Accordion("TONE CURVE", expanded=False)
        self.curve_editor = CurveEditor()
        self.curve_editor.changed.connect(self._on_curve_changed)
        curve_section.add(self.curve_editor)
        inner_layout.addWidget(curve_section)
        self._sections["TONE CURVE"] = curve_section

        # Geometry (rotate / straighten / flip)
        geo_section = Accordion("GEOMETRY", expanded=False)
        geo_section.add(self._build_geometry())
        inner_layout.addWidget(geo_section)
        self._sections["GEOMETRY"] = geo_section

        # Improve (spot heal / red-eye and AI repair)
        retouch_section = Accordion("IMPROVE", expanded=False)
        retouch_section.add(self._build_retouch())
        inner_layout.addWidget(retouch_section)
        self._sections["IMPROVE"] = retouch_section

        # Black & white — neutral toggle + per-colour luminance mix
        bw_section = Accordion("BLACK + WHITE", expanded=False)
        self.bw_check = QCheckBox("Convert to black and white")
        self.bw_check.toggled.connect(self._on_bw)
        bw_section.add(self.bw_check)
        bw_hint = QLabel("Colour mix — how each colour becomes grey")
        bw_hint.setObjectName("TargetLabel")
        bw_section.add(bw_hint)
        self._bw_mixer_widgets.append(bw_hint)
        for key, label in (("bw_red", "Red"), ("bw_orange", "Orange"),
                           ("bw_yellow", "Yellow"), ("bw_green", "Green"),
                           ("bw_aqua", "Aqua"), ("bw_blue", "Blue"),
                           ("bw_purple", "Purple"), ("bw_magenta", "Magenta")):
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            bw_section.add(srow)
            self._bw_mixer_widgets.append(srow)
        inner_layout.addWidget(bw_section)
        self._sections["BLACK + WHITE"] = bw_section

        # Colour mix — per-hue remapping, saturation + luminance (HSL in colour)
        hsl_section = Accordion("COLOUR MIX", expanded=False)
        _bands = (("red", "Red"), ("orange", "Orange"), ("yellow", "Yellow"),
                  ("green", "Green"), ("aqua", "Aqua"), ("blue", "Blue"),
                  ("purple", "Purple"), ("magenta", "Magenta"))
        sat_hint = QLabel("Saturation — how vivid each colour is")
        sat_hint.setObjectName("TargetLabel")
        hsl_section.add(sat_hint)
        for band, label in _bands:
            key = f"col_sat_{band}"
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            hsl_section.add(srow)
        hue_hint = QLabel("Hue shift — move each colour somewhere new")
        hue_hint.setObjectName("TargetLabel")
        hsl_section.add(hue_hint)
        for band, label in _bands:
            key = f"col_hue_{band}"
            srow = SliderRow(key, label, -180, 180, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            hsl_section.add(srow)
        lum_hint = QLabel("Luminance — how light or dark each colour is")
        lum_hint.setObjectName("TargetLabel")
        hsl_section.add(lum_hint)
        for band, label in _bands:
            key = f"col_lum_{band}"
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            hsl_section.add(srow)
        inner_layout.addWidget(hsl_section)
        self._sections["COLOUR MIX"] = hsl_section

        # Glow — a placed colour bloom (centre spotlight or coloured leak)
        glow_section = Accordion("GLOW", expanded=False)
        gwrap = QWidget()
        gl = QVBoxLayout(gwrap)
        gl.setContentsMargins(12, 4, 12, 6)
        gl.setSpacing(4)
        ghint = QLabel("A soft colour bloom you can place — a centre glow or a "
                       "coloured light leak.")
        ghint.setObjectName("TargetLabel")
        ghint.setWordWrap(True)
        gl.addWidget(ghint)
        self.glow_btn = QPushButton("Glow colour")
        self.glow_btn.setObjectName("SwatchBtn")
        self.glow_btn.setCursor(Qt.PointingHandCursor)
        self.glow_btn.clicked.connect(lambda: self._pick_split_colour("glow_colour"))
        gl.addWidget(self.glow_btn)
        for key, label, lo, hi, df in (("glow_amount", "Amount", 0, 100, 0),
                                       ("glow_x", "Position X", 0, 100, 50),
                                       ("glow_y", "Position Y", 0, 100, 40),
                                       ("glow_size", "Size", 5, 100, 45)):
            srow = SliderRow(key, label, lo, hi, 1, df)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            gl.addWidget(srow)
        glow_section.add(gwrap)
        inner_layout.addWidget(glow_section)
        self._sections["GLOW"] = glow_section

        # Photo filter — a coloured gel over the lens (warming / cooling / colour
        # filters + a few faux-infrared washes)
        pf_section = Accordion("PHOTO FILTER", expanded=False)
        pf_section.add(self._build_photo_filter())
        inner_layout.addWidget(pf_section)
        self._sections["PHOTO FILTER"] = pf_section

        # Layers and history belong after the editing tools. The controls lead;
        # document structure and navigation remain available at the bottom.
        layers_section = Accordion("LAYERS", expanded=True)
        self.layers_panel = LayersPanel(self)
        layers_section.add(self.layers_panel)
        inner_layout.addWidget(layers_section)
        self._sections["LAYERS"] = layers_section

        self.history_section = Accordion("HISTORY", expanded=True)
        self.history_list = QListWidget()
        self.history_list.setMaximumHeight(150)
        self.history_list.setToolTip("Click an earlier step to return to it")
        self.history_list.itemClicked.connect(self._history_selected)
        self.history_section.add(self.history_list)
        inner_layout.addWidget(self.history_section)
        self._sections["HISTORY"] = self.history_section

        inner_layout.addStretch(1)
        scroll.setWidget(inner)
        layout.addWidget(scroll)

        if self._histogram_locked:
            self._rail_layout.insertWidget(0, self._histogram_wrap, 0)
        else:
            self._sections["LIGHT"].body_layout.insertWidget(0, self._histogram_wrap)

        from PySide6.QtWidgets import QDockWidget
        dock = QDockWidget("", self)
        dock.setTitleBarWidget(QWidget())  # no title chrome
        dock.setFeatures(QDockWidget.NoDockWidgetFeatures)
        dock.setAllowedAreas(Qt.RightDockWidgetArea)
        dock.setWidget(rail)
        self.addDockWidget(Qt.RightDockWidgetArea, dock)
        self._controls_dock = dock

    def set_restricted_mode(self, restricted=True):
        """Unlicensed mode may open/view and export formats, nothing else."""
        self._restricted = bool(restricted)
        if hasattr(self, "_controls_dock"):
            self._controls_dock.setEnabled(not self._restricted)
        self._refresh_actions()
        if self._restricted:
            self.statusBar().showMessage(
                "RESTRICTED — authorize this computer in SNAP HQ to edit, organize, publish, or use LEWK AGAIN.")

    def _build_histogram(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(12, 6, 12, 6)
        layout.setSpacing(6)

        header = QHBoxLayout()
        header.setSpacing(4)
        title = QLabel("LIVE HISTOGRAM")
        title.setObjectName("ControlName")
        header.addWidget(title)
        header.addStretch(1)

        lock = QCheckBox("Lock")
        self.histogram_lock = lock
        lock.setChecked(self._histogram_locked)
        lock.setToolTip("Keep the histogram visible while the controls scroll")
        lock.toggled.connect(self._set_histogram_locked)
        header.addWidget(lock)

        self._hist_mode = "luma"
        self._hist_buttons = QButtonGroup(self)
        for text, value in (("LUMA", "luma"), ("RGB", "rgb")):
            btn = QPushButton(text)
            btn.setObjectName("MiniToggle")
            btn.setCheckable(True)
            btn.setChecked(value == "luma")
            btn.setCursor(Qt.PointingHandCursor)
            btn.setFixedHeight(20)
            btn.clicked.connect(lambda _c, v=value: self._set_hist_mode(v))
            self._hist_buttons.addButton(btn)
            header.addWidget(btn)
        layout.addLayout(header)

        self.histogram = Histogram()
        layout.addWidget(self.histogram)
        return wrap

    def _set_histogram_locked(self, checked):
        """Move the one live histogram above the rail or back into Light."""
        self._histogram_locked = bool(checked)
        if not hasattr(self, "_rail_layout") or "LIGHT" not in self._sections:
            return
        if checked:
            self._rail_layout.insertWidget(0, self._histogram_wrap, 0)
        else:
            self._sections["LIGHT"].body_layout.insertWidget(0, self._histogram_wrap)
        from . import prefs
        values = prefs.load()
        values["histogram_locked"] = self._histogram_locked
        prefs.save(values)

    def _build_geometry(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(0, 2, 0, 4)
        layout.setSpacing(4)

        self.rotation_row = SliderRow("rotation", "Rotate", -180, 180, 0.5, 0)
        self.rotation_row.changed.connect(self._on_geometry)
        self.rotation_row.committed.connect(lambda _k: self._commit_geometry("Rotate"))
        layout.addWidget(self.rotation_row)

        self.perspective_v_row = SliderRow(
            "perspective_vertical", "Vertical perspective", -100, 100, 1, 0)
        self.perspective_h_row = SliderRow(
            "perspective_horizontal", "Horizontal perspective", -100, 100, 1, 0)
        for row, label in ((self.perspective_v_row, "Vertical perspective"),
                           (self.perspective_h_row, "Horizontal perspective")):
            row.changed.connect(self._on_perspective)
            row.committed.connect(lambda _key, text=label: self._commit_geometry(text))
            layout.addWidget(row)

        perspective_buttons = QHBoxLayout()
        perspective_buttons.setContentsMargins(12, 2, 12, 2)
        self.free_perspective_btn = QPushButton("Free Corners")
        self.free_perspective_btn.setCheckable(True)
        self.free_perspective_btn.setCursor(Qt.PointingHandCursor)
        self.free_perspective_btn.setToolTip(
            "Drag any corner. Straight lines stay straight; this is not a bend or liquify tool.")
        self.free_perspective_btn.toggled.connect(self._toggle_free_perspective)
        perspective_buttons.addWidget(self.free_perspective_btn)
        self.perspective_edges = QComboBox()
        self.perspective_edges.addItem("Auto Crop", "auto_crop")
        self.perspective_edges.addItem("Transparent Edges", "transparent")
        self.perspective_edges.setToolTip(
            "Auto Crop removes empty edges; Transparent preserves the full canvas")
        self.perspective_edges.currentIndexChanged.connect(self._on_perspective_edges)
        perspective_buttons.addWidget(self.perspective_edges, 1)
        layout.addLayout(perspective_buttons)

        horizon_hint = QLabel("Trace the real curved horizon from one side to the other. "
                              "SNAP SLAPPER bends it level without changing bit depth.")
        horizon_hint.setObjectName("TargetLabel")
        horizon_hint.setWordWrap(True)
        layout.addWidget(horizon_hint)
        self.horizon_draw_btn = QPushButton("Draw Curved Horizon")
        self.horizon_draw_btn.setCheckable(True)
        self.horizon_draw_btn.setCursor(Qt.PointingHandCursor)
        self.horizon_draw_btn.toggled.connect(self._toggle_horizon_draw)
        layout.addWidget(self.horizon_draw_btn)
        self.horizon_strength_row = SliderRow(
            "horizon_strength", "Horizon strength", 0, 100, 1, 100)
        self.horizon_protection_row = SliderRow(
            "horizon_edge_protection", "Edge protection", 0, 100, 1, 60)
        for row in (self.horizon_strength_row, self.horizon_protection_row):
            row.changed.connect(self._on_horizon_setting)
            row.committed.connect(lambda _key: self._commit_geometry("Curved horizon"))
            layout.addWidget(row)
        clear_horizon = QPushButton("Clear Horizon Correction")
        clear_horizon.clicked.connect(self._clear_horizon)
        layout.addWidget(clear_horizon)

        self.lens_distortion_row = SliderRow(
            "lens_distortion", "Barrel / pincushion", -100, 100, 0.5, 0)
        self.lens_spherical_row = SliderRow(
            "lens_spherical", "Spherical / fisheye", -100, 100, 0.5, 0)
        self.lens_center_x_row = SliderRow(
            "lens_center_x", "Distortion centre X", -50, 50, 0.5, 0)
        self.lens_center_y_row = SliderRow(
            "lens_center_y", "Distortion centre Y", -50, 50, 0.5, 0)
        self.lens_scale_row = SliderRow(
            "lens_scale", "Edge scale", 100, 160, 1, 100)
        self._lens_rows = (self.lens_distortion_row, self.lens_spherical_row,
                           self.lens_center_x_row, self.lens_center_y_row,
                           self.lens_scale_row)
        for row in self._lens_rows:
            row.changed.connect(self._on_lens_geometry)
            row.committed.connect(lambda _key: self._commit_geometry("Lens distortion"))
            layout.addWidget(row)
        self.lens_edges = QComboBox()
        self.lens_edges.addItem("Auto Crop Edges", "auto_crop")
        self.lens_edges.addItem("Auto Fill Edges", "auto_fill")
        self.lens_edges.setToolTip(
            "Auto Crop trims curved gaps; Auto Fill zooms to retain the canvas size")
        self.lens_edges.currentIndexChanged.connect(self._on_lens_edges)
        layout.addWidget(self.lens_edges)

        buttons = QHBoxLayout()
        buttons.setContentsMargins(12, 2, 12, 2)
        buttons.setSpacing(4)
        self.flip_h_btn = QPushButton("Flip H")
        self.flip_v_btn = QPushButton("Flip V")
        for btn, axis in ((self.flip_h_btn, "flip_x"), (self.flip_v_btn, "flip_y")):
            btn.setObjectName("LayerAddBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, a=axis: self._flip(a))
            buttons.addWidget(btn)
        reset_geo = QPushButton("Reset")
        reset_geo.setObjectName("LayerOrderBtn")
        reset_geo.setCursor(Qt.PointingHandCursor)
        reset_geo.clicked.connect(self._reset_geometry)
        buttons.addWidget(reset_geo)
        layout.addLayout(buttons)
        return wrap

    def _on_geometry(self, _key, value):
        if not self.doc:
            return
        self.doc.geometry["rotation"] = float(value)
        self._schedule_render()

    def _on_perspective(self, key, value):
        if not self.doc:
            return
        self.doc.geometry[key] = float(value)
        self._interactive_render = True
        self._geometry_preview_mode = "perspective"
        self._schedule_render()

    def _on_lens_geometry(self, key, value):
        if not self.doc:
            return
        self.doc.geometry[key] = float(value)
        self._interactive_render = True
        self._geometry_preview_mode = "lens"
        self._schedule_render()

    def _on_lens_edges(self, index):
        if not self.doc:
            return
        value = self.lens_edges.itemData(index) or "auto_crop"
        if self.doc.geometry.get("lens_edges", "auto_crop") != value:
            self.doc.geometry["lens_edges"] = value
            self.doc.record("Lens edge handling")
            self._render_preview()
            self._update_title()

    def _toggle_free_perspective(self, enabled):
        if enabled and self.horizon_draw_btn.isChecked():
            self.horizon_draw_btn.setChecked(False)
        if enabled and self.act_crop.isChecked():
            # Crop and perspective both own a full-canvas frame/grid. They are
            # modal tools and must never be painted or receive input together.
            self.act_crop.setChecked(False)
        corners = (self.doc.geometry.get("perspective_corners") if self.doc else None)
        self.view.set_perspective_mode(enabled, corners)
        if enabled:
            self.status.showMessage("Drag a red corner handle; straight lines remain straight")
        elif self.doc:
            # While handles are active the preview deliberately keeps a fixed
            # transparent canvas. Leaving the tool is the point at which the
            # selected Auto Crop policy may change the output dimensions.
            self._render_preview(keep_view=True)

    def _commit_free_perspective(self):
        """Apply the active four-corner correction and leave its modal tool."""
        if self.free_perspective_btn.isChecked():
            self.free_perspective_btn.setChecked(False)

    def _toggle_horizon_draw(self, enabled):
        if enabled:
            if self.act_crop.isChecked():
                self.act_crop.setChecked(False)
            self.free_perspective_btn.setChecked(False)
            self.status.showMessage("Draw along the curved horizon from left to right")
        points = self.doc.geometry.get("horizon_curve", []) if self.doc else []
        self.view.set_horizon_mode(enabled, points)

    def _apply_horizon_curve(self, points):
        if not self.doc:
            return
        self.doc.geometry["horizon_curve"] = [[round(x, 6), round(y, 6)] for x, y in points]
        self.doc.record("Straighten curved horizon")
        self.horizon_draw_btn.setChecked(False)
        self._update_title()
        self._render_preview(keep_view=True)

    def _on_horizon_setting(self, key, value):
        if not self.doc:
            return
        self.doc.geometry[key] = float(value)
        self._interactive_render = True
        self._schedule_render()

    def _clear_horizon(self):
        if not self.doc:
            return
        self.doc.geometry["horizon_curve"] = []
        self.doc.record("Clear curved horizon")
        self.horizon_draw_btn.setChecked(False)
        self._render_preview()
        self._update_title()

    def _move_perspective_corner(self, index, x, y, finished):
        if not self.doc:
            return
        corners = [list(point) for point in self.doc.geometry.get(
            "perspective_corners", [[0, 0], [1, 0], [1, 1], [0, 1]])]
        # Keep a valid convex quadrilateral while dragging. A corner may fan
        # beyond the canvas, but it may not cross its two neighbours.
        limits = {
            0: (-0.5, corners[1][0] - .01, -0.5, corners[3][1] - .01),
            1: (corners[0][0] + .01, 1.5, -0.5, corners[2][1] - .01),
            2: (corners[3][0] + .01, 1.5, corners[1][1] + .01, 1.5),
            3: (-0.5, corners[2][0] - .01, corners[0][1] + .01, 1.5),
        }
        min_x, max_x, min_y, max_y = limits[index]
        x = max(min_x, min(max_x, float(x)))
        y = max(min_y, min(max_y, float(y)))
        corners[index] = [round(float(x), 6), round(float(y), 6)]
        self.doc.geometry["perspective_corners"] = corners
        self.view.set_perspective_corners(corners)
        self._interactive_render = True
        self._geometry_preview_mode = "perspective"
        if finished:
            self.doc.record("Free perspective")
            self._update_title()
            self._finish_geometry_preview()
        else:
            self._schedule_render()

    def _on_perspective_edges(self, index):
        if not self.doc:
            return
        value = self.perspective_edges.itemData(index) or "auto_crop"
        if self.doc.geometry.get("perspective_edges") != value:
            self.doc.geometry["perspective_edges"] = value
            self.doc.record("Perspective edges")
            self._render_preview()
            self._update_title()

    def _commit_geometry(self, label):
        if self.doc:
            self.doc.record(label)
            self._update_title()
            self._finish_geometry_preview()

    def _finish_geometry_preview(self):
        """Resolve one stable geometry interaction at full quality."""
        self._render_timer.stop()
        self._preview_generation += 1
        self._interactive_render = False
        self._geometry_preview_mode = None
        self._render_preview(keep_view=True)

    def _flip(self, axis):
        if not self.doc:
            self._sync_geometry()
            return
        self.doc.geometry[axis] = not self.doc.geometry.get(axis, False)
        self.doc.record("Flip")
        self._render_preview()
        self._update_title()
        self._sync_geometry()

    def _reset_geometry(self):
        if not self.doc:
            return
        self.doc.geometry.update({"rotation": 0.0, "crop": None,
                                  "flip_x": False, "flip_y": False,
                                  "perspective_vertical": 0.0,
                                  "perspective_horizontal": 0.0,
                                  "perspective_corners": [[0.0, 0.0], [1.0, 0.0],
                                                          [1.0, 1.0], [0.0, 1.0]],
                                  "perspective_edges": "auto_crop",
                                  "lens_distortion": 0.0, "lens_spherical": 0.0,
                                  "lens_center_x": 0.0, "lens_center_y": 0.0,
                                  "lens_scale": 100.0, "lens_edges": "auto_crop",
                                  "horizon_curve": [], "horizon_strength": 100.0,
                                  "horizon_edge_protection": 60.0})
        self.free_perspective_btn.setChecked(False)
        self.horizon_draw_btn.setChecked(False)
        self.doc.record("Reset geometry")
        self._sync_geometry()
        self._render_preview()
        self._update_title()

    def _build_mask_panel(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(12, 4, 12, 6)
        layout.setSpacing(6)

        hint = QLabel("A mask controls where this layer appears. White reveals "
                      "the layer; black hides it. The photo shows the result directly.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        layout.addWidget(hint)

        # --- Type chooser (pick first) --------------------------------------
        type_row = QGridLayout()
        type_row.setContentsMargins(0, 0, 0, 0)
        type_row.setSpacing(4)
        self.mask_type_group = QButtonGroup(self)
        self.mask_type_group.setExclusive(True)
        self._mask_type_buttons = {}
        for index, (kind, label) in enumerate((
                ("radial", "Oval / spotlight"), ("linear", "Linear gradient"),
                ("brush", "Paint by hand"), ("colour", "Select by colour"))):
            btn = QPushButton(label)
            btn.setObjectName("MaskTypeBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, k=kind: self._select_mask_type(k))
            self.mask_type_group.addButton(btn)
            self._mask_type_buttons[kind] = btn
            type_row.addWidget(btn, index // 2, index % 2)
        layout.addLayout(type_row)

        # --- Controls that change with the type -----------------------------
        self.mask_stack = QStackedWidget()

        # Radial page
        radial_page = QWidget()
        rl = QVBoxLayout(radial_page)
        rl.setContentsMargins(0, 0, 0, 0)
        rl.setSpacing(4)
        self.mask_cx = SliderRow("cx", "Centre X", 0, 100, 1, 50)
        self.mask_cy = SliderRow("cy", "Centre Y", 0, 100, 1, 50)
        self.mask_size = SliderRow("size", "Size", 5, 100, 1, 40)
        self.mask_soft = SliderRow("soft", "Softness", 0, 60, 1, 15)
        for row in (self.mask_cx, self.mask_cy, self.mask_size, self.mask_soft):
            row.committed.connect(self._reapply_mask)   # apply on release, not per tick
            rl.addWidget(row)
        self.mask_stack.addWidget(radial_page)

        # Linear gradients paint into one mask, like repeated black/white
        # foreground-to-transparent strokes in a conventional mask editor.
        linear_page = QWidget()
        ll = QVBoxLayout(linear_page)
        ll.setContentsMargins(0, 0, 0, 0)
        ll.setSpacing(4)
        mode_row = QHBoxLayout()
        self.gradient_mode_group = QButtonGroup(self)
        self.gradient_mode_group.setExclusive(True)
        self.gradient_hide = QPushButton("Hide from edge")
        self.gradient_reveal = QPushButton("Reveal from edge")
        for button in (self.gradient_hide, self.gradient_reveal):
            button.setObjectName("MaskTypeBtn")
            button.setCheckable(True)
            self.gradient_mode_group.addButton(button)
            mode_row.addWidget(button)
        self.gradient_hide.setChecked(True)
        ll.addLayout(mode_row)
        draw_hint = QLabel("Start at an edge and drag inward. Each drag adds to "
                           "the same mask; use Reveal to bring an area back. "
                           "Ctrl+Z undoes the last stroke.")
        draw_hint.setObjectName("TargetLabel")
        draw_hint.setWordWrap(True)
        ll.addWidget(draw_hint)
        self.mask_stack.addWidget(linear_page)

        # Brush page
        brush_page = QWidget()
        bl = QVBoxLayout(brush_page)
        bl.setContentsMargins(0, 0, 0, 0)
        bl.setSpacing(4)
        paint_hint = QLabel("Paint on the photo: Hide dims this layer, "
                            "Reveal brings it back.")
        paint_hint.setObjectName("TargetLabel")
        paint_hint.setWordWrap(True)
        bl.addWidget(paint_hint)
        self.paint_on_photo = QPushButton("Paint mask on full photo")
        self.paint_on_photo.setObjectName("LayerAddBtn")
        self.paint_on_photo.setCheckable(True)
        self.paint_on_photo.setToolTip(
            "Paint this layer mask directly on the large photograph")
        self.paint_on_photo.toggled.connect(self._toggle_canvas_mask_paint)
        bl.addWidget(self.paint_on_photo)
        self.mask_brush = MaskBrushCanvas()
        self.mask_brush.mask_changed.connect(self._store_brush_mask)
        bl.addWidget(self.mask_brush, 0, Qt.AlignHCenter)
        paint_row = QHBoxLayout()
        paint_row.setContentsMargins(0, 0, 0, 0)
        paint_row.setSpacing(4)
        self.brush_paint_group = QButtonGroup(self)
        self.brush_paint_group.setExclusive(True)
        self.btn_hide = QPushButton("Hide")
        self.btn_reveal = QPushButton("Reveal")
        for btn, white in ((self.btn_hide, False), (self.btn_reveal, True)):
            btn.setObjectName("MaskTypeBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, w=white: self.mask_brush.set_paint_white(w))
            self.brush_paint_group.addButton(btn)
            paint_row.addWidget(btn)
        self.btn_hide.setChecked(True)
        bl.addLayout(paint_row)

        preset_row = QHBoxLayout()
        for label, size, hardness in (("Soft", 28, 20), ("Medium", 22, 60),
                                      ("Hard", 16, 95)):
            button = QPushButton(label)
            button.setObjectName("LayerOrderBtn")
            button.clicked.connect(
                lambda _c, s=size, h=hardness: self._set_brush_preset(s, h))
            preset_row.addWidget(button)
        bl.addLayout(preset_row)

        self.brush_size = SliderRow("brush", "Brush size", 3, 60, 1, 22)
        self.brush_size.changed.connect(
            lambda _k, v: self.mask_brush.set_radius(int(v)))
        bl.addWidget(self.brush_size)
        self.brush_hardness = SliderRow("brush_hardness", "Hardness", 0, 100, 1, 70)
        self.brush_hardness.changed.connect(
            lambda _k, v: self.mask_brush.set_hardness(int(v)))
        bl.addWidget(self.brush_hardness)
        self.brush_opacity = SliderRow("brush_opacity", "Opacity", 1, 100, 1, 100)
        self.brush_opacity.changed.connect(
            lambda _k, v: self.mask_brush.set_opacity(int(v)))
        bl.addWidget(self.brush_opacity)
        self.brush_flow = SliderRow("brush_flow", "Flow", 1, 100, 1, 100)
        self.brush_flow.changed.connect(
            lambda _k, v: self.mask_brush.set_flow(int(v)))
        bl.addWidget(self.brush_flow)

        bucket_row = QHBoxLayout()
        fill_hide = QPushButton("Bucket: Hide all")
        fill_reveal = QPushButton("Bucket: Reveal all")
        for button, white in ((fill_hide, False), (fill_reveal, True)):
            button.setObjectName("LayerOrderBtn")
            button.setToolTip("Fill the entire current mask")
            button.clicked.connect(lambda _c, w=white: self.mask_brush.fill(w))
            bucket_row.addWidget(button)
        bl.addLayout(bucket_row)
        self.mask_stack.addWidget(brush_page)

        # Colour-range page
        colour_page = QWidget()
        cl = QVBoxLayout(colour_page)
        cl.setContentsMargins(0, 0, 0, 0)
        cl.setSpacing(4)
        self.mask_eyedropper = QPushButton("Eyedropper — pick colour on photo")
        self.mask_eyedropper.setObjectName("LayerAddBtn")
        self.mask_eyedropper.setCheckable(True)
        self.mask_eyedropper.setToolTip(
            "Click, then click the photograph to seed this colour-range mask")
        self.mask_eyedropper.toggled.connect(self._toggle_colour_picker)
        cl.addWidget(self.mask_eyedropper)
        self.mask_hue = SliderRow("hue", "Hue", 0, 359, 1, 30)
        self.mask_hue_range = SliderRow("hue_range", "Hue range", 2, 180, 1, 30)
        self.mask_min_sat = SliderRow("min_sat", "Minimum saturation", 0, 100, 1, 10)
        self.mask_min_lum = SliderRow("min_lum", "Minimum luminance", 0, 100, 1, 0)
        self.mask_max_lum = SliderRow("max_lum", "Maximum luminance", 0, 100, 1, 100)
        self.mask_colour_soft = SliderRow("colour_soft", "Softness", 0, 100, 1, 15)
        for row in (self.mask_hue, self.mask_hue_range, self.mask_min_sat,
                    self.mask_min_lum, self.mask_max_lum, self.mask_colour_soft):
            row.committed.connect(self._reapply_mask)
            cl.addWidget(row)
        self.mask_stack.addWidget(colour_page)

        layout.addWidget(self.mask_stack)

        # --- Shared: invert + clear -----------------------------------------
        self.mask_invert = QCheckBox("Swap hidden and revealed areas")
        self.mask_invert.toggled.connect(self._reapply_mask)
        layout.addWidget(self.mask_invert)

        clear_row = QHBoxLayout()
        clear_row.setContentsMargins(0, 2, 0, 0)
        clear = QPushButton("Clear mask")
        clear.setObjectName("LayerOrderBtn")
        clear.setCursor(Qt.PointingHandCursor)
        clear.clicked.connect(self._clear_mask)
        clear_row.addWidget(clear)
        layout.addLayout(clear_row)

        self._mask_kind = "radial"
        self._mask_type_buttons["radial"].setChecked(True)
        self.mask_stack.setCurrentIndex(0)
        return wrap

    _MASK_PAGES = {"radial": 0, "linear": 1, "brush": 2, "colour": 3}

    def _set_brush_preset(self, size, hardness):
        self.brush_size.set_value(size)
        self.brush_hardness.set_value(hardness)
        self.mask_brush.set_radius(size)
        self.mask_brush.set_hardness(hardness)

    def _select_mask_type(self, kind):
        if hasattr(self, "mask_eyedropper") and kind != "colour":
            self.mask_eyedropper.setChecked(False)
        if hasattr(self, "paint_on_photo") and kind != "brush":
            self.paint_on_photo.setChecked(False)
        self._mask_kind = kind
        self.mask_stack.setCurrentIndex(self._MASK_PAGES[kind])
        self.mask_invert.setVisible(kind != "linear")
        self.view.set_gradient_mode(kind == "linear")
        if kind == "brush":
            self._seed_brush()
        elif kind != "linear":
            self._reapply_mask()

    def _mask_layer(self):
        if not self.doc or self.active_target == BASE:
            return None
        for layer in self.doc.layers:
            if layer.get("id") == self.active_target:
                return layer
        return None

    def _mask_target_size(self):
        # A mask only needs the composition's current aspect ratio.  Rendering
        # the whole document here used to make every mask-slider commit block
        # Qt's event thread (especially with RAW and texture layers) merely to
        # rediscover dimensions we already have in the visible preview.
        probe = self._last_rendered
        if probe is None:
            # This is only an early-open fallback; normal mask editing starts
            # after the first background preview has arrived.
            probe = self.doc.render((256, 256))
        long_edge = 1600
        scale = long_edge / max(probe.width, probe.height)
        return (max(1, int(probe.width * scale)), max(1, int(probe.height * scale)))

    def _apply_radial_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        mask = masks.radial_mask(
            self._mask_target_size(), self.mask_cx.slider.value() / 100.0,
            self.mask_cy.slider.value() / 100.0, self.mask_size.slider.value() / 100.0,
            self.mask_soft.slider.value() / 100.0, self.mask_invert.isChecked())
        self._store_mask(layer, mask, "radial", "Radial mask")

    def _apply_drawn_gradient(self, start_x, start_y, end_x, end_y):
        layer = self._mask_layer()
        if layer is None:
            return
        mask = masks.drawn_linear_mask(
            self._mask_target_size(), start_x, start_y, end_x, end_y)
        hiding = self.gradient_hide.isChecked()
        if not hiding:
            mask = ImageOps.invert(mask)
        existing_kind = str(layer.get("mask_kind", ""))
        if layer.get("mask"):
            existing = editor_engine._mask_from_text(layer["mask"])
            if existing.size != mask.size:
                existing = existing.resize(mask.size, Image.Resampling.LANCZOS)
            mask = (ImageChops.darker(existing, mask) if hiding
                    else ImageChops.lighter(existing, mask))
        kind = "colour+linear" if "colour" in existing_kind else "linear"
        label = "Hide with gradient" if hiding else "Reveal with gradient"
        self._store_mask(layer, mask, kind, label)
        self.status.showMessage("Gradient added — drag another edge or Ctrl+Z to undo.")

    def _seed_brush(self):
        layer = self._mask_layer()
        if layer is None:
            return
        photo = self.doc.render((476, 344))   # reference view for painting
        existing = None
        if layer.get("mask"):
            existing = editor_engine._mask_from_text(layer.get("mask", ""))
        self.mask_brush.load(photo, existing)

    def _toggle_canvas_mask_paint(self, checked):
        layer = self._mask_layer()
        if checked and layer is None:
            self.paint_on_photo.setChecked(False)
            return
        if checked:
            size = self._mask_target_size()
            self._canvas_mask = (editor_engine._mask_from_text(layer.get("mask", ""))
                                 if layer.get("mask") else Image.new("L", size, 255))
            if self._canvas_mask.size != size:
                self._canvas_mask = self._canvas_mask.resize(size, Image.Resampling.LANCZOS)
            if not layer.get("mask"):
                layer["mask"] = editor_engine._mask_to_text(self._canvas_mask)
                layer["mask_enabled"] = True
                layer["mask_kind"] = "brush"
                self.layers_panel.rebuild()
            self._canvas_mask_last = None
            self.view.set_mask_paint_mode(True)
            self.status.showMessage(
                "Painting layer mask on photo — Hide paints black; Reveal paints white.")
        else:
            self.view.set_mask_paint_mode(False)
            self._canvas_mask_last = None

    def _paint_canvas_mask(self, x, y, finished):
        if getattr(self, "_canvas_mask", None) is None or self._mask_layer() is None:
            return
        width, height = self._canvas_mask.size
        point = (round(float(x) * (width - 1)), round(float(y) * (height - 1)))
        radius = max(2, round(self.brush_size.slider.value() / 172 * min(width, height)))
        alpha_value = round(255 * self.brush_opacity.slider.value() / 100 *
                            self.brush_flow.slider.value() / 100)
        dab = Image.new("L", (width, height), 0)
        draw = ImageDraw.Draw(dab)
        previous = getattr(self, "_canvas_mask_last", None) or point
        draw.line((previous, point), fill=alpha_value, width=radius * 2)
        draw.ellipse((point[0] - radius, point[1] - radius,
                      point[0] + radius, point[1] + radius), fill=alpha_value)
        softness = 1.0 - self.brush_hardness.slider.value() / 100.0
        if softness:
            dab = dab.filter(ImageFilter.GaussianBlur(max(.5, radius * softness * .55)))
        value = 255 if self.mask_brush._paint_white else 0
        self._canvas_mask.paste(value, mask=dab)
        self._canvas_mask_last = None if finished else point
        layer = self._mask_layer()
        display_mask = (ImageOps.invert(self._canvas_mask)
                        if self.mask_invert.isChecked() else self._canvas_mask)
        layer["mask"] = editor_engine._mask_to_text(display_mask)
        layer["mask_enabled"] = True
        layer["mask_kind"] = "brush"
        self.layers_panel.update_mask_thumbnail(layer)
        if finished:
            self.doc.record("Paint mask on photo")
            self.layers_panel.rebuild()
            self._render_preview()
            self._update_title()
        else:
            self._render_timer.start()

    def _store_brush_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        mask = self.mask_brush.mask_pil(self._mask_target_size())
        if mask is None:
            return
        if self.mask_invert.isChecked():
            mask = ImageOps.invert(mask)
        self._store_mask(layer, mask, "brush", "Brush mask")

    def _apply_colour_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        visible = layer.get("visible", True)
        layer["visible"] = False
        try:
            photo = self.doc.render(self._mask_target_size())
        finally:
            layer["visible"] = visible
        mask = masks.colour_range_mask(
            photo, self.mask_hue.slider.value(),
            self.mask_hue_range.slider.value(), self.mask_min_sat.slider.value(),
            self.mask_min_lum.slider.value(), self.mask_max_lum.slider.value(),
            self.mask_colour_soft.slider.value(), self.mask_invert.isChecked())
        self._store_mask(layer, mask, "colour", "Colour range mask")

    def _toggle_colour_picker(self, checked):
        if checked and self._mask_layer() is None:
            self.mask_eyedropper.setChecked(False)
            return
        self.view.set_neutral_mode(False)
        self.view.set_colour_range_mode(checked)
        self.status.showMessage(
            "Click a colour in the photograph to seed the range mask."
            if checked else "Colour-range eyedropper off.")

    def _apply_colour_sample(self, x, y, show_dialog=True):
        """Seed hue/saturation/luminance controls from the clicked photograph."""
        layer = self._mask_layer()
        if layer is None:
            return
        visible = layer.get("visible", True)
        layer["visible"] = False
        try:
            photo = self.doc.render((1200, 1200)).convert("RGB")
        finally:
            layer["visible"] = visible
        px = min(photo.width - 1, max(0, round(float(x) * (photo.width - 1))))
        py = min(photo.height - 1, max(0, round(float(y) * (photo.height - 1))))
        red, green, blue = photo.getpixel((px, py))
        hue, saturation, value = colorsys.rgb_to_hsv(
            red / 255.0, green / 255.0, blue / 255.0)
        sampled_hue = round(hue * 359)
        minimum_saturation = max(0, round(saturation * 100) - 20)
        centre_lum = round(value * 100)
        minimum_luminance = max(0, centre_lum - 30)
        maximum_luminance = min(100, centre_lum + 30)
        fuzziness = self.mask_hue_range.slider.value()
        invert = self.mask_invert.isChecked()
        if show_dialog:
            dialog = ColourRangeDialog(photo, sampled_hue, saturation, value, self)
            dialog.fuzziness.set_value(fuzziness)
            dialog.invert.setChecked(invert)
            if dialog.exec() != QDialog.Accepted:
                self.mask_eyedropper.setChecked(False)
                self.status.showMessage("Colour-range selection cancelled.")
                return
            fuzziness = dialog.fuzziness.slider.value()
            minimum_saturation = dialog.minimum_saturation.slider.value()
            minimum_luminance = dialog.minimum_luminance.slider.value()
            maximum_luminance = dialog.maximum_luminance.slider.value()
            invert = dialog.invert.isChecked()
        self.mask_hue.set_value(sampled_hue)
        # A sampled colour seeds useful protections but remains editable.
        self.mask_hue_range.set_value(fuzziness)
        self.mask_min_sat.set_value(minimum_saturation)
        self.mask_min_lum.set_value(minimum_luminance)
        self.mask_max_lum.set_value(maximum_luminance)
        self.mask_invert.setChecked(invert)
        self.mask_eyedropper.setChecked(False)
        self._apply_colour_mask()
        self.status.showMessage(
            f"Sampled colour range at H {round(hue * 359)}°, "
            f"S {round(saturation * 100)}%, L {centre_lum}%.")

    def _store_mask(self, layer, mask, kind, label):
        layer["mask"] = editor_engine._mask_to_text(mask)
        layer["mask_enabled"] = True
        layer["mask_kind"] = kind
        layer.pop("selection_source_mask", None)
        for key in ("selection_grow", "selection_smooth", "selection_feather"):
            layer[key] = 0
        self.doc.record(label)
        self.layers_panel.rebuild()
        self._render_preview()
        self._update_title()

    def _reapply_mask(self, *_args):
        # regenerate the currently-chosen mask kind (slider/invert changed)
        if self._mask_kind == "radial":
            self._apply_radial_mask()
        elif self._mask_kind == "brush":
            self._store_brush_mask()
        elif self._mask_kind == "colour":
            self._apply_colour_mask()

    def _clear_mask(self):
        layer = self._mask_layer()
        if layer is None or not layer.get("mask"):
            return
        layer["mask"] = ""
        self.doc.record("Clear mask")
        if self._mask_kind == "brush":
            self._seed_brush()
        self._render_preview()
        self._update_title()

    def _build_text_panel(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(12, 4, 12, 6)
        layout.setSpacing(6)

        self.text_edit = QLineEdit()
        self.text_edit.setPlaceholderText("Text…")
        self.text_edit.textEdited.connect(self._on_text_changed)
        self.text_edit.editingFinished.connect(lambda: self._commit_text("Edit text"))
        layout.addWidget(self.text_edit)

        self.text_size_row = SliderRow("font_size", "Size", 8, 400, 1, 72)
        self.text_size_row.changed.connect(self._on_text_size)
        self.text_size_row.committed.connect(lambda _k: self._commit_text("Text size"))
        layout.addWidget(self.text_size_row)

        colour_row = QHBoxLayout()
        colour_row.setContentsMargins(0, 0, 0, 0)
        colour_row.setSpacing(8)
        label = QLabel("Colour")
        label.setObjectName("ControlName")
        label.setFixedWidth(52)
        colour_row.addWidget(label)
        self.text_colour_btn = QPushButton("Choose…")
        self.text_colour_btn.setObjectName("LayerAddBtn")
        self.text_colour_btn.setCursor(Qt.PointingHandCursor)
        self.text_colour_btn.clicked.connect(self._pick_text_colour)
        colour_row.addWidget(self.text_colour_btn, 1)
        layout.addLayout(colour_row)
        return wrap

    def _text_layer(self):
        if not self.doc or self.active_target == BASE:
            return None
        for layer in self.doc.layers:
            if layer.get("id") == self.active_target and layer.get("type") == "text":
                return layer
        return None

    def _update_text_panel(self):
        # the mask panel applies to any selected layer
        self.mask_section.setVisible(self.doc is not None and self.active_target != BASE)
        layer = self._text_layer()
        self.text_section.setVisible(layer is not None)
        if not layer:
            return
        self.text_edit.blockSignals(True)
        self.text_edit.setText(layer.get("text", ""))
        self.text_edit.blockSignals(False)
        self.text_size_row.set_value(layer.get("font_size", 72))
        fill = layer.get("fill", [255, 255, 255, 255])
        self._set_colour_swatch(fill)

    def _set_colour_swatch(self, fill):
        colour = QColor(*[int(c) for c in fill[:3]])
        text = "#000" if colour.lightness() > 140 else "#fff"
        self.text_colour_btn.setStyleSheet(
            f"background:{colour.name()};color:{text};border:1px solid {theme.BORDER};"
            "border-radius:5px;padding:5px 6px;")

    def _on_text_changed(self, value):
        layer = self._text_layer()
        if layer is not None:
            layer["text"] = value
            self._schedule_render()

    def _on_text_size(self, _key, value):
        layer = self._text_layer()
        if layer is not None:
            layer["font_size"] = int(value)
            self._schedule_render()

    def _commit_text(self, label):
        if self._text_layer() is not None:
            self.doc.record(label)
            self._update_title()

    def _pick_text_colour(self):
        layer = self._text_layer()
        if layer is None:
            return
        fill = layer.get("fill", [255, 255, 255, 255])
        chosen = QColorDialog.getColor(QColor(*[int(c) for c in fill[:3]]), self,
                                       "Text colour")
        if chosen.isValid():
            layer["fill"] = [chosen.red(), chosen.green(), chosen.blue(),
                             fill[3] if len(fill) > 3 else 255]
            self._set_colour_swatch(layer["fill"])
            self.doc.record("Text colour")
            self._render_preview()
            self._update_title()

    def _build_retouch(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(0, 2, 0, 4)
        layout.setSpacing(4)

        hint = QLabel("Choose Spot Heal or Red-Eye, then click blemishes on the photograph.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        layout.addWidget(hint)

        tool_buttons = QHBoxLayout()
        tool_buttons.setContentsMargins(12, 2, 12, 2)
        self.retouch_heal_btn = QPushButton("Spot Heal")
        self.retouch_redeye_btn = QPushButton("Red-Eye")
        self.retouch_clone_btn = QPushButton("Clone Stamp")
        self.retouch_patch_btn = QPushButton("Patch")
        for button, action in ((self.retouch_heal_btn, self.act_heal),
                               (self.retouch_redeye_btn, self.act_redeye),
                               (self.retouch_clone_btn, self.act_clone),
                               (self.retouch_patch_btn, self.act_patch)):
            button.setCheckable(True)
            button.setCursor(Qt.PointingHandCursor)
            button.toggled.connect(action.setChecked)
            action.toggled.connect(button.setChecked)
            action.changed.connect(
                lambda target=button, source=action: target.setEnabled(source.isEnabled()))
            tool_buttons.addWidget(button)
        layout.addLayout(tool_buttons)

        ai_buttons = QHBoxLayout()
        ai_buttons.setContentsMargins(12, 2, 12, 2)
        self.retouch_ai_heal_btn = QPushButton("AI Heal…")
        self.retouch_ai_fill_btn = QPushButton("Generative Fill…")
        for button, action in ((self.retouch_ai_heal_btn, self.act_ai_heal),
                               (self.retouch_ai_fill_btn, self.act_ai_fill)):
            button.clicked.connect(action.trigger)
            action.changed.connect(
                lambda target=button, source=action: target.setEnabled(source.isEnabled()))
            ai_buttons.addWidget(button)
        layout.addLayout(ai_buttons)

        self.retouch_size_row = SliderRow("spot", "Spot size", 1, 15, 0.5, 3.5)
        self.retouch_size_row.changed.connect(
            lambda _k, v: setattr(self, "_retouch_radius", v / 100.0))
        layout.addWidget(self.retouch_size_row)

        buttons = QHBoxLayout()
        buttons.setContentsMargins(12, 2, 12, 2)
        clear = QPushButton("Clear all retouch")
        clear.setObjectName("LayerDeleteBtn")
        clear.setCursor(Qt.PointingHandCursor)
        clear.clicked.connect(self._clear_retouch)
        buttons.addWidget(clear)
        layout.addLayout(buttons)
        return wrap

    def _toggle_retouch(self, kind, on):
        if not self.doc:
            {"heal": self.act_heal, "red_eye": self.act_redeye,
             "clone": self.act_clone, "patch": self.act_patch}[kind].setChecked(False)
            return
        if on:
            self._retouch_type = kind
            self._clone_source = None
            for other in (self.act_heal, self.act_redeye, self.act_clone, self.act_patch):
                if other is not {"heal": self.act_heal, "red_eye": self.act_redeye,
                                 "clone": self.act_clone, "patch": self.act_patch}[kind] and other.isChecked():
                    other.setChecked(False)
            if self.act_crop.isChecked():
                self.act_crop.setChecked(False)
            self.view.set_retouch_mode(True)
            message = ("Click a clean source area, then click the destination" if
                       kind in {"clone", "patch"} else "Click blemishes; toggle off when done")
            self.status.showMessage(f"{kind.replace('_', ' ').title()} — {message}")
        elif not any(action.isChecked() for action in
                     (self.act_heal, self.act_redeye, self.act_clone, self.act_patch)):
            self.view.set_retouch_mode(False)

    def _add_retouch(self, nx, ny):
        if not self.doc:
            return
        if self._retouch_type in {"clone", "patch"} and self._clone_source is None:
            self._clone_source = (round(nx, 5), round(ny, 5))
            self.status.showMessage("Source selected — now click the area to repair")
            return
        spot = {"x": round(nx, 5), "y": round(ny, 5),
                "radius": self._retouch_radius, "type": self._retouch_type}
        if self._retouch_type in {"clone", "patch"}:
            spot["source_x"], spot["source_y"] = self._clone_source
            self._clone_source = None
        self.doc.retouched.append(spot)
        self.doc.record("Retouch")
        self._render_preview()
        self._update_title()

    def _clear_retouch(self):
        if not self.doc or not self.doc.retouched:
            return
        self.doc.retouched = []
        self.doc.record("Clear retouch")
        self._render_preview()
        self._update_title()

    def _toggle_crop(self, checked):
        if not self.doc:
            self.act_crop.setChecked(False)
            return
        if checked:
            # Crop is a single editing session.  Toolbar/menu re-entry (or a
            # duplicated signal in a packaged build) must never construct a
            # second overlay on top of the active one.
            if self.view._crop_mode:
                self.status.showMessage(
                    "Crop is already open — use Apply Crop or Cancel.")
                self._show_toolbar_context("edit")
                return
            for act in (self.act_heal, self.act_redeye, self.act_clone, self.act_patch):
                if act.isChecked():
                    act.setChecked(False)
            if self.free_perspective_btn.isChecked():
                self.free_perspective_btn.setChecked(False)
            else:
                # Also clear an orphaned overlay from an older or interrupted
                # tool state even if its button has already lost check state.
                self.view.set_perspective_mode(False)
            if self.horizon_draw_btn.isChecked():
                self.horizon_draw_btn.setChecked(False)
            self.view.set_retouch_mode(False)
            self._saved_crop = self.doc.geometry.get("crop")
            self.doc.geometry["crop"] = None      # show the full frame to crop on
            self._render_preview(keep_view=False)
            self.view.set_crop_mode(True, self._saved_crop)
            self._on_crop_aspect(self.crop_aspect.currentIndex())
            self.status.showMessage(
                "Crop — drag handles to resize, drag inside to move, then Apply Crop.")
        else:
            self.view.set_crop_mode(False)
            if self.doc.geometry.get("crop") is None and self._saved_crop is not None:
                self.doc.geometry["crop"] = self._saved_crop   # cancelled — restore
            if not self._crop_commit_in_progress:
                self._render_preview(keep_view=False)
        self._show_toolbar_context("edit")

    def _on_crop_aspect(self, index):
        if not hasattr(self, "view"):
            return
        value = self.crop_aspect.itemData(index)
        if value == "original":
            pixmap = self.view._item.pixmap()
            value = (pixmap.width() / pixmap.height()
                     if pixmap and pixmap.height() else None)
        self.view.set_crop_aspect(value)
        # The aspect selector otherwise keeps keyboard focus and consumes arrow
        # keys. Return focus to the canvas for immediate crop-frame nudging.
        if self.view._crop_mode:
            self.view.setFocus(Qt.OtherFocusReason)

    def _commit_crop(self):
        rect = self.view.crop_rect_normalized()
        if rect:
            self._apply_crop(*rect)

    def _cancel_crop(self):
        if self.act_crop.isChecked():
            self.act_crop.setChecked(False)

    def _apply_crop(self, left, top, right, bottom):
        if not self.doc:
            return
        # Give Apply immediate, deterministic feedback while a definitive RAW
        # development runs in the background.  This is the exact crop of the
        # frame the photographer was looking at when they pressed Apply.
        proxy = None
        if self._last_rendered is not None:
            width, height = self._last_rendered.size
            box = (max(0, min(width - 1, round(left * width))),
                   max(0, min(height - 1, round(top * height))),
                   max(1, min(width, round(right * width))),
                   max(1, min(height, round(bottom * height))))
            if box[2] > box[0] and box[3] > box[1]:
                proxy = self._last_rendered.crop(box)
        self.doc.geometry["crop"] = [round(left, 5), round(top, 5),
                                     round(right, 5), round(bottom, 5)]
        # Remove the editing furniture before record() refreshes actions and
        # rebuilds the contextual toolbar. In packaged builds that refresh can
        # otherwise detach the Apply widget before the action's toggled signal
        # finishes, leaving the crop border painted over the committed image.
        self.view.set_crop_mode(False)
        self._crop_commit_in_progress = True
        try:
            if self.act_crop.isChecked():
                self.act_crop.setChecked(False)
        finally:
            self._crop_commit_in_progress = False
        # Record first.  Rendering before record() advances the document again,
        # which makes the eventual RAW result look stale and silently rejects
        # it — the reason Apply Crop appeared to do nothing on RAW files.
        self.doc.record("Crop")
        if proxy is not None:
            self._show_rendered(proxy, keep_view=False)
        self._render_preview(keep_view=False)
        self._update_title()

    def _sync_geometry(self):
        if not self.doc:
            return
        self.rotation_row.set_value(self.doc.geometry.get("rotation", 0.0))
        self.perspective_v_row.set_value(
            self.doc.geometry.get("perspective_vertical", 0.0))
        self.perspective_h_row.set_value(
            self.doc.geometry.get("perspective_horizontal", 0.0))
        edge_index = self.perspective_edges.findData(
            self.doc.geometry.get("perspective_edges", "auto_crop"))
        self.perspective_edges.blockSignals(True)
        self.perspective_edges.setCurrentIndex(max(0, edge_index))
        self.perspective_edges.blockSignals(False)
        self.view.set_perspective_corners(self.doc.geometry.get(
            "perspective_corners", [[0, 0], [1, 0], [1, 1], [0, 1]]))
        for row in self._lens_rows:
            row.set_value(self.doc.geometry.get(row.key, 100.0 if row.key == "lens_scale" else 0.0))
        self.horizon_strength_row.set_value(self.doc.geometry.get("horizon_strength", 100.0))
        self.horizon_protection_row.set_value(
            self.doc.geometry.get("horizon_edge_protection", 60.0))
        lens_edge_index = self.lens_edges.findData(
            self.doc.geometry.get("lens_edges", "auto_crop"))
        self.lens_edges.blockSignals(True)
        self.lens_edges.setCurrentIndex(max(0, lens_edge_index))
        self.lens_edges.blockSignals(False)
        self.flip_h_btn.setChecked(bool(self.doc.geometry.get("flip_x", False)))
        self.flip_v_btn.setChecked(bool(self.doc.geometry.get("flip_y", False)))

    def _set_hist_mode(self, mode):
        self._hist_mode = mode
        self.histogram.set_mode(mode)
        self._refresh_histogram()

    def _refresh_histogram(self, rendered=None):
        if not self.doc:
            return
        if rendered is None:
            data = self.doc.histogram()
        else:
            # Reuse the canvas render.  Rendering the document again just for
            # the live histogram doubled every slider update, including every
            # layer, mask and filter in the stack.
            sample = rendered.convert("RGB")
            sample.thumbnail((512, 512))
            red, green, blue = sample.split()
            data = {
                "red": red.histogram(),
                "green": green.histogram(),
                "blue": blue.histogram(),
                "luminance": ImageOps.grayscale(sample).histogram(),
            }
        self.histogram.set_data(data, self._hist_mode)

    # --- Edit target (base vs a layer) --------------------------------------
    def active_adjustments(self):
        """The adjustments dict the rail currently edits."""
        if not self.doc:
            return None
        if self.active_target == BASE:
            return self.doc.adjustments
        for layer in self.doc.layers:
            if layer.get("id") == self.active_target:
                return layer.setdefault("adjustments",
                                        editor_engine.copy.deepcopy(
                                            editor_engine.DEFAULT_ADJUSTMENTS))
        return self.doc.adjustments

    def _active_name(self):
        if self.active_target == BASE:
            return "Base image"
        for layer in (self.doc.layers if self.doc else []):
            if layer.get("id") == self.active_target:
                return layer.get("name", "Layer")
        return "Base image"

    def _editing_target_text(self):
        suffix = " · RAW via RawTherapee" if (self.doc and
                 getattr(self.doc, "raw_source_path", "")) else ""
        return f"Editing: {self._active_name()}{suffix}"

    # --- Host interface used by LayersPanel ---------------------------------
    def open_mask_panel(self):
        """Expand the selected layer's mask editor and bring it on screen."""
        if self._mask_layer() is None:
            self.status.showMessage("Select or add a layer before editing its mask.")
            return False
        self.mask_section.setVisible(True)
        self.mask_section.header.setChecked(True)
        # Geometry is recalculated after expansion, so scroll on the next event
        # turn. Merely making this top-of-rail widget visible left the user at
        # the Layers/History end of the rail with no apparent response.
        QTimer.singleShot(
            0, lambda: self.rail_scroll.ensureWidgetVisible(
                self.mask_section, 0, 12))
        return True

    def set_target(self, target):
        self._draft_base = None
        self._draft_origin = None
        self.active_target = target
        self.target_label.setText(self._editing_target_text())
        # Layer navigation changes the adjustment target, not document
        # geometry. Re-syncing every perspective/lens/horizon widget on each
        # click caused needless layout and overlay work.
        self._sync_controls_from_doc(include_geometry=False)
        self._update_text_panel()
        layer = self._active_layer()
        if layer and layer.get("type") == "adjustment":
            self.mask_section.header.setChecked(True)
        if layer is None:
            self.view.set_gradient_mode(False)
            self.view.set_colour_range_mode(False)
            self.view.set_mask_paint_mode(False)
            # Selecting Base hides the selected-layer controls and changes the
            # rail height. Keep the layer stack in view; jumping to the top made
            # every delete require a long scroll back down.
            QTimer.singleShot(0, self._scroll_rail_to_layers)
        self._sync_canvas_layer_mode()

    def _scroll_rail_to_layers(self):
        """Keep structural layer work visible after the rail reflows itself."""
        section = self._sections.get("LAYERS")
        if section is None:
            return
        bar = self.rail_scroll.verticalScrollBar()
        bar.setValue(max(bar.minimum(), min(section.y(), bar.maximum())))

    def _active_layer(self):
        if not self.doc or self.active_target == BASE:
            return None
        return next((layer for layer in self.doc.layers
                     if layer.get("id") == self.active_target), None)

    def _sync_canvas_layer_mode(self):
        layer = self._active_layer()
        movable = bool(layer and (
            (layer.get("type") in {"image", "text"} and
             layer.get("fit", "original") == "original") or
            (layer.get("type") == "filter" and
             layer.get("filter_type") == "light_leak")))
        self.view.set_layer_move_mode(movable)

    def _move_active_layer(self, dx, dy, finished):
        layer = self._active_layer()
        if layer is None:
            return
        if not finished and (dx or dy):
            if layer.get("type") == "filter" and \
                    layer.get("filter_type") == "light_leak":
                settings = layer.setdefault("settings", {})
                edge = settings.get("edge", "left")
                delta = dy if edge in {"left", "right"} else dx
                settings["position"] = max(
                    0.0, min(100.0, float(settings.get("position", 25)) + delta * 100))
            elif layer.get("type") in {"image", "text"}:
                transform = layer.setdefault("transform", self.doc.default_transform())
                transform["x"] = max(-1.0, min(2.0, float(transform.get("x", .5)) + dx))
                transform["y"] = max(-1.0, min(2.0, float(transform.get("y", .5)) + dy))
            else:
                return
            self._layer_drag_changed = True
            self._schedule_render()
        elif finished and self._layer_drag_changed:
            self._layer_drag_changed = False
            self.doc.record("Move layer")
            self._update_title()

    def begin_interactive_render(self):
        """Use cancellable viewport proxies while a continuous control moves."""
        self._interactive_render = True

    def request_render(self, interactive=False):
        if interactive:
            self._interactive_render = True
            self._schedule_render()
        else:
            self._render_preview()

    def finish_interactive_render(self):
        """Replace the last quick proxy with one definitive quality render."""
        if not self.doc:
            return
        self._render_timer.stop()
        self._preview_generation += 1
        self._interactive_render = False
        self._render_preview(keep_view=True)

    def update_title(self):
        self._update_title()

    def after_structure_change(self):
        self.layers_panel.rebuild()
        self.target_label.setText(self._editing_target_text())
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._sync_canvas_layer_mode()
        self._render_preview()
        self._update_title()

    # --- Document lifecycle -------------------------------------------------
    def open_path(self, path):
        """Begin opening a specific image without blocking the event loop."""
        original_path = os.path.abspath(path)
        self._start_open_job(
            original_path, "image", recovery_paths=self._recovery_paths(original_path))
        return True

    def open_image(self):
        if not self._confirm_discard():
            return
        from . import prefs
        initial = prefs.load().get("library_folder", "")
        path, _ = QFileDialog.getOpenFileName(
            self, "Open photograph", initial, IMAGE_FILTER)
        if path:
            self.open_path(path)

    def open_project(self):
        if not self._confirm_discard():
            return
        from . import prefs
        initial = prefs.load().get("projects_folder", "")
        path, _ = QFileDialog.getOpenFileName(
            self, "Open SNAP SLAPPER project", initial, PROJECT_FILTER)
        if not path:
            return
        self.open_project_path(path)

    def open_project_path(self, path):
        """Begin opening a project without extraction or decode on the UI thread."""
        self._start_open_job(os.path.abspath(path), "project")
        return True

    def _start_open_job(self, path, mode, recovery_paths=(), trust_external=False):
        self._open_generation += 1
        token = self._open_generation
        job = _OpenJob(token, path, mode, recovery_paths, trust_external)
        self._open_jobs.add(job)
        job.signals.ready.connect(
            lambda generation, payload, current=job:
            self._accept_open_job(generation, payload, current))
        job.signals.approval.connect(
            lambda generation, project, source, current=job:
            self._approve_external_project(generation, project, source, current))
        job.signals.failed.connect(
            lambda generation, message, current=job:
            self._reject_open_job(generation, message, current))
        self.status.showMessage(
            "Opening project…" if mode == "project" else
            ("Developing RAW photograph…" if
             os.path.splitext(path)[1].lower() in photo_manager.RAW_EXTENSIONS else
             "Opening photograph…"))
        self._open_pool.start(job)

    def _accept_open_job(self, generation, payload, job):
        self._open_jobs.discard(job)
        if generation != self._open_generation:
            return
        document, source_colour, opened_path, kind, initial_preview = payload
        if kind == "project" and not self._resolve_texture_assets(document):
            return
        self._adopt_document(document, source_colour, opened_path, kind,
                             initial_preview)

    def _approve_external_project(self, generation, project, source, job):
        self._open_jobs.discard(job)
        if generation != self._open_generation:
            return
        answer = QMessageBox.question(
            self, "Confirm external files",
            "This project references files outside the project archive:\n\n"
            f"{source}\n\nOpen and process these files?",
            QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
        if answer == QMessageBox.Yes:
            self._start_open_job(project, "project", trust_external=True)
        else:
            self.status.showMessage("Project opening cancelled")

    def _reject_open_job(self, generation, message, job):
        self._open_jobs.discard(job)
        if generation != self._open_generation:
            return
        self._error("Cannot open", message)

    def _adopt_document(self, document, source_colour, opened_path, kind,
                        initial_preview=None):
        self.doc = document
        self.source_colour = source_colour
        is_raw = kind == "raw" or bool(getattr(document, "raw_source_path", ""))
        self.colour_status.setText(workspace_label(
            source_colour, raw_development=is_raw))
        if source_colour.get("icc_profile"):
            self.colour_status.setToolTip(
                "Embedded ICC detected and preserved for export. Edits use the float32 "
                "compositor; only the screen proxy is 8-bit.")
        else:
            self.colour_status.setToolTip(
                "This source has no embedded ICC profile. It is being interpreted as sRGB. "
                "Current processing: float32; only the screen proxy is 8-bit.")
        self.doc.on_change = self._on_doc_change
        self.doc.history_limit_handler = self._history_checkpoint
        self.active_target = BASE
        self._zoom_actual = False   # a freshly opened project starts fitted
        self.layers_panel.rebuild()
        self.target_label.setText(self._editing_target_text())
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._sync_canvas_layer_mode()
        if initial_preview is not None:
            self._show_rendered(initial_preview, keep_view=False,
                                max_size=(1600, 1600))
        else:
            self._render_preview(keep_view=False)
        self._update_title()
        self._refresh_filmstrip()
        self.status.showMessage(os.path.basename(opened_path))

    def save_project(self):
        self._save_project_interactive(force_dialog=True)

    def _save_project_interactive(self, force_dialog=False):
        if not self.doc:
            return False
        base = os.path.splitext(os.path.basename(self.doc.source_path))[0]
        path = self.doc.project_path if not force_dialog else ""
        if not path:
            from . import prefs
            project_dir = prefs.load().get("projects_folder", "")
            suggested = (os.path.join(project_dir, f"{base}.slapper")
                         if project_dir else f"{base}.slapper")
            path, _ = QFileDialog.getSaveFileName(
                self, "Save project", suggested, PROJECT_FILTER)
            if not path:
                return False
        try:
            self.doc.save_project(path)
        except Exception as error:  # noqa: BLE001
            self._error("Save failed", str(error))
            return False
        # A named project is an additional copy, not a reason to forget how the
        # original photograph was last being edited.
        self._write_recovery(force=True)
        self._update_title()
        self.status.showMessage(f"Saved {os.path.basename(path)}")
        return True

    def _history_checkpoint(self, _document):
        answer = QMessageBox.question(
            self, "Save before continuing",
            "This photograph has reached 100 editing steps. SNAP SLAPPER will not "
            "discard the oldest history.\n\nSave the project and continue with a new "
            "history segment?",
            QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Yes)
        if answer != QMessageBox.Yes:
            self.status.showMessage("Edit cancelled — history was not discarded", 7000)
            return False
        return self._save_project_interactive(force_dialog=False)

    def open_help(self):
        from .help_dialog import HelpDialog
        HelpDialog(self).show()

    def open_preferences(self):
        from .prefs_dialog import PreferencesDialog
        PreferencesDialog(self).exec()

    def open_hdr(self):
        """Open SNAP SLAPPER's dedicated external HDR workflow."""
        from .hdr_dialog import HdrDialog
        dialog = HdrDialog(self)
        dialog.completed.connect(self._open_hdr_result)
        dialog.exec()

    def _open_hdr_result(self, path):
        if self._confirm_discard():
            self.open_path(path)

    def open_external_edit(self):
        if not self.doc:
            QMessageBox.information(self, "External Edit", "Open a photograph first.")
            return
        import hashlib
        import time
        import snap_home
        from .external_edit import ExternalEditDialog
        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "external_edits")
        os.makedirs(folder, exist_ok=True)
        stem = os.path.splitext(self.doc.original_filename)[0]
        token = hashlib.sha256(
            f"{self.doc.source_path}|{time.time_ns()}".encode("utf-8")).hexdigest()[:10]
        path = os.path.join(folder, f"{stem}-external-{token}-16bit.tif")
        try:
            self.status.showMessage("Creating 16-bit external edit copy…")
            self.doc.export(path)
        except Exception as error:  # noqa: BLE001
            self._error("External Edit", str(error))
            return
        dialog = ExternalEditDialog(path, self)
        dialog.import_requested.connect(self._import_external_edit)
        dialog.exec()

    def _import_external_edit(self, path, editor_name):
        if not self.doc:
            return
        layer = self.doc.add_image_layer(path, f"{editor_name} return")
        self.set_target(layer["id"])
        self.after_structure_change()

    def open_ai_heal(self):
        if not self.doc:
            QMessageBox.information(self, "Open a photograph", "Open a photograph first.")
            return
        from . import generative_consent
        if not generative_consent.confirm(self):
            return
        from .ai_heal_dialog import AIHealDialog
        AIHealDialog(self).exec()

    def open_ai_fill(self):
        if not self.doc:
            QMessageBox.information(self, "Open a photograph", "Open a photograph first.")
            return
        from .generative_consent import confirm
        if not confirm(self):
            return
        from .ai_heal_dialog import AIHealDialog
        AIHealDialog(self, operation="fill").exec()

    def _open_ai_border_dialog(self):
        if not self.doc:
            QMessageBox.information(self, "Open a photograph", "Open a photograph first.")
            return
        from .generative_consent import confirm
        if not confirm(self):
            return
        from .ai_expand_dialog import AIExpandDialog
        AIExpandDialog(self).exec()

    def generative_expand_budget(self):
        """Return original/current/used/remaining pixels for active history."""
        if not self.doc:
            return 0, 0, 0, 0
        with Image.open(self.doc.source_path) as source:
            original = int(source.width * source.height)
        used = sum(
            max(0, int(layer.get("generated_area_pixels", 0)))
            for layer in self.doc.layers
            if layer.get("type") == "generative_expand"
        )
        remaining = max(0, int(round(original * 0.20)) - used)
        current = original + used
        return original, current, used, remaining

    def apply_ai_generation(self, path, mask, model, instruction="", operation="heal",
                            provider="Google Gemini"):
        """Add the generated frame as a locally enforced masked image layer."""
        # Build the generated layer completely before recording history. An
        # earlier version recorded an unmasked halfway state, so stepping back
        # in History exposed Gemini's entire returned frame.
        is_fill = operation == "fill"
        layer_name = "Generative Fill" if is_fill else "AI Heal"
        input_image = self.render_preview_image((2048, 2048)).convert("RGB")
        with Image.open(path) as generated:
            output_image = generated.convert("RGB")
        layer = self.doc.add_image_layer(path, name=layer_name, record=False)
        layer["fit"] = "stretch"
        import gemini_image_edit
        layer["ai_heal_source_mask"] = editor_engine._mask_to_text(mask)
        layer["ai_heal_feather"] = 100
        layer["mask"] = editor_engine._mask_to_text(
            gemini_image_edit.blend_mask(mask, 1.0))
        layer["mask_enabled"] = True
        layer["mask_linked"] = True
        layer["mask_kind"] = "ai-fill-selection" if is_fill else "ai-heal-selection"
        import slapper_provenance
        operation_class = ("C" if is_fill else
                           slapper_provenance.classify_heal_instruction(instruction))
        layer["provenance"] = slapper_provenance.new_ai_operation(
            operation_class=operation_class,
            tool_name=layer_name,
            purpose="creative fill" if is_fill else "localized restoration",
            provider=provider, model=model, instruction=instruction,
            sent_mask=mask, input_image=input_image, output_image=output_image,
            app_version=BUILD_VERSION,
            scene_invention=is_fill or operation_class == "C")
        # Retain the pre-v0.2 project hint for older SNAP SLAPPER builds. The
        # structured fields above are authoritative for new exports.
        layer["provenance"].update({
            "kind": "generative-fill" if is_fill else "generative-repair"})
        self.doc.record(layer_name)
        self.active_target = layer["id"]
        self.layers_panel.rebuild()
        self.request_render()
        self._update_title()
        self.status.showMessage(
            f"{layer_name} added as a masked layer — the original remains unchanged.")

    # --- Normal / Advanced mode ---------------------------------------------
    def _init_mode(self):
        from . import prefs
        self.mode = prefs.load().get("mode", "advanced")
        self.act_advanced.blockSignals(True)
        self.act_advanced.setChecked(self.mode == "advanced")
        self.act_advanced.blockSignals(False)
        self.mode_combo.blockSignals(True)
        index = self.mode_combo.findData(self.mode)
        self.mode_combo.setCurrentIndex(index if index >= 0 else 1)
        self.mode_combo.blockSignals(False)
        self.apply_mode(self.mode)

    def _on_mode_toggled(self, checked):
        self.apply_mode("advanced" if checked else "normal")
        self.mode_combo.blockSignals(True)
        self.mode_combo.setCurrentIndex(
            self.mode_combo.findData(self.mode))
        self.mode_combo.blockSignals(False)
        from . import prefs
        values = prefs.load()
        values["mode"] = self.mode
        prefs.save(values)

    def _on_mode_combo_changed(self, _index):
        mode = self.mode_combo.currentData() or "normal"
        self.apply_mode(mode)
        self.act_advanced.blockSignals(True)
        self.act_advanced.setChecked(mode == "advanced")
        self.act_advanced.blockSignals(False)
        from . import prefs
        values = prefs.load()
        values["mode"] = self.mode
        prefs.save(values)

    def apply_mode(self, mode):
        advanced = (mode == "advanced")
        self.mode = mode
        for title, section in self._sections.items():
            section.setVisible(advanced or title in NORMAL_SECTIONS)
        # Exposure feedback is useful in the everyday editor too.  Normal mode
        # keeps the histogram while still hiding the specialist controls.
        self._histogram_wrap.setVisible(True)
        for widget in self._bw_mixer_widgets:
            widget.setVisible(advanced)
        # Normal keeps the everyday Effects controls; the grain-specific option
        # belongs with the Advanced-only Grain control.
        self.grain_darken_check.setVisible(advanced)
        self.vignette_quick_btn.setVisible(not advanced)
        self.vignette_blend_combo.setVisible(advanced)
        self.vignette_colour_btn.setVisible(advanced)
        self.vignette_eyedropper.setVisible(advanced)
        for key, row in self.rows.items():
            row.setVisible(advanced or key in NORMAL_ROWS)
        self.target_label.setVisible(advanced)
        for action in self._advanced_actions:
            action.setVisible(advanced)
        self._show_toolbar_context(self._toolbar_context)
        if not advanced:
            # Normal edits the base photo; no layer panels.
            self.active_target = BASE
            self.text_section.setVisible(False)
            self.mask_section.setVisible(False)
            self._sync_controls_from_doc()
            self._sync_canvas_layer_mode()
        self.status.showMessage(
            "Advanced mode" if advanced else "Normal mode — simple editing")

    def auto_enhance(self):
        if not self.doc:
            return
        from PIL import Image, ImageOps
        try:
            with Image.open(self.doc.source_path) as source:
                small = ImageOps.exif_transpose(source).convert("RGB")
            small.thumbnail((400, 400))
            auto = editor_engine.auto_adjustments(small)
        except Exception as error:  # noqa: BLE001
            self._error("SMACK IT UP failed", str(error))
            return
        target = self.active_adjustments()
        if target is None:
            return
        target.update(auto)
        self.doc.record("SMACK IT UP")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()
        self.status.showMessage("SMACKED UP — tweak any slider to taste")

    def auto_exposure(self):
        """Apply only a highlight-safe exposure estimate to the active target."""
        if not self.doc:
            return
        try:
            with Image.open(self.doc.source_path) as source:
                small = ImageOps.exif_transpose(source).convert("RGB")
            small.thumbnail((400, 400))
            auto = editor_engine.auto_exposure_adjustments(small)
        except Exception as error:  # noqa: BLE001
            self._error("Auto exposure failed", str(error))
            return
        target = self.active_adjustments()
        if target is None:
            return
        target.update(auto)
        self.doc.record("Auto exposure")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()
        self.status.showMessage(
            f"Auto exposure: {auto['exposure']:+.2f} EV — highlights protected")

    def _build_neutral_picker(self):
        """Ordinary JPEG white balance: sample a neutral patch on the photo."""
        wrap = QWidget()
        row = QHBoxLayout(wrap)
        row.setContentsMargins(12, 7, 12, 7)
        row.setSpacing(8)
        self.neutral_picker = QPushButton("Pick Neutral Colour…")
        self.neutral_picker.setCheckable(True)
        self.neutral_picker.setCursor(Qt.PointingHandCursor)
        self.neutral_picker.setToolTip(
            "Click, then click a grey card or neutral grey/white area in the photo. "
            "Avoid blown highlights and crushed blacks.")
        self.neutral_picker.toggled.connect(self._toggle_neutral_picker)
        row.addWidget(self.neutral_picker, 1)
        return wrap

    def _toggle_neutral_picker(self, checked):
        if checked and not self.doc:
            self.neutral_picker.blockSignals(True)
            self.neutral_picker.setChecked(False)
            self.neutral_picker.blockSignals(False)
            self._error("Open a photo first",
                        "Open a photograph before choosing a neutral colour.")
            return
        self.view.set_neutral_mode(checked)
        if checked:
            self.status.showMessage(
                "Neutral Picker — click a grey card or neutral grey/white area")
        else:
            self.status.showMessage("Neutral Picker off")

    def _apply_neutral_sample(self, x, y):
        """Balance temperature/tint from the median of a small rendered patch."""
        if not self.doc:
            return
        try:
            image = self.doc.render((1600, 1600)).convert("RGB")
            px = max(0, min(image.width - 1, round(x * (image.width - 1))))
            py = max(0, min(image.height - 1, round(y * (image.height - 1))))
            radius = max(3, round(min(image.size) * 0.006))
            patch = image.crop((max(0, px - radius), max(0, py - radius),
                                min(image.width, px + radius + 1),
                                min(image.height, py + radius + 1)))
            red, green, blue = ImageStat.Stat(patch).median
        except Exception as error:  # noqa: BLE001
            self._error("Neutral sample failed", str(error))
            return

        darkest, brightest = min(red, green, blue), max(red, green, blue)
        if brightest < 18:
            self.status.showMessage(
                "That sample is crushed black — choose a lighter neutral area", 7000)
            return
        if darkest > 247 or brightest >= 254:
            self.status.showMessage(
                "That sample is blown white — choose a neutral area with visible detail",
                7000)
            return

        target = self.active_adjustments()
        if target is None:
            return
        old_temperature = float(target.get("temperature", 0.0))
        old_tint = float(target.get("tint", 0.0))
        temperature = max(-100, min(100,
            old_temperature + (blue - red) * 0.5))
        tint = max(-100, min(100,
            old_tint + (((red + blue) / 2.0) - green) * 0.4))
        target["temperature"] = round(temperature)
        target["tint"] = round(tint)
        self.rows["temperature"].set_value(target["temperature"])
        self.rows["tint"].set_value(target["tint"])
        self.doc.record("Neutral white balance")
        self._render_preview()
        self._update_title()

        self.neutral_picker.blockSignals(True)
        self.neutral_picker.setChecked(False)
        self.neutral_picker.blockSignals(False)
        self.view.set_neutral_mode(False)
        self.status.showMessage(
            f"Neutral balance set from RGB {red:.0f}, {green:.0f}, {blue:.0f} — "
            f"Temperature {target['temperature']:+.0f}, Tint {target['tint']:+.0f}",
            9000)

    def open_lewks(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before applying a LEWK.")
            return
        try:
            from .lewks_dialog import LewksDialog
        except Exception as error:  # noqa: BLE001
            self._error("LEWKS unavailable", str(error))
            return
        LewksDialog(self).show()

    def open_lewk_again(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before building a LEWK.")
            return
        from .lewk_again_dialog import LewkAgainDialog
        LewkAgainDialog(self).show()

    def apply_generated_lewk(self, recipe):
        """Stack a previously validated LEWK AGAIN recipe non-destructively."""
        if not self.doc or not isinstance(recipe, dict):
            return None
        # Revalidate serialized data at the application boundary. This prevents
        # a modified saved response from smuggling unsupported layer content in.
        import lewk_again
        safe = lewk_again.validate_response(
            __import__("json").dumps({
                "name": recipe.get("name"),
                "description": recipe.get("description"),
                "explanation": recipe.get("explanation", []),
                "adjustments": next((layer.get("adjustments", {}) for layer in recipe.get("layers", [])
                                     if layer.get("type") == "adjustment"), {}),
                "filters": [{"type": layer.get("filter_type"), "name": layer.get("name"),
                             "settings": layer.get("settings", {})}
                            for layer in recipe.get("layers", []) if layer.get("type") == "filter"],
            }), recipe.get("provider", ""), recipe.get("model", ""),
            recipe.get("prompt", ""))
        added = self.doc.stack_layers(
            safe["layers"], history_label=f"Apply LEWK — {safe['name']}")
        if added:
            self.set_target(added[-1]["id"])
        self.after_structure_change()
        return added[-1] if added else None

    def apply_lewk(self, lewk_id, strength=100):
        """Apply a built-in LEWK as a non-destructive adjustment layer on top,
        without flattening the photographer's existing edits."""
        if not self.doc:
            return None
        import built_in_lewks
        recipe = built_in_lewks.recipe(lewk_id, strength)
        added = self.doc.stack_layers(recipe.get("layers", []))
        if not self._resolve_texture_assets(self.doc):
            added_ids = {layer.get("id") for layer in added}
            self.doc.layers = [layer for layer in self.doc.layers
                               if layer.get("id") not in added_ids]
            return None
        if added:
            self.set_target(added[-1]["id"])
        self.after_structure_change()
        _log.info("Applied LEWK %s at %s%%", lewk_id, strength)
        return added[-1] if added else None

    def _resolve_texture_assets(self, document):
        """Resolve references, asking before a first-party network restore."""
        import texture_assets
        for position, layer in enumerate(document.layers, 1):
            ref = layer.get("asset_ref")
            if layer.get("type") != "image" or not ref:
                continue
            local = texture_assets.resolve(ref)
            if local:
                layer["path"] = local
                continue
            name = ref.get("name") or layer.get("name") or "Texture"
            if ref.get("origin") != "first-party" or not ref.get("restore_url"):
                self._error(
                    "Texture is missing",
                    f'"{name}" is missing from layer {position}. SNAP SLAPPER cannot '
                    "restore third-party textures automatically.\n\n"
                    f'Source: {ref.get("source_url") or "unknown"}')
                return False
            answer = QMessageBox.question(
                self, "Restore missing texture?",
                f'"{name}" is missing from layer {position}.\n\n'
                "Download it again from FOUND TEXTURES into the shared asset library?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if answer != QMessageBox.Yes:
                self._error("Texture is missing",
                            f'"{name}" is still missing from layer {position}.')
                return False
            try:
                import found_textures
                resolved = found_textures.resolve_profile() or ("", "")
                texture_id = str(ref.get("key", "")).partition(":")[2]
                texture = {
                    "id": texture_id, "title": name,
                    "source_site": resolved[0] or "https://foundtextures.ca",
                    "source_page_url": ref.get("source_url", ""),
                    "highres_download_url": ref.get("restore_url", ""),
                    "full_url": ref.get("restore_url", ""),
                    "rights_status": ref.get("license_status", "unknown"),
                }
                local = found_textures.download(texture, resolved[1])
                layer["path"] = local
                layer["asset_ref"] = texture_assets.register(
                    layer.get("texture") or found_textures.provenance(texture), local)
            except Exception as error:  # noqa: BLE001
                self._error("Texture restore failed",
                            f'Could not restore "{name}" in layer {position}.\n\n{error}')
                return False
        return True

    def render_preview_image(self, max_size=(160, 160)):
        """A small PIL render of the current document — for look previews."""
        if not self.doc:
            return None
        return self.doc.render(max_size=max_size)

    def open_filters(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before adding a filter layer.")
            return
        from .filters_dialog import FiltersDialog
        layer = next((candidate for candidate in self.doc.layers
                      if candidate.get("id") == self.active_target and
                      candidate.get("type") == "filter"), None)
        FiltersDialog(self, layer).exec()

    def open_textures(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before adding a texture layer.")
            return
        try:
            import found_textures
            from .textures_dialog import TexturesDialog
        except Exception as error:  # noqa: BLE001
            self._error("Textures unavailable", str(error))
            return
        resolved = found_textures.resolve_profile()
        if not resolved or not resolved[0]:
            self._error(
                "No Found Textures site",
                "No Found Textures site is set up in The Hub. Add the "
                "foundtextures.ca site (with its API key) in The Hub first.")
            return
        site, key = resolved
        if not key:
            self._error(
                "Found Textures needs discovery",
                "The Found Textures site is present, but it could not issue its "
                "read-only catalogue key. SNAP SLAPPER tried to repair discovery "
                "automatically. If this remains, update Found Textures on the "
                "server, run SNAP HQ discovery once, then open Textures again.")
            return
        TexturesDialog(self, site, key).show()

    def add_texture_layer(self, path, provenance, *, fit="cover",
                          blend="normal", opacity=1.0):
        """Add a downloaded texture as an image layer, with fit + provenance."""
        if not self.doc:
            return None
        layer = self.doc.add_image_layer(path, name=provenance.get("title") or "Texture")
        layer["fit"] = fit
        layer["blend"] = blend
        layer["opacity"] = float(opacity)
        layer["texture"] = dict(provenance)     # preserved in the .slapper project
        try:
            import texture_assets
            layer["asset_ref"] = texture_assets.register(provenance, path)
        except Exception as error:  # noqa: BLE001
            _log.warning("Texture asset could not be indexed: %s", error)
        self.doc.record("Add texture")
        self.set_target(layer["id"])
        self.after_structure_change()
        _log.info("Added texture layer: %s (%s)", provenance.get("title"),
                  provenance.get("source_url"))
        return layer

    def save_recipe(self):
        if not self.doc:
            return
        path, _ = QFileDialog.getSaveFileName(
            self, "Save recipe", "look.slaprecipe", RECIPE_FILTER)
        if not path:
            return
        try:
            editor_engine.save_recipe(path, self.doc.recipe())
        except Exception as error:  # noqa: BLE001
            self._error("Save failed", str(error))
            return
        self.status.showMessage(f"Saved recipe {os.path.basename(path)}")

    def apply_recipe(self):
        if not self.doc:
            return
        path, _ = QFileDialog.getOpenFileName(
            self, "Apply recipe", "", RECIPE_FILTER)
        if not path:
            return
        try:
            self.doc.apply_recipe(editor_engine.load_recipe(path))
        except Exception as error:  # noqa: BLE001
            self._error("Apply failed", str(error))
            return
        self.layers_panel.rebuild()
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()
        self.status.showMessage(f"Applied {os.path.basename(path)}")

    def _on_adjust(self, key, value):
        target = self.active_adjustments()
        if target is None:
            return
        if not self._interactive_render or self._draft_base is None:
            self._draft_base = (self._last_rendered.copy()
                                if self._last_rendered is not None else None)
            self._draft_origin = dict(target)
        target[key] = value
        self._draft_key = key
        self._interactive_render = True
        if (getattr(self.doc, "raw_source_path", "") and
                key.startswith("raw_") and key not in RAW_GEOMETRY_PROXY and
                key != "raw_noise_reduction"):
            # CA, optical vignetting and profile matching have no faithful
            # cheap equivalent. Keep the control responsive and run the one
            # definitive RawTherapee job on release/Enter.
            return
        # During motion, adjust the already-rendered canvas rather than
        # recompositing every layer. The authoritative float32 stack resolves
        # asynchronously on release.
        self._schedule_render()

    def _on_commit(self, key):
        if not self.doc:
            return
        self.doc.record(f"Adjust {key.replace('_', ' ')}")
        # Finish a drag at the normal viewport quality.  Stop a pending cheap
        # render first so it cannot replace the crisp result a moment later.
        self._render_timer.stop()
        self._preview_generation += 1  # invalidate every late proxy frame
        self._interactive_render = False
        self._draft_base = None
        self._draft_origin = None
        self._draft_key = None
        if getattr(self.doc, "raw_source_path", ""):
            # RawTherapee is authoritative, but it must never freeze the UI.
            # Keep the last instant float preview on screen while one final
            # viewport-quality RAW development completes in the worker pool.
            self._dispatch_raw_quality_render()
        else:
            # The definitive viewport render can be expensive with several
            # layers.  Never run it on Qt's event thread: keep the latest live
            # proxy visible and replace it when the worker finishes.
            self._dispatch_quality_render()
        self._update_title()

    def _on_raw_option(self, key, checked):
        if not self.doc or not getattr(self.doc, "raw_source_path", ""):
            return
        self.doc.adjustments[key] = bool(checked)
        self.doc.record(f"Adjust {key.replace('_', ' ')}")
        self._dispatch_raw_quality_render()
        self._update_title()

    def _on_bw(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["black_white"] = bool(checked)
        # A useful photographic starting mix, rather than a lifeless straight
        # desaturation. Warm subject tones lift; blue skies and cool shadows
        # deepen. The eight controls remain fully editable in Advanced mode.
        if checked and not any(float(target.get(key, 0.0)) for key, _deg
                               in editor_engine.BW_BANDS):
            defaults = {
                "bw_red": 10, "bw_orange": 22, "bw_yellow": 14,
                "bw_green": 5, "bw_aqua": -6, "bw_blue": -20,
                "bw_purple": -10, "bw_magenta": 6,
            }
            target.update(defaults)
            for key, value in defaults.items():
                if key in self.rows:
                    self.rows[key].set_value(value)
        self.doc.record("Black and white")
        self._schedule_render()
        self._update_title()

    def _build_split_tone(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(0, 2, 0, 0)
        col.setSpacing(4)
        hint = QLabel("Split tone — colour the shadows and highlights")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        col.addWidget(hint)

        self.split_shadow_btn = QPushButton()
        self.split_shadow_btn.setObjectName("ColourWell")
        self.split_shadow_btn.setFixedSize(28, 24)
        self.split_shadow_btn.setToolTip("Choose shadow colour")
        self.split_shadow_btn.setCursor(Qt.PointingHandCursor)
        self.split_shadow_btn.clicked.connect(
            lambda: self._pick_split_colour("split_shadow"))
        shadow_row = QHBoxLayout()
        shadow_row.addWidget(QLabel("Shadow colour"))
        shadow_row.addStretch(1)
        shadow_row.addWidget(self.split_shadow_btn)
        col.addLayout(shadow_row)
        shadow_amt = SliderRow("split_shadow_amount", "Shadows", 0, 100, 1, 0)
        shadow_amt.changed.connect(self._on_adjust)
        shadow_amt.committed.connect(self._on_commit)
        self.rows["split_shadow_amount"] = shadow_amt
        col.addWidget(shadow_amt)

        self.split_mid_btn = QPushButton()
        self.split_mid_btn.setObjectName("ColourWell")
        self.split_mid_btn.setFixedSize(28, 24)
        self.split_mid_btn.setToolTip("Choose midtone colour")
        self.split_mid_btn.setCursor(Qt.PointingHandCursor)
        self.split_mid_btn.clicked.connect(
            lambda: self._pick_split_colour("split_midtone"))
        mid_row = QHBoxLayout()
        mid_row.addWidget(QLabel("Midtone colour"))
        mid_row.addStretch(1)
        mid_row.addWidget(self.split_mid_btn)
        col.addLayout(mid_row)
        mid_amt = SliderRow("split_midtone_amount", "Midtones", 0, 100, 1, 0)
        mid_amt.changed.connect(self._on_adjust)
        mid_amt.committed.connect(self._on_commit)
        self.rows["split_midtone_amount"] = mid_amt
        col.addWidget(mid_amt)

        self.split_hi_btn = QPushButton()
        self.split_hi_btn.setObjectName("ColourWell")
        self.split_hi_btn.setFixedSize(28, 24)
        self.split_hi_btn.setToolTip("Choose highlight colour")
        self.split_hi_btn.setCursor(Qt.PointingHandCursor)
        self.split_hi_btn.clicked.connect(
            lambda: self._pick_split_colour("split_highlight"))
        hi_row = QHBoxLayout()
        hi_row.addWidget(QLabel("Highlight colour"))
        hi_row.addStretch(1)
        hi_row.addWidget(self.split_hi_btn)
        col.addLayout(hi_row)
        hi_amt = SliderRow("split_highlight_amount", "Highlights", 0, 100, 1, 0)
        hi_amt.changed.connect(self._on_adjust)
        hi_amt.committed.connect(self._on_commit)
        self.rows["split_highlight_amount"] = hi_amt
        col.addWidget(hi_amt)
        return wrap

    def _pick_split_colour(self, key):
        target = self.active_adjustments()
        if target is None:
            return
        current = target.get(key, editor_engine.DEFAULT_ADJUSTMENTS[key])
        colour = QColorDialog.getColor(
            QColor(*[int(c) for c in current]), self, "Choose split-tone colour")
        if not colour.isValid():
            return
        target[key] = [colour.red(), colour.green(), colour.blue()]
        self._update_split_swatches()
        self.doc.record("Split-tone colour")
        self._schedule_render()
        self._update_title()

    def _update_split_swatches(self):
        target = self.active_adjustments() or {}
        for key, btn in (("split_shadow", self.split_shadow_btn),
                        ("split_midtone", self.split_mid_btn),
                        ("split_highlight", self.split_hi_btn),
                        ("glow_colour", self.glow_btn)):
            rgb = target.get(key, editor_engine.DEFAULT_ADJUSTMENTS[key])
            self._set_swatch_icon(btn, rgb)

    @staticmethod
    def _set_swatch_icon(button, rgb):
        """Keep colour visible without turning the whole control into a pastel slab."""
        r, g, b = [int(c) for c in rgb][:3]
        chip = QPixmap(18, 18)
        chip.fill(QColor(r, g, b))
        button.setIcon(QIcon(chip))
        button.setIconSize(QSize(18, 18))
        button.setStyleSheet("")

    def _build_sharpen_detail(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(12, 2, 12, 6)
        col.setSpacing(4)
        hint = QLabel("Sharpen detail — set the Sharpen amount above, then tune "
                      "the edge width, noise guard, and edge model here.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        col.addWidget(hint)

        radius = SliderRow("sharpen_radius", "Radius", 0.1, 6.0, 0.1, 1.2)
        radius.changed.connect(self._on_adjust)
        radius.committed.connect(self._on_commit)
        self.rows["sharpen_radius"] = radius
        col.addWidget(radius)

        reduce_noise = SliderRow("sharpen_reduce_noise", "Protect noise", 0, 100, 1, 0)
        reduce_noise.setToolTip(
            "Prevents Sharpen from amplifying grain; it is not noise reduction and has no effect when Sharpen is 0.")
        reduce_noise.changed.connect(self._on_adjust)
        reduce_noise.committed.connect(self._on_commit)
        self.rows["sharpen_reduce_noise"] = reduce_noise
        col.addWidget(reduce_noise)

        self.sharpen_mode_combo = QComboBox()
        self.sharpen_mode_combo.addItem("Lens Blur — finer, fewer haloes", "lens")
        self.sharpen_mode_combo.addItem("Gaussian — classic unsharp mask", "gaussian")
        self.sharpen_mode_combo.setToolTip(
            "Lens Blur confines sharpening to real edges (like Photoshop's Smart "
            "Sharpen); Gaussian is the plain unsharp mask.")
        self.sharpen_mode_combo.activated.connect(self._on_sharpen_mode)
        col.addWidget(self.sharpen_mode_combo)
        return wrap

    def _on_sharpen_mode(self, index):
        target = self.active_adjustments()
        if target is None:
            return
        target["sharpen_mode"] = self.sharpen_mode_combo.itemData(index) or "lens"
        self.doc.record("Sharpen mode")
        self._schedule_render()
        self._update_title()

    def _build_photo_filter(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(12, 4, 12, 6)
        col.setSpacing(4)
        hint = QLabel("A coloured filter over the lens — warm or cool the photo, "
                      "or wash it with a colour. Pick a preset or your own colour.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        col.addWidget(hint)

        self.photo_filter_combo = QComboBox()
        self.photo_filter_combo.addItem("Custom", None)
        for label, rgb, density in editor_engine.PHOTO_FILTER_PRESETS:
            self.photo_filter_combo.addItem(label, [list(rgb), density])
        self.photo_filter_combo.activated.connect(self._on_photo_filter_preset)
        col.addWidget(self.photo_filter_combo)

        self.photo_filter_btn = QPushButton("Filter colour")
        self.photo_filter_btn.setObjectName("SwatchBtn")
        self.photo_filter_btn.setCursor(Qt.PointingHandCursor)
        self.photo_filter_btn.clicked.connect(self._pick_photo_filter_colour)
        col.addWidget(self.photo_filter_btn)

        density = SliderRow("photo_filter_density", "Density", 0, 100, 1, 0)
        density.changed.connect(self._on_adjust)
        density.committed.connect(self._on_commit)
        self.rows["photo_filter_density"] = density
        col.addWidget(density)

        self.photo_filter_preserve = QCheckBox("Preserve brightness")
        self.photo_filter_preserve.setChecked(True)
        self.photo_filter_preserve.setToolTip(
            "Keep the photo's original brightness and let the filter change only "
            "the colour — the standard photo-filter behaviour.")
        self.photo_filter_preserve.toggled.connect(self._on_photo_filter_preserve)
        col.addWidget(self.photo_filter_preserve)
        return wrap

    def _on_photo_filter_preset(self, index):
        data = self.photo_filter_combo.itemData(index)
        if data is None:      # "Custom" — keep whatever colour is set
            return
        target = self.active_adjustments()
        if target is None:
            return
        rgb, density = data
        target["photo_filter_color"] = [int(c) for c in rgb]
        target["photo_filter_density"] = float(density)
        self.rows["photo_filter_density"].set_value(float(density))
        self._update_photo_filter_swatch()
        self.doc.record("Photo filter")
        self._schedule_render()
        self._update_title()

    def _pick_photo_filter_colour(self):
        target = self.active_adjustments()
        if target is None:
            return
        current = target.get("photo_filter_color",
                             editor_engine.DEFAULT_ADJUSTMENTS["photo_filter_color"])
        colour = QColorDialog.getColor(
            QColor(*[int(c) for c in current[:3]]), self, "Choose filter colour")
        if not colour.isValid():
            return
        target["photo_filter_color"] = [colour.red(), colour.green(), colour.blue()]
        self.photo_filter_combo.setCurrentIndex(0)   # Custom
        self._update_photo_filter_swatch()
        self.doc.record("Photo filter colour")
        self._schedule_render()
        self._update_title()

    def _on_photo_filter_preserve(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["photo_filter_preserve_lum"] = bool(checked)
        self.doc.record("Photo filter")
        self._schedule_render()
        self._update_title()

    def _update_photo_filter_swatch(self):
        target = self.active_adjustments() or {}
        rgb = target.get("photo_filter_color",
                         editor_engine.DEFAULT_ADJUSTMENTS["photo_filter_color"])
        self._set_swatch_icon(self.photo_filter_btn, rgb)

    def _sync_photo_filter_combo(self, adjustments):
        rgb = [int(c) for c in adjustments.get(
            "photo_filter_color",
            editor_engine.DEFAULT_ADJUSTMENTS["photo_filter_color"])[:3]]
        self.photo_filter_combo.blockSignals(True)
        matched = 0     # default to Custom
        for i in range(self.photo_filter_combo.count()):
            data = self.photo_filter_combo.itemData(i)
            if data and [int(c) for c in data[0][:3]] == rgb:
                matched = i
                break
        self.photo_filter_combo.setCurrentIndex(matched)
        self.photo_filter_combo.blockSignals(False)

    def _build_vignette_style(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(12, 4, 12, 6)
        col.setSpacing(4)

        self.vignette_quick_btn = QPushButton("Edge colour…")
        self.vignette_quick_btn.setCursor(Qt.PointingHandCursor)
        self.vignette_quick_btn.setToolTip(
            "Use black, borrow a colour from the photograph, or choose one")
        quick_menu = QMenu(self.vignette_quick_btn)
        quick_menu.addAction("Black", self._use_black_vignette)
        quick_menu.addAction("Pick from photograph", self._start_quick_vignette_picker)
        quick_menu.addAction("Choose colour…", self._pick_vignette_colour)
        self.vignette_quick_btn.setMenu(quick_menu)
        col.addWidget(self.vignette_quick_btn)

        self.vignette_blend_combo = QComboBox()
        for label, mode in (("Normal", "normal"), ("Multiply", "multiply"),
                            ("Soft Light", "soft_light"), ("Overlay", "overlay")):
            self.vignette_blend_combo.addItem(label, mode)
        self.vignette_blend_combo.setToolTip(
            "How the feathered edge colour mixes with the photograph")
        self.vignette_blend_combo.activated.connect(self._on_vignette_blend)
        col.addWidget(self.vignette_blend_combo)

        self.vignette_colour_btn = QPushButton("Vignette colour")
        self.vignette_colour_btn.setObjectName("SwatchBtn")
        self.vignette_colour_btn.setCursor(Qt.PointingHandCursor)
        self.vignette_colour_btn.clicked.connect(self._pick_vignette_colour)
        col.addWidget(self.vignette_colour_btn)

        self.vignette_eyedropper = QPushButton("Pick colour from photograph")
        self.vignette_eyedropper.setCheckable(True)
        self.vignette_eyedropper.setCursor(Qt.PointingHandCursor)
        self.vignette_eyedropper.toggled.connect(self._toggle_vignette_picker)
        col.addWidget(self.vignette_eyedropper)
        return wrap

    def _on_vignette_blend(self, index):
        target = self.active_adjustments()
        if target is None:
            return
        target["vignette_blend"] = self.vignette_blend_combo.itemData(index) or "normal"
        self.doc.record("Vignette blend")
        self._schedule_render()
        self._update_title()

    def _pick_vignette_colour(self):
        target = self.active_adjustments()
        if target is None:
            return
        current = target.get(
            "vignette_color", editor_engine.DEFAULT_ADJUSTMENTS["vignette_color"])
        colour = QColorDialog.getColor(
            QColor(*[int(c) for c in current[:3]]), self, "Choose vignette colour")
        if colour.isValid():
            self._set_vignette_colour([colour.red(), colour.green(), colour.blue()],
                                      "Vignette colour")

    def _use_black_vignette(self):
        target = self.active_adjustments()
        if target is None:
            return
        target["vignette_blend"] = "normal"
        self._set_vignette_colour([0, 0, 0], "Black vignette")

    def _start_quick_vignette_picker(self):
        self.vignette_eyedropper.setChecked(True)

    def _toggle_vignette_picker(self, checked):
        self.view.set_vignette_colour_mode(bool(checked))
        self.status.showMessage(
            "Click a colour in the photograph for the vignette edge"
            if checked else "Vignette colour picker off")

    def _apply_vignette_colour_sample(self, x, y):
        if self._last_rendered is None:
            return
        sample = self._last_rendered.convert("RGB")
        px = max(0, min(sample.width - 1, round(float(x) * (sample.width - 1))))
        py = max(0, min(sample.height - 1, round(float(y) * (sample.height - 1))))
        self._set_vignette_colour(list(sample.getpixel((px, py))),
                                  "Sample vignette colour")
        self.vignette_eyedropper.blockSignals(True)
        self.vignette_eyedropper.setChecked(False)
        self.vignette_eyedropper.blockSignals(False)
        self.view.set_vignette_colour_mode(False)

    def _set_vignette_colour(self, rgb, label):
        target = self.active_adjustments()
        if target is None:
            return
        target["vignette_color"] = [max(0, min(255, int(c))) for c in rgb[:3]]
        if self.mode == "normal" and target["vignette_color"] != [0, 0, 0]:
            target["vignette_blend"] = "soft_light"
        self._update_vignette_controls(target)
        self.doc.record(label)
        self._schedule_render()
        self._update_title()

    def _update_vignette_controls(self, adjustments=None):
        adjustments = adjustments or self.active_adjustments() or {}
        rgb = adjustments.get(
            "vignette_color", editor_engine.DEFAULT_ADJUSTMENTS["vignette_color"])
        self._set_swatch_icon(self.vignette_colour_btn, rgb)
        self._set_swatch_icon(self.vignette_quick_btn, rgb)
        mode = str(adjustments.get("vignette_blend", "normal"))
        index = self.vignette_blend_combo.findData(mode)
        self.vignette_blend_combo.blockSignals(True)
        self.vignette_blend_combo.setCurrentIndex(max(0, index))
        self.vignette_blend_combo.blockSignals(False)

    def _on_grain_darken(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["grain_darken"] = bool(checked)
        self.doc.record("Grain style")
        self._schedule_render()
        self._update_title()

    def _on_curve_changed(self, key, points):
        target = self.active_adjustments()
        if target is None:
            return
        target[key] = points
        self.doc.record("Tone curve")
        self._schedule_render()
        self._update_title()

    def reset_all(self):
        target = self.active_adjustments()
        if target is None:
            return
        for key, value in editor_engine.DEFAULT_ADJUSTMENTS.items():
            target[key] = editor_engine.copy.deepcopy(value)
        self.doc.record("Reset adjustments")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()

    def _validate_target(self):
        if self.active_target == BASE:
            return
        ids = {layer.get("id") for layer in (self.doc.layers if self.doc else [])}
        if self.active_target not in ids:
            self.active_target = BASE
        self.target_label.setText(self._editing_target_text())
        self._sync_canvas_layer_mode()

    def undo(self):
        if self.doc and self.doc.undo():
            self._validate_target()
            self.layers_panel.rebuild()
            self._sync_controls_from_doc()
            self._update_text_panel()
            self._render_preview()
            self._update_title()

    def redo(self):
        if self.doc and self.doc.redo():
            self._validate_target()
            self.layers_panel.rebuild()
            self._sync_controls_from_doc()
            self._update_text_panel()
            self._render_preview()
            self._update_title()

    def export_image(self):
        if not self.doc:
            return
        base = self._document_stem()
        from . import prefs
        settings = prefs.load()
        export_dir = settings.get("exports_folder", "")
        filename = f"{base}.jpg" if self.doc.project_path else f"{base}_edited.jpg"
        suggested = (os.path.join(export_dir, filename)
                     if export_dir else filename)
        path, selected_filter = QFileDialog.getSaveFileName(
            self, "Export copy", suggested,
            "JPEG — flattened (*.jpg);;PNG — flattened (*.png);;"
            "TIFF — flattened (*.tif *.tiff);;"
            "OpenRaster — layered recovery (*.ora);;"
            "Photoshop PSD — layered checkpoints (*.psd)")
        if not path:
            return
        extension = os.path.splitext(path)[1].lower()
        if not extension:
            chosen = ".ora" if "OpenRaster" in selected_filter else \
                ".psd" if "PSD" in selected_filter else \
                ".tif" if "TIFF" in selected_filter else \
                ".png" if "PNG" in selected_filter else ".jpg"
            path += chosen
        copyright_text = (settings["copyright_text"]
                          if settings["add_copyright_if_missing"] else "")
        try:
            export_extension = os.path.splitext(path)[1].lower()
            import slapper_provenance
            preview_size = (self._last_rendered.size if self._last_rendered is not None
                            else (1, 1))
            records = slapper_provenance.export_operations(
                self.doc.layers, preview_size)
            if records and export_extension in {".ora", ".psd"}:
                raise ValueError(
                    "This layered checkpoint format cannot carry the mandatory AI "
                    "provenance record. Export a JPEG, PNG, or TIFF copy instead.")
            if records:
                summary = slapper_provenance.human_summary(records)
                if QMessageBox.question(
                        self, "Review export provenance",
                        summary + "\n\nThis record will travel inside the exported "
                        "photograph and cannot be switched off. Continue?",
                        QMessageBox.Yes | QMessageBox.Cancel,
                        QMessageBox.Yes) != QMessageBox.Yes:
                    return
        except Exception as error:  # noqa: BLE001
            self._error("Export failed", str(error))
            return
        self._export_generation += 1
        token = self._export_generation
        snapshot = self.doc.detached_copy()
        job = _ExportJob(
            token, snapshot, path, int(settings["export_quality"]),
            copyright_text, bool(settings["strip_gps"]))
        self._export_jobs.add(job)
        job.signals.ready.connect(
            lambda generation, output, revision, current=job:
            self._accept_export(generation, output, revision, current))
        job.signals.failed.connect(
            lambda generation, message, current=job:
            self._reject_export(generation, message, current))
        self.status.showMessage("Exporting photograph…")
        self._background_pool.start(job)

    def copy_flattened_jpeg(self):
        if not self.doc:
            return
        from . import prefs
        job = _ClipboardJob(
            self.doc.detached_copy(), int(prefs.load()["export_quality"]))
        self._export_jobs.add(job)
        job.signals.ready.connect(
            lambda payload, current=job: self._accept_clipboard(payload, current))
        job.signals.failed.connect(
            lambda message, current=job: self._reject_clipboard(message, current))
        self.status.showMessage("Rendering flattened JPEG for the clipboard…")
        self._background_pool.start(job)

    def _accept_clipboard(self, payload, job):
        self._export_jobs.discard(job)
        image = QImage.fromData(payload, "JPEG")
        if image.isNull():
            self._error("Copy failed", "The flattened JPEG could not be decoded.")
            return
        mime = QMimeData()
        mime.setImageData(image)
        mime.setData("image/jpeg", QByteArray(payload))
        QApplication.clipboard().setMimeData(mime)
        self.status.showMessage("Flattened JPEG copied — paste it into another window")

    def _reject_clipboard(self, message, job):
        self._export_jobs.discard(job)
        self._error("Copy failed", message)

    def _accept_export(self, generation, path, revision, job):
        self._export_jobs.discard(job)
        if generation != self._export_generation:
            return
        if self.doc and self.doc.revision == revision:
            self.doc.mark_saved()
            self._update_title()
        self.status.showMessage(f"Exported {os.path.basename(path)}")

    def _reject_export(self, generation, message, job):
        self._export_jobs.discard(job)
        if generation == self._export_generation:
            self._error("Export failed", message)

    def prepare_blog_copy(self):
        if not self.doc:
            QMessageBox.information(self, "Blog Copy", "Open a photograph first.")
            return
        try:
            import snap_profiles
            profiles = snap_profiles.list_profiles()
        except Exception as exc:  # noqa: BLE001
            self._error("Blog Copy", f"THE HUB profiles could not be read:\n{exc}")
            return
        if not profiles:
            QMessageBox.information(
                self, "Blog Copy",
                "No shared blog profiles were found. Add the blog in THE HUB first.")
            return
        labels = [f"{p.get('name') or p.get('site_url')} — {p.get('site_url')}"
                  for p in profiles]
        label, accepted = QInputDialog.getItem(
            self, "Prepare Blog Copy", "Blog profile:", labels, 0, False)
        if not accepted:
            return
        profile = profiles[labels.index(label)]
        from . import publishing_contract
        suggested = publishing_contract.suggested_stem(self.doc)
        filename_stem, accepted = QInputDialog.getText(
            self, "Name Blog Copy", "Filename (without extension):", text=suggested)
        if not accepted:
            return
        try:
            filename_stem = publishing_contract.safe_filename_stem(filename_stem)
        except ValueError as exc:
            QMessageBox.warning(self, "Blog Copy", str(exc))
            return
        if filename_stem != getattr(self.doc, "output_title", ""):
            self.doc.output_title = filename_stem
            self.doc.record("Set output title")
        extras = dict(profile.get("extras") or {})
        if str(extras.get("site_mode") or extras.get("gyss_site_mode") or "").lower() == "smackthemup":
            self._publish_to_smackthemup(profile, filename_stem)
            return
        try:
            summary = publishing_contract.describe(profile)
        except ValueError as exc:
            QMessageBox.warning(self, "Blog Copy", str(exc))
            return
        answer = QMessageBox.question(
            self, "Prepare local blog copy?",
            summary + "\n\nThis prepares a local file only. It does not upload or publish.",
            QMessageBox.Yes | QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        from . import prefs
        settings = prefs.load()
        copyright_text = (settings["copyright_text"]
                          if settings["add_copyright_if_missing"] else "")
        self._start_publish_prepare(
            profile, copyright_text, destination="", mode="local",
            filename_stem=filename_stem)

    def _publish_to_smackthemup(self, profile, filename_stem=""):
        """Prepare an upload copy, then use the mode-bound public publisher."""
        import snap_creds
        import snap_home
        from . import prefs, publishing_contract
        from .smackthemup_dialog import SmackPublishDialog

        site = profile.get("site_url", "")
        key = snap_creds.get_site(site, "api_key_smackthemup_publish", "")
        if not key:
            key, accepted = QInputDialog.getText(
                self, "Connect SNAP SLAPPER",
                "Paste this site's SNAP SLAPPER (SMACKTHEMUP — PUBLISH ONLY) key:",
                QLineEdit.Password)
            key = key.strip()
            if not accepted:
                return
            if len(key) != 64:
                QMessageBox.warning(
                    self, "Publishing key refused",
                    "The SMACKTHEMUP publishing key must contain 64 characters.")
                return
            snap_creds.set_site(site, "api_key_smackthemup_publish", key)

        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "publishing")
        os.makedirs(folder, exist_ok=True)
        settings = prefs.load()
        copyright_text = (settings["copyright_text"]
                          if settings["add_copyright_if_missing"] else "")
        upload_profile = dict(profile)
        upload_profile["extras"] = dict(upload_profile.get("extras") or {})
        upload_profile["extras"].update({
            "max_image_width": 2500, "max_image_height": 2500,
            "preferred_quality": 90, "preferred_extension": ".jpg",
            "strip_gps": bool(settings["strip_gps"]),
        })
        self._start_publish_prepare(
            upload_profile, copyright_text, destination=folder, mode="smackthemup",
            filename_stem=filename_stem)

    def _start_publish_prepare(self, profile, copyright_text, destination, mode,
                               filename_stem=""):
        if not self.doc:
            return
        self._publish_generation += 1
        token = self._publish_generation
        snapshot = self.doc.detached_copy()
        job = _PublishPrepareJob(
            token, snapshot, copy.deepcopy(profile), copyright_text,
            destination, mode, filename_stem)
        self._publish_jobs.add(job)
        job.signals.ready.connect(
            lambda generation, payload, current=job:
            self._accept_publish_prepare(generation, payload, current))
        job.signals.failed.connect(
            lambda generation, message, current=job:
            self._reject_publish_prepare(generation, message, current))
        self.status.showMessage(
            "Preparing local blog copy…" if mode == "local" else
            "Preparing photograph for publishing…")
        self._background_pool.start(job)

    def _accept_publish_prepare(self, generation, payload, job):
        self._publish_jobs.discard(job)
        if generation != self._publish_generation:
            return
        mode, profile, target, manifest_path, manifest = payload
        if mode == "local":
            self.status.showMessage(
                f"Prepared {os.path.basename(target)} — ready in the local staging folder",
                9000)
            return
        from .smackthemup_dialog import SmackPublishDialog
        try:
            SmackPublishDialog(self, profile, target, manifest).exec()
        finally:
            for temporary in (target, manifest_path):
                try:
                    os.remove(temporary)
                except OSError:
                    pass

    def _reject_publish_prepare(self, generation, message, job):
        self._publish_jobs.discard(job)
        if generation == self._publish_generation:
            QMessageBox.warning(self, "Publish preparation failed", message)

    # --- Rendering ----------------------------------------------------------
    def _schedule_render(self):
        self._render_timer.start()

    def _dispatch_render(self):
        if not self.doc:
            return
        if not self._interactive_render:
            self._render_preview()
            return
        if self._interactive_job_active:
            # One worker is enough. Remember only that a newer slider state is
            # waiting; otherwise a fast drag queues seconds of obsolete frames.
            self._interactive_render_pending = True
            return
        self._interactive_job_active = True
        self._interactive_render_pending = False
        self._preview_generation += 1
        token = self._preview_generation
        self.doc.revision += 1
        document_revision = self.doc.revision
        state = self.doc.snapshot()
        if self._geometry_preview_mode == "perspective":
            # Auto Crop changes output dimensions for every intermediate
            # keystone value. Keep a fixed canvas until the gesture commits.
            state["geometry"]["perspective_edges"] = "transparent"
        elif (self._geometry_preview_mode == "lens" and
              state["geometry"].get("lens_edges") == "auto_crop"):
            # Keep the editing canvas fixed while the distortion slider moves;
            # calculate and apply the clean crop only when the gesture commits.
            state["geometry"]["lens_edges"] = "transparent"
        raw_source = getattr(self.doc, "raw_source_path", "")
        raw_baseline = (getattr(self.doc, "_raw_developed_adjustments", {})
                        if raw_source else {})
        job = _PreviewJob(
            token, self.doc.source_path, state,
            self.view.viewport_target(interactive=True),
            raw_baseline=raw_baseline, document_revision=document_revision)
        self._preview_jobs.add(job)
        job.signals.ready.connect(
            lambda generation, image, current=job:
            self._accept_proxy(generation, image, current))
        job.signals.failed.connect(
            lambda generation, message, current=job:
            self._reject_proxy(generation, message, current))
        self._preview_pool.start(job)

    def _dispatch_raw_quality_render(self):
        """Run the definitive RawTherapee-backed preview without blocking Qt."""
        if not self.doc or not getattr(self.doc, "raw_source_path", ""):
            return
        self._preview_generation += 1
        token = self._preview_generation
        self.doc.revision += 1
        job = _PreviewJob(
            token, self.doc.source_path, self.doc.snapshot(),
            self.view.viewport_target(), self.doc.raw_source_path,
            document_revision=self.doc.revision)
        self._preview_jobs.add(job)
        job.signals.ready.connect(
            lambda generation, image, current=job:
            self._accept_quality_proxy(generation, image, current))
        job.signals.failed.connect(
            lambda generation, message, current=job:
            self._reject_proxy(generation, message, current))
        self.status.showMessage("Updating RAW development…")
        self._preview_pool.start(job)

    def _dispatch_quality_render(self, keep_view=True):
        """Run a crisp non-RAW viewport render without freezing the window."""
        if not self.doc:
            return
        self._preview_generation += 1
        token = self._preview_generation
        self.doc.revision += 1
        max_size = None if self._zoom_actual else self.view.viewport_target()
        state = self.doc.snapshot()
        if (self.free_perspective_btn.isChecked() and
                self._geometry_preview_mode in {None, "perspective"}):
            # Do not let Auto Crop move the canvas out from underneath the
            # still-active corner handles after every mouse release.
            state["geometry"]["perspective_edges"] = "transparent"
        job = _PreviewJob(
            token, self.doc.source_path, state,
            max_size, document_revision=self.doc.revision)
        self._preview_jobs.add(job)
        job.signals.ready.connect(
            lambda generation, image, current=job:
            self._accept_quality_proxy(generation, image, current, keep_view))
        job.signals.failed.connect(
            lambda generation, message, current=job:
            self._reject_proxy(generation, message, current))
        self.status.showMessage("Finishing preview…")
        self._preview_pool.start(job)

    def _accept_quality_proxy(self, generation, rendered, job, keep_view=True):
        self._preview_jobs.discard(job)
        if (generation != self._preview_generation or self._interactive_render or
                not self.doc or job.document_revision != self.doc.revision):
            return
        self._show_rendered(rendered, keep_view=keep_view,
                            max_size=job.max_size)
        self.status.showMessage(
            "RAW development updated" if getattr(self.doc, "raw_source_path", "")
            else "Preview updated")

    def _accept_proxy(self, generation, rendered, job):
        self._preview_jobs.discard(job)
        self._interactive_job_active = False
        if (generation != self._preview_generation or not self._interactive_render or
                not self.doc or job.document_revision != self.doc.revision):
            self._dispatch_pending_interactive()
            return
        self._show_rendered(rendered, keep_view=True,
                            max_size=self.view.viewport_target(interactive=True),
                            stable_geometry=True)
        self._dispatch_pending_interactive()

    def _reject_proxy(self, generation, message, job):
        self._preview_jobs.discard(job)
        self._interactive_job_active = False
        if generation == self._preview_generation:
            _log.warning("interactive preview failed: %s", message)
        self._dispatch_pending_interactive()

    def _dispatch_pending_interactive(self):
        if self._interactive_render and self._interactive_render_pending:
            self._interactive_render_pending = False
            QTimer.singleShot(0, self._dispatch_render)

    def _queue_fit_resolution_refresh(self):
        if self.doc and not self._zoom_actual:
            self._layout_render_timer.start()

    def _refresh_fit_resolution(self):
        if self.doc and not self._zoom_actual and self.view.isVisible():
            self._render_preview(keep_view=False)

    def _render_preview(self, keep_view=True):
        if not self.doc:
            return
        # Decode and composition are never performed on Qt's event thread.
        # The prior frame remains visible until this revision is ready.
        if getattr(self.doc, "raw_source_path", ""):
            self._dispatch_raw_quality_render()
        else:
            self._dispatch_quality_render(keep_view=keep_view)

    def _show_rendered(self, rendered, keep_view=True, max_size=None,
                       stable_geometry=False):
        self._last_rendered = rendered.copy()
        edited = pil_to_qpixmap(rendered)
        if self.act_compare.isChecked():
            original = original_pixmap(self.doc.source_path, max_size=max_size)
            self.view.set_compare(original, edited, keep_view=keep_view,
                                  stable_geometry=stable_geometry)
        else:
            self.view.set_pixmap(edited, keep_view=keep_view,
                                 stable_geometry=stable_geometry)
        self._refresh_histogram(rendered)

    def zoom_fit(self):
        """Fit the whole photograph to the window (fast preview resolution)."""
        self._zoom_actual = False
        if self.doc:
            self._render_preview(keep_view=False)
        else:
            self.view.fit()

    def zoom_actual(self):
        """Show actual pixels — re-render at native resolution, then 1:1."""
        self._zoom_actual = True
        if self.doc:
            self._render_preview(keep_view=True)
        self.view.actual_size()

    def zoom_in(self):
        self.view.zoom_by(1.15)

    def zoom_out(self):
        self.view.zoom_by(1 / 1.15)

    def _toggle_compare(self, checked):
        if checked:
            self.view.reset_divider()
        else:
            self.view.clear_compare()
        if self.doc:
            self._render_preview()

    # --- Filmstrip ----------------------------------------------------------
    def _toggle_filmstrip(self, checked):
        self._filmstrip_visible = bool(checked)
        self.filmstrip.setVisible(self._filmstrip_visible)
        self._sync_filmstrip_handle()
        if self._filmstrip_visible and self.doc:
            self.filmstrip.show_for(getattr(self.doc, "browse_source_path", None))
        from . import prefs
        values = prefs.load()
        values["filmstrip_visible"] = self._filmstrip_visible
        prefs.save(values)

    def _sync_filmstrip_handle(self):
        if not hasattr(self, "filmstrip_handle"):
            return
        self.filmstrip_handle.setText(
            "▼  Hide folder thumbnails" if self._filmstrip_visible
            else "▲  Show folder thumbnails")

    def _refresh_filmstrip(self):
        if self._filmstrip_visible and self.doc:
            self.filmstrip.show_for(getattr(self.doc, "browse_source_path", None))

    def _open_from_filmstrip(self, path):
        if not self._confirm_discard():
            self._refresh_filmstrip()   # bounce selection back to the open photo
            return
        self.open_path(path)

    # --- UI sync ------------------------------------------------------------
    def _sync_controls_from_doc(self, include_geometry=True):
        adjustments = self.active_adjustments()
        if adjustments is None:
            return
        for key, row in self.rows.items():
            row.set_value(adjustments.get(key, editor_engine.DEFAULT_ADJUSTMENTS.get(key, 0)))
        for key, row in self.raw_rows.items():
            row.set_value(adjustments.get(key, editor_engine.DEFAULT_ADJUSTMENTS.get(key, 0)))
        for key, checkbox in self._raw_lensfun_checks.items():
            checkbox.blockSignals(True)
            checkbox.setChecked(bool(adjustments.get(
                key, editor_engine.DEFAULT_ADJUSTMENTS[key])))
            checkbox.blockSignals(False)
        is_raw_base = bool(
            self.doc and getattr(self.doc, "raw_source_path", "") and
            self.active_target == BASE)
        self._sections["RAW DEVELOP"].setVisible(is_raw_base)
        for key in editor_engine.RAW_DEVELOPMENT_KEYS:
            ordinary = self.rows.get(key)
            if ordinary is not None:
                ordinary.setVisible(
                    not is_raw_base and
                    (getattr(self, "mode", "advanced") == "advanced" or
                     key in NORMAL_ROWS))
        self.bw_check.blockSignals(True)
        self.bw_check.setChecked(bool(adjustments.get("black_white", False)))
        self.bw_check.blockSignals(False)
        self.grain_darken_check.blockSignals(True)
        self.grain_darken_check.setChecked(bool(adjustments.get("grain_darken", False)))
        self.grain_darken_check.blockSignals(False)
        self.photo_filter_preserve.blockSignals(True)
        self.photo_filter_preserve.setChecked(
            bool(adjustments.get("photo_filter_preserve_lum", True)))
        self.photo_filter_preserve.blockSignals(False)
        self._update_photo_filter_swatch()
        self._sync_photo_filter_combo(adjustments)
        self._update_vignette_controls(adjustments)
        self.sharpen_mode_combo.blockSignals(True)
        self.sharpen_mode_combo.setCurrentIndex(
            0 if adjustments.get("sharpen_mode", "lens") == "lens" else 1)
        self.sharpen_mode_combo.blockSignals(False)
        self._update_split_swatches()
        self.curve_editor.set_curves(adjustments)
        if include_geometry:
            self._sync_geometry()

    def _refresh_actions(self):
        has = self.doc is not None
        has_layer = self._mask_layer() is not None
        editing = has and not self._restricted
        self.act_undo.setEnabled(editing and self.doc.history_index > 0)
        self.act_redo.setEnabled(editing and self.doc.history_index + 1 < len(self.doc.history))
        self.act_export.setEnabled(has)
        self.act_copy_flattened.setEnabled(has)
        self.act_reset.setEnabled(editing)
        self.act_fit.setEnabled(has)
        self.act_full.setEnabled(has)
        self.act_compare.setEnabled(editing)
        self.act_crop.setEnabled(editing)
        self.act_heal.setEnabled(editing)
        self.act_redeye.setEnabled(editing)
        self.act_clone.setEnabled(editing)
        self.act_patch.setEnabled(editing)
        self.act_ai_heal.setEnabled(editing)
        self.act_ai_fill.setEnabled(editing)
        self.act_ai_expand.setEnabled(editing)
        self.act_recipe_save.setEnabled(editing)
        self.act_recipe_apply.setEnabled(editing)
        self.act_save_project.setEnabled(editing)
        self.act_textures.setEnabled(editing)
        self.act_lewks.setEnabled(editing)
        self.act_lewk_again.setEnabled(editing)
        self.act_auto.setEnabled(editing)
        self.act_blog_copy.setEnabled(editing)
        self.act_mask_brush.setEnabled(editing and has_layer)
        self.act_mask_gradient.setEnabled(editing and has_layer)
        self.act_colour_range.setEnabled(editing and has_layer)

    def _refresh_history(self):
        if not hasattr(self, "history_list"):
            return
        self.history_list.blockSignals(True)
        self.history_list.clear()
        if self.doc:
            for index, entry in enumerate(self.doc.history):
                marker = "●  " if index == self.doc.history_index else "   "
                item = QListWidgetItem(marker + entry.get("label", "Edit"))
                item.setData(Qt.UserRole, index)
                self.history_list.addItem(item)
            if self.doc.history_index >= 0:
                self.history_list.setCurrentRow(self.doc.history_index)
                self.history_list.scrollToItem(self.history_list.currentItem())
        self.history_list.blockSignals(False)

    def _history_selected(self, item):
        if not self.doc:
            return
        index = int(item.data(Qt.UserRole))
        if index == self.doc.history_index or not (0 <= index < len(self.doc.history)):
            return
        self.doc.history_index = index
        self.doc.restore(self.doc.history[index]["state"])
        self._validate_target()
        self.layers_panel.rebuild()
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._render_preview()
        self._update_title()

    def _update_title(self):
        if not self.doc:
            self.setWindowTitle(BUILD_VERSION)
            self._refresh_history()
            return
        name = (os.path.basename(self.doc.project_path) if self.doc.project_path
                else getattr(self.doc, "original_filename",
                             os.path.basename(self.doc.source_path)))
        dirty = " ●" if self.doc.is_dirty() else ""
        raw = " [RAW · RawTherapee]" if getattr(self.doc, "raw_source_path", "") else ""
        self.setWindowTitle(f"{name}{raw}{dirty}")
        self._refresh_history()
        self._refresh_actions()

    def _document_stem(self):
        """Name derivatives after a named project, otherwise after the source."""
        remembered = str(getattr(self.doc, "output_title", "") or "").strip()
        if remembered:
            return remembered
        path = self.doc.project_path if self.doc and self.doc.project_path else \
            (self.doc.source_path if self.doc else "untitled")
        return os.path.splitext(os.path.basename(path))[0]

    # --- Close guard --------------------------------------------------------
    def showEvent(self, event):  # noqa: N802 — Qt override
        """Restore maximized state once, after Qt has created the native window."""
        super().showEvent(event)
        if not self._window_state_restored:
            self._window_state_restored = True
            if self._restore_maximized:
                QTimer.singleShot(0, self.showMaximized)

    def _save_window_state(self):
        from . import prefs as _prefs
        values = _prefs.load()
        values["editor_maximized"] = self.isMaximized()
        _prefs.save(values)

    def _confirm_discard(self):
        if (hasattr(self, "view") and self.act_crop.isChecked() and
                self.view._crop_mode):
            # The crop rectangle is only a proposal until Apply Crop. Restore
            # the prior crop before autosaving, closing, or opening another
            # photograph so the temporary uncropped state is never persisted.
            self.act_crop.setChecked(False)
        if self.doc and self.doc.is_dirty():
            # Per-photo working state is persistent. Closing or moving to the
            # next photograph flushes immediately and never asks the user to
            # throw work away. Reset All remains the explicit way to clear it.
            self._recovery_timer.stop()
            self._write_recovery(force=True)
        return True

    def keyPressEvent(self, event):  # noqa: N802 — Qt override
        if (self.free_perspective_btn.isChecked() and
                event.key() in (Qt.Key_Return, Qt.Key_Enter)):
            self._commit_free_perspective()
            event.accept()
            return
        if self.act_crop.isChecked():
            if event.key() in (Qt.Key_Return, Qt.Key_Enter):
                self._commit_crop()
                return
            if event.key() == Qt.Key_Escape:
                self._cancel_crop()
                return
        super().keyPressEvent(event)

    def closeEvent(self, event):
        if self._confirm_discard():
            self._save_window_state()
            event.accept()
        else:
            event.ignore()

# ===== SNAPSMACK EOF =====

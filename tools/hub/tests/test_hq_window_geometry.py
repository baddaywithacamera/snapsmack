"""SNAP HQ opens at a useful size and recovers stale window geometry.

SNAPSMACK_EOF_HEADER: last non-empty line must be # ===== SNAPSMACK EOF =====
"""

import ast
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
QT_SOURCE = ROOT / "qt_main.py"
MAIN_SOURCE = ROOT / "main.py"
SPEC_SOURCE = ROOT / "hub.spec"


def _function(name):
    tree = ast.parse(QT_SOURCE.read_text(encoding="utf-8"))
    return next(
        node for node in tree.body
        if isinstance(node, ast.FunctionDef) and node.name == name
    )


def _window_method(name):
    tree = ast.parse(QT_SOURCE.read_text(encoding="utf-8"))
    window = next(
        node for node in tree.body
        if isinstance(node, ast.ClassDef) and node.name == "Window"
    )
    return next(
        node for node in window.body
        if isinstance(node, ast.FunctionDef) and node.name == name
    )


def _source_segment(node):
    return ast.get_source_segment(QT_SOURCE.read_text(encoding="utf-8"), node)


def test_release_bumps_for_window_geometry_repair():
    source = MAIN_SOURCE.read_text(encoding="utf-8")
    assert re.search(r'^BUILD_VERSION = "0\.7\.54"$', source, re.M)


def test_source_build_uses_repository_icons_before_letter_placeholders():
    source = MAIN_SOURCE.read_text(encoding="utf-8")
    assert 'os.path.dirname(os.path.abspath(__file__))' in source
    assert '"INSTANT REPLAY": "instantreplay-simple.ico"' in source
    assert '"GHOSTED": "ghosted-simple.ico"' in source
    assert '"KEYS PLEASE": "keysplease-simple.ico"' in source
    assert (ROOT / "icons" / "instantreplay-simple.png").is_file()
    assert (ROOT / "icons" / "ghosted-simple.png").is_file()
    assert (ROOT / "icons" / "keysplease-simple.png").is_file()


def test_fed_up_subtitle_uses_product_casing():
    source = QT_SOURCE.read_text(encoding="utf-8")
    assert '("FED UP", "Fediverse → SNAPSMACK"' in source
    assert 'Fediverse → SnapSmack' not in source


def test_hq_build_embeds_the_authoritative_windows_version():
    source = SPEC_SOURCE.read_text(encoding="utf-8")
    assert "from PyInstaller.utils.win32.versioninfo import" in source
    assert "target.id == 'BUILD_VERSION'" in source
    assert "StringStruct('FileVersion', _build_version)" in source
    assert "StringStruct('ProductVersion', _build_version)" in source
    assert "version=_windows_version" in source


def test_first_launch_uses_full_taskbar_safe_height():
    source = _source_segment(_window_method("_fit_to_screen"))
    assert "available.height()" in source
    assert "available.height() * .88" not in source
    assert "self.content.sizeHint().height()" in source
    assert "self.move(available.center() - self.rect().center())" in source


def test_geometry_is_versioned_saved_and_restored():
    source = QT_SOURCE.read_text(encoding="utf-8")
    restore = _source_segment(_window_method("_restore_saved_geometry"))
    close = _source_segment(_window_method("closeEvent"))

    assert "WINDOW_GEOMETRY_SCHEMA = 1" in source
    assert 'self.settings.value("window/normal_geometry")' in restore
    assert 'self.settings.value("window/geometry_schema", 0, type=int)' in restore
    assert "self.restoreGeometry(geometry)" in restore
    assert "_frame_intersects_any_screen" in restore
    assert "self._fit_to_screen()" in restore
    assert 'self.settings.setValue("window/normal_geometry", self.saveGeometry())' in close
    assert 'self.settings.setValue("window/geometry_schema", WINDOW_GEOMETRY_SCHEMA)' in close


def test_offscreen_detection_checks_every_available_screen():
    function = _function("_frame_intersects_any_screen")
    namespace = {}
    exec(compile(ast.Module(body=[function], type_ignores=[]), str(QT_SOURCE), "exec"), namespace)
    visible = namespace["_frame_intersects_any_screen"]

    class Rect:
        def __init__(self, token):
            self.token = token

        def intersects(self, frame):
            return self.token == frame.token

    class Screen:
        def __init__(self, token):
            self.rect = Rect(token)

        def availableGeometry(self):
            return self.rect

    assert visible(Rect("right"), [Screen("left"), Screen("right")]) is True
    assert visible(Rect("missing"), [Screen("left"), Screen("right")]) is False


# ===== SNAPSMACK EOF =====

"""External editor handoff discovers installed apps and never uses a shell."""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
HUB = os.path.dirname(HERE)
if HUB not in sys.path:
    sys.path.insert(0, HUB)


def test_windows_detection_finds_supported_editors(monkeypatch, tmp_path):
    from slapper_qt import external_edit
    program_files = tmp_path / "Program Files"
    executable = program_files / "Topaz Labs LLC" / "Topaz DeNoise AI" / "Topaz DeNoise AI.exe"
    executable.parent.mkdir(parents=True); executable.write_bytes(b"exe")
    monkeypatch.setattr(external_edit.os, "name", "nt")
    monkeypatch.setenv("ProgramFiles", str(program_files))
    monkeypatch.delenv("ProgramFiles(x86)", raising=False)
    assert ("Topaz DeNoise AI", os.path.abspath(executable)) in external_edit.detected_editors()


def test_external_launch_is_argument_array_without_shell(monkeypatch, tmp_path):
    from PySide6.QtWidgets import QApplication
    from slapper_qt.external_edit import ExternalEditDialog
    app = QApplication.instance() or QApplication([])
    edit = tmp_path / "edit copy.tif"; edit.write_bytes(b"tiff")
    program = tmp_path / "editor.exe"; program.write_bytes(b"exe")
    seen = {}
    monkeypatch.setattr("slapper_qt.external_edit.detected_editors",
                        lambda: [("Editor", str(program))])
    monkeypatch.setattr("slapper_qt.external_edit.subprocess.Popen",
                        lambda argv, **kwargs: seen.update(argv=argv, kwargs=kwargs))
    dialog = ExternalEditDialog(str(edit))
    dialog._launch()
    assert seen["argv"] == [os.path.abspath(program), os.path.abspath(edit)]
    assert seen["kwargs"]["shell"] is False

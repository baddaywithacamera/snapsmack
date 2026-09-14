"""RAW browsing delegates thumbnail development to an external RawTherapee."""

from pathlib import Path
import sys

HUB = Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

import raw_preview


def test_versioned_rawtherapee_install_is_found(monkeypatch, tmp_path):
    root = tmp_path / "Program Files"
    executable = root / "RawTherapee" / "5.13" / "rawtherapee-cli.exe"
    executable.parent.mkdir(parents=True)
    executable.write_bytes(b"exe")
    monkeypatch.setattr(raw_preview, "_install_roots", lambda: [str(root)])
    monkeypatch.setattr(raw_preview.shutil, "which", lambda _name: None)
    assert raw_preview.find_rawtherapee(cli=True) == str(executable)


def test_raw_preview_command_uses_fast_external_pipeline(monkeypatch, tmp_path):
    from PIL import Image

    raw = tmp_path / "camera.orf"
    raw.write_bytes(b"raw")
    executable = tmp_path / "rawtherapee-cli.exe"
    executable.write_bytes(b"exe")
    seen = {}

    def fake_run(command, **kwargs):
        seen["command"] = command
        output = Path(command[command.index("-o") + 1])
        Image.new("RGB", (800, 600), "blue").save(output, "JPEG")
        return type("Result", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    monkeypatch.setattr(raw_preview, "find_rawtherapee", lambda cli=True: str(executable))
    monkeypatch.setattr(raw_preview.snap_home, "config_dir", lambda _tool: str(tmp_path / "config"))
    monkeypatch.setattr(raw_preview.subprocess, "run", fake_run)
    preview = raw_preview.render(raw)
    assert preview.size == (512, 384)
    assert seen["command"][-2:] == ["-c", str(raw)]
    assert "-f" in seen["command"]


# ===== SNAPSMACK EOF =====

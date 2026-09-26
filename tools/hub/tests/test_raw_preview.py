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


def test_known_install_wins_over_path_binary(monkeypatch, tmp_path):
    root = tmp_path / "Program Files"
    installed = root / "RawTherapee" / "5.13" / "rawtherapee-cli.exe"
    planted = tmp_path / "path" / "rawtherapee-cli.exe"
    installed.parent.mkdir(parents=True)
    planted.parent.mkdir(parents=True)
    installed.write_bytes(b"installed")
    planted.write_bytes(b"planted")
    monkeypatch.setattr(raw_preview, "_install_roots", lambda: [str(root)])
    monkeypatch.setattr(raw_preview.shutil, "which", lambda _name: str(planted))
    assert raw_preview.find_rawtherapee(cli=True) == str(installed)


def test_raw_preview_command_uses_quality_external_pipeline(monkeypatch, tmp_path):
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
    monkeypatch.setattr(raw_preview.subprocess_limits, "run", fake_run)
    preview = raw_preview.render(raw)
    assert preview.size == (800, 600)
    assert seen["command"][-2:] == ["-c", str(raw)]
    assert "-f" not in seen["command"]
    assert "-j90" in seen["command"]
    assert "-js3" in seen["command"]


def test_raw_preview_uses_1200_pixel_quality_thumbnail(monkeypatch, tmp_path):
    from PIL import Image

    raw = tmp_path / "camera.nef"
    raw.write_bytes(b"raw")
    executable = tmp_path / "rawtherapee-cli.exe"
    executable.write_bytes(b"")

    def fake_run(command, **_kwargs):
        target = command[command.index("-o") + 1]
        Image.new("RGB", (1800, 1200), "grey").save(target, "JPEG")
        return type("Result", (), {"returncode": 0, "stderr": "", "stdout": ""})()

    monkeypatch.setattr(raw_preview, "find_rawtherapee", lambda cli=True: str(executable))
    monkeypatch.setattr(raw_preview.snap_home, "config_dir", lambda _tool: str(tmp_path / "config"))
    monkeypatch.setattr(raw_preview.subprocess_limits, "run", fake_run)
    preview = raw_preview.render(raw)
    assert preview.size == (1200, 800)


def test_dark_raw_library_preview_is_lifted_without_resizing():
    from PIL import Image, ImageStat

    source = Image.new("RGB", (40, 20), (35, 45, 55))
    preview = raw_preview._visible_library_preview(source)
    assert preview.size == source.size
    assert ImageStat.Stat(preview).mean[1] > ImageStat.Stat(source).mean[1]


def test_dark_raw_library_preview_protects_small_bright_regions():
    from PIL import Image

    source = Image.new("RGB", (100, 100), (40, 40, 40))
    source.paste((180, 170, 160), (0, 0, 10, 10))
    preview = raw_preview._visible_library_preview(source)
    assert max(preview.getpixel((5, 5))) <= 225
    assert preview.getpixel((50, 50))[0] > 40


def test_raw_development_uses_derivative_size_ceiling(monkeypatch, tmp_path):
    from PIL import Image

    raw = tmp_path / "camera.cr3"
    raw.write_bytes(b"raw")
    executable = tmp_path / "rawtherapee-cli.exe"
    executable.write_bytes(b"exe")
    seen = []

    def fake_run(command, **_kwargs):
        Image.new("RGB", (20, 12), "grey").save(
            command[command.index("-o") + 1], "TIFF")
        return type("Result", (), {"returncode": 0, "stderr": "", "stdout": ""})()

    real_safe_open = raw_preview.snap_imgsafe.safe_open

    def recording_safe_open(source, **kwargs):
        seen.append(kwargs.get("max_bytes"))
        return real_safe_open(source, **kwargs)

    monkeypatch.setattr(raw_preview, "find_rawtherapee", lambda cli=True: str(executable))
    monkeypatch.setattr(raw_preview.snap_home, "config_dir", lambda _tool: str(tmp_path / "config"))
    monkeypatch.setattr(raw_preview.subprocess_limits, "run", fake_run)
    monkeypatch.setattr(raw_preview.snap_imgsafe, "safe_open", recording_safe_open)
    raw_preview.develop(raw)
    assert raw_preview.RAW_DEVELOPMENT_MAX_BYTES in seen


def test_development_returns_explicit_persistent_artifacts(monkeypatch, tmp_path):
    from PIL import Image

    raw = tmp_path / "camera.raf"
    raw.write_bytes(b"raw")
    executable = tmp_path / "rawtherapee-cli.exe"
    executable.write_bytes(b"exe")

    def fake_run(command, **_kwargs):
        Image.new("RGB", (20, 12), "grey").save(
            command[command.index("-o") + 1], "TIFF")
        return type("Result", (), {"returncode": 0, "stderr": "", "stdout": ""})()

    monkeypatch.setattr(raw_preview, "find_rawtherapee", lambda cli=True: str(executable))
    monkeypatch.setattr(raw_preview.snap_home, "config_dir", lambda _tool: str(tmp_path / "config"))
    monkeypatch.setattr(raw_preview.subprocess_limits, "run", fake_run)
    artifacts = raw_preview.development_artifacts(raw, {"exposure": 1})
    assert artifacts["original"] == str(raw)
    assert Path(artifacts["master"]).suffix == ".tif"
    assert Path(artifacts["profile"]).suffix == ".pp3"
    assert Path(artifacts["profile"]).is_file()
    assert "Compensation=1.0000" in Path(artifacts["profile"]).read_text()


def test_poisoned_development_cache_is_rebuilt(monkeypatch, tmp_path):
    from PIL import Image

    raw = tmp_path / "camera.dng"
    raw.write_bytes(b"raw")
    executable = tmp_path / "rawtherapee-cli.exe"
    executable.write_bytes(b"exe")
    calls = []

    def fake_run(command, **_kwargs):
        calls.append(command)
        Image.new("RGB", (20, 12), (len(calls), 20, 30)).save(
            command[command.index("-o") + 1], "TIFF")
        return type("Result", (), {"returncode": 0, "stderr": "", "stdout": ""})()

    monkeypatch.setattr(raw_preview, "find_rawtherapee", lambda cli=True: str(executable))
    monkeypatch.setattr(raw_preview.snap_home, "config_dir", lambda _tool: str(tmp_path / "config"))
    monkeypatch.setattr(raw_preview.subprocess_limits, "run", fake_run)
    first = raw_preview.develop(raw)
    Path(first).write_bytes(b"forged cache entry")
    second = raw_preview.develop(raw)
    assert first == second
    assert len(calls) == 2
    assert Path(second).read_bytes() != b"forged cache entry"


# ===== SNAPSMACK EOF =====

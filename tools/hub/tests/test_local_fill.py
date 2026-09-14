import os
import pathlib
import sys

from PIL import Image

HUB = pathlib.Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

from slapper_qt import local_fill


def test_install_is_separate_from_slapper(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    assert local_fill.install_root() == str(tmp_path / "local_ai" / "generative_fill")
    assert not local_fill.installed()


def test_fill_sends_only_a_bounded_crop_to_external_runner(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    root = pathlib.Path(local_fill.install_root())
    (root / "venv" / "Scripts").mkdir(parents=True)
    (root / "venv" / "Scripts" / "python.exe").write_bytes(b"")
    (root / "local_fill_runner.py").write_text("", encoding="utf-8")
    (root / "installed.txt").write_text(local_fill.MODEL, encoding="utf-8")
    seen = {}
    def fake_run(args, **_kwargs):
        image_path = args[args.index("--image") + 1]
        output_path = args[args.index("--output") + 1]
        seen["size"] = Image.open(image_path).size
        Image.new("RGB", seen["size"], "blue").save(output_path)
        return type("Result", (), {"returncode": 0, "stderr": "", "stdout": ""})()
    monkeypatch.setattr(local_fill.subprocess, "run", fake_run)
    photo = Image.new("RGB", (2000, 1200), "red")
    mask = Image.new("L", photo.size, 0)
    for y in range(590, 610):
        for x in range(990, 1010):
            mask.putpixel((x, y), 255)
    result, model = local_fill.fill(photo, mask, "repair")
    assert max(seen["size"]) <= 512
    assert result.getpixel((0, 0)) == (255, 0, 0)
    assert result.getpixel((1000, 600)) == (0, 0, 255)
    assert model == local_fill.MODEL


def test_packaged_installer_includes_runner_sources():
    spec = (HUB / "snap_slapper.spec").read_text(encoding="utf-8")
    assert "install_local_fill.py" in spec
    assert "local_fill_runner.py" in spec


# ===== SNAPSMACK EOF =====

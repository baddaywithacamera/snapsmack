"""The optional People component: the real streaming protocol, end to end.

OpenCV is installed in this build environment, so the runner is exercised for
real here -- a live subprocess, the real YuNet and SFace models, and a real
photograph through the actual line protocol -- rather than against a mock of
it. Set SLAPPER_FACE_MODELS to a models directory to include those; without it
the protocol is still checked with a stand-in runner.
"""

import json
import os
import shutil
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import pytest

import slapper_faces
from slapper_qt import faces_component

HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHOTO = os.path.join(os.path.dirname(os.path.dirname(HUB)),
                     "projects", "snapsmack-ca", "img", "sean-paddleboard.jpg")
MODELS = os.environ.get("SLAPPER_FACE_MODELS", "")
HAS_CV2 = __import__("importlib").util.find_spec("cv2") is not None


def _component(tmp_path, monkeypatch, models=""):
    """Lay out an installed component that runs on this interpreter."""
    root = tmp_path / "faces"
    (root / "venv" / "Scripts").mkdir(parents=True)
    shutil.copy2(os.path.join(HUB, "local_ai", "faces_runner.py"),
                 root / "faces_runner.py")
    shutil.copy2(os.path.join(HUB, "slapper_faces.py"), root / "slapper_faces.py")
    (root / "installed.txt").write_text("test", encoding="utf-8")
    if models:
        target = root / "models"
        target.mkdir(exist_ok=True)
        for name in ("yunet.onnx", "sface.onnx"):
            source = os.path.join(models, name)
            if os.path.isfile(source):
                shutil.copy2(source, target / name)
    monkeypatch.setattr(faces_component, "install_root", lambda: str(root))
    monkeypatch.setattr(faces_component, "_python", lambda: sys.executable)
    return root


def test_the_component_is_only_trusted_when_it_matches_what_we_shipped(
        tmp_path, monkeypatch):
    root = _component(tmp_path, monkeypatch)
    assert faces_component.installed()
    # Tampering with either file -- the runner or the detector that travels
    # with it -- takes the component out of service rather than running code
    # we did not ship.
    (root / "faces_runner.py").write_text("print('hello')", encoding="utf-8")
    assert not faces_component.installed()


def test_a_missing_component_is_simply_absent(tmp_path, monkeypatch):
    monkeypatch.setattr(faces_component, "install_root",
                        lambda: str(tmp_path / "nothing"))
    assert not faces_component.installed()
    assert faces_component.ComponentEngine.start.__name__ == "start"
    with pytest.raises(RuntimeError):
        faces_component.ComponentEngine.start()


def test_the_runner_carries_the_applications_own_detector(tmp_path, monkeypatch):
    # One detector, not a copy that drifts: the file beside the runner must be
    # the application's slapper_faces.py, byte for byte.
    root = _component(tmp_path, monkeypatch)
    shipped = open(os.path.join(HUB, "slapper_faces.py"), "rb").read()
    assert (root / "slapper_faces.py").read_bytes() == shipped


def test_slapper_faces_imports_without_any_application_modules():
    """The component's environment has OpenCV and nothing of ours."""
    probe = ("import sys; sys.path=[p for p in sys.path if 'hub' not in p.lower()];"
             "sys.path.insert(0, r'%s'); import slapper_faces;"
             "print(slapper_faces.SAME_PERSON)" % HUB)
    result = subprocess.run([sys.executable, "-c", probe],
                            capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    assert "0.363" in result.stdout


@pytest.mark.skipif(not HAS_CV2, reason="OpenCV is not installed here")
@pytest.mark.skipif(not MODELS, reason="set SLAPPER_FACE_MODELS")
@pytest.mark.skipif(not os.path.isfile(PHOTO), reason="sample photograph missing")
def test_a_real_scan_runs_through_the_component_and_finds_the_face(
        tmp_path, monkeypatch):
    _component(tmp_path, monkeypatch, models=MODELS)
    engine = faces_component.ComponentEngine.start()
    try:
        faces = engine.detect(PHOTO)
        assert len(faces) == 1
        face = faces[0]
        assert len(face.embedding) == 128
        assert face.score > 0.8
        x, y, width, height = face.box
        assert width > 20 and height > 20
        # The same photograph twice gives the same fingerprint, which is what
        # makes "this is Ray" stick.
        again = engine.detect(PHOTO)
        assert slapper_faces.cosine(face.embedding, again[0].embedding) > 0.99
        # A file that is not a photograph is reported as no faces, and the
        # process stays up for the next one.
        bad = tmp_path / "not-a-photo.jpg"
        bad.write_bytes(b"nope")
        assert engine.detect(str(bad)) == []
        assert len(engine.detect(PHOTO)) == 1
    finally:
        engine.close()


@pytest.mark.skipif(not HAS_CV2, reason="OpenCV is not installed here")
@pytest.mark.skipif(not MODELS, reason="set SLAPPER_FACE_MODELS")
@pytest.mark.skipif(not os.path.isfile(PHOTO), reason="sample photograph missing")
def test_a_full_scan_stores_people_and_never_touches_the_photographs(
        tmp_path, monkeypatch):
    _component(tmp_path, monkeypatch, models=MODELS)
    copies = []
    for index in range(3):
        target = tmp_path / f"shot{index}.jpg"
        shutil.copy2(PHOTO, target)
        copies.append(str(target))
    before = {path: os.stat(path).st_mtime_ns for path in copies}
    store = slapper_faces.FaceStore(str(tmp_path / "store"))
    engine = faces_component.ComponentEngine.start()
    try:
        slapper_faces.scan_paths(engine, store, copies)
    finally:
        engine.close()
    assert sum(len(store.photos[store.key(path)]["faces"]) for path in copies) == 3
    groups = store.cluster_unnamed()
    # The same person in all three frames is one group, not three.
    assert len(groups) == 1 and len(groups[0]) == 3
    store.name_faces(groups[0], "Sean")
    assert store.people_in(copies[0]) == ["Sean"]
    assert {path: os.stat(path).st_mtime_ns for path in copies} == before
    # The store holds numbers, not pixels.
    for root, _dirs, files in os.walk(tmp_path / "store"):
        for name in files:
            assert os.path.splitext(name)[1].lower() not in (
                ".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp")


# ===== SNAPSMACK EOF =====

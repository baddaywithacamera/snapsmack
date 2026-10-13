"""Bridge between frozen SNAP SLAPPER and its optional People component.

Face finding needs OpenCV. OpenCV is 82 MB and SNAP SLAPPER is 73 MB, so
bundling it would more than double what every photographer downloads for a
feature not all of them want. It installs separately instead, and this module
is how the application talks to it: one process for a whole scan, a photograph
written per line, a result read per line.

``ComponentEngine`` exposes the same ``detect(path)`` as the in-process
``slapper_faces.FaceEngine``, so ``slapper_faces.scan_paths`` neither knows
nor cares which one it was handed.
"""

import hashlib
import hmac
import json
import os
import subprocess
import sys

import snap_home
import slapper_faces

MAX_LINE_BYTES = 4 * 1024 * 1024        # one result for one photograph


def install_root():
    return os.path.join(snap_home.home(), "local_ai", "faces")


def _python():
    return os.path.join(install_root(), "venv", "Scripts", "python.exe")


def _runner():
    return os.path.join(install_root(), "faces_runner.py")


def _engine_copy():
    return os.path.join(install_root(), "slapper_faces.py")


def _sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _resource(name):
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(__file__)))
    candidates = (os.path.join(base, "local_ai", name),
                  os.path.join(os.path.dirname(os.path.dirname(__file__)),
                               "local_ai", name))
    return next((path for path in candidates if os.path.isfile(path)), "")


def _engine_source():
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(__file__)))
    candidates = (os.path.join(base, "local_ai", "slapper_faces.py"),
                  os.path.join(base, "slapper_faces.py"),
                  os.path.join(os.path.dirname(os.path.dirname(__file__)),
                               "slapper_faces.py"))
    return next((path for path in candidates if os.path.isfile(path)), "")


def installed():
    """True only when the component is present AND is the code we shipped."""
    required = (_python(), _runner(), _engine_copy(),
                os.path.join(install_root(), "installed.txt"))
    if not all(os.path.isfile(path) for path in required):
        return False
    for installed_path, source in ((_runner(), _resource("faces_runner.py")),
                                   (_engine_copy(), _engine_source())):
        if not source:
            return False
        if not hmac.compare_digest(_sha256(installed_path), _sha256(source)):
            return False
    return True


def start_installer():
    installer = _resource("install_faces.py")
    runner = _resource("faces_runner.py")
    engine = _engine_source()
    if not installer or not runner or not engine:
        raise RuntimeError("The People installer files are missing.")
    launcher = _launcher()
    if not launcher:
        raise RuntimeError(
            "Python 3.10 or newer must be installed before People.")
    flags = getattr(subprocess, "CREATE_NEW_CONSOLE", 0)
    return subprocess.Popen(
        [launcher, "-3", installer, install_root(), runner, engine],
        creationflags=flags)


def _launcher():
    """A Python outside the frozen application, to build the component with."""
    import shutil
    return shutil.which("py") or shutil.which("python")


class ComponentEngine:
    """The installed component, speaking slapper_faces.FaceEngine's language."""

    def __init__(self, process):
        self._process = process

    @classmethod
    def start(cls):
        if not installed():
            raise RuntimeError("The People component is not installed.")
        process = subprocess.Popen(
            [_python(), _runner(), "--models", install_root()],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, text=True, encoding="utf-8",
            bufsize=1,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        first = process.stdout.readline()
        try:
            hello = json.loads(first or "{}")
        except ValueError:
            hello = {}
        if not hello.get("ready"):
            process.kill()
            raise RuntimeError(
                hello.get("fatal") or
                "The People component did not start. Reinstall it from the "
                "library's PEOPLE panel.")
        return cls(process)

    def healthy(self):
        """Whether the component is still running and able to answer."""
        return self._process.poll() is None

    def detect(self, image_path):
        """Faces in one photograph, via the component. Reads, never writes."""
        if self._process.poll() is not None:
            raise RuntimeError(
                "The People component stopped running, so the scan cannot "
                "continue. Nothing was changed.")
        self._process.stdin.write(os.path.abspath(image_path) + "\n")
        self._process.stdin.flush()
        line = self._process.stdout.readline()
        if not line:
            raise RuntimeError(
                "The People component stopped answering, so the scan cannot "
                "continue. Nothing was changed.")
        if len(line) > MAX_LINE_BYTES:
            raise RuntimeError("The People component returned an oversized result.")
        try:
            payload = json.loads(line)
        except ValueError as error:
            raise RuntimeError("The People component returned an unreadable "
                               "result.") from error
        if payload.get("error"):
            # One bad photograph is reported as no faces, exactly as the
            # in-process engine does, and the scan carries on.
            return []
        return [slapper_faces.Face(face["box"], face["score"], face["embedding"])
                for face in payload.get("faces", [])]

    def close(self):
        if self._process.poll() is None:
            try:
                self._process.stdin.write("quit\n")
                self._process.stdin.flush()
                self._process.wait(timeout=10)
            except Exception:  # noqa: BLE001
                self._process.kill()

    def __enter__(self):
        return self

    def __exit__(self, *_exception):
        self.close()


def engine():
    """The best available detector, or None.

    In-process OpenCV first -- the Linux packages declare it as a dependency
    and a developer checkout usually has it -- then the installed component.
    """
    try:
        import cv2   # noqa: F401
        in_process = slapper_faces.FaceEngine.load()
        if in_process is not None:
            return in_process
    except ImportError:
        pass
    if installed():
        return ComponentEngine.start()
    return None


# ===== SNAPSMACK EOF =====

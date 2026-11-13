"""COLD TAKE's narrow, local handoff to SNAP SLAPPER.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from dataclasses import dataclass, field
import os
import subprocess
import time


IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp", ".bmp"}


def find_executable(configured="") -> str:
    """Return a real SNAP SLAPPER executable, preferring the owner's choice."""
    candidates = [configured, os.environ.get("SNAP_SLAPPER_EXE", "")]
    try:
        import snap_home
        candidates.append(os.path.join(
            snap_home.install_dir("snap-slapper"), "SNAP SLAPPER.exe"))
    except Exception:  # noqa: BLE001 - the fixed install path remains available
        pass
    candidates.extend((
        r"C:\snapsmack\snap_slapper\SNAP SLAPPER.exe",
        r"C:\snapsmack\snap-slapper\SNAP SLAPPER.exe",
    ))
    for candidate in candidates:
        path = os.path.abspath(str(candidate or "").strip()) if candidate else ""
        if path and os.path.isfile(path):
            return path
    return ""


def launch(executable: str, photograph: str):
    """Open one photograph in SNAP SLAPPER without modifying either file."""
    return subprocess.Popen(  # noqa: S603 - executable was selected locally
        [os.path.abspath(executable), os.path.abspath(photograph)],
        cwd=os.path.dirname(os.path.abspath(executable)))


def focus_window() -> bool:
    """Raise the installed editor when Windows exposes its top-level window."""
    if os.name != "nt":
        return False
    try:
        import ctypes
        from ctypes import wintypes

        user32 = ctypes.windll.user32
        found = []

        @ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
        def visit(hwnd, _lparam):
            if not user32.IsWindowVisible(hwnd):
                return True
            length = user32.GetWindowTextLengthW(hwnd)
            if length <= 0:
                return True
            title = ctypes.create_unicode_buffer(length + 1)
            user32.GetWindowTextW(hwnd, title, length + 1)
            if "SNAP SLAPPER" in title.value.upper():
                found.append(hwnd)
                return False
            return True

        user32.EnumWindows(visit, 0)
        if not found:
            return False
        user32.ShowWindow(found[0], 9)  # SW_RESTORE
        user32.SetForegroundWindow(found[0])
        return True
    except Exception:  # noqa: BLE001 - failure to focus must not stop the handoff
        return False


def _fingerprint(path: str):
    try:
        stat = os.stat(path)
        return stat.st_mtime_ns, stat.st_size
    except OSError:
        return None


def _snapshot(folder: str) -> dict:
    values = {}
    try:
        names = os.listdir(folder)
    except OSError:
        return values
    for name in names:
        path = os.path.abspath(os.path.join(folder, name))
        if os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
            fingerprint = _fingerprint(path)
            if fingerprint:
                values[path] = fingerprint
    return values


def _related(source: str, candidate: str) -> bool:
    source_stem = os.path.splitext(os.path.basename(source))[0].casefold()
    candidate_stem = os.path.splitext(os.path.basename(candidate))[0].casefold()
    return (candidate_stem == source_stem or
            candidate_stem.startswith(source_stem + "-") or
            candidate_stem.startswith(source_stem + "_"))


@dataclass
class ReturnWatch:
    """Find one stable, new flattened image saved beside the handed-off photo."""

    source_path: str
    before: dict = field(default_factory=dict)
    started_ns: int = 0
    _candidate: str = ""
    _fingerprint_value: tuple | None = None
    _stable_polls: int = 0

    @classmethod
    def start(cls, source_path: str):
        source = os.path.abspath(source_path)
        return cls(source, _snapshot(os.path.dirname(source)), time.time_ns())

    def poll(self) -> str:
        folder = os.path.dirname(self.source_path)
        candidates = []
        for path, fingerprint in _snapshot(folder).items():
            if path == self.source_path or not _related(self.source_path, path):
                continue
            if self.before.get(path) == fingerprint:
                continue
            if fingerprint[0] < self.started_ns:
                continue
            candidates.append((fingerprint[0], path, fingerprint))
        if not candidates:
            self._candidate = ""
            self._fingerprint_value = None
            self._stable_polls = 0
            return ""
        _mtime, path, fingerprint = max(candidates)
        if path == self._candidate and fingerprint == self._fingerprint_value:
            self._stable_polls += 1
        else:
            self._candidate = path
            self._fingerprint_value = fingerprint
            self._stable_polls = 0
        # Wait for the same size and timestamp twice. This avoids opening a
        # JPEG or TIFF while SNAP SLAPPER is still writing it.
        return path if self._stable_polls >= 1 else ""


# ===== SNAPSMACK EOF =====

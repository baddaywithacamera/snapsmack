"""COLD SNAP Qt — file pickers that open where the photos actually live.

Sean's staging convention on disk (2026-09-06):
    C:\\snapsmack\\shared_library\\image workflow\\<site domain>\\upload

So "Add photos" starts in the CONNECTED site's upload folder — not in
Documents — and after the first pick it starts wherever you last picked.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
from urllib.parse import urlparse

from PySide6.QtWidgets import QFileDialog

import snap_home

_IMAGE_FILTER = "Images (*.jpg *.jpeg *.png *.webp);;All files (*.*)"
_last_dir = ""


def _workflow_root() -> str:
    """The image-workflow root is a SNAP HQ setting — INHERIT it, never
    re-invent it here (Sean, 2026-09-06). Prefer the shared accessor when the
    settings module ships one; otherwise read the same stored value directly
    (snap-hq/site_settings.json → 'workflow_root'). Falls back to the on-disk
    convention shared_library/'image workflow' when nothing is configured."""
    try:
        import snap_site_settings
        loader = getattr(snap_site_settings, "load_workflow_root", None)
        if loader:
            root = loader()
            if root and os.path.isdir(root):
                return root
    except Exception:  # noqa: BLE001 — settings module absent/older: read the file
        pass
    try:
        with open(snap_home.config_path("snap-hq", "site_settings.json"),
                  encoding="utf-8") as fh:
            store = json.load(fh)
        root = str(store.get("workflow_root", "") or "").strip()
        if root and os.path.isdir(root):
            return root
    except Exception:  # noqa: BLE001 — no HQ setting yet
        pass
    return os.path.join(snap_home.shared_library(), "image workflow")


def start_dir(site_url: str) -> str:
    """Best starting folder: last used → HQ workflow root's site upload →
    site folder → the root itself → SnapSmack home. Never Documents."""
    if _last_dir and os.path.isdir(_last_dir):
        return _last_dir
    domain = urlparse(site_url if "://" in (site_url or "")
                      else "https://" + (site_url or "")).netloc
    workflow_root = _workflow_root()
    candidates = []
    if domain:
        candidates.append(os.path.join(workflow_root, domain, "upload"))
        candidates.append(os.path.join(workflow_root, domain))
    candidates.append(workflow_root)
    for cand in candidates:
        if os.path.isdir(cand):
            return cand
    return snap_home.home()


def _remember(paths):
    global _last_dir
    if paths:
        d = os.path.dirname(paths[0] if isinstance(paths, (list, tuple)) else paths)
        if os.path.isdir(d):
            _last_dir = d


def pick_image(parent, site_url: str, title: str = "Choose photo") -> str:
    path, _ = QFileDialog.getOpenFileName(parent, title, start_dir(site_url), _IMAGE_FILTER)
    _remember(path)
    return path


def pick_images(parent, site_url: str, title: str = "Add photos") -> list:
    paths, _ = QFileDialog.getOpenFileNames(parent, title, start_dir(site_url), _IMAGE_FILTER)
    _remember(paths)
    return list(paths)

# ===== SNAPSMACK EOF =====

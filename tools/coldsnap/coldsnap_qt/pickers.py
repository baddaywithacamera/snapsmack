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

import os
from urllib.parse import urlparse

from PySide6.QtWidgets import QFileDialog

import snap_home

_IMAGE_FILTER = "Images (*.jpg *.jpeg *.png *.webp);;All files (*.*)"
_last_dir = ""


def start_dir(site_url: str) -> str:
    """Best starting folder: last used → site's upload → site workflow →
    workflow root → SnapSmack home. Never Documents."""
    if _last_dir and os.path.isdir(_last_dir):
        return _last_dir
    domain = urlparse(site_url if "://" in (site_url or "")
                      else "https://" + (site_url or "")).netloc
    workflow_root = os.path.join(snap_home.shared_library(), "image workflow")
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

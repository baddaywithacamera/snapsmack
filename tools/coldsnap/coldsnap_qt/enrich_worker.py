"""COLD SNAP Qt — one AI-enrichment worker for every tab.

Wraps the shared snap_enrich module (Gemini vision) behind Qt signals so a
mode can enrich one photo or a whole bucket off the UI thread. COLD ONE's
inline worker predates this; STACK and TAKE use it for per-photo ALT and
caption/tag suggestions.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import threading
from urllib.parse import urlparse

from PySide6.QtCore import QObject, Signal


class EnrichWorker(QObject):
    """Enrich a list of image paths in a daemon thread.

    image_done(index, meta) fires per photo as results land; progressed(done,
    total) drives a status line; failed(message) fires once on the first hard
    error (missing module / no key) and stops the run."""

    progressed = Signal(int, int)
    image_done = Signal(int, dict)
    finished = Signal()
    failed = Signal(str)

    def __init__(self, site_config=None, parent=None):
        super().__init__(parent)
        self.site_config = dict(site_config or {})

    def start(self, paths: list) -> None:
        threading.Thread(target=self._run, args=(list(paths),), daemon=True).start()

    def _run(self, paths: list) -> None:
        try:
            import snap_enrich
            import config as _cfg
        except Exception as e:  # noqa: BLE001
            self.failed.emit(f"The shared enrichment module isn't available: {e}")
            return
        data = _cfg.load()
        api_key = (data.get("gemini_api_key") or "").strip()
        site = (self.site_config.get("url") or data.get("url") or "").strip()
        # The CMS prompt is mirrored into the shared prompt pool under the site's
        # hostname.  Prefer it over whichever generic preset happened to be used
        # most recently by another desktop tool.
        prompt = ""
        try:
            import snap_prompts
            host = (urlparse(site).hostname or site).lower().rstrip("/")
            prompt = str((snap_prompts.load() or {}).get(host, "") or "").strip()
        except Exception:
            pass
        if not prompt:
            prompt = (data.get("gemini_last_prompt") or "").strip()
        cats, albums, cat_d, alb_d, etags = [], [], {}, {}, []
        try:
            import snap_library as lib
            if site:
                cats, albums = lib.categories(site), lib.albums(site)
                cat_d = lib.category_descriptions(site)
                alb_d = lib.album_descriptions(site)
                etags = lib.tags(site)
        except Exception:  # noqa: BLE001 — no catalog yet: enrich without it
            pass
        total = len(paths)
        for i, p in enumerate(paths):
            if not p or not os.path.isfile(p):
                self.progressed.emit(i + 1, total)
                continue
            try:
                meta = snap_enrich.enrich_image(
                    p, categories=cats, albums=albums, api_key=api_key,
                    custom_prompt=prompt, cat_descriptions=cat_d,
                    album_descriptions=alb_d, existing_tags=etags,
                    site_url=site) or {}
            except Exception as e:  # noqa: BLE001
                self.failed.emit(str(e))
                return
            self.image_done.emit(i, meta)
            self.progressed.emit(i + 1, total)
        self.finished.emit()

# ===== SNAPSMACK EOF =====

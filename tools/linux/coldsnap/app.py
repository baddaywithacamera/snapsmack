#!/usr/bin/env python3
"""
COLD SNAP — Linux Chrome/Blink port.

The window is HTML/CSS/JS drawn by Chromium; the WORK is the original COLD SNAP
Python, imported unchanged. COLD SNAP is a standalone OFFLINE store-and-forward
poster with two modes — COLD ONE (solo / SMACKONEOUT) and COLD STACK (gram /
GRAMOFSMACK single, carousel and trigram). Compose with no connection; SYNC when
you're online.

Nothing about the posting/compose logic is rewritten here. This file is a thin
host that:
  * imports config.py / profile_manager.py (config + profiles, shared-library aware),
  * imports sumna_offline.py (the GUI-free draft/session/thumb/slice/sync engine),
  * imports sumna_post.py (the HTTP transport: SoloPoster / GramPoster),
and drives them through @app.api handlers that map 1:1 to the old tkinter controls.

Two in-memory controllers (SoloController / GramController) hold exactly the
working state the tkinter panels held (self._work_images, self._trig_slots,
self._sel_img, the per-image control vars, the editor fields), so every tkinter
action has a matching blink.call.

Ported for Linux; imports verified with ast.parse. NOT yet run on Linux hardware.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import base64
import os
import shutil
import subprocess
import sys
from urllib.parse import urlparse

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/coldsnap/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")       # tools/_shared/
for _p in (os.path.abspath(SHARED), os.path.abspath(TOOL_ROOT)):
    if _p not in sys.path:
        sys.path.insert(0, _p)

import snap_blink

# ── The original COLD SNAP logic, imported unchanged ─────────────────────────
import config as cfg_module          # config load/save + gemini prompt presets
import profile_manager               # per-site connection profiles
import sumna_offline as O            # GUI-free engine: Session/Draft/thumbs/slice/sync

# The HTTP transport needs `requests`; guard so COMPOSE still works fully offline
# even if requests isn't installed (only SYNC needs it), mirroring coldsnap.py's
# guard around the mode panels.
try:
    from sumna_post import SumnaConnection, SoloPoster, GramPoster
    _POST_OK = True
    _POST_ERR = ""
except Exception as _e:              # pragma: no cover - import shim
    _POST_OK = False
    _POST_ERR = str(_e)

BUILD_VERSION = "0.1.7"

app = snap_blink.App(tool="coldsnap", title="COLD SNAP",
                     web_dir=os.path.join(HERE, "web"))


# ─────────────────────────────────────────────────────────────────────────────
# Host shim — the posters read getattr(host, "_config", {}) / "_site_data".
# ─────────────────────────────────────────────────────────────────────────────
class _Host:
    def __init__(self):
        self._config = {}
        self._site_data = None       # COLD SNAP builds no SiteData (matches tkinter)


_host = _Host()


def _reload_config():
    _host._config = cfg_module.load()
    return _host._config


_reload_config()


# ─────────────────────────────────────────────────────────────────────────────
# Native file/dir chooser — the web sandbox can't hand Python an absolute path,
# so we use the desktop's own dialog (zenity / kdialog / qarma). If none is
# present the handler raises and the page falls back to a paste-a-path field, so
# no action is ever lost.  (Replaces tkinter filedialog; rule 2: no tkinter.)
# ─────────────────────────────────────────────────────────────────────────────
def _native_pick(*, multiple=False, directory=False, title="Choose"):
    home = os.path.expanduser("~")
    zen = shutil.which("zenity") or shutil.which("qarma")
    if zen:
        args = [zen, "--file-selection", "--title", title]
        if directory:
            args.append("--directory")
        if multiple:
            args += ["--multiple", "--separator", "\n"]
        try:
            r = subprocess.run(args, capture_output=True, text=True, timeout=600)
        except Exception as e:
            raise RuntimeError("file dialog failed: %s" % e)
        if r.returncode != 0:
            return []               # user cancelled
        return [p for p in r.stdout.strip().split("\n") if p]
    kd = shutil.which("kdialog")
    if kd:
        if directory:
            args = [kd, "--getexistingdirectory", home]
        elif multiple:
            args = [kd, "--multiple", "--separate-output", "--getopenfilename", home]
        else:
            args = [kd, "--getopenfilename", home]
        try:
            r = subprocess.run(args, capture_output=True, text=True, timeout=600)
        except Exception as e:
            raise RuntimeError("file dialog failed: %s" % e)
        if r.returncode != 0:
            return []
        return [p for p in r.stdout.strip().split("\n") if p]
    raise RuntimeError(
        "No native file dialog found (install 'zenity' or 'kdialog'). "
        "Paste an absolute file path into the box instead.")


# ─────────────────────────────────────────────────────────────────────────────
# Serve a local image to the page as a data: URI (snap_blink only serves web/).
# Used for every thumbnail/preview the tkinter version drew with PIL.
# ─────────────────────────────────────────────────────────────────────────────
_IMG_MIME = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
             ".webp": "image/webp", ".gif": "image/gif"}


@app.api
def image_data_uri(path):
    """Return a base64 data: URI for a local image, or '' if it's not readable."""
    if not path or not os.path.isfile(path):
        return ""
    ext = os.path.splitext(path)[1].lower()
    if ext not in _IMG_MIME:
        return ""
    try:
        with open(path, "rb") as fh:
            b = fh.read()
    except Exception:
        return ""
    return "data:%s;base64,%s" % (_IMG_MIME[ext], base64.b64encode(b).decode("ascii"))


def _host_name(url):
    if not url:
        return "your site"
    return urlparse(url if "://" in url else "https://" + url).netloc or url


# ═════════════════════════════════════════════════════════════════════════════
# CONNECTION panel  (coldsnap.py)
# ═════════════════════════════════════════════════════════════════════════════
@app.api
def load_state():
    """Everything the page needs on open."""
    cfg = _reload_config()
    return {
        "version": BUILD_VERSION,
        "post_available": _POST_OK,
        "post_error": _POST_ERR,
        "profiles": profile_manager.list_profiles(),
        "config": {
            "url": cfg.get("url", ""),
            "api_key": cfg.get("api_key", ""),
            "gemini_set": bool((cfg.get("gemini_api_key") or "").strip()),
        },
        "solo": _solo.state(),
        "gram": _gram.state(),
    }


@app.api
def pick_profile(name):
    """LOAD PROFILE combobox → fill url (+ api key if the profile carries one)."""
    name = (name or "").strip()
    if not name:
        return {"ok": False, "message": "No profile chosen."}
    prof = profile_manager.load_profile(name)
    if not prof:
        return {"ok": False, "message": "Profile not found."}
    out = {"ok": True, "url": prof.get("url", "") or ""}
    if prof.get("api_key"):
        out["api_key"] = prof.get("api_key", "")
    out["message"] = "Loaded '%s' — review + SAVE / APPLY." % name
    return out


@app.api
def save_connection(url, api_key):
    """SAVE / APPLY — persist url + api key through config.py (shared-library aware)."""
    url = (url or "").strip()
    api_key = (api_key or "").strip()
    if not url or not api_key:
        return {"ok": False, "message": "Both SITE URL and API KEY are required."}
    cfg = _reload_config()
    cfg["url"] = url
    cfg["api_key"] = api_key
    try:
        cfg_module.save(cfg)
    except Exception as e:
        return {"ok": False, "message": "Save failed: %s" % e}
    _host._config = cfg
    return {"ok": True, "message": "Saved. Connection ready."}


# ═════════════════════════════════════════════════════════════════════════════
# Shared AI Fill (Gemini) — used by both modes (mirrors sumna_solo._ai_fill).
# ═════════════════════════════════════════════════════════════════════════════
def _shared_mod(name):
    try:
        sd = os.path.abspath(SHARED)
        if os.path.isdir(sd) and sd not in sys.path:
            sys.path.insert(0, sd)
        return __import__(name)
    except Exception:
        return None


def _ai_fill(image_path):
    """Fill caption/ALT/tags/title/category/album from an image via Gemini, drawing
    categories/albums from the OFFLINE catalog mirror. Raises on error."""
    img = (image_path or "").strip()
    if not img or not os.path.isfile(img):
        raise RuntimeError("Choose an image first.")
    enrich = _shared_mod("snap_enrich")
    if not enrich:
        raise RuntimeError("The shared enrichment module isn't installed.")
    data = cfg_module.load()
    site = (data.get("url") or "").strip()
    lib = _shared_mod("snap_library")
    cats = lib.categories(site) if (lib and site) else []
    albums = lib.albums(site) if (lib and site) else []
    cat_desc = lib.category_descriptions(site) if (lib and site) else {}
    alb_desc = lib.album_descriptions(site) if (lib and site) else {}
    etags = lib.tags(site) if (lib and site) else []
    meta = enrich.enrich_image(
        img, categories=cats, albums=albums,
        api_key=data.get("gemini_api_key", ""),
        custom_prompt=(data.get("gemini_last_prompt") or "").strip(),
        cat_descriptions=cat_desc, album_descriptions=alb_desc,
        existing_tags=etags,
    )
    return meta or {}


# ═════════════════════════════════════════════════════════════════════════════
# COLD ONE  (solo)  — controller mirrors sumna_solo.SoloMode
# ═════════════════════════════════════════════════════════════════════════════
class SoloController:
    SUITE_MODE = O.MODE_SOLO

    def __init__(self):
        self.store = O.SessionStore()
        self.session = None
        self._editing_id = None
        self._sessions_cache = []

    # -- sessions -----------------------------------------------------------
    def _sessions(self):
        return [s for s in self.store.list() if s.mode == self.SUITE_MODE]

    def _session_views(self):
        self._sessions_cache = self._sessions()
        return [{"id": s.session_id,
                 "name": s.name,
                 "label": "%s  ·  %d drafts" % (s.name, len(s.list_drafts()))}
                for s in self._sessions_cache]

    def state(self):
        views = self._session_views()
        if self._sessions_cache and self.session is None:
            self.session = self._sessions_cache[0]
        elif not self._sessions_cache:
            self.session = None
        return {
            "sessions": views,
            "current": self.session.session_id if self.session else "",
            "drafts": self._draft_views(),
        }

    def select_session(self, session_id):
        for s in self._sessions_cache or self._sessions():
            if s.session_id == session_id:
                self.session = s
                break
        return {"drafts": self._draft_views(),
                "current": self.session.session_id if self.session else ""}

    def new_session(self, name):
        name = (name or "").strip() or None
        self.session = self.store.create(name, self.SUITE_MODE)
        return self.state()

    def export_session(self, dest_dir):
        if not self.session:
            raise RuntimeError("No session to export.")
        if not dest_dir:
            picks = _native_pick(directory=True, title="Export session to (USB / folder)")
            if not picks:
                return {"ok": False, "message": "Export cancelled."}
            dest_dir = picks[0]
        out = O.export_session(self.session, dest_dir)
        return {"ok": True, "message": "Session exported to:\n%s" % out}

    def import_session(self, src_dir):
        if not src_dir:
            picks = _native_pick(directory=True, title="Choose an exported session folder")
            if not picks:
                return {"ok": False, "message": "Import cancelled."}
            src_dir = picks[0]
        self.session = O.import_session(src_dir, self.store)
        st = self.state()
        st["ok"] = True
        return st

    # -- drafts -------------------------------------------------------------
    def _draft_views(self):
        if not self.session:
            return []
        out = []
        for d in self.session.list_drafts():
            cover = d.cover()
            out.append({
                "id": d.draft_id,
                "title": d.title or "(untitled)",
                "status": d.status,
                "error": d.error or "",
                "thumb": (cover.thumb_square if cover else "") or "",
            })
        return out

    def edit(self, draft_id):
        if not self.session:
            raise RuntimeError("No session.")
        d = self.session.load_draft(draft_id)
        if not d:
            raise RuntimeError("Draft not found.")
        self._editing_id = d.draft_id
        cover = d.cover()
        return {
            "editing_id": d.draft_id,
            "image_path": (cover.local_path if cover else "") or "",
            "title": d.title,
            "tags": d.tags,
            "caption": d.caption,
            "alt": d.alt,
            "category": d.category,
            "album": d.album,
            "orientation": d.orientation or "auto",
            "color_mode": {"color": "Colour", "bw": "B&W"}.get(d.color_mode, "—"),
            "status": d.img_status,
            "allow_dl": bool(d.allow_download),
            "download_url": d.download_url,
            "preview": (cover.thumb_square if cover else (cover.local_path if cover else "")) or "",
        }

    def delete(self, draft_id):
        if self.session:
            self.session.delete_draft(draft_id)
            if self._editing_id == draft_id:
                self._editing_id = None
        return {"drafts": self._draft_views()}

    def _blank_draft(self):
        return O.Draft(draft_id=O._new_id(), kind=O.KIND_SOLO, mode=self.SUITE_MODE)

    def save_draft(self, f, ready):
        """f: dict of editor fields. Mirrors SoloMode._save_draft."""
        if not self.session:
            self.session = self.store.create(None, self.SUITE_MODE)
        img = (f.get("image_path") or "").strip()
        if not img or not os.path.isfile(img):
            return {"ok": False, "message": "Choose an image first."}

        if self._editing_id:
            draft = self.session.load_draft(self._editing_id) or self._blank_draft()
        else:
            draft = self._blank_draft()
        draft.title = (f.get("title") or "").strip()
        draft.tags = (f.get("tags") or "").strip()
        draft.caption = (f.get("caption") or "").strip()
        draft.alt = (f.get("alt") or "").strip()
        draft.category = (f.get("category") or "").strip()
        draft.album = (f.get("album") or "").strip()
        draft.orientation = f.get("orientation") or "auto"
        draft.color_mode = {"Colour": "color", "B&W": "bw"}.get(f.get("color_mode"), "")
        draft.img_status = f.get("status") or "published"
        draft.allow_download = bool(f.get("allow_dl"))
        draft.download_url = (f.get("download_url") or "").strip()
        draft.images = [O.DraftImage(local_path=img, filename=os.path.basename(img),
                                     is_cover=True)]
        O.generate_draft_thumbs(draft)
        problems = draft.validate()
        warn = ""
        if ready and problems:
            warn = "\n".join(problems)
        draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
        self.session.add_draft(draft)
        self._editing_id = None
        big = ""
        if self.session.over_soft_limit():
            big = ("This batch now holds %d images (soft limit ~%d). It'll still sync "
                   "fine — but consider starting a new batch." %
                   (self.session.image_count(), O.SOFT_BATCH_IMAGE_LIMIT))
        return {"ok": True, "not_ready": warn, "big_batch": big, "state": self.state()}

    # -- sync ---------------------------------------------------------------
    def sync_target(self):
        """Return the destination + ready count for the page's NAME-the-site confirm."""
        if not self.session:
            return {"ok": False, "message": "No session."}
        cfg = _host._config or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("api_key") or "").strip()
        if not url or not key:
            return {"ok": False, "message": "Set the site URL + API key on CONNECTION first."}
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            return {"ok": False, "message": "Nothing marked OFFLINE POST yet — compose and commit first."}
        return {"ok": True, "count": len(ready), "host": _host_name(url), "url": url}

    def sync(self):
        """Publish every READY draft + positively verify. (Confirm already handled
        in the page, naming the site — the ARCH-03 Parkinson's guard.)"""
        if not _POST_OK:
            return {"ok": False, "message": "Posting unavailable: %s" % _POST_ERR}
        if not self.session:
            return {"ok": False, "message": "No session."}
        cfg = _host._config or {}
        conn = SumnaConnection((cfg.get("url") or "").strip(), (cfg.get("api_key") or "").strip())
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            return {"ok": False, "message": "Nothing ready."}
        poster = SoloPoster(conn, site_data=_host._site_data)
        # TODO(port): the tkinter version streamed per-draft badge updates via
        # after(); blink.call is request/response, so we run the whole batch and
        # return the final per-draft statuses (rendered from state()) instead.
        engine = O.SyncEngine(self.session, poster)
        results = engine.sync_all(ready)
        ok = sum(1 for r in results.values() if r.ok)
        return {"ok": True, "synced": ok, "total": len(results), "state": self.state()}


# ═════════════════════════════════════════════════════════════════════════════
# COLD STACK  (gram)  — controller mirrors sumna_gram.GramMode
# ═════════════════════════════════════════════════════════════════════════════
class GramController:
    SUITE_MODE = O.MODE_GRAM

    def __init__(self):
        self.store = O.SessionStore()
        self.session = None
        self._sessions_cache = []
        # compose working state (mirrors GramMode)
        self.kind = "carousel"                 # single | carousel | trigram
        self.trig_style = "single"             # single | carousels
        self.trig_orientation = "h"            # h | v
        self.cut_a = 33
        self.cut_b = 67
        self._trig_cover_src = ""
        self._trig_group_key = ""
        self.work_images = []                  # List[DraftImage]
        self.trig_slots = [[], [], []]         # 3 slots
        self._sel = None                       # (slot_or_None, idx) selection ref
        self._editing_id = ""
        self._editing_group = ""

    # -- sessions (same shape as solo) --------------------------------------
    def _sessions(self):
        return [s for s in self.store.list() if s.mode == self.SUITE_MODE]

    def _session_views(self):
        self._sessions_cache = self._sessions()
        return [{"id": s.session_id, "name": s.name,
                 "label": "%s  ·  %d items" % (s.name, len(s.list_drafts()))}
                for s in self._sessions_cache]

    def state(self):
        views = self._session_views()
        if self._sessions_cache and self.session is None:
            self.session = self._sessions_cache[0]
        elif not self._sessions_cache:
            self.session = None
        return {
            "sessions": views,
            "current": self.session.session_id if self.session else "",
            "drafts": self._draft_views(),
            "compose": self.compose_state(),
        }

    def select_session(self, session_id):
        for s in self._sessions_cache or self._sessions():
            if s.session_id == session_id:
                self.session = s
                break
        return {"drafts": self._draft_views(),
                "current": self.session.session_id if self.session else ""}

    def new_session(self, name):
        self.session = self.store.create((name or "").strip() or None, self.SUITE_MODE)
        return self.state()

    def _ensure_session(self):
        if not self.session:
            self.session = self.store.create(None, self.SUITE_MODE)
        return self.session is not None

    def export_session(self, dest_dir):
        if not self.session:
            raise RuntimeError("No batch to export.")
        if not dest_dir:
            picks = _native_pick(directory=True, title="Export batch to (USB / folder)")
            if not picks:
                return {"ok": False, "message": "Export cancelled."}
            dest_dir = picks[0]
        out = O.export_session(self.session, dest_dir)
        return {"ok": True, "message": "Batch exported to:\n%s" % out}

    def import_session(self, src_dir):
        if not src_dir:
            picks = _native_pick(directory=True, title="Choose an exported batch folder")
            if not picks:
                return {"ok": False, "message": "Import cancelled."}
            src_dir = picks[0]
        self.session = O.import_session(src_dir, self.store)
        st = self.state()
        st["ok"] = True
        return st

    # -- compose: kind / trig settings --------------------------------------
    def set_kind(self, kind):
        if kind in ("single", "carousel", "trigram"):
            self.kind = kind
        return self.compose_state()

    def set_trig_style(self, style):
        if style in ("single", "carousels"):
            self.trig_style = style
        return {"ok": True}

    def set_trig_orientation(self, o):
        self.trig_orientation = "v" if o == "v" else "h"
        return {"ok": True}

    # -- image sources ------------------------------------------------------
    def _new_image(self, path, cover=False, pos=0):
        return O.DraftImage(local_path=path, filename=os.path.basename(path),
                            is_cover=cover, sort_position=pos)

    def add_images(self, paths=None):
        if paths is None:
            paths = _native_pick(multiple=True, title="Add images")
        msg = ""
        for p in paths:
            if len(self.work_images) >= O.CAROUSEL_MAX_IMAGES:
                msg = "Up to %d images per post." % O.CAROUSEL_MAX_IMAGES
                break
            self.work_images.append(self._new_image(p, cover=not self.work_images,
                                                     pos=len(self.work_images)))
        out = self.compose_state()
        out["message"] = msg
        return out

    def clear_images(self):
        self.work_images = []
        self._sel = None
        return self.compose_state()

    def slice_cover(self, path=None, cut_a=None, cut_b=None, orientation=None):
        if orientation:
            self.trig_orientation = "v" if orientation == "v" else "h"
        if cut_a is not None:
            self.cut_a = int(cut_a)
        if cut_b is not None:
            self.cut_b = int(cut_b)
        if not path:
            picks = _native_pick(title="Choose a cover to slice into three")
            if not picks:
                return {"ok": False, "message": "Slice cancelled."}
            path = picks[0]
        if not self._ensure_session():
            return {"ok": False, "message": "No batch."}
        self._trig_cover_src = path
        self._trig_group_key = ""
        return self._do_slice()

    def reslice(self, cut_a=None, cut_b=None):
        if cut_a is not None:
            self.cut_a = int(cut_a)
        if cut_b is not None:
            self.cut_b = int(cut_b)
        if self.kind == "trigram" and self._trig_cover_src:
            return self._do_slice()
        return self.compose_state()

    def _do_slice(self):
        if not self._trig_cover_src or not os.path.isfile(self._trig_cover_src):
            return {"ok": False, "message": "Cover image missing."}
        extras = [slot[1:] if slot else [] for slot in self.trig_slots]
        ca = max(5, min(90, int(self.cut_a))) / 100.0
        cb = max(int(self.cut_a) + 5, min(95, int(self.cut_b))) / 100.0
        gk = self._trig_group_key or None
        chunks = O.slice_trigram_cover(
            self._trig_cover_src, self.session.images_dir,
            orientation=self.trig_orientation, mode=self.SUITE_MODE,
            cut_a=ca, cut_b=cb, group_key=gk)
        self._trig_group_key = chunks[0].group_key
        self.trig_slots = []
        for i, c in enumerate(chunks):
            slot = [c.images[0]]
            if i < len(extras):
                slot.extend(extras[i])
            self.trig_slots.append(slot)
        self._sel = (0, 0)
        out = self.compose_state()
        out["ok"] = True
        return out

    def add_to_slot(self, slot_idx, paths=None):
        if self.trig_style != "carousels":
            return {"ok": False,
                    "message": "Switch 'Trigram of' to '3 carousels' to add images to a slot."}
        if paths is None:
            paths = _native_pick(multiple=True, title="Add images to slot %d" % (slot_idx + 1))
        slot = self.trig_slots[slot_idx]
        for p in paths:
            if len(slot) >= O.CAROUSEL_MAX_IMAGES:
                break
            slot.append(self._new_image(p, cover=False, pos=len(slot)))
        out = self.compose_state()
        out["ok"] = True
        return out

    # -- strip / slot addressing --------------------------------------------
    def _list_for_slot(self, slot):
        return self.work_images if slot is None or slot < 0 else self.trig_slots[slot]

    def _ref_key(self, slot, idx):
        return (None if (slot is None or slot < 0) else slot, idx)

    def move(self, slot, idx, delta):
        lst = self._list_for_slot(slot)
        if not (0 <= idx < len(lst)):
            return self.compose_state()
        j = idx + delta
        if 0 <= j < len(lst):
            lst[idx], lst[j] = lst[j], lst[idx]
            is_trig = (slot is not None and slot >= 0)
            for k, x in enumerate(lst):
                x.sort_position = k
                if not is_trig:
                    x.is_cover = (k == 0)
        return self.compose_state()

    def remove(self, slot, idx):
        lst = self._list_for_slot(slot)
        if not (0 <= idx < len(lst)):
            return self.compose_state()
        im = lst[idx]
        if slot is not None and slot >= 0 and im.is_cover:
            out = self.compose_state()
            out["message"] = "The slice is the slot's cover; it can't be removed."
            return out
        lst.pop(idx)
        for k, x in enumerate(lst):
            x.sort_position = k
        if self._sel == self._ref_key(slot, idx):
            self._sel = None
        return self.compose_state()

    # -- per-image controls -------------------------------------------------
    def select_image(self, slot, idx):
        lst = self._list_for_slot(slot)
        if not (0 <= idx < len(lst)):
            return {"ok": False}
        self._sel = self._ref_key(slot, idx)
        im = lst[idx]
        return {
            "ok": True,
            "controls": {
                "crop": im.crop_mode, "size": im.size_pct, "border": im.border_px,
                "border_color": im.border_color, "bg": im.bg_color, "shadow": im.shadow,
                "fx": im.focus_x, "fy": im.focus_y, "zoom": im.zoom, "split": im.split,
            },
            "preview": (im.thumb_square or im.local_path) or "",
            "compose": self.compose_state(),
        }

    def _selected_image(self):
        if self._sel is None:
            return None
        slot, idx = self._sel
        lst = self._list_for_slot(slot)
        return lst[idx] if 0 <= idx < len(lst) else None

    def write_controls(self, c):
        im = self._selected_image()
        if im is None:
            return {"ok": False}
        im.crop_mode = c.get("crop", im.crop_mode)
        try:
            im.size_pct = int(c.get("size", im.size_pct))
            im.border_px = int(c.get("border", im.border_px))
            im.shadow = int(c.get("shadow", im.shadow))
            im.focus_x = int(c.get("fx", im.focus_x))
            im.focus_y = int(c.get("fy", im.focus_y))
            im.zoom = int(c.get("zoom", im.zoom))
        except (ValueError, TypeError):
            pass
        im.border_color = c.get("border_color", im.border_color)
        im.bg_color = c.get("bg", im.bg_color)
        im.split = bool(c.get("split", im.split))
        return {"ok": True}

    def recrop(self, c):
        """Update crop preview — regenerate the square thumb with focal/zoom."""
        self.write_controls(c)
        im = self._selected_image()
        if im is None:
            return {"ok": False}
        if im.local_path and os.path.isfile(im.local_path):
            import snap_thumbs
            res = snap_thumbs.generate_thumbs(im.local_path, sq_size=400, asp_max=400,
                                              focus_x=im.focus_x, focus_y=im.focus_y,
                                              zoom=im.zoom)
            if res:
                im.thumb_square = res["sq_path"]
                im.thumb_aspect = res["asp_path"]
                im.width = res["width"]
                im.height = res["height"]
        return {"ok": True, "preview": (im.thumb_square or im.local_path) or "",
                "compose": self.compose_state()}

    def _img_view(self, im, i, slot):
        sel = (self._sel == self._ref_key(slot, i))
        return {"idx": i, "cover": bool(im.is_cover),
                "tag": "★" if im.is_cover else str(i + 1),
                "thumb": (im.thumb_square or im.local_path) or "",
                "selected": sel}

    def compose_state(self):
        st = {
            "kind": self.kind,
            "trig_style": self.trig_style,
            "trig_orientation": self.trig_orientation,
            "cut_a": self.cut_a, "cut_b": self.cut_b,
            "editing": bool(self._editing_id or self._editing_group),
            "has_selection": self._sel is not None,
        }
        if self.kind == "trigram":
            labels = ("L/T", "M", "R/B")
            st["slots"] = [
                {"label": labels[i],
                 "images": [self._img_view(im, j, i) for j, im in enumerate(slot)]}
                for i, slot in enumerate(self.trig_slots)
            ]
            st["band"] = [
                {"thumb": (slot[0].thumb_square or slot[0].local_path) if slot else ""}
                for slot in self.trig_slots
            ]
            st["sliced"] = any(self.trig_slots)
        else:
            st["images"] = [self._img_view(im, i, None)
                            for i, im in enumerate(self.work_images)]
        return st

    # -- the batch list -----------------------------------------------------
    def _draft_views(self):
        if not self.session:
            return []
        drafts = self.session.list_drafts()
        out = []
        shown = set()
        for d in drafts:
            if d.kind == O.KIND_GRAM_TRIGRAM:
                if d.group_key in shown:
                    continue
                shown.add(d.group_key)
                out.append(self._trigram_view(drafts, d.group_key))
            else:
                out.append(self._single_view(d))
        return out

    def _single_view(self, d):
        cover = d.cover()
        return {
            "type": "single",
            "id": d.draft_id,
            "status": d.status,
            "synced": d.status == O.ST_SYNCED,
            "label": "%s · %d img" % (
                "Carousel" if d.kind == O.KIND_GRAM_CAROUSEL else "Single", len(d.images)),
            "error": d.error or "",
            "thumb": (cover.thumb_square if cover else "") or "",
        }

    def _trigram_view(self, drafts, group_key):
        members = sorted([d for d in drafts if d.group_key == group_key],
                         key=lambda d: d.trigram_slot)
        ready_n = O.trigram_ready_count(drafts, group_key)
        statuses = {m.status for m in members}
        orient = members[0].trigram_orientation if members else "h"
        is_caro = any(len(m.images) > 1 for m in members)
        if ready_n < 3:
            badge = "queued"
            note = "waiting for %d more" % (3 - ready_n)
        else:
            note = ""
            badge = ("synced" if statuses == {O.ST_SYNCED} else
                     ("failed" if O.ST_FAILED in statuses else
                      ("ready" if O.ST_READY in statuses else "draft")))
        thumbs = []
        for m in members:
            cov = m.cover()
            thumbs.append((cov.thumb_square if cov else "") or "")
        err = next((m.error for m in members if m.error), "")
        return {
            "type": "trigram",
            "group_key": group_key,
            "label": "Trigram (%s%s) · %d/3" % (orient, "·carousels" if is_caro else "", ready_n),
            "badge": badge,
            "note": note,
            "error": err,
            "thumbs": thumbs,
            "synced": statuses == {O.ST_SYNCED},
        }

    # -- edit (unsynced only) ----------------------------------------------
    def edit_single(self, draft_id):
        self.clear_compose()
        if not self.session:
            return {"ok": False}
        d = self.session.load_draft(draft_id)
        if not d:
            return {"ok": False}
        self._editing_id = d.draft_id
        self.kind = "carousel" if d.kind == O.KIND_GRAM_CAROUSEL else "single"
        self.work_images = [O.DraftImage.from_dict(im.to_dict()) for im in d.images]
        post = self._post_fields(d)
        if self.work_images:
            self._sel = (None, 0)
        return {"ok": True, "post": post, "compose": self.compose_state()}

    def edit_trigram(self, group_key):
        self.clear_compose()
        if not self.session:
            return {"ok": False}
        members = sorted(self.session.group_drafts(group_key), key=lambda d: d.trigram_slot)
        if not members:
            return {"ok": False}
        self._editing_group = group_key
        self._trig_group_key = group_key
        self.kind = "trigram"
        self.trig_orientation = members[0].trigram_orientation
        self.cut_a = int(round(members[0].trigram_cut_a * 100))
        self.cut_b = int(round(members[0].trigram_cut_b * 100))
        self._trig_cover_src = ""       # original cover not retained
        self.trig_style = "carousels" if any(len(m.images) > 1 for m in members) else "single"
        self.trig_slots = [[O.DraftImage.from_dict(im.to_dict()) for im in m.images]
                           for m in members]
        post = self._post_fields(members[0])
        if self.trig_slots and self.trig_slots[0]:
            self._sel = (0, 0)
        return {"ok": True, "post": post, "compose": self.compose_state()}

    def _post_fields(self, d):
        return {
            "caption": d.caption, "tags": d.tags, "date": d.post_date,
            "status": d.img_status, "allow_comments": bool(d.allow_comments),
            "allow_dl": bool(d.allow_download), "download_url": d.download_url,
        }

    def delete(self, draft_id):
        if self.session:
            self.session.delete_draft(draft_id)
        return {"drafts": self._draft_views()}

    def delete_group(self, group_key):
        if self.session:
            for m in self.session.group_drafts(group_key):
                self.session.delete_draft(m.draft_id)
        return {"drafts": self._draft_views()}

    # -- commit -------------------------------------------------------------
    def _apply_post_fields(self, d, f):
        d.img_status = f.get("status") or "published"
        d.post_date = (f.get("date") or "").strip()
        d.allow_comments = bool(f.get("allow_comments"))
        d.allow_download = bool(f.get("allow_dl"))
        d.download_url = (f.get("download_url") or "").strip()
        d.panorama_rows = 1

    def commit(self, f, ready):
        if not self._ensure_session():
            return {"ok": False, "message": "No batch."}
        caption = (f.get("caption") or "").strip()
        tags = (f.get("tags") or "").strip()

        if self._editing_id:
            self.session.delete_draft(self._editing_id)
        if self._editing_group:
            for d in self.session.group_drafts(self._editing_group):
                self.session.delete_draft(d.draft_id)

        if self.kind == "trigram":
            if not all(self.trig_slots) or len(self.trig_slots) != 3:
                return {"ok": False, "message": "Choose a cover and slice it into three."}
            group_key = self._trig_group_key or O._new_id()
            for slot_idx, slot in enumerate(self.trig_slots, start=1):
                d = O.Draft(draft_id=O._new_id(), kind=O.KIND_GRAM_TRIGRAM, mode=self.SUITE_MODE,
                            caption=caption, tags=tags, group_key=group_key,
                            trigram_slot=slot_idx, trigram_orientation=self.trig_orientation)
                self._apply_post_fields(d, f)
                d.images = list(slot)
                O.generate_draft_thumbs(d)
                probs = d.validate()
                if ready and probs:
                    return {"ok": False, "message": "\n".join(probs)}
                d.status = O.ST_READY if ready else O.ST_DRAFT
                self.session.add_draft(d)
        else:
            imgs = list(self.work_images)
            if not imgs:
                return {"ok": False, "message": "Add at least one image."}
            dkind = O.KIND_GRAM_CAROUSEL if len(imgs) > 1 else O.KIND_GRAM_SINGLE
            d = O.Draft(draft_id=O._new_id(), kind=dkind, mode=self.SUITE_MODE,
                        caption=caption, tags=tags)
            self._apply_post_fields(d, f)
            d.images = imgs
            O.generate_draft_thumbs(d)
            probs = d.validate()
            if ready and probs:
                return {"ok": False, "message": "\n".join(probs)}
            d.status = O.ST_READY if ready else O.ST_DRAFT
            self.session.add_draft(d)

        big = ""
        if self.session.over_soft_limit():
            big = ("This batch now holds %d images (soft limit ~%d). It'll still sync "
                   "fine — but consider starting a new batch." %
                   (self.session.image_count(), O.SOFT_BATCH_IMAGE_LIMIT))
        self.clear_compose()
        return {"ok": True, "big_batch": big, "state": self.state()}

    def clear_compose(self):
        self.work_images = []
        self.trig_slots = [[], [], []]
        self._trig_group_key = ""
        self._trig_cover_src = ""
        self.cut_a = 33
        self.cut_b = 67
        self._sel = None
        self._editing_id = ""
        self._editing_group = ""
        return self.compose_state()

    # -- sync ---------------------------------------------------------------
    def sync_target(self):
        if not self.session:
            return {"ok": False, "message": "No batch."}
        cfg = _host._config or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("api_key") or "").strip()
        if not url or not key:
            return {"ok": False, "message": "Set the site URL + API key on CONNECTION first."}
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            return {"ok": False, "message": "Nothing marked OFFLINE POST yet — compose and commit first."}
        return {"ok": True, "count": len(ready), "host": _host_name(url), "url": url}

    def sync(self):
        if not _POST_OK:
            return {"ok": False, "message": "Posting unavailable: %s" % _POST_ERR}
        if not self.session:
            return {"ok": False, "message": "No batch."}
        cfg = _host._config or {}
        conn = SumnaConnection((cfg.get("url") or "").strip(), (cfg.get("api_key") or "").strip())
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            return {"ok": False, "message": "Nothing ready."}
        poster = GramPoster(conn)
        # TODO(port): per-item live badge streaming (after()) is not reproduced;
        # the whole batch runs and the final statuses come back via state().
        engine = O.SyncEngine(self.session, poster)
        results = engine.sync_all(ready)
        ok = sum(1 for r in results.values() if r.ok)
        return {"ok": True, "synced": ok, "total": len(results), "state": self.state()}


_solo = SoloController()
_gram = GramController()


# ═════════════════════════════════════════════════════════════════════════════
# @app.api glue — one thin handler per controller method (positional args).
# ═════════════════════════════════════════════════════════════════════════════
# -- COLD ONE (solo) --
@app.api
def solo_state():
    return _solo.state()


@app.api
def solo_select_session(session_id):
    return _solo.select_session(session_id)


@app.api
def solo_new_session(name):
    return _solo.new_session(name)


@app.api
def solo_export_session(dest_dir=""):
    return _solo.export_session(dest_dir)


@app.api
def solo_import_session(src_dir=""):
    return _solo.import_session(src_dir)


@app.api
def solo_edit(draft_id):
    return _solo.edit(draft_id)


@app.api
def solo_delete(draft_id):
    return _solo.delete(draft_id)


@app.api
def solo_choose_image(path=""):
    """Choose image… — native picker unless a pasted path is supplied."""
    if not path:
        picks = _native_pick(title="Choose image")
        if not picks:
            return {"ok": False}
        path = picks[0]
    if not os.path.isfile(path):
        return {"ok": False, "message": "That file doesn't exist."}
    return {"ok": True, "path": path,
            "preview": image_data_uri(path),
            "basename": os.path.splitext(os.path.basename(path))[0]}


@app.api
def solo_ai_fill(image_path):
    return _ai_fill(image_path)


@app.api
def solo_save_draft(fields, ready):
    return _solo.save_draft(fields, bool(ready))


@app.api
def solo_sync_target():
    return _solo.sync_target()


@app.api
def solo_sync():
    return _solo.sync()


# -- COLD STACK (gram) --
@app.api
def gram_state():
    return _gram.state()


@app.api
def gram_select_session(session_id):
    return _gram.select_session(session_id)


@app.api
def gram_new_session(name):
    return _gram.new_session(name)


@app.api
def gram_export_session(dest_dir=""):
    return _gram.export_session(dest_dir)


@app.api
def gram_import_session(src_dir=""):
    return _gram.import_session(src_dir)


@app.api
def gram_set_kind(kind):
    return _gram.set_kind(kind)


@app.api
def gram_set_trig_style(style):
    return _gram.set_trig_style(style)


@app.api
def gram_set_trig_orientation(o):
    return _gram.set_trig_orientation(o)


@app.api
def gram_add_images(paths=None):
    return _gram.add_images(paths)


@app.api
def gram_clear_images():
    return _gram.clear_images()


@app.api
def gram_slice_cover(path="", cut_a=None, cut_b=None, orientation=None):
    return _gram.slice_cover(path or None, cut_a, cut_b, orientation)


@app.api
def gram_reslice(cut_a=None, cut_b=None):
    return _gram.reslice(cut_a, cut_b)


@app.api
def gram_add_to_slot(slot_idx, paths=None):
    return _gram.add_to_slot(int(slot_idx), paths)


@app.api
def gram_select_image(slot, idx):
    return _gram.select_image(None if slot is None else int(slot), int(idx))


@app.api
def gram_move(slot, idx, delta):
    return _gram.move(None if slot is None else int(slot), int(idx), int(delta))


@app.api
def gram_remove(slot, idx):
    return _gram.remove(None if slot is None else int(slot), int(idx))


@app.api
def gram_write_controls(controls):
    return _gram.write_controls(controls)


@app.api
def gram_recrop(controls):
    return _gram.recrop(controls)


@app.api
def gram_compose_state():
    return _gram.compose_state()


@app.api
def gram_edit_single(draft_id):
    return _gram.edit_single(draft_id)


@app.api
def gram_edit_trigram(group_key):
    return _gram.edit_trigram(group_key)


@app.api
def gram_delete(draft_id):
    return _gram.delete(draft_id)


@app.api
def gram_delete_group(group_key):
    return _gram.delete_group(group_key)


@app.api
def gram_commit(fields, ready):
    return _gram.commit(fields, bool(ready))


@app.api
def gram_clear_compose():
    return _gram.clear_compose()


@app.api
def gram_sync_target():
    return _gram.sync_target()


@app.api
def gram_sync():
    return _gram.sync()


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====

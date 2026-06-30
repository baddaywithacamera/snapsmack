"""
SON OF A BATCH — sob_offline.py
Shared offline-first engine for the SnapSmack desktop posting suite.

This module is GUI-free and headless-testable. It owns:
  * the flat-file draft model (a draft JSON == a SnapSmack DB row waiting to
    be inserted) with a schema/build-version stamp;
  * sessions (a first-class, resumable working set of drafts + their images);
  * client-side thumbnail generation via the shared snap_thumbs module
    (300² square + 600px aspect @ q85 — dimensionally identical to the CMS);
  * the 3:1 trigram-cover slicer (cuts one cover into three chunks offline,
    so a trigram syncs as one complete group);
  * thumb-drive / disk export + import (a versioned, self-contained folder);
  * the store-and-forward SyncEngine with POSITIVE verification — a draft is
    only marked synced after the live post is pulled back and compared
    (the SYBU lesson: never infer success from no-error);
  * the mode-filtered install picker (classify connection profiles by the
    install's site_mode so a carousel can never be pushed at a solo site).

The actual HTTP transport (solo post, gram upload/post/trigram, verify, the
site_mode probe) lives in sob_post.py and is injected into the SyncEngine, so
this module stays pure and testable with a fake poster.

Build order (spec v0.4): BATCH SLAPPED (solo) + BATCH, PLEASE (gram) ship
first; SMACK YOUR BATCH UP (longform) is deferred.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import shutil
import sys
import time
import uuid
from dataclasses import dataclass, field, asdict
from datetime import datetime
from typing import Callable, Dict, List, Optional, Tuple

try:
    # Shared, build-once thumbnailer (tools/_shared/snap_thumbs.py). On the
    # frozen exe it is bundled flat next to this module; in dev it lives one
    # directory up under _shared/. Try both.
    import snap_thumbs
except ImportError:  # pragma: no cover - import shim for dev tree
    _shared = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '_shared')
    if _shared not in sys.path:
        sys.path.insert(0, _shared)
    import snap_thumbs


# ---------------------------------------------------------------------------
# Versioning — every draft and every export carries these so an old artifact
# is validated/migrated instead of inserted wrong.
# ---------------------------------------------------------------------------

SCHEMA_VERSION = 1          # draft-JSON structure version
BUILD_VERSION  = "0.1.0"    # SON OF A BATCH suite build
EXPORT_MANIFEST_VERSION = 1  # thumb-drive export folder format

# Draft kinds — each maps to a concrete SnapSmack post shape.
KIND_SOLO          = "solo"            # SMACKONEOUT single-image post
KIND_GRAM_SINGLE   = "gram_single"     # GRAMOFSMACK single image
KIND_GRAM_CAROUSEL = "gram_carousel"   # GRAMOFSMACK multi-image carousel (<=10)
KIND_GRAM_TRIGRAM  = "gram_trigram"    # GRAMOFSMACK trigram slice/chunk

GRAM_KINDS = (KIND_GRAM_SINGLE, KIND_GRAM_CAROUSEL, KIND_GRAM_TRIGRAM)

# Sync-status machine for a draft.
ST_DRAFT   = "draft"     # being composed
ST_READY   = "ready"     # user marked it ready to push
ST_SYNCING = "syncing"   # in flight
ST_SYNCED  = "synced"    # pushed + verified
ST_FAILED  = "failed"    # push or verify failed; error retained
ST_QUEUED  = "queued"    # trigram chunk waiting on its siblings (mirrors trigram_ready_count)

# Site modes (snap_settings.site_mode values) and the suite mode that serves them.
MODE_SOLO       = "photoblog"    # BATCH SLAPPED
MODE_GRAM       = "carousel"     # BATCH, PLEASE
MODE_SMACKTALK  = "smacktalk"    # SMACK YOUR BATCH UP (deferred)
MODE_UNKNOWN    = "unknown"      # couldn't verify — show greyed, don't hide

CAROUSEL_MAX_IMAGES = 10
TRIGRAM_GROUP_SIZE  = 3

# Soft per-batch image cap — a compromise on the server's import "fire-hose"
# guard. It's SOFT (a warning, never a block) because a trigram group can push
# the count a few images past the line and shouldn't be guillotined mid-group.
# Compose as many batches (sessions) as you like; each stays around this size.
SOFT_BATCH_IMAGE_LIMIT = 50

MAX_FULL_EDGE = 1900  # full-res long edge the server accepts (matches SYBU web size)


def _now_iso() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def _new_id() -> str:
    return uuid.uuid4().hex[:12]


# ---------------------------------------------------------------------------
# Image manifest entry — a local image plus its generated thumbs and the
# remote path the server assigns once uploaded.
# ---------------------------------------------------------------------------

@dataclass
class DraftImage:
    local_path:   str = ""    # absolute local path to the full-res image
    filename:     str = ""    # basename used on upload
    width:        int = 0
    height:       int = 0
    thumb_square: str = ""    # local t_ thumb (400²)        — client-generated
    thumb_aspect: str = ""    # local a_ thumb (400px long)  — client-generated
    remote_path:  str = ""    # img_uploads/YYYY/MM/... assigned by the server on sync
    remote_thumb_square: str = ""   # server thumb path after upload
    remote_thumb_aspect: str = ""
    sort_position: int = 0    # carousel order (0-based)
    is_cover:     bool = False
    # Per-image GRAM controls — map 1:1 to snap_post_images columns. These are
    # EXACTLY the controls the web gram poster (smack-post-gram.php) exposes.
    crop_mode:    str = "fit"      # 'fit' (image-in-tile) | 'fill' (square cover-crop)
    size_pct:     int = 100        # 10-100 (fit only)
    border_px:    int = 0          # 0-50  (fit only)
    border_color: str = "#000000"
    bg_color:     str = "#ffffff"  # matte behind a fit image
    shadow:       int = 0          # 0-3   (fit only)
    focus_x:      int = 50         # 0-100 square-crop focal point — bakes into the t_ thumb
    focus_y:      int = 50         # 0-100
    zoom:         int = 100        # 100-300 square-crop zoom — bakes into the t_ thumb
    split:        bool = False     # post this image as its own separate post

    def to_dict(self) -> dict:
        return asdict(self)

    @classmethod
    def from_dict(cls, d: dict) -> "DraftImage":
        return cls(**{k: d.get(k, getattr(cls, k, "")) for k in cls.__dataclass_fields__})


# ---------------------------------------------------------------------------
# Draft — one JSON file == one DB row waiting to be inserted.
# ---------------------------------------------------------------------------

@dataclass
class Draft:
    draft_id:   str
    kind:       str                       # KIND_*
    mode:       str                       # MODE_SOLO / MODE_GRAM
    status:     str = ST_DRAFT
    # Post fields (superset; only the ones relevant to `kind` are used on sync).
    title:      str = ""
    caption:    str = ""                  # body / description
    tags:       str = ""                  # space-separated #hashtags
    post_date:  str = ""                  # "YYYY-MM-DD HH:MM:SS"; blank => now on sync
    img_status: str = "published"         # published | draft (server-side post status)
    # Solo extras (mirror smack-post-solo.php form fields).
    category:   str = ""
    album:      str = ""
    orientation: str = "auto"
    allow_download: bool = False
    download_url: str = ""
    ai_colors:  str = ""                  # space-separated hex codes
    # Gram post-level fields (map to snap_posts).
    allow_comments: bool = True
    panorama_rows: int = 1                # 1-3 (panorama post_type only)
    post_type:  str = ""                  # '', 'single', 'carousel', 'panorama' (derived if blank)
    # Images.
    images:     List[DraftImage] = field(default_factory=list)
    # Trigram grouping (KIND_GRAM_TRIGRAM only).
    group_key:    str = ""                # local key tying the 3 chunks together
    trigram_slot: int = 0                 # 1, 2 or 3 (L/M/R or T/M/B)
    trigram_orientation: str = "h"        # 'h' or 'v'
    trigram_cut_a: float = 1.0 / 3.0      # first seam, fraction of the long axis (0-1)
    trigram_cut_b: float = 2.0 / 3.0      # second seam, fraction of the long axis (0-1)
    # Sync bookkeeping.
    remote_post_id: int = 0               # filled after a successful create
    remote_trigram_id: int = 0
    error:        str = ""
    created_at:   str = field(default_factory=_now_iso)
    updated_at:   str = field(default_factory=_now_iso)
    # Provenance stamp — lets an old draft be validated/migrated, never inserted wrong.
    schema_version: int = SCHEMA_VERSION
    build_version:  str = BUILD_VERSION

    # -- serialization ------------------------------------------------------
    def to_dict(self) -> dict:
        d = asdict(self)
        d["images"] = [im.to_dict() for im in self.images]
        return d

    @classmethod
    def from_dict(cls, d: dict) -> "Draft":
        d = migrate_draft_dict(dict(d))
        imgs = [DraftImage.from_dict(i) for i in d.get("images", [])]
        known = {k: d.get(k) for k in cls.__dataclass_fields__ if k in d and k != "images"}
        obj = cls(**known)
        obj.images = imgs
        return obj

    # -- helpers ------------------------------------------------------------
    def touch(self) -> None:
        self.updated_at = _now_iso()

    def cover(self) -> Optional[DraftImage]:
        for im in self.images:
            if im.is_cover:
                return im
        return self.images[0] if self.images else None

    def validate(self) -> List[str]:
        """Return a list of human-readable problems; empty == ok to sync."""
        problems: List[str] = []
        if self.kind not in (KIND_SOLO,) + GRAM_KINDS:
            problems.append(f"unknown draft kind '{self.kind}'")
        if not self.images:
            problems.append("no images attached")
        for i, im in enumerate(self.images):
            if not im.local_path or not os.path.isfile(im.local_path):
                problems.append(f"image {i + 1} missing on disk: {im.local_path}")
        if self.kind == KIND_GRAM_CAROUSEL and len(self.images) > CAROUSEL_MAX_IMAGES:
            problems.append(f"carousel has {len(self.images)} images (max {CAROUSEL_MAX_IMAGES})")
        if self.kind == KIND_GRAM_TRIGRAM:
            if self.trigram_slot not in (1, 2, 3):
                problems.append("trigram chunk has no valid slot (1-3)")
            if self.trigram_orientation not in ("h", "v"):
                problems.append("trigram orientation must be 'h' or 'v'")
            # A slot is a single sliced image OR a carousel whose COVER is the
            # slice (cover at sort 0, extra images after). At least one image,
            # exactly one cover.
            if not self.images:
                problems.append("a trigram chunk needs at least the sliced cover image")
            elif sum(1 for im in self.images if im.is_cover) != 1:
                problems.append("a trigram chunk must have exactly one cover (the slice)")
        if len(self.images) > CAROUSEL_MAX_IMAGES:
            problems.append(f"{len(self.images)} images exceeds the max of {CAROUSEL_MAX_IMAGES}")
        if self.kind == KIND_SOLO and self.img_status == "published" \
                and self.allow_download and not self.download_url:
            problems.append("download enabled but no download URL set")
        return problems


# ---------------------------------------------------------------------------
# Draft migration — bridge old schema stamps forward instead of inserting wrong.
# ---------------------------------------------------------------------------

def migrate_draft_dict(d: dict) -> dict:
    """Bring a draft dict up to the current SCHEMA_VERSION. Idempotent."""
    v = int(d.get("schema_version", 0) or 0)
    # (No breaking versions yet — v0/missing is treated as v1-compatible.)
    if v < 1:
        d.setdefault("status", ST_DRAFT)
        d.setdefault("img_status", "published")
        d["schema_version"] = 1
    return d


# ---------------------------------------------------------------------------
# DB-mirror sidecar — render a draft as the EXACT SnapSmack DB rows it becomes
# on sync, using the real table + column names (and the same value logic the
# gram/post and solo endpoints apply). Written next to each draft as a
# <draft_id>.dbrows.json sidecar so a recovery tool (e.g. MIDNIGHT MOVE) can
# rebuild the database straight from disk if a sync gets bitched up. Local
# string refs ("post", "img_0"…) stand in for the auto-increment IDs the server
# assigns; real remote IDs are filled in after a successful sync.
# ---------------------------------------------------------------------------

DBROWS_SUFFIX = ".dbrows.json"


def _img_orientation(w: int, h: int) -> int:
    """0 = landscape, 1 = portrait, 2 = square (matches the server)."""
    return 1 if h > w else (2 if w == h else 0)


def draft_db_rows(draft: Draft) -> dict:
    base_name = lambda im: im.filename or os.path.basename(im.local_path or "")

    meta = {
        "draft_id": draft.draft_id,
        "kind": draft.kind,
        "mode": draft.mode,
        "status": draft.status,
        "schema_version": SCHEMA_VERSION,
        "build_version": BUILD_VERSION,
        "remote_post_id": draft.remote_post_id or None,
        "remote_trigram_id": draft.remote_trigram_id or None,
    }

    # ---- SOLO: smack-post-solo.php is image-centric; capture the row + the
    #      exact form payload it injects. ----------------------------------
    if draft.kind == KIND_SOLO:
        im = draft.cover()
        meta["endpoint"] = "smack-post-solo.php"
        img_row = {}
        if im:
            img_row = {
                "img_slug": "",
                "img_file": im.remote_path or base_name(im),
                "img_title": draft.title,
                "img_description": draft.caption,
                "img_status": draft.img_status,
                "img_date": draft.post_date,
                "img_width": im.width,
                "img_height": im.height,
                "img_orientation": _img_orientation(im.width, im.height),
                "img_thumb_square": im.remote_thumb_square or ("thumbs/t_" + base_name(im)),
                "img_thumb_aspect": im.remote_thumb_aspect or ("thumbs/a_" + base_name(im)),
                "img_source_file": base_name(im),
                "img_ai_colors": draft.ai_colors,
                "allow_download": 1 if draft.allow_download else 0,
                "download_url": draft.download_url,
            }
        return {
            "_meta": meta,
            "snap_images": {"img_0": img_row} if img_row else {},
            "_post_form": {
                "title": draft.title, "tags": draft.tags,
                "img_status": draft.img_status, "desc": draft.caption,
                "allow_download": 1 if draft.allow_download else 0,
                "download_url": draft.download_url,
                "orientation_override": draft.orientation,
                "img_ai_colors": draft.ai_colors,
                "category": draft.category, "album": draft.album,
            },
        }

    # ---- GRAM (single / carousel / trigram chunk): exact snap_* rows. ------
    meta["endpoint"] = "api.php?route=threeacross/gram/post"

    def _img_row(im, idx):
        return {
            "img_slug": "",
            "img_file": im.remote_path or base_name(im),
            "img_title": "",
            "img_description": draft.caption,
            "img_date": draft.post_date,
            "img_width": im.width,
            "img_height": im.height,
            "img_orientation": _img_orientation(im.width, im.height),
            "img_thumb_square": im.remote_thumb_square or ("thumbs/t_" + base_name(im)),
            "img_thumb_aspect": im.remote_thumb_aspect or ("thumbs/a_" + base_name(im)),
            "img_source_file": "sob:" + base_name(im),
            "img_status": draft.img_status,
            "sort_order": idx,
            "allow_comments": 1 if draft.allow_comments else 0,
            "allow_download": 1 if draft.allow_download else 0,
            "download_url": draft.download_url,
        }

    def _pivot_row(im, idx):
        fill = im.crop_mode == "fill"
        return {
            "post_ref": "post",
            "image_ref": f"img_{idx}",
            "sort_position": idx,
            "is_cover": 1 if im.is_cover else 0,
            "img_size_pct": 100 if fill else im.size_pct,
            "img_border_px": 0 if fill else im.border_px,
            "img_border_color": "#000000" if fill else im.border_color,
            "img_bg_color": "#ffffff" if fill else im.bg_color,
            "img_shadow": 0 if fill else im.shadow,
            "img_crop_mode": "fill" if fill else "fit",
            "img_focus_x": im.focus_x,
            "img_focus_y": im.focus_y,
            "img_zoom": im.zoom,
        }

    n = len(draft.images)
    ptype = draft.post_type or ("single" if n <= 1 else "carousel")
    post_row = {
        "title": draft.title,
        "slug": "",
        "description": draft.caption,
        "post_type": ptype,
        "status": draft.img_status,
        "created_at": draft.post_date,
        "allow_comments": 1 if draft.allow_comments else 0,
        "allow_download": 1 if draft.allow_download else 0,
        "download_url": draft.download_url,
        "panorama_rows": draft.panorama_rows,
        "post_img_size_pct": 100,
        "post_border_px": 0,
        "post_border_color": "#000000",
        "post_bg_color": "#ffffff",
        "post_shadow": 0,
        "trigram_id": "trigram" if draft.kind == KIND_GRAM_TRIGRAM else None,
    }

    tag_list = [t.lstrip("#") for t in draft.tags.split() if t.strip()]
    rows = {
        "_meta": meta,
        "snap_posts": {"post": post_row},
        "snap_images": {f"img_{i}": _img_row(im, i) for i, im in enumerate(draft.images)},
        "snap_post_images": [_pivot_row(im, i) for i, im in enumerate(draft.images)],
        "snap_image_tags": [
            {"image_ref": f"img_{i}", "tag": t}
            for i in range(len(draft.images)) for t in tag_list
        ],
    }
    if draft.kind == KIND_GRAM_TRIGRAM:
        # The trigram row belongs to the GROUP — a recovery tool collects all
        # three sidecars sharing group_key and fills post_id_<slot> from each.
        rows["snap_trigrams"] = {
            "group_key": draft.group_key,
            "trigram_type": "group",
            "orientation": draft.trigram_orientation,
            "source_path": None,
            "cut_a": None,
            "cut_b": None,
            "slot": draft.trigram_slot,
            "post_ref": "post",
        }
    return rows


# ---------------------------------------------------------------------------
# Thumbnails — generate the CMS-identical t_/a_ pair for every image in a draft.
# ---------------------------------------------------------------------------

def generate_draft_thumbs(draft: Draft, *, sq_size: int = 400, asp_max: int = 400) -> Draft:
    """
    Populate width/height/thumb_square/thumb_aspect for each image using the
    shared snap_thumbs port, baking each image's focal point + zoom into the
    square thumb. Sizes match the server generator EXACTLY (400² square + 400px
    longest-edge aspect @ q85 — the size smack-post-gram.php and threeacross-api.php
    actually produce), so the client thumbs are a true drop-in: the server saves
    them and skips its own GD pass. Client thumbs are mandatory.
    """
    for im in draft.images:
        if not im.local_path or not os.path.isfile(im.local_path):
            continue
        res = snap_thumbs.generate_thumbs(
            im.local_path, sq_size=sq_size, asp_max=asp_max,
            focus_x=im.focus_x, focus_y=im.focus_y, zoom=im.zoom)
        if res:
            im.width = res["width"]
            im.height = res["height"]
            im.thumb_square = res["sq_path"]
            im.thumb_aspect = res["asp_path"]
            if not im.filename:
                im.filename = os.path.basename(im.local_path)
    draft.touch()
    return draft


# ---------------------------------------------------------------------------
# Trigram cover slicer — cut one 3:1 (h) or 1:3 (v) cover into three chunks,
# write them to disk, and return three grouped trigram-chunk drafts. The whole
# group exists the moment it's created offline, so it syncs as one unit and the
# server's trigram_check_and_publish promotes it exactly like the Unzucker path.
# ---------------------------------------------------------------------------

def _clamp_cuts(cut_a, cut_b) -> Tuple[float, float]:
    """Normalize two seam fractions to a sane, ordered pair in (0,1)."""
    fa = 1.0 / 3.0 if cut_a is None else float(cut_a)
    fb = 2.0 / 3.0 if cut_b is None else float(cut_b)
    fa = max(0.05, min(0.90, fa))
    fb = max(fa + 0.05, min(0.95, fb))
    return fa, fb


def slice_trigram_cover(
    src_path: str,
    out_dir: str,
    *,
    orientation: str = "h",
    mode: str = MODE_GRAM,
    caption: str = "",
    tags: str = "",
    cut_a: Optional[float] = None,
    cut_b: Optional[float] = None,
    group_key: Optional[str] = None,
) -> List[Draft]:
    """
    Slice `src_path` into three KIND_GRAM_TRIGRAM chunks sharing one group_key,
    slots 1/2/3. orientation 'h' = left/mid/right, 'v' = top/mid/bottom.

    cut_a / cut_b are the two seam positions as fractions of the long axis
    (defaults 1/3, 2/3 = even thirds). For an irregular (non-3:1) cover, set the
    seams where they look right; each strip is then cropped to a square tile
    (crop_mode='fill', focal/zoom adjustable per tile afterwards). Pass an
    existing group_key to re-slice in place (keeps the group identity stable).
    """
    from PIL import Image as _PILImage  # local import keeps headless tests light

    if orientation not in ("h", "v"):
        orientation = "h"
    os.makedirs(out_dir, exist_ok=True)
    fa, fb = _clamp_cuts(cut_a, cut_b)

    with _PILImage.open(src_path) as im:
        im.load()
        src = im.convert("RGB")
    w, h = src.size

    if orientation == "h":
        xa, xb = int(round(w * fa)), int(round(w * fb))
        boxes = [(0, 0, xa, h), (xa, 0, xb, h), (xb, 0, w, h)]
    else:
        ya, yb = int(round(h * fa)), int(round(h * fb))
        boxes = [(0, 0, w, ya), (0, ya, w, yb), (0, yb, w, h)]

    group_key = group_key or _new_id()
    base = os.path.splitext(os.path.basename(src_path))[0]
    drafts: List[Draft] = []
    for slot, box in enumerate(boxes, start=1):
        chunk = src.crop(box)
        chunk_name = f"{base}_trig_{group_key}_{slot}.jpg"
        chunk_path = os.path.join(out_dir, chunk_name)
        chunk.save(chunk_path, "JPEG", quality=92)

        d = Draft(
            draft_id=_new_id(),
            kind=KIND_GRAM_TRIGRAM,
            mode=mode,
            caption=caption,
            tags=tags,
            group_key=group_key,
            trigram_slot=slot,
            trigram_orientation=orientation,
            trigram_cut_a=fa,
            trigram_cut_b=fb,
        )
        # Trigram covers fill the square tile so the 3-across band reads as one
        # image (a 'fit' default would letterbox an off-square strip).
        d.images = [DraftImage(local_path=chunk_path, filename=chunk_name,
                               sort_position=0, is_cover=True, crop_mode="fill")]
        generate_draft_thumbs(d)
        drafts.append(d)
    return drafts


def trigram_ready_count(drafts: List[Draft], group_key: str) -> int:
    """How many of a trigram group's three chunks exist locally (0-3) —
    mirrors the server trigram_ready_count() for 'waiting for N more' UI."""
    slots = {d.trigram_slot for d in drafts
             if d.kind == KIND_GRAM_TRIGRAM and d.group_key == group_key
             and d.trigram_slot in (1, 2, 3)}
    return len(slots)


# ---------------------------------------------------------------------------
# Sessions — a resumable working set. One directory holding draft JSON files,
# an images/ store, and a session manifest. Sessions are first-class.
# ---------------------------------------------------------------------------

def _app_dir() -> str:
    """Persistent dir — next to the exe when frozen, source dir otherwise."""
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


SESSIONS_ROOT = os.path.join(_app_dir(), "sob_sessions")


class Session:
    """A single working session: drafts + their images under one folder."""

    def __init__(self, path: str, meta: dict):
        self.path = path
        self.meta = meta

    @property
    def session_id(self) -> str:
        return self.meta.get("session_id", os.path.basename(self.path))

    @property
    def name(self) -> str:
        return self.meta.get("name", self.session_id)

    @property
    def mode(self) -> str:
        return self.meta.get("mode", "")

    @property
    def drafts_dir(self) -> str:
        return os.path.join(self.path, "drafts")

    @property
    def images_dir(self) -> str:
        return os.path.join(self.path, "images")

    def _manifest_path(self) -> str:
        return os.path.join(self.path, "session.json")

    def save_meta(self) -> None:
        self.meta["updated_at"] = _now_iso()
        with open(self._manifest_path(), "w", encoding="utf-8") as f:
            json.dump(self.meta, f, indent=2, ensure_ascii=False)

    # -- draft CRUD ---------------------------------------------------------
    def _draft_path(self, draft_id: str) -> str:
        return os.path.join(self.drafts_dir, f"{draft_id}.json")

    def add_draft(self, draft: Draft, copy_images: bool = True) -> Draft:
        """Add a draft to the session. Optionally copy its images (and thumbs)
        into the session's images/ store so the session is self-contained."""
        os.makedirs(self.drafts_dir, exist_ok=True)
        os.makedirs(self.images_dir, exist_ok=True)
        if copy_images:
            self._absorb_images(draft)
        self.save_draft(draft)
        return draft

    def _absorb_images(self, draft: Draft) -> None:
        thumbs_dir = os.path.join(self.images_dir, "thumbs")
        os.makedirs(thumbs_dir, exist_ok=True)
        for im in draft.images:
            for attr, dest_dir in (("local_path", self.images_dir),
                                   ("thumb_square", thumbs_dir),
                                   ("thumb_aspect", thumbs_dir)):
                src = getattr(im, attr)
                if src and os.path.isfile(src):
                    dst = os.path.join(dest_dir, os.path.basename(src))
                    if os.path.abspath(src) != os.path.abspath(dst):
                        shutil.copy2(src, dst)
                    setattr(im, attr, dst)

    def _dbrows_path(self, draft_id: str) -> str:
        return os.path.join(self.drafts_dir, f"{draft_id}{DBROWS_SUFFIX}")

    def save_draft(self, draft: Draft) -> None:
        os.makedirs(self.drafts_dir, exist_ok=True)
        draft.touch()
        tmp = self._draft_path(draft.draft_id) + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(draft.to_dict(), f, indent=2, ensure_ascii=False)
        os.replace(tmp, self._draft_path(draft.draft_id))  # atomic write
        # DB-mirror recovery sidecar (kept in lock-step with the draft).
        tmp2 = self._dbrows_path(draft.draft_id) + ".tmp"
        with open(tmp2, "w", encoding="utf-8") as f:
            json.dump(draft_db_rows(draft), f, indent=2, ensure_ascii=False)
        os.replace(tmp2, self._dbrows_path(draft.draft_id))

    def load_draft(self, draft_id: str) -> Optional[Draft]:
        p = self._draft_path(draft_id)
        if not os.path.isfile(p):
            return None
        with open(p, "r", encoding="utf-8") as f:
            return Draft.from_dict(json.load(f))

    def list_drafts(self) -> List[Draft]:
        out: List[Draft] = []
        if not os.path.isdir(self.drafts_dir):
            return out
        for fn in sorted(os.listdir(self.drafts_dir)):
            if fn.endswith(".json") and not fn.endswith(DBROWS_SUFFIX):
                try:
                    with open(os.path.join(self.drafts_dir, fn), "r", encoding="utf-8") as f:
                        out.append(Draft.from_dict(json.load(f)))
                except Exception:
                    pass
        return out

    def delete_draft(self, draft_id: str) -> None:
        for p in (self._draft_path(draft_id), self._dbrows_path(draft_id)):
            if os.path.isfile(p):
                os.remove(p)

    def group_drafts(self, group_key: str) -> List[Draft]:
        return [d for d in self.list_drafts() if d.group_key == group_key]

    def image_count(self) -> int:
        """Total images across all drafts in this batch (for the soft cap)."""
        return sum(len(d.images) for d in self.list_drafts())

    def over_soft_limit(self) -> bool:
        return self.image_count() > SOFT_BATCH_IMAGE_LIMIT


class SessionStore:
    """Discovery + lifecycle for sessions under SESSIONS_ROOT."""

    def __init__(self, root: str = SESSIONS_ROOT):
        self.root = root
        os.makedirs(self.root, exist_ok=True)

    def create(self, name: str, mode: str) -> Session:
        sid = _new_id()
        path = os.path.join(self.root, sid)
        os.makedirs(os.path.join(path, "drafts"), exist_ok=True)
        os.makedirs(os.path.join(path, "images"), exist_ok=True)
        meta = {
            "session_id": sid,
            "name": name or f"Session {datetime.now():%Y-%m-%d %H:%M}",
            "mode": mode,
            "schema_version": SCHEMA_VERSION,
            "build_version": BUILD_VERSION,
            "created_at": _now_iso(),
            "updated_at": _now_iso(),
        }
        s = Session(path, meta)
        s.save_meta()
        return s

    def load(self, session_id: str) -> Optional[Session]:
        path = os.path.join(self.root, session_id)
        mp = os.path.join(path, "session.json")
        if not os.path.isfile(mp):
            return None
        with open(mp, "r", encoding="utf-8") as f:
            return Session(path, json.load(f))

    def list(self) -> List[Session]:
        out: List[Session] = []
        for sid in sorted(os.listdir(self.root)):
            s = self.load(sid)
            if s:
                out.append(s)
        return out

    def delete(self, session_id: str) -> None:
        path = os.path.join(self.root, session_id)
        if os.path.isdir(path):
            shutil.rmtree(path, ignore_errors=True)


# ---------------------------------------------------------------------------
# Thumb-drive / disk export + import — a self-contained, versioned folder.
# ---------------------------------------------------------------------------

def export_session(session: Session, dest_dir: str) -> str:
    """
    Copy a whole session to dest_dir as a self-contained, versioned export
    (drafts + images + a manifest). Returns the export folder path. Another
    machine running the suite can import it and keep working / syncing.
    """
    os.makedirs(dest_dir, exist_ok=True)
    out = os.path.join(dest_dir, f"sob_export_{session.session_id}")
    if os.path.isdir(out):
        shutil.rmtree(out, ignore_errors=True)
    # Self-contained copy: drafts (JSON = DB rows) + every image + its thumbs.
    shutil.copytree(session.path, out)

    drafts = session.list_drafts()
    # A readable per-post summary so the export is auditable on any machine and
    # the recovery state (what's synced vs still waiting) is visible without the
    # tool. Synced/failed/ready are all preserved so a re-import can resume.
    summary = []
    for d in drafts:
        summary.append({
            "draft_id": d.draft_id,
            "kind": d.kind,
            "status": d.status,
            "title": d.title,
            "caption": (d.caption[:80] + "…") if len(d.caption) > 80 else d.caption,
            "images": [os.path.basename(im.local_path) for im in d.images],
            "trigram_group": d.group_key or None,
            "trigram_slot": d.trigram_slot or None,
            "remote_post_id": d.remote_post_id or None,
            "error": d.error or None,
        })
    manifest = {
        "manifest_version": EXPORT_MANIFEST_VERSION,
        "schema_version": SCHEMA_VERSION,
        "build_version": BUILD_VERSION,
        "session_id": session.session_id,
        "name": session.name,
        "mode": session.mode,
        "exported_at": _now_iso(),
        "draft_count": len(drafts),
        "image_count": session.image_count(),
        "posts": summary,
    }
    with open(os.path.join(out, "export-manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2, ensure_ascii=False)

    # Human-readable recovery note (so anyone finding the drive knows what it is).
    n_ready  = sum(1 for d in drafts if d.status == ST_READY)
    n_synced = sum(1 for d in drafts if d.status == ST_SYNCED)
    n_failed = sum(1 for d in drafts if d.status == ST_FAILED)
    with open(os.path.join(out, "RECOVERY.txt"), "w", encoding="utf-8") as f:
        f.write(
            "SON OF A BATCH — recoverable batch export\n"
            "=========================================\n\n"
            f"Batch:        {session.name}\n"
            f"Mode:         {session.mode}\n"
            f"Exported:     {manifest['exported_at']}\n"
            f"Build:        {BUILD_VERSION} (schema v{SCHEMA_VERSION}, manifest v{EXPORT_MANIFEST_VERSION})\n"
            f"Posts:        {len(drafts)}  ({n_ready} ready, {n_synced} synced, {n_failed} failed)\n"
            f"Images:       {manifest['image_count']}\n\n"
            "This folder is a complete, self-contained copy of an offline posting\n"
            "batch: every draft (a JSON file that maps 1:1 to the database row),\n"
            "every full-resolution image, and the client-generated thumbnails.\n\n"
            "TO RECOVER / CONTINUE ON ANOTHER MACHINE:\n"
            "  1. Open SON OF A BATCH (the SYBU suite) -> BATCH, PLEASE (or BATCH SLAPPED).\n"
            "  2. Click 'Import...' and choose THIS folder.\n"
            "  3. The batch loads with every post and its sync state intact —\n"
            "     already-synced posts stay done, the rest are ready to push.\n"
            "  4. Click 'SYNC WITH LIVE' when you have a connection.\n\n"
            "Nothing here talks to a server. It is safe to copy, back up, or move\n"
            "between machines. See export-manifest.json for the full post list.\n\n"
            "DATABASE RECOVERY:\n"
            "  Every draft in drafts/ has a matching <id>.dbrows.json sidecar that\n"
            "  mirrors the exact SnapSmack tables and columns the post becomes on\n"
            "  the live server (snap_posts, snap_images, snap_post_images,\n"
            "  snap_image_tags, and snap_trigrams for trigrams). A recovery tool\n"
            "  (MIDNIGHT MOVE) can rebuild the database straight from these files —\n"
            "  local refs ('post', 'img_0'...) stand in for auto-increment IDs, and\n"
            "  real server IDs appear once a post has been synced.\n"
        )
    return out


def import_session(export_dir: str, store: SessionStore) -> Session:
    """Import an exported session folder into `store`, returning the new Session.
    Rejects an export whose manifest version is newer than this build understands."""
    mp = os.path.join(export_dir, "export-manifest.json")
    if not os.path.isfile(mp):
        raise ValueError("Not a SON OF A BATCH export (no export-manifest.json).")
    with open(mp, "r", encoding="utf-8") as f:
        manifest = json.load(f)
    if int(manifest.get("manifest_version", 0)) > EXPORT_MANIFEST_VERSION:
        raise ValueError(
            "This export was written by a newer build. Update SON OF A BATCH to import it."
        )
    sid = _new_id()  # fresh local id so imports never collide
    dest = os.path.join(store.root, sid)
    shutil.copytree(export_dir, dest)
    # Reset identity + drop the export manifest from the live copy.
    em = os.path.join(dest, "export-manifest.json")
    if os.path.isfile(em):
        os.remove(em)
    s = store.load(sid) or Session(dest, {})
    s.meta["session_id"] = sid
    s.meta.setdefault("mode", manifest.get("mode", ""))
    s.meta["name"] = manifest.get("name", sid) + " (imported)"
    s.meta["imported_at"] = _now_iso()
    s.save_meta()
    # Re-root every absorbed image path to the new session dir.
    _rebase_session_images(s)
    return s


def _rebase_session_images(session: Session) -> None:
    """Point each draft's image/thumb paths at this session's images dir."""
    for d in session.list_drafts():
        changed = False
        for im in d.images:
            for attr in ("local_path", "thumb_square", "thumb_aspect"):
                val = getattr(im, attr)
                if not val:
                    continue
                base = os.path.basename(val)
                sub = "thumbs" if attr != "local_path" else ""
                cand = os.path.join(session.images_dir, sub, base) if sub \
                    else os.path.join(session.images_dir, base)
                if os.path.isfile(cand) and os.path.abspath(cand) != os.path.abspath(val):
                    setattr(im, attr, cand)
                    changed = True
        if changed:
            session.save_draft(d)


# ---------------------------------------------------------------------------
# Mode-filtered install picker — classify connection profiles by the install's
# site_mode so a carousel can never be pushed at a solo site and vice versa.
# An unreachable / unknown-mode install is greyed-out with a note, never hidden.
# ---------------------------------------------------------------------------

@dataclass
class InstallEntry:
    profile_name: str
    url: str
    site_mode: str = MODE_UNKNOWN     # cached at profile-save; re-verified at sync
    reachable: bool = True
    note: str = ""

    @property
    def enabled_for(self) -> str:
        return self.site_mode

    def matches(self, suite_mode: str) -> bool:
        return self.site_mode == suite_mode


def classify_installs(
    profiles: List[dict],
    suite_mode: str,
    probe: Optional[Callable[[str, str], Tuple[str, bool, str]]] = None,
) -> List[InstallEntry]:
    """
    Build the picker list for `suite_mode`.

    profiles: list of profile dicts (need at least 'name', 'url', optional
              cached 'site_mode' and 'api_key').
    probe:    optional callable(url, api_key) -> (site_mode, reachable, note).
              When given, every profile is (re)verified live; otherwise the
              cached site_mode on the profile is trusted.

    Returns InstallEntry list. Mode-matching installs come first, then
    unknown/greyed ones; a wrong-mode install is dropped entirely (it can
    never receive this mode's posts).
    """
    entries: List[InstallEntry] = []
    for p in profiles:
        name = p.get("name", "")
        url = p.get("url", "")
        mode = p.get("site_mode", MODE_UNKNOWN)
        reachable, note = True, ""
        if probe is not None:
            try:
                mode, reachable, note = probe(url, p.get("api_key", ""))
            except Exception as e:  # never let a bad probe hide the install
                mode, reachable, note = MODE_UNKNOWN, False, f"couldn't verify mode: {e}"
        if mode == MODE_UNKNOWN and not note:
            note = "couldn't verify mode"
        entries.append(InstallEntry(name, url, mode, reachable, note))

    def _rank(e: InstallEntry) -> int:
        if e.matches(suite_mode):
            return 0
        if e.site_mode == MODE_UNKNOWN:
            return 1
        return 2  # different known mode

    # Keep matching + unknown; drop confirmed wrong-mode installs.
    keep = [e for e in entries if _rank(e) < 2]
    keep.sort(key=_rank)
    return keep


# ---------------------------------------------------------------------------
# SyncEngine — store-and-forward with POSITIVE verification.
#
# The concrete network operations are injected via a `poster` object so this
# engine stays headless-testable. The poster must implement:
#
#   sync_solo(draft) -> SyncResult
#   sync_gram(draft) -> SyncResult            # single image or carousel
#   link_trigram(post_ids: List[int], orientation: str) -> int   # trigram_id
#   verify(draft) -> bool                     # pull the live post back + compare
#
# A SyncResult carries (ok, remote_post_id, message). A trigram group is synced
# as ONE unit: all three chunks are created, then linked, then verified; a
# partial group is left 'queued'/'failed' and never half-published.
# ---------------------------------------------------------------------------

@dataclass
class SyncResult:
    ok: bool
    remote_post_id: int = 0
    message: str = ""


class SyncEngine:
    def __init__(self, session: Session, poster,
                 on_event: Optional[Callable[[str, Draft, str], None]] = None):
        self.session = session
        self.poster = poster
        self.on_event = on_event or (lambda phase, draft, msg: None)

    def _emit(self, phase: str, draft: Draft, msg: str = "") -> None:
        try:
            self.on_event(phase, draft, msg)
        except Exception:
            pass

    def sync_all(self, drafts: Optional[List[Draft]] = None) -> Dict[str, SyncResult]:
        """Sync every READY draft (or the supplied subset). Trigram chunks are
        grouped and pushed as complete units only. Returns {draft_id: result}."""
        if drafts is None:
            drafts = [d for d in self.session.list_drafts() if d.status == ST_READY]
        results: Dict[str, SyncResult] = {}

        singles = [d for d in drafts if d.kind != KIND_GRAM_TRIGRAM]
        trigrams = [d for d in drafts if d.kind == KIND_GRAM_TRIGRAM]

        for d in singles:
            results[d.draft_id] = self._sync_one(d)

        # Group trigram chunks by group_key and only push complete groups.
        groups: Dict[str, List[Draft]] = {}
        for d in trigrams:
            groups.setdefault(d.group_key, []).append(d)
        for gk, members in groups.items():
            self._sync_trigram_group(gk, members, results)

        return results

    def _mark(self, draft: Draft, status: str, error: str = "") -> None:
        draft.status = status
        draft.error = error
        self.session.save_draft(draft)

    def _sync_one(self, draft: Draft) -> SyncResult:
        problems = draft.validate()
        if problems:
            self._mark(draft, ST_FAILED, "; ".join(problems))
            self._emit("failed", draft, draft.error)
            return SyncResult(False, message=draft.error)

        self._mark(draft, ST_SYNCING)
        self._emit("syncing", draft)
        try:
            if draft.kind == KIND_SOLO:
                res = self.poster.sync_solo(draft)
            else:
                res = self.poster.sync_gram(draft)
        except Exception as e:
            self._mark(draft, ST_FAILED, str(e))
            self._emit("failed", draft, str(e))
            return SyncResult(False, message=str(e))

        if not res.ok:
            self._mark(draft, ST_FAILED, res.message)
            self._emit("failed", draft, res.message)
            return res

        draft.remote_post_id = res.remote_post_id
        # POSITIVE verification — pull the live post back and compare.
        try:
            verified = self.poster.verify(draft)
        except Exception as e:
            verified, vmsg = False, f"verification error: {e}"
        else:
            vmsg = "" if verified else "server post did not match the local draft"

        if not verified:
            self._mark(draft, ST_FAILED, vmsg)
            self._emit("failed", draft, vmsg)
            return SyncResult(False, res.remote_post_id, vmsg)

        self._mark(draft, ST_SYNCED)
        self._emit("synced", draft)
        return res

    def _sync_trigram_group(self, group_key: str, members: List[Draft],
                            results: Dict[str, SyncResult]) -> None:
        members = sorted(members, key=lambda d: d.trigram_slot)
        if len(members) != TRIGRAM_GROUP_SIZE:
            # Partial group — refuse to push; mirror trigram_ready_count UI.
            for d in members:
                self._mark(d, ST_QUEUED,
                           f"waiting for {TRIGRAM_GROUP_SIZE - len(members)} more chunk(s)")
                self._emit("queued", d, d.error)
                results[d.draft_id] = SyncResult(False, message=d.error)
            return

        # 1) Create all three posts.
        post_ids: List[int] = []
        failed = False
        for d in members:
            problems = d.validate()
            if problems:
                self._mark(d, ST_FAILED, "; ".join(problems))
                self._emit("failed", d, d.error)
                results[d.draft_id] = SyncResult(False, message=d.error)
                failed = True
                break
            self._mark(d, ST_SYNCING)
            self._emit("syncing", d)
            try:
                res = self.poster.sync_gram(d)
            except Exception as e:
                res = SyncResult(False, message=str(e))
            if not res.ok:
                self._mark(d, ST_FAILED, res.message)
                self._emit("failed", d, res.message)
                results[d.draft_id] = res
                failed = True
                break
            d.remote_post_id = res.remote_post_id
            self.session.save_draft(d)
            post_ids.append(res.remote_post_id)
            results[d.draft_id] = res

        if failed or len(post_ids) != TRIGRAM_GROUP_SIZE:
            # Leave any already-created posts marked failed for retry/cleanup.
            for d in members:
                if d.status == ST_SYNCING:
                    self._mark(d, ST_FAILED, "trigram group incomplete — siblings failed")
            return

        # 2) Link the three into a trigram (one indivisible unit).
        orientation = members[0].trigram_orientation
        try:
            trigram_id = self.poster.link_trigram(post_ids, orientation)
        except Exception as e:
            for d in members:
                self._mark(d, ST_FAILED, f"trigram link failed: {e}")
                self._emit("failed", d, d.error)
            return

        # 3) Verify + finalize each chunk.
        for d in members:
            d.remote_trigram_id = trigram_id
            try:
                verified = self.poster.verify(d)
            except Exception as e:
                verified = False
                vmsg = f"verification error: {e}"
            else:
                vmsg = "" if verified else "server post did not match the local draft"
            if verified:
                self._mark(d, ST_SYNCED)
                self._emit("synced", d)
            else:
                self._mark(d, ST_FAILED, vmsg)
                self._emit("failed", d, vmsg)
# ===== SNAPSMACK EOF =====

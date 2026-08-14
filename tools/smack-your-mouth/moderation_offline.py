"""
SMACK YOUR MOUTH — moderation_offline.py
Offline-first engine for fleet comment moderation. The inbound twin of COLD
SNAP's sumna_offline: where that module models a post waiting to be pushed OUT,
this one models a moderation decision + reply waiting to be pushed BACK.

This module is GUI-free and headless-testable. It owns:
  * the flat-file moderation model — one JSON file == one pulled comment plus
    the local decision (approve / delete / spam) and any drafted reply, stamped
    with a schema/build version so an old artifact is validated, never replayed
    against the wrong shape;
  * sessions — a first-class, resumable working set of pulled comments so the
    work survives a closed laptop and short connection windows (pull in one
    10-minute burst, moderate offline for an hour, sync in the next burst);
  * thumb-drive / disk export + import (a versioned, self-contained folder) so a
    moderation batch can move between machines and keep its sync state;
  * the store-and-forward SyncEngine with POSITIVE verification — a decision or
    reply is only marked synced after the live comment is pulled back and the
    change is confirmed on the server (the SYBU/COLD SNAP lesson: never infer
    success from no-error).

The actual HTTP transport (pull, action, reply, verify) lives in
moderation_api.py and is injected into the SyncEngine, so this module stays pure
and testable with a fake poster.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import shutil
import sys
import uuid
from dataclasses import dataclass, field, asdict
from datetime import datetime
from typing import Callable, Dict, List, Optional


# ---------------------------------------------------------------------------
# Versioning — every item and every export carries these so an old artifact is
# validated/migrated instead of replayed against the wrong shape.
# ---------------------------------------------------------------------------

SCHEMA_VERSION = 1           # moderation-item JSON structure version
BUILD_VERSION  = "0.1.0"     # SMACK YOUR MOUTH engine build
EXPORT_MANIFEST_VERSION = 1  # thumb-drive export folder format

# Moderation verbs — each maps to a concrete server-side action.
ACT_NONE    = "none"     # no decision yet
ACT_APPROVE = "approve"  # publish the comment (is_approved = 1)
ACT_DELETE  = "delete"   # remove the comment
ACT_SPAM    = "spam"     # mark as spam (and, server-side, optionally feed Shield)

DECISIONS = (ACT_APPROVE, ACT_DELETE, ACT_SPAM)

# Sync-status machine for a moderation item.
ST_PULLED  = "pulled"    # fetched from a spoke, no work queued yet
ST_READY   = "ready"     # a decision and/or reply is queued to push
ST_SYNCING = "syncing"   # in flight
ST_SYNCED  = "synced"    # pushed + verified
ST_FAILED  = "failed"    # push or verify failed; error retained


def _now_iso() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def _new_id() -> str:
    return uuid.uuid4().hex[:12]


# ---------------------------------------------------------------------------
# CommentRecord — the read-only snapshot of a spoke's snap_comments row as it
# was pulled. The fields mirror multisite/comments/pending's response exactly.
# ---------------------------------------------------------------------------

@dataclass
class CommentRecord:
    comment_id:     int = 0          # remote snap_comments.id on the spoke
    img_id:         int = 0
    img_title:      str = ""
    img_slug:       str = ""
    comment_author: str = ""
    comment_email:  str = ""
    comment_text:   str = ""
    comment_date:   str = ""
    comment_ip:     str = ""
    is_approved:    int = 0          # 0 = pending, 1 = published (for "recent" pulls)
    is_spam:        int = 0

    def to_dict(self) -> dict:
        return asdict(self)

    @classmethod
    def from_dict(cls, d: dict) -> "CommentRecord":
        return cls(**{k: d.get(k, getattr(cls, k)) for k in cls.__dataclass_fields__})

    @classmethod
    def from_api(cls, row: dict) -> "CommentRecord":
        """Build a record from a raw multisite/comments API row. Tolerant of the
        differing key set a 'recent'/'get' endpoint may add (is_approved etc.)."""
        def _i(v) -> int:
            try:
                return int(v)
            except (TypeError, ValueError):
                return 0
        return cls(
            comment_id     = _i(row.get("id") or row.get("comment_id")),
            img_id         = _i(row.get("img_id")),
            img_title      = str(row.get("img_title") or ""),
            img_slug       = str(row.get("img_slug") or ""),
            comment_author = str(row.get("comment_author") or ""),
            comment_email  = str(row.get("comment_email") or ""),
            comment_text   = str(row.get("comment_text") or ""),
            comment_date   = str(row.get("comment_date") or ""),
            comment_ip     = str(row.get("comment_ip") or ""),
            is_approved    = _i(row.get("is_approved")),
            is_spam        = _i(row.get("is_spam")),
        )


# ---------------------------------------------------------------------------
# ModItem — one JSON file == one pulled comment plus the local moderation work
# (decision + drafted reply) waiting to be synced back to its spoke.
# ---------------------------------------------------------------------------

@dataclass
class ModItem:
    item_id:    str                       # local uuid (stable across a session)
    site_key:   str                       # snap_home.site_key(site_url)
    site_url:   str
    site_name:  str = ""
    node_id:    int = 0                    # the hub's snap_multisite_nodes.id for this spoke
    comment:    CommentRecord = field(default_factory=CommentRecord)
    # Local moderation work.
    action:     str = ACT_NONE            # ACT_* decision
    reply_text: str = ""                  # drafted admin reply; blank == no reply
    reply_author: str = ""                # display name for the reply; blank => hub default
    # Sync bookkeeping.
    status:     str = ST_PULLED
    error:      str = ""
    remote_reply_id: int = 0              # server id of the posted reply, once known
    decided_at: str = ""
    created_at: str = field(default_factory=_now_iso)
    updated_at: str = field(default_factory=_now_iso)
    # Provenance stamp.
    schema_version: int = SCHEMA_VERSION
    build_version:  str = BUILD_VERSION

    # -- serialization ------------------------------------------------------
    def to_dict(self) -> dict:
        d = asdict(self)
        d["comment"] = self.comment.to_dict()
        return d

    @classmethod
    def from_dict(cls, d: dict) -> "ModItem":
        d = migrate_item_dict(dict(d))
        comment = CommentRecord.from_dict(d.get("comment", {}) or {})
        known = {k: d.get(k) for k in cls.__dataclass_fields__ if k in d and k != "comment"}
        obj = cls(**known)
        obj.comment = comment
        return obj

    # -- helpers ------------------------------------------------------------
    def touch(self) -> None:
        self.updated_at = _now_iso()

    def dedup_key(self) -> str:
        """Identity of the underlying comment across pulls — site + remote id."""
        return f"{self.site_key}#{self.comment.comment_id}"

    def has_reply(self) -> bool:
        return bool((self.reply_text or "").strip())

    def has_work(self) -> bool:
        """True if there is anything to sync: a decision or a drafted reply."""
        return self.action in DECISIONS or self.has_reply()

    def set_action(self, action: str) -> None:
        self.action = action if action in DECISIONS else ACT_NONE
        self.decided_at = _now_iso()
        if self.status in (ST_PULLED, ST_FAILED):
            self.status = ST_READY if self.has_work() else ST_PULLED
        self.touch()

    def set_reply(self, text: str) -> None:
        self.reply_text = text or ""
        if self.status in (ST_PULLED, ST_FAILED):
            self.status = ST_READY if self.has_work() else ST_PULLED
        self.touch()

    def clear_work(self) -> None:
        """Drop the queued decision + reply (e.g. after a successful sync)."""
        self.action = ACT_NONE
        self.reply_text = ""

    def validate(self) -> List[str]:
        """Return a list of human-readable problems; empty == ok to sync."""
        problems: List[str] = []
        if self.comment.comment_id <= 0:
            problems.append("item has no remote comment id")
        if not self.site_url:
            problems.append("item has no site url")
        if self.action not in (ACT_NONE,) + DECISIONS:
            problems.append(f"unknown moderation action '{self.action}'")
        if not self.has_work():
            problems.append("nothing to sync (no decision, no reply)")
        # A reply on a comment you're deleting is a contradiction — the reply
        # would be orphaned. Force the operator to pick one.
        if self.action == ACT_DELETE and self.has_reply():
            problems.append("cannot reply to a comment you are deleting")
        if self.has_reply() and len((self.reply_text or "").strip()) < 1:
            problems.append("reply text is empty")
        return problems


# ---------------------------------------------------------------------------
# Item migration — bridge old schema stamps forward instead of replaying wrong.
# ---------------------------------------------------------------------------

def migrate_item_dict(d: dict) -> dict:
    """Bring an item dict up to the current SCHEMA_VERSION. Idempotent."""
    v = int(d.get("schema_version", 0) or 0)
    if v < 1:
        d.setdefault("status", ST_PULLED)
        d.setdefault("action", ACT_NONE)
        d["schema_version"] = 1
    return d


# ---------------------------------------------------------------------------
# Sessions — a resumable working set. One directory holding item JSON files and
# a session manifest. Sessions are first-class so a moderation batch survives a
# closed laptop and can be resumed / moved between machines.
# ---------------------------------------------------------------------------

def _app_dir() -> str:
    """Persistent dir — next to the exe when frozen, source dir otherwise."""
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


SESSIONS_ROOT = os.path.join(_app_dir(), "mouth_sessions")


class Session:
    """A single working session: pulled comments + their local moderation work
    under one folder."""

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
    def items_dir(self) -> str:
        return os.path.join(self.path, "items")

    def _manifest_path(self) -> str:
        return os.path.join(self.path, "session.json")

    def save_meta(self) -> None:
        self.meta["updated_at"] = _now_iso()
        os.makedirs(self.path, exist_ok=True)
        tmp = self._manifest_path() + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(self.meta, f, indent=2, ensure_ascii=False)
        os.replace(tmp, self._manifest_path())

    # -- item CRUD ----------------------------------------------------------
    def _item_path(self, item_id: str) -> str:
        return os.path.join(self.items_dir, f"{item_id}.json")

    def save_item(self, item: ModItem) -> None:
        os.makedirs(self.items_dir, exist_ok=True)
        item.touch()
        tmp = self._item_path(item.item_id) + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(item.to_dict(), f, indent=2, ensure_ascii=False)
        os.replace(tmp, self._item_path(item.item_id))  # atomic write

    def load_item(self, item_id: str) -> Optional[ModItem]:
        p = self._item_path(item_id)
        if not os.path.isfile(p):
            return None
        with open(p, "r", encoding="utf-8") as f:
            return ModItem.from_dict(json.load(f))

    def list_items(self) -> List[ModItem]:
        out: List[ModItem] = []
        if not os.path.isdir(self.items_dir):
            return out
        for fn in sorted(os.listdir(self.items_dir)):
            if fn.endswith(".json"):
                try:
                    with open(os.path.join(self.items_dir, fn), "r", encoding="utf-8") as f:
                        out.append(ModItem.from_dict(json.load(f)))
                except Exception:
                    pass
        # Newest comment first, matching the CMS moderation queue.
        out.sort(key=lambda it: it.comment.comment_date, reverse=True)
        return out

    def delete_item(self, item_id: str) -> None:
        p = self._item_path(item_id)
        if os.path.isfile(p):
            os.remove(p)

    def existing_keys(self) -> Dict[str, str]:
        """Map dedup_key -> item_id for every item already in the session, so a
        re-pull of the same comment updates in place instead of duplicating."""
        return {it.dedup_key(): it.item_id for it in self.list_items()}

    def ingest_pull(self, site: dict, rows: List[dict]) -> int:
        """Turn raw API comment rows from one site into ModItems, skipping any
        comment already present (by site + remote id). Returns the count added.

        `site` is a fleet entry dict: {site_url, site_name, site_key, node_id}.
        """
        existing = self.existing_keys()
        added = 0
        site_url  = site.get("site_url", "")
        site_key  = site.get("site_key", "")
        site_name = site.get("site_name", "")
        node_id   = int(site.get("node_id", 0) or 0)
        for row in rows:
            rec = CommentRecord.from_api(row)
            if rec.comment_id <= 0:
                continue
            key = f"{site_key}#{rec.comment_id}"
            if key in existing:
                continue  # already pulled — keep the local decision intact
            item = ModItem(
                item_id=_new_id(),
                site_key=site_key,
                site_url=site_url,
                site_name=site_name,
                node_id=node_id,
                comment=rec,
            )
            self.save_item(item)
            existing[key] = item.item_id
            added += 1
        return added

    # -- counts (for fleet badges + progress) -------------------------------
    def counts(self) -> Dict[str, int]:
        items = self.list_items()
        c = {"total": len(items), "pending": 0, "ready": 0,
             "synced": 0, "failed": 0}
        for it in items:
            if it.status == ST_SYNCED:
                c["synced"] += 1
            elif it.status == ST_FAILED:
                c["failed"] += 1
            elif it.has_work():
                c["ready"] += 1
            else:
                c["pending"] += 1
        return c


class SessionStore:
    """Discovery + lifecycle for sessions under SESSIONS_ROOT."""

    def __init__(self, root: str = SESSIONS_ROOT):
        self.root = root
        os.makedirs(self.root, exist_ok=True)

    def create(self, name: str = "") -> Session:
        sid = _new_id()
        path = os.path.join(self.root, sid)
        os.makedirs(os.path.join(path, "items"), exist_ok=True)
        meta = {
            "session_id": sid,
            "name": name or f"Session {datetime.now():%Y-%m-%d %H:%M}",
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
        if not os.path.isdir(self.root):
            return out
        for sid in sorted(os.listdir(self.root)):
            s = self.load(sid)
            if s:
                out.append(s)
        # Most-recently-updated session first.
        out.sort(key=lambda s: s.meta.get("updated_at", ""), reverse=True)
        return out

    def delete(self, session_id: str) -> None:
        path = os.path.join(self.root, session_id)
        if os.path.isdir(path):
            shutil.rmtree(path, ignore_errors=True)


# ---------------------------------------------------------------------------
# Thumb-drive / disk export + import — a self-contained, versioned folder so a
# moderation batch can move between machines and keep its sync state.
# ---------------------------------------------------------------------------

def export_session(session: Session, dest_dir: str) -> str:
    """Copy a whole session to dest_dir as a self-contained, versioned export
    (items + a manifest + a human-readable recovery note). Returns the export
    folder path. Another machine running the tool can import it and keep
    moderating / syncing."""
    os.makedirs(dest_dir, exist_ok=True)
    out = os.path.join(dest_dir, f"mouth_export_{session.session_id}")
    if os.path.isdir(out):
        shutil.rmtree(out, ignore_errors=True)
    shutil.copytree(session.path, out)

    items = session.list_items()
    summary = []
    for it in items:
        summary.append({
            "item_id": it.item_id,
            "site_url": it.site_url,
            "comment_id": it.comment.comment_id,
            "author": it.comment.comment_author,
            "on": it.comment.img_title,
            "action": it.action,
            "has_reply": it.has_reply(),
            "status": it.status,
            "error": it.error or None,
        })
    manifest = {
        "manifest_version": EXPORT_MANIFEST_VERSION,
        "schema_version": SCHEMA_VERSION,
        "build_version": BUILD_VERSION,
        "session_id": session.session_id,
        "name": session.name,
        "exported_at": _now_iso(),
        "item_count": len(items),
        "items": summary,
    }
    with open(os.path.join(out, "export-manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2, ensure_ascii=False)

    c = session.counts()
    with open(os.path.join(out, "RECOVERY.txt"), "w", encoding="utf-8") as f:
        f.write(
            "SMACK YOUR MOUTH — recoverable moderation batch\n"
            "================================================\n\n"
            f"Batch:     {session.name}\n"
            f"Exported:  {manifest['exported_at']}\n"
            f"Build:     {BUILD_VERSION} (schema v{SCHEMA_VERSION}, manifest v{EXPORT_MANIFEST_VERSION})\n"
            f"Comments:  {c['total']}  ({c['ready']} decided/ready, "
            f"{c['synced']} synced, {c['failed']} failed)\n\n"
            "This folder is a complete, self-contained copy of an offline comment\n"
            "moderation batch: every pulled comment plus the local decision\n"
            "(approve / delete / spam) and any drafted reply, one JSON file each.\n\n"
            "TO RECOVER / CONTINUE ON ANOTHER MACHINE:\n"
            "  1. Open SMACK YOUR MOUTH.\n"
            "  2. Click 'Import...' and choose THIS folder.\n"
            "  3. The batch loads with every decision + reply and its sync state\n"
            "     intact — already-synced comments stay done, the rest are ready.\n"
            "  4. Click 'SYNC' when you have a connection.\n\n"
            "Nothing here talks to a server. It is safe to copy, back up, or move\n"
            "between machines. See export-manifest.json for the full comment list.\n"
        )
    return out


def import_session(export_dir: str, store: SessionStore) -> Session:
    """Import an exported session folder into `store`, returning the new Session.
    Rejects an export whose manifest version is newer than this build understands."""
    mp = os.path.join(export_dir, "export-manifest.json")
    if not os.path.isfile(mp):
        raise ValueError("Not a SMACK YOUR MOUTH export (no export-manifest.json).")
    with open(mp, "r", encoding="utf-8") as f:
        manifest = json.load(f)
    if int(manifest.get("manifest_version", 0)) > EXPORT_MANIFEST_VERSION:
        raise ValueError(
            "This export was written by a newer build. Update SMACK YOUR MOUTH to import it."
        )
    sid = _new_id()  # fresh local id so imports never collide
    dest = os.path.join(store.root, sid)
    shutil.copytree(export_dir, dest)
    em = os.path.join(dest, "export-manifest.json")
    if os.path.isfile(em):
        os.remove(em)
    s = store.load(sid) or Session(dest, {})
    s.meta["session_id"] = sid
    s.meta["name"] = manifest.get("name", sid) + " (imported)"
    s.meta["imported_at"] = _now_iso()
    s.save_meta()
    return s


# ---------------------------------------------------------------------------
# SyncEngine — store-and-forward with POSITIVE verification.
#
# The concrete network operations are injected via a `poster` object so this
# engine stays headless-testable. The poster must implement:
#
#   apply_decision(item) -> SyncResult      # approve / delete / spam
#   post_reply(item)     -> SyncResult      # sets remote reply id in .remote_id
#   verify_decision(item) -> bool           # pull the comment back + confirm
#   verify_reply(item)    -> bool           # pull the comment back + confirm reply
#
# A SyncResult carries (ok, remote_id, message). An item is only marked synced
# after every queued operation on it is applied AND positively verified — a
# reply that the server accepted but that verification can't confirm leaves the
# item failed for retry, never a silent success.
# ---------------------------------------------------------------------------

@dataclass
class SyncResult:
    ok: bool
    remote_id: int = 0
    message: str = ""


class SyncEngine:
    def __init__(self, session: Session, poster,
                 on_event: Optional[Callable[[str, ModItem, str], None]] = None):
        self.session = session
        self.poster = poster
        self.on_event = on_event or (lambda phase, item, msg: None)

    def _emit(self, phase: str, item: ModItem, msg: str = "") -> None:
        try:
            self.on_event(phase, item, msg)
        except Exception:
            pass

    def _mark(self, item: ModItem, status: str, error: str = "") -> None:
        item.status = status
        item.error = error
        self.session.save_item(item)

    def sync_all(self, items: Optional[List[ModItem]] = None) -> Dict[str, SyncResult]:
        """Sync every item that has queued work (or the supplied subset).
        Returns {item_id: result}."""
        if items is None:
            items = [it for it in self.session.list_items()
                     if it.has_work() and it.status != ST_SYNCED]
        results: Dict[str, SyncResult] = {}
        for it in items:
            results[it.item_id] = self._sync_one(it)
        return results

    def _sync_one(self, item: ModItem) -> SyncResult:
        problems = item.validate()
        if problems:
            self._mark(item, ST_FAILED, "; ".join(problems))
            self._emit("failed", item, item.error)
            return SyncResult(False, message=item.error)

        self._mark(item, ST_SYNCING)
        self._emit("syncing", item)

        # 1) Reply first when the decision is a delete-free one. Posting the
        #    reply before an approve keeps the thread intact; a delete never
        #    carries a reply (validate() blocks that combination).
        if item.has_reply():
            try:
                res = self.poster.post_reply(item)
            except Exception as e:
                self._mark(item, ST_FAILED, f"reply error: {e}")
                self._emit("failed", item, item.error)
                return SyncResult(False, message=str(e))
            if not res.ok:
                self._mark(item, ST_FAILED, res.message)
                self._emit("failed", item, res.message)
                return res
            item.remote_reply_id = res.remote_id
            self.session.save_item(item)
            # POSITIVE verification — pull the comment back and confirm the reply.
            try:
                verified = self.poster.verify_reply(item)
            except Exception as e:
                verified, vmsg = False, f"reply verification error: {e}"
            else:
                vmsg = "" if verified else "reply not found on the server after posting"
            if not verified:
                self._mark(item, ST_FAILED, vmsg)
                self._emit("failed", item, vmsg)
                return SyncResult(False, item.remote_reply_id, vmsg)

        # 2) Apply the moderation decision (if any).
        if item.action in DECISIONS:
            try:
                res = self.poster.apply_decision(item)
            except Exception as e:
                self._mark(item, ST_FAILED, f"decision error: {e}")
                self._emit("failed", item, item.error)
                return SyncResult(False, message=str(e))
            if not res.ok:
                self._mark(item, ST_FAILED, res.message)
                self._emit("failed", item, res.message)
                return res
            # POSITIVE verification — pull the comment back and confirm the state.
            try:
                verified = self.poster.verify_decision(item)
            except Exception as e:
                verified, vmsg = False, f"verification error: {e}"
            else:
                vmsg = "" if verified else "server did not reflect the moderation decision"
            if not verified:
                self._mark(item, ST_FAILED, vmsg)
                self._emit("failed", item, vmsg)
                return SyncResult(False, message=vmsg)

        self._mark(item, ST_SYNCED)
        self._emit("synced", item)
        return SyncResult(True, item.remote_reply_id, "synced")
# ===== SNAPSMACK EOF =====

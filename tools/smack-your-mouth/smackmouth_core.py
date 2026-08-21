"""
SMACK YOUR MOUTH — smackmouth_core.py
GUI-free controller factored out of main.py (the tkinter shell) so BOTH the
Windows tkinter window and the Linux Chrome/Blink window drive the same logic.

Nothing here talks to a widget. Every method returns plain JSON-serialisable
data (dicts / lists) or raises on failure, so it can sit behind tkinter buttons
OR behind snap_blink's blink.call() handlers unchanged. The real work still lives
in the existing modules — moderation_offline (engine), moderation_api (transport),
fleet (shared-store loader), config (shared-home config). This file only lifts
the orchestration that used to be tangled with tk.Tk in main.py.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from typing import Dict, List, Optional

import config as cfg_module

BUILD_VERSION = "0.1.1"  # keep in step with main.py's shell version

# The engine + transport + fleet are guarded so a missing optional dep (requests,
# a shared module) can never stop the shell from launching — it just reports why.
# This mirrors main.py's import shim exactly.
try:
    import fleet as fleet_module
    import moderation_offline as mo
    from moderation_api import MouthConnection, MouthPoster
    ENGINE_OK = True
    ENGINE_ERR = ""
except Exception as _eng_err:  # pragma: no cover - import shim
    fleet_module = None
    mo = None
    MouthConnection = MouthPoster = None
    ENGINE_OK = False
    ENGINE_ERR = str(_eng_err)


class MouthCore:
    """One live moderation controller: config + session store + fleet + the
    orchestration for pull / decide / reply / sync. Web and tkinter shells both
    hold ONE of these and call its methods."""

    def __init__(self):
        self.config = cfg_module.load()
        self.reply_author = self.config.get("reply_author", "SnapSmack") or "SnapSmack"
        self.one_off_url = self.config.get("url", "")
        self.one_off_key = self.config.get("api_key", "")

        self.fleet: List["fleet_module.SiteEntry"] = []
        self.store = mo.SessionStore() if ENGINE_OK else None
        self.session = None

        if ENGINE_OK:
            self._boot_session()
            self.refresh_fleet()

    # ------------------------------------------------------------------
    # Session lifecycle
    # ------------------------------------------------------------------

    def _boot_session(self) -> None:
        """Resume the last session, or create a fresh one (main._boot_session)."""
        sessions = self.store.list()
        last_id = self.config.get("last_session", "")
        chosen = None
        for s in sessions:
            if s.session_id == last_id:
                chosen = s
                break
        if chosen is None:
            chosen = sessions[0] if sessions else self.store.create()
        self.session = chosen

    def session_label(self, s) -> str:
        c = s.counts()
        return f"{s.name}  ·  {c['total']} comments  ({c['ready']} ready)"

    def sessions_payload(self) -> List[dict]:
        if not self.store:
            return []
        return [{"session_id": s.session_id, "label": self.session_label(s)}
                for s in self.store.list()]

    def select_session(self, session_id: str) -> dict:
        if not self.store:
            raise RuntimeError("engine not available")
        for s in self.store.list():
            if s.session_id == session_id:
                self.session = s
                self._persist_last_session()
                return self.state_payload()
        raise RuntimeError("session not found")

    def new_session(self) -> dict:
        if not self.store:
            raise RuntimeError("engine not available")
        self.session = self.store.create()
        self._persist_last_session()
        return self.state_payload()

    def _persist_last_session(self) -> None:
        try:
            if self.session:
                self.config["last_session"] = self.session.session_id
            self.config["reply_author"] = self.reply_author or "SnapSmack"
            cfg_module.save(self.config)
        except Exception:
            pass

    # ------------------------------------------------------------------
    # Author
    # ------------------------------------------------------------------

    def set_author(self, author: str) -> dict:
        self.reply_author = (author or "").strip() or "SnapSmack"
        try:
            self.config["reply_author"] = self.reply_author
            cfg_module.save(self.config)
        except Exception:
            pass
        return {"reply_author": self.reply_author}

    # ------------------------------------------------------------------
    # Fleet
    # ------------------------------------------------------------------

    def refresh_fleet(self) -> dict:
        """Load the shared-profile fleet (no network). main._refresh_fleet."""
        if not ENGINE_OK:
            return {"fleet": [], "note": f"Engine failed to import: {ENGINE_ERR}"}
        note = ""
        try:
            self.fleet = fleet_module.load_fleet()
        except Exception as e:
            self.fleet = []
            note = f"Fleet load failed: {e}"
        if not self.fleet and not note:
            note = ("No fleet profiles found. Use a one-off site, or run "
                    "THE HUB -> Discover Fleet.")
        return {"fleet": self.fleet_payload(), "note": note}

    def probe_fleet(self) -> dict:
        """Live reachability pass (network). main._on_probe."""
        if not ENGINE_OK or not self.fleet:
            return {"fleet": self.fleet_payload(), "note": "Nothing to probe."}
        fleet_module.probe_fleet(self.fleet)
        return {"fleet": self.fleet_payload(), "note": "Fleet probed."}

    def _fleet_tag(self, e) -> str:
        if not e.api_key:
            return "no key"
        if e.note:
            return e.note
        if e.reachable:
            return f"{e.pending_count} pending"
        return "not probed"

    def fleet_payload(self) -> List[dict]:
        out = []
        for e in self.fleet:
            out.append({
                "name": e.name,
                "site_url": e.site_url,
                "has_key": bool(e.api_key),
                "reachable": bool(e.reachable),
                "pending_count": int(e.pending_count),
                "note": e.note,
                "tag": self._fleet_tag(e),
            })
        return out

    # ------------------------------------------------------------------
    # Pull (network -> local session)
    # ------------------------------------------------------------------

    def pull_all(self) -> dict:
        """Pull pending comments from every fleet site with a key. main._on_pull_all."""
        if not ENGINE_OK:
            raise RuntimeError(f"engine not available: {ENGINE_ERR}")
        if not self.session:
            self.new_session()
        targets = [e for e in self.fleet if e.api_key]
        if not targets:
            return {"added": 0, "errors": [], "note": "No fleet sites with a key to pull."}
        added_total, errors = 0, []
        for e in targets:
            try:
                conn = MouthConnection(e.site_url, e.api_key)
                rows = conn.pull_pending()
                added_total += self.session.ingest_pull(e.as_ingest_site(), rows)
            except Exception as ex:
                errors.append(f"{e.name}: {ex}")
        note = f"Pulled {added_total} new comment(s)."
        if errors:
            note += f"  {len(errors)} site(s) failed."
        return {"added": added_total, "errors": errors, "note": note}

    def pull_one(self, url: str, key: str) -> dict:
        """Pull one off-fleet site. main._on_pull_one."""
        if not ENGINE_OK:
            raise RuntimeError(f"engine not available: {ENGINE_ERR}")
        url = (url or "").strip()
        key = (key or "").strip()
        if not url or not key:
            raise RuntimeError("Both the one-off SITE URL and API KEY are required.")
        if not self.session:
            self.new_session()
        # Persist the one-off connection for next time.
        self.one_off_url, self.one_off_key = url, key
        self.config["url"] = url
        self.config["api_key"] = key
        try:
            cfg_module.save(self.config)
        except Exception:
            pass
        snap_home = None
        try:
            import snap_home as _sh
            snap_home = _sh
        except Exception:
            pass
        skey = snap_home.site_key(url) if snap_home else url
        site = {"site_url": url, "site_name": url, "site_key": skey, "node_id": 0}
        conn = MouthConnection(url, key)
        rows = conn.pull_pending()
        added = self.session.ingest_pull(site, rows)
        return {"added": added, "errors": [],
                "note": f"Pulled {added} new comment(s) from this site."}

    # ------------------------------------------------------------------
    # Queue rendering + moderation controls
    # ------------------------------------------------------------------

    def _item_payload(self, item) -> dict:
        c = item.comment
        who = c.comment_author or "Anonymous"
        if c.comment_email:
            who += f"  [{c.comment_email}]"
        meta = (f"ON: {c.img_title or 'unknown'}   ·   "
                f"IP: {c.comment_ip or '—'}   ·   {c.comment_date}")
        return {
            "item_id": item.item_id,
            "site_name": item.site_name or item.site_url,
            "site_url": item.site_url,
            "status": item.status,
            "action": item.action,
            "who": who,
            "meta": meta,
            "text": c.comment_text,
            "reply_text": item.reply_text or "",
            "error": item.error or "",
        }

    def queue_payload(self) -> dict:
        if not self.session:
            return {"items": [], "counts": {"total": 0, "ready": 0, "synced": 0,
                                            "failed": 0, "pending": 0}}
        items = [self._item_payload(it) for it in self.session.list_items()]
        return {"items": items, "counts": self.session.counts()}

    def _load_item(self, item_id: str):
        if not self.session:
            raise RuntimeError("no active session")
        item = self.session.load_item(item_id)
        if not item:
            raise RuntimeError("item not found")
        return item

    def set_decision(self, item_id: str, action: str,
                     reply_text: Optional[str] = None) -> dict:
        """Approve / delete / spam / clear. main._on_decision. Any typed-but-
        unsaved reply is saved first so a decision click never loses it."""
        item = self._load_item(item_id)
        if reply_text is not None:
            item.reply_text = (reply_text or "").strip()
        item.set_action(action)
        self.session.save_item(item)
        return self._item_payload(item)

    def save_reply(self, item_id: str, reply_text: str,
                   author: Optional[str] = None) -> dict:
        """main._on_save_reply."""
        item = self._load_item(item_id)
        item.set_reply((reply_text or "").strip())
        if author is not None:
            self.reply_author = (author or "").strip() or "SnapSmack"
        item.reply_author = self.reply_author
        self.session.save_item(item)
        return self._item_payload(item)

    def flush_replies(self, edits: List[list]) -> dict:
        """Save a batch of [item_id, text] reply edits before a sync, mirroring
        main._flush_unsaved_replies. Ignores rows that didn't actually change."""
        if not self.session:
            return {"saved": 0}
        saved = 0
        for pair in edits or []:
            try:
                item_id, text = pair[0], (pair[1] or "").strip()
            except Exception:
                continue
            item = self.session.load_item(item_id)
            if item and text != (item.reply_text or "").strip():
                item.set_reply(text)
                item.reply_author = self.reply_author
                self.session.save_item(item)
                saved += 1
        return {"saved": saved}

    # ------------------------------------------------------------------
    # Sync (local session -> network, with positive verification)
    # ------------------------------------------------------------------

    def sync_preview(self) -> dict:
        """What a sync WOULD push — count, destination sites, and destructive
        deletes — so the window can show the Parkinson's-forgiving confirmation
        (ARCH-03) before anything is applied. main._on_sync's guard, headless."""
        if not self.session:
            return {"count": 0, "deletes": 0, "sites": []}
        todo = [it for it in self.session.list_items()
                if it.has_work() and it.status != mo.ST_SYNCED]
        deletes = sum(1 for it in todo if it.action == mo.ACT_DELETE)
        sites = sorted({it.site_url for it in todo})
        return {"count": len(todo), "deletes": deletes, "sites": sites}

    def sync_run(self) -> dict:
        """Apply every queued decision + reply and positively verify each.
        main._on_sync's worker body. Confirmation is the caller's job (done in
        the window via sync_preview)."""
        if not ENGINE_OK:
            raise RuntimeError(f"engine not available: {ENGINE_ERR}")
        if not self.session:
            raise RuntimeError("no active session")
        todo = [it for it in self.session.list_items()
                if it.has_work() and it.status != mo.ST_SYNCED]
        if not todo:
            return {"ok": 0, "fail": 0,
                    "note": "Nothing to sync — decide or reply on a comment first."}

        # Group by site so each site gets one authenticated connection.
        by_site: Dict[str, List] = {}
        for it in todo:
            by_site.setdefault(it.site_key, []).append(it)
        author = self.reply_author or "SnapSmack"

        ok = fail = 0
        for site_key, items in by_site.items():
            entry = fleet_module.find_entry(self.fleet, site_key)
            url = items[0].site_url
            key = entry.api_key if entry else ""
            if not key:
                # Fall back to the one-off connection if it matches.
                if self.one_off_key and self.one_off_url:
                    key = self.one_off_key
            if not key:
                for it in items:
                    it.status = mo.ST_FAILED
                    it.error = "no API key for this site (refresh the fleet)"
                    self.session.save_item(it)
                    fail += 1
                continue
            conn = MouthConnection(url, key)
            poster = MouthPoster(conn, hub_author=author)
            engine = mo.SyncEngine(self.session, poster)
            results = engine.sync_all(items)
            for r in results.values():
                if r.ok:
                    ok += 1
                else:
                    fail += 1
        return {"ok": ok, "fail": fail,
                "note": f"Sync complete: {ok} confirmed, {fail} failed."}

    # ------------------------------------------------------------------
    # Export / import
    # ------------------------------------------------------------------

    def export_session(self, dest_dir: str) -> dict:
        """main._on_export (dest chosen by the window's folder picker)."""
        if not self.session:
            raise RuntimeError("no active session")
        if not (dest_dir or "").strip():
            raise RuntimeError("no destination folder given")
        out = mo.export_session(self.session, dest_dir.strip())
        return {"path": out, "note": f"Exported to {out}"}

    def import_session(self, src_dir: str) -> dict:
        """main._on_import (src chosen by the window's folder picker)."""
        if not self.store:
            raise RuntimeError("engine not available")
        if not (src_dir or "").strip():
            raise RuntimeError("no source folder given")
        s = mo.import_session(src_dir.strip(), self.store)
        self.session = s
        self._persist_last_session()
        return self.state_payload("Batch imported.")

    # ------------------------------------------------------------------
    # Whole-window state
    # ------------------------------------------------------------------

    def state_payload(self, note: str = "") -> dict:
        """Everything the window needs to render itself in one shot."""
        return {
            "engine_ok": ENGINE_OK,
            "engine_err": ENGINE_ERR,
            "build_version": BUILD_VERSION,
            "config": {
                "reply_author": self.reply_author,
                "one_off_url": self.one_off_url,
                "has_one_off_key": bool(self.one_off_key),
            },
            "sessions": self.sessions_payload(),
            "current_session": self.session.session_id if self.session else "",
            "fleet": self.fleet_payload(),
            "queue": self.queue_payload(),
            "note": note,
        }

# ===== SNAPSMACK EOF =====

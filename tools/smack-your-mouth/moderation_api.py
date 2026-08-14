"""
SMACK YOUR MOUTH — moderation_api.py
HTTP transport for the offline moderation suite. Injected into the SyncEngine so
the engine itself stays headless. Nothing here is invented — it reuses the exact
multisite comment contract the CMS hub page (smack-multisite-comments.php)
already speaks to every spoke via core/multisite-api.php:

    GET  api.php?route=multisite/heartbeat         — reachability + pending count   (EXISTS)
    GET  api.php?route=multisite/comments/pending  — pull unapproved comments       (EXISTS)
    POST api.php?route=multisite/comments/action   — approve | delete a comment     (EXISTS)

Three operations this tool needs do NOT yet exist on the server; the client
calls them behind graceful fallbacks and degrades to a clear message instead of
a crash, so the moment the server adds them the tool lights up (see
docs/smack-your-mouth-spec.md, "Server API"):

    POST api.php?route=multisite/comments/reply    — write an admin reply          (MUST-ADD)
    action=spam on multisite/comments/action       — mark a comment as spam         (MUST-ADD)
    GET  api.php?route=multisite/comments/get       — read one comment back by id   (MUST-ADD)

Auth: the same Bearer key the hub presents to a spoke (the per-node
api_key_local). It is stored locally in the shared connection profile and never
uploaded — consistent with the least-privilege model the rest of the family uses.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


from typing import List, Optional, Tuple

import requests

from moderation_offline import (
    ModItem, SyncResult,
    ACT_APPROVE, ACT_DELETE, ACT_SPAM,
)

_UA = "SmackYourMouth/0.1.0"


def _resp_msg(r, default: str) -> str:
    """Surface the server's JSON error so the user sees the real reason
    (revoked key / not-a-hub / unknown endpoint) rather than a generic note."""
    try:
        data = r.json() or {}
        m = data.get("error") or data.get("message")
        if m:
            return str(m)
    except Exception:
        pass
    return default


# ---------------------------------------------------------------------------
# Connection — one Bearer session per spoke, matching the hub->spoke contract.
# ---------------------------------------------------------------------------

class MouthConnection:
    def __init__(self, base_url: str, api_key: str = "", api_path: str = "/api.php"):
        self.base_url = (base_url or "").rstrip("/")
        self.api_path = api_path
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": _UA,
            "Authorization": f"Bearer {api_key}",
            "Accept": "application/json",
        })

    def _api(self, route: str) -> str:
        return f"{self.base_url}{self.api_path}?route={route}"

    # -- reachability -------------------------------------------------------
    def heartbeat(self, timeout: int = 12) -> Tuple[bool, int, str]:
        """Return (reachable, pending_comments, note). Uses multisite/heartbeat
        (EXISTS) — the same authenticated read the hub dashboard uses. This is
        the reachability probe (multisite/ping needs the spoke's OWN api_key_remote,
        which the fleet doesn't hold, so heartbeat is the right check here)."""
        try:
            r = self.session.get(self._api("multisite/heartbeat"), timeout=timeout)
        except requests.RequestException as e:
            return False, 0, f"unreachable: {e}"
        if r.status_code in (401, 403):
            return False, 0, "API key rejected (needs the hub->spoke key)"
        if r.status_code != 200:
            return False, 0, f"HTTP {r.status_code}"
        try:
            data = r.json()
        except ValueError:
            return False, 0, "non-JSON response"
        if not data.get("ok"):
            return False, 0, _resp_msg(r, "heartbeat returned an error")
        return True, int(data.get("pending_comments", 0) or 0), ""

    # -- pull ---------------------------------------------------------------
    def pull_pending(self, timeout: int = 30) -> List[dict]:
        """GET multisite/comments/pending (EXISTS). Returns the raw comment rows
        (up to the server's 100-row cap). Raises RuntimeError on a hard failure so
        the caller can report which site could not be pulled."""
        try:
            r = self.session.get(self._api("multisite/comments/pending"), timeout=timeout)
        except requests.RequestException as e:
            raise RuntimeError(f"unreachable: {e}")
        if r.status_code in (401, 403):
            raise RuntimeError(_resp_msg(r, "API key rejected (needs the hub->spoke key)"))
        if r.status_code != 200:
            raise RuntimeError(f"HTTP {r.status_code}")
        try:
            data = r.json()
        except ValueError:
            raise RuntimeError("non-JSON response")
        if not data.get("ok"):
            raise RuntimeError(_resp_msg(r, "server returned an error"))
        return list(data.get("comments") or [])

    def pull_recent(self, status: str = "all", since: str = "",
                    limit: int = 100, timeout: int = 30) -> Optional[List[dict]]:
        """GET multisite/comments/list (MUST-ADD) — approved + pending, optionally
        since a cursor. Returns None (not an error) when the endpoint is absent on
        an older spoke, so the caller can fall back to pull_pending()."""
        params = {"status": status, "limit": str(limit)}
        if since:
            params["since"] = since
        try:
            r = self.session.get(self._api("multisite/comments/list"),
                                 params=params, timeout=timeout)
        except requests.RequestException:
            return None
        if r.status_code == 404:
            return None  # endpoint not present on this build — caller falls back
        if r.status_code != 200:
            return None
        try:
            data = r.json()
        except ValueError:
            return None
        if not data.get("ok"):
            return None
        return list(data.get("comments") or [])

    # -- moderation actions -------------------------------------------------
    def apply_action(self, comment_id: int, action: str,
                     timeout: int = 30) -> Tuple[bool, str]:
        """POST multisite/comments/action. approve|delete EXIST; spam is a
        MUST-ADD the server currently rejects (surfaced as a clear message)."""
        try:
            r = self.session.post(
                self._api("multisite/comments/action"),
                data={"comment_id": str(int(comment_id)), "action": action},
                timeout=timeout,
            )
        except requests.RequestException as e:
            return False, f"network error: {e}"
        if r.status_code == 200:
            try:
                data = r.json()
            except ValueError:
                return False, "non-JSON response"
            if data.get("ok"):
                return True, ""
            return False, _resp_msg(r, "server did not confirm the action")
        if r.status_code == 400 and action == ACT_SPAM:
            # The current whitelist is approve|delete only — spell out the gap.
            return False, ("spam is not accepted by this spoke yet "
                           "(multisite/comments/action needs the 'spam' verb)")
        return False, _resp_msg(r, f"HTTP {r.status_code}")

    def post_reply(self, comment_id: int, text: str, author: str = "",
                   timeout: int = 30) -> Tuple[bool, int, str]:
        """POST multisite/comments/reply (MUST-ADD). Returns (ok, reply_id, msg).
        A 404 means the spoke has no reply route yet — reported as a clear,
        actionable message rather than a silent failure."""
        payload = {"comment_id": str(int(comment_id)), "reply_text": text}
        if author:
            payload["author"] = author
        try:
            r = self.session.post(self._api("multisite/comments/reply"),
                                  data=payload, timeout=timeout)
        except requests.RequestException as e:
            return False, 0, f"network error: {e}"
        if r.status_code == 404:
            return False, 0, ("this spoke has no reply endpoint yet "
                              "(multisite/comments/reply must be added server-side)")
        if r.status_code != 200:
            return False, 0, _resp_msg(r, f"HTTP {r.status_code}")
        try:
            data = r.json()
        except ValueError:
            return False, 0, "non-JSON response"
        if not data.get("ok"):
            return False, 0, _resp_msg(r, "server did not confirm the reply")
        return True, int(data.get("reply_id", 0) or 0), ""

    # -- read-back (positive verification) ----------------------------------
    def get_comment(self, comment_id: int, timeout: int = 20) -> Optional[dict]:
        """GET multisite/comments/get (MUST-ADD) — one comment by id, including
        is_approved / is_spam / any replies. Returns the row dict, or None when
        the endpoint is absent (caller then verifies via the pending-list delta)."""
        try:
            r = self.session.get(self._api("multisite/comments/get"),
                                 params={"comment_id": str(int(comment_id))},
                                 timeout=timeout)
        except requests.RequestException:
            return None
        if r.status_code == 404:
            # Ambiguous: could be "no such endpoint" OR "comment deleted". The
            # caller disambiguates with the pending-list fallback.
            return None
        if r.status_code != 200:
            return None
        try:
            data = r.json()
        except ValueError:
            return None
        if not data.get("ok"):
            return None
        return data.get("comment") or data


# ---------------------------------------------------------------------------
# MouthPoster — adapts a MouthConnection to the SyncEngine's injected interface.
# Every verify() reads the comment back and confirms the change; where the
# read-back endpoint is absent it falls back to the pending-list delta, and only
# where NO read is possible does it trust the server's explicit 'ok' (never
# manufacturing a false failure — the COLD SNAP verification philosophy).
# ---------------------------------------------------------------------------

class MouthPoster:
    def __init__(self, conn: MouthConnection, hub_author: str = "SnapSmack"):
        self.conn = conn
        self.hub_author = hub_author or "SnapSmack"

    # -- decision -----------------------------------------------------------
    def apply_decision(self, item: ModItem) -> SyncResult:
        ok, msg = self.conn.apply_action(item.comment.comment_id, item.action)
        return SyncResult(ok, message=msg)

    def verify_decision(self, item: ModItem) -> bool:
        cid = item.comment.comment_id
        row = self.conn.get_comment(cid)
        if row is not None:
            # Read-back endpoint answered — confirm the concrete state.
            if item.action == ACT_APPROVE:
                return int(row.get("is_approved", 0) or 0) == 1
            if item.action == ACT_SPAM:
                return int(row.get("is_spam", 0) or 0) == 1
            if item.action == ACT_DELETE:
                return False  # a row came back — the delete did NOT take
        # No read-back row. Disambiguate via the pending list.
        still_pending = self._is_in_pending(cid)
        if still_pending is None:
            # Cannot read at all — trust the explicit 'ok' from apply_action
            # rather than manufacturing a false failure.
            return True
        if item.action == ACT_APPROVE:
            return not still_pending          # approved => left the pending queue
        if item.action == ACT_DELETE:
            return not still_pending          # deleted => gone from the queue
        if item.action == ACT_SPAM:
            return not still_pending          # spammed => out of the pending queue
        return False

    # -- reply --------------------------------------------------------------
    def post_reply(self, item: ModItem) -> SyncResult:
        author = (item.reply_author or "").strip() or self.hub_author
        ok, reply_id, msg = self.conn.post_reply(
            item.comment.comment_id, item.reply_text, author)
        return SyncResult(ok, remote_id=reply_id, message=msg)

    def verify_reply(self, item: ModItem) -> bool:
        row = self.conn.get_comment(item.comment.comment_id)
        if row is None:
            # No read-back available — the reply endpoint already returned an
            # explicit ok + reply_id, so trust it rather than false-fail.
            return True
        replies = row.get("replies")
        if isinstance(replies, list) and replies:
            if item.remote_reply_id:
                return any(int(rp.get("id", 0) or 0) == item.remote_reply_id
                           for rp in replies)
            return True
        # Endpoint returned the comment but no replies array — fall back to
        # trusting the explicit reply_id the server issued.
        return bool(item.remote_reply_id)

    # -- helpers ------------------------------------------------------------
    def _is_in_pending(self, comment_id: int) -> Optional[bool]:
        """True/False if the pending list is readable; None if it isn't."""
        try:
            rows = self.conn.pull_pending()
        except RuntimeError:
            return None
        return any(int(r.get("id", 0) or 0) == int(comment_id) for r in rows)
# ===== SNAPSMACK EOF =====

"""Thin client for the SnapSmack forum API (projects/forum-server).

Zero third-party dependencies — just urllib — so BOARD LORD runs anywhere Python
and PySide6 do. Every call carries the operator key as a Bearer token. Errors
come back as ``ApiError`` with the server's error code and message.
"""

import json
import urllib.error
import urllib.parse
import urllib.request

# Author post-flags, in triage order. Kept in sync with the forum ENUM.
FLAGS = ["support", "question", "chat", "brag", "none"]

# Role ladder (see forum-server): God is out-of-band (the mod key), not a value.
ROLES = {0: "Ordinary", 1: "Power", 2: "Moderator", 3: "Admin"}


class ApiError(Exception):
    def __init__(self, status, code, message):
        super().__init__(message or code or f"HTTP {status}")
        self.status = status
        self.code = code
        self.message = message or code or f"HTTP {status}"


class ForumClient:
    def __init__(self, base_url: str, key: str, timeout: float = 15.0):
        self.base = (base_url or "").rstrip("/")
        self.key = key or ""
        self.timeout = timeout

    # -- transport -----------------------------------------------------------
    def _request(self, method, path, params=None, body=None):
        url = self.base + "/" + path.lstrip("/")
        if params:
            # Drop empties and zeros: cat=0 means "all boards", flag/filter/tag ""
            # mean "no filter", so none of them belong in the query string.
            clean = {k: v for k, v in params.items() if v not in (None, "", 0)}
            if clean:
                url += "?" + urllib.parse.urlencode(clean)

        data = None
        headers = {"Authorization": "Bearer " + self.key}
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"

        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                raw = resp.read().decode("utf-8")
                return json.loads(raw) if raw.strip() else {}
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", "replace") if exc.fp else ""
            try:
                payload = json.loads(raw)
            except Exception:  # noqa: BLE001
                payload = {}
            raise ApiError(exc.code, payload.get("error"),
                           payload.get("message") or (raw[:200] if raw else None))
        except urllib.error.URLError as exc:
            raise ApiError(0, "NETWORK", f"Could not reach the forum ({exc.reason}).")

    # -- boards --------------------------------------------------------------
    def categories(self):
        return self._request("GET", "categories")

    def create_category(self, name, description="", sort_order=0):
        return self._request("POST", "categories",
                             body={"name": name, "description": description, "sort_order": sort_order})

    def update_category(self, cid, **fields):
        return self._request("PATCH", f"categories/{cid}", body=fields)

    # -- threads -------------------------------------------------------------
    def threads(self, cat=0, flag="", sort="active", filt="", tag="", page=1):
        return self._request("GET", "threads", params={
            "cat": cat, "flag": flag, "sort": sort, "filter": filt, "tag": tag, "page": page,
        })

    def thread(self, tid):
        return self._request("GET", f"threads/{tid}")

    def create_thread(self, category_id, title, body, flag="none"):
        return self._request("POST", "threads",
                             body={"category_id": category_id, "title": title, "body": body, "flag": flag})

    def patch_thread(self, tid, **fields):
        return self._request("PATCH", f"threads/{tid}", body=fields)

    def delete_thread(self, tid):
        return self._request("DELETE", f"threads/{tid}")

    def mark_read(self, tid):
        return self._request("POST", f"threads/{tid}/read")

    def follow(self, tid):
        return self._request("POST", f"threads/{tid}/follow")

    def unfollow(self, tid):
        return self._request("DELETE", f"threads/{tid}/follow")

    # -- replies -------------------------------------------------------------
    def reply(self, tid, body):
        return self._request("POST", f"threads/{tid}/replies", body={"body": body})

    def delete_reply(self, rid):
        return self._request("DELETE", f"replies/{rid}")

    def solve(self, rid):
        return self._request("POST", f"replies/{rid}/solve")

    def unsolve(self, rid):
        return self._request("DELETE", f"replies/{rid}/solve")

    # -- installs / roles ----------------------------------------------------
    def installs(self):
        return self._request("GET", "installs")

    def set_role(self, iid, role):
        return self._request("PATCH", f"installs/{iid}/role", body={"role": role})

    # -- connection check ----------------------------------------------------
    def ping(self):
        """Cheap authenticated call to confirm the key works. Returns True or raises."""
        self._request("GET", "categories")
        return True

# ===== SNAPSMACK EOF =====

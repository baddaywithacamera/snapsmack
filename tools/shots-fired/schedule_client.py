"""
SHOTS FIRED — schedule_client.py

The HTTP transport that talks to a single spoke's scheduling API. SHOTS FIRED is
a VIEW-AND-SHUFFLE tool: it lists a site's FUTURE-dated posts (which the CMS
already holds back via its img_date date-gate) and writes a new img_date back to
move one on the calendar. It never creates posts.

TWO server routes are needed, and NEITHER EXISTS YET (see docs/shots-fired-spec.md
"Server API" — both are flagged MUST-ADD):

    (a) LIST scheduled posts   GET  {site}/smack-schedule.php?action=list
    (b) RESCHEDULE (set date)  POST {site}/smack-schedule.php?action=set_date

This client calls them exactly as specified and DEGRADES GRACEFULLY: a 404 (route
not deployed on that spoke yet) is reported as ApiStatus.NOT_DEPLOYED so the UI
can show "no scheduling API yet" for that site instead of an error, and the rest
of the fleet still loads. Auth mirrors the fleet's existing endpoints — the key
travels in BOTH the `X-Snap-Key` header (what core/api-auth.php checks) and an
`Authorization: Bearer` header (what the api.php routes check), so whichever the
new route adopts, this client already speaks it.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


import datetime
from dataclasses import dataclass, field
from enum import Enum
from typing import List, Optional

import requests

# The intended route file on each spoke. A single endpoint with two actions keeps
# it parallel to the existing smack-audit.php (action=list / action=update_title),
# which is the pattern the spec says to copy.
_ROUTE_FILE = "smack-schedule.php"
_UA = "ShotsFired/0.1.0"
_TIMEOUT = (10, 30)   # (connect, read)


class ApiStatus(Enum):
    OK = "ok"                    # the route answered
    NOT_DEPLOYED = "not_deployed"  # 404 — the MUST-ADD route isn't on this spoke yet
    UNAUTHORIZED = "unauthorized"  # 401/403 — key missing / wrong scope
    ERROR = "error"              # network / non-JSON / server error


@dataclass
class ScheduledPost:
    """One future-dated post as the calendar sees it. `img_date` is the publish
    date the CMS gates on — moving it IS the reschedule."""
    snap_id: int
    title: str
    img_date: datetime.datetime
    site_name: str
    site_url: str
    thumb_url: str = ""
    img_file: str = ""
    post_id: Optional[int] = None
    raw: dict = field(default_factory=dict)


@dataclass
class ListResult:
    status: ApiStatus
    posts: List[ScheduledPost] = field(default_factory=list)
    message: str = ""


@dataclass
class RescheduleResult:
    status: ApiStatus
    message: str = ""


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

def _headers(api_key: str) -> dict:
    return {
        "User-Agent": _UA,
        "X-Snap-Key": api_key,                 # core/api-auth.php form
        "Authorization": f"Bearer {api_key}",  # api.php route form
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",
    }


def _endpoint(site_url: str) -> str:
    return f"{site_url.rstrip('/')}/{_ROUTE_FILE}"


def parse_dt(value: str) -> Optional[datetime.datetime]:
    """Parse a server datetime string ('YYYY-MM-DD HH:MM:SS' or ISO). None on junk."""
    if not value:
        return None
    v = value.strip().replace("T", " ")
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y-%m-%d"):
        try:
            return datetime.datetime.strptime(v[:len(fmt) + 2].strip(), fmt)
        except ValueError:
            continue
    try:
        return datetime.datetime.fromisoformat(value.strip())
    except Exception:
        return None


def fmt_dt(dt: datetime.datetime) -> str:
    """The wire format the reschedule route expects (matches snap_images.img_date)."""
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def _classify(resp) -> ApiStatus:
    if resp.status_code == 404:
        return ApiStatus.NOT_DEPLOYED
    if resp.status_code in (401, 403):
        return ApiStatus.UNAUTHORIZED
    if resp.status_code != 200:
        return ApiStatus.ERROR
    return ApiStatus.OK


# ---------------------------------------------------------------------------
# (a) LIST scheduled posts
# ---------------------------------------------------------------------------

def list_scheduled(site, lookahead_days: int = 60) -> ListResult:
    """GET the site's future-dated posts. `site` is a fleet.Site.

    Calls  GET {site}/smack-schedule.php?action=list&from=<now>&to=<now+N days>
    and expects  {"ok": true, "posts": [ {snap_id, img_title, img_date, ...}, ... ]}
    A site with no api_key never hits the network — it returns UNAUTHORIZED so the
    UI can prompt for a key. A 404 returns NOT_DEPLOYED (route not on this spoke)."""
    if not getattr(site, "has_key", False):
        return ListResult(ApiStatus.UNAUTHORIZED,
                          message="no API key for this site")

    now = datetime.datetime.now()
    params = {
        "action": "list",
        "from":   fmt_dt(now),
        "to":     fmt_dt(now + datetime.timedelta(days=max(1, lookahead_days))),
    }
    try:
        resp = requests.get(_endpoint(site.url), params=params,
                            headers=_headers(site.api_key), timeout=_TIMEOUT)
    except requests.RequestException as e:
        return ListResult(ApiStatus.ERROR, message=f"unreachable: {e}")

    status = _classify(resp)
    if status is ApiStatus.NOT_DEPLOYED:
        return ListResult(status, message="scheduling API not deployed on this site")
    if status is ApiStatus.UNAUTHORIZED:
        return ListResult(status, message="API key rejected")
    if status is ApiStatus.ERROR:
        return ListResult(status, message=f"HTTP {resp.status_code}")

    try:
        data = resp.json()
    except ValueError:
        return ListResult(ApiStatus.ERROR, message="non-JSON response")
    if not data.get("ok", True):
        return ListResult(ApiStatus.ERROR, message=str(data.get("error", "server error")))

    posts: List[ScheduledPost] = []
    for row in (data.get("posts") or []):
        dt = parse_dt(str(row.get("img_date", "")))
        if dt is None:
            continue
        snap_id = int(row.get("snap_id") or row.get("id") or 0)
        if not snap_id:
            continue
        posts.append(ScheduledPost(
            snap_id=snap_id,
            title=(row.get("img_title") or row.get("title") or "").strip(),
            img_date=dt,
            site_name=site.display,
            site_url=site.url,
            thumb_url=(row.get("thumb_url") or row.get("img_thumb_square") or "").strip(),
            img_file=(row.get("img_file") or "").strip(),
            post_id=(int(row["post_id"]) if row.get("post_id") else None),
            raw=row,
        ))
    posts.sort(key=lambda p: p.img_date)
    return ListResult(ApiStatus.OK, posts=posts)


# ---------------------------------------------------------------------------
# (b) RESCHEDULE — set img_date
# ---------------------------------------------------------------------------

def reschedule(site, snap_id: int, new_dt: datetime.datetime) -> RescheduleResult:
    """POST a new img_date for one post. `site` is a fleet.Site.

    Calls  POST {site}/smack-schedule.php?action=set_date
           body: snap_id=<int>&img_date=<YYYY-MM-DD HH:MM:SS>
    and expects  {"ok": true}  (or 404 {"ok": false, "error": ...} if no such
    published row on that spoke). Because the feed already gates on
    img_date <= NOW(), writing a future date re-hides the post and a past/now date
    reveals it — the whole reschedule is this one UPDATE."""
    if not getattr(site, "has_key", False):
        return RescheduleResult(ApiStatus.UNAUTHORIZED, "no API key for this site")

    form = {"action": "set_date", "snap_id": str(int(snap_id)), "img_date": fmt_dt(new_dt)}
    try:
        resp = requests.post(_endpoint(site.url), data=form,
                             headers=_headers(site.api_key), timeout=_TIMEOUT)
    except requests.RequestException as e:
        return RescheduleResult(ApiStatus.ERROR, f"unreachable: {e}")

    status = _classify(resp)
    if status is ApiStatus.NOT_DEPLOYED:
        # Could be the whole route (not deployed) OR a genuinely-missing row.
        # Distinguish via the JSON body when present.
        try:
            err = (resp.json() or {}).get("error", "")
        except ValueError:
            err = ""
        return RescheduleResult(status,
                                err or "scheduling API not deployed on this site")
    if status is ApiStatus.UNAUTHORIZED:
        return RescheduleResult(status, "API key rejected")
    if status is ApiStatus.ERROR:
        return RescheduleResult(status, f"HTTP {resp.status_code}")

    try:
        data = resp.json()
    except ValueError:
        return RescheduleResult(ApiStatus.ERROR, "non-JSON response")
    if not data.get("ok", False):
        return RescheduleResult(ApiStatus.ERROR, str(data.get("error", "reschedule failed")))
    return RescheduleResult(ApiStatus.OK, "rescheduled")
# ===== SNAPSMACK EOF =====

"""FED UP — public fediverse fetcher.

Reads what any fediverse server shows a stranger: the actor document, the
outbox (paged), each Note's attachments and counts, the followers / following
collections, and the public replies under a post. No token, no signature —
v1 archives what the world can see. Where a server hides a collection behind
authorized fetch (401/403) or simply does not publish it, the fetcher says
``unavailable`` with the reason instead of failing the whole run.

Pixelfed publishes an outbox that only carries ``totalItems`` (no pages), so
for it — and for Mastodon, which serves the same shape — the REST status
route is tried first and the outbox crawl is the fallback. That is the path
the CMS curator already uses (core/fediverse.php sv_masto_statuses); this is
the Python of it.

Every request carries a browser-shaped User-Agent: several fediverse hosts sit
behind Cloudflare rules that 1010-block a bare python-requests UA.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import html
import re
import time
from dataclasses import dataclass, field
from typing import Callable, Iterator, List, Optional
from urllib.parse import quote, urlparse

import requests

AP_ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", application/json;q=0.5'
USER_AGENT = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) FED-UP/0.1 (+https://snapsmack.ca; fediverse backup)")
PUBLIC = "https://www.w3.org/ns/activitystreams#Public"
REST_PAGE = 20   # Pixelfed refuses limit > 24; Mastodon allows 40 — 20 suits both
HANDLE_RE = re.compile(r"^@?([A-Za-z0-9_.-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$")

LogFn = Callable[[str], None]


class FetchError(RuntimeError):
    pass


@dataclass
class Attachment:
    url: str
    media_type: str = ""
    name: str = ""          # alt text
    width: int = 0
    height: int = 0


@dataclass
class Post:
    id: str
    url: str = ""
    published: str = ""
    content: str = ""       # HTML as served
    text: str = ""          # plain text
    summary: str = ""       # content warning
    sensitive: bool = False
    visibility: str = "public"
    tags: List[str] = field(default_factory=list)
    attachments: List[Attachment] = field(default_factory=list)
    in_reply_to: str = ""
    likes: Optional[int] = None
    shares: Optional[int] = None
    replies_count: Optional[int] = None
    replies_url: str = ""
    replies: List[dict] = field(default_factory=list)
    raw: dict = field(default_factory=dict)


@dataclass
class Profile:
    actor_url: str
    handle: str
    domain: str
    preferred_username: str = ""
    display_name: str = ""
    summary: str = ""
    url: str = ""
    icon_url: str = ""
    image_url: str = ""
    fields: List[dict] = field(default_factory=list)
    outbox: str = ""
    followers: str = ""
    following: str = ""
    also_known_as: List[str] = field(default_factory=list)
    software: str = ""      # best guess: mastodon / pixelfed / gotosocial / snapsmack / unknown
    rest_id: str = ""       # Mastodon-API account id when the REST route answers
    raw: dict = field(default_factory=dict)


@dataclass
class Graph:
    items: List[dict] = field(default_factory=list)   # [{"actor": url, "acct": "name@host"}]
    total: Optional[int] = None
    unavailable: str = ""                              # reason when the server hid it
    capped: int = 0                                    # list stopped at this many (huge accounts)
    partial: bool = False                              # server stopped answering before the end


class Fetcher:
    """One session, polite pacing, bounded pages. ``pause`` seconds between requests."""

    def __init__(self, pause: float = 0.35, timeout: int = 25, log: Optional[LogFn] = None,
                 session: Optional[requests.Session] = None):
        self.pause = pause
        self.timeout = timeout
        self.log = log or (lambda s: None)
        self.s = session or requests.Session()
        self.s.headers.update({"User-Agent": USER_AGENT})
        self._last = 0.0

    # ── transport ──
    def _wait(self):
        gap = time.monotonic() - self._last
        if gap < self.pause:
            time.sleep(self.pause - gap)
        self._last = time.monotonic()

    def get_json(self, url: str, accept: str = AP_ACCEPT, _retries: int = 2) -> dict:
        self._wait()
        try:
            r = self.s.get(url, headers={"Accept": accept}, timeout=self.timeout, allow_redirects=True)
        except requests.RequestException as e:
            raise FetchError(f"{url}: {e}") from e
        if r.status_code in (401, 403):
            raise FetchError(f"{url}: HTTP {r.status_code} (this server only answers signed requests)")
        if r.status_code == 404:
            raise FetchError(f"{url}: not found (404)")
        if r.status_code == 429:
            # Some servers (mastodon.social) answer 429 to UNSIGNED collection
            # requests as a soft "no" — no Retry-After, forever. Two honest
            # waits, then treat it as unavailable so the caller can fall back.
            if _retries <= 0:
                raise FetchError(f"{url}: HTTP 429 (kept refusing; likely signed-only)")
            retry = r.headers.get("Retry-After", "")
            wait = int(retry) if retry.isdigit() else 10
            self.log(f"rate limited by {urlparse(url).netloc}; waiting {wait}s")
            time.sleep(min(wait, 60))
            return self.get_json(url, accept, _retries - 1)
        if r.status_code >= 400:
            raise FetchError(f"{url}: HTTP {r.status_code}")
        try:
            data = r.json()
        except ValueError as e:
            raise FetchError(f"{url}: not JSON") from e
        if not isinstance(data, (dict, list)):
            raise FetchError(f"{url}: unexpected body")
        return data

    def download(self, url: str, dest: str, on_bytes: Optional[Callable[[int], None]] = None) -> int:
        """Stream a media file to ``dest``. Returns bytes written."""
        self._wait()
        try:
            with self.s.get(url, stream=True, timeout=self.timeout) as r:
                if r.status_code >= 400:
                    raise FetchError(f"{url}: HTTP {r.status_code}")
                n = 0
                with open(dest, "wb") as fh:
                    for chunk in r.iter_content(1 << 16):
                        if chunk:
                            fh.write(chunk); n += len(chunk)
                            if on_bytes:
                                on_bytes(len(chunk))
                return n
        except requests.RequestException as e:
            raise FetchError(f"{url}: {e}") from e

    # ── identity ──
    @staticmethod
    def split_handle(handle: str) -> tuple:
        m = HANDLE_RE.match(handle.strip())
        if not m:
            raise FetchError("A handle looks like @name@instance.")
        return m.group(1), m.group(2).lower()

    def webfinger(self, handle: str) -> str:
        """acct → actor URL via the instance's own WebFinger."""
        name, host = self.split_handle(handle)
        doc = self.get_json(f"https://{host}/.well-known/webfinger?resource=" + quote(f"acct:{name}@{host}", safe=""),
                            accept="application/jrd+json, application/json")
        for link in doc.get("links", []) if isinstance(doc, dict) else []:
            if link.get("rel") == "self" and "activity+json" in str(link.get("type", "")) and link.get("href"):
                return str(link["href"])
        for link in doc.get("links", []) if isinstance(doc, dict) else []:
            if link.get("rel") == "self" and link.get("href"):
                return str(link["href"])
        raise FetchError(f"{host} does not know @{name}@{host}.")

    def profile(self, handle: str) -> Profile:
        name, host = self.split_handle(handle)
        actor_url = self.webfinger(handle)
        try:
            a = self.get_json(actor_url)
        except FetchError as e:
            # Authorized fetch (mastodon.social and friends): the actor document is
            # signed-only, but the same public facts are on the REST API.
            self.log(f"actor document not readable ({e}); using the public API instead")
            return self._profile_from_rest(name, host, actor_url)
        if not isinstance(a, dict) or a.get("type") not in ("Person", "Service", "Group", "Organization", "Application"):
            raise FetchError(f"{actor_url} is not an actor document.")
        p = Profile(actor_url=str(a.get("id") or actor_url), handle=f"{name}@{host}", domain=host, raw=a)
        p.preferred_username = str(a.get("preferredUsername") or name)
        p.display_name = html.unescape(str(a.get("name") or ""))
        p.summary = str(a.get("summary") or "")
        p.url = str(a.get("url") or "") if isinstance(a.get("url"), str) else ""
        p.icon_url = _media_url(a.get("icon"))
        p.image_url = _media_url(a.get("image"))
        p.outbox = str(a.get("outbox") or "")
        p.followers = str(a.get("followers") or "")
        p.following = str(a.get("following") or "")
        aka = a.get("alsoKnownAs") or []
        p.also_known_as = [str(x) for x in (aka if isinstance(aka, list) else [aka]) if x]
        for f in a.get("attachment") or []:
            if isinstance(f, dict) and f.get("type") == "PropertyValue":
                p.fields.append({"name": str(f.get("name", "")), "value": str(f.get("value", ""))})
        p.software = self._guess_software(host, a)
        p.rest_id = self._rest_account_id(host, p.preferred_username)
        return p

    def _rest_account_id(self, host: str, username: str) -> str:
        try:
            look = self.get_json(f"https://{host}/api/v1/accounts/lookup?acct=" + quote(username), accept="application/json")
        except FetchError:
            return ""
        return re.sub(r"[^A-Za-z0-9]", "", str(look.get("id", ""))) if isinstance(look, dict) else ""

    def _profile_from_rest(self, name: str, host: str, actor_url: str) -> Profile:
        try:
            a = self.get_json(f"https://{host}/api/v1/accounts/lookup?acct=" + quote(name), accept="application/json")
        except FetchError as e:
            raise FetchError(f"{host} answers neither the actor document nor the public API for @{name}: {e}") from e
        if not isinstance(a, dict) or not a.get("id"):
            raise FetchError(f"{host} has no public account record for @{name}.")
        p = Profile(actor_url=actor_url, handle=f"{name}@{host}", domain=host, raw=a)
        p.preferred_username = str(a.get("username") or name)
        p.display_name = html.unescape(str(a.get("display_name") or ""))
        p.summary = str(a.get("note") or "")
        p.url = str(a.get("url") or "")
        p.icon_url = str(a.get("avatar_static") or a.get("avatar") or "")
        p.image_url = str(a.get("header_static") or a.get("header") or "")
        for f in a.get("fields") or []:
            if isinstance(f, dict):
                p.fields.append({"name": str(f.get("name", "")), "value": str(f.get("value", ""))})
        p.rest_id = re.sub(r"[^A-Za-z0-9]", "", str(a.get("id")))
        p.software = self._guess_software(host, a)
        # Collections are signed-only here too; graph() falls through to the API.
        p.followers = actor_url.rstrip("/") + "/followers"
        p.following = actor_url.rstrip("/") + "/following"
        return p

    def _guess_software(self, host: str, actor: dict) -> str:
        try:
            ni = self.get_json(f"https://{host}/.well-known/nodeinfo", accept="application/json")
            href = ""
            for l in ni.get("links", []) if isinstance(ni, dict) else []:
                if str(l.get("rel", "")).endswith("/schema/2.0") or str(l.get("rel", "")).endswith("/schema/2.1"):
                    href = str(l.get("href", ""))
            if href:
                doc = self.get_json(href, accept="application/json")
                return str((doc.get("software") or {}).get("name", "unknown")).lower()
        except FetchError:
            pass
        return "unknown"

    # ── collections ──
    def iter_collection(self, url: str, max_items: int = 100000, max_pages: int = 5000) -> Iterator[dict]:
        """Walk an (Ordered)Collection: embedded items, or first → next pages."""
        seen = 0
        page = self.get_json(url)
        if not isinstance(page, dict):
            return
        # A collection whose items sit on 'first' — a URL or an embedded page.
        first = page.get("first")
        pages = 0
        while isinstance(page, dict) and pages < max_pages:
            items = page.get("orderedItems")
            if items is None:
                items = page.get("items")
            if isinstance(items, list):
                for it in items:
                    yield it
                    seen += 1
                    if seen >= max_items:
                        return
            nxt = page.get("next")
            if items is None and first is not None and pages == 0:
                nxt = first
            first = None
            if isinstance(nxt, dict):
                page = nxt
            elif isinstance(nxt, str) and nxt:
                page = self.get_json(nxt)
            else:
                return
            pages += 1

    def collection_total(self, url: str) -> Optional[int]:
        try:
            page = self.get_json(url)
            t = page.get("totalItems") if isinstance(page, dict) else None
            return int(t) if t is not None else None
        except (FetchError, ValueError, TypeError):
            return None

    def graph(self, url: str, max_items: int = 5000, prof: Optional[Profile] = None, which: str = "") -> Graph:
        """ActivityPub collection first; if it is hidden or signed-only and the REST
        API knows the account, /api/v1/accounts/{id}/followers|following (public
        unless the owner hid it). ``which`` is 'followers' or 'following'."""
        g = Graph()
        if url:
            try:
                g.total = self.collection_total(url)
                for it in self.iter_collection(url, max_items=max_items):
                    actor = it if isinstance(it, str) else (it.get("id") if isinstance(it, dict) else "")
                    if actor:
                        g.items.append({"actor": str(actor), "acct": _acct_from_actor_url(str(actor))})
            except FetchError as e:
                g.unavailable = str(e)
        else:
            g.unavailable = "the actor publishes no such collection"
        if not g.items and prof is not None and prof.rest_id and which in ("followers", "following"):
            rest = self._graph_from_rest(prof.domain, prof.rest_id, which, max_items)
            if rest:
                g.items = rest
                g.unavailable = ""
                if g.total is None:
                    g.total = len(rest)
        if not g.items and g.total and not g.unavailable:
            g.unavailable = f"the server reports {g.total} but publishes no list (hidden by the account or the software)"
        if len(g.items) >= max_items and (g.total is None or g.total > max_items):
            g.capped = max_items
        elif g.items and g.total is not None and len(g.items) < g.total:
            g.partial = True
        return g

    def _graph_from_rest(self, host: str, account_id: str, which: str, max_items: int) -> List[dict]:
        out: List[dict] = []
        max_id = ""
        for _ in range(2000):
            url = f"https://{host}/api/v1/accounts/{account_id}/{which}?limit=80" + (f"&max_id={max_id}" if max_id else "")
            try:
                rows = self.get_json(url, accept="application/json")
            except FetchError:
                break
            if not isinstance(rows, list) or not rows:
                break
            for a in rows:
                if not isinstance(a, dict):
                    continue
                acct = str(a.get("acct") or "")
                if acct and "@" not in acct:
                    acct = f"{acct}@{host}"
                out.append({"actor": str(a.get("uri") or a.get("url") or ""), "acct": acct})
            if len(out) >= max_items or len(rows) < 2:
                break
            last = str(rows[-1].get("id", ""))
            if not last or last == max_id:
                break
            max_id = last
        return out

    # ── posts ──
    def posts(self, prof: Profile, known_ids: Optional[set] = None, max_posts: int = 100000,
              on_post: Optional[Callable[[Post], None]] = None, fetch_replies: bool = True) -> List[Post]:
        """All public posts, newest first. Stops early on a page made only of ``known_ids``
        (incremental run). REST route first, outbox crawl if the REST route gives nothing."""
        known = known_ids or set()
        out: List[Post] = []
        rest = list(self._iter_rest_statuses(prof, known, max_posts))
        if rest:
            self.log(f"REST route answered — {len(rest)} status(es)")
            for st in rest:
                p = _post_from_status(st)
                if p is None:
                    continue
                if fetch_replies and p.replies_count and p.id:
                    p.replies = self._replies_from_rest(prof.domain, str(st.get("id", "")),
                                                        account_id=str((st.get("account") or {}).get("id", "")))
                out.append(p)
                if on_post:
                    on_post(p)
            return out
        self.log("REST route empty or absent — crawling the outbox")
        if not prof.outbox:
            return out
        page_known = 0; page_seen = 0
        for act in self.iter_collection(prof.outbox, max_items=max_posts * 3):
            if not isinstance(act, dict):
                continue
            if act.get("type") not in ("Create", None) and act.get("type") != "Note":
                continue
            obj = act.get("object", act if act.get("type") == "Note" else None)
            if isinstance(obj, str):
                try:
                    obj = self.get_json(obj)
                except FetchError as e:
                    self.log(f"skipped one post: {e}")
                    continue
            if not isinstance(obj, dict) or obj.get("type") not in ("Note", "Article", "Page", "Image"):
                continue
            if not _is_public(act, obj):
                continue
            p = _post_from_note(obj)
            page_seen += 1
            if p.id in known:
                page_known += 1
                # Ten known posts in a row = we've reached the previous snapshot.
                if page_known >= 10:
                    break
                continue
            page_known = 0
            if fetch_replies and p.replies_url:
                p.replies = self._replies_from_collection(p.replies_url)
            out.append(p)
            if on_post:
                on_post(p)
            if len(out) >= max_posts:
                break
        return out

    def _iter_rest_statuses(self, prof: Profile, known: set, max_posts: int) -> Iterator[dict]:
        base = f"https://{prof.domain}"
        try:
            look = self.get_json(base + "/api/v1/accounts/lookup?acct=" + quote(prof.preferred_username), accept="application/json")
        except FetchError:
            return
        acct_id = re.sub(r"[^A-Za-z0-9]", "", str((look or {}).get("id", ""))) if isinstance(look, dict) else ""
        if not acct_id:
            return
        routes = [f"{base}/api/v1/accounts/{acct_id}/statuses", f"{base}/api/pixelfed/v1/accounts/{acct_id}/statuses"]
        for route in routes:
            got_any = False
            max_id = ""
            yielded = 0
            known_run = 0
            while True:
                url = f"{route}?limit={REST_PAGE}" + (f"&max_id={max_id}" if max_id else "")
                try:
                    rows = self.get_json(url, accept="application/json")
                except FetchError:
                    rows = []
                if not isinstance(rows, list) or not rows:
                    break
                got_any = True
                for st in rows:
                    if not isinstance(st, dict):
                        continue
                    pid = str(st.get("uri") or st.get("url") or "")
                    if pid in known:
                        known_run += 1
                        if known_run >= 10:
                            return
                        continue
                    known_run = 0
                    yield st
                    yielded += 1
                    if yielded >= max_posts:
                        return
                max_id = str(rows[-1].get("id", ""))
                if not max_id or len(rows) < 2:
                    break
            if got_any:
                return

    def _replies_from_rest(self, host: str, status_id: str, limit: int = 200, account_id: str = "") -> List[dict]:
        """Mastodon: /api/v1/statuses/{id}/context (public). Pixelfed gates that one
        for guests but serves its own /api/v2/comments/{account}/status/{id}, paged."""
        if not status_id:
            return []
        rows: List[dict] = []
        try:
            ctx = self.get_json(f"https://{host}/api/v1/statuses/{status_id}/context", accept="application/json")
            rows = list(ctx.get("descendants") or []) if isinstance(ctx, dict) else []
        except FetchError:
            pass
        if not rows and account_id:
            url = f"https://{host}/api/v2/comments/{account_id}/status/{status_id}?limit=10"
            for _ in range(20):
                try:
                    page = self.get_json(url, accept="application/json")
                except FetchError:
                    break
                data = page.get("data") if isinstance(page, dict) else page
                if not isinstance(data, list) or not data:
                    break
                rows.extend(data)
                nxt = (((page.get("meta") or {}).get("pagination") or {}).get("links") or {}).get("next") if isinstance(page, dict) else ""
                if not nxt or len(rows) >= limit:
                    break
                url = str(nxt) + ("&limit=10" if "limit=" not in str(nxt) else "")
        out = []
        for st in rows:
            if not isinstance(st, dict):
                continue
            acct = (st.get("account") or {})
            out.append({
                "id": str(st.get("uri") or st.get("url") or ""),
                "author": str(acct.get("acct") or ""),
                "author_url": str(acct.get("url") or ""),
                "published": str(st.get("created_at") or ""),
                "content": str(st.get("content") or ""),
                "text": _strip_tags(str(st.get("content_text") or st.get("content") or "")),
            })
            if len(out) >= limit:
                break
        return out

    def _replies_from_collection(self, url: str, limit: int = 200) -> List[dict]:
        out = []
        try:
            for it in self.iter_collection(url, max_items=limit, max_pages=10):
                obj = it
                if isinstance(it, str):
                    try:
                        obj = self.get_json(it)
                    except FetchError:
                        continue
                if not isinstance(obj, dict) or obj.get("type") not in ("Note", "Article", None):
                    continue
                attr = obj.get("attributedTo", "")
                attr = attr if isinstance(attr, str) else (attr.get("id", "") if isinstance(attr, dict) else "")
                out.append({
                    "id": str(obj.get("id") or ""),
                    "author": _acct_from_actor_url(str(attr)),
                    "author_url": str(attr),
                    "published": str(obj.get("published") or ""),
                    "content": str(obj.get("content") or ""),
                    "text": _strip_tags(str(obj.get("content") or "")),
                })
        except FetchError as e:
            self.log(f"replies not readable: {e}")
        return out


# ── mapping helpers ──

def _media_url(v) -> str:
    if isinstance(v, str):
        return v
    if isinstance(v, dict):
        return str(v.get("url") or "")
    if isinstance(v, list) and v:
        return _media_url(v[0])
    return ""


def _acct_from_actor_url(url: str) -> str:
    """Best-effort name@host from the common actor URL shapes; '' if unguessable."""
    try:
        u = urlparse(url)
    except ValueError:
        return ""
    host = u.netloc.lower()
    path = u.path.rstrip("/")
    m = re.search(r"/(?:users|u|@|profile|accounts)/?@?([A-Za-z0-9_.-]+)$", path) or re.search(r"/@([A-Za-z0-9_.-]+)$", path)
    if m and host:
        return f"{m.group(1)}@{host}"
    return ""


_TAG_RE = re.compile(r"<[^>]+>")


def _strip_tags(s: str) -> str:
    s = re.sub(r"</p>\s*<p>", "\n\n", s)
    s = re.sub(r"<br\s*/?>", "\n", s)
    return html.unescape(_TAG_RE.sub("", s)).strip()


def _is_public(act: dict, obj: dict) -> bool:
    for aud in (act.get("to"), act.get("cc"), obj.get("to"), obj.get("cc")):
        for v in (aud if isinstance(aud, list) else [aud]):
            if v in (PUBLIC, "as:Public", "Public"):
                return True
    return False


def _visibility(act_or_obj: dict) -> str:
    to = act_or_obj.get("to") or []
    cc = act_or_obj.get("cc") or []
    to = to if isinstance(to, list) else [to]
    cc = cc if isinstance(cc, list) else [cc]
    if PUBLIC in to:
        return "public"
    if PUBLIC in cc:
        return "unlisted"
    return "followers"


def _post_from_note(obj: dict) -> Post:
    p = Post(id=str(obj.get("id") or ""), raw=obj)
    p.url = str(obj.get("url") or p.id) if isinstance(obj.get("url"), str) else p.id
    p.published = str(obj.get("published") or "")
    p.content = str(obj.get("content") or "")
    p.text = _strip_tags(p.content)
    p.summary = str(obj.get("summary") or "")
    p.sensitive = bool(obj.get("sensitive", False))
    p.visibility = _visibility(obj)
    irt = obj.get("inReplyTo")
    p.in_reply_to = irt if isinstance(irt, str) else (irt.get("id", "") if isinstance(irt, dict) else "")
    for t in obj.get("tag") or []:
        if isinstance(t, dict) and t.get("type") == "Hashtag":
            p.tags.append(str(t.get("name", "")).lstrip("#"))
    for a in obj.get("attachment") or []:
        if not isinstance(a, dict):
            continue
        u = str(a.get("url") or "")
        if isinstance(a.get("url"), list) and a["url"]:
            u = _media_url(a["url"])
        if not u:
            continue
        p.attachments.append(Attachment(url=u, media_type=str(a.get("mediaType") or ""), name=str(a.get("name") or ""),
                                        width=int(a.get("width") or 0), height=int(a.get("height") or 0)))
    for key, attr in (("likes", "likes"), ("shares", "shares")):
        v = obj.get(key)
        if isinstance(v, dict) and v.get("totalItems") is not None:
            try:
                setattr(p, attr, int(v["totalItems"]))
            except (TypeError, ValueError):
                pass
    r = obj.get("replies")
    if isinstance(r, dict):
        p.replies_url = str(r.get("id") or "")
        if r.get("totalItems") is not None:
            try:
                p.replies_count = int(r["totalItems"])
            except (TypeError, ValueError):
                pass
        elif isinstance(r.get("first"), dict):
            p.replies_url = str(r["first"].get("id") or r["first"].get("next") or p.replies_url)
    elif isinstance(r, str):
        p.replies_url = r
    return p


def _post_from_status(st: dict) -> Optional[Post]:
    """Mastodon / Pixelfed status entity → Post. Boosts are skipped (not our content)."""
    if st.get("reblog"):
        return None
    pid = str(st.get("uri") or st.get("url") or "")
    if not pid:
        return None
    p = Post(id=pid, raw=st)
    p.url = str(st.get("url") or pid)
    p.published = str(st.get("created_at") or "")
    p.content = str(st.get("content") or "")
    p.text = str(st.get("content_text") or "") or _strip_tags(p.content)
    p.summary = str(st.get("spoiler_text") or "")
    p.sensitive = bool(st.get("sensitive", False))
    p.visibility = str(st.get("visibility") or "public")
    irt = st.get("in_reply_to_id")
    p.in_reply_to = str(irt) if irt else ""
    for t in st.get("tags") or []:
        if isinstance(t, dict) and t.get("name"):
            p.tags.append(str(t["name"]).lstrip("#"))
    for m in st.get("media_attachments") or []:
        if not isinstance(m, dict):
            continue
        u = str(m.get("url") or m.get("remote_url") or m.get("preview_url") or "")
        if not u:
            continue
        meta = (m.get("meta") or {}).get("original") or {}
        mt = {"image": "image/jpeg", "video": "video/mp4", "gifv": "video/mp4", "audio": "audio/mpeg"}.get(str(m.get("type")), "")
        p.attachments.append(Attachment(url=u, media_type=str(m.get("mime_type") or mt), name=str(m.get("description") or ""),
                                        width=int(meta.get("width") or 0), height=int(meta.get("height") or 0)))
    def _n(k):
        v = st.get(k)
        try:
            return int(v) if v is not None else None
        except (TypeError, ValueError):
            return None
    p.likes = _n("favourites_count")
    p.shares = _n("reblogs_count")
    p.replies_count = _n("replies_count") if st.get("replies_count") is not None else _n("reply_count")  # Pixelfed spells it reply_count
    return p

# ===== SNAPSMACK EOF =====

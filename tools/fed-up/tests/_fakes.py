"""A fake fediverse server for the FED UP tests: a dict of URL → JSON (or bytes)
served through the same ``session.get`` surface requests offers, so the real
Fetcher code paths run — webfinger, actor, outbox pages, REST routes, media.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
import json
from urllib.parse import parse_qs, urlparse


class FakeResponse:
    def __init__(self, status, body=None, raw=b"", headers=None):
        self.status_code = status
        self._body = body
        self._raw = raw
        self.headers = headers or {}

    def json(self):
        if self._body is None:
            raise ValueError("no json")
        return self._body

    def iter_content(self, n):
        for i in range(0, len(self._raw), n):
            yield self._raw[i:i + n]

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False


class FakeSession:
    def __init__(self, routes):
        self.routes = routes          # url → dict|list|bytes|int(status)
        self.headers = {}
        self.calls = []

    def get(self, url, headers=None, timeout=None, allow_redirects=True, stream=False):
        self.calls.append(url)
        if url in self.routes:
            v = self.routes[url]
        else:
            # allow route matching ignoring query order: compare path + sorted query
            v = None
            u = urlparse(url)
            for k, val in self.routes.items():
                ku = urlparse(k)
                if ku.netloc == u.netloc and ku.path == u.path and parse_qs(ku.query) == parse_qs(u.query):
                    v = val
                    break
            if v is None:
                return FakeResponse(404)
        if isinstance(v, int):
            return FakeResponse(v)
        if isinstance(v, bytes):
            return FakeResponse(200, raw=v)
        return FakeResponse(200, body=json.loads(json.dumps(v)))


def mastodon_like(host="masto.test", user="leo", n_posts=25, hidden_graph=False):
    """Routes for a Mastodon-shaped account: webfinger, actor, nodeinfo, outbox in
    pages of 10 (Create/Note), followers/following, and NO REST route (404) so the
    outbox crawl is exercised."""
    actor = f"https://{host}/users/{user}"
    r = {}
    r[f"https://{host}/.well-known/webfinger?resource=acct%3A{user}%40{host}"] = {
        "subject": f"acct:{user}@{host}",
        "links": [{"rel": "self", "type": "application/activity+json", "href": actor}]}
    r[actor] = {"id": actor, "type": "Person", "preferredUsername": user, "name": "Leo &amp; co",
                "summary": "<p>photos</p>", "url": f"https://{host}/@{user}",
                "icon": {"type": "Image", "url": f"https://{host}/media/avatar.png"},
                "outbox": actor + "/outbox", "followers": actor + "/followers", "following": actor + "/following",
                "attachment": [{"type": "PropertyValue", "name": "Site", "value": "x"}]}
    r[f"https://{host}/.well-known/nodeinfo"] = {"links": [{"rel": "http://nodeinfo.diaspora.software/ns/schema/2.0", "href": f"https://{host}/nodeinfo/2.0"}]}
    r[f"https://{host}/nodeinfo/2.0"] = {"software": {"name": "Mastodon"}}
    r[f"https://{host}/media/avatar.png"] = b"PNGAVATAR"
    # outbox
    notes = []
    for i in range(n_posts, 0, -1):
        nid = f"https://{host}/users/{user}/statuses/{1000 + i}"
        notes.append({"type": "Create", "to": ["https://www.w3.org/ns/activitystreams#Public"],
                      "object": {"id": nid, "type": "Note", "url": nid, "published": f"2025-0{(i % 9) + 1}-1{i % 10}T10:00:00Z",
                                 "content": f"<p>Post {i} #film</p>", "sensitive": False,
                                 "to": ["https://www.w3.org/ns/activitystreams#Public"],
                                 "tag": [{"type": "Hashtag", "name": "#film"}],
                                 "attachment": [{"type": "Document", "mediaType": "image/jpeg", "url": f"https://{host}/media/{i}.jpg", "name": "alt", "width": 100, "height": 80}],
                                 "likes": {"type": "Collection", "totalItems": i}, "shares": {"type": "Collection", "totalItems": 1},
                                 "replies": {"type": "Collection", "id": nid + "/replies", "totalItems": 0}}})
        r[f"https://{host}/media/{i}.jpg"] = b"JPEGDATA" + bytes([i])
    pages = [notes[k:k + 10] for k in range(0, len(notes), 10)]
    r[actor + "/outbox"] = {"type": "OrderedCollection", "totalItems": n_posts, "first": actor + "/outbox?page=1"}
    for pi, page in enumerate(pages, 1):
        doc = {"type": "OrderedCollectionPage", "orderedItems": page}
        if pi < len(pages):
            doc["next"] = actor + f"/outbox?page={pi + 1}"
        r[actor + f"/outbox?page={pi}"] = doc
    if hidden_graph:
        r[actor + "/followers"] = {"type": "OrderedCollection", "totalItems": 3}
        r[actor + "/following"] = 403
    else:
        r[actor + "/followers"] = {"type": "OrderedCollection", "totalItems": 2, "first": actor + "/followers?page=1"}
        r[actor + "/followers?page=1"] = {"type": "OrderedCollectionPage", "orderedItems": ["https://a.test/users/ann", "https://b.test/@bob"]}
        r[actor + "/following"] = {"type": "OrderedCollection", "totalItems": 1, "orderedItems": ["https://c.test/users/cid"]}
    # REST lookup absent → 404 (dict lookup falls through)
    return r, actor


def pixelfed_like(host="pix.test", user="sean"):
    """Pixelfed shape: outbox is totalItems-only; REST lookup + statuses answer."""
    actor = f"https://{host}/users/{user}"
    r = {}
    r[f"https://{host}/.well-known/webfinger?resource=acct%3A{user}%40{host}"] = {
        "links": [{"rel": "self", "type": "application/activity+json", "href": actor}]}
    r[actor] = {"id": actor, "type": "Person", "preferredUsername": user, "name": "Sean",
                "outbox": actor + "/outbox", "followers": actor + "/followers", "following": actor + "/following"}
    r[actor + "/outbox"] = {"type": "OrderedCollection", "totalItems": 3}
    r[actor + "/followers"] = {"type": "OrderedCollection", "totalItems": 0, "orderedItems": []}
    r[actor + "/following"] = {"type": "OrderedCollection", "totalItems": 0, "orderedItems": []}
    r[f"https://{host}/api/v1/accounts/lookup?acct={user}"] = {"id": "77"}
    r[f"https://{host}/api/v1/accounts/77/statuses?limit=20"] = []          # Pixelfed gates this for guests
    sts = []
    for i in (3, 2, 1):
        sts.append({"id": str(500 + i), "uri": f"https://{host}/p/{user}/{500 + i}", "url": f"https://{host}/p/{user}/{500 + i}",
                    "created_at": f"2024-05-0{i}T09:00:00.000Z", "content": f"<p>pix {i}</p>", "content_text": f"pix {i}",
                    "visibility": "public", "favourites_count": 4, "reblogs_count": 0, "replies_count": 1 if i == 3 else 0,
                    "tags": [{"name": "street"}],
                    "media_attachments": [{"type": "image", "url": f"https://{host}/storage/{i}.jpg", "description": "d", "mime_type": "image/jpeg", "meta": {"original": {"width": 10, "height": 20}}}]})
        r[f"https://{host}/storage/{i}.jpg"] = b"PIX" + bytes([i])
    sts.append({"id": "400", "uri": f"https://{host}/p/{user}/400", "reblog": {"id": "x"}, "created_at": "2024-01-01T00:00:00Z"})
    r[f"https://{host}/api/pixelfed/v1/accounts/77/statuses?limit=20"] = sts
    r[f"https://{host}/api/pixelfed/v1/accounts/77/statuses?limit=20&max_id=400"] = []
    r[f"https://{host}/api/v1/statuses/503/context"] = {"descendants": [
        {"uri": f"https://{host}/p/ann/9", "created_at": "2024-05-03T10:00:00Z", "content": "<p>nice</p>", "account": {"acct": "ann@a.test", "url": "https://a.test/@ann"}}]}
    return r, actor

# ===== SNAPSMACK EOF =====

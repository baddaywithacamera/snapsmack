"""FED UP — put a FED UP archive onto a SnapSmack site (function 2).

Rides UNZUCKER's poster unchanged: this module only turns the archive into
the ``ParsedPost`` list UNZUCKER already knows how to post (original dates,
caption, tags, media), then hands it to ``run_migration``. The site needs a
key of type ``unzucker`` (the shared profile's ``api_key_unzucker``, or its
main key), exactly as UNZUCKER itself does.

What comes across: every archived post that has at least one still image.
Videos and audio are skipped (SnapSmack is a photo blog); text-only posts are
skipped too. The profile is NOT pushed — the site owner already has a name,
bio and avatar and would not thank us for overwriting them.

Followers do not "restore": if the old server is alive, the owner sets the
old actor URL in Fediverse Config → PROFILE → MOVING FROM (the new actor's
``alsoKnownAs``) and triggers Move from the old account; if it is dead,
``followers_to_tell()`` returns the archived follower handles as a list to
copy and paste into a "here is where I went" post.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import json
import os
import re
import sys
import tempfile
from datetime import datetime, timezone
from typing import Callable, List, Optional

_UNZUCKER = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), "unzucker")
IMAGE_EXT = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".avif", ".heic"}
LogFn = Callable[[str], None]


def _unzucker():
    for p in (_UNZUCKER, getattr(sys, "_MEIPASS", "")):
        if p and p not in sys.path and os.path.isdir(p):
            sys.path.insert(0, p)
    import ig_parser  # noqa
    import poster     # noqa
    return ig_parser, poster


def _epoch(published: str) -> int:
    s = (published or "").strip()
    if not s:
        return 0
    try:
        if s.endswith("Z"):
            s = s[:-1] + "+00:00"
        dt = datetime.fromisoformat(s)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return int(dt.timestamp())
    except ValueError:
        m = re.match(r"^(\d{4})-(\d{2})-(\d{2})", s)
        if m:
            return int(datetime(int(m.group(1)), int(m.group(2)), int(m.group(3)), tzinfo=timezone.utc).timestamp())
        return 0


def load_posts(archive_dir: str) -> List[dict]:
    """Every posts/<year>/<id>.json, oldest first."""
    root = os.path.join(archive_dir, "posts")
    out = []
    if not os.path.isdir(root):
        return out
    for base, _d, names in os.walk(root):
        for n in names:
            if not n.endswith(".json"):
                continue
            try:
                with open(os.path.join(base, n), encoding="utf-8") as fh:
                    doc = json.load(fh)
                if isinstance(doc, dict) and doc.get("id"):
                    doc["_file"] = os.path.join(base, n)
                    out.append(doc)
            except (OSError, ValueError):
                continue
    out.sort(key=lambda d: (_epoch(d.get("published", "")), d.get("id", "")))
    return out


def parse(archive_dir: str, include_replies: bool = False, include_unlisted: bool = True):
    """Archive → UNZUCKER ParseResult. Posts without a still image are reported in
    ``stats['skipped_no_image']``; replies to other people are skipped unless asked."""
    ig_parser, _poster = _unzucker()
    result = ig_parser.ParseResult()
    seen_ts = set()
    skipped_no_image = skipped_reply = skipped_private = 0
    for i, doc in enumerate(load_posts(archive_dir)):
        if doc.get("in_reply_to") and not include_replies:
            skipped_reply += 1
            continue
        vis = str(doc.get("visibility") or "public")
        if vis not in ("public", "unlisted") or (vis == "unlisted" and not include_unlisted):
            skipped_private += 1
            continue
        images = []
        for rel in doc.get("media_files") or []:
            if not rel:
                continue
            path = os.path.join(archive_dir, rel)
            if os.path.splitext(path)[1].lower() in IMAGE_EXT and os.path.isfile(path):
                images.append(path)
        if not images:
            skipped_no_image += 1
            continue
        ts = _epoch(doc.get("published", ""))
        # UNZUCKER keys a post by its timestamp; two posts in the same second get
        # nudged apart so neither is mistaken for a duplicate of the other.
        while ts in seen_ts:
            ts += 1
        seen_ts.add(ts)
        tags = [str(t).lstrip("#") for t in (doc.get("tags") or []) if str(t).strip()]
        text = str(doc.get("text") or "").strip()
        cw = str(doc.get("summary") or "").strip()
        if cw:
            text = f"{cw}\n\n{text}" if text else cw
        caption = text + (("\n\n" + " ".join("#" + t for t in tags)) if tags and not _tags_in_text(text, tags) else "")
        result.posts.append(ig_parser.ParsedPost(
            ig_timestamp=ts, caption=caption, body=text, hashtags=tags, images=images,
            post_type="carousel" if len(images) > 1 else "single", original_index=i))
    result.stats = {"posts": len(result.posts), "skipped_no_image": skipped_no_image,
                    "skipped_replies": skipped_reply, "skipped_private": skipped_private}
    return result


def _tags_in_text(text: str, tags: List[str]) -> bool:
    low = text.lower()
    return all(("#" + t.lower()) in low for t in tags)


def site_key_for(profile: dict) -> str:
    """The key UNZUCKER would use for this site: its own type first, main key second."""
    extras = profile.get("extras") or {}
    return str(extras.get("api_key_unzucker") or profile.get("api_key") or "")


def run(archive_dir: str, site_url: str, api_key: str, default_category: str = "", default_album: str = "",
        copyright_text: str = "", include_replies: bool = False, log: Optional[LogFn] = None,
        on_progress=None) -> dict:
    """Post the archive. Returns {posted, skipped, failed, results}."""
    log = log or (lambda s: None)
    _ig, poster = _unzucker()
    parsed = parse(archive_dir, include_replies=include_replies)
    if not parsed.posts:
        return {"posted": 0, "skipped": 0, "failed": 0, "results": [], "stats": parsed.stats}
    client = poster.UnzuckerClient(site_url, api_key)
    ok, msg = client.ping()
    if not ok:
        raise RuntimeError(msg)
    log(msg)
    site = client.fetch_site_data()
    staging = tempfile.mkdtemp(prefix="fed-up-restore-")
    try:
        results = poster.run_migration(client, parsed.posts, site, staging,
                                       default_category=default_category, default_album=default_album,
                                       copyright_text=copyright_text, on_progress=on_progress, post_delay=0.5)
    finally:
        try:
            for n in os.listdir(staging):
                os.remove(os.path.join(staging, n))
            os.rmdir(staging)
        except OSError:
            pass
    posted = sum(1 for r in results if r.success and not r.duplicate and r.post_id)
    skipped = sum(1 for r in results if r.success and (r.duplicate or not r.post_id))
    failed = sum(1 for r in results if not r.success)
    return {"posted": posted, "skipped": skipped, "failed": failed, "results": results, "stats": parsed.stats}


def followers_to_tell(archive_dir: str) -> List[str]:
    """Archived follower handles, for the 'here is where I went' post."""
    path = os.path.join(archive_dir, "graph", "followers.json")
    try:
        with open(path, encoding="utf-8") as fh:
            doc = json.load(fh)
    except (OSError, ValueError):
        return []
    out = []
    for it in (doc.get("items") or []) if isinstance(doc, dict) else []:
        acct = str(it.get("acct") or "")
        out.append("@" + acct if acct else str(it.get("actor") or ""))
    return [x for x in out if x]

# ===== SNAPSMACK EOF =====

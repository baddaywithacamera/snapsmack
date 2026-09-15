"""FED UP — the archive on disk.

Plain files, no database, so the owner can open it with nothing but a file
manager. Layout (spec v0.2):

    <handle>@<domain>/
      manifest.json          version, source, actor, snapshot time, counts, sha256 per file
      profile.json           display name, bio, fields, avatar/header filenames
      posts/<YYYY>/<id>.json one post per file
      media/<id>-<n>.<ext>   originals as served
      graph/followers.json   [{acct, actor}] or {"unavailable": reason}
      graph/following.json
      snapshots/<ISO>.json   per-run summary; incremental = only ids not in the previous run

Incremental: the manifest carries the id → file index of every post archived
so far; the next run hands that id set to the fetcher, which stops when it
reaches posts it already has. Media is fetched once and kept.

This archives the REMOTE account only. It never reads or copies the owner's
own SnapSmack originals — the owner keeps their own archive (see memory:
user owns their archive).
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
from dataclasses import asdict
from datetime import datetime, timezone
from typing import Callable, Dict, List, Optional
from urllib.parse import urlparse

from .fetch import FetchError, Fetcher, Graph, Post, Profile

FORMAT_VERSION = 1
LogFn = Callable[[str], None]
ProgressFn = Callable[[int, int, str], None]   # (done, total, what)

_EXT_BY_TYPE = {
    "image/jpeg": ".jpg", "image/png": ".png", "image/gif": ".gif", "image/webp": ".webp",
    "image/avif": ".avif", "image/heic": ".heic", "video/mp4": ".mp4", "video/webm": ".webm",
    "video/quicktime": ".mov", "audio/mpeg": ".mp3", "audio/ogg": ".ogg",
}


def post_key(post_id: str) -> str:
    """Stable, filesystem-safe name for a post: the numeric/ULID tail when the id
    has one, else a short hash. Two different ids never collide on the tail alone
    because the hash of the full id is appended."""
    tail = re.sub(r"[^A-Za-z0-9]", "", post_id.rstrip("/").rsplit("/", 1)[-1])[:32] or "post"
    return f"{tail}-{hashlib.sha1(post_id.encode('utf-8')).hexdigest()[:8]}"


def _sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _year_of(published: str) -> str:
    m = re.match(r"^(\d{4})", published or "")
    return m.group(1) if m else "undated"


def _ext_for(url: str, media_type: str) -> str:
    if media_type in _EXT_BY_TYPE:
        return _EXT_BY_TYPE[media_type]
    path = urlparse(url).path
    ext = os.path.splitext(path)[1].lower()
    if re.match(r"^\.[a-z0-9]{2,5}$", ext):
        return ext
    return ".bin"


def _write_json(path: str, data) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False, indent=2, sort_keys=True)
    os.replace(tmp, path)


class Archive:
    def __init__(self, root: str, handle: str):
        self.handle = handle.lstrip("@")
        self.dir = os.path.join(root, self.handle)
        self.manifest_path = os.path.join(self.dir, "manifest.json")

    # ── state ──
    def exists(self) -> bool:
        return os.path.isfile(self.manifest_path)

    def manifest(self) -> dict:
        if not self.exists():
            return {}
        try:
            with open(self.manifest_path, encoding="utf-8") as fh:
                m = json.load(fh)
            return m if isinstance(m, dict) else {}
        except (OSError, ValueError):
            return {}

    def known_ids(self) -> set:
        return set((self.manifest().get("posts") or {}).keys())

    def snapshots(self) -> List[dict]:
        out = []
        d = os.path.join(self.dir, "snapshots")
        if not os.path.isdir(d):
            return out
        for name in sorted(os.listdir(d)):
            if name.endswith(".json"):
                try:
                    with open(os.path.join(d, name), encoding="utf-8") as fh:
                        out.append(json.load(fh))
                except (OSError, ValueError):
                    continue
        return out

    # ── the run ──
    def run(self, fetcher: Fetcher, want_media: bool = True, want_graph: bool = True,
            want_replies: bool = True, log: Optional[LogFn] = None,
            progress: Optional[ProgressFn] = None, max_posts: int = 100000, max_graph: int = 5000) -> dict:
        """Full first time, incremental after. Returns the snapshot summary."""
        log = log or (lambda s: None)
        progress = progress or (lambda d, t, w: None)
        started = datetime.now(timezone.utc)
        os.makedirs(self.dir, exist_ok=True)
        previous = self.manifest()
        files: Dict[str, str] = dict(previous.get("files") or {})
        posts_index: Dict[str, str] = dict(previous.get("posts") or {})
        media_index: Dict[str, dict] = dict(previous.get("media") or {})
        known = set(posts_index.keys())
        incremental = bool(known)
        log(f"{'Incremental' if incremental else 'Full'} backup of @{self.handle} → {self.dir}")

        # 1. profile
        log("Reading the profile…")
        prof = fetcher.profile("@" + self.handle)
        log(f"Actor: {prof.actor_url} ({prof.software or 'unknown software'})")
        profile_doc = {
            "actor_url": prof.actor_url, "handle": prof.handle, "domain": prof.domain,
            "preferred_username": prof.preferred_username, "display_name": prof.display_name,
            "summary": prof.summary, "url": prof.url, "fields": prof.fields,
            "also_known_as": prof.also_known_as, "software": prof.software,
            "avatar": "", "header": "", "actor_document": prof.raw,
        }
        if want_media:
            for key, url in (("avatar", prof.icon_url), ("header", prof.image_url)):
                if url:
                    rel = f"media/profile-{key}{_ext_for(url, '')}"
                    if self._fetch_media(fetcher, url, rel, media_index, log):
                        profile_doc[key] = rel
        _write_json(os.path.join(self.dir, "profile.json"), profile_doc)
        files["profile.json"] = _sha256(os.path.join(self.dir, "profile.json"))

        # 2. posts
        log("Reading posts…" + (f" (stopping at the {len(known)} already archived)" if known else ""))
        new_posts: List[Post] = []
        counted = [0]

        def on_post(p: Post):
            counted[0] += 1
            progress(counted[0], 0, f"post {counted[0]}: {p.published[:10]}")

        posts = fetcher.posts(prof, known_ids=known, max_posts=max_posts, on_post=on_post, fetch_replies=want_replies)
        media_failures = 0
        for i, p in enumerate(posts, 1):
            key = post_key(p.id)
            rel = f"posts/{_year_of(p.published)}/{key}.json"
            doc = asdict(p)
            doc["media_files"] = []
            if want_media:
                for n, a in enumerate(p.attachments, 1):
                    mrel = f"media/{key}-{n}{_ext_for(a.url, a.media_type)}"
                    if self._fetch_media(fetcher, a.url, mrel, media_index, log):
                        doc["media_files"].append(mrel)
                    else:
                        media_failures += 1
                        doc["media_files"].append("")
            doc.pop("raw", None)
            doc["raw"] = p.raw
            _write_json(os.path.join(self.dir, rel), doc)
            files[rel] = _sha256(os.path.join(self.dir, rel))
            posts_index[p.id] = rel
            new_posts.append(p)
            progress(i, len(posts), f"saved {rel}")

        # 3. graph
        graph_summary = {}
        if want_graph:
            for name, url in (("followers", prof.followers), ("following", prof.following)):
                log(f"Reading {name}…")
                g: Graph = fetcher.graph(url, max_items=max_graph, prof=prof, which=name)
                rel = f"graph/{name}.json"
                if g.unavailable and not g.items:
                    _write_json(os.path.join(self.dir, rel), {"unavailable": g.unavailable, "total_reported": g.total})
                    graph_summary[name] = {"unavailable": g.unavailable, "total_reported": g.total}
                    log(f"{name}: unavailable — {g.unavailable}")
                else:
                    _write_json(os.path.join(self.dir, rel), {"total_reported": g.total, "capped_at": g.capped or None,
                                                              "partial": g.partial, "items": g.items})
                    graph_summary[name] = {"count": len(g.items), "total_reported": g.total, "capped_at": g.capped or None, "partial": g.partial}
                    log(f"{name}: {len(g.items)} listed" + (f" of {g.total} reported" if g.total is not None else ""))
                files[rel] = _sha256(os.path.join(self.dir, rel))

        # 4. snapshot + manifest
        finished = datetime.now(timezone.utc)
        snap_name = finished.strftime("%Y-%m-%dT%H-%M-%S") + f"-{finished.microsecond // 1000:03d}Z"
        summary = {
            "started": started.isoformat(), "finished": finished.isoformat(), "incremental": incremental,
            "source": {"handle": prof.handle, "actor_url": prof.actor_url, "software": prof.software},
            "new_posts": len(new_posts), "total_posts": len(posts_index),
            "media_files": sum(1 for _ in media_index), "media_failures": media_failures,
            "graph": graph_summary, "fetched_replies": want_replies, "fetched_media": want_media,
            "could_not_fetch": self._limits(prof, want_media, want_graph, graph_summary),
        }
        _write_json(os.path.join(self.dir, "snapshots", snap_name + ".json"), summary)
        files[f"snapshots/{snap_name}.json"] = _sha256(os.path.join(self.dir, "snapshots", snap_name + ".json"))
        manifest = {
            "format": "fed-up-archive", "format_version": FORMAT_VERSION,
            "handle": prof.handle, "actor_url": prof.actor_url, "domain": prof.domain, "software": prof.software,
            "first_snapshot": (previous.get("first_snapshot") or started.isoformat()),
            "last_snapshot": finished.isoformat(), "snapshot_count": int(previous.get("snapshot_count") or 0) + 1,
            "counts": {"posts": len(posts_index), "media": len(media_index),
                       "followers": (graph_summary.get("followers") or {}).get("count"),
                       "following": (graph_summary.get("following") or {}).get("count")},
            "posts": posts_index, "media": media_index, "files": files,
        }
        _write_json(self.manifest_path, manifest)
        log(f"Done: {len(new_posts)} new post(s), {len(posts_index)} in the archive, "
            f"{len(media_index)} media file(s)" + (f", {media_failures} media fetch(es) FAILED" if media_failures else ""))
        return summary

    def _fetch_media(self, fetcher: Fetcher, url: str, rel: str, media_index: dict, log: LogFn) -> bool:
        dest = os.path.join(self.dir, rel)
        rec = media_index.get(rel)
        if rec and os.path.isfile(dest) and os.path.getsize(dest) == rec.get("bytes", -1):
            return True
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        try:
            n = fetcher.download(url, dest + ".part")
        except FetchError as e:
            log(f"media not fetched: {e}")
            try:
                os.remove(dest + ".part")
            except OSError:
                pass
            return False
        os.replace(dest + ".part", dest)
        media_index[rel] = {"url": url, "bytes": n, "sha256": _sha256(dest)}
        return True

    @staticmethod
    def _limits(prof: Profile, want_media: bool, want_graph: bool, graph_summary: dict) -> List[str]:
        """Duty of care: say plainly what a public fetch cannot see."""
        out = ["Followers-only and private posts — this run reads only what the server shows a stranger."]
        for name, g in graph_summary.items():
            if g.get("unavailable"):
                out.append(f"The {name} list — {g['unavailable']}.")
            elif g.get("capped_at"):
                out.append(f"The {name} list — stopped at {g['capped_at']} of {g.get('total_reported')} (very large account).")
            elif g.get("partial"):
                out.append(f"The {name} list — only {g.get('count')} of {g.get('total_reported')}; the server refused to page further for a stranger.")
        if not want_media:
            out.append("Media — you chose not to download it this run.")
        if not want_graph:
            out.append("Followers/following — you chose not to fetch them this run.")
        return out

    # ── verify ──
    def verify(self) -> dict:
        """Re-hash every file the manifest names. Returns {ok, missing, changed}."""
        m = self.manifest()
        missing, changed = [], []
        for rel, digest in (m.get("files") or {}).items():
            path = os.path.join(self.dir, rel)
            if not os.path.isfile(path):
                missing.append(rel)
            elif _sha256(path) != digest:
                changed.append(rel)
        for rel, rec in (m.get("media") or {}).items():
            path = os.path.join(self.dir, rel)
            if not os.path.isfile(path):
                missing.append(rel)
            elif rec.get("sha256") and _sha256(path) != rec["sha256"]:
                changed.append(rel)
        return {"ok": not missing and not changed, "missing": missing, "changed": changed,
                "files": len(m.get("files") or {}) + len(m.get("media") or {})}

    def mirror_to_folder(self, dest_root: str, log: Optional[LogFn] = None) -> int:
        """Copy the archive into another folder (a synced cloud folder, an external
        drive). Only files whose size differs or that are missing are copied. Returns count."""
        log = log or (lambda s: None)
        dest = os.path.join(dest_root, self.handle)
        copied = 0
        for base, _dirs, names in os.walk(self.dir):
            for name in names:
                if name.endswith(".tmp") or name.endswith(".part"):
                    continue
                src = os.path.join(base, name)
                rel = os.path.relpath(src, self.dir)
                dst = os.path.join(dest, rel)
                if os.path.isfile(dst) and os.path.getsize(dst) == os.path.getsize(src):
                    continue
                os.makedirs(os.path.dirname(dst), exist_ok=True)
                shutil.copy2(src, dst)
                copied += 1
        log(f"Mirrored {copied} file(s) to {dest}")
        return copied

# ===== SNAPSMACK EOF =====

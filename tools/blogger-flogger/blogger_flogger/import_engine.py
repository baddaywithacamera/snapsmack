"""Resumable, read-back-verified Blogger-to-SMACKTALK orchestration."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import html
import re
import tempfile
import threading
from datetime import datetime
from pathlib import Path
from urllib.parse import urlparse

from . import media_resolver


def _mysql_date(value: str) -> str:
    if not value:
        return ""
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).strftime("%Y-%m-%d %H:%M:%S")
    except ValueError:
        return ""


def _slug(entry) -> str:
    path = urlparse(entry.canonical_url).path.rstrip("/").rsplit("/", 1)[-1]
    if path.endswith(".html"):
        path = path[:-5]
    return re.sub(r"[^a-z0-9-]+", "-", path.lower()).strip("-")


def _comment_text(content: str) -> str:
    text = re.sub(r"<br\s*/?>", "\n", content, flags=re.I)
    return html.unescape(re.sub(r"<[^>]+>", "", text)).strip()


class ImportEngine:
    def __init__(self, blog, client, store, *, preserve_published=False,
                 fetch_media=True, on_log=None, on_progress=None):
        self.blog = blog; self.client = client; self.store = store
        self.preserve_published = preserve_published; self.fetch_media = fetch_media
        self.on_log = on_log or (lambda _message: None)
        self.on_progress = on_progress or (lambda _done, _total, _message: None)
        self.cancelled = threading.Event()
        self._destinations = {}

    def cancel(self):
        self.cancelled.set()

    def _publication(self, entry):
        return entry.status if self.preserve_published else "draft"

    def _prior(self, entry):
        local = self.store.state(entry.source_id)
        if local and local["state"] == "verified":
            return local["destination_type"], int(local["destination_id"])
        remote = self.client.lookup(self.blog.source_id, entry.kind, entry.source_id)
        if remote.get("found"):
            mapped = remote["mapping"]
            return mapped["destination_type"], int(mapped["destination_id"])
        return None

    def _media_content(self, entry, tempdir):
        content = entry.content_html
        ids = []
        if not self.fetch_media:
            return content, ids
        for index, ref in enumerate(entry.media):
            if self.cancelled.is_set():
                break
            media_source = f"{entry.source_id}:media:{index}"
            remote = self.client.lookup(self.blog.source_id, "media", media_source)
            if remote.get("found"):
                image_id = int(remote["mapping"]["destination_id"])
            else:
                path, checksum, _mime = media_resolver.download(ref.url, tempdir)
                result = self.client.upload_media(path, self.blog.source_id, media_source,
                                                  alt=ref.alt, source_checksum=checksum)
                image_id = int(result.get("image_id") or result.get("asset_id"))
                self.client.verify("media", image_id)
            ids.append(image_id)
            pattern = re.compile(r"<img\b[^>]*\bsrc\s*=\s*(['\"])" + re.escape(ref.url)
                                 + r"\1[^>]*>", re.I)
            content, count = pattern.subn(f"[img:g{image_id}]", content, count=1)
            if not count:
                content += f"\n[img:g{image_id}]"
        return content, ids

    def run(self, selected_ids=None):
        chosen = [e for e in self.blog.entries if selected_ids is None or e.source_id in selected_ids]
        self.store.seed(chosen)
        preflight = self.client.preflight()
        if not preflight.get("compatible"):
            raise RuntimeError("BLOGGER FLOGGER imports into SMACKTALK sites only.")
        if not preflight.get("import_authorized"):
            raise RuntimeError("Open a one-hour import authorization on the destination site.")
        parents = [e for e in chosen if e.kind in {"post", "page"}]
        comments = [e for e in chosen if e.kind == "comment"]
        total = len(parents) + len(comments); done = 0
        with tempfile.TemporaryDirectory(prefix="blogger-flogger-") as tempdir:
            for entry in parents:
                if self.cancelled.is_set():
                    break
                prior = self._prior(entry)
                if prior:
                    dtype, dest_id = prior
                    self._destinations[entry.source_id] = (dtype, dest_id)
                    self.store.mark(entry.source_id, "verified", destination_type=dtype,
                                    destination_id=dest_id, message="Already imported")
                else:
                    self.store.mark(entry.source_id, "running")
                    content, image_ids = self._media_content(entry, tempdir)
                    body = {"source_site_id": self.blog.source_id, "source_type": entry.kind,
                            "source_id": entry.source_id, "source_checksum": entry.source_sha256,
                            "title": entry.title or "Untitled", "content": content,
                            "created_at": _mysql_date(entry.published), "slug": _slug(entry),
                            "status": self._publication(entry), "tags": " ".join(entry.labels),
                            "bucket_image_ids": image_ids,
                            "featured_image_id": image_ids[0] if image_ids else None}
                    result = self.client.create_post(body) if entry.kind == "post" else self.client.create_page(body)
                    dtype = entry.kind; dest_id = int(result[f"{dtype}_id"])
                    self.client.verify(dtype, dest_id)
                    self._destinations[entry.source_id] = (dtype, dest_id)
                    self.store.mark(entry.source_id, "verified", destination_type=dtype,
                                    destination_id=dest_id)
                done += 1; self.on_progress(done, total, entry.title or entry.source_id)
            for entry in comments:
                if self.cancelled.is_set():
                    break
                prior = self._prior(entry)
                parent = self._destinations.get(entry.parent_id)
                if prior:
                    dtype, dest_id = prior
                    self.store.mark(entry.source_id, "verified", destination_type=dtype,
                                    destination_id=dest_id, message="Already imported")
                elif not parent or parent[0] != "post":
                    self.store.mark(entry.source_id, "skipped", message="Parent post was not imported")
                else:
                    body = {"source_site_id": self.blog.source_id, "source_type": "comment",
                            "source_id": entry.source_id, "source_checksum": entry.source_sha256,
                            "post_id": parent[1], "author_name": entry.author_name,
                            "author_email": entry.author_email, "author_url": entry.author_uri,
                            "content": _comment_text(entry.content_html),
                            "created_at": _mysql_date(entry.published), "is_approved": 1,
                            "is_spam": 0}
                    result = self.client.create_comment(body); dest_id = int(result["comment_id"])
                    self.client.verify("comment", dest_id)
                    self.store.mark(entry.source_id, "verified", destination_type="comment",
                                    destination_id=dest_id)
                done += 1; self.on_progress(done, total, entry.author_name or "Comment")
        return {"cancelled": self.cancelled.is_set(), "summary": self.store.summary()}

# ===== SNAPSMACK EOF =====

"""Resumable, idempotent, read-back-verified Ghost-to-SMACKTALK orchestration."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import re
import tempfile
import threading

from . import archive_reader


class ImportEngine:
    def __init__(self, blog, client, store, *, preserve_published=False,
                 fetch_media=True, on_log=None, on_progress=None):
        self.blog = blog
        self.client = client
        self.store = store
        self.preserve_published = preserve_published
        self.fetch_media = fetch_media
        self.on_log = on_log or (lambda _message: None)
        self.on_progress = on_progress or (lambda _done, _total, _message: None)
        self.cancelled = threading.Event()
        self._destinations: dict[str, tuple[str, int]] = {}
        self._uploaded_media: dict[str, int] = {}

    def cancel(self):
        self.cancelled.set()

    def _publication(self, entry) -> str:
        return entry.status if self.preserve_published else "draft"

    def _prior(self, entry, *, check_remote: bool = True) -> tuple[str, int] | None:
        if self.store is not None:
            local = self.store.state(entry.source_id)
            if local and local["state"] == "verified" and local["destination_id"]:
                return local["destination_type"], int(local["destination_id"])
        if not check_remote or self.client is None:
            return None
        remote = self.client.lookup(self.blog.source_id, entry.kind, entry.source_id)
        if remote.get("found"):
            mapped = remote["mapping"]
            return mapped["destination_type"], int(mapped["destination_id"])
        by_slug = self.client.find_by_slug(entry.kind, entry.slug)
        if by_slug is not None:
            return entry.kind, int(by_slug)
        return None

    def dry_run(self, selected_ids=None, *, check_remote: bool = False) -> dict:
        chosen = [e for e in self.blog.entries if selected_ids is None or e.source_id in selected_ids]
        posts = sum(1 for e in chosen if e.kind == "post")
        pages = sum(1 for e in chosen if e.kind == "page")
        unique_images = {m.url for e in chosen for m in e.media if m.url}
        unique_tags = {t for e in chosen for t in e.tags if t}
        leftover_details = [
            f"{e.kind.upper()} '{e.slug}': {lc.kind} — {lc.detail}"
            for e in chosen for lc in e.leftover_cards
        ]
        already_imported = 0
        for e in chosen:
            if self._prior(e, check_remote=check_remote) is not None:
                already_imported += 1
        return {
            "posts": posts,
            "pages": pages,
            "images": len(unique_images),
            "tags": len(unique_tags),
            "total": len(chosen),
            "already_imported": already_imported,
            "to_import": len(chosen) - already_imported,
            "leftover_cards": len(leftover_details),
            "leftover_details": leftover_details,
        }

    def _media_content(self, entry, tempdir) -> tuple[str, list[int], int | None]:
        content = entry.content_html
        ids: list[int] = []
        featured_id: int | None = None
        if not self.fetch_media:
            return content, ids, featured_id

        for index, ref in enumerate(entry.media):
            if self.cancelled.is_set():
                break
            if ref.url in self._uploaded_media:
                image_id = self._uploaded_media[ref.url]
            else:
                media_source = f"{entry.source_id}:media:{index}"
                remote = self.client.lookup(self.blog.source_id, "media", media_source)
                if remote.get("found"):
                    image_id = int(remote["mapping"]["destination_id"])
                else:
                    path, checksum, _mime = archive_reader.resolve_media(
                        ref.url,
                        media_root=self.blog.media_root,
                        zip_path=self.blog.zip_path,
                        tempdir=tempdir,
                    )
                    result = self.client.upload_media(
                        path, self.blog.source_id, media_source,
                        alt=ref.alt, source_checksum=checksum,
                    )
                    image_id = int(result.get("image_id") or result.get("asset_id"))
                    self.client.verify("media", image_id)
                self._uploaded_media[ref.url] = image_id

            if image_id not in ids:
                ids.append(image_id)
            if ref.context == "feature" and featured_id is None:
                featured_id = image_id

            # Replace <figure class="kg-card kg-image-card">...<img src="URL">...</figure> or bare <img>
            fig_pat = re.compile(
                r"<figure\b[^>]*>\s*<img\b[^>]*\bsrc\s*=\s*(['\"])"
                + re.escape(ref.url) + r"\1[^>]*>.*?</figure>",
                re.I | re.S,
            )
            content, count = fig_pat.subn(f"[img:g{image_id}]", content, count=1)
            if not count:
                img_pat = re.compile(
                    r"<img\b[^>]*\bsrc\s*=\s*(['\"])" + re.escape(ref.url) + r"\1[^>]*>",
                    re.I,
                )
                content, count = img_pat.subn(f"[img:g{image_id}]", content, count=1)
            if not count and ref.context != "feature":
                content += f"\n[img:g{image_id}]"

        if featured_id is None and ids:
            featured_id = ids[0]
        return content, ids, featured_id

    def run(self, selected_ids=None) -> dict:
        chosen = [e for e in self.blog.entries if selected_ids is None or e.source_id in selected_ids]
        dry = self.dry_run(selected_ids, check_remote=False)
        self.on_log(
            f"Dry run: {dry['posts']} posts, {dry['pages']} pages, "
            f"{dry['images']} images, {dry['tags']} tags, "
            f"{dry['leftover_cards']} leftover Ghost cards."
        )
        for detail in dry["leftover_details"]:
            self.on_log(f"WARN: {detail}")

        self.store.seed(chosen)
        preflight = self.client.preflight()
        if not preflight.get("compatible"):
            raise RuntimeError("GHOSTED imports into SMACKTALK sites only.")
        if not preflight.get("import_authorized"):
            raise RuntimeError("Open a one-hour import authorization on the destination site.")

        total = len(chosen)
        done = 0
        created = 0
        already_imported = 0

        with tempfile.TemporaryDirectory(prefix="ghosted-") as tempdir:
            for entry in chosen:
                if self.cancelled.is_set():
                    break
                prior = self._prior(entry, check_remote=True)
                if prior:
                    dtype, dest_id = prior
                    self._destinations[entry.source_id] = (dtype, dest_id)
                    self.store.mark(
                        entry.source_id, "verified",
                        destination_type=dtype, destination_id=dest_id,
                        message="Already imported",
                    )
                    already_imported += 1
                    self.on_log(f"SKIP (idempotent): {entry.kind} '{entry.slug}' -> {dtype} #{dest_id}")
                else:
                    self.store.mark(entry.source_id, "running")
                    content, image_ids, featured_id = self._media_content(entry, tempdir)
                    body = {
                        "source_site_id": self.blog.source_id,
                        "source_type": entry.kind,
                        "source_id": entry.source_id,
                        "source_checksum": entry.source_sha256,
                        "title": entry.title or "Untitled",
                        "content": content,
                        "excerpt": entry.excerpt,
                        "created_at": entry.published or entry.created,
                        "slug": entry.slug,
                        "status": self._publication(entry),
                        "tags": " ".join(t.replace(" ", "-") for t in entry.tags if t),
                        "bucket_image_ids": image_ids,
                        "featured_image_id": featured_id,
                    }
                    result = (
                        self.client.create_post(body)
                        if entry.kind == "post"
                        else self.client.create_page(body)
                    )
                    dtype = entry.kind
                    dest_id = int(result[f"{dtype}_id"])
                    self.client.verify(dtype, dest_id)
                    self._destinations[entry.source_id] = (dtype, dest_id)
                    self.store.mark(
                        entry.source_id, "verified",
                        destination_type=dtype, destination_id=dest_id,
                    )
                    created += 1
                done += 1
                self.on_progress(done, total, entry.title or entry.slug)

        summary = dict(self.store.summary())
        summary["created"] = created
        summary["already_imported"] = already_imported
        summary["leftover_cards"] = dry["leftover_cards"]
        return {"summary": summary, "dry_run": dry, "destinations": dict(self._destinations)}

# ===== SNAPSMACK EOF =====

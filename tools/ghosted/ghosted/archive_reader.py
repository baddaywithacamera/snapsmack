"""Discover and read Ghost JSON exports, ZIPs, and content/images folders."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import hashlib
import json
import mimetypes
import posixpath
import zipfile
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import unquote, urlparse

import requests

MAX_JSON_BYTES = 150 * 1024 * 1024
MAX_MEDIA_BYTES = 100 * 1024 * 1024


@dataclass(frozen=True)
class GhostCandidate:
    kind: str  # "json", "zip"
    path: Path
    inner_json: str = ""
    media_root: str = ""
    label: str = ""


def _looks_like_ghost_json(raw: bytes) -> bool:
    if len(raw) > MAX_JSON_BYTES:
        return False
    try:
        doc = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return False
    if isinstance(doc, dict) and isinstance(doc.get("db"), list) and doc["db"]:
        data = (doc["db"][0] or {}).get("data")
        return isinstance(data, dict) and "posts" in data
    return isinstance(doc, dict) and isinstance(doc.get("data"), dict) and "posts" in doc["data"]


def _find_media_root(json_path: Path) -> str:
    for parent in (json_path.parent, json_path.parent.parent):
        if (parent / "content" / "images").is_dir() or (parent / "content").is_dir():
            return str(parent)
    return str(json_path.parent)


def discover_archives(path: str | Path) -> list[GhostCandidate]:
    target = Path(path)
    candidates: list[GhostCandidate] = []
    if target.is_file():
        if target.suffix.lower() == ".json":
            if _looks_like_ghost_json(target.read_bytes()):
                candidates.append(GhostCandidate(
                    kind="json", path=target, media_root=_find_media_root(target),
                    label=f"Ghost JSON — {target.name}",
                ))
        elif target.suffix.lower() == ".zip":
            with zipfile.ZipFile(target) as zf:
                for info in sorted(zf.infolist(), key=lambda i: i.filename):
                    if info.is_dir() or not info.filename.lower().endswith(".json"):
                        continue
                    if info.file_size > MAX_JSON_BYTES:
                        continue
                    with zf.open(info) as stream:
                        if _looks_like_ghost_json(stream.read(MAX_JSON_BYTES)):
                            candidates.append(GhostCandidate(
                                kind="zip", path=target, inner_json=info.filename,
                                label=f"Ghost ZIP — {info.filename}",
                            ))
        return candidates

    if target.is_dir():
        for file_path in sorted(target.rglob("*.json")):
            if file_path.stat().st_size > MAX_JSON_BYTES:
                continue
            if _looks_like_ghost_json(file_path.read_bytes()):
                rel = file_path.relative_to(target).as_posix()
                candidates.append(GhostCandidate(
                    kind="json", path=file_path, media_root=str(target),
                    label=f"Ghost Folder — {rel}",
                ))
        for zip_path in sorted(target.glob("*.zip")):
            candidates.extend(discover_archives(zip_path))
    return candidates


def read_candidate(candidate: GhostCandidate) -> tuple[bytes, str, str]:
    """Return (json_bytes, media_root, zip_path)."""
    if candidate.kind == "zip":
        with zipfile.ZipFile(candidate.path) as zf:
            info = zf.getinfo(candidate.inner_json)
            if info.file_size > MAX_JSON_BYTES:
                raise ValueError("Ghost JSON export exceeds size limit.")
            return zf.read(info), candidate.media_root, str(candidate.path)
    return candidate.path.read_bytes(), candidate.media_root, ""


def _relative_ghost_path(url: str) -> str:
    clean = (url or "").strip()
    if clean.startswith("__GHOST_URL__/"):
        clean = clean[len("__GHOST_URL__/"):]
    else:
        parsed = urlparse(clean)
        clean = parsed.path if parsed.scheme else clean
    clean = unquote(clean).lstrip("/")
    return posixpath.normpath(clean).lstrip("/")


def _sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        while True:
            chunk = f.read(65536)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def resolve_media(url: str, *, media_root: str = "", zip_path: str = "",
                  tempdir: str | Path, session=None) -> tuple[Path, str, str]:
    """Resolve a Ghost media reference from ZIP, content folder, or HTTPS URL."""
    rel = _relative_ghost_path(url)
    base_name = posixpath.basename(rel) or "image.bin"
    dest_dir = Path(tempdir)
    dest_dir.mkdir(parents=True, exist_ok=True)

    # 1. ZIP archive lookup
    if zip_path and Path(zip_path).is_file():
        with zipfile.ZipFile(zip_path) as zf:
            names = [n for n in zf.namelist() if not n.endswith("/")]
            match = next((n for n in names if n.lstrip("/") == rel), None)
            if match is None and base_name:
                match = next((n for n in names if posixpath.basename(n) == base_name
                              and "content/images" in n.lower()), None)
            if match is not None:
                data = zf.read(match)
                if len(data) > MAX_MEDIA_BYTES:
                    raise ValueError(f"Media file too large: {match}")
                digest = hashlib.sha256(data).hexdigest()
                out = dest_dir / f"{digest[:16]}_{base_name}"
                out.write_bytes(data)
                mime = mimetypes.guess_type(base_name)[0] or "application/octet-stream"
                return out, digest, mime

    # 2. Extracted content folder lookup
    if media_root:
        root = Path(media_root)
        candidates = [root / rel, root / "content" / "images" / base_name]
        if rel.startswith("content/images/"):
            candidates.append(root / rel[len("content/images/"):])
        for cand in candidates:
            if cand.is_file():
                digest = _sha256_file(cand)
                mime = mimetypes.guess_type(cand.name)[0] or "application/octet-stream"
                return cand, digest, mime

    # 3. Remote HTTP/HTTPS fallback for external URLs
    parsed = urlparse(url)
    if parsed.scheme in {"http", "https"} and parsed.hostname:
        sess = session or requests.Session()
        resp = sess.get(url, timeout=45, stream=True)
        resp.raise_for_status()
        chunks = []
        total = 0
        for chunk in resp.iter_content(65536):
            total += len(chunk)
            if total > MAX_MEDIA_BYTES:
                raise ValueError(f"Remote media too large: {url}")
            chunks.append(chunk)
        data = b"".join(chunks)
        digest = hashlib.sha256(data).hexdigest()
        out = dest_dir / f"{digest[:16]}_{base_name}"
        out.write_bytes(data)
        mime = resp.headers.get("Content-Type", "").split(";")[0].strip() or (
            mimetypes.guess_type(base_name)[0] or "application/octet-stream")
        return out, digest, mime

    raise FileNotFoundError(f"Could not locate Ghost media file: {url}")

# ===== SNAPSMACK EOF =====

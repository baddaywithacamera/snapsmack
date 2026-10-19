from __future__ import annotations

import base64
import hashlib
import mimetypes
import shutil
from pathlib import Path
from typing import Any


ALLOWED_TYPES = {"image/png", "image/jpeg", "image/gif", "image/webp"}
MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024


def attachment_root(root: Path) -> Path:
    path = root / "attachments"
    path.mkdir(parents=True, exist_ok=True)
    return path


def import_image(source: str | Path, root: Path) -> dict[str, Any]:
    source_path = Path(source).resolve()
    if not source_path.is_file():
        raise ValueError("The selected screenshot does not exist.")
    size = source_path.stat().st_size
    if size <= 0 or size > MAX_ATTACHMENT_BYTES:
        raise ValueError("Screenshots must be between 1 byte and 10 MiB.")
    mime = mimetypes.guess_type(source_path.name)[0] or ""
    if mime not in ALLOWED_TYPES:
        raise ValueError("Only PNG, JPEG, GIF, and WebP screenshots are supported.")
    digest = hashlib.sha256(source_path.read_bytes()).hexdigest()
    extension = {"image/png": ".png", "image/jpeg": ".jpg", "image/gif": ".gif", "image/webp": ".webp"}[mime]
    destination = attachment_root(root) / f"{digest}{extension}"
    if not destination.exists():
        shutil.copy2(source_path, destination)
    return {
        "id": digest,
        "name": source_path.name,
        "mime": mime,
        "size": size,
        "path": str(destination),
    }


def read_image(attachment: dict[str, Any], root: Path) -> dict[str, Any]:
    digest = str(attachment.get("id", ""))
    path = Path(str(attachment.get("path", ""))).resolve()
    managed = attachment_root(root).resolve()
    if not digest or path.parent != managed or not path.is_file():
        raise ValueError("Attachment is missing or is outside the managed screenshot folder.")
    data = path.read_bytes()
    if len(data) > MAX_ATTACHMENT_BYTES or hashlib.sha256(data).hexdigest() != digest:
        raise ValueError("Attachment failed its integrity check.")
    return {
        "id": digest,
        "name": str(attachment.get("name") or path.name),
        "mime": str(attachment.get("mime") or "application/octet-stream"),
        "size": len(data),
        "base64": base64.b64encode(data).decode("ascii"),
    }

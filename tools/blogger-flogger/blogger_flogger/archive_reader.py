"""Bounded discovery and reading of Blogger Takeout feeds."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import os
import zipfile
from dataclasses import dataclass
from pathlib import Path, PurePosixPath


MAX_XML_BYTES = 256 * 1024 * 1024
MAX_ZIP_ENTRIES = 1_000_000
MAX_EXPANDED_BYTES = 500 * 1024**3
MAX_RATIO = 1000


class ArchiveRefused(ValueError):
    """The selected source violates a safe, explicit archive boundary."""


@dataclass(frozen=True)
class ArchiveCandidate:
    container: str
    member: str
    label: str


def _is_feed_name(name: str) -> bool:
    base = PurePosixPath(name.replace("\\", "/")).name.lower()
    return base == "feed.atom" or base.endswith(".xml")


def _safe_member(name: str) -> bool:
    norm = name.replace("\\", "/")
    path = PurePosixPath(norm)
    return bool(norm and not path.is_absolute() and ".." not in path.parts
                and not (len(norm) > 1 and norm[1] == ":"))


def _validate_zip(zf: zipfile.ZipFile) -> list[zipfile.ZipInfo]:
    infos = zf.infolist()
    if len(infos) > MAX_ZIP_ENTRIES:
        raise ArchiveRefused(f"Archive has {len(infos):,} entries; limit is {MAX_ZIP_ENTRIES:,}.")
    expanded = 0
    for info in infos:
        if not _safe_member(info.filename):
            raise ArchiveRefused(f"Unsafe archive path: {info.filename!r}")
        expanded += info.file_size
        if expanded > MAX_EXPANDED_BYTES:
            raise ArchiveRefused("Archive expands beyond the 500 GB safety limit.")
        if info.compress_size and info.file_size / info.compress_size > MAX_RATIO:
            raise ArchiveRefused(f"Archive entry expands suspiciously: {info.filename!r}")
    return infos


def discover_archives(path: str | os.PathLike[str]) -> list[ArchiveCandidate]:
    source = Path(path).resolve()
    if source.is_file() and zipfile.is_zipfile(source):
        with zipfile.ZipFile(source) as zf:
            infos = _validate_zip(zf)
            return [ArchiveCandidate(str(source), i.filename, i.filename)
                    for i in infos if not i.is_dir() and _is_feed_name(i.filename)]
    if source.is_file():
        if not _is_feed_name(source.name):
            raise ArchiveRefused("Choose a Takeout ZIP, feed.atom, Blogger XML, or extracted folder.")
        if source.stat().st_size > MAX_XML_BYTES:
            raise ArchiveRefused("Feed exceeds the 256 MB XML safety limit.")
        return [ArchiveCandidate(str(source), "", source.name)]
    if source.is_dir():
        candidates = []
        for candidate in source.rglob("*"):
            if candidate.is_file() and _is_feed_name(candidate.name):
                resolved = candidate.resolve()
                try:
                    rel = resolved.relative_to(source)
                except ValueError as exc:
                    raise ArchiveRefused("A discovered feed escapes the selected folder.") from exc
                if resolved.stat().st_size <= MAX_XML_BYTES:
                    candidates.append(ArchiveCandidate(str(resolved), "", rel.as_posix()))
        return sorted(candidates, key=lambda item: item.label.lower())
    raise ArchiveRefused("The selected archive does not exist.")


def read_candidate(candidate: ArchiveCandidate) -> bytes:
    if candidate.member:
        with zipfile.ZipFile(candidate.container) as zf:
            infos = _validate_zip(zf)
            info = next((i for i in infos if i.filename == candidate.member), None)
            if info is None:
                raise ArchiveRefused("The selected feed is no longer in the archive.")
            if info.file_size > MAX_XML_BYTES:
                raise ArchiveRefused("Feed exceeds the 256 MB XML safety limit.")
            with zf.open(info) as stream:
                data = stream.read(MAX_XML_BYTES + 1)
    else:
        with open(candidate.container, "rb") as stream:
            data = stream.read(MAX_XML_BYTES + 1)
    if len(data) > MAX_XML_BYTES:
        raise ArchiveRefused("Feed exceeds the 256 MB XML safety limit.")
    return data

# ===== SNAPSMACK EOF =====

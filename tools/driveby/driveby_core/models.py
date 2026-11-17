"""Data models and inventory aggregation for DRIVE BY."""

from dataclasses import dataclass, field
import re
from typing import Dict, List, Optional


_SUYB_NAME_RX = re.compile(
    r"^(?P<blog>.+?)[_-](?P<btype>full|db|database|media|files|inc|incremental)"
    r"[_-](?P<date>\d{4}-\d{2}-\d{2})(?:[_-](?P<time>\d{4,6}))?",
    re.IGNORECASE,
)


def parse_suyb_filename(filename: str) -> dict:
    """Extract (blog, backup_type, date_iso) from a SUYB archive filename."""
    stem = str(filename or "").strip()
    m = _SUYB_NAME_RX.match(stem)
    if m:
        btype = m.group("btype").lower()
        if btype == "database":
            btype = "db"
        elif btype == "incremental":
            btype = "inc"
        return {
            "blog": m.group("blog").replace("_", " "),
            "backup_type": btype,
            "date_iso": m.group("date"),
        }
    return {
        "blog": stem.split("_")[0].split("-")[0] or "unknown",
        "backup_type": "unknown",
        "date_iso": "",
    }


@dataclass
class BackupRecord:
    """One backup artifact stored on Google Drive or Backblaze B2."""

    provider: str           # "google_drive" | "b2"
    blog: str
    filename: str
    backup_type: str        # "full" | "db" | "media" | "inc" | "unknown"
    date_iso: str           # "YYYY-MM-DD" or ISO timestamp
    size_bytes: int
    verified: bool = False
    replayed: bool = False  # SS-1363 INSTANT REPLAY status
    remote_id: str = ""
    trashed: bool = False
    raw_meta: dict = field(default_factory=dict)


@dataclass
class InventorySummary:
    total_bytes: int
    total_count: int
    by_provider: Dict[str, int]
    by_blog: Dict[str, int]
    counts_by_provider: Dict[str, int]
    counts_by_blog: Dict[str, int]


def summarize_inventory(records: List[BackupRecord]) -> InventorySummary:
    """Compute per-blog and per-provider storage totals for active (non-trashed) backups."""
    by_provider: Dict[str, int] = {}
    by_blog: Dict[str, int] = {}
    counts_by_provider: Dict[str, int] = {}
    counts_by_blog: Dict[str, int] = {}
    total_bytes = 0
    total_count = 0

    for rec in records:
        if rec.trashed:
            continue
        sz = max(0, int(rec.size_bytes or 0))
        total_bytes += sz
        total_count += 1
        by_provider[rec.provider] = by_provider.get(rec.provider, 0) + sz
        by_blog[rec.blog] = by_blog.get(rec.blog, 0) + sz
        counts_by_provider[rec.provider] = counts_by_provider.get(rec.provider, 0) + 1
        counts_by_blog[rec.blog] = counts_by_blog.get(rec.blog, 0) + 1

    return InventorySummary(
        total_bytes=total_bytes,
        total_count=total_count,
        by_provider=by_provider,
        by_blog=by_blog,
        counts_by_provider=counts_by_provider,
        counts_by_blog=counts_by_blog,
    )


def format_bytes(num_bytes: int) -> str:
    val = float(max(0, int(num_bytes or 0)))
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if val < 1024.0 or unit == "TB":
            return f"{val:.2f} {unit}" if unit != "B" else f"{int(val)} B"
        val /= 1024.0
    return f"{val:.2f} TB"


# ===== SNAPSMACK EOF =====

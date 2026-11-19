"""Data models for INSTANT REPLAY (SS-1363)."""

from dataclasses import dataclass, field


@dataclass
class BackupPackage:
    backup_id: str
    blog_name: str
    date_iso: str
    size_bytes: int
    posts_count: int
    photos_count: int
    pages_count: int
    settings_count: int
    skin_name: str
    sample_image_urls: list[str] = field(default_factory=list)


@dataclass
class TargetTestBlog:
    blog_name: str
    url: str
    is_test_blog: bool
    current_posts: int
    current_images: int


@dataclass
class ReplayChallenge:
    request_id: str
    target_host: str
    backup_id: str
    phrase: str
    expires_at: float


@dataclass
class VerificationCheck:
    name: str
    passed: bool
    expected: str
    actual: str


@dataclass
class VerificationResult:
    verdict: str  # "PASS" or "FAIL"
    checks: list[VerificationCheck] = field(default_factory=list)
    summary: str = ""


def format_bytes(n: int) -> str:
    val = float(max(0, n))
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if val < 1024.0 or unit == "TB":
            return f"{val:.1f} {unit}" if unit != "B" else f"{int(val)} B"
        val /= 1024.0
    return f"{n} B"


# ===== SNAPSMACK EOF =====

"""Offline fixture providers for INSTANT REPLAY (zero real host mutations)."""

import time
import unicodedata

from .models import BackupPackage, ReplayChallenge, TargetTestBlog


class FakeBackupCatalog:
    def list_backups(self) -> list[BackupPackage]:
        return [
            BackupPackage(
                backup_id="bkp-2026-11-16-foundtextures",
                blog_name="Found Textures",
                date_iso="2026-11-16",
                size_bytes=428 * 1024 * 1024,
                posts_count=142,
                photos_count=318,
                pages_count=6,
                settings_count=48,
                skin_name="chaplin",
                sample_image_urls=["/media/img_001.jpg", "/media/img_042.jpg", "/media/img_142.jpg"],
            ),
            BackupPackage(
                backup_id="bkp-2026-11-10-photowalk",
                blog_name="Photowalking",
                date_iso="2026-11-10",
                size_bytes=192 * 1024 * 1024,
                posts_count=64,
                photos_count=128,
                pages_count=4,
                settings_count=45,
                skin_name="glide",
                sample_image_urls=["/media/pw_01.jpg", "/media/pw_64.jpg"],
            ),
        ]


class FakeTestBlogTarget:
    """Offline test-blog target fixture simulating server-side TEST BLOG & challenge gates."""

    def __init__(self, *, corrupt_after_restore: bool = False):
        self.corrupt_after_restore = corrupt_after_restore
        self.targets = [
            TargetTestBlog(
                blog_name="Found Textures TEST",
                url="https://test.foundtextures.example",
                is_test_blog=True,
                current_posts=12,
                current_images=24,
            ),
            TargetTestBlog(
                blog_name="Found Textures LIVE",
                url="https://foundtextures.example",
                is_test_blog=False,
                current_posts=142,
                current_images=318,
            ),
        ]
        self._active_challenge: ReplayChallenge | None = None
        self._snapshot: dict = {}

    def list_targets(self) -> list[TargetTestBlog]:
        return list(self.targets)

    def request_challenge(self, target: TargetTestBlog, backup: BackupPackage) -> ReplayChallenge:
        if not target.is_test_blog:
            raise PermissionError("Refused: target is NOT a designated TEST BLOG.")
        phrase = f"0v3rWr1T3 {target.blog_name.swapcase()}"
        self._active_challenge = ReplayChallenge(
            request_id=f"req-{int(time.time() * 1000)}",
            target_host=target.url,
            backup_id=backup.backup_id,
            phrase=phrase,
            expires_at=time.time() + 120.0,
        )
        return self._active_challenge

    def execute_replay(
        self,
        target: TargetTestBlog,
        backup: BackupPackage,
        challenge_id: str,
        typed_phrase: str,
        totp_code: str,
    ) -> dict:
        if not target.is_test_blog:
            raise PermissionError("Refused: target is NOT a designated TEST BLOG.")
        ch = self._active_challenge
        self._active_challenge = None  # single-use
        if ch is None or ch.request_id != challenge_id:
            raise ValueError("Challenge missing or already used.")
        if time.time() > ch.expires_at:
            raise TimeoutError("Challenge expired (120s limit).")
        if unicodedata.normalize("NFC", typed_phrase.strip()) != unicodedata.normalize("NFC", ch.phrase):
            raise ValueError("Challenge phrase did not match.")
        if not totp_code.strip().isdigit() or len(totp_code.strip()) != 6:
            raise ValueError("Valid 6-digit TOTP code required.")

        self._snapshot = {
            "posts_count": backup.posts_count - (1 if self.corrupt_after_restore else 0),
            "photos_count": backup.photos_count,
            "pages_count": backup.pages_count,
            "settings_count": backup.settings_count,
            "skin_name": backup.skin_name,
            "loaded_sample_images": list(backup.sample_image_urls),
        }
        return dict(self._snapshot)


# ===== SNAPSMACK EOF =====

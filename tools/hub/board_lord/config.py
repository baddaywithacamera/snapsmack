"""Persisted operator settings (forum base URL + operator key).

Stored with QSettings (per-user, OS-native) so the operator enters the key once.
The key is the God key (FORUM_MOD_KEY) or an admin blog's api_key.
"""

from PySide6.QtCore import QSettings

DEFAULT_BASE = "https://snapsmack.ca/api/forum"


class Config:
    def __init__(self):
        self._s = QSettings("SnapSmack", "BOARD LORD")

    @property
    def base_url(self) -> str:
        return str(self._s.value("base_url", DEFAULT_BASE) or DEFAULT_BASE)

    @base_url.setter
    def base_url(self, value: str):
        self._s.setValue("base_url", (value or DEFAULT_BASE).strip())

    @property
    def key(self) -> str:
        return str(self._s.value("key", "") or "")

    @key.setter
    def key(self, value: str):
        self._s.setValue("key", (value or "").strip())

    @property
    def is_configured(self) -> bool:
        return bool(self.base_url and self.key)

# ===== SNAPSMACK EOF =====

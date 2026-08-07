"""Shared manifest-path validation for backup and restore operations."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import re


def is_safe_relative(path: str) -> bool:
    if not isinstance(path, str) or not path or "\x00" in path:
        return False
    normalized = path.replace("\\", "/")
    if normalized.startswith("/") or re.match(r"^[A-Za-z]:", normalized):
        return False
    parts = [part for part in normalized.split("/") if part not in ("", ".")]
    return bool(parts) and all(part != ".." for part in parts)


def contained_local_path(root: str, relative: str) -> str:
    if not is_safe_relative(relative):
        raise ValueError(f"Unsafe relative path: {relative!r}")
    root_abs = os.path.abspath(root)
    candidate = os.path.abspath(os.path.join(root_abs, *relative.replace("\\", "/").split("/")))
    if os.path.commonpath([root_abs, candidate]) != root_abs:
        raise ValueError(f"Path escapes staging directory: {relative!r}")
    return candidate

# ===== SNAPSMACK EOF =====

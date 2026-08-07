"""
Shared path containment for the SnapSmack desktop tools.

Lifted VERBATIM from tools/smack-up-your-backup/path_safety.py, which has been
in service and under test since the recovery-kit work. This is the shared home
for it; SUYB itself is deliberately NOT migrated onto this copy yet — it is
shipped and frozen, its build does not pass `--paths ..\\_shared`, and changing
the import path of a working tool's containment check to tidy a directory would
be a poor trade. Moving it deserves its own change, exactly as the credential
vault did.

The two implementations must stay identical. If you change one, change both.

WHAT THIS IS FOR. Any time a tool joins a path that came from OUTSIDE its own
code — a manifest, a sidecar, a resume ledger, a server response — the join has
to be contained. Files on disk are data, and data is untrusted even when we
wrote it ourselves: a resume ledger is a file a person can open in Notepad, and
`os.path.join(root, '../../Windows/System32/x')` does exactly what it says.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import re


def is_safe_relative(path) -> bool:
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

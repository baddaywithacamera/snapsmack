"""
Smack Up Your Backup — bump_version.py
Auto-increment BUILD_VERSION in main.py by one patch and print the new value.
Single source of truth for the per-build version bump; called by build.bat and
build.sh so the increment logic lives in exactly one place.

Edits main.py in BINARY mode and rewrites only the version literal, so existing
line endings (CRLF/LF) are left untouched — never trip the repo's EOL guard.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import re
import sys

MAIN = os.path.join(os.path.dirname(os.path.abspath(__file__)), "main.py")


def bump(version: str) -> str:
    """Increment the trailing integer of a version string.
    0.7.4 -> 0.7.5 ; 0.7.9 -> 0.7.10. Any legacy alpha suffix (0.7.9a) is
    dropped so the result is always clean, monotonic, numeric semver."""
    m = re.search(r"(\d+)(\D*)$", version)
    if not m:
        raise ValueError(f"cannot find a patch number in version {version!r}")
    return version[: m.start(1)] + str(int(m.group(1)) + 1)


def main() -> int:
    try:
        data = open(MAIN, "rb").read()
    except OSError as e:
        sys.stderr.write(f"bump_version: cannot read main.py: {e}\n")
        return 1

    m = re.search(rb'BUILD_VERSION\s*=\s*"([^"]+)"', data)
    if not m:
        sys.stderr.write("bump_version: BUILD_VERSION not found in main.py\n")
        return 1

    old = m.group(1).decode()
    try:
        new = bump(old)
    except ValueError as e:
        sys.stderr.write(f"bump_version: {e}\n")
        return 1

    data = data[: m.start(1)] + new.encode() + data[m.end(1):]
    try:
        open(MAIN, "wb").write(data)
    except OSError as e:
        sys.stderr.write(f"bump_version: cannot write main.py: {e}\n")
        return 1

    # stdout carries ONLY the new version — build scripts capture it directly.
    print(new)
    return 0


if __name__ == "__main__":
    sys.exit(main())
# ===== SNAPSMACK EOF =====

#!/usr/bin/env python3
# SNAPSMACK_EOF_HEADER — last non-empty line of this file MUST be the .py EOF
# marker: a hash comment with five '=', space, SNAPSMACK EOF, space, five '='.
"""
Bump the patch component of BUILD_VERSION in main.py (0.7.X -> 0.7.X+1).

Run automatically by build.bat before each PyInstaller build, so every exe is
stamped with a fresh, auto-incrementing 0.7.xx version — matching the 0.7.x
scheme used by the SnapSmack CMS and the unzucker tool.
"""
import re
import sys
from pathlib import Path

MAIN = Path(__file__).with_name("main.py")


def main() -> int:
    src = MAIN.read_text(encoding="utf-8")
    m = re.search(r'BUILD_VERSION\s*=\s*"0\.7\.(\d+)"', src)
    if not m:
        print('bump_version: BUILD_VERSION = "0.7.N" not found in main.py', file=sys.stderr)
        return 1
    new_patch = int(m.group(1)) + 1
    src = re.sub(
        r'(BUILD_VERSION\s*=\s*")0\.7\.\d+(")',
        lambda mm: f'{mm.group(1)}0.7.{new_patch}{mm.group(2)}',
        src, count=1,
    )
    MAIN.write_text(src, encoding="utf-8")
    print(f"bump_version: BUILD_VERSION -> 0.7.{new_patch}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
# ===== SNAPSMACK EOF =====

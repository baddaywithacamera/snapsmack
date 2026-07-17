# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
"""
fix_mojibake.py — run once from Windows Python to fix 3 garbage-char sequences in main.py.
Usage: python fix_mojibake.py
"""

import os

TARGET = os.path.join(os.path.dirname(__file__), "main.py")

REPLACEMENTS = [
    (b"\xc3\xa2\xe2\x80\xa0\xc2\x90", b"\xe2\x86\x90"),  # â†  -> ← (U+2190)
    (b"\xc3\xa2\xe2\x80\x94\xc2\x8f", b"\xe2\x97\x8f"),  # â—  -> ● (U+25CF)
    (b"\xc5\xa1\xc2\xa0",              b"\xe2\x9a\xa0"),  # Å¡\xa0 -> ⚠ (U+26A0)
    # Catch residual partial after third replacement in case it left leading bytes
    (b"\xc3\xa2\xe2\x9a\xa0",          b"\xe2\x9a\xa0"),  # strip stray c3 a2 prefix
]

with open(TARGET, "rb") as f:
    data = f.read()

orig_len = len(data)
for bad, good in REPLACEMENTS:
    n = data.count(bad)
    if n:
        data = data.replace(bad, good)
        print(f"  Fixed {n}x: {bad.hex()} -> {good.hex()}")

if data.count(b"SNAPSMACK EOF") == 0:
    print("WARNING: EOF marker missing after patch — aborting, no write.")
else:
    with open(TARGET, "wb") as f:
        f.write(data)
    print(f"Done. {TARGET} patched in place.")
# ===== SNAPSMACK EOF =====

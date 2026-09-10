"""Gemini must be TOLD the filename — a prompt that says "the title is the
filename" cannot work otherwise (Sean, 2026-09-10). Pure-function checks."""

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT.parent / "_shared"))

import gemini            # noqa: E402  (SYBU client)
import snap_enrich       # noqa: E402  (shared client: COLD SNAP / GYSS / SLAPPER)


def _checks():
    n = 0
    for mod in (gemini, snap_enrich):
        stem = mod.filename_stem("496, Car Show, Strathmore, AB, 2026-07-11.jpg")
        assert stem == "496, Car Show, Strathmore, AB, 2026-07-11", stem
        assert mod.filename_stem(r"C:\x\Black Corvette Dash.PNG") == "Black Corvette Dash"
        n += 2
        # No token: the FILENAME line is prepended, prompt text untouched.
        out = mod.prompt_with_filename("TITLE: <the filename>", "a.jpg")
        assert out.startswith("FILENAME: a\n"), out
        assert out.endswith("TITLE: <the filename>"), out
        n += 2
        # Token: substituted in place, no extra line.
        out = mod.prompt_with_filename("TITLE: {filename}\nTAGS: x", "Candy Orange Fender.jpg")
        assert out == "TITLE: Candy Orange Fender\nTAGS: x", out
        assert "FILENAME:" not in out
        n += 2
        # Empty name: prompt returned as-is.
        assert mod.prompt_with_filename("p", "") == "p"
        n += 1
    return n


if __name__ == "__main__":
    print(f"OK - {_checks()} checks passed")
# ===== SNAPSMACK EOF =====

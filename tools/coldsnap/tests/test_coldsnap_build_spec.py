"""COLD SNAP frozen-build boundary checks.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import ast
from pathlib import Path


def test_production_bundle_excludes_unittest_so_real_home_is_not_refused():
    spec = Path(__file__).resolve().parents[1] / "coldsnap-qt.spec"
    tree = ast.parse(spec.read_text(encoding="utf-8"))
    analysis = next(
        node for node in ast.walk(tree)
        if isinstance(node, ast.Call) and getattr(node.func, "id", "") == "Analysis"
    )
    excludes = next(
        keyword.value for keyword in analysis.keywords if keyword.arg == "excludes"
    )
    values = ast.literal_eval(excludes)
    assert "unittest" in values


# ===== SNAPSMACK EOF =====

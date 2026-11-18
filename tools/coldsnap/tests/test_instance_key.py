"""Tests for COLD SNAP Qt single-instance key selection.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

COLD_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if COLD_DIR not in sys.path:
    sys.path.insert(0, COLD_DIR)

from coldsnap_qt.app import instance_key


def test_prod_version_uses_prod_instance_key():
    assert instance_key("0.7.33") == "cold-snap"
    assert instance_key("0.7.35") == "cold-snap"


def test_test_build_uses_isolated_instance_key():
    assert instance_key("0.7.35-test") == "cold-snap-0.7.35-test"
    assert instance_key("0.7.34-test ") == "cold-snap-0.7.34-test"

# ===== SNAPSMACK EOF =====

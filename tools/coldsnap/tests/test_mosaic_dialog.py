"""Headless checks for COLD SNAP's contextual mosaic choices."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from coldsnap_qt.mode_take import TakeMode


def _checks():
    assert [v for _, v in TakeMode._mosaic_layouts(1)] == ["asymmetric"]
    assert len(TakeMode._mosaic_layouts(2)) == 3
    assert [v for _, v in TakeMode._mosaic_layouts(3)] == [
        "one-left", "one-right", "three-across", "one-top"]
    assert [v for _, v in TakeMode._mosaic_layouts(4)] == [
        "asymmetric", "columns", "rows", "square"]
    return 4


if __name__ == "__main__":
    print(f"OK - {_checks()} checks passed")

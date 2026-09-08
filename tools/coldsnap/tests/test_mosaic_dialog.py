"""Headless checks for COLD SNAP's contextual mosaic choices."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from coldsnap_qt.mode_take import TakeMode, MosaicPreview


def _checks():
    assert [v for _, v in TakeMode._mosaic_layouts(1)] == ["asymmetric"]
    assert len(TakeMode._mosaic_layouts(2)) == 3
    assert [v for _, v in TakeMode._mosaic_layouts(3)] == [
        "one-left", "one-right", "three-across", "one-top"]
    assert [v for _, v in TakeMode._mosaic_layouts(4)] == [
        "asymmetric", "columns", "rows", "square"]
    assert len(MosaicPreview.tile_rects(600, 240, 3, "one-left")) == 3
    one_top = MosaicPreview.tile_rects(600, 240, 3, "one-top")
    assert one_top[0].width() == 600 and one_top[1].top() > one_top[0].top()
    across = MosaicPreview.tile_rects(600, 240, 3, "three-across")
    assert across[0].top() == across[1].top() == across[2].top()
    assert TakeMode._exclusive_checks(4, [1, 2, 3]) == [False, True, True, True]
    return 8


if __name__ == "__main__":
    print(f"OK - {_checks()} checks passed")

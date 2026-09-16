"""Internal worker that keeps full raster decoding outside the GUI process."""

from __future__ import annotations

import os
import sys

import numpy as np

import highbit_image


def main(argv=None):
    argv = list(sys.argv if argv is None else argv)
    if len(argv) != 3:
        return 2
    source, target = map(os.path.abspath, argv[1:])
    highbit_image._safe_input_spec(source)
    image = highbit_image._read_direct(source)
    np.savez(target, pixels=image.pixels,
             channel_names=np.asarray(image.channel_names, dtype="U64"),
             source_format=np.asarray([image.source_format], dtype="U64"),
             icc_profile=np.frombuffer(image.icc_profile, dtype=np.uint8))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())


# ===== SNAPSMACK EOF =====

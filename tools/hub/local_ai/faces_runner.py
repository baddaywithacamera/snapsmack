"""External OpenCV runner for SNAP SLAPPER's People (faces) component.

This file is copied into the separately installed faces environment, beside a
copy of slapper_faces.py. It is not imported by the frozen SNAP SLAPPER
process: that build does not contain OpenCV, which is why this exists.

It imports the application's own slapper_faces, so there is exactly ONE
detector in SNAP SLAPPER rather than a second copy here that drifts away from
it. slapper_faces imports nothing from the application at module level.

Protocol, one photograph per line both ways, so a long scan reports as it goes
and one process serves the whole scan:

    in  : C:\\photos\\IMG_0001.jpg\\n
    out : {"path": "...", "faces": [{"box": [x, y, w, h], "score": 0.97,
                                     "embedding": [...128 floats...]}]}\\n
    out : {"path": "...", "error": "why this one failed"}\\n

A line of "quit" ends it. Errors are per photograph: one unreadable file must
not end a scan of five thousand.
"""

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import slapper_faces   # noqa: E402 — must follow the sys.path line above


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--warmup", action="store_true")
    parser.add_argument("--models", default="")
    arguments = parser.parse_args()
    directory = arguments.models or None

    if arguments.warmup:
        slapper_faces.ensure_models(directory)
        engine = slapper_faces.FaceEngine.load(directory)
        import cv2
        print(json.dumps({"ok": engine is not None, "opencv": cv2.__version__}),
              flush=True)
        return 0 if engine is not None else 1

    engine = slapper_faces.FaceEngine.load(directory)
    if engine is None:
        print(json.dumps({"fatal": "The face models are not installed."}),
              flush=True)
        return 1
    print(json.dumps({"ready": True}), flush=True)
    for line in sys.stdin:
        path = line.strip()
        if not path or path == "quit":
            break
        try:
            faces = engine.detect(path)
            payload = {"path": path, "faces": [
                {"box": face.box, "score": face.score,
                 "embedding": face.embedding} for face in faces]}
        except Exception as error:  # noqa: BLE001 — reported, never fatal
            payload = {"path": path, "error": str(error)[:400]}
        print(json.dumps(payload), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====

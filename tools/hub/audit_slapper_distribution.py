"""Fail the release when SNAP SLAPPER's binary/licence contract is broken."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import sys


FORBIDDEN = ("x265", "x264", "libraw", "rawspeed", "avcodec", "avformat",
             "avutil", "libheif", "libde265", "ffmpeg")
REQUIRED_LICENSES = (
    "SNAPSMACK-LICENSE.txt", "THE-THOMAS-CLAUSE.txt",
    "NumPy-BSD-3-Clause.txt", "OpenImageIO-Apache-2.0.txt",
    "OpenImageIO-THIRD-PARTY.txt", "SNAP-SLAPPER-HIGH-BIT-ENGINE-NOTICE.txt",
    "Qt-PySide6-LGPLv3-NOTICE.txt", "LGPL-3.0.txt", "GPL-3.0.txt",
    "rawtherapee-external-tool-notice.txt", "luminance-hdr-external-tool-notice.txt",
    "artifacts/tifffile-2026.9.9/LICENSE",
)
REQUIRED_BINARIES = ("OpenImageIO.dll", "OpenImageIO_Util.dll", "Qt6Core.dll")


def audit(root):
    root = Path(root).resolve()
    executable = root / "SNAP SLAPPER.exe"
    license_dir = root / "licenses"
    target = license_dir / "SNAP-SLAPPER-BINARY-DEPENDENCY-MANIFEST.json"
    failures = []
    if not executable.is_file():
        failures.append("SNAP SLAPPER.exe is missing")
    for name in REQUIRED_LICENSES:
        if not (license_dir / name).is_file():
            failures.append(f"licenses/{name} is missing")
    files = [path for path in root.rglob("*") if path.is_file() and path != target]
    lower_names = [str(path.relative_to(root)).lower() for path in files]
    for forbidden in FORBIDDEN:
        matches = [name for name in lower_names if forbidden in name]
        if matches:
            failures.append(f"forbidden GPL/RAW/video component {forbidden}: {matches}")
    for required in REQUIRED_BINARIES:
        if not any(path.name.lower() == required.lower() for path in files):
            failures.append(f"required replaceable library is missing: {required}")
    if failures:
        raise RuntimeError("Distribution audit failed:\n- " + "\n- ".join(failures))
    manifest = {
        "schema": 1,
        "application": "SNAP SLAPPER",
        "codec_policy": "GPL-free; external RawTherapee and Luminance HDR only",
        "forbidden_name_gate": list(FORBIDDEN),
        "files": [{"path": str(path.relative_to(root)).replace("\\", "/"),
                   "bytes": path.stat().st_size,
                   "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
                  for path in sorted(files)],
    }
    target.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    written = json.loads(target.read_text(encoding="utf-8"))
    for entry in written["files"]:
        candidate = root / entry["path"]
        if (not candidate.is_file() or candidate.stat().st_size != entry["bytes"] or
                hashlib.sha256(candidate.read_bytes()).hexdigest() != entry["sha256"]):
            raise RuntimeError(f"Distribution manifest verification failed: {entry['path']}")
    return target, len(files)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: audit_slapper_distribution.py <application-directory>")
    manifest, count = audit(sys.argv[1])
    print(f"Distribution audit passed: {count} files; manifest: {manifest}")


# ===== SNAPSMACK EOF =====

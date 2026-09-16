import hashlib
import json
from pathlib import Path
import sys


HUB = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HUB))

import audit_slapper_distribution as distribution


def test_manifest_excludes_itself_and_verifies_payload(tmp_path, monkeypatch):
    root = tmp_path / "SNAP SLAPPER"
    licenses = root / "licenses"
    licenses.mkdir(parents=True)
    (root / "SNAP SLAPPER.exe").write_bytes(b"exe")
    for name in distribution.REQUIRED_LICENSES:
        target = licenses / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("license", encoding="utf-8")
    for name in distribution.REQUIRED_BINARIES:
        (root / name).write_bytes(b"dll")
    old_manifest = licenses / "SNAP-SLAPPER-BINARY-DEPENDENCY-MANIFEST.json"
    old_manifest.write_text('{"stale": true}', encoding="utf-8")

    manifest, count = distribution.audit(root)
    value = json.loads(manifest.read_text(encoding="utf-8"))
    paths = {entry["path"] for entry in value["files"]}
    assert "licenses/SNAP-SLAPPER-BINARY-DEPENDENCY-MANIFEST.json" not in paths
    assert count == len(value["files"])
    for entry in value["files"]:
        candidate = root / entry["path"]
        assert candidate.stat().st_size == entry["bytes"]
        assert hashlib.sha256(candidate.read_bytes()).hexdigest() == entry["sha256"]


# ===== SNAPSMACK EOF =====

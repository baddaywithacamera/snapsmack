# SLAP BACK

SLAP BACK is the independent, offline recovery tool for portable `.slapper` files.
It does not import SNAP SLAPPER, contact SnapSmack, request authentication, or execute
anything stored in a project.

```text
python slap_back.py project.slapper --json
python slap_back.py project.slapper --extract-original recovered
python slap_back.py project.slapper --extract-all recovered-project
python slap_back.py project.slapper --export-flat recovered.jpg
```

Existing files receive collision-safe names unless `--overwrite` is explicit. The
original is accepted only when its packaged SHA-256 checksum matches.

The current reference implementation verifies, inspects, extracts, and exports the
packaged full-resolution composite as JPEG, PNG, or TIFF. Layered PSD, OpenRaster, and
layered-TIFF exporters remain release-gating work and must not be described as complete.

<!-- ===== SNAPSMACK EOF ===== -->

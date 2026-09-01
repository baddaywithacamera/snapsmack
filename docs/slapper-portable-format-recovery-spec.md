<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# SLAPPER Portable Project and Recovery Tool Specification

**Status:** Approved architecture / implementation pending  
**Written:** 2026-08-25  
**Recovery-tool name:** SLAP BACK  
**Rejected name:** SLAP YOUR BITS UP / SYBU, because it is easily confused with the
existing real-world use of SYBU

## 1. Governing promise

A `.slapper` project must remain recoverable if SNAP SLAPPER, SnapSmack, snapsmack.ca,
its entitlement service, every AI provider, and every person currently maintaining the
project disappear.

The format must never require authentication, decryption, a network request, or an
undocumented proprietary decoder to recover the user's original photograph and ordinary
project components.

## 2. Container format

`.slapper` is a standard ZIP/ZIP64 archive with a custom extension. It uses no proprietary
compression and no encryption. A user may rename it to `.zip` or open it directly with
an ordinary ZIP utility.

The archive must contain a root `README.txt` beginning with a plain explanation that it
is a standard ZIP archive and describing how to recover its contents without SNAP
SLAPPER.

Required top-level structure:

```text
project.slapper
|-- mimetype
|-- manifest.json
|-- README.txt
|-- original/
|   `-- original.<source-extension>
|-- layers/
|   |-- <stable-layer-id>/layer.json
|   |-- <stable-layer-id>/pixels.png-or-tif
|   |-- <stable-layer-id>/mask.png
|   `-- <stable-layer-id>/fallback.png-or-tif
|-- resources/
|   |-- textures/
|   |-- brushes/
|   |-- luts/
|   `-- profiles/
|-- metadata/
|   |-- original-exif.json
|   |-- provenance.json
|   |-- dependencies.json
|   `-- checksums.json
|-- schemas/
|   `-- project-schema.json
`-- previews/
    |-- composite.png-or-tif
    `-- thumbnail.jpg
```

The exact internal paths may evolve only through a documented format-version change.
ZIP entries use forward slashes and portable UTF-8 names. Importers reject absolute
paths, parent traversal, device names, links escaping the destination, duplicate
security-sensitive entries, decompression bombs, and unreasonable declared sizes.

## 3. Original photograph

- The embedded original is byte-for-byte identical to the source file.
- Its original filename, extension, byte length, and SHA-256 hash are recorded.
- It is never recompressed, normalized, metadata-edited, or decoded and rewritten merely
  to enter the project.
- A RAW original may be embedded and recovered even though SNAP SLAPPER does not process
  RAW photographs internally.
- Recovery of the original must remain possible even if every edit operation is unknown.

## 4. Layer representation

Each layer has a stable UUID. Human-readable stack order is stored separately in
`manifest.json`; filenames are not the authoritative order, so reordering a layer does
not change its identity.

Each `layer.json` records:

- Stable ID, type, name, visibility, opacity, blend mode, and stack position
- Mask and resource references
- Exact machine-readable settings with explicit units and ranges
- Operation/schema version
- A plain-English description of what the layer does
- Whether the layer can be recreated natively, approximately, or only from its fallback
- Any external dependency and the consequence if that dependency is missing

Machine-readable values are authoritative. The English description exists for human
recovery and must not be the only representation of an operation.

Raster layers use standard PNG or TIFF payloads. Masks use grayscale PNG or TIFF.
Higher-bit-depth or wide-gamut data must not be silently reduced to 8-bit merely for
container convenience.

## 5. Procedural and adjustment fallbacks

Every SNAP SLAPPER-specific filter, LEWK, procedural texture, adjustment, plugin result,
or other operation that ordinary software cannot reproduce must include a standard-image
fallback sufficient to recover its visible contribution.

The project also contains a full-resolution composite fallback. A recovery tool must
state when it preserved appearance by rasterizing an editable operation. It must never
claim that a rasterized Photoshop/OpenRaster layer remains a native editable adjustment.

Unknown future layers are retained byte-for-byte when an older SNAP SLAPPER or recovery
tool rewrites a project. Unsupported data is not silently discarded.

## 6. Metadata and provenance

The archive records readable copies of:

- Source EXIF and embedded metadata
- ICC profiles and colour-space information
- DPI and orientation
- Copyright and creator data
- Found Textures identifiers, source URLs, creator, retrieval date, and copyright status
- External brushes, LUTs, plugins, fonts, LEWKS, and other dependencies
- Which resources are embedded and which were merely referenced
- SHA-256 checksums for the original and every packaged resource

The embedded original remains the authority for its own metadata. JSON metadata copies
exist for readability and recovery; they never justify rewriting the original.

## 7. Saving and validation

- Write a new archive to a temporary sibling path.
- Complete and close the ZIP central directory.
- Reopen it independently.
- Validate required entries, JSON schemas, checksums, layer order, references, and the
  original hash.
- Only after successful validation atomically replace the prior project where supported.
- Preserve the last known-good project when validation or replacement fails.
- Use ZIP64 automatically when required.
- Store already-compressed JPEG, PNG, and similar resources without wasteful recompression.
- Never include executable scripts or run content found inside an archive.

## 8. Current project migration

The current project implementation stores version-1 `.slapper` files as JSON with local
path references and base64 masks. The ZIP-based format is the next major project-schema
version, not a silent reinterpretation of version 1.

SNAP SLAPPER and the independent recovery tool must:

- Detect legacy JSON before attempting ZIP parsing
- Read and recover version-1 adjustments, geometry, layers, masks, and source references
- Offer conversion to a new self-contained ZIP/ZIP64 project
- Report missing externally referenced files clearly
- Never overwrite the legacy project during conversion
- Maintain public fixtures for both legacy and current versions

## 9. Independent recovery/conversion tool

**SLAP BACK** is a small independently buildable tool that lives in the public GitHub
repository and is available as source plus downloadable Windows and Linux builds. Do not
use the rejected SYBU name.

It requires no:

- SnapSmack CMS installation
- SNAP SLAPPER installation
- Entitlement or API key
- SnapSmack server
- Network connection
- AI service
- User account

It provides a simple GUI and command-line interface.

Required operations:

- Inspect and verify a `.slapper` archive
- Explain compatibility, missing dependencies, rasterization, and recovery limitations
- Extract the byte-identical original
- Extract every layer, mask, texture, profile, LEWK, and readable metadata document
- Export a flattened JPEG, PNG, or TIFF
- Export layered PSD where supported
- Export OpenRaster `.ora`
- Produce a plain-text and JSON recovery report
- Recover every independently readable entry possible from a partially damaged archive
- Choose collision-safe output names and never overwrite without explicit confirmation

The command-line executable is `slap-back`. Exact flags are finalized with the
implementation and documented in bundled offline help.

## 10. Conversion fidelity

- Raster layers and masks transfer directly where the destination supports them.
- Supported SNAP SLAPPER operations are rendered by the public reference compositor.
- Unsupported native adjustments become clearly labelled raster layers using packaged
  fallbacks.
- The recovery report distinguishes exact, visually rasterized, approximated, omitted,
  and unrecoverable elements.
- A flattened export uses the full-resolution packaged composite when the reference
  compositor cannot reproduce a newer operation.
- PSD, flattened-TIFF, and OpenRaster limitations are reported plainly rather than hidden behind a
  successful-looking export message.

## 11. Public documentation and durability

The repository includes:

- Complete container and operation specifications
- JSON Schemas
- Blend, mask, colour, rounding, and compositing definitions
- Public example projects
- Legacy and current-version fixtures
- Expected recovered files and hashes
- Reproducible build instructions
- A compatibility/version matrix
- Instructions for third parties to implement independent readers

Formats must not depend solely on prose or on the current SNAP SLAPPER implementation.
The reference compositor and recovery tool are separate enough that a broken or expired
SNAP SLAPPER build cannot prevent recovery.

## 12. Release gate

Every SNAP SLAPPER release that changes project writing or rendering must pass an
independently executed recovery test:

1. Create a representative `.slapper` project.
2. Verify the original hash.
3. Open and inspect it as an ordinary ZIP.
4. Recover it using the independently built recovery tool without authentication or
   networking.
5. Export its original, component assets, flattened formats, PSD, and OpenRaster.
6. Compare expected manifests, checksums, dimensions, colour information, metadata, and
   declared compatibility results.

Failure of this gate blocks release. Data portability is a product invariant, not a
best-effort feature.

<!-- ===== SNAPSMACK EOF ===== -->

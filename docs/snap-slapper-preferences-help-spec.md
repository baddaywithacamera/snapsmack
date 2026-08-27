<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# SNAP SLAPPER Preferences, Help, and File-Safety Specification

**Status:** Required before public beta  
**Written:** 2026-08-25

## 1. Immutable-original rule

SNAP SLAPPER never modifies, overwrites, rotates in place, strips metadata from, moves,
or deletes an original photograph as an editing side effect. Every edit and conversion
creates a new derivative. File-management moves and recoverable Trash are separate,
explicit organizer operations with confirmation.

Every derivative preserves all available source EXIF, camera information, timestamps,
ICC profile, DPI, copyright, and other embedded metadata. GPS is the only metadata that
may be removed, only from a newly created derivative, and only when the user explicitly
enables that privacy choice. The original retains its GPS. Existing copyright is never
overwritten; configured copyright is added only when the field is missing.

All save/export paths use one central metadata-preserving writer so individual filters,
batches, plugins, LEWKS, or future features cannot bypass this invariant.

## 2. Preferences window

Provide one searchable Preferences window with these sections:

- **Identity and metadata:** creator/copyright defaults, add-if-missing behavior, preserve
  all metadata, and the explicit GPS-only derivative privacy option.
- **Files and saving:** default project, export, texture, LEWKS, preset, cache, and local
  upload-copy folders; default formats, quality/compression, filename templates, and
  collision-safe behavior.
- **Colour management:** working and export colour spaces, embedded ICC preservation,
  optional sRGB conversion for web copies, rendering intent, monitor profile, soft proof,
  and clipping/gamut warnings. Creative colour grading stays in the editor.
- **External tools:** detected editors, Photoshop-compatible plugins in use, plugin
  folders, brush sets, and installed LEWK sets.
- **Found Textures:** local gallery/cache location, cache policy, authentication for site
  favorites, local private categories, and hiding unclear-copyright textures by default.
- **SUYB:** locations published to SUYB, extra included locations, and selected backup
  data types such as projects, exports, organizer metadata, albums, preferences, LEWKS,
  brushes, plugins, and downloaded textures. Disposable cache is excluded by default.
- **Interface and performance:** thumbnail defaults, subfolder default, preview quality,
  background scanning, autosave/recovery, history depth, and confirmation behavior.

Settings use a versioned local format rather than scattered UI state. Workstation paths
and executable locations remain machine-local. SUYB consumes a published backup manifest
instead of guessing which SNAP SLAPPER directories matter.

## 3. Unsupported and RAW files

SNAP SLAPPER does not implement a RAW editor or RAW-processing workflow. If internal
decoding fails or a recognized RAW format is opened, show a simple offline message:

> SNAP SLAPPER cannot open this file format. If this is a RAW photograph, open it with
> RawTherapee or darktable.

When either application is detected, offer `Open in RawTherapee` and/or `Open in
darktable`, plus `Choose another program` and `Cancel`. Launch the untouched file using
safe platform-native process arguments on Windows and Linux. Do not build return-folder
watching, duplicate RAW conversion, or a native RAW subsystem. Never modify the source.

## 4. Offline help system

Help ships inside every Windows and Linux application package and remains fully usable
without a network connection.

- Searchable contents and index
- Quick-start workflow
- `F1` opens help for the active window, tool, or panel
- Visible contextual help controls beside unfamiliar interactions
- Tool instructions, screenshots, examples, and expected results
- Mouse and keyboard reference
- Layers, masks, filters, brushes, projects, presets, LEWKS, and batch help
- File safety, immutable originals, metadata preservation, and GPS privacy explanation
- Unsupported/RAW guidance recommending RawTherapee and darktable
- Recovery, backup, and troubleshooting guidance
- Help content version matching the installed application

Online links may offer newer material, but no essential instruction may require the
website. Help text should be sourced from stable tool definitions where practical so UI
labels, shortcuts, and documentation do not silently diverge.

## 5. Acceptance requirements

- Original-file hashes remain unchanged through editing and export tests.
- All derivative formats preserve metadata except explicit GPS-only removal.
- GPS removal is verified against a derivative while the original remains byte-identical.
- Existing copyright survives; missing copyright is added only when configured.
- Preferences survive restart and invalid/older schemas fail safely.
- SUYB receives only explicitly published locations and data types.
- Unsupported and RAW messages work offline on Windows and Linux.
- Bundled help search, contextual F1 routing, and core articles work with networking
  disabled.

<!-- ===== SNAPSMACK EOF ===== -->

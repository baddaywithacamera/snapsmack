<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER — catalog, project and backup specification

**Status:** design gate; implementation must not begin until this document is reviewed.  
**Product boundary:** original SnapSmack code under SPL; no HEIC/HEIF; originals are immutable.

## Product model

PIXEL TICKLER has two presentations over one processing engine and one project model.

- **Basic** is the default, photo-first workspace: library/lighttable, bottom filmstrip,
  crop/straighten, photographic adjustments, effects and Save Copy. It hides layer
  machinery and records ordinary tonal edits in a designated `Photo Adjustments` layer.
- **Advanced** exposes adjustment, text and image layers; nested layer folders; masks;
  styles; blend modes; opacity/fill; groups; `.spt` project management; and PSD interchange.

Changing workspaces never converts, duplicates, flattens or rerenders the project. Basic
may open a complex project and show its correct composite, but only edits the designated
basic adjustment layer. Geometry remains document-level because it changes the canvas.

The UI is inspired by the approachability of classic photo organizers, not copied from
Picasa or another application. It uses original structure, language, icons and Midnight
Lime styling. Public copy may say “inspired by approachable, photo-first organizers” but
must not imply Google, Adobe, Serif, ON1 or Topaz affiliation.

## Immutable originals

The engine never writes, replaces, renames or deletes an opened original. There is no
`edit-replace-working-copy` mode. `Save Copy` and every suite handoff create a derivative.
An existing destination is never silently replaced: choose another path or generate a
collision-safe name. Autosave stores recipe state only. A portable project may embed an
unaltered copy of the original but cannot modify the source file.

## Library and catalog

The user registers folders; photographs remain where the user put them. Scanning admits
only verified JPEG, PNG, WebP and TIFF images. Unsupported, disguised, corrupt or unsafe
files are absent from the browser rather than shown as broken thumbnails.

Durable catalog truth is split from disposable acceleration:

1. A portable, atomic, documented JSON record travels with each registered folder and
   stores stable image identity, relative path, pick/reject, zero-to-five stars, colour
   label, edit/project reference and optional metadata. It never stores credentials.
2. A local SQLite index provides fast cross-folder search, sorting and pagination. SQLite
   is selected for its public-domain status. The index is a rebuildable cache, never the
   sole copy of user decisions.
3. Thumbnails and previews live in a versioned disposable cache and may be regenerated.

Image identity uses normalized relative path plus size and content fingerprint. A
relocation workflow finds moved folders and verifies candidates before relinking. If the
photo folder is read-only, portable records fall back under `SNAPSMACK_HOME` while keeping
the same documented JSON shape. Concurrent writers use generation IDs and atomic replace;
conflicts stop and request reconciliation rather than discarding newer ratings.

The bottom browser has Hidden, Filmstrip and Lighttable states. Its upper edge is
draggable, its height and filters are remembered, and `Tab` can hide both docks. Single
click selects, double click edits; P/X flag, 0–5 rating and colour-label shortcuts work
without opening the editor. Thumbnail work is lazy, bounded and cancellable.

## Open layered project format

`.spt` version 2 is an openly documented ZIP64 package using standard files:

```text
mimetype
manifest.json
preview.webp
assets/overlays/
assets/masks/
assets/thumbnails/
profiles/
```

The manifest stores source fingerprint, document geometry/colour policy, ordered layers,
nested folders, operations, masks, styles, blend modes, export presets and version data.
All internal paths are relative and traversal-safe. Masks use documented PNG/TIFF raster
data. No executable content is permitted. Unknown optional properties survive a round
trip; unknown required operations fail closed. Version-1 JSON `.spt` remains readable.

Regular projects reference the original. `Save Portable Project` embeds the original and
all assets. Fonts are referenced with documented fallbacks and are not embedded without
explicit, verified redistribution permission. Publish schemas and a validator with the
application so another free implementation can read and write `.spt`.

## Layers and folders

Layers may be nested in folders to a documented safe maximum depth. Folders have name,
visibility, lock, opacity, blend mode, collapse state and optional colour label. Structural
actions are undoable and survive project round trips. The initial blend set is Normal,
Multiply, Screen, Overlay and Soft Light; folder pass-through semantics must be specified
before adding them. Basic mode hides this structure but never removes it.

## Interchange and external editors

`.spt` is authoritative. Optional layered PSD export is an interchange copy produced by
original code based on Adobe's published format specification; no Adobe/Serif library,
SDK, code or asset is distributed. A legal/dependency review gates shipment.

PSD export preserves raster layers, names, folders, order, visibility, opacity, common
blend modes, compatible masks and a flattened composite. Unsupported PIXEL TICKLER
adjustments render to pixel layers for appearance. The export UI offers Preserve
Appearance, Preserve Editability (approximate) and Hybrid; Hybrid is default. Never claim
pixel-identical editable adjustments or compatibility certification.

`Edit Copy In…` creates a new 16-bit TIFF, opens a user-configured external application,
waits for a separately saved result and imports that result as a named image layer. This
supports tools such as RawTherapee, ON1, Topaz, Affinity or Photoshop without hosting
their plugins, distributing their code or modifying the original.

## Backup and restore

SMACK UP YOUR BACKUP currently protects SnapSmack websites; local library protection is a
new explicit feature, not an implied side effect of website backup.

PIXEL TICKLER exposes a snapshot operation that flushes portable records and creates a
consistent SQLite backup without copying live WAL state incorrectly. SMACK UP YOUR BACKUP
collects that snapshot plus `.spt` projects, masks/assets, presets and library-root map;
hashes every durable file; writes a versioned manifest; packages; uploads when configured;
then verifies remote size and content hash. No site or application credential belongs in
the catalog package.

Thumbnail, preview, render and cleanly obsolete autosave caches are excluded by default.
They may be included only as an explicitly labelled performance option.

Catalog backup is **not photo backup**. The UI must separately offer “Include original
photographs from my library folders,” show the estimated bytes and destinations, and
default it off until configured. Restore verifies the package, restores durable state,
rebuilds disposable indexes/caches and provides a missing-folder relocation workflow.
Restoring never overwrites a photograph without a separate collision decision.

## Acceptance gate

Before catalog implementation: freeze schemas and migration rules; test removable-drive
letter changes, Unicode, read-only folders, concurrent ratings, moved/missing/changed
photos, corrupt records, interrupted atomic writes, SQLite snapshot during active use,
backup without originals, backup with originals, verified restore, cache rebuild, nested
folder round trips, version-1 project migration and original immutability.

<!-- ===== SNAPSMACK EOF ===== -->

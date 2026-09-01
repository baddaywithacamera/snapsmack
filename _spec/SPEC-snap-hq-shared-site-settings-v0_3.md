# SPEC — SNAP HQ shared per-site settings

**File:** `SPEC-snap-hq-shared-site-settings-v0_3.md`
**Date:** 2026-08-31
**Status:** v0.3 review draft — Claude applying Sean's decisions to Codex's v0.2. Not cleared to build.
**Supersedes after three-way sign-off:**
- `SPEC-snap-hq-shared-site-settings-v0_2.md` (Codex)
- `SPEC-snap-hq-site-config-ssot-v0_1.md` (Claude)
- `SPEC-shared-upload-dir-and-prompt-across-tools.md` (Claude)

**What changed from v0.2 (three decisions from Sean, 2026-08-31):**
1. **Handoff folders are siblings, not nested.** `upload/` and `done/` sit side by side under one parent. Kills the re-scan bug and matches Sean's stated dislike of nesting (been bitten before).
2. **Conflict machinery cut.** No baseline-tracking apparatus, no two-sided conflict panel. Portable settings are read-only in SNAP HQ when offline, which removes the conflict class entirely. See Synchronization.
3. **EXIF is a hard two-lever guarantee.** SNAP SLAPPER modifies exactly two EXIF fields — copyright (editable) and GPS (strippable) — and nothing else, ever. Upload export defaults GPS-strip ON; archive keeps GPS.

One item from Codex's v0.2 remains **unconfirmed by Sean** — see Decision 4 (portrait/landscape sizing asymmetry). It is flagged, not accepted.

---

## One line

Configure a blog's portable workflow settings once through the online multisite hub, configure each computer's local handoff directory once through SNAP HQ, and let every desktop tool consume one visibly synchronized per-site profile.

## Problem

Prompt, image-size, export, and handoff settings currently risk being repeated across the CMS and the desktop tools. Repetition creates drift, extra clicks, mismatched sites, unexpected re-resizing, and uncertainty about which copy is correct.

The online service and a local desktop program are **not** literally one authority. They coordinate, but own different categories of data:

- the **online multisite hub** owns portable per-blog settings;
- **SNAP HQ on each computer** owns machine-local paths and local workflow state;
- `snap_profiles` is a visible offline mirror consumed by desktop tools, never an independent authority.

## Names

- **SNAP HQ:** the desktop launcher and configuration application, formerly "THE HUB."
- **Online multisite hub:** the CMS hub in the existing hub/spoke network.
- **Spoke:** an individual SnapSmack blog managed through the online multisite hub.
- **Shared profile:** the combined portable + machine-local view exposed to desktop tools.

Do not call SNAP HQ and the online multisite hub "two faces of the same authority." One system, two ownership boundaries.

`ASH NAZG` is an inscription / boot / about line only. Never a functional product name, config key, directory name, or API name.

---

## Authority boundaries

### Online multisite hub — owns portable per-blog settings
`prompt`, `max_width_landscape`, `max_height_portrait`, `jpeg_quality`, `image_resize_enabled`, `export_sharpen`. These follow the blog across computers. The hub is canonical.

### SNAP HQ — owns machine-local settings
`handoff_dir` (the parent path; see Handoff), local directory validation state, last successful local export/import details, last sync timestamp and cache metadata. A local Windows path is not portable authority and is never pushed to a blog.

### Desktop tools — consume, never own
SNAP SLAPPER, SYBU, and SUYB read the combined shared profile through the common shared-profile layer. They must not keep authoritative private copies of these fields.

---

## Per-site fields and proposed defaults

| Field | Owner | Proposed default | Consumers |
|---|---|---:|---|
| `prompt` | Online hub | unset | CMS, SYBU |
| `handoff_dir` | SNAP HQ (per computer) | unset | SNAP SLAPPER, SYBU, SUYB |
| `max_width_landscape` | Online hub | 3840 px *(see Decision 4)* | SNAP SLAPPER, CMS |
| `max_height_portrait` | Online hub | 2160 px *(see Decision 4)* | SNAP SLAPPER, CMS |
| `jpeg_quality` | Online hub | 85 | SNAP SLAPPER, CMS |
| `image_resize_enabled` | Online hub | on | SNAP SLAPPER, CMS |
| `export_sharpen` | Online hub | `auto` | SNAP SLAPPER |

**Field rename from v0.2:** `upload_dir` → `handoff_dir`. It now denotes the **parent** directory, not the upload folder itself. Deliberate rename so nothing reads the old value with the old meaning — do not silently reuse the `upload_dir` key with new semantics.

---

## Image-processing contract

- Landscape images capped at `max_width_landscape`; portrait images capped at `max_height_portrait`.
- **Downscale only.** Neither SNAP SLAPPER nor the CMS ever upscales a smaller source.
- SNAP SLAPPER and the CMS honor the same caps, so the CMS never re-shrinks an already-correct export.
- Export order: **resize → output sharpen → encode/write.**
- `export_sharpen = auto` derives a mild output sharpen from the actual reduction ratio. Advanced choices may expose `off` / `low` / `medium`; never expose raw implementation parameters in the ordinary workflow.

### EXIF — hard two-lever guarantee (binds every tool)
SNAP SLAPPER modifies **exactly two** EXIF fields and no others, ever:
- **copyright** — editable.
- **GPS** — strippable.

Every other EXIF field is **preserved unchanged** through resize, sharpen, and encode.
- **Upload export defaults GPS-strip ON.** (Privacy: published coordinates are a stalking vector.)
- **Archive export keeps GPS.**
- **No downstream tool** — SYBU, CMS, SUYB — modifies EXIF in any path. What SLAPPER writes is what ships.

This boundary is load-bearing and deliberate. It is not to be "improved" into blanket stripping or blanket preservation by any future change.

---

## Synchronization model

### Local mirror
Portable settings are cached under `C:\snapsmack\shared_library\profiles\<site>.json` — a mirror for offline **reading**, not a second authority. Machine-local settings live in a clearly separate machine-local section/file and must never be mistaken for portable data during backup, sync, or restore. No API keys or secrets in profile `extras` JSON.

### Refresh triggers
SNAP HQ refreshes portable settings: (1) at startup; (2) on site selection; (3) on **SYNC NOW**. Every consuming tool refreshes through the shared sync layer, not its own network client.

### Offline behaviour — and why there is no conflict machinery
Portable settings are **owned by the online hub and edited there** (or through SNAP HQ writing through to the hub while online, reporting success/failure). **When the hub is unreachable, portable settings are shown read-only in SNAP HQ.** You cannot originate an offline edit to a portable field.

That single rule removes the entire conflict class: if a portable value can only change at its owner, the local mirror can never hold a competing edit. So v0.2's baseline tracking, revision metadata, modification-time evidence, and two-sided conflict panel are **cut** — they defended a case that can no longer occur.

While offline, SNAP HQ:
- uses last-known portable values for any read;
- shows **OFFLINE COPY — synced [date/time]** wherever those values drive an action;
- never presents a cached value as current;
- preserves machine-local edits (e.g. `handoff_dir`) normally — those aren't portable and don't sync to the hub.

*(Reversible decision: if Sean later wants to edit portable settings from a disconnected machine, the minimal conflict handling is a single last-synced value per field plus a plain "hub says X, local says Y — pick one" prompt on reconnect. Not built unless that need is real.)*

---

## Export-to-upload handoff

### Directory layout — siblings under one parent (changed from v0.2)
```text
<handoff_dir>\
  upload\   ← SNAP SLAPPER writes here; SYBU reads here
  done\     ← SYBU moves confirmed files here
```
`upload/` and `done/` are **siblings**, both children of the one configured `handoff_dir`. `done/` is **not** inside `upload/`. Because the folder SYBU scans (`upload/`) contains only files and never a subfolder, SYBU can never descend into `done/` and re-upload confirmed files — the nested-directory re-scan bug is structurally impossible. Same parent = same filesystem, so the atomic move is preserved.

### SNAP SLAPPER
1. User selects a blog.
2. SNAP SLAPPER resolves that blog's shared profile.
3. **EXPORT FOR UPLOAD** applies the image contract (resize → sharpen → EXIF per the two-lever guarantee, GPS stripped for upload).
4. Writes to a temp file `filename.jpg.part` in `upload/`.
5. After write + metadata handling complete, **atomically renames** to the final name. SYBU therefore sees complete files only. Same-filesystem requirement validated (or clearly warned) for network/container mounts.

### SYBU
1. User selects the same blog.
2. SYBU opens that site's `upload/` and auto-loads its prompt.
3. Uploads a completed file.
4. **Only after the CMS confirms the post** does SYBU move the file into `done/`.
5. Failed, rejected, interrupted, or uncertain uploads **stay in `upload/`.** No batch tool deletes an original.

### SUYB
Backs up: pending files in `upload/`; confirmed files in `done/`; the portable cached profiles; and the machine-local SNAP HQ configuration needed to reconstruct the workflow. On a different computer, SUYB may restore files and config, but SNAP HQ must ask the user to confirm/remap restored local paths before use.

---

## UI requirements

SNAP HQ provides one site editor showing: site identity + display name; portable fields and their sync status; machine-local `handoff_dir` and whether it currently exists; last successful sync time; **SYNC NOW**; an **offline-copy** warning when applicable. (No conflict panel — see Synchronization.)

Selecting one site drives prompt, handoff directory, sizing, quality, resizing, and sharpening together. Tools must not resolve these through unrelated mappings that merely share a site name.

---

## Rename and compatibility

Functional desktop name is **SNAP HQ**. Active titles, descriptions, shortcuts, executable metadata, docs, and new config names use SNAP HQ. Existing `hub.exe`, `C:\snapsmack\hub`, or historical identifiers may remain as **compatibility paths** that launch the current SNAP HQ build and must not create a second configuration authority. Physical directory migration happens only through a coordinated migration with rollback; cosmetic renaming alone doesn't justify breaking installed paths.

---

## Security and safety guardrails

- Local filesystem paths never travel to a website as portable settings.
- Profile JSON contains no secrets.
- All network writes authenticate through existing scoped mechanisms.
- Sync validates field types, ranges, and site identity.
- File handoff uses temp-write + atomic rename where the filesystem supports it.
- Completed files move only after explicit remote confirmation.
- **No delete-first** operation in export, upload, sync, or restore.
- Stale cached values are visibly identified.
- EXIF two-lever guarantee holds across every tool (copyright + GPS only; nothing else touched).

---

## Decisions

### Accepted (Sean, 2026-08-31)
1. Split authority: online hub owns portable settings; SNAP HQ owns machine-local settings.
2. Automatic refresh at launch + site selection, **SYNC NOW**, visible offline timestamp.
3. **Conflict machinery cut**; portable settings read-only when offline.
4. `jpeg_quality = 85`.
5. `export_sharpen = auto` with optional Off/Low/Medium.
6. Handoff folders are **siblings** `upload/` + `done/` under one `handoff_dir`.
7. EXIF preserved except the two levers (copyright editable, GPS strippable); GPS-strip default ON for upload, kept for archive.
8. Compatibility paths during rename instead of destructive directory migration.

### Still open — needs Sean's explicit call
**Decision 4 (sizing asymmetry, carried from v0.2, unconfirmed).** Separate caps mean a portrait's long edge (2160) is capped **lower** than a landscape's long edge (3840), so portraits export with fewer pixels and may read soft on high-DPR or portrait-oriented displays. Coherent if the intent is "fill a 4K frame in the constrained dimension," but it's a real tradeoff and Sean (the NYIP grad) hasn't confirmed it. Options: keep 3840/2160 as-is; raise portrait cap; or return to a single long-edge cap. **Not accepted until Sean says so.**

---

## Implementation sequence (after sign-off)
1. Combined profile schema + validation + clean machine-local separation. (No baseline/revision fields — conflict machinery is out.)
2. Online multisite API read/write contract for portable fields, incl. write-through with success/failure reporting.
3. One shared desktop sync client used by SNAP HQ and all consumers, with read-only-when-offline behaviour.
4. SNAP HQ per-site editor, offline state, directory validation. (No conflict UI.)
5. SNAP SLAPPER export: atomic writes, shared sizing, EXIF two-lever handling (GPS strip on upload), auto sharpen.
6. SYBU: prompt/directory loading, confirmation-gated move to `done/`, failed-stays-pending.
7. CMS upload-resize path: same caps, no upscaling.
8. SUYB backup/restore: folders + portable profiles + machine-local config, with local-path confirmation on restore.
9. Fix help docs pointing Image Processing at the wrong CMS section.
10. Cross-tool integration tests before calling the feature complete.

## Acceptance tests
- A portable cap changed at the hub reaches SNAP HQ and both processing paths after sync.
- A cached value is visibly marked stale/offline when the hub is unreachable.
- Portable settings are read-only in SNAP HQ while offline.
- A local Windows path never appears in a request to the CMS.
- Selecting one blog resolves all its fields from one profile identity.
- A 5000 px landscape export becomes ≤ 3840 px wide.
- A 4000 px portrait export becomes ≤ 2160 px high.
- A smaller source is never enlarged.
- Non-GPS EXIF survives export unchanged; GPS is absent from an upload export and present in an archive export; copyright reflects the edited value.
- SYBU never treats a `.part` file as uploadable.
- SYBU's scan of `upload/` never descends into `done/`.
- SYBU moves a file only after confirmed post completion; failed/uncertain uploads stay pending.
- Restoring on another computer requires local-path confirmation.
- Legacy Hub launch paths open the current SNAP HQ configuration, not a separate store.

---

## Three-way sign-off
Not cleared until Sean + Cowork + Codex align. **Codex specifically should confirm** that cutting the conflict machinery (replaced by read-only-when-offline) is safe against its v0.2 reasoning, and owns any DB-mutating PHP plus the atomic-write / confirmation-gated-move correctness review.

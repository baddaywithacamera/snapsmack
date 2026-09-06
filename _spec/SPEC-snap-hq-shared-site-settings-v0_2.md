# SPEC — SNAP HQ shared per-site settings

**File:** `SPEC-snap-hq-shared-site-settings-v0_2.md`  
**Date:** 2026-08-31  
**Status:** v0.2 review draft — Codex consolidation for Sean + Claude  
**Supersedes after three-way sign-off:**

- `SPEC-snap-hq-site-config-ssot-v0_1.md`
- `SPEC-shared-upload-dir-and-prompt-across-tools.md`

This draft does not authorize implementation until Sean and Claude accept or amend the proposed decisions explicitly identified below.

---

## One line

Configure a blog's portable workflow settings once through the online multisite hub, configure each computer's local handoff directory once through SNAP HQ, and let every desktop tool consume one visibly synchronized per-site profile.

## Problem

Prompt, image-size, export, and handoff settings currently risk being repeated across the CMS and desktop applications. Repetition creates drift, extra clicks, mismatched sites, unexpected re-resizing, and uncertainty about which copy is correct.

The solution is not to pretend the online service and a local desktop program are literally one authority. They coordinate, but they own different categories of data:

- the **online multisite hub** owns portable per-blog settings;
- **SNAP HQ on each computer** owns machine-local paths and local workflow state;
- `snap_profiles` is a visible offline mirror consumed by desktop tools, never an independent authority.

## Names

- **SNAP HQ:** the desktop launcher and configuration application, formerly called THE HUB.
- **Online multisite hub:** the CMS hub in the existing hub/spoke network.
- **Spoke:** an individual SnapSmack blog managed through the online multisite hub.
- **Shared profile:** the combined portable and machine-local view exposed to desktop tools.

Do not call SNAP HQ and the online multisite hub “two faces of the same authority.” They participate in one configuration system but have distinct ownership boundaries.

`ASH NAZG` may be used as an inscription or boot/about line only. It is never a functional product name, config key, directory name, or API name.

---

## Authority boundaries

### Online multisite hub owns portable per-blog settings

- `prompt`
- `max_width_landscape`
- `max_height_portrait`
- `jpeg_quality`
- `image_resize_enabled`
- `export_sharpen`

These values follow the blog across computers. The online multisite hub is their canonical source.

### SNAP HQ owns machine-local settings

- `upload_dir`
- local directory validation state
- last successful local export/import details
- last synchronization timestamp and local cache metadata

`upload_dir` is never pushed to a blog or synchronized as a portable website setting. A Windows path copied to another computer is not meaningful authority.

### Desktop tools consume; they do not own

SNAP SLAPPER, SYBU, and SUYB read the combined shared profile through the common shared-profile layer. They must not maintain authoritative private copies of these fields.

---

## Per-site fields and proposed defaults

| Field | Owner | Proposed default | Consumers |
|---|---|---:|---|
| `prompt` | Online hub | unset | CMS, SYBU |
| `upload_dir` | SNAP HQ per computer | unset | SNAP SLAPPER, SYBU, SUYB |
| `max_width_landscape` | Online hub | 3840 px | SNAP SLAPPER, CMS |
| `max_height_portrait` | Online hub | 2160 px | SNAP SLAPPER, CMS |
| `jpeg_quality` | Online hub | 85 | SNAP SLAPPER, CMS |
| `image_resize_enabled` | Online hub | on | SNAP SLAPPER, CMS |
| `export_sharpen` | Online hub | `auto` | SNAP SLAPPER |

The earlier single `export pixel-length` or “long edge” model is retired. A 4K display frame requires separate orientation caps.

## Image-processing contract

- Landscape images are capped at `max_width_landscape`.
- Portrait images are capped at `max_height_portrait`.
- Resizing is downscale-only. Neither SNAP SLAPPER nor the CMS ever upscales a smaller source.
- SNAP SLAPPER and the CMS honor the same caps so the CMS does not shrink an already-correct export a second time.
- Export order is: resize → output sharpen → encode/write.
- EXIF is preserved by default through resize, sharpening, and encoding.
- `export_sharpen = auto` derives a mild output sharpen from the actual reduction ratio.
- Advanced choices may expose `off`, `low`, and `medium`; do not expose raw implementation parameters in the ordinary workflow.

---

## Synchronization model

### Local mirror

Portable settings are cached in the existing shared profile store under `C:\snapsmack\shared_library\profiles\<site>.json`. The cache is a mirror for offline operation, not a second authority.

Machine-local settings must be stored in a clearly separate machine-local section or file. They must not be mistaken for portable data during backup, synchronization, or restoration.

No API keys or other secrets are stored in profile `extras` JSON.

### Refresh triggers

SNAP HQ refreshes portable settings:

1. when SNAP HQ starts;
2. whenever the user selects a site;
3. when the user presses **SYNC NOW**.

Every consuming desktop application refreshes the selected site through the shared synchronization layer rather than implementing its own network client.

### Offline behaviour

If the online hub cannot be reached:

- use the last-known portable values;
- show **OFFLINE COPY — synced [date/time]** wherever those values affect an action;
- never silently present cached values as current;
- preserve all unsynchronized local edits until connectivity returns.

### Ordinary changes and conflicts

Routine changes synchronize without repeated confirmation prompts. Constant “adopt this value?” prompts across a fleet would make the system unusable.

Each portable field keeps a last-synchronized baseline and revision metadata. If only one side changed since that baseline, the changed value synchronizes normally. If both the online value and an allowed local edit changed the same field, show:

- the online value;
- the local value;
- their modification times and sources;
- an explicit choice of which value to retain.

Never silently use newest-edit-wins. Clock skew and accidental edits make timestamps evidence, not authority.

Long term, portable settings should ordinarily be edited at their owner—the online multisite hub. SNAP HQ may provide a convenient editing surface only if it writes through to that authority and reports whether the write succeeded.

---

## Export-to-upload handoff

### Directory layout

The user chooses one `upload_dir`. SNAP HQ creates an `uploaded` subdirectory inside it:

```text
upload_dir\
  pending-file.jpg
  uploaded\
    confirmed-file.jpg
```

Keeping the completed directory inside the configured handoff tree makes backup, portability, and atomic same-filesystem moves simpler than maintaining an unrelated sibling path.

### SNAP SLAPPER

1. User selects a blog.
2. SNAP SLAPPER resolves that blog's shared profile.
3. **EXPORT FOR UPLOAD** applies the shared image contract.
4. It writes to a temporary file such as `filename.jpg.part` in `upload_dir`.
5. After the write and metadata preservation complete, it atomically renames the file to its final name.

SYBU therefore sees complete files only. The same-filesystem requirement must be validated or clearly warned about for network/container mounts.

### SYBU

1. User selects the same blog.
2. SYBU opens that site's `upload_dir` and loads its prompt automatically.
3. SYBU uploads a completed file.
4. Only after the CMS confirms the completed post does SYBU move the file into `uploaded\`.
5. Failed, rejected, interrupted, or uncertain uploads remain pending.

No batch tool deletes an original as part of this workflow.

### SUYB

SUYB backs up:

- pending files in `upload_dir`;
- confirmed files in `uploaded\`;
- the portable cached profiles;
- the machine-local SNAP HQ configuration needed to reconstruct the workflow.

On a different computer, SUYB may restore the files and configuration data, but SNAP HQ must ask the user to confirm or remap restored local paths before using them.

---

## UI requirements

SNAP HQ provides one site editor showing:

- site identity and display name;
- portable fields and their online synchronization status;
- machine-local `upload_dir` and whether it currently exists;
- last successful synchronization time;
- **SYNC NOW**;
- a clear conflict panel when both sides changed;
- an offline-copy warning when applicable.

Selecting one site drives prompt, upload directory, sizing, quality, resizing, and sharpening together. Applications must not resolve these fields through unrelated mappings that merely happen to use the same site name.

---

## Rename and compatibility

The functional desktop name is SNAP HQ. Active titles, descriptions, shortcuts, executable metadata, documentation, and new configuration names use SNAP HQ.

Existing `hub.exe`, `C:\snapsmack\hub`, or historical config identifiers may remain temporarily as compatibility paths. A compatibility path must launch the current SNAP HQ build and must not create a second configuration authority. Physical directory migration should occur only through a coordinated migration with rollback; cosmetic renaming alone does not justify breaking installed paths.

---

## Security and safety guardrails

- Local filesystem paths never travel to a website as portable settings.
- Profile JSON contains no secrets.
- All network writes authenticate through existing scoped mechanisms.
- Synchronization validates field types, ranges, and site identity.
- File handoff uses temp-write plus atomic rename where the filesystem supports it.
- Completed files move only after explicit remote confirmation.
- No delete-first operation appears in export, upload, synchronization, or restoration.
- Stale cached values are visibly identified.
- Conflict resolution never silently discards either changed value.

---

## Proposed decisions requiring Sean + Claude sign-off

1. Accept the split authority model: online hub owns portable settings; SNAP HQ owns machine-local settings.
2. Accept automatic refresh at launch and site selection, plus **SYNC NOW** and a visible offline cache timestamp.
3. Accept automatic ordinary synchronization with show-both-and-pick only for genuine two-sided conflicts.
4. Accept 3840 landscape width and 2160 portrait height as separate downscale-only defaults.
5. Accept `jpeg_quality = 85`.
6. Accept `export_sharpen = auto`, with optional Off/Low/Medium advanced choices.
7. Accept `uploaded\` as a child of `upload_dir`.
8. Accept preservation of EXIF by default.
9. Accept compatibility paths during the SNAP HQ rename instead of an immediate destructive directory migration.

---

## Implementation sequence after sign-off

1. Define and test the combined profile schema, revisions, baselines, validation, and machine-local separation.
2. Add the online multisite API read/write contract for portable fields.
3. Add one shared desktop synchronization client used by SNAP HQ and all consumers.
4. Build the SNAP HQ per-site editor, conflict UI, offline state, and directory validation.
5. Wire SNAP SLAPPER export with atomic writes, shared sizing, EXIF preservation, and auto sharpening.
6. Wire SYBU prompt/directory loading and confirmation-gated move to `uploaded\`.
7. Wire the CMS upload-resize path to the same caps and prevent upscaling.
8. Extend SUYB backup/restore with local-path confirmation on a different computer.
9. Correct help documentation that currently points Image Processing to the wrong CMS section.
10. Run cross-tool integration tests before calling the shared-settings feature complete.

## Acceptance tests

- Changing a portable cap at the online hub reaches SNAP HQ and both processing paths after sync.
- A cached value is visibly marked stale/offline when the hub is unreachable.
- A one-sided change synchronizes without a nuisance confirmation.
- A true two-sided conflict preserves both values and requires a choice.
- A local Windows path never appears in a request to the CMS.
- Selecting one blog resolves all of its fields from one profile identity.
- A 5000 px landscape export becomes at most 3840 px wide.
- A 4000 px portrait export becomes at most 2160 px high.
- A smaller source is never enlarged.
- EXIF survives export.
- SYBU never sees a `.part` file as uploadable.
- SYBU moves a file only after confirmed post completion.
- Failed or uncertain uploads remain pending.
- Restoring on another computer requires local-path confirmation.
- Existing legacy Hub launch paths open the current SNAP HQ configuration rather than a separate store.


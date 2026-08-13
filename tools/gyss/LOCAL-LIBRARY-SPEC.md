<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment with five equals, space, 'SNAPSMACK EOF', space,
  five equals. Missing/different = truncated. Restore before saving.
-->

# GYSS — Offline Sorter (local library of posts + thumbs, per blog)

**GYSS IS AN OFFLINE SORTER. That is the founding intent.** You sort a blog's
archive locally, offline; the network only exists at the edges as *sync*. The
per-blog local library (records + downloaded thumb files) is not a cache bolted
onto an online app — it **is** the app's working data. Everything renders and
reorders from disk.

**Status: BUILT — shipped in GYSS 0.1.3-alpha (the offline-library rebuild landed
in core 0.7.518D).** The online-first architecture described below as "the problem"
is exactly what this rebuild REPLACED: GYSS now keeps a persistent per-blog local
library on disk and syncs it in two bounded steps (PULL on connect, PUSH on
reconnect). The design sections below are the spec it was built to — kept as
rationale and history, not a to-do. If you are here to "build the offline library,"
it already exists; read `src/scripts/library.js` + `paths.js` and `src-tauri/src/lib.rs`.

**PATHS HAVE MOVED.** The `%APPDATA%\GetYourShitSorted\…` locations named further
down are superseded. The shipped build uses the shared SnapSmack root (default
`C:\snapsmack`, env `SNAPSMACK_HOME`): profiles + sessions under `config_files\gyss\`,
and the per-blog library (index/meta/thumbs + `db\catalog.sqlite`) under
`shared_library\<site_key>\`, shared with COLD SNAP and SYBU.

## Correct architecture (local-first)

- **Local library is the source of truth.** Sort/reorder/edit operate entirely
  on the on-disk per-blog library — fully offline, no network.
- **Online is two bounded sync steps only:**
  - **PULL** on connect — download records changed since `last_synced_at` +
    any missing/updated thumb files.
  - **PUSH** on reconnect — send the queued edit/reorder diff via
    `gyss/batch-update` (with `expected_modified_at` conflict detection).
- Between syncs GYSS is a normal desktop app over local files. It never needs the
  blog to be up to be usable.

## The problem it fixes

Today GYSS stores only:
- `%APPDATA%\GetYourShitSorted\profiles\<hostname>.json` — connection profile per blog
- `%APPDATA%\GetYourShitSorted\sessions\<session_id>.json` — an **ephemeral** working set

Every thumbnail renders from a **live remote URL** (`<img src="${p.thumb_url}">`
— `src/scripts/main.js` lines 339, 565, 681, 880, 989). Nothing downloads the
thumb bytes. The Rust `write_file` command (`src-tauri/src/lib.rs`) is
**UTF-8-string-only** — the app currently *cannot* persist an image at all.

Consequences: no offline use; breaks when the blog is slow/down; re-streams the
whole archive every session; unusable at scale. For a sorting tool over a large
per-blog archive, this is not a gap — it's the missing substrate GYSS sorts against.

## The design

A **persistent, per-blog local library** on disk that GYSS reads from, kept in
sync incrementally with the blog:

```
%APPDATA%\GetYourShitSorted\library\<hostname>\
    index.json          # post/photo records (or SQLite — see open question)
    meta.json           # last_synced_at, counts, site_mode
    thumbs\<image_id>.jpg   # downloaded thumbnail files (named a_<original>)
```

- **Records** synced incrementally via the existing `gyss-api` — it already
  exposes `modified_at` per record and conflict detection, so "pull what changed
  since last_synced_at" is already supported server-side.
- **Thumbs** downloaded to `thumbs\` on first sight; re-downloaded only when the
  record's `modified_at` moved. Prune thumbs whose records were deleted.
- **Render from local files** (Tauri `convertFileSrc` / asset protocol); fall
  back to the remote `thumb_url` only on a cache miss.
- **Sessions become views over the library**, not the source of truth.

## Seeding the library (reuse the existing export machinery)

Do NOT paginate `gyss/photos` to build the library from cold. The CMS already
serves DB copies via `suyb-export.php` (`core/export-engine.php`) — reuse it:

- **SEED = a GYSS-scoped SQL export** — one request returns the whole archive's
  records; being SQL, it loads almost directly into the local SQLite library.
- **HARD CONSTRAINT: never `type=full`.** That dumps every `snap_*` table,
  including `snap_users` (password hashes), `snap_ohsnap_keys` (ALL API keys),
  and secret-bearing settings — exactly what the GYSS trust boundary (SECAUDIT
  039) forbids. Add a **table-allowlist export** scoped to image/post/category/
  album tables only. No users, no keys, no secrets.
- **Thumbs are not in the DB.** The export gives records only; thumb *files*
  still download separately (see piece 1).
- **`since=<datetime>` param unifies seed and delta** on ONE endpoint. Big record
  tables filter cleanly: `snap_images.modified_at` and `snap_posts.updated_at`
  both auto-bump `ON UPDATE`, so `WHERE modified_at >= :since` yields exactly the
  changed rows. Seed = no `since` (or epoch); delta = `since=last_synced_at`. No
  full dump is ever required.
- **Deletions need help — a date filter can't express them.** `snap_images` hard-
  deletes (`img_status` is only `published|draft`, no soft-delete), so a removed
  image is just absent from a since-export, indistinguishable from unchanged. Fix:
  the endpoint also returns the **full current image-ID list** (bare integers,
  tiny even for huge archives); GYSS prunes any local row whose ID is not in it.
- **Small tables sent whole every sync.** Categories, albums, and image↔cat/album
  maps mostly carry only `created_at`, so date-filtering would miss edits (a
  rename, a re-tag). They're tiny — send them in full, always correct.

```
SEED:  export (no since)            -> local SQLite ; download thumbs -> thumbs/
DELTA: export?since=last_synced_at  -> upsert changed images/posts
       + whole small tables (cats/albums/maps)
       + current image-ID list      -> prune locally-orphaned rows + their thumbs
       + download any missing/updated thumbs
PUSH:  gyss/batch-update diff on reconnect
```

## Schema markers — do we need to change the schema? (audit)

**No schema change is required to ship.** Marker audit:

- **Change detection — already present.** `snap_images.modified_at` +
  `snap_posts.updated_at` both auto-bump `ON UPDATE`. That is the `since` marker.
- **Re-tag / re-categorize — caught, but only because maps are sent whole.**
  Moving an image between categories mutates the MAP table, NOT `snap_images`, so
  `modified_at` does not bump. Sending the small map tables whole every sync
  catches it. NEVER switch maps to date-filtering or re-tags silently vanish.
- **Deletes — the one gap.** `snap_images` hard-deletes (no soft-delete column),
  so a removed row is absent from a `since` export, indistinguishable from
  unchanged. Two closes:
  - **A. ID-reconcile (CHOSEN default):** endpoint returns the full current
    image-ID list; client prunes orphans. No schema change. ~0.5 MB at 100k ids.
  - **B. Soft-delete marker (`deleted_at` on images+posts):** deletes ride the
    normal `since` delta and become recoverable — but requires rewiring EVERY
    delete path + filtering deleted from ALL queries + a purge job. Defer unless
    per-sync ID lists get heavy at scale. Broad/risky; not a casual add.
- If a marker is ever added, use the defensive `ALTER … ADD COLUMN IF NOT EXISTS`
  pattern (constants.php is a protected path and won't deliver it to installs).

## Build pieces

1. **Rust — add a binary-safe write.** `write_file` is UTF-8-only. Add
   `write_bytes(path, Vec<u8>)` (or a `download_to(path, url)` command that
   fetches + writes) with the **same path-jail hardening** `write_file` already
   has (it was fixed from an arbitrary-file-write primitive — do not regress that).
2. **JS — `library.js`.** Load/save the per-blog index; incremental sync against
   `gyss-api` using `modified_at`/`last_synced_at`; a thumb resolver returning a
   local file path or the remote URL on miss.
3. **Wire the render sites** in `main.js` (339, 565, 681, 880, 989) to the resolver.
4. **Sync UI** — first-sync progress (bulk thumb download), subsequent incremental.

## Open questions to confirm with Sean (decide, don't re-ask blindly)

- Index store: flat `index.json` vs **SQLite** (better at scale / partial updates).
- Retention/prune policy (keep everything vs cap per blog).
- Thumb size(s) to cache — just the grid thumb, or also the edit-panel size.

<!-- ===== SNAPSMACK EOF ===== -->

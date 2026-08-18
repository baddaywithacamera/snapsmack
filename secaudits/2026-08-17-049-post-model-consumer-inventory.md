<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical SNAPSMACK EOF HTML comment.
-->

# Post-Model Consumer Inventory — the complete map (Audit 049 §5.2 deliverable)

**Date:** 2026-08-17 (night) · **Target:** `dev` / v0.7.533 · **Method:** mechanical `git grep`
across the tracked tree by five parallel readers, each covering one consumer domain.
**Status:** review-state evidence for the architectural-assessment gate. NOT a sign-off.
**Authority doc:** `_continuity/2026-08-17-post-model-postmortem-and-assessment-gate.md`.

> **Honesty caveat (VERIFY-LIVE-FIRST):** this is a static read of `dev` source, not a
> live-database row audit. *Which* columns actually hold image-vs-post ids on any given
> install still needs the restored-DB pass (gate §5.3). This maps the CODE; it does not
> yet count the ROWS.

---

## Plain-English summary (read this first)

Nothing here is on fire. The live sites work today. This is the map of a **design flaw**,
not an outage.

The flaw in one sentence: **SnapSmack can't agree on what a "post" is.** On photoblogs,
a published photo is stored as a bare image row — but the newer, correct model says every
published thing should be a real `snap_posts` row. So some of the code treats a photo as
"an image," and some treats it as "a post." That split is copy-pasted in a lot of places.

The good news the map gives us: it's **finite and it clusters.** It looks like ~60+ files,
but they collapse into **five root fixes**, and two of them (the listing query and the
federation id) are single points that fix whole families at once. The monster is measured.

### The five root fixes (this is the whole job)

1. **One shared "list published photos" query.** Right now the filter
   `img_status='published' AND img_date<=NOW()` is hand-copied into ~36 files with **no
   shared helper**. Make one post-aware helper, convert `index.php` + `archive.php`
   (which feed most skins), and the bulk of the public side falls in behind them.
2. **The fediverse identity (`core/smackverse.php`).** One file is the root of the
   `/ap/note/i/{image}` vs `/ap/note/p/{post}` split. Fix its id-building and the outbox,
   and federation converges. (Old `i/` links will 404 — that loss is already an accepted
   decision for alpha.)
3. **The engagement columns.** Likes, reactions, and community comments store an **image
   id in a column named `post_id`**, and collections write `item_type='post'` while
   pointing at an image. Needs a careful data remap + database rules so it can't recur.
4. **The database has no guardrails.** There are almost no foreign keys and nothing stops
   the two "status" fields from disagreeing. The post-model migration **does not exist yet**,
   and the updater **can't prove per-site that a migration ran** (gate §5.5 is unbuilt).
5. **The writers + the scheduler.** The publish paths that still create the old shape must
   switch to post+image+pivot (transactionally), and SHOTS FIRED + the offline tools must
   be **paused per site during migration** — because "scheduling" is really just a future
   date on the image row, so a scheduled photo firing mid-migration would split from its post.

Everything below is the evidence behind those five.

---

## Domain 1 — Public read surfaces (~38 files with query logic)

**Wrong (image-as-unit; would OMIT post-backed solo entries under the canonical model):**

- **Core (8):** `index.php` (L365/368/457/460), `archive.php` (L331-332/461),
  `load-more.php` (L41-43), `gallery-wall.php` (L99/105-107), `rss.php` (L47),
  `sitemap.php` (photoblog branch, L105), `eatmeclaude.php` (L59-61/97-98),
  `core/search-engine.php` (L113-141 — longform posts are currently **unsearchable**).
- **Skins (18 photoblog-family):** `galleria/{landing,layout}`, `chaplin/landing`,
  `glide/landing`, `hip-to-be-square/{landing,layout}`, `show-n-tell/landing`,
  `slickr/{landing,skin-header}`, `scroll/{wall,nav-filter}`, `sliders/skin-profile`,
  `photogram/{feed,search}`, `stanley/preload`, `tilez/preload`,
  `writing-with-impact/preload`, `alfred/preload`.
- **Skins (10 `hashtag.php`):** `aurora, heuristic, instant-camera, jive-turkey, parade,
  sudden-impact, the-grid, sliders, true-grit, photogram` — image-base query + dedup off
  the direct `post_id` column (not the pivot).
- **Hybrid (1):** `photogram/landing.php` — main grid is post-based (safe) but its
  community/count blocks read `img_status`.

**Already resilient (no change needed — the pattern to copy):**
`core/public-profile.php` (L75-92) uses a `snap_posts` + `snap_images … post_id IS NULL`
**UNION** — a post-backed solo migrates from the image branch to the post branch and still
shows, no double-count. ~25 gram-family skin files already filter on `snap_posts.status`.

**Fix-once leverage:** there is **no shared listing helper** — that copy-paste is the
central maintainability defect. `index.php` drives every passthrough single-entry skin;
`archive.php`'s `SELECT DISTINCT i.* FROM snap_images` drives all 11 passthrough
`archive-layout.php` skins. Fix those two queries → fix both whole families.

## Domain 2 — Federation + APIs (8 in-install files)

**Wrong (key the object id off storage shape — `snap_images.post_id IS NULL` → `/ap/note/i/`):**

1. **`core/smackverse.php`** — the AP engine, **the root cause.** `sv_image_row` (L3650,
   `post_id IS NULL`), `sv_note_for_image` (L3694), `sv_outbox_doc` UNION (L4577/4622/4661-74),
   `sv_federate_image_change` (L4407), `sv_content_note_id_for_image` (L4000-4002),
   `sv_resolve_target` (L1303/1320-24).
2. `smackverse.php` — router `i`→`id` map (L77) + `/ap/note/i` route (L218-219).
3. `core/public-post.php` — image-kind branch + its `rel=alternate` id (L33-41/83).
4. `core/public-profile.php` — tile links `/ap/note/i/N` (L275-277).
5. `pixelfed-api.php` — photoblog `px_status` (L88-90) + `px_timeline` (L108, no
   `post_id IS NULL` guard). *Also a pre-existing bug: `uri` missing a slash (`ap/note/i5`).*

**Blast radius — endpoints whose object identity changes (image-keyed → post-keyed):**
`GET /ap/note/i/{image}` (JSON + HTML), `GET /ap/outbox`, legacy `?ap=note&id=N`, profile
tile URLs, the Mastodon/Pixelfed status `uri`+`id`, and federated-comment threading targets.
**Double-emit trap:** the outbox image branch keys on `snap_images.post_id IS NULL`, *not*
on pivot absence — so any migration/writer that inserts a pivot but leaves `post_id` NULL
makes one photo emit **twice** (`i/` and `p/`). **Preserved** (rides `img_slug`, per decision
3.3): public permalinks, Note `url`, slug-based Like resolution, indieweb `u-url`.

## Domain 3 — Engagement + moderation + collections (20 files)

**Ambiguous-reference map — where an IMAGE id masquerades as a POST id (the migration must remap):**

1. **`snap_likes.post_id`** — written image-id by `process-like.php` (L113/116); read as
   image by community-component/dock; read as a **post** id by `core/cover-assign.php` (L53)
   → conflicting readers, a live latent bug. Propagated by `migrate-reactions-to-likes.sql`.
2. **`snap_reactions.post_id`** — written by `process-reaction.php` (L142/150).
3. **`snap_community_comments.post_id`** — written by `process-community-comment.php`
   (L251/256); **joined to `snap_images`** by `community-component.php` (L185) and
   `smack-comments.php` (L257) — confirming it holds an image id.
4. **`snap_collection_items.item_id` where `item_type='post'`** — written with image ids
   by `smack-post-solo.php` (L648) and `smack-edit.php` (L242); but read as a real post id
   (pivot join) by `collection.php` (L99) and `collections.php` (L97). **This reader/writer
   split already collides today** — solo photos silently drop from collection pages, or a
   real post sharing that integer shows instead. No migration needed to trigger it.
5. **`snap_collection_items.image_id`** with `item_type` left at the migration default
   `'post'` — written by `smack-lighttable.php` (L310/390).

**Dishonest discriminators to fix:** `smack-post-solo.php:648`, `smack-edit.php:242`,
`smack-lighttable.php:310/390`; enabled by `migrate-collection-items-polymorphic.sql:9`
(`DEFAULT 'post'`). **Not affected** (out of the integer-remap blast radius): boosts/shares
key on AP URL strings, not integers. **Honest models already in the code to copy:**
`snap_ap_likes.target_type enum('image','post')`, `snap_comments`'s split `img_id`/`post_id`,
`smack-collections.php`'s truthful `item_type='image'`.

## Domain 4 — Admin / stats / search / schema / installer / migration (14 files)

**Image-as-unit admin/stat consumers (would omit/double-count):** `smack-admin.php`
(dashboard counts, L180-193), `smack-manage.php` (main listing + the `snap_likes.post_id = i.id`
image-match at L291), `smack-gallery.php`, `smack-stats.php`, `smack-multisite-stats.php`,
`core/search-engine.php`. (`smack-multisite-posts.php` reads remote posts, safe.)

**Schema-level gaps (why the defect is even *legal*):** in `snapsmack_canonical.sql` /
`install.php` the **only** `snap_*` foreign keys are `fk_ci_collection` and `fk_bucket_post`
plus the three groups children. Missing: any FK on `snap_images.post_id`,
`snap_post_images.post_id/image_id`, or the three engagement `post_id` columns; and
**nothing** (no trigger, CHECK, or generated column — zero exist in the repo) prevents
`snap_images.img_status` and `snap_posts.status` disagreeing. A fresh install *starts* in
the defective state.

**Delivery / migration (gate §5.5):** `updater.php` records migrations as a **filename
ledger** (`snap_migrations`), idempotent per file — but recording happens only *after* a
whole file succeeds, so a mid-file failure **replays the entire file**; safe only for
idempotent DDL, **not** a partial data-backfill (no transaction wrapper). `_updater_ping_home`
is anonymized aggregate by design; `multisite/updates/status` returns only the core version
per spoke. **No per-named-site migration/invariant self-verification exists.** **No
post-model migration file exists yet** — when authored it must be transactional/resumable
and ship a hub-visible per-site verification report.

## Domain 5 — Writers + scheduling + tools (22 files)

**Write sites that create the LEGACY shape (the writers half of the fix):**

1. **`smack-post-solo.php:580`** — THE primary defect. Bare `snap_images` insert,
   non-transactional (partial category/collection state possible on failure).
2. **`core/image-ingest.php:474`** (`snap_ingest_image()`) — shared legacy creator; its
   publish-as-unit callers `smack-gallery.php:51/56` (the Gallery uploader),
   `smack-edit.php:138`, `smack-maintenance.php:470` all inherit the legacy shape.
   (`smackpress-api.php:268` uses it as a featured-image asset — acceptable.)
3. **`core/flkrfckr-api.php:513`** — FLKR-FCKR import.
4. **`core/multisite-api.php:1205`** — hub media-create.
5. **`pixelfed-api.php:212`** — draft media staging (lower priority; attach path is canonical).

**Canonical writers (already correct):** `smack-post-gram.php` (but **NOT transactional** —
a mid-run failure orphans `post_id`-NULL images that then look like legacy solos; wrap it),
`smack-slicer.php` (triptych, transactional), `core/threeacross-api.php` (carousel-locked,
transactional), `smack-edit-carousel.php`, `gram-client-authoring.php`, longform. **Note:**
gram/slicer/carousel-edit still stamp `img_status`/`img_date` on the image even when
post-backed — that's the split-authority half of the defect and needs the same reader-first
treatment.

**Quiesce per site during migration (no cron exists — "scheduling" is a future `img_date`
display-gate, which is exactly why a scheduled row firing mid-migration splits from its post):**

1. **`smack-schedule.php`** — SHOTS FIRED `set_date` (writes future `snap_images.img_date`).
2. `tools/coldsnap` (`sumna_offline.py:301/340`, `sumna_post.py:171`) — offline queue drain.
3. `tools/sybu/poster.py:280/423` — photoblog solo path.
4. `tools/flkr-fckr/poster.py` — batch import.
5. `pixelfed-api.php` — engage the existing `px_offline_gate()`.

**Desktop tools model written:** COLD SNAP solo→legacy / gram→canonical; SYBU photoblog→legacy
/ carousel→canonical; FLKR-FCKR→legacy; GYSS→no publish writes (reader + metadata + canonical
carousel-combine); Oh Snap!→viewer only; SIDEWAYS→not in this checkout; SUYB/backup/restore
(SMACK-VAX, SMACKBACK, recovery-engine)→**model-neutral, no legacy re-creation on restore.**

**Deletion paths to make post-aware:** `smack-manage.php:36` (hard-deletes a bare image by
id — must cascade to post+pivot once solo is post-backed), `core/pixelix-lifecycle.php:64`
(draft-staging cleanup, consistent).

---

## What "done" means from here (build order — gated, not tonight)

Ordered per the postmortem §6, with web-Claude's evening corrections folded in:

1. Confirm the two pending backup receipts (`pct listsnapshot 110` + `102`, eyeball the 8 files).
2. **This inventory + a live restored-DB row audit (§5.3)** → ratify the invariant set.
3. Build the **shared post-aware read helper**; convert `index.php` + `archive.php` + the
   wrong skins/feeds/federation to it (readers first).
4. Switch the writers (`smack-post-solo`, `image-ingest`, importers) to transactional
   post+image+pivot; quiesce the scheduler + offline tools per site.
5. Author the migration (doesn't exist yet): transactional, resumable, records its version,
   **per-site self-verification** reported to the hub (§5.5).
6. Dry-run on restored data (incl. `foreverphotograph.ing` ~10k images), remap ambiguous
   engagement/collection ids, add the missing FKs + a status-authority guard.
7. Ship readers+writers as one release → migrate per site with backups → verify → remove
   the temporary dual-read **only when the code proves zero legacy-shape consumers remain**
   (not on a date).

**Gate reminder:** convergence on this plan is not the gate clearing. The §5 assessment
still runs and **Codex + Sean still sign off** before any build resumes.

<!-- ===== SNAPSMACK EOF ===== -->

<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# SMACK YOUR MOUTH — Specification

**Offline fleet comment moderation + replies. The inbound twin of COLD SNAP.**
Build 0.1.0. Lives at `tools/smack-your-mouth/`. Launched by THE HUB or run
standalone.

---

## 1. Purpose

SnapSmack runs as a fleet: one **hub** install and many **spoke** blogs. The hub
already gathers pending comments from every spoke and lets the operator
approve/delete them from one page (`smack-multisite-comments.php`). That page is
**online-only** — it moderates in the browser, one action at a time, against a
live connection to each spoke, and it cannot **reply**.

SMACK YOUR MOUTH is the desktop counterpart. It is to *inbound* comments what
COLD SNAP is to *outbound* posts:

- COLD SNAP composes posts offline and **pushes** them out to the fleet.
- SMACK YOUR MOUTH **pulls** each site's comments in, lets the operator moderate
  and reply **offline**, and **syncs** the decisions + replies back later.

It reuses COLD SNAP's whole spine: the `_shared` path bootstrap, `snap_home`
config/logs, `snap_creds` shared secrets, `snap_profiles` shared per-site
connections, the dark tkinter theme, the resumable-session store, and — most
importantly — the store-and-forward `SyncEngine` with **positive verification**.

## 2. Connectivity rationale

The operator's connection comes in short bursts (~10 minutes — a coffee-shop
window, then offline again). Moderating a backlog one-at-a-time against a live
connection wastes that window and stalls the moment the link drops. SMACK YOUR
MOUTH decouples the *work* from the *connection*:

1. **Pull** (needs network, seconds per site): fetch each site's pending
   comments into a local, resumable session.
2. **Moderate** (no network, unbounded): approve / delete / mark-spam and write
   replies for as long as you like; everything is saved to disk as you go.
3. **Sync** (needs network, seconds): push the queued decisions + replies back,
   each **positively verified** against the server before it is marked done.

A dropped connection during pull or sync is safe: nothing is half-applied,
already-synced items stay done, and the rest are simply retried next burst.

## 3. Flow

```
FLEET (snap_profiles + snap_creds, from THE HUB → Discover Fleet)
   │  load_fleet()  → SiteEntry[]           (offline, instant)
   │  probe_fleet() → heartbeat per site    (optional live: reachable + pending count)
   ▼
PULL  ── per site: GET multisite/comments/pending ──► Session.ingest_pull()
   │        (dedup by site + remote comment id; one JSON file per comment)
   ▼
MODERATE (offline)
   │   set_action(approve|delete|spam)   → item.action
   │   set_reply(text)                   → item.reply_text
   │   every change saved atomically to items/<item_id>.json
   ▼
SYNC  ── SyncEngine.sync_all(), grouped by site, one connection each ──►
         for each item with work:
            1. post reply (if any)     → verify_reply()    (positive)
            2. apply decision (if any) → verify_decision() (positive)
            mark SYNCED only if every queued op is confirmed; else FAILED (retained for retry)
```

Export/import moves a whole session (with its decisions + sync state) between
machines as a self-contained, versioned folder with a `RECOVERY.txt`.

## 4. Data model

### 4.1 Local session (on disk)

```
mouth_sessions/<session_id>/
    session.json            # manifest: id, name, schema/build version, timestamps
    items/<item_id>.json    # one file == one pulled comment + its local work
```

Sessions live next to the exe (or the source dir in dev), matching COLD SNAP's
`sob_sessions` convention, and are deliberately kept in-app (not under a
shared-host web root) so nothing GCs them.

### 4.2 `CommentRecord` — the pulled snapshot (read-only)

Mirrors a spoke's `snap_comments` row as returned by
`multisite/comments/pending`:

| field | source |
|-------|--------|
| `comment_id` | `snap_comments.id` (remote, on the spoke) |
| `img_id`, `img_title`, `img_slug` | joined `snap_images` |
| `comment_author`, `comment_email`, `comment_text`, `comment_date`, `comment_ip` | `snap_comments` |
| `is_approved`, `is_spam` | for "recent"/read-back pulls (0 on a pending pull) |

### 4.3 `ModItem` — the working unit (one JSON file)

| field | meaning |
|-------|---------|
| `item_id` | local uuid (stable within a session) |
| `site_key`, `site_url`, `site_name`, `node_id` | which spoke this comment belongs to (`node_id` = the hub's `snap_multisite_nodes.id` for that spoke) |
| `comment` | the `CommentRecord` snapshot |
| `action` | moderation decision: `none` / `approve` / `delete` / `spam` |
| `reply_text`, `reply_author` | drafted admin reply (blank = none); author defaults to the hub display name |
| `status` | `pulled` → `ready` → `syncing` → `synced` / `failed` |
| `error` | last failure reason (retained for retry) |
| `remote_reply_id` | server id of the posted reply, once known |
| `decided_at`, `created_at`, `updated_at` | timestamps |
| `schema_version`, `build_version` | provenance — an old item is migrated, never replayed against the wrong shape |

**Invariants (enforced in `ModItem.validate()`):**
- a comment with no remote id or no site url cannot sync;
- an item with no decision **and** no reply is not "work" and is skipped;
- **a reply on a comment you are deleting is rejected** (the reply would be
  orphaned) — the operator must choose one.

Dedup identity across pulls is `site_key + '#' + comment_id`, so re-pulling the
same queue never duplicates a comment and never clobbers a local decision.

### 4.4 Decision / reply records

There is no separate table: an item's **decision** is `action` + `decided_at`,
and its **draft reply** is `reply_text` + `reply_author`. Both are applied at
sync time. This keeps a single atomic file per comment (the recovery unit)
rather than scattering related state across records.

## 5. Sync semantics (positive verification)

Adapted in spirit from COLD SNAP's `SyncEngine`. For each item with queued work:

1. **Reply first** (if drafted): `post_reply()` → then `verify_reply()` reads the
   comment back and confirms the reply landed. Posting before an `approve` keeps
   the thread intact.
2. **Decision** (if any): `apply_decision()` → then `verify_decision()` reads the
   comment back and confirms the concrete state (`is_approved == 1` for approve,
   `is_spam == 1` for spam, gone for delete).
3. Mark `synced` **only** if every queued op is positively confirmed; otherwise
   `failed` with the reason retained. Success is **never** inferred from the
   absence of an error.

Where a read-back endpoint is unavailable on an older spoke, verification
degrades in a defined order: (a) use the pending-list delta (approved/deleted/
spammed comments leave the pending queue); (b) only where **no** read is possible
at all does it trust the server's explicit `{ok:true}` rather than manufacture a
false failure.

---

## 6. Server API

Every endpoint the client calls, each marked **EXISTS** or **MUST-ADD**. The
client is written so MUST-ADD routes fail *gracefully* (a clear message, no
crash), so the tool lights up the moment the server side ships them. **No server
endpoints are implemented by this work** — the CMS is owned by another developer.

All routes are under `api.php?route=multisite/…`, handled by
`core/multisite-api.php`. Auth is `Authorization: Bearer <key>` where the key is
the hub→spoke `api_key_local` (the credential the hub already uses when it calls
a spoke from `smack-multisite-comments.php`).

### 6.1 EXISTS

| Route | Method | Purpose in this tool | Cite |
|-------|--------|----------------------|------|
| `multisite/heartbeat` | GET | Reachability probe + `pending_comments` count for the fleet panel. Chosen over `multisite/ping` because ping authenticates with the spoke's own `api_key_remote`, which the fleet does not hold. | `core/multisite-api.php` L269–297 |
| `multisite/comments/pending` | GET | **The pull.** Up to 100 unapproved comments with `id, img_id, comment_author, comment_email, comment_text, comment_date, comment_ip, img_title, img_slug`. | `core/multisite-api.php` L303–323 |
| `multisite/comments/action` | POST | Apply `approve` or `delete` by `comment_id`. Requires the calling node's `role = 'hub'`. Returns `{ok, comment_id, action}`. | `core/multisite-api.php` L329–351 |

### 6.2 MUST-ADD

Three operations the inbound twin needs have **no** route today. Each is
specified for the server developer; the client already calls them behind
fallbacks.

#### (A) `POST multisite/comments/reply` — write an admin reply *(the core write)*

The single most important gap: there is currently **no way to reply to a
comment** through the multisite API. Without it the tool can moderate but not
converse — and replying is the reason the tool exists beyond the web queue.

- **Method / auth:** `POST`, `Bearer api_key_local`, guard `node['role'] === 'hub'`
  (mirror `comments/action`).
- **Params (form-encoded):**
  - `comment_id` (int, required) — the parent `snap_comments.id`.
  - `reply_text` (string, required) — the reply body.
  - `author` (string, optional) — display name; default to the site/admin name.
- **Behaviour:** insert a reply comment tied to the same `img_id` as the parent,
  approved (`is_approved = 1`), with a parent link. Two clean options:
  - add a `parent_id` column to `snap_comments` (self-referential thread); or
  - a `snap_comment_replies` table (`id, comment_id, author, reply_text, created_at`).
- **Response:** `{ ok: true, reply_id: <int>, comment_id: <int>, parent_id: <int> }`.
- **Client behaviour if absent:** a `404` is reported as *"this spoke has no
  reply endpoint yet"* and the item is left `failed` for retry — never a silent
  success.

#### (B) `action=spam` on `multisite/comments/action` — mark spam

`comments/action` today whitelists only `['approve','delete']`
(`core/multisite-api.php` L334). SMACK YOUR MOUTH offers a distinct **spam** verb
(spam is not the same as delete — it should be flaggable and, ideally, feed the
Shield ban registry).

- **Change:** accept `action = 'spam'` in the whitelist; set the comment's spam
  state (e.g. `is_spam = 1`, and/or remove it from the pending queue).
  Optionally hook the existing `ban-sync` / Shield path so a spammer's
  fingerprint/IP/email hash can be shared to the fleet — an enhancement, not
  required by this client.
- **Response:** unchanged shape — `{ ok: true, comment_id, action: 'spam' }`.
- **Client behaviour if absent:** a `400` on a `spam` action is reported as
  *"spam is not accepted by this spoke yet"*; the item stays `failed`.

Alternatively this can be a new sub-action `multisite/comments/spam`; extending
the existing whitelist is the smaller change and the client accepts either.

#### (C) `GET multisite/comments/get` — read one comment back by id *(positive verification + "recent")*

`comments/pending` only lists **unapproved** comments; once a comment is approved
it vanishes from that list, and there is no way to read a **single** comment's
current state or its replies. That makes true positive verification of
`approve`/`reply`/`spam` impossible without a read-back.

- **Method / auth:** `GET`, `Bearer api_key_local`.
- **Params:** `comment_id` (int, required).
- **Response:** `{ ok: true, comment: { id, img_id, img_title, img_slug,
  comment_author, comment_email, comment_text, comment_date, comment_ip,
  is_approved, is_spam, replies: [ { id, author, reply_text, created_at }, … ] } }`.
  Return `404 {ok:false}` when the comment does not exist (a genuine delete).
- **Client behaviour if absent:** verification degrades to the pending-list delta
  (see §5); the tool still works, just with weaker confirmation for replies.

Optionally, a companion `GET multisite/comments/list?status=pending|approved|all
&since=<cursor>&limit=<n>` would let the tool pull **recent** (already-approved)
comments too, not only pending — useful for replying to older threads and for
incremental/resumable pulls. The client already probes `multisite/comments/list`
and silently falls back to `comments/pending` when it 404s.

### 6.3 Key-scope note (server authorization, not a client route)

`multisite/comments/action` authenticates the caller by matching `api_key_local`
to a node row with `role = 'hub'`. THE HUB's **Discover Fleet**, however, stores
a *posting-scoped* key in the shared profile (it mints a `sybu` key via
`multisite/provision-key`; see `tools/_shared/snap_discovery.py`
`_provision_spoke_key`), which will **not** satisfy the hub-role check.

To let the fleet-provisioned key moderate, the server side should do **one** of:

1. **Persist `api_key_local` to the shared profile** — have Discover write the
   hub→spoke key into the profile's `extras.api_key_local`. The client already
   prefers `extras['api_key_local']` when present (`tools/smack-your-mouth/fleet.py`).
2. **Provision a moderation-scoped key** — extend `multisite/provision-key` with a
   `key_type` such as `mouth`/`comments`, and have the comment endpoints accept a
   moderation scope (parallel to how posting keys authorize the posting routes).

This is a server/`_shared` decision, both out of scope for this client. Until it
is made, the tool authenticates with the best key it has and surfaces a `401` as
*"API key rejected (needs the hub→spoke key)."*

---

## 7. Out of scope (v1)

- Composing new posts — that is COLD SNAP.
- Community/forum threads beyond blog-post comments (later, if wanted).
- Real-time / always-connected moderation — the web queue already covers that.
- Any server-side change — the three MUST-ADD routes in §6.2 and the key-scope
  decision in §6.3 are **specified here for the CMS developer**, not built by this
  work.

## 8. File map (`tools/smack-your-mouth/`)

| File | Role |
|------|------|
| `main.py` | tkinter shell: CONNECTION/FLEET panel, comment queue with moderate + reply controls, SYNC button. `BUILD_VERSION = "0.1.0"`. |
| `moderation_offline.py` | GUI-free engine: `CommentRecord`, `ModItem`, `Session`/`SessionStore`, export/import, `SyncEngine` (positive verification). |
| `moderation_api.py` | HTTP transport (`MouthConnection`) + `MouthPoster` adapter for the SyncEngine. Marks EXISTS vs MUST-ADD inline. |
| `fleet.py` | Loads the fleet from `snap_profiles` + `snap_creds` (read-only), reachability probe. |
| `config.py` | Shared-home config (`config_files/smackmouth`), shared-secret overlay, reply-author + window prefs. |
| `ui.py` | Dark tk palette + widget helpers (from `sumna_ui`). |
| `build.bat`, `smackmouth.spec`, `bump_version.py`, `requirements.txt` | Build harness → `C:\snapsmack\smack-your-mouth\smackmouth.exe`. |
| `CHANGELOG.md` | Version history. |

<!-- ===== SNAPSMACK EOF ===== -->

<!--
SNAPSMACK_EOF_HEADER
    <!-- ===== SNAPSMACK EOF ===== -->
Last non-empty line of this file MUST match the marker named above.
Missing or different = truncated/corrupted. Restore before saving.
-->

# SHOTS FIRED — Specification

**Tool:** SHOTS FIRED — fleet-wide scheduled-post calendar (see + shuffle the queue)
**Kind:** SnapSmack desktop companion tool (Python / tkinter, ships inside an install only)
**Status:** client built (`tools/shots-fired/`, BUILD_VERSION 0.1.0); **two server routes are MUST-ADD and do not exist yet.**

---

## 1. Purpose

Every SnapSmack spoke can already **schedule** a post. Nothing new is needed on
the server for scheduling itself — it falls out of the feed query. The canonical
feed read (see `database/schema/snapsmack_canonical.sql`, `snap_images`, the
comment on `idx_images_status_date`) is:

```sql
WHERE img_status = 'published' AND img_date <= NOW()
ORDER BY sort_order ASC, id DESC
```

So a post row that is `img_status='published'` with an `img_date` **in the
future** is *already a scheduled post*: it exists, it is "published", but the
date-gate hides it until its `img_date` arrives, at which point the spoke reveals
it with no further action. SYBU and COLD SNAP already write a future `img_date`
when posting (`smack-post-solo.php` accepts an `img_date` form field; the gram
path accepts `post_date`). **Rescheduling a post is therefore nothing more than
changing its `img_date`.**

What is missing is not scheduling — it is a **centralized VIEW to rearrange the
queue**. Today the operator would have to open each site's admin, one at a time,
to see what is coming up, and there is no cross-site picture at all. SHOTS FIRED
is that picture: it pulls the future-dated posts from **every fleet site** into a
single agenda so the operator can **SEE the whole lineup and SHUFFLE it** — drag
(or click-to-move) a post to a new day, spread a bunched week out, bump one.

**Out of scope (explicitly):** SHOTS FIRED does **not** create posts, upload
images, edit captions, or change a site's mode. Creating posts is SYBU / COLD
SNAP's job. SHOTS FIRED only *surfaces* and *reschedules* what is already queued.
It reads the fleet read-only from the shared profile store and touches exactly one
field on the server — `img_date` — and only via the dedicated route below.

---

## 2. The fleet, read-only

The tool enumerates blogs from the **shared cross-tool profile store**
(`tools/_shared/snap_profiles`, one JSON file per site under
`shared_library/profiles/`). A blog set up in any tool (SYBU, GYSS, COLD SNAP)
appears automatically; SHOTS FIRED never writes a profile. Each profile yields
`(name, site_url, api_key)` — the api_key is the site's posting-scope key, already
stored locally and never uploaded, consistent with the least-privilege model the
rest of the suite uses.

A site with no stored api_key is still listed (as a status note) so the operator
sees the whole fleet and knows which blogs need a key before their queue can be
read or shuffled.

---

## 3. View & interaction model

**Agenda, not month grid (for now).** A fleet's queue is sparse and spread across
many sites, so a vertical, day-grouped timeline reads better than 30 tiny month
cells and scales to any look-ahead. The data model (a flat list of
`ScheduledPost`, each with an `img_date`) is grid-ready, so a month view can layer
on later without a data change.

- **Header bar:** tool title, a **LOOK AHEAD** selector (14 / 30 / 60 / 90 / 180 /
  365 days — how far forward to pull), and a **REFRESH** button.
- **Agenda body:** scrollable. Posts are grouped under a **day header**
  (`WEDNESDAY 20 AUGUST 2026 — in 6 days — 3 posts`). Each post is one row:
  a per-site colour swatch (left rail), the time, the title, the site name, and a
  large **MOVE…** button on the right.
- **Per-site status notes** at the top of the agenda name any site *not* fully
  represented below: "no scheduling API yet" (route not deployed), "no API key",
  "unreachable", or "no scheduled posts". The rest of the fleet still loads —
  one dark site never blanks the board.
- **Reschedule (MOVE…):** opens a small modal with the post's title, its current
  date, and two typed fields — **NEW DATE (YYYY-MM-DD)** and **NEW TIME (HH:MM,
  24h)** — pre-filled from the current `img_date`. **CANCEL** sits on the left; the
  large **MOVE POST** confirm sits apart on the right. This follows the family's
  forgiving-tool rule (Parkinson's): a single deliberate click on a big,
  well-separated target, no fragile two-step, no drag-only path. (Drag-to-move may
  be added later as a *shortcut*, never as the only way.)
- On confirm the client writes the new `img_date` (route (b) below) on a worker
  thread, then refreshes. Networking is always off the UI thread so the window
  never freezes against a slow spoke.

**Rescheduling semantics (why it is cheap):** because the feed already gates on
`img_date <= NOW()`, moving `img_date` *is* the whole operation — write a later
date and the post re-hides itself; write now/past and it publishes. No queue
table, no scheduler daemon, no post-state machine. The date-gate the CMS already
has does all the work.

---

## 4. Server API — **MUST-ADD**

The client is built and calls the two routes below exactly as specified. **Neither
exists on the server yet.** Both are the missing half of this tool and are flagged
MUST-ADD. Until a spoke deploys them the client 404s and that site shows
"no scheduling API yet" while the rest of the fleet still loads.

Both routes should live in **one new endpoint file, `smack-schedule.php`**, with an
`action` switch — deliberately parallel to the existing `smack-audit.php`
(`?action=list` / `?action=update_title`), which is the pattern to **copy, not
reinvent**. `smack-audit.php` already does 90% of this: it authenticates via
`core/api-auth.php`, its `list` action selects `id, img_title, img_date` from
`snap_images`, and its `update_title` action is a guarded single-column UPDATE
returning `404` on `rowCount()===0`. The two routes below are the same shapes with
a date filter added to the read and `img_date` as the written column.

### Auth (both routes)

Reuse `core/api-auth.php` (the same dual session/key guard every tool endpoint
uses). It validates the `X-Snap-Key` request header against the stored
`tool_api_key` setting with `hash_equals`, or falls through to an admin session.
On an invalid key it already returns `401 {"error": ...}`.

- The client sends the key in **both** `X-Snap-Key: <api_key>` *and*
  `Authorization: Bearer <api_key>` so whichever convention the route adopts is
  satisfied (the `smack-*.php` endpoints read `X-Snap-Key`; the `api.php?route=…`
  endpoints read `Bearer`). Modelling on `smack-audit.php`, **`X-Snap-Key` is the
  expected one.**
- The key must carry (or be gated to) a scheduling/scope permission — reading and
  moving the queue is a management action, not anonymous read. Reuse whatever
  scope check the posting key already passes.

### (a) LIST scheduled posts

> **What the client needs:** "give me this site's upcoming (future-dated) posts."

| | |
|---|---|
| **Method** | `GET` |
| **URL** | `{site_url}/smack-schedule.php?action=list` |
| **Auth** | `X-Snap-Key` header (see above) |
| **Query params** | `from` — ISO/`YYYY-MM-DD HH:MM:SS`, lower bound (default `NOW()`); `to` — same format, upper bound (optional; default open-ended). Client sends `from = now` and `to = now + <look-ahead> days`. Optional `limit` (int) the server may cap at, e.g. 500. |

**Server query (the whole route):**

```sql
SELECT id AS snap_id, img_title, img_date, img_status, img_file,
       img_thumb_square, post_id
FROM   snap_images
WHERE  img_status = 'published'
  AND  img_date > :from            -- future-dated == scheduled (the date-gate)
  AND  (:to IS NULL OR img_date <= :to)
ORDER  BY img_date ASC
LIMIT  :limit
```

**Response** — `200`, JSON:

```json
{
  "ok": true,
  "posts": [
    {
      "snap_id": 8123,
      "img_title": "Prairie storm front",
      "img_date": "2026-08-20 09:00:00",
      "img_status": "published",
      "img_file": "prairie-storm.jpg",
      "img_thumb_square": "t_prairie-storm.jpg",
      "post_id": null
    }
  ]
}
```

- The client is tolerant: it also accepts `id` for `snap_id`, `title` for
  `img_title`, and a `thumb_url` string; rows with an unparseable `img_date` or no
  id are skipped.
- `img_thumb_square` / `img_file` are optional niceties for a future thumbnail
  rail; the agenda works from `img_date` + `img_title` alone.
- Error contract: `401` on bad key (from `api-auth.php`); any other non-200 is
  treated by the client as "site error" and noted; **`404` means the route is not
  deployed** ("no scheduling API yet").

### (b) RESCHEDULE — set `img_date`

> **What the client needs:** "move post N to this new date/time."

| | |
|---|---|
| **Method** | `POST` |
| **URL** | `{site_url}/smack-schedule.php?action=set_date` |
| **Auth** | `X-Snap-Key` header (see above) |
| **Body params** (form-encoded) | `snap_id` — int, the `snap_images.id`; `img_date` — `YYYY-MM-DD HH:MM:SS` (the exact column format; what the client sends) |

**Server update (the whole route):**

```sql
UPDATE snap_images
SET    img_date = :img_date
WHERE  id = :snap_id
  AND  img_status = 'published'
```

- Reject a malformed `img_date` or missing `snap_id` with `400
  {"ok": false, "error": "..."}` (mirror `update_title`'s validation).
- If `rowCount() === 0` (no such published row), return `404
  {"ok": false, "error": "No published post found with id=…"}`. The client
  distinguishes a genuine missing-row 404 (has an `error` body) from a
  route-not-deployed 404 (no JSON body) and reports accordingly.
- On success return `200 {"ok": true}`.
- **No other column changes.** This route sets exactly one field. `modified_at`
  updates itself (`ON UPDATE CURRENT_TIMESTAMP`), which is correct — GYSS's
  conflict detection will see the change, as it should.

**Optional, nice-to-have (not required for v1):** a bulk `action=set_dates` taking
an array of `{snap_id, img_date}` so "spread this week out" is one round-trip
instead of N. The client can drive the single route N times for now.

### Why these are cheap to add

Both are a handful of lines on top of machinery that already ships: the auth guard
(`core/api-auth.php`), the read shape (`smack-audit.php?action=list`), and the
guarded single-column UPDATE (`smack-audit.php?action=update_title`). The
date-gate already turns "scheduling" into "a row with a future `img_date`", so the
server needs **no new table, no scheduler process, and no post-state model** — only
a filtered read and a one-column write.

---

## 5. Client architecture (`tools/shots-fired/`)

| File | Role |
|---|---|
| `main.py` | Host `App` (tkinter). BUILD_VERSION, debug-log setup, `_shared` bootstrap, fleet load + per-site pull on a worker thread, agenda render, reschedule handler. Entry point. |
| `fleet.py` | Loads the fleet from `snap_profiles` (read-only) as `Site(name, url, api_key)`. |
| `schedule_client.py` | HTTP transport for routes (a) and (b). `ApiStatus` (OK / NOT_DEPLOYED / UNAUTHORIZED / ERROR); `list_scheduled(site, lookahead_days)`; `reschedule(site, snap_id, new_dt)`. Degrades on 404. |
| `agenda.py` | `AgendaView` — the scrollable day-grouped board + the MOVE… reschedule dialog. |
| `ui.py` | Shared dark palette + widget helpers + clipboard bindings (mirrors the family theme). |
| `config.py` | `_shared` path bootstrap + this tool's small prefs (look-ahead, window geometry); shared-home aware, next-to-exe fallback. |
| `bump_version.py`, `build.bat`, `shots-fired.spec`, `requirements.txt`, `CHANGELOG.md` | Build tooling, cloned from COLD SNAP. Output: `C:\snapsmack\shots-fired\shots-fired.exe`. |

**Conventions honoured:** the exact `_add_shared_to_path()` bootstrap; shared
config/logs via `snap_home`; shared profiles via `snap_profiles`; the SnapSmack
`# ===== SNAPSMACK EOF =====` sentinel on every source file; the self-bundling
`.spec` (every local `.py` + every `_shared/*.py` forced in so nothing is silently
dropped from the exe). The tool ships **inside an install only**, never hosted on
the net.

---

## 6. Delivery note

Per the family rule, fixes/features reach installs **only through a release +
updater** (or the packager for skins) — never a hand-placed file. SHOTS FIRED's
client can be delivered now; it stays inert-but-honest ("no scheduling API yet")
against every spoke until the two MUST-ADD routes above ship server-side in a
core release. Once a spoke has `smack-schedule.php`, the same client lights up with
no client change.

<!-- ===== SNAPSMACK EOF ===== -->

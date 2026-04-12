# Unzucker — Pixelfed Target Mode
## Spec v0.1 — April 2026

---

## Overview

Unzucker already migrates Instagram exports to SnapSmack. This spec adds
**Pixelfed** as a second posting target. The IG parser, EXIF pipeline, 3-column
grid UI, and carousel handling are all reused. The new work is:

1. A `PixelfedClient` that speaks the Mastodon-compatible REST API
2. A stagger scheduler that drips posts across days at a user-set rate
3. A persistent queue file so the app can be closed between days
4. Config/UI additions for the Pixelfed target

The goal: give Instagram migrants a rate-limited, server-safe import path that
no existing tool provides. Most Pixelfed admins disabled the built-in importer
because it hammers the server in one shot. This solves that.

---

## Architecture

### Target toggle

A single `[target]` selector at the top of the config panel:

```
Target:  ( ) SnapSmack   (•) Pixelfed
```

Switching it shows/hides relevant config sections. No fork, no separate
executable — same app, two modes.

### New file: `poster_pixelfed.py`

Mirrors the interface of `poster.py` so `run_migration()` can call either
transparently. Key differences:

- **Auth**: Bearer token, not session cookie. No keepalive needed.
- **Image upload**: Direct multipart POST to `/api/v1/media` — no FTP step.
- **Post creation**: POST to `/api/v1/statuses` with `media_ids[]`.
- **No `smack-post-carousel.php`**: Pixelfed multi-image posts are just
  a status with up to N `media_ids`. N is `server_image_limit` from config.
- **Visibility**: Per-post field (`public` / `unlisted` / `followers_only` /
  `direct`). Defaults to `public`.

### New file: `scheduler.py`

Manages the drip queue. Reads/writes `unzucker-queue.json` next to the exe.

```json
{
  "target": "pixelfed",
  "instance": "https://pixelfed.social",
  "posts_per_day": 10,
  "start_date": "2026-04-14",
  "posts": [
    { "ig_index": 0, "scheduled_date": "2026-04-14", "status": "posted" },
    { "ig_index": 1, "scheduled_date": "2026-04-14", "status": "posted" },
    { "ig_index": 2, "scheduled_date": "2026-04-15", "status": "pending" }
  ]
}
```

`status` values: `pending` | `posted` | `failed` | `excluded`

The scheduler calculates the full schedule at load time (or when the user
hits "Build Schedule"). It does not require the app to stay running —
launch it each day to post that day's batch, then close it.

---

## Pixelfed API Calls

### Upload media

```
POST /api/v1/media
Authorization: Bearer {access_token}
Content-Type: multipart/form-data

file=<image bytes>
description=<alt text>   ← populated from post body/caption
```

Returns `{ "id": "12345", ... }`. Collect IDs for all images in a carousel
before creating the status.

### Create status

```
POST /api/v1/statuses
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "status":      "#caption #tags",
  "media_ids":   ["12345", "67890"],
  "visibility":  "public",
  "created_at":  "2022-03-15T14:32:00.000Z"   ← optional original IG date
}
```

`created_at` is a Pixelfed-specific extension to the Mastodon API. Not all
instances support it; `poster_pixelfed.py` should attempt it and fall back
gracefully if the instance returns 422.

### Get instance config

```
GET /api/v1/instance
```

Returns `configuration.statuses.max_media_attachments` — the server's actual
image limit per post. Use this to validate the user-entered `server_image_limit`
and warn if they've set it higher.

---

## Carousel Splitting

When an IG carousel has more images than `server_image_limit`:

- **Split mode** (default): post first N images as post 1, next N as post 2,
  etc. Caption and tags repeat on each split.
- **Truncate mode**: post only the first N images, log a warning.
- User selects mode in config. Split is the better default — no data loss.

Each split counts as a separate post toward the daily limit.

---

## Config Additions (`unzucker.ini`)

```ini
[target]
mode = pixelfed          ; snapsmack | pixelfed

[pixelfed]
instance_url   = https://pixelfed.social
access_token   = (base64-obfuscated, same scheme as passwords)
visibility     = public
server_image_limit = 4   ; hard limit per post on this instance
carousel_split = true    ; true = split | false = truncate

[schedule]
posts_per_day  = 10
start_date     = 2026-04-14
```

---

## UI Changes

### Config panel additions (Pixelfed section, hidden when target=SnapSmack)

```
PIXELFED INSTANCE
  Instance URL:   [ https://pixelfed.social          ]
  Access Token:   [ ••••••••••••••••••••             ]  [Test Connection]

POSTING SCHEDULE
  Posts per day:  [ 10 ]   Start date: [ 2026-04-14 ]
  Server image limit: [ 4 ]   Carousel over limit: (•) Split  ( ) Truncate
  Visibility:  (•) Public  ( ) Unlisted  ( ) Followers  ( ) Direct

  [Build Schedule]   → populates queue.json, shows summary:
                       "486 posts · 49 days · finishes 2026-06-02"
```

### Grid cell overlay additions

- Clock icon on cells scheduled for a future date
- "DAY N" overlay in the corner (day 1, day 2...) after schedule is built

### SnapSmack sections hidden in Pixelfed mode

- FTP SETTINGS box — entirely hidden (direct upload, no FTP)
- SITE CONNECTION box replaced by PIXELFED INSTANCE box
- Category / Album defaults — hidden (no equivalent on Pixelfed)

### Sections that remain unchanged

- IG export folder picker
- Date range filter
- Copyright text (written to EXIF regardless of target)
- Exclusion toggle (right-click on grid cell)
- Post detail view
- Gemini enrichment (still fires, still useful for caption cleanup)

---

## Build Schedule Logic

```python
def build_schedule(posts, posts_per_day, start_date):
    schedule = []
    day = 0
    count_today = 0
    for post in posts:
        if post.excluded:
            continue
        if count_today >= posts_per_day:
            day += 1
            count_today = 0
        post_date = start_date + timedelta(days=day)
        schedule.append({ 'ig_index': post.original_index,
                           'scheduled_date': str(post_date),
                           'status': 'pending' })
        count_today += 1
    return schedule
```

Posts are ordered chronologically (oldest first, ig_parser already sorts).
A split carousel sub-post inherits the same scheduled_date as its parent.

---

## Daily Run Behaviour

On launch:
1. Load `unzucker-queue.json` if it exists
2. Determine today's posts: `scheduled_date == today` and `status == pending`
3. Show them highlighted in the grid ("TODAY'S BATCH: N posts")
4. User clicks **Post Today's Batch**
5. App posts them in sequence, updates status in queue.json after each one
6. Summary panel shows: posted / failed / skipped, queue progress

No daemon, no scheduler process. Just run it once a day. Works on a laptop
that sleeps — if you miss a day, tomorrow it shows two days' worth and you
decide whether to post them all or split across two sessions manually.

---

## `PixelfedClient` Class Sketch

```python
class PixelfedClient:
    def __init__(self, instance_url: str, access_token: str): ...

    def verify_credentials(self) -> dict:
        """GET /api/v1/accounts/verify_credentials"""

    def get_instance_limits(self) -> dict:
        """GET /api/v1/instance — returns max_media_attachments etc."""

    def upload_media(self, image_path: str, alt_text: str = '') -> str:
        """POST /api/v1/media → returns media_id string"""

    def create_status(
        self,
        text:        str,
        media_ids:   List[str],
        visibility:  str = 'public',
        created_at:  Optional[str] = None,   # ISO 8601
    ) -> dict:
        """POST /api/v1/statuses → returns status object"""
```

---

## What Is NOT In This Spec

- **Mastodon support**: The API is identical. Adding Mastodon would be a
  config option (target = mastodon), not a code change. Out of scope for now.
- **Gemini auto-caption**: Unzucker already wires in Gemini. The Pixelfed
  path inherits this automatically.
- **Scheduling via `scheduled_at`**: The Mastodon API supports pre-scheduling
  posts. Pixelfed's support is inconsistent across instances. The queue-file
  approach is more reliable and doesn't require the instance to support it.
- **OAuth2 flow in-app**: Access token is paste-in only. Users generate it
  in their Pixelfed settings (Settings → Applications). No in-app OAuth dance.

---

## Implementation Order

1. `poster_pixelfed.py` — client + `run_migration()` equivalent
2. `scheduler.py` — queue file read/write + build_schedule()
3. `config.py` additions — new sections, load/save
4. `main.py` UI additions — target toggle, Pixelfed config box, schedule box
5. Grid overlay updates — clock icon, DAY N labels
6. `build.bat` updates — version bump, new spec file

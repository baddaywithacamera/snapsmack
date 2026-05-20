<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# SnapSmack Forum Server

The server-side hub for the SnapSmack admin community forum. Deploys to any PHP/MySQL host. Each SnapSmack install connects to a hub instance; by default that is `snapsmack.ca`, but forks can run their own.

---

## Requirements

- PHP 7.4+ with PDO and PDO_MySQL
- MySQL 8.0+ (required for `ADD COLUMN IF NOT EXISTS` in the v2 migration; fresh installs on 5.7 are fine)
- Apache with `mod_rewrite` and `AllowOverride All` (standard on cPanel shared hosting)

---

## Deployment

### 1. Create the database

Create a MySQL database and user, then run the schema:

```bash
mysql -u your_user -p your_database < forum-schema.sql
```

Or paste `forum-schema.sql` into phpMyAdmin's SQL tab. Safe to re-run — all `CREATE TABLE` statements use `IF NOT EXISTS` and category seeding uses `INSERT IGNORE`.

**Upgrading an existing install?** Run `forum-schema-v2-migration.sql` instead. It adds the new columns and tables without touching existing data. Requires MySQL 8.0+.

### 2. Upload the API files

Upload the contents of `api/forum/` to your web root so the endpoints resolve at:

```
https://yourdomain.com/api/forum/
```

The directory structure on the server should be:

```
public_html/
  api/
    forum/
      .htaccess
      config.php        ← you create this (see step 3)
      config.example.php
      index.php
```

### 3. Configure credentials

Copy `config.example.php` to `config.php` and fill in your values:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Generate with: python3 -c "import secrets; print('mod_' + secrets.token_hex(32))"
define('FORUM_MOD_KEY', 'mod_your_generated_key_here');
```

`config.php` is listed in `.gitignore` and must never be committed.

### 4. Verify mod_rewrite

The `.htaccess` rewrites all paths to `index.php?path=`. Test it:

```bash
curl -s -H "Authorization: Bearer fake" https://yourdomain.com/api/forum/categories
```

You should get `{"error":"INVALID_KEY",...}` — a JSON error, not a 404 or HTML page. A 404 means mod_rewrite is not working; check that `AllowOverride All` is set in your Apache virtual host config.

---

## Pointing SnapSmack installs at your hub

On each SnapSmack install, go to **Admin → Community Forum → Settings** and change the Forum API URL from the default (`https://snapsmack.ca/api/forum/`) to your own endpoint before connecting.

---

## API reference

All endpoints except `POST /register` require:

```
Authorization: Bearer {api_key}
Content-Type: application/json
```

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| POST | `/register` | None | Register an install; returns `api_key` and `is_moderator` |
| GET | `/categories` | Install key | List active boards with thread/reply counts |
| GET | `/threads?cat=N&page=N&tag=slug` | Install key | Thread listing — pinned first, then by activity. Includes excerpt, last-reply author, solved state, unread flag, tag list |
| GET | `/threads/{id}` | Install key | Full thread + replies + reactions + tags + read state. Increments view count. |
| POST | `/threads` | Install key | Create a thread (rate-limited: 3/hour) |
| PATCH | `/threads/{id}` | Own or mod | Edit body (own/mod) or toggle pin/lock flags (mod only) |
| DELETE | `/threads/{id}` | Own or mod | Soft-delete a thread |
| POST | `/threads/{id}/replies` | Install key | Add a reply (rate-limited: 10/hour). Triggers notifications. |
| PATCH | `/replies/{id}` | Own or mod | Edit reply body. Saves previous body to edit history. |
| DELETE | `/replies/{id}` | Own or mod | Soft-delete a reply. Unsolves thread if this was the accepted answer. |
| POST | `/replies/{id}/solve` | Thread author or mod | Mark reply as accepted answer |
| DELETE | `/replies/{id}/solve` | Thread author or mod | Unmark accepted answer |
| POST | `/threads/{id}/react` | Install key | Add emoji reaction (rate-limited: 30/10min). Same emoji toggles off; different emoji replaces. |
| DELETE | `/threads/{id}/react` | Install key | Remove own reaction from a thread |
| POST | `/replies/{id}/react` | Install key | Add emoji reaction to a reply |
| DELETE | `/replies/{id}/react` | Install key | Remove own reaction from a reply |
| POST | `/threads/{id}/read` | Install key | Mark thread as read; records current reply count for unread tracking |
| GET | `/search?q=term&cat=N&page=N` | Install key | Full-text search across thread titles/bodies and reply bodies |
| GET | `/tags` | Install key | List all tags with thread counts |
| POST | `/threads/{id}/tags` | Mod only | Add tag to thread. Provide `tag_id`, or `slug`+`name` to create a new tag. |
| DELETE | `/threads/{id}/tags/{tag_id}` | Mod only | Remove tag from thread |
| GET | `/notifications?page=N` | Install key | Get notifications (replies to your threads/watched threads). Includes unread count. |
| POST | `/notifications/read` | Install key | Mark notifications as read. Body: `{"ids":[1,2]}` or `{"all":true}` |
| PATCH | `/installs/me` | Install key | Update display name when blog name changes |

### Moderator actions

Installs with `is_moderator = 1` in `ss_forum_installs` can delete any thread or reply, edit any post, pin/lock threads, and manage tags. Promote installs to moderator from the Smack Central forum admin panel. The `FORUM_MOD_KEY` Bearer token also works for direct API access without being tied to an install record.

### Rate limits

| Action | Limit |
|--------|-------|
| Post a thread | 3 per hour |
| Post a reply | 10 per hour |
| Add a reaction | 30 per 10 minutes |

Rate limits apply per install. Old entries are pruned automatically on each check.

---

## Database tables

| Table | Purpose |
|-------|---------|
| `ss_forum_installs` | One row per registered SnapSmack install |
| `ss_forum_categories` | Forum boards (seeded with 5 defaults) |
| `ss_forum_threads` | Original posts — includes view count, solved state, excerpt, last-reply info, reaction count, tag cache |
| `ss_forum_replies` | Replies — includes edit state, reaction count |
| `ss_forum_reactions` | Emoji reactions (one per install per target; target_type = thread or reply) |
| `ss_forum_edit_history` | Body snapshots taken before each edit |
| `ss_forum_tags` | Tag definitions with denormalised thread count |
| `ss_forum_thread_tags` | Thread ↔ tag pivot |
| `ss_forum_read_state` | Per-install, per-thread read tracking (reply count at last read) |
| `ss_forum_rate_limit` | Sliding window action log for rate limiting |
| `ss_forum_notifications` | Reply notifications (polled, no push) |

See `forum-schema.sql` for full column definitions.

---

## Security notes

- All calls are server-to-server (install PHP → hub PHP). No browser JS touches this API directly.
- Body text is stored as plain text and HTML-escaped on the install side. No HTML is accepted or emitted by the API.
- `FORUM_MOD_KEY` is the only credential that bypasses ownership checks. Treat it like a root password.
- Banned installs (`is_banned = 1` in `ss_forum_installs`) have their API key rejected with HTTP 403 on every request.
<!-- ===== SNAPSMACK EOF ===== -->

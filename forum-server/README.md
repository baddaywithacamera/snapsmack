# SnapSmack Forum Server

The server-side hub for the SnapSmack admin community forum. Deploys to any PHP/MySQL host. Each SnapSmack install connects to a hub instance; by default that is `snapsmack.ca`, but forks can run their own.

---

## Requirements

- PHP 7.4+ with PDO and PDO_MySQL
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` and `AllowOverride All` (standard on cPanel shared hosting)

---

## Deployment

### 1. Create the database

Create a MySQL database and user, then run the schema:

```bash
mysql -u your_user -p your_database < forum-schema.sql
```

Or paste `forum-schema.sql` into phpMyAdmin's SQL tab. Safe to re-run — all `CREATE TABLE` statements use `IF NOT EXISTS` and category seeding uses `INSERT IGNORE`.

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
| POST | `/register` | None | Register an install; returns `api_key` |
| GET | `/categories` | Install key | List active boards with counts |
| GET | `/threads?cat=N&page=N` | Install key | List threads (pinned first, then by activity) |
| GET | `/threads/{id}` | Install key | Thread + all replies |
| POST | `/threads` | Install key | Create a thread |
| POST | `/threads/{id}/replies` | Install key | Add a reply |
| PATCH | `/installs/me` | Install key | Update display name |
| DELETE | `/threads/{id}` | Install key (own) or mod key | Soft-delete a thread |
| DELETE | `/replies/{id}` | Install key (own) or mod key | Soft-delete a reply |

### Moderator actions

Pass `FORUM_MOD_KEY` as the Bearer token to delete any thread or reply regardless of ownership. Keep this key private.

---

## Database tables

| Table | Purpose |
|-------|---------|
| `ss_forum_installs` | One row per registered SnapSmack install |
| `ss_forum_categories` | Forum boards (seeded with 5 defaults) |
| `ss_forum_threads` | Original posts |
| `ss_forum_replies` | Replies to threads |

See `forum-schema.sql` for full column definitions.

---

## Security notes

- All calls are server-to-server (install PHP → hub PHP). No browser JS touches this API directly.
- Body text is stored as plain text and HTML-escaped on the install side. No HTML is accepted or emitted by the API.
- `FORUM_MOD_KEY` is the only credential that bypasses ownership checks. Treat it like a root password.
- Banned installs (`is_banned = 1` in `ss_forum_installs`) have their API key rejected with HTTP 403 on every request.

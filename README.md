# SnapSmack

**Alpha v0.7.4 "La-Z-Boy"**

A self-hosted photoblog CMS for people who care about their photographs. One image a day, your domain, no algorithm.

---

## What it is

SnapSmack is a PHP/MySQL photoblog platform inspired by the classic photolog format of the early web. Upload a photo, write a caption, publish. Visitors browse your archive, tag pages, and (optionally) leave comments and reactions. You own the data, the design, and the URL.

Key characteristics:

- **One image per post** — single-image publishing with full EXIF display, optional download, and hashtag captions. Carousel posts available for skins that support them.
- **Swappable skins** — Galleria, Hip to be Square, True Grit, Photogram (Instagram-style), Impact Printer, 50 Shades of Noah Grey, A Grey Reckoning, New Horizon, and more. Each skin has its own CMS-driven appearance settings.
- **Self-update system** — Ed25519-signed release packages; apply updates through the admin without SSH access.
- **Community features** — comments, likes, and emoji reactions. Three identity modes: open (no account required), hybrid, or registered-only.
- **No tracking, no ads, no third-party dependencies at runtime.**

---

## Requirements

- PHP 8.0 or later
- MySQL 5.7 / MariaDB 10.3 or later
- A web host that allows `.htaccess` overrides (mod_rewrite)

---

## Installation

1. Upload all files to your web root (or a subdirectory).
2. Create a MySQL database and note the credentials.
3. Visit `https://yourdomain.com/setup.php` in your browser.
4. Follow the installer — it creates the database schema, seeds default settings, and writes `core/db.php`.
5. Delete or rename `setup.php` and `install.php` when done.

If you're upgrading from an earlier version, use the self-update system in the admin under **System → Update Manager** rather than overwriting files manually.

---

## Directory overview

```
/assets/          — Global CSS, JS engines, fonts
/core/            — Shared PHP: auth, meta, tags, updater, manifest
/skins/           — One directory per skin
/migrations/      — SQL migration files (applied automatically by updater)
/licenses/        — Font and library licensing
```

Each skin lives in `/skins/{skin-name}/` and declares its options, required scripts, and font dependencies in `manifest.php`. Skins never carry their own JavaScript or font files — those are checked out from the CMS via the manifest inventory.

---

## Development

File headers follow the convention in `CLAUDE.md` at the root. The architecture rules (no inline scripts, no inline styles for new CSS, manifest-driven skin resources) are documented there.

The self-update / release packaging system lives in the separate `smack-central/` application, deployed to snapsmack.ca.

---

## Changelog

See `CHANGELOG.md` for the full release history.

---

## License

SnapSmack is proprietary software. All rights reserved.
Fonts in `/assets/fonts/` are open-licensed; see `/licenses/` for individual terms.

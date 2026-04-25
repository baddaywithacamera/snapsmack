# SnapSmack

**Alpha v0.7.9L "Hot Seat"**

A self-hosted photography CMS for people who care about their photographs. Your domain, your archive, no algorithm.

---

## What it is

SnapSmack is a PHP/MySQL photoblog platform inspired by the classic photolog format of the early web. Upload a photo, write a caption, publish. Visitors browse your archive, tag pages, and (optionally) leave comments and reactions. You own the data, the design, and the URL.

Key characteristics:

SnapSmack installs in one of two editions chosen at setup time:

- **1.0 Photoblog** — one image per post, daily archive. The classic photoblog format: full EXIF display, hashtag captions, optional download, comments and reactions.
- **2.0 Carousel** — single or multi-image posts in a chronological stream. The way Instagram felt before the algorithm, on your own server.

Key characteristics across both editions:

- **Swappable skins** — Galleria, Hip to be Square, True Grit, Photogram, Impact Printer, A Grey Reckoning, New Horizon, Show N Tell, The Grid, and more. Each skin has its own CMS-driven appearance settings.
- **Self-update system** — Ed25519-signed release packages; apply updates through the admin without SSH access.
- **Community features** — comments, likes, and emoji reactions. Three identity modes: open (no account required), hybrid, or registered-only.
- **AI training crawler policy** — choose to allow, disallow, or take no position on AI training crawlers. Disallow mode blocks known AI bots via `robots.txt` and injects `noai`/`noimageai` meta tags site-wide.
- **Homepage modes** — Latest Post, Skin Landing Page, or a Static Page. A Landing Page Only toggle strips all navigation and chrome for coming-soon or splash installs.
- **Multisite management** — designate one install as the hub and connect spoke sites. Monitor heartbeat, cross-post images, moderate comments from a single dashboard, sync blogrolls, aggregate traffic stats, and SSO drill-through so you log in once and move freely between sites.
- **Troll protection system** — browser fingerprinting, writing style detection (AI semantic analysis of TF-IDF vectors), and keyword/phrase banning. Detect coordinated attacks or persistent trolls across VPN rotations; flag or silently reject submissions from banned users without alerting them.
- **No tracking, no ads, no third-party dependencies at runtime.**

---

## Requirements

- PHP 8.0 or later
- MySQL 5.7 / MariaDB 10.3 or later
- A web host that allows `.htaccess` overrides (mod_rewrite)

---

## Installation

1. Upload `setup.php` to an empty directory on your server.
2. Create a MySQL database and note the credentials.
3. Visit `https://yourdomain.com/setup.php` in your browser.
4. The deployer fetches the latest signed release from snapsmack.ca, verifies its checksum and Ed25519 signature, and extracts it. It self-deletes on success and hands off to `install.php`.
5. Follow the installer — it creates the database schema, seeds default settings, and writes `core/db.php`.
6. `install.php` self-deletes on completion.

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

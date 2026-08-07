<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# TAKE YOUR SHIT WITH YOU

**Every image. Every scrap of data. Pack it up and leave.**

TYSWY is SnapSmack's portable-export desktop application. It downloads a
complete, readable copy of everything you published — the photographs, the
words, the dates, the categories, the comments — into a plain folder on your
own computer, verifies that nothing went missing, and can build a WordPress
import package on the way out.

It exists because ownership is meaningless if leaving means starting over.

Spec: [`_spec/take-your-shit-with-you-spec-v0_1.md`](../../_spec/take-your-shit-with-you-spec-v0_1.md)

## What this is not

- **Not a backup.** Backups preserve an *implementation* so a broken site can be
  put back. That is SMACK UP YOUR BACKUP, and it stays a separate product.
  This preserves your *work* so you can take it elsewhere.
- **Not a restore tool.** There is no import back into SnapSmack here, by design.
- **Not a mode converter.** A GramOfSmack carousel comes out as a carousel; a
  photoblog image comes out as a photoblog image. Nothing is silently reshaped.
- **Not a promise about WordPress.** The WordPress package is a courtesy, and
  every single thing WordPress cannot represent is written down in the
  conversion report rather than quietly dropped.

## The one architectural rule

> Anything that can crater a server gets moved off the server.

The site does bounded, indexed reads and streams one file at a time. It never
builds an archive, never walks the media tree, never hashes the library, never
runs a job. All of that happens on your computer. A ten-thousand-image export is
thousands of cheap requests, not one heroic one that takes the site down with it.

## Using it

1. On your site: **Boring Ass Stuff → API Keys → TYSWY (read-only export)**.
   The key is shown once. It cannot write anything, it works on that one site,
   and it expires in three months.
2. Open the tool, paste the site address and the key, press **CONNECT**.
3. Choose a folder. Check the free space it reports.
4. Press **PACK MY SHIT**.

Stopping is safe at any point. Everything already downloaded and verified stays
on disk; point the tool at the same folder later and it carries on from the last
verified row and the last completed file. It will refuse to resume into a folder
holding a different site's export, and it will never overwrite a finished one.

## What comes out

```
Take Your Shit With You - Your Site - 2026-08-07/
├── README.txt              explains the folder with no reference to us
├── manifest.json           every file, with a SHA-256
├── verification.json       expected vs arrived, per table
├── site.json               public site identity and settings
├── content/
│   ├── posts/              one JSON file per post
│   ├── images/             one JSON file per image
│   ├── pages/
│   ├── comments/           threads, grouped by what they hang off
│   └── relationships/      carousel order, memberships, mosaics, trigrams
├── media/
│   ├── originals/          your photographs (and assets/ for inline longform)
│   └── optional/           thumbnails, if you asked for them
├── indexes/                albums, categories, collections, tags, blogroll,
│                           fediverse references, stats, content-map
├── courtesy/wordpress/     WXR + media + conversion report
├── schema/                 the JSON Schema these files validate against
└── logs/export.log
```

The archive stays a **folder**. No tooling is needed to read it — a file manager
and a text editor will do. Compression is local, optional, and only after
verification.

## What is deliberately excluded

Passwords and password hashes, TOTP secrets and recovery codes, API keys and
OAuth tokens, ActivityPub private signing keys, database and SMTP credentials,
sessions and CSRF data, IP bans, raw visitor IP addresses and security
fingerprints, internal queues and caches, and server filesystem paths.

"Every scrap of data" means every portable piece of *your content and your
organisation of it*. It has never meant live credentials or other people's
security data. The final report names every excluded class so the boundary is
stated rather than implied.

Two things ARE included that are worth knowing about:

- **EXIF, including GPS**, exactly as your site held it. The tool does not decide
  what metadata your own archive keeps.
- **Commenter email addresses**, because they are part of a comment record and a
  destination needs them to import comments. Raw IPs and fingerprints are not.

## Layout

| File | What it owns |
| --- | --- |
| `tyswy_client.py` | The only thing that talks to the network. Verifies every record and every chunk. |
| `portable_archive.py` | The output format. Sidecars, manifest, inventory. |
| `export_state.py` | Resume. Atomic state, append-only ledgers, byte-offset truncation. |
| `export_engine.py` | The orchestrator: collect, assemble, media, verify, adapters. |
| `wordpress_adapter.py` | The courtesy package. Reads the archive, writes only under `courtesy/wordpress/`. |
| `config.py` | `tyswy.ini` beside the exe; export key sealed with the shared vault. |
| `main.py` | The window. |
| `schema/` | The portable JSON Schema, shipped in the app and in every archive. |

## Server side

- `core/tyswy-api.php` — six GET actions, read-only, per-type column allowlists.
- `tests/tyswy-export-regression.php` — pins the export boundary.
- Key type `tyswy`, minted in `smack-api-keys.php`, three-month expiry.

## Tests

```bash
python -m unittest discover -s tests -v
```

`build.bat` runs them before it builds, and aborts if they fail. A tool whose
whole promise is "nothing went missing" has no business shipping without proving
its verification path works.

<!-- ===== SNAPSMACK EOF ===== -->

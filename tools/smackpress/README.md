<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->
# SMACKPRESS

One-post-at-a-time WordPress → SMACKTALK migration. A Qt shell on COLD SNAP's engine
(2026-09-21): the left side is the WordPress you are leaving, the right side is COLD
SNAP's COLD TAKE editor, batch and SEND — SMACKPRESS has no editor of its own.

## Quick start

```
cd tools/smackpress
pip install -r requirements.txt        # PySide6, requests, keyring
python smackpress_qt_launcher.py       # or: python -m smackpress_qt
```

1. Pick the SnapSmack site you are migrating INTO at the top (COLD SNAP's connection
   panel; needs that site's long-form 'smackpress' key).
2. Fill in the WordPress URL (https://), username and Application Password
   (WP Admin → Users → Profile → Application Passwords). TEST + LOAD POSTS.
3. Highlight a post, PULL ACROSS. Every picture is downloaded and placed as a bucket
   picture; nothing in the migrated post points back at WordPress.
4. Finish it in the editor, QUEUE POST, SEND QUEUED POSTS.
5. MARK MIGRATED records what became what and offers to hide the original on WordPress.

F1 for help inside the app.

## WordPress companion plugin

Install `tools/smackpress-wp-companion/smackpress-wp-companion.php` on the source
WordPress site. It exposes the /wp-json/smackpress/v1/ REST API that SMACKPRESS reads
from. Delete it once migration is complete.

## Credentials & security

The WordPress application password goes to your OS keychain when one is available,
otherwise it is sealed by the shared SnapSmack credential vault — never written to
disk in the clear (if the vault cannot seal it, it works for this session only and
the status line says so). The SnapSmack site key lives where COLD SNAP keeps it.
The SnapSmack SmackPress key expires (max 4 weeks); the WordPress application password
can be revoked any time in WP Admin.

## Layout

```
smackpress_qt/          the Qt shell (app, main_window, source_pane, help_topics)
smackpress/wp_source.py WordPress post → COLD SNAP Draft, pictures downloaded, [img:bucket:N]
smackpress/wp_client.py the companion REST client
smackpress/config.py    WP-side settings + migration db (smackpress.db next to the exe)
smackpress/db.py        which WordPress post became which SMACKTALK post
```
The editor, poster, MOSAIC, batch rail and connection panel are imported from
`tools/coldsnap` (COLD SNAP). Fix them there; SMACKPRESS gets the fix for free.

<!-- ===== SNAPSMACK EOF ===== -->

<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# GHOSTED

Ghost -> SNAPSMACK (`SMACKTALK`) importer for SNAP HQ's Migration Centre.

## Features
- Imports Ghost JSON exports (`ghost-import.json` / `*.json`), `.zip` archives (with `content/images/`), or extracted export folders.
- Reverses `tools/take-your-shit-with-you/ghost_adapter.py` field mapping: preserves slugs, dates, tags, authors, excerpts, drafts/published status, feature images, and inline images.
- Reports leftover Ghost cards/shortcodes (`kg-*` cards, mobiledoc/lexical cards, `[shortcode]` tags) rather than silently dropping them.
- Dry-run first with exact counts (`posts`, `pages`, `images`, `tags`).
- Idempotent re-run with no duplicates (tracks by Ghost post ID + destination `import-map` + slug lookup).

<!-- ===== SNAPSMACK EOF ===== -->

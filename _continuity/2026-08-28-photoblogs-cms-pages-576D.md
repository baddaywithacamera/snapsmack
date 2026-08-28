# PHOTOBLOGS.FYI CMS PAGE CONTINUITY — 0.7.576D

## Start here

Sean's non-negotiable architecture rule is: **every public page uses the active
SnapSmack skin. No page is exempt.** Custom HTML and specialized displays may be
inserted only inside the CMS page body. They must not ship their own document,
header, navigation, footer, or competing page shell.

`v0.7.575D` already exists at commit `fa57b347`. Do not rebuild, move, or replace
that tag. The photoblogs work therefore ships in `0.7.576D` through the normal
signed update path. Do not FTP these files into production: SMACKBACK/file-tamper
integrity depends on the signed package and its manifest.

## What is implemented on dev

Commit `1b66e677` contains the implementation:

- `core/parser.php` recognizes `[photoblogs_directory]` as a body shortcode.
- `core/photoblogs-directory-view.php` renders the Directory as a searchable,
  filterable text listing with recently updated blogs first and a bounded
  fairness rotation for inactive-but-live blogs.
- `core/photoblogs-feed.php` reads `snap_directory_feed_items` joined to active,
  non-dead directory members, with a compatibility fallback to the former feed
  table. It emits one image per blog per day and links to the original post.
- `directory.php` and `feed.php` are compatibility redirects to
  `/page.php?slug=directory` and `/page.php?slug=feed`.
- `page.php` exposes a safe slug body class for page-scoped styling while still
  using the normal skin lifecycle.
- `projects/photoblogs-fyi/cms-pages/directory.html` contains only
  `[photoblogs_directory]`.
- `projects/photoblogs-fyi/cms-pages/custom.css` styles the shortcode bodies and
  removes the discarded black-panel treatment from About/static content. It
  does not create replacement chrome.

Regression checks passed before commit:

- PHP lint for all changed PHP files.
- `tests/photoblogs-directory-regression.php`.
- `tests/directory-feed-regression.php`.
- `git diff --check`.

## Production CMS state already changed

The authenticated Page Manager on photoblogs.fyi now contains four CMS pages:

- Home (`home`)
- Directory (`directory`) with `[photoblogs_directory]`
- Feed (`feed`) with `[photoblogs_feed]`
- About (`about`)

The shared custom CSS was also saved through the CMS. Until `0.7.576D` is built,
published, and installed, Directory will display its shortcode literally because
production `0.7.572D` does not yet know that shortcode. That is expected and is
the release blocker—not a reason to create another standalone page.

## Required next steps

1. Commit this continuity/changelog addition without folding in unrelated dirty
   work (`projects/snapsmack-ca/brass-tacks.php` is a separate existing change).
2. Tag `0.7.576D` using `php tools/release-flow.php tag-dev 0.7.576` once the dev
   tree is clean and regression tests pass.
3. Build the beta only through Smack Central's BITCHIN' release panel.
4. Publish it through the established signed update channel.
5. Install it on photoblogs.fyi from **System Updates**.
6. Confirm the hourly `cron-directory-feeds.php` job is installed by the release
   or fleet process, run it once, and verify `snap_directory_feed_items` fills.
7. Verify `/`, `/page.php?slug=directory`, `/page.php?slug=feed`, and
   `/page.php?slug=about` share the active ONYX header/footer, Directory is a text
   list, About has no black box, and Feed contains original-post images only.

## Do not regress

- Do not create standalone public page templates for Directory, Feed, or About.
- Do not delete the compatibility entry points; keep them as redirects so old
  URLs remain valid.
- Do not manually FTP release files or bypass the signed updater.
- Do not repoint or rebuild `v0.7.575D`.


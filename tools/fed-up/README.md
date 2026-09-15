<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the HTML SNAPSMACK EOF marker -->
# FED UP — fediverse backup, migration, and identity

Fed(iverse) + "fed up with your current server."

Three functions (spec: `_spec/fed-up-spec-v0_1.md` + `v0_2.md`):

1. **BACK UP** your fediverse profile — public posts with real dates, media, like/boost/reply
   counts, the public replies under each post, and your follower and following lists — into a
   folder of plain files you keep. Full the first time, then only what is new. Optional second
   copy: any mirror folder (a synced cloud folder, an external drive) and/or Backblaze B2 through
   SMACK UP YOUR BACKUP's own client.
2. **RESTORE** a FED UP archive onto a SnapSmack site — rides UNZUCKER's poster unchanged
   (original dates, captions, tags, photos at full size; posts already on the site are skipped).
   FOLLOWERS TO TELL hands you the archived follower handles for a "here is where I went" post.
3. **ALIAS** — `@you@photoblogs.fyi`. Built in the CMS (0.7.719D), set on your site's Fediverse
   Config; this tab only explains and points.

## State: 0.1.0 — functions 1 and 2 built, live-tested read-only
Tested against pixelfed.social (REST route, comments API, media), mastodon.social (authorized
fetch: actor 401 → public API; follower paging 429 → recorded as partial) and a SnapSmack site
(outbox crawl). Not yet exercised by a real user end to end — the RESTORE leg has only run
against the fake server in `tests/`.

### What a public fetch cannot see (the tool says so in every summary)
- followers-only / private posts (needs the owner's token — v1.1)
- follower lists the server or account hides; very large lists are capped (5,000) or cut off
  where the server refuses to page further for a stranger
- SnapSmack Notes do not publish like/boost/reply collections yet, so counts from a SnapSmack
  source are blank (CMS gap, noted)

## Archive layout
    <handle>@<domain>/
      manifest.json          format, source, snapshot times, counts, sha256 per file, id → file index
      profile.json           display name, bio, fields, avatar/header filenames, raw actor document
      posts/<YYYY>/<id>.json one post per file (text, html, tags, counts, replies[], media_files[], raw)
      media/<id>-<n>.<ext>   originals as served
      graph/followers.json   {items:[{acct, actor}], total_reported, partial|capped_at} or {unavailable}
      graph/following.json
      snapshots/<ISO>.json   per-run summary incl. "could_not_fetch"
      .b2-ledger.json        (only if B2 is used) sha256 of what was last pushed

## Run from source
    python app.py          # needs tools/_shared, tools/unzucker, tools/smack-up-your-backup on the path; app.py adds them

## Build
    build.bat              # tests → PyInstaller (fed-up.spec) → C:\snapsmack\fed-up\fed-up.exe
                           # spec bundles _shared, unzucker's poster/ig_parser/exif_writer, suyb's cloud_client

## Tests
    python -m unittest discover -s tests     # 13 tests, fake fediverse server in tests/_fakes.py

<!-- ===== SNAPSMACK EOF ===== -->

<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# Smack Your Batch Up (SYBU)

Desktop batch poster for SnapSmack. Point it at a folder of photos, let Gemini
fill in the titles, captions, tags, categories and albums, review the queue, and
post the whole batch to your site in one go. Works with both SOLO (SmackOneOut)
and GRAM (GramOfSmack) sites.

## Usage

1. **Connect** — enter your site URL and API Key (generated in SnapSmack Admin →
   Settings → API Access) and click Connect. SYBU logs in and loads your
   categories and albums.
2. **Set image folder** — click `…` next to Image Folder and pick the folder of
   images.
3. **Scan Folder** — loads every JPG / PNG / WebP in the folder into the queue,
   applying your default category, album and orientation to each row.
4. **Enrich with Gemini** — Gemini looks at each image and fills in a title,
   tags, category and album. Rows that already have a title are skipped, and you
   can edit any field directly.
5. **Post Batch** — validates, then posts every item in the queue. Progress
   shows row by row; failed posts stay red so you can retry.

Loading a pre-written `.txt` manifest instead of scanning is still supported as
an advanced option. In-app **Help** ("?") covers Google Drive uploads, the
COLOUR / B&W tag, sessions, and settings.

## Building from source

Requirements: Python 3.11+, `exiftool.exe` (download from https://exiftool.org).

```
pip install -r requirements.txt
# Place exiftool.exe in this folder
build.bat
```

Output: `sybu.exe` — single file, no install required. `build.bat` auto-bumps
`BUILD_VERSION` in `main.py` each build (skip with `build.bat norev`).

## Files

| File | Purpose |
|---|---|
| `main.py` | tkinter UI, entry point |
| `poster.py` | SnapSmack login, category/album lookup, image posting |
| `gemini.py` | Gemini vision enrichment (titles, tags, categories, albums) |
| `drive.py` | optional Google Drive upload for hosted originals |
| `exif_writer.py` | ExifTool wrapper — embeds copyright into a temp copy |
| `manifest_parser.py` | parses/validates the advanced `.txt` manifest format |
| `profile_manager.py` | per-site connection profiles |
| `recovery.py` | resume / crash recovery for an interrupted batch |
| `config.py` | reads/writes `config.ini` |
| `build.bat` | PyInstaller build script |
| `requirements.txt` | Python dependencies |
<!-- ===== SNAPSMACK EOF ===== -->

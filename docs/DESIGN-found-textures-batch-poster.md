# Found Textures Batch Poster — Design Document

**Tool:** `ft-batch-poster`
**Platform:** Windows desktop app (.exe, Python + tkinter + PyInstaller)
**Target site:** foundtextures.ca (SnapSmack Alpha v0.7.5)
**Status:** Design / pre-build

---

## Overview

A standalone Windows application that takes a folder of texture images and a matching AI-generated manifest `.txt` file, then batch-posts each image to foundtextures.ca with correct title, tags, category, and album — without the user ever touching the SnapSmack admin panel.

The user's workflow is:

1. Collect a batch of images for a single category + album (e.g. 10 concrete shots all going into "Concrete" / "Spring 2026")
2. Upload them to Gemini using the **Found Textures AI Prompt** to generate titles and tags
3. Paste the AI output into a `.txt` manifest file, add the CATEGORY and ALBUM fields (or leave them to the tool's defaults)
4. Open the batch poster, point it at the image folder and manifest, enter credentials, and click **Post Batch**
5. The tool handles the rest: ExifTool metadata embedding, then direct HTTP upload to SnapSmack for each image in sequence

---

## Tech Stack

| Component | Choice | Reason |
|---|---|---|
| Language | Python 3.11+ | Cross-platform libs, ExifTool bindings, requests |
| UI | tkinter | Ships with Python, no extra install, sufficient for this tool |
| HTTP | `requests` | Session-based auth, multipart POST |
| EXIF | ExifTool (bundled) | Industry standard, handles all JPEG metadata reliably |
| Packaging | PyInstaller | Produces a single `.exe` with Python + ExifTool bundled |
| Config storage | `config.ini` (local, same folder as .exe) | Simple, no registry, portable |

ExifTool is bundled inside the `.exe` (via PyInstaller's `--add-binary` or included as a resource). The user does not install anything — they just run `ft-batch-poster.exe`.

---

## Manifest File Format

See `found-textures-ai-prompt.md` for the full spec and AI prompt. Summary:

```
---
FILE: IMG_4521.jpg
TITLE: Salt specks on the dark, rust invades the speckled grey, earth turns into grit
TAGS: #rust #concrete #speckled #grey #decay #industrial #macro #texture #foundtexture
CATEGORY: Concrete
ALBUM: Spring 2026
---
FILE: IMG_4522.jpg
TITLE: Blue islands emerge, dark scales crack on faded grey, time wears down the paint
TAGS: #autopaint #peeling #texture #macro #abstract #weathered #foundtexture
CATEGORY: Concrete
ALBUM: Spring 2026
---
```

**Parser rules:**
- Entries are delimited by `---` on its own line
- Fields are `KEY: value`, one per line
- Field order within an entry does not matter
- CATEGORY and ALBUM are matched case-insensitively against SnapSmack's category/album names
- If CATEGORY or ALBUM is blank in the manifest, the tool uses its configured defaults (which can also be blank = uncategorized/no album)
- FILE must match exactly (case-sensitive) to a file in the selected image folder

---

## SnapSmack Integration

### Authentication

The tool logs in by POSTing to the SnapSmack login page and maintaining a `requests.Session()` that carries the session cookie for all subsequent requests. No API key — same session cookie the browser uses.

```
POST https://foundtextures.ca/login.php
  username=...
  password=...
```

After login, the session is reused for all image posts and for the initial category/album lookup.

### Pre-flight: Resolve Category and Album IDs

Before posting, the tool fetches `smack-post.php` (GET) and parses the HTML to build lookup tables:

```
cat_name  → cat_id   (from <input name="cat_ids[]" ...> labels)
album_name → album_id (from <input name="album_ids[]" ...> labels)
```

This happens once per run. Unrecognised category/album names are flagged to the user before posting begins (not mid-run).

### Image Post

Each image is POSTed to `smack-post.php` as a multipart form:

```
POST https://foundtextures.ca/smack-post.php
  Content-Type: multipart/form-data

  img_file   = <binary image data>
  title      = <TITLE from manifest>
  tags       = <TAGS from manifest, space-separated hashtags>
  cat_ids[]  = <resolved category ID, if any>
  album_ids[]= <resolved album ID, if any>
  img_status = published
  desc       = (empty)
```

SnapSmack handles thumbnail generation, palette extraction, EXIF orientation correction, and slug generation server-side — the tool does not need to replicate any of that.

---

## ExifTool Step

Before each image is uploaded, ExifTool embeds the copyright string into the file's EXIF/IPTC metadata. The tool writes to a **temp copy** of the image (in the system temp folder) — the user's original files are never modified.

**Copyright string:**
```
© Sean McCormick / foundtextures.ca. Free for personal and commercial use. Cannot be resold as a standalone texture file. No attribution required. Permitted for use in AI training datasets.
```

**ExifTool command:**
```
exiftool -overwrite_original
  -Copyright="© Sean McCormick / foundtextures.ca. ..."
  -Artist="Sean McCormick"
  -ImageDescription="<TITLE>"
  -Keywords="<comma-separated tags without #>"
  /tmp/ft_working/IMG_4521.jpg
```

ExifTool is called via `subprocess`. The bundled ExifTool binary is extracted to a temp location on first run.

---

## Application UI

Single window, no tabs. Vertical flow.

```
┌─────────────────────────────────────────────────┐
│  Found Textures Batch Poster                    │
├─────────────────────────────────────────────────┤
│  Site URL    [https://foundtextures.ca        ] │
│  Username    [sean                            ] │
│  Password    [••••••••••••••                  ] │
│              [ ] Remember credentials           │
├─────────────────────────────────────────────────┤
│  Image Folder  [/Users/Sean/Desktop/batch1  📁] │
│  Manifest File [/Users/Sean/Desktop/batch1.txt📄]│
├─────────────────────────────────────────────────┤
│  Default Category  [Concrete ▼] (if blank in manifest) │
│  Default Album     [Spring 2026 ▼] (if blank in manifest) │
├─────────────────────────────────────────────────┤
│  [ Validate Manifest ]    [ Post Batch ]        │
├─────────────────────────────────────────────────┤
│  Progress: ████████████░░░░░░  8 / 12           │
│                                                 │
│  ✓ IMG_4521.jpg — posted                        │
│  ✓ IMG_4522.jpg — posted                        │
│  ✓ IMG_4523.jpg — posted                        │
│  ⚠ IMG_4524.jpg — category "Cemnet" not found  │
│  ...                                            │
└─────────────────────────────────────────────────┘
```

**Validate Manifest** (runs before posting):
- Parses the manifest file
- Checks that every FILE exists in the image folder
- Resolves all CATEGORY and ALBUM names against SnapSmack (requires login)
- Reports any mismatches so the user can fix the manifest before committing

**Post Batch**:
- Runs validate first
- If validation passes (or user overrides warnings), posts sequentially
- Progress bar updates after each image
- Each result logged to the scroll area: ✓ success, ✗ error, ⚠ warning
- Failures are non-fatal — tool continues to the next image
- After completion, shows a summary: "12 posted, 0 failed"

---

## Configuration File

`config.ini` lives beside the `.exe`. Credentials are stored only if the user checks **Remember credentials**. Password is stored obfuscated (base64 — not encrypted, just not plaintext in a casual glance).

```ini
[site]
url = https://foundtextures.ca

[auth]
username = sean
password = <base64>

[defaults]
category =
album =

[paths]
last_image_folder =
last_manifest_file =
```

---

## Error Handling

| Scenario | Behaviour |
|---|---|
| Login fails | Show error immediately, do not proceed |
| Category/album name not found | Flag in validation; during post, skip assignment and log warning |
| Image file not found on disk | Log error for that entry, skip, continue |
| HTTP error on post | Log error with status code, skip, continue |
| ExifTool failure | Log warning, upload original (without embedded metadata), continue |
| Network timeout | Retry once, then log error and skip |
| SnapSmack returns error page instead of redirect | Detect via response URL or body, log error |

---

## Build & Distribution

```
# Build command
pyinstaller --onefile --windowed --name ft-batch-poster
  --add-binary "exiftool.exe;."
  --icon assets/icon.ico
  main.py
```

Output: `dist/ft-batch-poster.exe` — single file, ~15MB, no install required.

ExifTool for Windows (standalone .exe) is downloaded separately and placed in the project root before building. It is not redistributed in source — only bundled in the final `.exe`.

---

## File Structure (Source)

```
ft-batch-poster/
  main.py              — Entry point, tkinter UI
  poster.py            — Core posting logic (login, resolve IDs, post image)
  manifest_parser.py   — Parses and validates .txt manifest files
  exif_writer.py       — ExifTool wrapper (subprocess)
  config.py            — config.ini read/write
  exiftool.exe         — Bundled ExifTool binary (not in git)
  assets/
    icon.ico
  requirements.txt
  build.bat            — Runs the pyinstaller command above
```

---

## Out of Scope (v1)

- Parallel/concurrent uploads (sequential only for simplicity and to avoid hammering the server)
- Drag-and-drop for image folder or manifest
- Image preview in the UI
- Direct Gemini integration (user still generates manifest manually)
- Editing manifest entries in the UI
- Posting to multiple sites

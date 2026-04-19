# ft-batch-poster

Batch posting tool for foundtextures.ca (SnapSmack). Takes a folder of images
and an AI-generated manifest `.txt` file and posts them all to SnapSmack with
correct titles, tags, categories, and albums.

## Usage

1. Generate image metadata using the AI prompt in `found-textures-ai-prompt.md`
2. Add `CATEGORY` and `ALBUM` to the manifest (or leave blank for defaults)
3. Run `ft-batch-poster.exe`
4. Fill in your site URL and credentials
5. Select your image folder and manifest file
6. Click **Validate Manifest** to catch any issues first
7. Click **Post Batch**

## Building from source

Requirements: Python 3.11+, `exiftool.exe` (download from https://exiftool.org)

```
pip install -r requirements.txt
# Place exiftool.exe in this folder
build.bat
```

Output: `dist\ft-batch-poster.exe` — single file, no install required.

## Files

| File | Purpose |
|---|---|
| `main.py` | tkinter UI, entry point |
| `poster.py` | SnapSmack login, category/album lookup, image posting |
| `manifest_parser.py` | Parses and validates the `.txt` manifest format |
| `exif_writer.py` | ExifTool wrapper — embeds copyright into a temp copy |
| `config.py` | Reads/writes `config.ini` |
| `build.bat` | PyInstaller build script |
| `requirements.txt` | Python dependencies |

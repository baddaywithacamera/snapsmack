"""
ft-batch-poster — drive-backfill-match.py
==========================================
Matches your original local files to server images using perceptual
hashing, uploads originals to Drive where matched, falls back to the
FTP'd server copy where not — so ALL 567 get Drive links in one run.

HOW IT WORKS:
  1. Reads the SQL export to find all published images with no download_url.
  2. For each, finds the matching file in --server-folder (FTP'd copies,
     named identically to img_file on the server, e.g. rough-scales-....jpg).
  3. Computes a perceptual hash (pHash) for each server file.
  4. Computes pHash for every image in --originals-folder (your camera files).
  5. Matches originals to server images by closest hash distance.
  6. Uploads the ORIGINAL where matched, the SERVER COPY as fallback.
  7. Writes a SQL patch file — run it against your DB to apply Drive links.

RESULT: every one of the 567 gets a Drive link. Matched ones get the
full-resolution original; unmatched get the web-sized server copy.

REQUIREMENTS:
  pip install imagehash Pillow requests

USAGE:
  python drive-backfill-match.py ^
    --sql           path\to\squir871_foundtextures.sql ^
    --server-folder "C:\path\to\ftp-images" ^
    --originals     "C:\path\to\camera-originals"

  Optional:
    --folder-id   DRIVE_FOLDER_ID   (reads config.ini if omitted)
    --creds       credentials.json  (reads config.ini if omitted)
    --threshold   10                (hash distance 0-64, lower = stricter)
    --dry-run                       (match only, no uploads)
    --output      patch.sql
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import argparse
import configparser
import os
import re
import sys
import time
from pathlib import Path

from PIL import Image

try:
    import imagehash
except ImportError:
    print("ERROR: imagehash not installed.  Run: pip install imagehash Pillow")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Parse SQL export
# ---------------------------------------------------------------------------

def parse_missing(sql_path: str):
    """Return list of dicts for published images with no download_url."""
    with open(sql_path, 'r', encoding='utf-8') as f:
        content = f.read()

    row_re = re.compile(
        r"\((\d+),\s*'((?:[^'\\]|\\.)*)'"   # id, title
        r".*?'(img_uploads/[^']+)'"           # img_file (full relative path)
        r".*?'(published)'"                   # status
        r".*?,\s*(\d+),\s*'([^']*)'\s*,",    # allow_download, download_url
        re.DOTALL
    )

    missing = []
    for m in row_re.finditer(content):
        id_, title, img_file, status, allow_dl, download_url = m.groups()
        if not download_url:
            missing.append({
                'id':       int(id_),
                'title':    title,
                'img_file': img_file,                        # full relative path on server
                'filename': os.path.basename(img_file),     # just the filename
            })
    return missing


# ---------------------------------------------------------------------------
# Perceptual hashing
# ---------------------------------------------------------------------------

def phash_file(path: str):
    try:
        return imagehash.phash(Image.open(path).convert('RGB'))
    except Exception:
        return None


def hash_folder(folder: Path, label: str):
    """Return dict of {str(path): hash} for all images in folder."""
    exts   = {'.jpg', '.jpeg', '.png', '.JPG', '.JPEG', '.PNG'}
    files  = [f for f in folder.rglob('*') if f.suffix in exts]
    hashes = {}
    print(f"Hashing {len(files)} files in {label}...")
    for i, f in enumerate(files, 1):
        h = phash_file(str(f))
        if h is not None:
            hashes[str(f)] = h
        if i % 100 == 0:
            print(f"  {i}/{len(files)}...")
    print(f"  {len(hashes)} hashed.\n")
    return hashes


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

def load_config():
    base = os.path.dirname(os.path.abspath(__file__))
    cfg  = configparser.ConfigParser()
    cfg.read(os.path.join(base, 'config.ini'))
    return {
        'google_credentials': cfg.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cfg.get('google', 'drive_folder_id',    fallback=''),
    }


# ---------------------------------------------------------------------------
# Drive helpers
# ---------------------------------------------------------------------------

def get_drive_service(creds_path: str):
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import drive as drive_module
    return drive_module.authenticate(creds_path)


def upload_to_drive(service, file_path: str, filename: str, folder_id: str) -> str:
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import drive as drive_module
    return drive_module.upload(service, file_path, filename, folder_id or None)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description='Backfill Drive links by matching originals to server images.'
    )
    parser.add_argument('--sql',            required=True,
                        help='Path to SQL export file')
    parser.add_argument('--server-folder',  required=True,
                        help='Folder containing FTP\'d server images (web-sized copies)')
    parser.add_argument('--originals',      default='',
                        help='Folder containing your camera originals (optional)')
    parser.add_argument('--folder-id',      default='')
    parser.add_argument('--creds',          default='')
    parser.add_argument('--threshold',      type=int, default=10,
                        help='Max hash distance to count as a match (default 10)')
    parser.add_argument('--dry-run',        action='store_true',
                        help='Match and report only — no uploads')
    parser.add_argument('--output',         default='drive-backfill-patch.sql')
    args = parser.parse_args()

    cfg        = load_config()
    creds_path = args.creds      or cfg['google_credentials']
    folder_id  = args.folder_id  or cfg['drive_folder_id']

    if not args.dry_run:
        if not creds_path or not os.path.isfile(creds_path):
            print(f"ERROR: credentials.json not found: {creds_path!r}")
            print("Pass --creds or set credentials_path in config.ini")
            sys.exit(1)

    server_folder = Path(args.server_folder)
    if not server_folder.is_dir():
        print(f"ERROR: --server-folder not found: {server_folder}")
        sys.exit(1)

    # ── Parse SQL ─────────────────────────────────────────────────────
    print(f"Parsing {args.sql}...")
    missing = parse_missing(args.sql)
    print(f"{len(missing)} images missing Drive links.\n")
    if not missing:
        print("Nothing to do.")
        return

    # ── Build server filename → path map ─────────────────────────────
    # Server files are FTP'd locally, named the same as on the server.
    exts = {'.jpg', '.jpeg', '.png', '.JPG', '.JPEG', '.PNG'}
    server_by_name = {
        f.name: str(f)
        for f in server_folder.rglob('*')
        if f.suffix in exts
    }
    print(f"Found {len(server_by_name)} files in server folder.\n")

    # ── Hash server images ────────────────────────────────────────────
    server_hashes = hash_folder(server_folder, "server-folder")

    # ── Hash originals (if provided) ─────────────────────────────────
    orig_hashes = {}
    if args.originals:
        originals_folder = Path(args.originals)
        if originals_folder.is_dir():
            orig_hashes = hash_folder(originals_folder, "originals-folder")
        else:
            print(f"WARNING: --originals folder not found: {originals_folder}, skipping.\n")

    # ── Match each missing record ─────────────────────────────────────
    # Strategy:
    #   1. Direct filename match (server_folder has file with same name as img_file)
    #   2. Hash match against originals (upload original)
    #   3. Fallback: use server copy

    results = []  # (rec, upload_path, upload_filename, match_type)

    print("Matching records to files...")
    for i, rec in enumerate(missing, 1):
        filename   = rec['filename']
        server_path = server_by_name.get(filename)

        if server_path is None:
            print(f"  [{i}/{len(missing)}] ✗ SERVER FILE NOT FOUND: {filename[:60]}")
            results.append((rec, None, filename, 'missing'))
            continue

        server_hash = phash_file(server_path)

        # Try to find a matching original
        best_orig_path = None
        best_dist      = 999
        if orig_hashes and server_hash is not None:
            for opath, ohash in orig_hashes.items():
                d = server_hash - ohash
                if d < best_dist:
                    best_dist      = d
                    best_orig_path = opath

        if best_orig_path and best_dist <= args.threshold:
            # Confident original match
            print(f"  [{i}/{len(missing)}] ✓ ORIGINAL  dist={best_dist:2d}  {filename[:45]}")
            print(f"       → {os.path.basename(best_orig_path)}")
            results.append((rec, best_orig_path, filename, 'original'))
        elif best_orig_path and best_dist <= args.threshold * 2:
            # Uncertain — use server copy but flag it
            print(f"  [{i}/{len(missing)}] ~ UNCERTAIN dist={best_dist:2d}  {filename[:45]}")
            print(f"       → using server copy (original was {os.path.basename(best_orig_path)})")
            results.append((rec, server_path, filename, 'uncertain-fallback'))
        else:
            # No original found — use server copy
            if orig_hashes:
                print(f"  [{i}/{len(missing)}] → FALLBACK  {filename[:55]}")
            else:
                print(f"  [{i}/{len(missing)}] → SERVER    {filename[:55]}")
            results.append((rec, server_path, filename, 'server'))

    # ── Summary ───────────────────────────────────────────────────────
    by_type = {}
    for _, _, _, t in results:
        by_type[t] = by_type.get(t, 0) + 1

    print(f"\n{'='*60}")
    print(f"Total:              {len(results)}")
    print(f"Original matched:   {by_type.get('original', 0)}")
    print(f"Server copy:        {by_type.get('server', 0)}")
    print(f"Uncertain fallback: {by_type.get('uncertain-fallback', 0)}")
    print(f"File not found:     {by_type.get('missing', 0)}")

    uploadable = [(r, p, f, t) for r, p, f, t in results if p is not None]
    print(f"To upload:          {len(uploadable)}")

    if args.dry_run:
        print("\n-- DRY RUN -- no uploads performed.")
        return

    if not uploadable:
        print("Nothing to upload.")
        return

    # ── Authenticate Drive ────────────────────────────────────────────
    print("\nAuthenticating with Google Drive...")
    service = get_drive_service(creds_path)
    print("Authenticated.\n")

    # ── Upload ────────────────────────────────────────────────────────
    patches  = []
    failures = []
    out_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), args.output)

    for idx, (rec, upload_path, filename, match_type) in enumerate(uploadable, 1):
        tag = 'ORIG' if match_type == 'original' else 'SRVR'
        print(f"[{idx}/{len(uploadable)}] {tag}  id={rec['id']}  {filename[:50]}")
        try:
            drive_url = upload_to_drive(service, upload_path, filename, folder_id)
            patches.append((rec['id'], drive_url))
            print(f"  ✓ {drive_url}")
        except Exception as e:
            failures.append((rec['id'], filename, str(e)))
            print(f"  ✗ {e}")

        # Write patch incrementally — safe to interrupt and resume
        with open(out_path, 'w', encoding='utf-8') as f:
            f.write("-- SnapSmack Drive backfill patch\n")
            f.write(f"-- {len(patches)} records patched so far\n\n")
            for pid, url in patches:
                safe_url = url.replace("'", "\\'")
                f.write(
                    f"UPDATE snap_images "
                    f"SET allow_download=1, download_url='{safe_url}' "
                    f"WHERE id={pid};\n"
                )

        time.sleep(0.3)

    # ── Final summary ─────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"Uploaded: {len(patches)}   Failed: {len(failures)}")
    if patches:
        print(f"\nSQL patch written to:\n  {out_path}")
        print("Run that file against your DB to apply all Drive links.")
    if failures:
        print(f"\nFailed uploads:")
        for fid, fname, reason in failures:
            print(f"  id={fid}  {fname[:50]}  — {reason}")


if __name__ == '__main__':
    main()
# ===== SNAPSMACK EOF =====

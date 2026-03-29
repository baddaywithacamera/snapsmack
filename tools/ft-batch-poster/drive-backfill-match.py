"""
ft-batch-poster — drive-backfill-match.py
==========================================
Matches your original local files to the 567 server images using
perceptual hashing, then uploads the originals to Google Drive
and generates a SQL patch.

HOW IT WORKS:
  1. Reads the SQL export to get all images missing download_url.
  2. Downloads the server thumbnail for each (small — fast).
  3. Computes a perceptual hash (pHash) for each server thumbnail.
  4. Computes pHash for every .jpg/.png in your local source folder.
  5. Matches local files to server records by closest hash distance.
  6. Flags any uncertain matches (distance > threshold) for review.
  7. Uploads confirmed originals to Drive, writes SQL patch.

REQUIREMENTS:
  pip install imagehash Pillow requests

USAGE:
  python drive-backfill-match.py \
    --sql path/to/squir871_foundtextures.sql \
    --local-folder "C:/path/to/your/originals" \
    --base-url https://foundtextures.ca

  Optional:
    --folder-id  DRIVE_FOLDER_ID
    --creds      credentials.json
    --threshold  10          (hash distance 0-64, lower = stricter, default 10)
    --dry-run                (match only, no uploads, shows results)
    --output     patch.sql   (output filename, default drive-backfill-patch.sql)
"""

import argparse
import configparser
import os
import re
import sys
import tempfile
import time
from pathlib import Path

import requests
from PIL import Image

try:
    import imagehash
except ImportError:
    print("ERROR: imagehash not installed. Run: pip install imagehash")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Parse SQL export
# ---------------------------------------------------------------------------

def parse_missing(sql_path: str):
    """Return list of (id, title, img_file, thumb_square) missing download_url."""
    with open(sql_path, 'r', encoding='utf-8') as f:
        content = f.read()

    # Columns: id, img_title, img_slug, ..., img_file, ..., img_thumb_square, ...
    # We need id, title, img_file, img_thumb_square, download_url
    row_re = re.compile(
        r"\((\d+),\s*'((?:[^'\\]|\\.)*)'"            # id, title
        r".*?'(img_uploads/[^']+)'"                   # img_file
        r".*?'(published)'"                           # status
        r".*?,\s*(\d+),\s*'([^']*)'\s*,"             # allow_download, download_url
        r".*?'(img_uploads/[^']*thumbs/t_[^']+)'",   # img_thumb_square
        re.DOTALL
    )

    missing = []
    for m in row_re.finditer(content):
        id_, title, img_file, status, allow_dl, download_url, thumb = m.groups()
        if not download_url:
            missing.append({
                'id':        int(id_),
                'title':     title,
                'img_file':  img_file,
                'thumb':     thumb,
            })

    return missing


# ---------------------------------------------------------------------------
# Perceptual hashing
# ---------------------------------------------------------------------------

def phash_from_path(path: str):
    try:
        return imagehash.phash(Image.open(path).convert('RGB'))
    except Exception:
        return None


def phash_from_url(url: str, session: requests.Session):
    try:
        resp = session.get(url, timeout=20)
        resp.raise_for_status()
        with tempfile.NamedTemporaryFile(suffix='.jpg', delete=False) as tmp:
            tmp.write(resp.content)
            tmp_path = tmp.name
        h = phash_from_path(tmp_path)
        os.unlink(tmp_path)
        return h
    except Exception:
        return None


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

def load_config():
    base = os.path.dirname(os.path.abspath(__file__))
    cfg = configparser.ConfigParser()
    cfg.read(os.path.join(base, 'config.ini'))
    return {
        'google_credentials': cfg.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cfg.get('google', 'drive_folder_id', fallback=''),
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
    parser = argparse.ArgumentParser()
    parser.add_argument('--sql',          required=True)
    parser.add_argument('--local-folder', required=True,  help='Folder containing original files')
    parser.add_argument('--base-url',     default='https://foundtextures.ca')
    parser.add_argument('--folder-id',    default='')
    parser.add_argument('--creds',        default='')
    parser.add_argument('--threshold',    type=int, default=10,
                        help='Max hash distance to count as a match (0-64). Default 10.')
    parser.add_argument('--dry-run',      action='store_true')
    parser.add_argument('--output',       default='drive-backfill-patch.sql')
    args = parser.parse_args()

    cfg = load_config()
    creds_path = args.creds or cfg['google_credentials']
    folder_id  = args.folder_id or cfg['drive_folder_id']
    base_url   = args.base_url.rstrip('/')

    if not args.dry_run:
        if not creds_path or not os.path.isfile(creds_path):
            print(f"ERROR: credentials.json not found: {creds_path!r}")
            sys.exit(1)

    # ── Load local originals ──────────────────────────────────────────
    local_folder = Path(args.local_folder)
    local_files  = list(local_folder.glob('**/*.jpg')) + list(local_folder.glob('**/*.JPG')) \
                 + list(local_folder.glob('**/*.jpeg')) + list(local_folder.glob('**/*.png'))
    print(f"Found {len(local_files)} local files in {local_folder}")

    # ── Parse SQL ─────────────────────────────────────────────────────
    print(f"Parsing {args.sql}...")
    missing = parse_missing(args.sql)
    print(f"Found {len(missing)} server images missing Drive links.\n")

    if not missing:
        print("Nothing to do.")
        return

    # ── Hash local files ──────────────────────────────────────────────
    print("Hashing local originals...")
    local_hashes = {}
    for i, lf in enumerate(local_files, 1):
        h = phash_from_path(str(lf))
        if h is not None:
            local_hashes[str(lf)] = h
        if i % 50 == 0:
            print(f"  {i}/{len(local_files)} hashed...")
    print(f"  {len(local_hashes)} local files hashed.\n")

    # ── Download server thumbs and match ─────────────────────────────
    session = requests.Session()
    session.headers['User-Agent'] = 'SnapSmack-Backfill/1.0'

    matched    = []   # (server_record, local_path, distance)
    uncertain  = []   # same — distance > threshold
    no_match   = []   # server records with no close local match

    print("Matching server images to local files...")
    for i, rec in enumerate(missing, 1):
        thumb_url = f"{base_url}/{rec['thumb']}"
        server_hash = phash_from_url(thumb_url, session)

        if server_hash is None:
            print(f"  [{i}/{len(missing)}] id={rec['id']} — could not fetch thumbnail, skipping")
            no_match.append(rec)
            continue

        # Find closest local hash
        best_path = None
        best_dist = 999
        for lpath, lhash in local_hashes.items():
            d = server_hash - lhash
            if d < best_dist:
                best_dist = d
                best_path = lpath

        filename = os.path.basename(rec['img_file'])
        if best_dist <= args.threshold:
            matched.append((rec, best_path, best_dist))
            print(f"  [{i}/{len(missing)}] ✓ dist={best_dist:2d}  {filename[:50]}")
            print(f"       → {os.path.basename(best_path)}")
        elif best_dist <= args.threshold * 2:
            uncertain.append((rec, best_path, best_dist))
            print(f"  [{i}/{len(missing)}] ? dist={best_dist:2d}  UNCERTAIN  {filename[:50]}")
            print(f"       → {os.path.basename(best_path)}")
        else:
            no_match.append(rec)
            print(f"  [{i}/{len(missing)}] ✗ dist={best_dist:2d}  NO MATCH   {filename[:50]}")

        time.sleep(0.1)

    # ── Summary ───────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"Matched:   {len(matched)}")
    print(f"Uncertain: {len(uncertain)}  (review manually — dist {args.threshold+1}–{args.threshold*2})")
    print(f"No match:  {len(no_match)}")

    if args.dry_run:
        print("\n-- DRY RUN -- no uploads performed.")
        if uncertain:
            print("\nUNCERTAIN matches (review before uploading):")
            for rec, lpath, dist in uncertain:
                print(f"  id={rec['id']:4d}  dist={dist}  server: {os.path.basename(rec['img_file'])[:50]}")
                print(f"            local:  {os.path.basename(lpath)}")
        return

    if not matched and not uncertain:
        print("Nothing confident enough to upload.")
        return

    # ── Authenticate Drive ────────────────────────────────────────────
    print("\nAuthenticating with Google Drive...")
    service = get_drive_service(creds_path)
    print("Authenticated.\n")

    # Upload matched (confident) files
    patches  = []
    failures = []

    to_upload = matched  # add uncertain here too if you want after manual review

    for rec, local_path, dist in to_upload:
        filename = os.path.basename(rec['img_file'])
        print(f"Uploading id={rec['id']}  {filename[:55]}")
        try:
            drive_url = upload_to_drive(service, local_path, filename, folder_id)
            patches.append((rec['id'], drive_url))
            print(f"  ✓ {drive_url}")
        except Exception as e:
            failures.append((rec['id'], str(e)))
            print(f"  ✗ {e}")
        time.sleep(0.3)

    # ── Write SQL patch ───────────────────────────────────────────────
    out_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), args.output)
    if patches:
        with open(out_path, 'w', encoding='utf-8') as f:
            f.write("-- SnapSmack Drive backfill patch (matched originals)\n")
            f.write(f"-- {len(patches)} records\n\n")
            for pid, url in patches:
                safe_url = url.replace("'", "\\'")
                f.write(
                    f"UPDATE snap_images SET allow_download=1, download_url='{safe_url}' "
                    f"WHERE id={pid};\n"
                )
        print(f"\nSQL patch written to:\n  {out_path}")

    print(f"\nDone. {len(patches)} uploaded, {len(failures)} failed, "
          f"{len(uncertain)} uncertain (not uploaded), {len(no_match)} unmatched.")

    if no_match:
        print(f"\nThe {len(no_match)} unmatched records have no original on disk —")
        print("run drive-backfill.py (server copy backfill) to cover those.")


if __name__ == '__main__':
    main()

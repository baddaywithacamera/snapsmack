"""
ft-batch-poster — drive-backfill.py
====================================
Backfill Google Drive links for images that were posted without Drive connected.

HOW IT WORKS:
  1. Reads a SnapSmack SQL export to find all published images with no download_url.
  2. Downloads each image directly from the live site (they're public).
  3. Uploads each to Google Drive in your existing folder.
  4. Writes a .sql patch file you run against your DB to update all records.

USAGE:
  python drive-backfill.py --sql squir871_foundtextures.sql --base-url https://foundtextures.ca

  Optional flags:
    --folder-id  DRIVE_FOLDER_ID   (reads from config.ini if omitted)
    --creds      credentials.json  (reads from config.ini if omitted)
    --start-id   N                 (resume from a specific image ID if interrupted)
    --dry-run                      (parse and list only, no uploads)
"""

import argparse
import configparser
import os
import re
import sys
import tempfile
import time

import requests


# ---------------------------------------------------------------------------
# Parse SQL export
# ---------------------------------------------------------------------------

def parse_missing(sql_path: str):
    """Return list of (id, title, img_file) for published images with no download_url."""
    with open(sql_path, 'r', encoding='utf-8') as f:
        content = f.read()

    row_re = re.compile(
        r"\((\d+),\s*'((?:[^'\\]|\\.)*)'"   # id, title
        r".*?'(img_uploads/[^']+)'"           # img_file
        r".*?'(published)'"                   # status
        r".*?,\s*(\d+),\s*'([^']*)'\s*,",    # allow_download, download_url
        re.DOTALL
    )

    missing = []
    for m in row_re.finditer(content):
        id_, title, img_file, status, allow_dl, download_url = m.groups()
        if not download_url:
            missing.append((int(id_), title, img_file))

    return missing


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

def load_config():
    """Load config.ini from next to this script."""
    base = os.path.dirname(os.path.abspath(__file__))
    cfg_path = os.path.join(base, 'config.ini')
    cfg = configparser.ConfigParser()
    cfg.read(cfg_path)
    return {
        'google_credentials': cfg.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cfg.get('google', 'drive_folder_id', fallback=''),
    }


# ---------------------------------------------------------------------------
# Drive helpers (reuse drive.py from same folder)
# ---------------------------------------------------------------------------

def get_drive_service(creds_path: str):
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import drive as drive_module
    return drive_module.authenticate(creds_path)


def upload_to_drive(service, file_path: str, filename: str, folder_id: str) -> str:
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import drive as drive_module
    return drive_module.upload(
        service=service,
        file_path=file_path,
        filename=filename,
        folder_id=folder_id or None,
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="Backfill Google Drive links for FoundTextures images.")
    parser.add_argument('--sql',        required=True,  help='Path to SQL export file')
    parser.add_argument('--base-url',   default='https://foundtextures.ca', help='Site base URL')
    parser.add_argument('--folder-id',  default='',     help='Google Drive folder ID')
    parser.add_argument('--creds',      default='',     help='Path to Google credentials.json')
    parser.add_argument('--start-id',   type=int, default=0, help='Skip images with id < this (resume)')
    parser.add_argument('--dry-run',    action='store_true', help='List only, no uploads')
    args = parser.parse_args()

    # ── Load config fallbacks ─────────────────────────────────────────
    cfg = load_config()
    creds_path = args.creds or cfg['google_credentials']
    folder_id  = args.folder_id or cfg['drive_folder_id']

    if not args.dry_run:
        if not creds_path or not os.path.isfile(creds_path):
            print(f"ERROR: credentials.json not found at: {creds_path!r}")
            print("Pass --creds path/to/credentials.json or set it in config.ini")
            sys.exit(1)

    # ── Parse SQL ─────────────────────────────────────────────────────
    print(f"Parsing {args.sql}...")
    missing = parse_missing(args.sql)
    print(f"Found {len(missing)} images missing Drive links.")

    if args.start_id:
        before = len(missing)
        missing = [r for r in missing if r[0] >= args.start_id]
        print(f"Resuming from id {args.start_id} — skipping {before - len(missing)} already done.")

    if not missing:
        print("Nothing to do.")
        return

    if args.dry_run:
        print("\n-- DRY RUN -- listing only:\n")
        for id_, title, img_file in missing[:20]:
            print(f"  [{id_}] {img_file}")
            print(f"        {title[:70]}")
        if len(missing) > 20:
            print(f"  ... and {len(missing) - 20} more")
        return

    # ── Authenticate Drive ────────────────────────────────────────────
    print("\nAuthenticating with Google Drive...")
    service = get_drive_service(creds_path)
    print("Drive authenticated.\n")

    # ── Output SQL patch file ─────────────────────────────────────────
    out_sql = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'drive-backfill-patch.sql')
    base_url = args.base_url.rstrip('/')

    session = requests.Session()
    session.headers['User-Agent'] = 'SnapSmack-Backfill/1.0'

    done    = 0
    failed  = []
    patches = []

    for idx, (id_, title, img_file) in enumerate(missing, 1):
        img_url  = f"{base_url}/{img_file}"
        filename = os.path.basename(img_file)

        print(f"[{idx}/{len(missing)}] id={id_} {filename[:60]}")

        # Download from server
        try:
            resp = session.get(img_url, timeout=30, stream=True)
            resp.raise_for_status()
        except Exception as e:
            print(f"  DOWNLOAD FAILED: {e}")
            failed.append((id_, img_file, f"download: {e}"))
            continue

        # Save to temp file
        suffix = os.path.splitext(filename)[1] or '.jpg'
        with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
            for chunk in resp.iter_content(chunk_size=65536):
                tmp.write(chunk)
            tmp_path = tmp.name

        # Upload to Drive
        try:
            drive_url = upload_to_drive(service, tmp_path, filename, folder_id)
            print(f"  ✓ {drive_url}")
            patches.append((id_, drive_url))
            done += 1
        except Exception as e:
            print(f"  DRIVE UPLOAD FAILED: {e}")
            failed.append((id_, img_file, f"drive: {e}"))
        finally:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

        # Write patch file incrementally so we don't lose progress if interrupted
        if patches:
            with open(out_sql, 'w', encoding='utf-8') as f:
                f.write("-- SnapSmack Drive backfill patch\n")
                f.write("-- Generated by drive-backfill.py\n")
                f.write("-- Run this against your foundtextures DB\n\n")
                for pid, url in patches:
                    safe_url = url.replace("'", "\\'")
                    f.write(
                        f"UPDATE snap_images SET allow_download=1, download_url='{safe_url}' "
                        f"WHERE id={pid};\n"
                    )

        # Polite pause to avoid hammering Drive API
        time.sleep(0.3)

    # ── Summary ───────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"Done. {done} uploaded, {len(failed)} failed.")
    if patches:
        print(f"\nSQL patch written to:\n  {out_sql}")
        print("\nRun that file against your DB to apply the Drive links.")
    if failed:
        print(f"\nFailed ({len(failed)}):")
        for id_, f, reason in failed:
            print(f"  id={id_} {reason}")
        print("\nRe-run with --start-id to retry from a specific point.")


if __name__ == '__main__':
    main()

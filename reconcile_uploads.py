#!/usr/bin/env python3
"""
reconcile_uploads.py — find images that did NOT land on the server.

Compares a source image folder (everything you attempted to post) against a
mysqldump of the site DB. The server records each image's ORIGINAL filename in
snap_images.img_source_file (and img_file keeps it too), so:

    filename present anywhere in the dump  ==  it landed
    filename absent from the dump          ==  it never uploaded

Set-based, so a name that appears multiple times in the dump is handled fine.

USAGE (Windows CMD or git-bash — plain Python, no FUSE):

    # DRY RUN — list what's missing (nothing is touched):
    python reconcile_uploads.py . "snapsmack_full_fauxlaroid.fyi_2026-07-01_03-16.sql"

    # Then MOVE the misses into a retry folder:
    python reconcile_uploads.py . "snapsmack_full_fauxlaroid.fyi_2026-07-01_03-16.sql" --move "Failed Uploads"

'.' is the current folder. Run it from the folder that holds the images + dump,
or pass absolute paths for both args.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""
import argparse
import os
import re
import shutil
import sys

# Original filenames look like 20260624_21_39_49.jpg, with an occasional
# " (1)" dedup suffix. Also accept .jpeg. Case-insensitive.
FN_RE = re.compile(r"\d{8}[_-]?\d{2}[_-]?\d{2}[_-]?\d{2}(?: \(\d+\))?\.jpe?g", re.IGNORECASE)


def landed_from_dump(dump_path):
    with open(dump_path, encoding="utf-8", errors="replace") as f:
        text = f.read()
    return {m.lower() for m in FN_RE.findall(text)}


def main():
    ap = argparse.ArgumentParser(description="Find images that did not land on the server.")
    ap.add_argument("folder", help="source image folder (everything you attempted to post)")
    ap.add_argument("dump", help="path to the .sql mysqldump")
    ap.add_argument("--move", metavar="DEST", help="MOVE the misses into DEST (omit for a dry run)")
    args = ap.parse_args()

    if not os.path.isdir(args.folder):
        sys.exit(f"Folder not found: {args.folder}")
    if not os.path.isfile(args.dump):
        sys.exit(f"Dump not found: {args.dump}")

    landed = landed_from_dump(args.dump)
    files = sorted(
        f for f in os.listdir(args.folder)
        if f.lower().endswith((".jpg", ".jpeg"))
    )
    missing = [f for f in files if f.lower() not in landed]

    print(f"Distinct names in dump : {len(landed)}")
    print(f"Images in folder       : {len(files)}")
    print(f"Did NOT land           : {len(missing)}")
    print("-" * 48)
    for f in missing:
        print(f)

    if args.move:
        os.makedirs(args.move, exist_ok=True)
        moved = 0
        for f in missing:
            src = os.path.join(args.folder, f)
            if os.path.isfile(src):
                shutil.move(src, os.path.join(args.move, f))
                moved += 1
        print("-" * 48)
        print(f"Moved {moved} file(s) to: {args.move}")
        print("Point SUMNABATCH at that folder to re-post only the misses.")


if __name__ == "__main__":
    main()
# ===== SNAPSMACK EOF =====

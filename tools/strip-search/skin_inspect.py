#!/usr/bin/env python3
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""
STRIP SEARCH -- SnapSmack skin frisker (airgap bench tool).

Unzip a skin bundle on an airgapped box and search it BEFORE it ever
touches a CMS. A virus scanner catches known malware; it will NOT catch a
hand-written PHP backdoor tucked into a skin -- to AV that's just a text
file. So this tool's real job is loud: it flags ANY file that can run
code, catches code hiding in a file that lies about its extension
(a <?php buried in a .css or .png), rejects zip-slip paths, and prints a
sha256 for every file so you can diff what you scanned against what you install.

Pure Python standard library. No pip, no network. Python 3.8+.

Usage:
    python skin_inspect.py SKIN.zip
    python skin_inspect.py SKIN.zip --out ./review

Then: point your scanner at the --out folder, read anything it flags as CODE,
and only THEN hand the clean folder to the CMS.

Exit code: 0 = no code found, 2 = code found (needs your eyes), 1 = bad input.
"""
import argparse
import hashlib
import os
import sys
import zipfile

# Files that are executable/config by their name.
CODE_EXT = {
    '.php', '.phtml', '.php3', '.php4', '.php5', '.php7', '.phps', '.pht', '.phar',
    '.sh', '.bash', '.zsh', '.py', '.pl', '.rb', '.cgi', '.lua',
    '.exe', '.dll', '.so', '.dylib', '.bat', '.cmd', '.com', '.scr',
    '.ps1', '.vbs', '.js', '.mjs', '.jar', '.wsf', '.hta',
}
CODE_BASENAMES = {'.htaccess', '.htpasswd', 'web.config'}

# Byte markers that mean "this can execute", even inside a file with a harmless
# extension. Catches code smuggled into a .css/.png/.txt that a skin might include.
CODE_MARKERS = [b'<?php', b'<?=', b'<?\n', b'<?\r', b'<?\t', b'<? ',
                b'#!/', b'<script language="php"', b'<%']

SNIFF_BYTES = 128 * 1024          # how much of each file to sniff for code markers
MAX_UNCOMPRESSED = 200 * 1024 * 1024   # zip-bomb guard: 200 MB total

def sha256_file(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 16), b''):
            h.update(chunk)
    return h.hexdigest()

def unsafe_entry(name):
    """Zip-slip / absolute-path / null-byte guard on an archive entry name."""
    n = name.replace('\\', '/')
    parts = n.split('/')
    return (
        n == '' or '\0' in n or n.startswith('/')
        or (len(n) > 1 and n[1] == ':')     # Windows drive-absolute (C:/...)
        or '..' in parts
    )

def extract(zip_path, out_dir):
    skipped = []
    try:
        zf = zipfile.ZipFile(zip_path)
    except zipfile.BadZipFile:
        raise SystemExit("BAD INPUT: not a valid zip archive.")
    with zf as z:
        total = sum(max(0, i.file_size) for i in z.infolist())
        if total > MAX_UNCOMPRESSED:
            raise SystemExit(
                f"REFUSING: uncompressed size {total:,} bytes exceeds "
                f"{MAX_UNCOMPRESSED:,} (possible zip bomb). Inspect by hand.")
        for info in z.infolist():
            if unsafe_entry(info.filename):
                skipped.append(info.filename)
                continue
            z.extract(info, out_dir)
    return skipped

def classify(path):
    """Return ('CODE', reason) if the file can run, else ('DATA', '')."""
    ext = os.path.splitext(path)[1].lower()
    base = os.path.basename(path).lower()
    if ext in CODE_EXT:
        return 'CODE', f'executable by extension ({ext})'
    if base in CODE_BASENAMES:
        return 'CODE', f'server-config file ({base})'
    try:
        with open(path, 'rb') as f:
            head = f.read(SNIFF_BYTES).lower()
    except OSError:
        return 'DATA', ''
    for m in CODE_MARKERS:
        if m in head:
            shown = m.decode('latin-1').strip() or m.decode('latin-1')
            return 'CODE', f'code marker {shown!r} inside a {ext or "no-extension"} file'
    return 'DATA', ''

def walk(out_dir):
    rows = []
    for root, _dirs, files in os.walk(out_dir):
        for fn in files:
            full = os.path.join(root, fn)
            rel = os.path.relpath(full, out_dir).replace('\\', '/')
            kind, reason = classify(full)
            rows.append({
                'rel': rel,
                'bytes': os.path.getsize(full),
                'sha256': sha256_file(full),
                'kind': kind,
                'reason': reason,
            })
    rows.sort(key=lambda r: (r['kind'] != 'CODE', r['rel']))  # code first
    return rows

def render_report(zip_path, out_dir, rows, skipped):
    L = []
    L.append("=" * 72)
    L.append("STRIP SEARCH -- skin frisk report")
    L.append("=" * 72)
    L.append(f"bundle : {os.path.abspath(zip_path)}")
    L.append(f"bundle sha256 : {sha256_file(zip_path)}")
    L.append(f"unpacked to  : {os.path.abspath(out_dir)}")
    L.append(f"files        : {len(rows)}")
    code = [r for r in rows if r['kind'] == 'CODE']

    if skipped:
        L.append("")
        L.append(f"!! REJECTED {len(skipped)} UNSAFE PATH(S) (zip-slip / absolute / null):")
        for s in skipped:
            L.append(f"     {s}")
        L.append("   A skin has no business shipping paths like these. Be suspicious.")

    L.append("")
    if code:
        L.append(f"##  NEEDS YOUR EYES: {len(code)} FILE(S) CAN RUN CODE  ##")
        L.append("   A skin should be a document, not a program. READ each of these")
        L.append("   before this bundle goes anywhere near a CMS:")
        for r in code:
            L.append(f"     [CODE] {r['rel']}  ({r['bytes']:,} B)  -- {r['reason']}")
    else:
        L.append("OK: no executable code found.")
        L.append("   Still eyeball manifest.json + the CSS, but there is nothing here")
        L.append("   that can run on your server.")

    L.append("")
    L.append("--- full inventory (sha256) ---")
    for r in rows:
        tag = 'CODE' if r['kind'] == 'CODE' else 'data'
        L.append(f"  {tag}  {r['sha256']}  {r['bytes']:>10,}  {r['rel']}")
    L.append("=" * 72)
    verdict = "NEEDS REVIEW -- code present" if code else "no code found"
    L.append(f"VERDICT: {verdict}")
    L.append("=" * 72)
    return "\n".join(L), bool(code)

def main():
    ap = argparse.ArgumentParser(description="Frisk a SnapSmack skin bundle before install.")
    ap.add_argument("zip", help="the skin .zip to inspect")
    ap.add_argument("--out", default=None, help="folder to unpack into (default: <zip>_review)")
    args = ap.parse_args()

    if not os.path.isfile(args.zip):
        raise SystemExit(f"BAD INPUT: no such file: {args.zip}")

    out_dir = args.out or (os.path.splitext(args.zip)[0] + "_review")
    os.makedirs(out_dir, exist_ok=True)

    skipped = extract(args.zip, out_dir)
    rows = walk(out_dir)
    report, has_code = render_report(args.zip, out_dir, rows, skipped)

    print(report)
    report_path = os.path.join(out_dir, "STRIP-SEARCH-REPORT.txt")
    try:
        with open(report_path, "w", encoding="utf-8") as f:
            f.write(report + "\n")
        print(f"\n(report written to {report_path})")
    except OSError:
        pass

    sys.exit(2 if has_code else 0)

if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====

#!/usr/bin/env python3
"""
SNAPSMACK - Pre-commit integrity scanner
tools/check-eof.py

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
Missing or different = truncated/corrupted. Restore before saving.

Checks every tracked source file (PHP/JS/CSS/HTML/HTM/MD/SQL/PY/SH) for:
  1. Long-form 'SNAPSMACK EOF' marker on the last non-empty line.
  2. SNAPSMACK_EOF_HEADER tag within the first 8KB (header block near top
     names or describes the expected bottom marker — file self-describes).
  3. Null bytes anywhere in the file (truncation/encoding artifact).
  4. Structural \\r\\n corruption (literal backslash-r backslash-n outside
     string literals).

Excluded paths (third-party / build artifacts) are listed in
EXCLUDED_PATTERNS below.

Run from repo root before every commit:
    python3 tools/check-eof.py

Exit code 0 = all clear. Non-zero = failures found; do not commit.
"""

import subprocess
import sys
import re
import os
import fnmatch

def read_nocache(abs_path: str) -> bytes:
    """Read a file bypassing the OS page cache.

    On CIFS/SMB mounts the kernel page cache can return stale (pre-truncation)
    content even after a write — the cached bytes look correct while the actual
    bytes on the server are truncated.  Using dd with iflag=direct forces the
    read to come from the server rather than the cache.

    Falls back to a normal read on filesystems that don't support O_DIRECT
    (e.g. tmpfs, some virtual mounts) so the scanner stays usable everywhere.
    """
    # dd iflag=direct bypasses the OS page cache on CIFS/SMB mounts.
    # Skip on Windows — dd either absent or hangs on iflag=direct.
    if os.name != 'nt':
        result = subprocess.run(
            ['dd', f'if={abs_path}', 'bs=65536', 'iflag=direct'],
            capture_output=True,
            timeout=5,
        )
        if result.returncode == 0:
            return result.stdout
    # Fallback: normal read — used on Windows and non-CIFS Linux mounts.
    with open(abs_path, 'rb') as fh:
        return fh.read()


REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Per extension: canonical long-form bottom marker. Short forms are no longer
# accepted (migration complete).
EOF_MARKERS = {
    '.php':  b'// ===== SNAPSMACK EOF =====',
    '.js':   b'// ===== SNAPSMACK EOF =====',
    '.css':  b'/* ===== SNAPSMACK EOF ===== */',
    '.html': b'<!-- ===== SNAPSMACK EOF ===== -->',
    '.htm':  b'<!-- ===== SNAPSMACK EOF ===== -->',
    '.md':   b'<!-- ===== SNAPSMACK EOF ===== -->',
    '.sql':  b'-- ===== SNAPSMACK EOF =====',
    '.py':   b'# ===== SNAPSMACK EOF =====',
    '.sh':   b'# ===== SNAPSMACK EOF =====',
}

# Header tag required near top of every source file (within first 8KB).
# The header block names (or describes) the expected bottom marker so a
# future Claude session reading the file under context strain doesn't have
# to recall the convention from external docs — file self-describes.
HEADER_TAG = b'SNAPSMACK_EOF_HEADER'
HEADER_SCAN_BYTES = 8192

EXTENSIONS = set(EOF_MARKERS.keys())

# Paths excluded from the scan (third-party / build artifacts).
# Mirrors the list documented in repo CLAUDE.md.
EXCLUDED_PATTERNS = [
    'smack-central/*',
    'licenses/*',
    'vendor/*',
    'node_modules/*',
    '*.min.js',
    '*.min.css',
    'assets/js/fjGallery*',
]

# Regex to detect literal \r\n sequences as bytes (backslash-r backslash-n)
# i.e. the four bytes: 0x5c 0x72 0x5c 0x6e
LITERAL_CRLF = re.compile(rb'\\r\\n')

# Patterns that indicate the line legitimately mentions \r\n (string literal,
# regex, comment, markdown backtick, etc.) — i.e. NOT structural corruption.
SAFE_LINE_PATTERNS = [
    # Inside quotes (PHP/JS/Py/etc string literal)
    re.compile(rb'''(['"])[^'"]*\\r\\n'''),
    # PHP-specific: implode/header/MIME contexts
    re.compile(rb'implode\s*\('),
    re.compile(rb'->addHeaderLine\b'),
    re.compile(rb'Content-Type|MIME|multipart'),
    # Inside markdown / shell / Python comment backticks: `\r\n`
    re.compile(rb'`[^`]*\\r\\n[^`]*`'),
    # Python/shell/SQL line comments mentioning \r\n
    re.compile(rb'^\s*#.*\\r\\n'),
    re.compile(rb'^\s*--.*\\r\\n'),
    # Block-comment continuation lines (PHP/JS/CSS): start with * after spaces
    re.compile(rb'^\s*\*.*\\r\\n'),
    # Regex patterns/raw strings explicitly referencing \\r\\n
    re.compile(rb'rb?[\'"][^\'"]*\\\\r\\\\n'),
    # Markdown prose mentioning literal "\r\n" (very common in changelogs)
    re.compile(rb'literal.{0,20}\\r\\n', re.IGNORECASE),
    re.compile(rb'\\r\\n.{0,20}corruption', re.IGNORECASE),
]

def is_likely_string_context(line: bytes) -> bool:
    for pat in SAFE_LINE_PATTERNS:
        if pat.search(line):
            return True
    return False


def is_excluded(rel_path: str) -> bool:
    rp = rel_path.replace('\\', '/')
    for pat in EXCLUDED_PATTERNS:
        if fnmatch.fnmatch(rp, pat):
            return True
    return False


def get_tracked_files() -> list[str]:
    """Return repo-relative paths of all source files.

    Tries git ls-files first. If the git index is corrupt (common on CIFS
    mounts), falls back to a plain filesystem walk so the scanner still runs.
    The fallback skips .git/, vendor/, and node_modules/ automatically.
    """
    result = subprocess.run(
        ['git', 'ls-files'],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True
    )
    if result.returncode == 0 and result.stdout.strip():
        return result.stdout.splitlines()

    # Fallback: walk the filesystem.
    print("WARNING: git ls-files unavailable (index corrupt?) — falling back to filesystem walk.")
    skip_dirs = {'.git', 'vendor', 'node_modules', '__pycache__'}
    files = []
    for dirpath, dirnames, filenames in os.walk(REPO_ROOT):
        # Prune in-place so os.walk won't descend into skipped dirs.
        dirnames[:] = [d for d in dirnames if d not in skip_dirs]
        for fname in filenames:
            ext = os.path.splitext(fname)[1].lower()
            if ext not in EXTENSIONS:
                continue
            abs_path = os.path.join(dirpath, fname)
            rel_path = os.path.relpath(abs_path, REPO_ROOT).replace('\\', '/')
            files.append(rel_path)
    return files


def check_file(rel_path: str) -> list[str]:
    ext = os.path.splitext(rel_path)[1].lower()
    if ext not in EXTENSIONS:
        return []

    abs_path = os.path.join(REPO_ROOT, rel_path)
    try:
        data = read_nocache(abs_path)
    except FileNotFoundError:
        return [f"  MISSING: file tracked in git but not on disk"]

    issues = []

    # 1. Null bytes
    null_count = data.count(b'\x00')
    if null_count:
        issues.append(f"  NULL BYTES: {null_count} found")

    # 2. EOF marker (long form required)
    expected_marker = EOF_MARKERS[ext]
    lines = data.rstrip(b'\r\n').split(b'\n')
    last_nonempty = None
    for line in reversed(lines):
        stripped = line.rstrip(b'\r')
        if stripped.strip():
            last_nonempty = stripped
            break
    if last_nonempty is None or expected_marker not in last_nonempty:
        marker_str = expected_marker.decode()
        issues.append(f"  MISSING EOF MARKER: expected '{marker_str}' as last non-empty line")

    # 3. SNAPSMACK_EOF_HEADER tag near top of file
    if HEADER_TAG not in data[:HEADER_SCAN_BYTES]:
        issues.append(f"  MISSING SNAPSMACK_EOF_HEADER tag in first {HEADER_SCAN_BYTES} bytes")

    # 3. Structural \r\n corruption
    # 4. Structural \\r\\n corruption
    for i, line in enumerate(data.split(b'\n'), 1):
        if LITERAL_CRLF.search(line) and not is_likely_string_context(line):
            snippet = line.strip()[:80]
            issues.append(f"  SUSPICIOUS \\r\\n on line {i}: {snippet!r}")

    return issues


def main():
    files = get_tracked_files()
    checked = 0
    failed = 0
    skipped_excluded = 0

    print(f"Scanning {len(files)} tracked files in {REPO_ROOT}\n")

    for rel_path in sorted(files):
        ext = os.path.splitext(rel_path)[1].lower()
        if ext not in EXTENSIONS:
            continue
        if is_excluded(rel_path):
            skipped_excluded += 1
            continue
        issues = check_file(rel_path)
        checked += 1
        if issues:
            failed += 1
            print(f"FAIL  {rel_path}")
            for issue in issues:
                print(issue)

    print(f"\n{'='*60}")
    print(
        f"Checked {checked} files across {len(EXTENSIONS)} extensions. "
        f"{skipped_excluded} excluded by path. {failed} failed."
    )
    if failed:
        print("Do NOT commit until all failures are resolved.")
        return 1
    print("All clear.")
    return 0


if __name__ == '__main__':
    sys.exit(main())

# ===== SNAPSMACK EOF =====

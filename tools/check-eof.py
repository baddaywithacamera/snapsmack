#!/usr/bin/env python3
"""
SNAPSMACK - Pre-commit integrity scanner
tools/check-eof.py

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
Missing or different = truncated/corrupted. Restore before saving.

Checks every tracked source file (PHP/JS/CSS/HTML/HTM/MD/SQL/PY/SH) for:
  1. EOF marker on the last non-empty line. Long-form 'SNAPSMACK EOF' is the
     target convention; short-form ('// EOF', '/* EOF */', '# EOF', '-- EOF',
     '<!-- EOF -->') is temporarily accepted during the long-form migration.
     A later phase drops short-form acceptance and also requires the header.
  2. Null bytes anywhere in the file (truncation/encoding artifact).
  3. Structural \\r\\n corruption (literal backslash-r backslash-n outside
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

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Per extension: ordered list of acceptable substring markers. The long form
# is canonical; short form is accepted during the migration. Long form is
# listed first so error messages display it as the expected target.
EOF_MARKERS = {
    '.php':  [b'// ===== SNAPSMACK EOF =====', b'// EOF'],
    '.js':   [b'// ===== SNAPSMACK EOF =====', b'// EOF'],
    '.css':  [b'/* ===== SNAPSMACK EOF ===== */', b'/* EOF */'],
    '.html': [b'<!-- ===== SNAPSMACK EOF ===== -->', b'<!-- EOF -->'],
    '.htm':  [b'<!-- ===== SNAPSMACK EOF ===== -->', b'<!-- EOF -->'],
    '.md':   [b'<!-- ===== SNAPSMACK EOF ===== -->', b'<!-- EOF -->'],
    '.sql':  [b'-- ===== SNAPSMACK EOF =====', b'-- EOF'],
    '.py':   [b'# ===== SNAPSMACK EOF =====', b'# EOF'],
    '.sh':   [b'# ===== SNAPSMACK EOF =====', b'# EOF'],
}

# Active scan scope. EOF_MARKERS is pre-populated for all target extensions,
# but during the long-form migration the scanner only enforces markers on the
# extensions that already had them under the old convention. The remaining
# types (.html .htm .md .sql .py .sh) are added to this set in the migration
# wrap-up phase, after marker+header has been applied to those files.
EXTENSIONS = {'.php', '.js', '.css'}

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

# Patterns that indicate we're inside a PHP/JS string — legitimate use of \r\n
# We do a simple heuristic: if the line also contains a string delimiter or
# known-safe keywords, we skip it. For now we flag all occurrences and let
# the human decide; false positives in string literals are noted.
SAFE_LINE_PATTERNS = [
    # PHP string contexts that legitimately use \r\n
    re.compile(rb'''(['"])[^'"]*\\r\\n'''),          # inside quotes
    re.compile(rb'implode\s*\('),                     # implode("\r\n", ...)
    re.compile(rb'->addHeaderLine\b'),
    re.compile(rb'Content-Type|MIME|multipart'),
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
    result = subprocess.run(
        ['git', 'ls-files'],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True
    )
    if result.returncode != 0:
        print("ERROR: git ls-files failed:", result.stderr)
        sys.exit(1)
    return result.stdout.splitlines()


def check_file(rel_path: str) -> list[str]:
    ext = os.path.splitext(rel_path)[1].lower()
    if ext not in EXTENSIONS:
        return []

    abs_path = os.path.join(REPO_ROOT, rel_path)
    try:
        data = open(abs_path, 'rb').read()
    except FileNotFoundError:
        return [f"  MISSING: file tracked in git but not on disk"]

    issues = []

    # 1. Null bytes
    null_count = data.count(b'\x00')
    if null_count:
        issues.append(f"  NULL BYTES: {null_count} found")

    # 2. EOF marker (accept any of the configured markers for this extension)
    accepted_markers = EOF_MARKERS[ext]
    lines = data.rstrip(b'\r\n').split(b'\n')
    last_nonempty = None
    for line in reversed(lines):
        stripped = line.rstrip(b'\r')
        if stripped.strip():
            last_nonempty = stripped
            break
    if last_nonempty is None or not any(m in last_nonempty for m in accepted_markers):
        long_form = accepted_markers[0].decode()
        issues.append(f"  MISSING EOF MARKER: expected '{long_form}' as last non-empty line")

    # 3. Structural \r\n corruption
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

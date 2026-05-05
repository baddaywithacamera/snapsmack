#!/usr/bin/env python3
"""
SNAPSMACK - EOF marker long-form migration tool
tools/migrate-eof-marker.py

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
Missing or different = truncated/corrupted. Restore before saving.

One-shot migration that converts every tracked source file to:
  - long-form bottom marker  ('===== SNAPSMACK EOF =====')
  - SNAPSMACK_EOF_HEADER block near the top quoting that exact bottom marker

Two-pass design:
  1. Dry-run (default). Walks git ls-files, classifies each file, prints what
     WOULD change. Zero writes.
  2. Real run (--apply). Rewrites via atomic temp+rename. After every write
     the script re-reads the file and asserts:
       a) zero null bytes
       b) byte count strictly increased
       c) SNAPSMACK_EOF_HEADER tag present
       d) last non-empty line is the expected long marker
     If any assertion fails, the script aborts with the failing path so we
     never leave a half-migrated tree.

Skip rule: any file that already contains SNAPSMACK_EOF_HEADER AND ends
with the correct long marker is treated as already-migrated and is left
alone. (Part A's three files satisfy this.)

Files with weird state (PHP/JS/CSS missing the short-form marker, or any
mid-state inconsistency) are logged as NEEDS REVIEW and NOT touched.

Usage from repo root:
    python3 tools/migrate-eof-marker.py            # dry-run, full repo
    python3 tools/migrate-eof-marker.py --apply    # actually rewrite
    python3 tools/migrate-eof-marker.py --filter 'skins/*'   # subset

Exit code 0 = success. Non-zero = at least one failure.
"""

import argparse
import fnmatch
import os
import re
import subprocess
import sys

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Per extension: short-form marker (legacy) and long-form marker (canonical).
# PHP has two modes — HTML-mode (file ends after ?>) and logic-mode (ends in PHP block).
SHORT_LOGIC = b'// EOF'
SHORT_HTML  = b'<?php // EOF'
LONG_LOGIC  = b'// ===== SNAPSMACK EOF ====='
LONG_HTML   = b'<?php // ===== SNAPSMACK EOF ====='

EXT_CONFIG = {
    '.php':  {'short': [SHORT_HTML, SHORT_LOGIC], 'long_logic': LONG_LOGIC, 'long_html': LONG_HTML},
    '.js':   {'short': [b'// EOF'],     'long': b'// ===== SNAPSMACK EOF ====='},
    '.css':  {'short': [b'/* EOF */'],  'long': b'/* ===== SNAPSMACK EOF ===== */'},
    '.html': {'short': [b'<!-- EOF -->'], 'long': b'<!-- ===== SNAPSMACK EOF ===== -->'},
    '.htm':  {'short': [b'<!-- EOF -->'], 'long': b'<!-- ===== SNAPSMACK EOF ===== -->'},
    '.md':   {'short': [b'<!-- EOF -->'], 'long': b'<!-- ===== SNAPSMACK EOF ===== -->'},
    '.sql':  {'short': [b'-- EOF'],     'long': b'-- ===== SNAPSMACK EOF ====='},
    '.py':   {'short': [b'# EOF'],      'long': b'# ===== SNAPSMACK EOF ====='},
    '.sh':   {'short': [b'# EOF'],      'long': b'# ===== SNAPSMACK EOF ====='},
}

EXCLUDED_PATTERNS = [
    'smack-central/*', 'licenses/*', 'vendor/*', 'node_modules/*',
    '*.min.js', '*.min.css', 'assets/js/fjGallery*',
]

EXTENSIONS = set(EXT_CONFIG.keys())


def is_excluded(rel_path):
    rp = rel_path.replace('\\', '/')
    return any(fnmatch.fnmatch(rp, p) for p in EXCLUDED_PATTERNS)


def get_tracked_files():
    out = subprocess.check_output(['git', 'ls-files'], cwd=REPO_ROOT, text=True)
    return out.splitlines()


def last_nonempty_line(data):
    """Return last non-empty line as bytes (no trailing CR/LF)."""
    for ln in reversed(data.rstrip(b'\r\n').split(b'\n')):
        s = ln.rstrip(b'\r')
        if s.strip():
            return s
    return b''


def detect_php_mode(data):
    """HTML mode if last non-empty line shows the HTML-mode marker."""
    last = last_nonempty_line(data)
    if SHORT_HTML in last or LONG_HTML in last:
        return 'html'
    if SHORT_LOGIC in last or LONG_LOGIC in last:
        return 'logic'
    # Fallback: heuristic on file ending — does the body end after ?>
    # by scanning for the last non-comment occurrence of '?>' before tail
    if b'?>' in data[-200:]:
        return 'html'
    return 'logic'


def expected_long_marker_bytes(ext, data):
    cfg = EXT_CONFIG[ext]
    if ext == '.php':
        return LONG_HTML if detect_php_mode(data) == 'html' else LONG_LOGIC
    return cfg['long']


def build_header_block(ext, long_marker_str):
    """Build the SNAPSMACK_EOF_HEADER block for this ext, quoting the marker."""
    if ext == '.php' or ext == '.js':
        return (
            "/**\n"
            " * SNAPSMACK_EOF_HEADER\n"
            f" *     {long_marker_str}\n"
            " * Last non-empty line of this file MUST match the line above.\n"
            " * Missing or different = truncated/corrupted. Restore before saving.\n"
            " */\n"
        )
    if ext == '.css':
        # The literal CSS marker contains '*/' which would close an outer /* */.
        # Use a multi-line block but quote the marker on its own line carefully:
        # split '*/' so it doesn't terminate the outer comment.
        return (
            "/*\n"
            " * SNAPSMACK_EOF_HEADER\n"
            " * Last non-empty line of this file MUST be (literally):\n"
            f" *     {long_marker_str}\n"
            " * Missing or different = truncated/corrupted. Restore before saving.\n"
            " */\n"
        ).replace('*/', '*/').replace('===== */', '===== *' + '/')  # passthrough; see below
    if ext in ('.html', '.htm', '.md'):
        # HTML comments cannot nest and the canonical marker contains '-->',
        # so the header cannot literally quote it. Describe structurally.
        # Source of truth: tools/check-eof.py EOF_MARKERS for this extension.
        return (
            "<!--\n"
            "  SNAPSMACK_EOF_HEADER\n"
            "  Last non-empty line of this file MUST be the canonical EOF\n"
            "  marker for this file type: an HTML comment containing five\n"
            "  equals, space, the literal string 'SNAPSMACK EOF', space, five\n"
            "  equals.\n"
            "  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)\n"
            "  Missing or different = truncated/corrupted. Restore before saving.\n"
            "-->\n"
        )
    if ext == '.sql':
        return (
            "-- SNAPSMACK_EOF_HEADER\n"
            f"--     {long_marker_str}\n"
            "-- Last non-empty line of this file MUST match the line above.\n"
            "-- Missing or different = truncated/corrupted. Restore before saving.\n"
        )
    if ext in ('.py', '.sh'):
        return (
            "# SNAPSMACK_EOF_HEADER\n"
            f"#     {long_marker_str}\n"
            "# Last non-empty line of this file MUST match the line above.\n"
            "# Missing or different = truncated/corrupted. Restore before saving.\n"
        )
    raise ValueError(f"no header for {ext}")


# CSS header has a special problem: the bottom marker '/* ===== SNAPSMACK EOF ===== */'
# contains '*/'. Quoting that literal inside another /* */ block would prematurely
# close the outer comment. We work around it by quoting the marker with a zero-width
# split: '/* ===== SNAPSMACK EOF ===== *' + '/' so the source contains *exactly*
# the same characters but the parser doesn't see a comment terminator.
# Implemented via direct string concat below.
def build_header_block_css(long_marker_str):
    # CSS /* */ comments cannot nest and have no escape mechanism, so the
    # header cannot literally quote the bottom marker (it contains '*/').
    # Describe the marker structurally instead. Source of truth for the exact
    # bytes: tools/check-eof.py EOF_MARKERS['.css'].
    return (
        "/*\n"
        " * SNAPSMACK_EOF_HEADER\n"
        " * Last non-empty line of this file MUST be the canonical CSS EOF\n"
        " * marker: slash-star, space, five equals, space, the literal string\n"
        " * 'SNAPSMACK EOF', space, five equals, space, star-slash.\n"
        " * (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS['.css'].)\n"
        " * Missing or different = truncated/corrupted. Restore before saving.\n"
        " */\n"
    )


def find_php_header_insertion_offset(data):
    """Insert after first /** ... */ docblock following <?php; else right after <?php."""
    # locate <?php near top
    m = re.match(rb'^<\?php\b[^\n]*\n', data)
    if not m:
        return 0  # no <?php — insert at top (very unusual)
    pos = m.end()
    # Look for /** on first non-whitespace line after <?php
    rest = data[pos:]
    m2 = re.match(rb'\s*/\*\*', rest)
    if m2:
        # Find the matching */
        end_idx = rest.find(b'*/', m2.end())
        if end_idx == -1:
            return pos  # malformed docblock — insert after <?php
        # Advance past the */ and the following newline
        absolute_end = pos + end_idx + 2
        # consume trailing newline if any
        if absolute_end < len(data) and data[absolute_end:absolute_end+1] == b'\n':
            absolute_end += 1
        return absolute_end
    return pos


def find_js_header_insertion_offset(data):
    """Insert after first /** ... */ block at top; else top."""
    m = re.match(rb'\s*/\*\*', data)
    if m:
        end_idx = data.find(b'*/', m.end())
        if end_idx == -1:
            return 0
        absolute_end = end_idx + 2
        if absolute_end < len(data) and data[absolute_end:absolute_end+1] == b'\n':
            absolute_end += 1
        return absolute_end
    return 0


def find_css_header_insertion_offset(data):
    """Insert after @charset and/or first /* ... */ block, else top."""
    pos = 0
    m = re.match(rb'\s*@charset\s+[^;]+;\s*\n?', data)
    if m:
        pos = m.end()
    # Look for first /* ... */ block after charset (or at top)
    rest = data[pos:]
    m2 = re.match(rb'\s*/\*', rest)
    if m2:
        end_idx = data.find(b'*/', pos + m2.end())
        if end_idx != -1:
            absolute_end = end_idx + 2
            if absolute_end < len(data) and data[absolute_end:absolute_end+1] == b'\n':
                absolute_end += 1
            return absolute_end
    return pos


def find_html_header_insertion_offset(data):
    """Insert after <!DOCTYPE ...> if present, else top."""
    m = re.match(rb'\s*<!DOCTYPE[^>]*>\s*\n?', data, re.IGNORECASE)
    if m:
        return m.end()
    return 0


def find_md_header_insertion_offset(data):
    return 0


def find_sql_header_insertion_offset(data):
    return 0


def find_py_header_insertion_offset(data):
    """Insert after shebang and module docstring."""
    pos = 0
    if data.startswith(b'#!'):
        nl = data.find(b'\n', pos)
        if nl != -1:
            pos = nl + 1
    # Skip blank lines
    while pos < len(data) and data[pos:pos+1] in (b'\n', b'\r'):
        pos += 1
    # Module docstring? """...""" or '''...'''
    rest = data[pos:]
    m = re.match(rb'(\"\"\"|\'\'\')', rest)
    if m:
        delim = m.group(1)
        end_idx = rest.find(delim, m.end())
        if end_idx != -1:
            absolute_end = pos + end_idx + len(delim)
            if absolute_end < len(data) and data[absolute_end:absolute_end+1] == b'\n':
                absolute_end += 1
            return absolute_end
    return pos


def find_sh_header_insertion_offset(data):
    """Insert after shebang."""
    if data.startswith(b'#!'):
        nl = data.find(b'\n')
        if nl != -1:
            return nl + 1
    return 0


def find_header_insertion_offset(ext, data):
    return {
        '.php':  find_php_header_insertion_offset,
        '.js':   find_js_header_insertion_offset,
        '.css':  find_css_header_insertion_offset,
        '.html': find_html_header_insertion_offset,
        '.htm':  find_html_header_insertion_offset,
        '.md':   find_md_header_insertion_offset,
        '.sql':  find_sql_header_insertion_offset,
        '.py':   find_py_header_insertion_offset,
        '.sh':   find_sh_header_insertion_offset,
    }[ext](data)


def replace_or_append_bottom_marker(data, ext, long_marker):
    """Replace existing short-form marker with long, or append long if absent."""
    cfg = EXT_CONFIG[ext]
    short_forms = cfg['short']

    # Find last non-empty line and its byte range
    # Strategy: find the last newline followed by the short marker, replace the
    # whole trailing region from that point.
    # But actually simpler: find the offset of the last non-empty line content
    # and replace it.

    # Walk from end to find last non-empty line
    end = len(data)
    # strip trailing \r\n
    while end > 0 and data[end-1:end] in (b'\n', b'\r'):
        end -= 1
    line_end = end
    line_start = data.rfind(b'\n', 0, line_end) + 1  # 0 if not found

    last_line = data[line_start:line_end].rstrip(b'\r')

    matched_short = None
    for s in short_forms:
        if s in last_line:
            matched_short = s
            break

    if matched_short:
        # Replace the last line entirely with the long marker
        return data[:line_start] + long_marker + b'\n', 'replaced'
    else:
        # No short marker on last line — append long marker
        # Ensure file ends with newline before appending
        if data and data[-1:] != b'\n':
            return data + b'\n' + long_marker + b'\n', 'appended'
        return data + long_marker + b'\n', 'appended'


def file_already_migrated(data, ext, expected_long):
    """True if SNAPSMACK_EOF_HEADER tag present AND last non-empty line is expected long."""
    if b'SNAPSMACK_EOF_HEADER' not in data[:4096]:
        return False
    last = last_nonempty_line(data)
    return expected_long in last


def file_is_in_short_state(data, ext):
    """True if file currently has any short-form marker on last non-empty line."""
    last = last_nonempty_line(data)
    cfg = EXT_CONFIG[ext]
    for s in cfg['short']:
        if s in last:
            return True
    return False


def migrate_one(rel_path, apply):
    """
    Returns (status, info) where status is one of:
      'skip-already-migrated', 'migrated', 'needs-review', 'error'
    info is a free-form string describing what happened.
    """
    abs_path = os.path.join(REPO_ROOT, rel_path)
    ext = os.path.splitext(rel_path)[1].lower()
    if ext not in EXTENSIONS:
        return 'skip-not-target', f'extension {ext} not in scope'

    try:
        data = open(abs_path, 'rb').read()
    except FileNotFoundError:
        return 'error', 'file not found on disk'
    except OSError as e:
        return 'error', f'read error: {e}'

    # Pre-flight integrity
    if data.count(b'\x00') > 0:
        return 'needs-review', f'{data.count(b"\\x00")} null bytes already present'

    expected_long = expected_long_marker_bytes(ext, data)

    if file_already_migrated(data, ext, expected_long):
        return 'skip-already-migrated', 'header + long marker present'

    # Determine action plan
    plan = []

    # Bottom marker
    cfg = EXT_CONFIG[ext]
    last = last_nonempty_line(data)
    has_short = any(s in last for s in cfg['short']) and not (expected_long in last)
    has_long_already = expected_long in last

    if has_long_already:
        plan.append('marker already long')
        new_data = data
    elif has_short:
        new_data, _ = replace_or_append_bottom_marker(data, ext, expected_long)
        plan.append(f'replace short -> long')
    else:
        # No marker at all
        if ext in ('.php', '.js', '.css'):
            # These should have had short markers — flag for review
            return 'needs-review', f'no marker found and ext is {ext} (expected short)'
        # New-extension files: append long marker
        new_data, _ = replace_or_append_bottom_marker(data, ext, expected_long)
        plan.append(f'append long marker (no prior marker)')

    # Header block
    has_header = b'SNAPSMACK_EOF_HEADER' in new_data[:4096]
    if not has_header:
        if ext == '.css':
            header_str = build_header_block_css(expected_long.decode())
        else:
            header_str = build_header_block(ext, expected_long.decode())
        header_bytes = header_str.encode('utf-8')
        offset = find_header_insertion_offset(ext, new_data)
        # Always frame the header with a blank line above (unless inserting
        # at byte 0) and below — keeps it visually separated from existing
        # docblocks/code.
        prefix = b''
        suffix = b''
        if offset > 0:
            # Ensure we land on a clean line, then add a blank line above
            if new_data[offset-1:offset] != b'\n':
                prefix = b'\n\n'
            else:
                prefix = b'\n'
        # Below: ensure a blank line follows the header
        if offset < len(new_data):
            if new_data[offset:offset+1] != b'\n':
                suffix = b'\n'
            # else: header itself ends with \n; one more \n makes blank line
            suffix += b'\n'
        new_data = new_data[:offset] + prefix + header_bytes + suffix + new_data[offset:]
        plan.append(f'insert header at byte {offset}')
    else:
        plan.append('header already present')

    # Sanity: the new file must be longer (we only add)
    if len(new_data) <= len(data):
        return 'needs-review', f'new size {len(new_data)} not greater than original {len(data)}'

    if not apply:
        return 'migrated', '; '.join(plan) + ' [DRY RUN]'

    # Atomic write
    tmp_path = abs_path + '.eof-migrate.tmp'
    try:
        with open(tmp_path, 'wb') as f:
            f.write(new_data)
        os.replace(tmp_path, abs_path)
    except OSError as e:
        if os.path.exists(tmp_path):
            try: os.remove(tmp_path)
            except OSError: pass
        return 'error', f'write error: {e}'

    # Verify
    try:
        verify = open(abs_path, 'rb').read()
    except OSError as e:
        return 'error', f'verify-read error: {e}'

    nulls = verify.count(b'\x00')
    if nulls:
        return 'error', f'POST-WRITE: {nulls} null bytes in {rel_path}'
    if len(verify) <= len(data):
        return 'error', f'POST-WRITE: size {len(verify)} not > original {len(data)}'
    if b'SNAPSMACK_EOF_HEADER' not in verify[:8192]:
        return 'error', f'POST-WRITE: SNAPSMACK_EOF_HEADER tag missing'
    last_v = last_nonempty_line(verify)
    if expected_long not in last_v:
        return 'error', f'POST-WRITE: last line {last_v!r} lacks expected {expected_long!r}'

    return 'migrated', '; '.join(plan)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--apply', action='store_true', help='actually write (default is dry-run)')
    ap.add_argument('--filter', default=None, help='glob filter on rel_path (e.g. "skins/*")')
    ap.add_argument('--verbose', '-v', action='store_true')
    args = ap.parse_args()

    files = get_tracked_files()
    if args.filter:
        files = [f for f in files if fnmatch.fnmatch(f.replace('\\','/'), args.filter)]

    counters = {'migrated': 0, 'skip-already-migrated': 0, 'needs-review': 0,
                'error': 0, 'skip-excluded': 0, 'skip-not-target': 0}
    review_list = []
    error_list = []
    migrated_examples = []

    print(f"Mode: {'APPLY' if args.apply else 'DRY-RUN'}")
    print(f"Scanning {len(files)} files (after filter)\n")

    for rel in sorted(files):
        ext = os.path.splitext(rel)[1].lower()
        if ext not in EXTENSIONS:
            counters['skip-not-target'] += 1
            continue
        if is_excluded(rel):
            counters['skip-excluded'] += 1
            continue
        status, info = migrate_one(rel, args.apply)
        counters[status] = counters.get(status, 0) + 1
        if status == 'migrated':
            if len(migrated_examples) < 12:
                migrated_examples.append((rel, info))
            if args.verbose:
                print(f"OK    {rel}  -- {info}")
        elif status == 'needs-review':
            review_list.append((rel, info))
            print(f"REVW  {rel}  -- {info}")
        elif status == 'error':
            error_list.append((rel, info))
            print(f"ERR   {rel}  -- {info}")
            if args.apply:
                print(f"\nABORT: error during apply at {rel}; halting.")
                return 1

    print("\n" + "="*60)
    print("Summary:")
    for k in ('migrated','skip-already-migrated','needs-review','error',
              'skip-excluded','skip-not-target'):
        print(f"  {k:30s} {counters.get(k,0):5d}")

    if migrated_examples and not args.verbose:
        print("\nMigrated samples (first few):")
        for rel, info in migrated_examples[:8]:
            print(f"  {rel}  -- {info}")

    if review_list:
        print(f"\n{len(review_list)} files NEED REVIEW (skipped):")
        for rel, info in review_list[:20]:
            print(f"  {rel}: {info}")

    if error_list:
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())

# ===== SNAPSMACK EOF =====

#!/usr/bin/env python3
"""
SNAPSMACK - EOF marker auto-fixer (companion to tools/check-eof.py)
tools/check-eof-fix.py

SNAPSMACK_EOF_HEADER: last non-empty line of this file must be the SNAPSMACK EOF comment.

Purpose
-------
check-eof.py *detects* two fixable, non-destructive marker gaps:
  1. Missing SNAPSMACK_EOF_HEADER tag near the top of a source file.
  2. Missing '// ===== SNAPSMACK EOF =====' style bottom marker.
This script *fixes* exactly those two, and ONLY those two. It is
ADDITIVE ONLY: it never deletes or rewrites existing content, it only
inserts a header comment line near the top and/or appends the bottom
marker. Idempotent — safe to run repeatedly.

It DELIBERATELY refuses to touch any file that has NULL BYTES or a
structural \\r\\n issue, because those are the "is this real corruption?"
cases that must be judged by a human on native disk — never auto-patched.

Run natively from repo root (NOT through the cloud/FUSE mount):
    python tools/check-eof-fix.py          # dry run — shows what it WOULD do
    python tools/check-eof-fix.py --write   # actually write the fixes

After --write it re-scans and prints the residual failure count so you
can confirm you're clean before you commit. It does NOT git-add or commit
anything — you review the diff and commit yourself.
"""

import importlib.util
import os
import sys

# ---- Load the real check-eof.py so we share its EXACT logic/constants ----
REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_checker_path = os.path.join(REPO_ROOT, 'tools', 'check-eof.py')
_spec = importlib.util.spec_from_file_location('check_eof', _checker_path)
ce = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(ce)

# Per-extension top-of-file header comment. Only requirement (per check-eof.py)
# is that the literal bytes SNAPSMACK_EOF_HEADER appear in the first 8KB.
# Kept simple + describing (not embedding) the bottom marker so we never
# accidentally terminate a CSS/HTML comment early.
_HEADER_LINES = {
    '.php':  b'// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.',
    '.js':   b'// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.',
    '.css':  b'/* SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */',
    '.html': b'<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->',
    '.htm':  b'<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->',
    '.md':   b'<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->',
    '.sql':  b'-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.',
    '.py':   b'# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.',
    '.sh':   b'# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.',
}


def _detect_eol(data: bytes) -> bytes:
    return b'\r\n' if b'\r\n' in data[:4096] else b'\n'


def _first_line_wants_header_below(first_line: bytes) -> bool:
    """True if the header must go AFTER the first line (shebang / php open /
    doctype / xml decl) rather than as a brand-new first line."""
    s = first_line.lstrip().lower()
    return (
        s.startswith(b'#!')
        or s.startswith(b'<?php')
        or s.startswith(b'<?=')
        or s.startswith(b'<?xml')
        or s.startswith(b'<!doctype')
    )


def _php_ends_inside_block(data: bytes) -> bool:
    """Heuristic: does the .php file end INSIDE an open <?php block (True) or
    out in HTML/text (False)? True when the last PHP open tag has no closing
    ?> after it. Good enough for our templates; you review the diff anyway."""
    last_open = max(data.rfind(b'<?php'), data.rfind(b'<?='))
    if last_open == -1:
        return False  # no PHP at all — pure template
    return data.find(b'?>', last_open) == -1


_PHP_HEADER_COMMENT = b'/* SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */'


def _insert_header(data: bytes, ext: str, eol: bytes):
    """Return (new_bytes, wrapped_flag). For .php we make the tag output-safe."""
    if ext == '.php':
        # Put the tag INSIDE PHP, never as bare text that could render.
        # Case 1: file opens with <?php / <?= -> insert the comment right after
        # that opening token. Stays inside the block (zero output) and sits
        # before the first real statement, so declare(strict_types) is safe
        # (comments don't count as statements).
        # Case 2: file starts in HTML -> prepend a self-contained <?php ... ?>
        # line (emits nothing, guaranteed within the first 8KB).
        lead = len(data) - len(data.lstrip())
        head = data[lead:lead + 5].lower()
        if head.startswith(b'<?php'):
            pos = lead + 5
            return data[:pos] + eol + _PHP_HEADER_COMMENT + data[pos:], False
        if data[lead:lead + 3] == b'<?=':
            pos = lead + 3
            return data[:pos] + eol + _PHP_HEADER_COMMENT + data[pos:], False
        return b'<?php ' + _PHP_HEADER_COMMENT + b' ?>' + eol + data, True

    header = _HEADER_LINES[ext]
    nl = data.find(b'\n')
    if nl == -1:
        first_line, had_first_nl = data, False
    else:
        first_line, had_first_nl = data[:nl], True

    if _first_line_wants_header_below(first_line.rstrip(b'\r')) and had_first_nl:
        return first_line + b'\n' + header + eol + data[nl + 1:], False
    return header + eol + data, False


def _append_marker(data: bytes, ext: str, eol: bytes):
    """Return (new_bytes, wrapped_flag)."""
    marker = ce.EOF_MARKERS[ext]
    body = data
    if body and not body.endswith(b'\n'):
        body += eol
    if ext == '.php' and not _php_ends_inside_block(data):
        # Ends in HTML/text — wrap so nothing is printed. In PHP a // comment
        # runs until ?>, so this closes cleanly and emits nothing.
        return body + b'<?php ' + marker + b' ?>' + eol, True
    return body + marker + eol, False


def fix_file(rel_path: str, write: bool):
    ext = os.path.splitext(rel_path)[1].lower()
    if ext not in ce.EXTENSIONS:
        return None
    abs_path = os.path.join(REPO_ROOT, rel_path)
    try:
        data = ce.read_nocache(abs_path)
    except FileNotFoundError:
        return ('MISSING', rel_path, [])

    actions = []

    # SAFETY: never auto-touch a file with null bytes or structural \r\n.
    if data.count(b'\x00'):
        return ('SKIP-NULL', rel_path, ['has NULL bytes — fix by hand on native disk'])
    for i, line in enumerate(data.split(b'\n'), 1):
        if ce.LITERAL_CRLF.search(line) and not ce.is_likely_string_context(line):
            return ('SKIP-CRLF', rel_path, [f'suspicious \\r\\n on line {i} — review by hand'])

    eol = _detect_eol(data)
    new = data

    # 1. Header tag near top.
    if ce.HEADER_TAG not in new[:ce.HEADER_SCAN_BYTES]:
        new, wrapped = _insert_header(new, ext, eol)
        actions.append('add HEADER tag' + (' (php-wrapped)' if wrapped else ''))

    # 2. Bottom marker.
    expected = ce.EOF_MARKERS[ext]
    lines = new.rstrip(b'\r\n').split(b'\n')
    last_nonempty = None
    for line in reversed(lines):
        stripped = line.rstrip(b'\r')
        if stripped.strip():
            last_nonempty = stripped
            break
    if last_nonempty is None or expected not in last_nonempty:
        new, wrapped = _append_marker(new, ext, eol)
        actions.append('append EOF marker' + (' (php-wrapped)' if wrapped else ''))

    if not actions:
        return None
    if write and new != data:
        with open(abs_path, 'wb') as fh:
            fh.write(new)
    return ('FIX', rel_path, actions)


def main():
    write = '--write' in sys.argv[1:]
    files = ce.get_tracked_files()

    fixed, skipped, missing = [], [], []
    for rel_path in sorted(files):
        ext = os.path.splitext(rel_path)[1].lower()
        if ext not in ce.EXTENSIONS or ce.is_excluded(rel_path):
            continue
        res = fix_file(rel_path, write)
        if res is None:
            continue
        kind = res[0]
        if kind == 'FIX':
            fixed.append(res)
        elif kind == 'MISSING':
            missing.append(res)
        else:
            skipped.append(res)

    mode = 'WROTE' if write else 'WOULD FIX (dry run — pass --write to apply)'
    print(f"{mode}: {len(fixed)} file(s)\n")
    for _, rel, actions in fixed:
        print(f"  {rel}")
        for a in actions:
            print(f"      - {a}")

    if skipped:
        print(f"\nSKIPPED (needs a human — NOT auto-touched): {len(skipped)}")
        for _, rel, notes in skipped:
            print(f"  {rel}: {notes[0]}")
    if missing:
        print(f"\nTRACKED BUT NOT ON DISK: {len(missing)}")
        for _, rel, _n in missing:
            print(f"  {rel}")

    if write:
        # Re-scan with the real checker to confirm we're clean.
        print(f"\n{'='*60}\nRe-scanning with check-eof.py ...")
        residual = 0
        for rel_path in sorted(ce.get_tracked_files()):
            ext = os.path.splitext(rel_path)[1].lower()
            if ext not in ce.EXTENSIONS or ce.is_excluded(rel_path):
                continue
            if ce.check_file(rel_path):
                residual += 1
        if residual:
            print(f"{residual} file(s) still failing (likely null/\\r\\n — review by hand).")
        else:
            print("All clear. Review `git diff`, then commit.")
    return 0


if __name__ == '__main__':
    sys.exit(main())

# ===== SNAPSMACK EOF =====

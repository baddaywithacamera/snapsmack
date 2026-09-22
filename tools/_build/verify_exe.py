#!/usr/bin/env python3
"""
verify_exe.py — post-build gate for a PyInstaller onefile desktop exe.

    python verify_exe.py dist\\app.exe --entry <launcher_module> [--no-tk]
                         [--version 0.7.26] [--no-icon-check]

Checks, in order, and exits 1 on the first failure:
  * ENTRY SCRIPT — the archive's script entry is the module you name. This is
    the check that would have caught SYBU 0.7.67 shipping the tkinter app: the
    version number was right, the entry script was wrong.
  * --no-tk        — no `tkinter` module and no `_tkinter` binary in the bundle
                     (a Qt build that drags Tk in is the wrong build).
  * version        — the Windows version resource is present (Properties →
                     Details). With --version, it must equal that string.
  * icon           — the exe carries at least one icon resource.

stdlib + PyInstaller's own reader only, so it runs in the build environment.

SNAPSMACK_EOF_HEADER: last non-empty line must be # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import argparse
import os
import sys


def _fail(msg: str) -> int:
    print(f'verify_exe: FAIL — {msg}', file=sys.stderr)
    return 1


def _toc(path: str):
    from PyInstaller.archive.readers import CArchiveReader
    r = CArchiveReader(path)
    return r, r.toc


def _pyz_modules(reader, toc) -> set:
    mods = set()
    for name, entry in toc.items():
        if entry[4] == 'z':          # PKG_ITEM_PYZ
            try:
                pyz = reader.open_embedded_archive(name)
                mods.update(pyz.toc.keys())
            except Exception:
                pass
    return mods


def check_entry(reader, toc, entry: str) -> str:
    scripts = [n for n, e in toc.items() if e[4] == 's']   # PKG_ITEM_SCRIPT
    user_scripts = [s for s in scripts if not s.startswith('pyi')]
    if entry not in user_scripts:
        return f'entry script is {user_scripts or scripts}, expected {entry!r}'
    return ''


def check_no_tk(reader, toc) -> str:
    bins = [n for n, e in toc.items() if e[4] == 'b' and '_tkinter' in n.lower()]
    if bins:
        return f'tkinter binary bundled: {bins}'
    mods = _pyz_modules(reader, toc)
    tk = sorted(m for m in mods if m == 'tkinter' or m.startswith('tkinter.'))
    if tk:
        return f'tkinter modules bundled: {tk[:5]}'
    return ''


def _file_version(path: str) -> str:
    """Read ProductVersion/FileVersion from the PE version resource (Windows)."""
    if sys.platform != 'win32':
        return 'n/a'
    import ctypes
    from ctypes import wintypes
    ver = ctypes.windll.version
    size = ver.GetFileVersionInfoSizeW(path, None)
    if not size:
        return ''
    buf = ctypes.create_string_buffer(size)
    if not ver.GetFileVersionInfoW(path, 0, size, buf):
        return ''
    lp = ctypes.c_void_p()
    ln = wintypes.UINT()
    if not ver.VerQueryValueW(buf, '\\VarFileInfo\\Translation', ctypes.byref(lp), ctypes.byref(ln)):
        return ''
    lang, cp = ctypes.cast(lp, ctypes.POINTER(wintypes.WORD * 2)).contents
    key = f'\\StringFileInfo\\{lang:04x}{cp:04x}\\FileVersion'
    if not ver.VerQueryValueW(buf, key, ctypes.byref(lp), ctypes.byref(ln)):
        return ''
    return ctypes.wstring_at(lp.value, ln.value).rstrip('\x00')


def _icon_count(path: str) -> int:
    if sys.platform != 'win32':
        return -1
    import ctypes
    n = ctypes.windll.shell32.ExtractIconExW(path, -1, None, None, 0)
    return int(n)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('exe')
    ap.add_argument('--entry', required=True, help='launcher module name, e.g. flkrfckr_launcher')
    ap.add_argument('--no-tk', action='store_true')
    ap.add_argument('--version', default='')
    ap.add_argument('--no-icon-check', action='store_true')
    a = ap.parse_args()

    if not os.path.isfile(a.exe):
        return _fail(f'{a.exe} not found')
    try:
        reader, toc = _toc(a.exe)
    except Exception as e:
        return _fail(f'cannot read PyInstaller archive: {e}')

    err = check_entry(reader, toc, a.entry)
    if err:
        return _fail(err)
    print(f'verify_exe: entry script OK ({a.entry})')

    if a.no_tk:
        err = check_no_tk(reader, toc)
        if err:
            return _fail(err)
        print('verify_exe: no tkinter in bundle OK')

    fv = _file_version(a.exe)
    if fv == '':
        return _fail('no Windows version resource — version_info.txt missing from the spec?')
    if a.version and fv != 'n/a' and fv != a.version:
        return _fail(f'file version {fv} != expected {a.version}')
    print(f'verify_exe: file version OK ({fv})')

    if not a.no_icon_check:
        n = _icon_count(a.exe)
        if n == 0:
            return _fail('exe has no icon resource — icon= missing from the spec?')
        print(f'verify_exe: icon OK ({n if n > 0 else "skipped, not Windows"})')
    return 0


if __name__ == '__main__':
    sys.exit(main())
# ===== SNAPSMACK EOF =====

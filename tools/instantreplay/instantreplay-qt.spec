# -*- mode: python ; coding: utf-8 -*-
"""PyInstaller spec for INSTANT REPLAY (onedir bundle, SS-1363)."""

import ast
import glob
import os

_src = os.path.abspath(SPECPATH)
with open(os.path.join(_src, '_version.py'), encoding='utf-8') as _f:
    _version_ast = ast.parse(_f.read(), filename='_version.py')
_build_version = next(
    ast.literal_eval(node.value)
    for node in _version_ast.body
    if isinstance(node, ast.Assign)
    and any(isinstance(t, ast.Name) and t.id == 'BUILD_VERSION' for t in node.targets)
)

_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data = [(f, '.') for f in _shared_files]
_shared_mods = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

a = Analysis(
    ['instantreplay_qt_launcher.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_shared_data + [(os.path.join(_src, 'assets', 'instantreplay.ico'), 'assets')],
    hiddenimports=_shared_mods + [
        'PySide6', 'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=['tkinter', 'torch', 'matplotlib', 'pandas'],
    noarchive=False,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name='instantreplay',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,
    disable_windowed_traceback=False,
    icon=os.path.join(_src, 'assets', 'instantreplay.ico'),
)

coll = COLLECT(
    exe,
    a.binaries,
    a.datas,
    strip=False,
    upx=False,
    upx_exclude=[],
    name='instantreplay',
)
# ===== SNAPSMACK EOF =====

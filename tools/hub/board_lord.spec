# -*- mode: python ; coding: utf-8 -*-
# Standalone BOARD LORD (Qt / PySide6) build recipe.
#
# Builds the forum operator console (board_lord/) into a single windowed
# "BOARD LORD.exe". BOARD LORD only reuses slapper_qt.theme from the editor —
# NOT the image engine — so PIL and the editor modules are intentionally excluded
# to keep the exe lean.
#
# Build:  python -m PyInstaller tools/hub/board_lord.spec
import os
import sys
from PyInstaller.utils.hooks import collect_submodules

_src        = SPECPATH                                             # tools/hub
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))

for _p in (_src, _shared_dir):
    if _p not in sys.path:
        sys.path.insert(0, _p)

# Only the theme is borrowed from slapper_qt; do not drag in the editor package.
_hidden = collect_submodules('board_lord') + [
    'slapper_qt', 'slapper_qt.theme',
    'snap_log',
    'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
]

a = Analysis(
    [os.path.join(_src, 'run_board_lord.py')],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[],
    hiddenimports=_hidden,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    # BOARD LORD is network + UI only — no imaging, no fleet/credential modules.
    excludes=['torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
              'skimage', 'matplotlib', 'transformers', 'pandas', 'numpy', 'cv2',
              'google', 'googleapiclient', 'bs4', 'imagehash', 'IPython',
              'notebook', 'streamlit', 'gradio', 'tkinter',
              'PIL', 'editor_engine', 'built_in_lewks', 'found_textures',
              'photo_manager', 'photo_library'],
    noarchive=False,
)

pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, a.binaries, a.datas, [], name='BOARD LORD',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None)

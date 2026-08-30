# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER (Qt / PySide6) build recipe.
#
# Builds the Qt rebuild (slapper_qt/) into a single windowed SNAP SLAPPER.exe.
# The image engine (editor_engine.py, pure PIL) is reused unchanged.
#
# SECAUDIT 051 posture: only the modules the standalone editor actually imports
# ride along. Verified imports from tools/hub: editor_engine, built_in_lewks,
# found_textures, photo_manager; from tools/_shared: snap_home, snap_log,
# snap_profiles. Credential, enrichment, and fleet-discovery modules are not
# imported, so PyInstaller does not bundle them.
import os
import sys
from PyInstaller.utils.hooks import collect_submodules

_src        = SPECPATH                                             # tools/hub
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))

# Make hub + shared importable so collect_submodules can enumerate the package.
for _p in (_src, _shared_dir):
    if _p not in sys.path:
        sys.path.insert(0, _p)

_hidden = collect_submodules('slapper_qt') + [
    'editor_engine', 'built_in_lewks', 'found_textures', 'photo_manager',
    'snap_home', 'snap_log', 'snap_profiles',
    'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
    'PySide6.QtPrintSupport',
    'PIL', 'PIL.Image', 'psd_tools',
]

a = Analysis(
    [os.path.join(_src, 'run_slapper_qt.py')],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[],
    hiddenimports=_hidden,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    # Nothing here uses these; excluding keeps the exe lean. tkinter excluded
    # because this is the Qt build (no Tk, no PIL.ImageTk).
    excludes=['torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
              'skimage', 'matplotlib', 'transformers', 'pandas', 'cv2',
              'google', 'googleapiclient', 'bs4', 'imagehash', 'IPython',
              'notebook', 'streamlit', 'gradio', 'tkinter'],
    noarchive=False,
)

pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, a.binaries, a.datas, [], name='SNAP SLAPPER',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None)

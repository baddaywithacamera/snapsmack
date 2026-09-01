# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER (Qt / PySide6) build recipe.
import os
import sys
from PyInstaller.utils.hooks import collect_submodules

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_license_dir = os.path.normpath(os.path.join(_src, '..', '..', 'licenses'))
_external_notices = [
    os.path.join(_license_dir, name) for name in (
        'xpano-external-tool-notice.txt',
        'rawtherapee-external-tool-notice.txt',
        'darktable-external-tool-notice.txt',
    )
]

for _path in (_src, _shared_dir):
    if _path not in sys.path:
        sys.path.insert(0, _path)

_hidden = collect_submodules('slapper_qt') + [
    'editor_engine', 'built_in_lewks', 'found_textures', 'texture_assets',
    'photo_manager', 'slapper_filters', 'lewk_again',
    'snap_home', 'snap_log', 'snap_profiles', 'snap_creds', 'snap_vault',
    'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
    'PySide6.QtPrintSupport', 'PySide6.QtSvg',
    'PIL', 'PIL.Image', 'psd_tools',
]

a = Analysis(
    [os.path.join(_src, 'run_slapper_qt.py')],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(path, 'licenses') for path in _external_notices],
    hiddenimports=_hidden,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
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
          entitlements_file=None, icon='icons/snap-slapper.ico')

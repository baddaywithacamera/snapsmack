# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER photo manager build recipe.
import glob
import os

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_app_files = [os.path.join(_src, name) for name in
              ('snap_slapper.py', 'photo_library.py', 'photo_manager.py')]
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_mods = [os.path.splitext(os.path.basename(path))[0] for path in _shared_files]

a = Analysis(
    ['snap_slapper.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(path, '.') for path in _app_files + _shared_files],
    hiddenimports=['photo_library', 'photo_manager', 'PIL', 'PIL.Image', 'PIL.ImageTk'] + _shared_mods,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=['torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
              'skimage', 'matplotlib', 'transformers', 'pandas', 'numpy', 'cv2',
              'google', 'googleapiclient', 'bs4', 'imagehash', 'IPython', 'notebook',
              'streamlit', 'gradio'],
    noarchive=False,
)

pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, a.binaries, a.datas, [], name='SNAP SLAPPER',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None)

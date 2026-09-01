# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER Qt photo manager/editor build recipe.
import os

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
# Qt reuses the established processing engine and LEWK catalogue.
_app_files = [os.path.join(_src, name) for name in
              ('editor_engine.py', 'built_in_lewks.py', 'found_textures.py')]
_shared_names = ('snap_home.py', 'snap_paths.py', 'snap_log.py', 'snap_errors.py',
                 'snap_profiles.py', 'snap_creds.py', 'snap_vault.py')
_shared_files = [os.path.join(_shared_dir, name) for name in _shared_names]
_shared_mods = [os.path.splitext(name)[0] for name in _shared_names]

a = Analysis(
    ['run_slapper_qt.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(path, '.') for path in _app_files + _shared_files],
    hiddenimports=['slapper_qt', 'editor_engine', 'built_in_lewks', 'found_textures',
                   'PIL', 'PIL.Image', 'PIL.ImageCms', 'PIL.ImageFilter'] + _shared_mods,
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
          entitlements_file=None, icon='icons/snap-slapper.ico')

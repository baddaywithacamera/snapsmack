# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER photo manager build recipe.
import os
import tkinter as tk

try:
    _tk_probe = tk.Tk()
    _tk_probe.withdraw()
    _tk_probe.destroy()
except Exception as exc:
    raise SystemExit('SNAP SLAPPER build blocked: Python has no usable Tk runtime: ' + str(exc))

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
# Bundle the editor modules SNAP SLAPPER genuinely imports. Keep shared fleet
# modules separately allowlisted below so credentials/network code cannot ride.
_app_files = [os.path.join(_src, name) for name in
              ('snap_slapper.py', 'photo_library.py', 'photo_manager.py',
               'editor_engine.py', 'editor_ui.py', 'help_ui.py')]
_app_files.append(os.path.join(_src, 'built_in_lewks.py'))
# SECAUDIT 051: bundle ONLY the shared modules the standalone editor imports.
_shared_names = ('snap_home.py', 'snap_paths.py')
_shared_files = [os.path.join(_shared_dir, name) for name in _shared_names]
_shared_mods = [os.path.splitext(name)[0] for name in _shared_names]

a = Analysis(
    ['snap_slapper.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(path, '.') for path in _app_files + _shared_files],
    hiddenimports=['photo_library', 'photo_manager', 'editor_engine', 'editor_ui', 'help_ui', 'built_in_lewks',
                   'PIL', 'PIL.Image', 'PIL.ImageTk'] + _shared_mods,
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

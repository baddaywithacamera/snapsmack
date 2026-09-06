# -*- mode: python ; coding: utf-8 -*-
# SNAP HQ build recipe — self-bundling, mirrors the SYBU spec's _shared bundling.
# Shared modules are copied flat and force-imported,
# so snap_creds / snap_profiles / snap_discovery / snap_home / snap_stepup can
# never be silently dropped from the frozen exe.
import os, glob, sys
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

_py_files   = [os.path.join(_src, 'main.py')]
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) != 'main.py']

_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]
_icon_dir     = os.path.join(_src, 'icons')
_ui_icons     = glob.glob(os.path.join(_icon_dir, '*-simple.png')) + [os.path.join(_icon_dir, 'snap-hq.png')]
_icon_data    = [(f, 'icons') for f in _ui_icons]

a = Analysis(
    ['main.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_local_data + _shared_data + _icon_data,
    hiddenimports=_local_mods + _shared_mods + [
        'tkinter', 'tkinter.ttk', 'tkinter.filedialog', 'tkinter.messagebox',
        'requests',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        'torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
        'skimage', 'matplotlib', 'transformers', 'pandas', 'numpy',
        'cv2', 'google', 'googleapiclient', 'bs4', 'imagehash',
        'IPython', 'notebook', 'streamlit', 'gradio',
    ],
    noarchive=False,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='SNAP HQ',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    runtime_tmpdir=None,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=os.path.join(_icon_dir, 'snap-hq.ico'),
)

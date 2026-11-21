# -*- mode: python ; coding: utf-8 -*-
# SNAP HQ build recipe — self-bundling, mirrors the SYBU spec's _shared bundling.
# Shared modules are copied flat and force-imported,
# so snap_creds / snap_profiles / snap_discovery / snap_home / snap_stepup can
# never be silently dropped from the frozen exe.
import os, glob, sys, ast
from PyInstaller.utils.win32.versioninfo import (
    VSVersionInfo, FixedFileInfo, StringFileInfo, StringTable, StringStruct,
    VarFileInfo, VarStruct,
)
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

# Stamp the same build version the application displays into Windows file
# properties. Parsing the source keeps a single version authority.
with open(os.path.join(_src, 'main.py'), encoding='utf-8') as _vf:
    _version_tree = ast.parse(_vf.read())
_build_version = next(
    ast.literal_eval(node.value) for node in _version_tree.body
    if isinstance(node, ast.Assign)
    and any(isinstance(target, ast.Name) and target.id == 'BUILD_VERSION'
            for target in node.targets)
)
_version_tuple = tuple(int(part) for part in _build_version.split('.')) + (0,)
if len(_version_tuple) != 4 or any(part < 0 or part > 65535 for part in _version_tuple):
    raise ValueError('BUILD_VERSION must contain three Windows-compatible integers')
_windows_version = VSVersionInfo(
    ffi=FixedFileInfo(filevers=_version_tuple, prodvers=_version_tuple,
                      mask=0x3f, flags=0, OS=0x40004, fileType=1, subtype=0, date=(0, 0)),
    kids=[StringFileInfo([StringTable('040904B0', [
        StringStruct('FileVersion', _build_version),
        StringStruct('ProductVersion', _build_version),
        StringStruct('FileDescription', 'SNAP HQ'),
        StringStruct('ProductName', 'SNAP HQ'),
        StringStruct('CompanyName', 'Sean McCormick'),
        StringStruct('OriginalFilename', 'SNAP HQ.exe'),
    ])]), VarFileInfo([VarStruct('Translation', [0x0409, 1200])])],
)

_py_files   = [os.path.join(_src, 'main.py'), os.path.join(_src, 'qt_main.py'), os.path.join(_src, 'app_cli.py')]
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) != 'main.py']

_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]
_icon_dir     = os.path.join(_src, 'icons')
_ui_icons     = glob.glob(os.path.join(_icon_dir, '*-simple.png')) + [
    os.path.join(_icon_dir, 'fed-up.png'),
    os.path.join(_icon_dir, 'snap-hq.png'),
    os.path.join(_icon_dir, 'sybu-taskbar.png'),
]
_icon_data    = [(f, 'icons') for f in _ui_icons]
# PyInstaller 6.22 can discover Tcl's timezone/message files while omitting the
# two root scripts that actually boot Tcl/Tk from this portable build runtime.
# Bundle both trees explicitly; duplicate entries are harmless and a frozen HQ
# without init.tcl is not an application.
_tcl_root = os.path.join(sys.base_prefix, 'tcl')
_tk_data = [
    (os.path.join(_tcl_root, 'tcl8.6'), '_tcl_data'),
    (os.path.join(_tcl_root, 'tk8.6'), '_tk_data'),
]

a = Analysis(
    ['qt_main.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_local_data + _shared_data + _icon_data + _tk_data,
    hiddenimports=_local_mods + _shared_mods + [
        'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
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
    version=_windows_version,
)

# -*- mode: python ; coding: utf-8 -*-
# CRONOMETER build recipe — clean, complete, self-bundling.
# Cloned from coldsnap.spec. KEY: every local .py is auto-bundled (datas +
# hiddenimports) so a new module can NEVER be silently dropped from the exe.
# That dropped-module bug is what hid the gram tabs in SYBU.
import os, glob, sys, ast
from PyInstaller.utils.win32.versioninfo import (
    VSVersionInfo, FixedFileInfo, StringFileInfo, StringTable, StringStruct,
    VarFileInfo, VarStruct,
)
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

# Windows version resource. Copied from coldsnap-qt.spec, which is the working
# recipe; without it the exe carries an EMPTY FileVersion and no one can tell
# which build is installed. Reads the same literal the app imports -- never
# maintain a second version.
with open(os.path.join(_src, 'cronometer.py'), encoding='utf-8') as _vf:
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
        StringStruct('FileDescription', 'CRONOMETER'),
        StringStruct('ProductName', 'CRONOMETER'),
        StringStruct('CompanyName', 'Sean McCormick'),
        StringStruct('OriginalFilename', 'cronometer.exe'),
    ])]), VarFileInfo([VarStruct('Translation', [0x0409, 1200])])],
)

# --- auto-bundle every local .py (data copy + forced hidden import) ---
_py_files   = glob.glob(os.path.join(_src, '*.py'))
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) != 'cronometer.py']

# --- ALSO bundle shared modules (tools/_shared/*.py) that this tool imports by
#     bare name at runtime (snap_home, snap_profiles, snap_creds, snap_stepup, …).
#     Copy them FLAT ('.') next to the frozen entry AND force the hidden import so
#     PyInstaller resolves and embeds them; without this the fleet load / transport
#     guard would fail to import on the frozen exe.
_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]
# App icon lives with the family's icons (tools/hub/icons); exe + window use it.
_icon_ico     = os.path.normpath(os.path.join(_src, '..', 'hub', 'icons', 'cronometer.ico'))
_icon_png     = os.path.join(_src, 'cronometer-64.png')   # 64 px copy of hub/icons/cronometer.png for the title bar

a = Analysis(
    ['cronometer.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    # Bundle an assets/ folder only if this tool actually has one. CRONOMETER has
    # no assets dir yet, and an unconditional entry makes PyInstaller abort with
    # "Unable to find …\assets" on a clean build.
    datas=_local_data + _shared_data + (
        [(os.path.join(_src, 'assets'), 'assets')]
        if os.path.isdir(os.path.join(_src, 'assets')) else [])
        + [(_icon_ico, 'assets'), (_icon_png, 'assets')],   # window icon at runtime (see cronometer.py)
    hiddenimports=_local_mods + _shared_mods + [
        # UI
        'tkinter', 'tkinter.ttk', 'tkinter.messagebox',
        # Network
        'requests',
        # Concurrency
        'concurrent.futures',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        'torch', 'torchvision', 'torchaudio', 'tensorflow', 'keras',
        'scipy', 'sklearn', 'skimage', 'matplotlib', 'matplotlib.pyplot',
        'transformers', 'tokenizers', 'huggingface_hub', 'timm', 'numba',
        'llvmlite', 'pandas', 'numpy.distutils', 'altair', 'streamlit',
        'gradio', 'IPython', 'ipykernel', 'notebook', 'uvicorn', 'fastapi',
        'starlette', 'fsspec', 'pyarrow', 'PIL',
    ],
    noarchive=False,
)

pyz = PYZ(a.pure)

# Onefile GUI build (single .exe, no console, UPX off — matches the family recipe).
exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='cronometer',
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
    icon=_icon_ico,
    version=_windows_version,
)
# ===== SNAPSMACK EOF =====

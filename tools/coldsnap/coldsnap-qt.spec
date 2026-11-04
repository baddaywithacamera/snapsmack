# -*- mode: python ; coding: utf-8 -*-
# COLD SNAP (Qt shell) build recipe — side-by-side with the Tk coldsnap.exe.
# Same self-bundling discipline as coldsnap.spec: every local .py and every
# shared module is bundled AND force-imported, so a new module can never be
# silently dropped from the exe (the bug that once hid whole tabs).
import os, glob, sys, ast
from PyInstaller.utils.win32.versioninfo import (
    VSVersionInfo, FixedFileInfo, StringFileInfo, StringTable, StringStruct,
    VarFileInfo, VarStruct,
)
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

# Read the same literal the app imports; never maintain a second version.
with open(os.path.join(_src, '_version.py'), encoding='utf-8') as _vf:
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
        StringStruct('FileDescription', 'COLD SNAP'),
        StringStruct('ProductName', 'COLD SNAP'),
        StringStruct('CompanyName', 'Sean McCormick'),
        StringStruct('OriginalFilename', 'coldsnap.exe'),
    ])]), VarFileInfo([VarStruct('Translation', [0x0409, 1200])])],
)

# --- engine modules, bundled flat (sumna_* import each other by bare name) ---
_py_files   = glob.glob(os.path.join(_src, '*.py'))
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) not in ('coldsnap.py', 'coldsnap_qt_launcher.py')]

# --- the Qt shell package -----------------------------------------------------
_pkg_dir   = os.path.join(_src, 'coldsnap_qt')
_pkg_files = glob.glob(os.path.join(_pkg_dir, '*.py'))
_pkg_data  = [(f, 'coldsnap_qt') for f in _pkg_files]
_pkg_mods  = ['coldsnap_qt'] + [
    'coldsnap_qt.' + os.path.splitext(os.path.basename(f))[0]
    for f in _pkg_files if not os.path.basename(f).startswith('__')]

# --- shared modules (tools/_shared/*.py), bundled flat ---------------------------
_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

# --- Qt LGPLv3: licences must travel, and the build refuses without them ----
# SS-1195. Qt under the LGPLv3 obliges whoever distributes the combined work to
# let a recipient replace the Qt libraries with their own build. That is why
# this is a COLLECT directory and not a onefile exe: a onefile exe unpacks to a
# temporary directory at run time and cannot be relinked.
_license_dir = os.path.normpath(os.path.join(_src, '..', '..', 'licenses'))
for _required in ('LGPL-3.0.txt', 'Qt-PySide6-LGPLv3-NOTICE.txt'):
    if not os.path.isfile(os.path.join(_license_dir, _required)):
        raise SystemExit('coldsnap-qt.spec: licenses/%s is missing. Refusing '
                         'to build a Qt application without it.' % _required)

a = Analysis(
    ['coldsnap_qt_launcher.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_local_data + _pkg_data + _shared_data + [(_license_dir, 'licenses')] + (
        [(os.path.join(_src, 'assets'), 'assets')]
        if os.path.isdir(os.path.join(_src, 'assets')) else []),
    hiddenimports=_local_mods + _pkg_mods + _shared_mods + [
        'PySide6', 'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
        'PIL', 'PIL.Image',
        'requests',
        'concurrent.futures',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        'tkinter',
        'torch', 'torchvision', 'torchaudio', 'tensorflow', 'keras',
        'scipy', 'sklearn', 'skimage', 'matplotlib', 'matplotlib.pyplot',
        'transformers', 'tokenizers', 'huggingface_hub', 'timm', 'numba',
        'llvmlite', 'pandas', 'numpy.distutils', 'altair', 'streamlit',
        'gradio', 'IPython', 'ipykernel', 'notebook', 'uvicorn', 'fastapi',
        'starlette', 'fsspec', 'pyarrow',
        # Qt modules the shell never touches — keeps the onefile exe lean.
        'PySide6.QtWebEngineCore', 'PySide6.QtWebEngineWidgets',
        'PySide6.QtQml', 'PySide6.QtQuick', 'PySide6.Qt3DCore',
        'PySide6.QtMultimedia', 'PySide6.QtCharts', 'PySide6.QtPdf',
    ],
    noarchive=False,
)

pyz = PYZ(a.pure)

# COLLECT DIRECTORY, not onefile (SS-1195) -- see the licence block above.
#
# LAUNCHER CHECK, done before changing anything:
#   SNAP HQ       tools/hub/main.py launches
#                 C:/snapsmack/coldsnap/coldsnap.exe
#   build.bat     had NO copy step at all -- it passed
#                 --distpath "C:/snapsmack/coldsnap" and built STRAIGHT INTO
#                 the install directory
#   spec name     already 'coldsnap', so the onefile landed exactly there
#
# THAT IS THE TRAP HERE, and it is not a rename. PyInstaller writes a COLLECT
# build to <distpath>/<COLLECT name>/, so building into the install directory
# would have produced C:/snapsmack/coldsnap/coldsnap/coldsnap.exe -- one level
# too deep, and SNAP HQ would no longer find it. build.bat builds to a local
# dist/ and copies the directory across instead, which also means a failed
# build can no longer half-overwrite a working install. Its own comment already
# claimed "the existing executable was NOT replaced" on failure; now that is
# actually true.
exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    contents_directory='.',
    name='coldsnap',
    version=_windows_version,
    icon=os.path.join(_src, 'assets', 'coldsnap.ico'),
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
)
# Never collapse this back to onefile: the dynamic Qt libraries must stay
# separately inspectable and relinkable in the distributed directory, which
# is what the LGPLv3 asks of us. licenses/ ships beside them.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False,
              name='coldsnap')

# ===== SNAPSMACK EOF =====

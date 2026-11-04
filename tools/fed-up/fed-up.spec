# -*- mode: python ; coding: utf-8 -*-
# FED UP build recipe — clean, complete, self-bundling.
# Cloned from coldsnap.spec. KEY: every local .py is auto-bundled (datas +
# hiddenimports) so a new module can NEVER be silently dropped from the exe.
# That dropped-module bug is what hid the gram tabs in SYBU.
import os, glob, sys
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

# --- auto-bundle every local .py (data copy + forced hidden import) ---
_py_files   = glob.glob(os.path.join(_src, '*.py'))
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) != 'app.py']

# --- ALSO bundle shared modules (tools/_shared/*.py) that this tool imports by
#     bare name at runtime (snap_home, snap_profiles, snap_creds, snap_stepup, …).
#     Copy them FLAT ('.') next to the frozen entry AND force the hidden import so
#     PyInstaller resolves and embeds them; without this the fleet load / transport
#     guard would fail to import on the frozen exe.
_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

# --- RESTORE rides UNZUCKER's poster (poster.py, ig_parser.py, exif_writer.py) and
#     the optional B2 copy rides SUYB's cloud_client.py. Bundle them FLAT the same
#     way, so fed_up.restore / fed_up.cloud find them by bare name in the exe.
_unz_dir   = os.path.normpath(os.path.join(_src, '..', 'unzucker'))
_unz_files = [os.path.join(_unz_dir, n) for n in ('poster.py', 'ig_parser.py', 'exif_writer.py')]
_suyb_dir  = os.path.normpath(os.path.join(_src, '..', 'smack-up-your-backup'))
_suyb_files = [os.path.join(_suyb_dir, 'cloud_client.py')]
_ride_files = [f for f in _unz_files + _suyb_files if os.path.isfile(f)]
_ride_data  = [(f, '.') for f in _ride_files]
_ride_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _ride_files]

# ─── Qt LGPLv3: licences must travel, and the build refuses without them ─────
# SS-1195. Qt is used under the LGPLv3, which obliges whoever distributes the
# combined work to let a recipient replace the Qt libraries with their own
# build. That is why this is a COLLECT directory and not a onefile exe: a
# onefile exe unpacks to a temporary directory at run time and cannot be
# relinked. Same guard as tools/unzucker/unzucker.spec.
_license_dir = os.path.normpath(os.path.join(_src, '..', '..', 'licenses'))
for _required in ('LGPL-3.0.txt', 'Qt-PySide6-LGPLv3-NOTICE.txt'):
    if not os.path.isfile(os.path.join(_license_dir, _required)):
        raise SystemExit('fed-up.spec: licenses/%s is missing. Refusing to '
                         'build a Qt application without it.' % _required)

a = Analysis(
    ['app.py'],
    pathex=[_src, _shared_dir, _unz_dir, _suyb_dir],
    binaries=[],
    # Bundle an assets/ folder only if this tool actually has one. FED UP has
    # no assets dir yet, and an unconditional entry makes PyInstaller abort with
    # "Unable to find …\assets" on a clean build.
    datas=_local_data + _shared_data + _ride_data + [(_license_dir, 'licenses')] + (
        [(os.path.join(_src, 'assets'), 'assets')]
        if os.path.isdir(os.path.join(_src, 'assets')) else []),
    hiddenimports=_local_mods + _shared_mods + _ride_mods + [
        # UI
        'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets', 'fed_up', 'fed_up.ui',
        'fed_up.fetch', 'fed_up.archive', 'fed_up.cloud', 'fed_up.restore', 'fed_up.config',
        # Network
        'requests',
        # RESTORE: UNZUCKER's poster writes EXIF and reads image sizes
        'PIL', 'PIL.Image', 'piexif',
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
        'starlette', 'fsspec', 'pyarrow',
    ],
    noarchive=False,
)

pyz = PYZ(a.pure)

# COLLECT DIRECTORY, not onefile (SS-1195) — see the licence block above.
#
# LAUNCHER CHECK, done before changing the name: tools/hub/qt_main.py's
# MIGRATION_ROSTER launches C:\snapsmacked-uped-up.exe, and build.bat
# copied disted-up.exe there WITHOUT renaming it. So the exe keeps the name
# 'fed-up' and lands at exactly the same path once build.bat copies the whole
# directory. No rename step to trip over, unlike FLKR FCKR.
exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    contents_directory='.',
    name='fed-up',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    runtime_tmpdir=None,
    console=False,
    icon=os.path.join(_src, '..', 'hub', 'icons', 'fed-up.ico'),
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)

# Never collapse this back to onefile: the dynamic Qt libraries must stay
# separately inspectable and relinkable in the distributed directory, which is
# what the LGPLv3 asks of us. licenses/ ships beside them.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False, name='fed-up')
# ===== SNAPSMACK EOF =====

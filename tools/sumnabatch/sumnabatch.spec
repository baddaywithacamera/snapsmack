# -*- mode: python ; coding: utf-8 -*-
# SUMNABATCH build recipe — clean, complete, self-bundling.
# Written fresh 2026-06-28 to replace the truncated smackyourbatchup spec.
# KEY: every local .py is auto-bundled (datas + hiddenimports) so a new module
# (e.g. the sob_ offline-poster suite) can NEVER be silently dropped from the
# exe again. That dropped-module bug is what hid the gram tabs.
import os, glob, sys
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

# --- auto-bundle every local .py (data copy + forced hidden import) ---
_py_files   = glob.glob(os.path.join(_src, '*.py'))
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) != 'main.py']

# --- ALSO bundle shared modules (tools/_shared/*.py) that the sob_ suite imports
#     by bare name (e.g. snap_thumbs). sob_offline expects snap_thumbs bundled
#     FLAT next to it on the frozen exe; without this the gram suite fails to
#     import at runtime and the BATCH tabs silently vanish. Copy flat ('.') AND
#     force the hidden import so PyInstaller can resolve and embed them.
_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

a = Analysis(
    ['main.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_local_data + _shared_data + [(os.path.join(_src, 'assets'), 'assets')],
    hiddenimports=_local_mods + _shared_mods + [
        # UI
        'tkinter', 'tkinter.ttk', 'tkinter.filedialog',
        'tkinter.messagebox', 'tkinter.simpledialog',
        # Imaging / EXIF
        'PIL', 'PIL.Image', 'PIL.ImageTk', 'piexif',
        # Network
        'requests', 'bs4',
        # Google Drive / Auth / Gemini
        'googleapiclient', 'google.auth', 'google.auth.transport.requests',
        'google.oauth2.credentials', 'google_auth_oauthlib.flow',
        'googleapiclient.discovery', 'googleapiclient.http',
        'google.generativeai', 'google.ai.generativelanguage',
        # Visual matching
        'cv2', 'imagehash', 'concurrent.futures',
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

# Onefile GUI build (single .exe, no console, UPX off — matches the old recipe).
exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='sumnabatch',
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

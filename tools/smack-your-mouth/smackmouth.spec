# -*- mode: python ; coding: utf-8 -*-
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# SMACK YOUR MOUTH build recipe — clean, complete, self-bundling.
# Cloned from coldsnap.spec. KEY: every local .py is auto-bundled (datas +
# hiddenimports) so a new module can NEVER be silently dropped from the exe —
# that dropped-module bug is what once hid the gram tabs in SYBU.
import os, glob, sys
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

# --- auto-bundle every local .py (data copy + forced hidden import) ---
_py_files   = glob.glob(os.path.join(_src, '*.py'))
_local_data = [(f, '.') for f in _py_files]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files
               if os.path.basename(f) != 'main.py']

# --- ALSO bundle shared modules (tools/_shared/*.py) that the tool imports by
#     bare name (snap_home / snap_profiles / snap_creds / snap_vault / snap_paths).
#     They are bundled FLAT next to main.py so the frozen exe resolves them the
#     same way the dev tree does (via the _add_shared_to_path bootstrap). Copy
#     flat ('.') AND force the hidden import so PyInstaller embeds them.
_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = glob.glob(os.path.join(_shared_dir, '*.py')) if os.path.isdir(_shared_dir) else []
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

a = Analysis(
    ['main.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    # Bundle an assets/ folder only if this tool actually has one (an
    # unconditional entry makes PyInstaller abort on a clean build).
    datas=_local_data + _shared_data + (
        [(os.path.join(_src, 'assets'), 'assets')]
        if os.path.isdir(os.path.join(_src, 'assets')) else []),
    hiddenimports=_local_mods + _shared_mods + [
        # UI
        'tkinter', 'tkinter.ttk', 'tkinter.filedialog',
        'tkinter.messagebox', 'tkinter.simpledialog',
        # Imaging (optional per-comment source thumbnail)
        'PIL', 'PIL.Image', 'PIL.ImageTk',
        # Network
        'requests',
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

# Onefile GUI build (single .exe, no console, UPX off — matches the recipe).
exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='smackmouth',
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
# ===== SNAPSMACK EOF =====

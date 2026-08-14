# -*- mode: python ; coding: utf-8 -*-
# CRONOMETER build recipe — clean, complete, self-bundling.
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

a = Analysis(
    ['cronometer.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    # Bundle an assets/ folder only if this tool actually has one. CRONOMETER has
    # no assets dir yet, and an unconditional entry makes PyInstaller abort with
    # "Unable to find …\assets" on a clean build.
    datas=_local_data + _shared_data + (
        [(os.path.join(_src, 'assets'), 'assets')]
        if os.path.isdir(os.path.join(_src, 'assets')) else []),
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
)
# ===== SNAPSMACK EOF =====

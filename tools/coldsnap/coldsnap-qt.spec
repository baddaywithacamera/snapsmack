# -*- mode: python ; coding: utf-8 -*-
# COLD SNAP (Qt shell) build recipe — side-by-side with the Tk coldsnap.exe.
# Same self-bundling discipline as coldsnap.spec: every local .py and every
# shared module is bundled AND force-imported, so a new module can never be
# silently dropped from the exe (the bug that once hid whole tabs).
import os, glob, sys
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH

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

a = Analysis(
    ['coldsnap_qt_launcher.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_local_data + _pkg_data + _shared_data + (
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

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='coldsnap',
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
# ===== SNAPSMACK EOF =====

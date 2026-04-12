# -*- mode: python ; coding: utf-8 -*-
import sys ; sys.setrecursionlimit(sys.getrecursionlimit() * 5)

a = Analysis(
    ['main.py'],
    pathex=[],
    binaries=[],
    datas=[],
    hiddenimports=['tkinter', 'tkinter.ttk', 'PIL', 'PIL.Image', 'PIL.ImageTk', 'piexif', 'googleapiclient', 'google.auth', 'google.auth.transport.requests', 'google.oauth2.credentials', 'google_auth_oauthlib.flow', 'googleapiclient.discovery', 'googleapiclient.http', 'google.generativeai', 'google.ai.generativelanguage'],
    hookspath=['.'],
    hooksconfig={},
    runtime_hooks=['hook-recursion.py'],
    excludes=[
        'torch', 'torchvision', 'torchaudio',
        'tensorflow', 'keras',
        'scipy', 'sklearn', 'skimage',
        'matplotlib', 'matplotlib.pyplot',
        'cv2', 'opencv',
        'transformers', 'tokenizers', 'huggingface_hub',
        'timm', 'numba', 'llvmlite',
        'pandas', 'numpy.distutils',
        'altair', 'streamlit', 'gradio',
        'IPython', 'ipykernel', 'notebook',
        'uvicorn', 'fastapi', 'starlette',
        'fsspec', 'pyarrow',
    ],
    noarchive=False,
    optimize=0,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='smackyourbatchup-0.7.7a-05',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)

# -*- mode: python ; coding: utf-8 -*-
import sys; sys.setrecursionlimit(sys.getrecursionlimit() * 5)

a = Analysis(
    ['main.py'],
    pathex=[],
    binaries=[],
    datas=[],
    hiddenimports=[
        'tkinter', 'tkinter.ttk', 'tkinter.filedialog', 'tkinter.messagebox',
        'requests',
        'googleapiclient', 'google.auth', 'google.auth.transport.requests',
        'google.oauth2.credentials', 'google_auth_oauthlib.flow',
        'googleapiclient.discovery', 'googleapiclient.http',
        'msal', 'msal.authority', 'msal.application',
        'pystray', 'PIL', 'PIL.Image', 'PIL.ImageDraw',
        'checkpoint', 'scheduler',
    ],
    excludes=[
        # AI file matching — optional, too large to bundle (several GB)
        # Users install separately: pip install sentence-transformers
        'sentence_transformers',
        'torch', 'torchvision', 'torchaudio',
        'transformers', 'tokenizers', 'huggingface_hub',
        # Heavy scientific stack pulled in by the above
        'scipy', 'sklearn', 'scikit_learn',
        'numpy', 'pandas', 'matplotlib',
        'numba', 'llvmlite',
        'fsspec', 'pyarrow',
        # Other heavy optional deps not needed at runtime
        'IPython', 'ipykernel', 'notebook',
        'pytest', 'setuptools', 'pkg_resources',
        'jinja2', 'pygments',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
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
    name='smackupyourbackup-0.2.3',
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

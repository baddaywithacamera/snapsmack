# -*- mode: python ; coding: utf-8 -*-


a = Analysis(
    ['smackpress_qt_launcher.py'],
    pathex=['../coldsnap', 'smackpress', '../_shared'],
    binaries=[],
    datas=[],
    hiddenimports=['app_cli', 'snap_cli', 'snap_connections', 'snap_profiles', 'snap_creds', 'snap_home', 'snap_hq_gate', 'snap_paths', 'snap_session_gate', 'snap_site_settings', 'snap_stepup', 'snap_vault', 'snap_single_instance', 'snap_imgsafe', 'sumna_offline', 'sumna_post', 'coldsnap_qt'],
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
        'PySide6.QtWebEngineCore', 'PySide6.QtWebEngineWidgets',
        'PySide6.QtQml', 'PySide6.QtQuick', 'PySide6.Qt3DCore',
        'PySide6.QtMultimedia', 'PySide6.QtCharts', 'PySide6.QtPdf',
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
    name='SmackPress',
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

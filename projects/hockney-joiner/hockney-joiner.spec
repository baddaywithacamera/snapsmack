# -*- mode: python ; coding: utf-8 -*-
#
# PyInstaller spec for Hockney Joiner Assembly Tool
#
# Build on Windows:
#   pip install pyinstaller
#   pyinstaller hockney-joiner.spec
#
# Build on Linux:
#   pyinstaller hockney-joiner.spec
#
# Output: dist/hockney-joiner/ (FOLDER, via COLLECT). Never one-file: the
#         dynamic Qt libraries must stay separately inspectable and
#         relinkable, which is the LGPLv3 provision SNAP SLAPPER honours in
#         tools/hub/snap_slapper.spec:187-189. upx is off for the same reason -
#         a compressed library is not a replaceable one.
#
# Notes:
#   - Models directory is NOT bundled (downloaded at first run)
#   - PySide6 is auto-detected by PyInstaller but may need Qt platform plugins
#   - On Windows, the installer will ask for scratch disk on first launch
#   - rawpy is optional; PyInstaller won't fail if absent

import sys
from pathlib import Path

ROOT = Path(SPECPATH)

block_cipher = None

a = Analysis(
    [str(ROOT / 'hockney' / 'main.py')],
    pathex=[str(ROOT)],
    binaries=[],
    datas=[
        # Include the models directory placeholder
        (str(ROOT / 'models' / '.gitkeep'), 'models'),
        # Include ETHICS.md and LICENSE
        (str(ROOT / 'ETHICS.md'), '.'),
        (str(ROOT / 'LICENSE'), '.'),
        # LGPLv3 obligations for PySide6/Qt. Shipped the same way SNAP SLAPPER
        # ships them (tools/hub/snap_slapper.spec:115): the whole licenses/
        # directory, which carries Qt-PySide6-LGPLv3-NOTICE.txt and
        # LGPL-3.0.txt. SPL permits LGPL and requires its terms be honoured.
        (str(ROOT / '..' / '..' / 'licenses'), 'licenses'),
    ],
    hiddenimports=[
        # PySide6 platform plugins. shiboken6 is PySide6's binding layer and is
        # the counterpart of PyQt6.sip, which does not exist here.
        'PySide6.QtCore',
        'PySide6.QtGui',
        'PySide6.QtWidgets',
        'shiboken6',
        # Pillow formats
        'PIL._tkinter_finder',
        'PIL.JpegImagePlugin',
        'PIL.PngImagePlugin',
        'PIL.TiffImagePlugin',
        'PIL.BmpImagePlugin',
        # OpenCV
        'cv2',
        # Numpy
        'numpy',
        # Our packages
        'hockney',
        'hockney.core',
        'hockney.ui',
        'hockney.effects',
        'hockney.installer',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        # Don't bundle torch/lightglue/moondream — downloaded at runtime
        'torch',
        'lightglue',
        'moondream',
        'huggingface_hub',
        # Remove test frameworks from bundle
        'pytest',
        'unittest',
    ],
    win_no_prefer_redirects=False,
    win_private_assemblies=False,
    cipher=block_cipher,
    noarchive=False,
)

pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

# ── One-folder build (recommended — easier to update models) ──────────────────
exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name='hockney-joiner',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,         # No terminal window on Windows
    disable_windowed_traceback=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=None,             # TODO: add icon.ico / icon.icns
)

coll = COLLECT(
    exe,
    a.binaries,
    a.zipfiles,
    a.datas,
    strip=False,
    upx=False,
    upx_exclude=[],
    name='hockney-joiner',
)

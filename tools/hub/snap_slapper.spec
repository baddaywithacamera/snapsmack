# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER (Qt / PySide6) build recipe.
import os
import sys
from PyInstaller.utils.hooks import collect_submodules

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_license_dir = os.path.normpath(os.path.join(_src, '..', '..', 'licenses'))
for _path in (_src, _shared_dir):
    if _path not in sys.path:
        sys.path.insert(0, _path)

_hidden = collect_submodules('slapper_qt') + [
    'editor_engine', 'built_in_lewks', 'found_textures', 'texture_assets',
    'highbit_image', 'highbit_decode_worker', 'blog_copy_worker', 'subprocess_limits', 'render_graph',
    'core_release_gate',
    'photo_manager', 'raw_preview', 'hdr_processor', 'slapper_filters', 'lewk_again', 'gemini_image_edit',
    'slapper_qt.external_edit',
    'slapper_provenance',
    'snap_home', 'snap_log', 'snap_profiles', 'snap_creds', 'snap_vault',
    'snap_device_auth', 'snap_native_creds', 'cryptography',
    'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
    'PIL', 'PIL.Image', 'psd_tools',
    'numpy', 'OpenImageIO',
]

a = Analysis(
    [os.path.join(_src, 'run_slapper_qt.py')],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(_license_dir, 'licenses')] +
           [(os.path.join(_src, 'local_ai', name), 'local_ai') for name in
            ('install_local_fill.py', 'local_fill_runner.py')],
    hiddenimports=_hidden,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=['torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
              'skimage', 'matplotlib', 'transformers', 'pandas', 'cv2',
              'google', 'googleapiclient', 'bs4', 'imagehash', 'IPython',
              'notebook', 'streamlit', 'gradio', 'tkinter'],
    noarchive=False,
)

# SECAUDIT 057: Qt 6.9's SVG parser has a current vendor-rated HIGH advisory.
# The beta does not accept SVG, so remove the parser and its image/icon plugins
# from the frozen payload instead of merely hiding the file-picker extension.
_svg_runtime = ('qt6svg.dll', 'qsvg.dll', 'qsvgicon.dll')
a.binaries = [entry for entry in a.binaries
              if os.path.basename(entry[0]).lower() not in _svg_runtime]
a.datas = [entry for entry in a.datas
           if os.path.basename(entry[0]).lower() not in _svg_runtime]

pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, [], exclude_binaries=True, name='SNAP SLAPPER',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None, icon='icons/snap-slapper.ico',
          contents_directory='.')

# Qt/PySide6 remains replaceable as required by LGPLv3.  Never collapse this
# recipe back to onefile: the dynamic Qt and OpenImageIO libraries must remain
# separately inspectable/relinkable in the distributed directory.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False,
              name='SNAP SLAPPER')

# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER photo manager build recipe.
import os

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_app_files = [os.path.join(_src, name) for name in
              ('snap_slapper.py', 'photo_library.py', 'photo_manager.py')]
# SECAUDIT: bundle ONLY the shared modules SNAP SLAPPER actually imports
# (snap_slapper/photo_* import just snap_home, which imports snap_paths).
# The previous glob('*.py') force-compiled the whole fleet toolkit into the
# photo editor — including the credential vault (snap_creds/snap_vault) and the
# network/discovery stack (snap_discovery + requests) — code a standalone local
# editor must never carry. Keep this list minimal; add a name only when the app
# genuinely imports it.
_shared_names = ('snap_home.py', 'snap_paths.py')
_shared_files = [os.path.join(_shared_dir, n) for n in _shared_names]
_shared_mods = [os.path.splitext(n)[0] for n in _shared_names]

a = Analysis(
    ['snap_slapper.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(path, '.') for path in _app_files + _shared_files],
    hiddenimports=['photo_library', 'photo_manager', 'PIL', 'PIL.Image', 'PIL.ImageTk'] + _shared_mods,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=['torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
              'skimage', 'matplotlib', 'transformers', 'pandas', 'numpy', 'cv2',
              'google', 'googleapiclient', 'bs4', 'imagehash', 'IPython', 'notebook',
              'streamlit', 'gradio',
              # SECAUDIT: hard-exclude the fleet credential/network stack so a
              # future stray import can never drag it back into the editor exe.
              'snap_creds', 'snap_vault', 'snap_discovery', 'snap_enrich',
              'snap_profiles', 'snap_stepup', 'snap_library', 'snap_prompt_sync',
              'requests'],
    noarchive=False,
)

pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, a.binaries, a.datas, [], name='SNAP SLAPPER',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None)

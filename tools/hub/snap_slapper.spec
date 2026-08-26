# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER photo manager build recipe.
import os
import tkinter as tk

try:
    _tk_probe = tk.Tk()
    _tk_probe.withdraw()
    _tk_probe.destroy()
except Exception as exc:
    raise SystemExit('SNAP SLAPPER build blocked: Python has no usable Tk runtime: ' + str(exc))

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_app_files = [os.path.join(_src, name) for name in
              ('snap_slapper.py', 'photo_library.py', 'photo_manager.py',
               'editor_engine.py', 'editor_ui.py', 'help_ui.py')]
_app_files.append(os.path.join(_src, 'built_in_lewks.py'))
# LEWK AGAIN (the AI look builder). Stdlib-only on the wire (urllib, no requests);
# it talks to models through the shared snap_ai client (bundled below). The
# credential vault is read lazily and remains hard-excluded, so the editor exe
# still carries no fleet auth/network code.
_app_files.append(os.path.join(_src, 'lewk_again.py'))
# Thomas the Bear — the Easter egg required by THE-THOMAS-CLAUSE. He lives in the
# shared library (tools/_shared) so every desktop app carries the same bear; the
# module is bundled via _shared_files below and the image ships alongside it.
_asset_files = [(os.path.join(_shared_dir, 'thomas-transparent.png'), '.')]
# SECAUDIT: bundle ONLY the shared modules SNAP SLAPPER actually imports
# (snap_slapper/photo_* import just snap_home, which imports snap_paths).
# The previous glob('*.py') force-compiled the whole fleet toolkit into the
# photo editor — including the credential vault (snap_creds/snap_vault) and the
# network/discovery stack (snap_discovery + requests) — code a standalone local
# editor must never carry. Keep this list minimal; add a name only when the app
# genuinely imports it.
_shared_files = [os.path.join(_shared_dir, name) for name in
                 ('snap_home.py', 'snap_paths.py', 'snap_ai.py', 'thomas.py')]
_shared_mods = [os.path.splitext(os.path.basename(path))[0] for path in _shared_files]

a = Analysis(
    ['snap_slapper.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=[(path, '.') for path in _app_files + _shared_files] + _asset_files,
    hiddenimports=['photo_library', 'photo_manager', 'editor_engine', 'editor_ui', 'help_ui', 'built_in_lewks',
                   'lewk_again',
                   'PIL', 'PIL.Image', 'PIL.ImageTk'] + _shared_mods,
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

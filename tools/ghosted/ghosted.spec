# -*- mode: python ; coding: utf-8 -*-

import os

ROOT = os.path.dirname(os.path.abspath(SPEC))
SHARED = os.path.abspath(os.path.join(ROOT, "..", "_shared"))
shared_mods = ["snap_connections", "snap_creds", "snap_home", "snap_native_creds",
               "snap_paths", "snap_profiles", "snap_single_instance", "snap_site_scope",
               "snap_vault"]

a = Analysis(
    ["app.py"],
    pathex=[ROOT, SHARED],
    binaries=[],
    datas=[
        (os.path.join(ROOT, "assets", "ghosted.png"), "assets"),
        (os.path.join(ROOT, "assets", "ghosted.ico"), "assets"),
    ],
    hiddenimports=shared_mods + ["PySide6.QtCore", "PySide6.QtGui", "PySide6.QtWidgets",
                                 "keyring.backends.Windows"],
    hookspath=[], hooksconfig={}, runtime_hooks=[], excludes=[], noarchive=False,
)
pyz = PYZ(a.pure)
exe = EXE(
    pyz, a.scripts, a.binaries, a.datas, [], name="ghosted-0.1.0-test",
    debug=False, bootloader_ignore_signals=False, strip=False, upx=True,
    upx_exclude=[], runtime_tmpdir=None, console=False,
    disable_windowed_traceback=False, argv_emulation=False,
    target_arch=None, codesign_identity=None, entitlements_file=None,
    icon=os.path.join(ROOT, "assets", "ghosted.ico"),
)

# ===== SNAPSMACK EOF =====

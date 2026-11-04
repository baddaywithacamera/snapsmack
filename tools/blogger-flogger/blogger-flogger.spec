# -*- mode: python ; coding: utf-8 -*-

import os

ROOT = os.path.dirname(os.path.abspath(SPEC))
SHARED = os.path.abspath(os.path.join(ROOT, "..", "_shared"))
shared_mods = ["snap_connections", "snap_creds", "snap_home", "snap_native_creds",
               "snap_paths", "snap_profiles", "snap_single_instance", "snap_vault"]

# ─── Qt LGPLv3: licences must travel, and the build refuses without them ─────
# SS-1195. Qt under the LGPLv3 obliges whoever distributes the combined work to
# let a recipient replace the Qt libraries with their own build. That is why
# this is a COLLECT directory and not a onefile exe: a onefile exe unpacks to a
# temporary directory at run time and cannot be relinked.
LICENSES = os.path.abspath(os.path.join(ROOT, "..", "..", "licenses"))
for _required in ("LGPL-3.0.txt", "Qt-PySide6-LGPLv3-NOTICE.txt"):
    if not os.path.isfile(os.path.join(LICENSES, _required)):
        raise SystemExit("blogger-flogger.spec: licenses/%s is missing. "
                         "Refusing to build a Qt application without it."
                         % _required)

a = Analysis(
    ["app.py"],
    pathex=[ROOT, SHARED],
    binaries=[],
    datas=[(os.path.join(ROOT, "assets", "blogger-flogger.png"), "assets"),
           (LICENSES, "licenses")],
    hiddenimports=shared_mods + ["PySide6.QtCore", "PySide6.QtGui", "PySide6.QtWidgets", "keyring.backends.Windows"],
    hookspath=[], hooksconfig={}, runtime_hooks=[], excludes=[], noarchive=False,
)
pyz = PYZ(a.pure)

# COLLECT DIRECTORY, not onefile (SS-1195) — see the licence block above.
#
# RENAMED FROM blogger-flogger-0.1.0 TO blogger-flogger, AND THAT IS THE TRAP.
# LAUNCHER CHECK, done before changing anything: tools/hub/qt_main.py's
# MIGRATION_ROSTER launches the installed tool as
#     C:/snapsmack/blogger-flogger/blogger-flogger.exe
# while build.bat produced dist/blogger-flogger-0.1.0.exe and RENAMED it on
# the way there. A COLLECT directory is copied wholesale and nothing renames
# anything inside it, so the exe has to carry its FINAL name here or SNAP HQ
# stops finding the tool. Same trap FLKR FCKR had.
#
# UPX IS OFF NOW. It was on, which is survivable for one packed exe but is a
# known way to produce Qt DLLs that will not load once they are separate files
# in a directory. The rest of the family builds with upx off; this now matches.
exe = EXE(
    pyz, a.scripts, [], exclude_binaries=True, contents_directory=".",
    name="blogger-flogger",
    debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
    upx_exclude=[], runtime_tmpdir=None, console=False,
    disable_windowed_traceback=False, argv_emulation=False,
    target_arch=None, codesign_identity=None, entitlements_file=None,
    icon=os.path.join(ROOT, "assets", "blogger-flogger.png"),
)

# Never collapse this back to onefile: the dynamic Qt libraries must stay
# separately inspectable and relinkable in the distributed directory, which is
# what the LGPLv3 asks of us. licenses/ ships beside them.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False,
              name="blogger-flogger")

# ===== SNAPSMACK EOF =====

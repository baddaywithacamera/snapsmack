# -*- mode: python ; coding: utf-8 -*-
# GET YOUR SHIT SORTED build recipe.
#
# COLLECT, NOT ONEFILE, AND THAT IS THE LICENCE. (SS-1195)
#
# Qt is used under the LGPLv3, which obliges whoever distributes the combined
# work to let a recipient replace the Qt libraries with their own build. A
# onefile exe unpacks itself to a temporary directory at run time, so its Qt
# libraries cannot be replaced and that obligation cannot be met.
#
# LAUNCHER CHECK, done before changing anything:
#   SNAP HQ       tools/hub/main.py launches the installed tool as
#                 C:/snapsmack/gyss/GET YOUR SHIT SORTED.exe
#   build.bat     copied dist/GET YOUR SHIT SORTED.exe to
#                 <install>/GET YOUR SHIT SORTED.exe.new and then moved it over
#                 the live exe -- a hot swap, so no rename of the base name
#   spec name     already 'GET YOUR SHIT SORTED', spaces and all
# So the exe name is unchanged and lands at the same path. The hot swap is the
# part that could not survive, and build.bat says what replaced it.
#
# This file was one dense line. It is spelled out because a COLLECT conversion
# and a licence guard do not fit on one line, and an unreadable build recipe is
# how these drift apart.
import os

from PyInstaller.utils.hooks import collect_submodules

root = os.path.abspath('.')
_shared = os.path.abspath('../_shared')
_icon = os.path.join(root, 'src-tauri', 'icons', 'icon.ico')

_license_dir = os.path.abspath(os.path.join(root, '..', '..', 'licenses'))
for _required in ('LGPL-3.0.txt', 'Qt-PySide6-LGPLv3-NOTICE.txt'):
    if not os.path.isfile(os.path.join(_license_dir, _required)):
        raise SystemExit('gyss.spec: licenses/%s is missing. Refusing to '
                         'build a Qt application without it.' % _required)

a = Analysis(
    ['gyss_qt.py'],
    pathex=[root, _shared],
    binaries=[],
    datas=[(_icon, '.'), (_license_dir, 'licenses')],
    hiddenimports=collect_submodules('cryptography'),
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    noarchive=False,
)

pyz = PYZ(a.pure)

# UPX IS OFF NOW. It was on. That is survivable for one packed exe but is a
# known way to produce Qt DLLs that will not load once they are separate files
# in a directory -- which is exactly what this commit makes them. The rest of
# the family builds with upx off.
exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    contents_directory='.',
    name='GET YOUR SHIT SORTED',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,
    icon=_icon,
)

# Never collapse this back to onefile: the dynamic Qt libraries must stay
# separately inspectable and relinkable in the distributed directory, which is
# what the LGPLv3 asks of us. licenses/ ships beside them.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False, name='gyss')

# ===== SNAPSMACK EOF =====

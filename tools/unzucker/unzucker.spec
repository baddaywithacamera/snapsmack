# -*- mode: python ; coding: utf-8 -*-
# UNZUCKER build recipe — Qt shell (unzucker_launcher.py -> unzucker_qt.py).
# SS-1191.
#
# Build it like this, from this directory, with the pinned runtime:
#
#   C:/dev/snapsmack/.python-build/python.exe -m PyInstaller \
#       --noconfirm --clean unzucker.spec
#
#   C:/dev/snapsmack/.python-build/python.exe ../_build/verify_exe.py \
#       "dist/unzucker/unzucker.exe" --entry unzucker_launcher --no-tk \
#       --version <BUILD_VERSION> --no-icon-check
#
# COLLECT, NOT ONEFILE, AND THAT IS A LICENCE REQUIREMENT RATHER THAN A
# PREFERENCE. Qt is used under the LGPLv3, which obliges us to let a recipient
# replace the Qt libraries with their own build. A onefile exe unpacks to a
# temporary directory at run time and cannot be relinked, so this spec follows
# the SNAP SLAPPER recipe (tools/hub/snap_slapper.spec): a COLLECT directory
# where every Qt DLL is a separate, inspectable, replaceable file, with
# licenses/ shipped alongside it.
#
# NOTE FOR WHOEVER TOUCHES FLKR FCKR NEXT: tools/flkr-fckr/flkrfckr.spec is
# ONEFILE and bundles PySide6. By the reasoning above that build does not
# satisfy the relinking obligation. Not changed here -- it is a different tool
# and a different ticket -- but it is written down rather than left for someone
# to find.
#
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
import glob
import os
import sys

sys.setrecursionlimit(sys.getrecursionlimit() * 5)

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_license_dir = os.path.normpath(os.path.join(_src, '..', '..', 'licenses'))

# Every local module and every tools/_shared module travels as BOTH a data copy
# and a forced hidden import, so a module can never be silently dropped from
# the exe. Copied from the SYBU/FLKR FCKR specs, which learned it the hard way.
#
# main.py IS DELIBERATELY EXCLUDED FROM THE IMPORT LIST. It is the old tkinter
# window; importing it would drag tkinter into a build whose whole point is not
# to have it, and the excludes below would then fail the build rather than
# silently ship it. It is also left out of the data copy, so the shipped
# directory contains no tkinter source at all.
_py_files = sorted(glob.glob(os.path.join(_src, '*.py')))
_skip = {'main.py'}
_local_data = [(f, '.') for f in _py_files if os.path.basename(f) not in _skip]
_local_mods = [os.path.splitext(os.path.basename(f))[0]
               for f in _py_files if os.path.basename(f) not in _skip]

_shared_files = sorted(glob.glob(os.path.join(_shared_dir, '*.py')))
_shared_data = [(f, '.') for f in _shared_files]
_shared_mods = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

if not os.path.isdir(_license_dir):
    raise SystemExit('unzucker.spec: licenses/ not found at %s. The LGPL-3.0 '
                     'text and the Qt notice must ship with a Qt build.'
                     % _license_dir)
for _required in ('LGPL-3.0.txt', 'Qt-PySide6-LGPLv3-NOTICE.txt'):
    if not os.path.isfile(os.path.join(_license_dir, _required)):
        raise SystemExit('unzucker.spec: licenses/%s is missing. Refusing to '
                         'build a Qt application without it.' % _required)

a = Analysis(
    [os.path.join(_src, 'unzucker_launcher.py')],
    pathex=[_src, _shared_dir],
    binaries=[],
    datas=_local_data + _shared_data + [(_license_dir, 'licenses')],
    hiddenimports=_local_mods + _shared_mods + [
        'PySide6', 'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
        'PIL', 'PIL.Image',
        'piexif',
        'requests',
        # The credential vault picks its keyring backend at run time, so static
        # analysis finds none of them without this (SECAUDIT 040 finding A).
        'cryptography', 'keyring', 'keyring.backends',
        'keyring.backends.Windows', 'keyring.backends.chainer',
        'keyring.backends.fail', 'keyring.backends.null',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        # THE POINT OF THE TICKET. If any of these reappears in the build, the
        # port did not happen; verify_exe.py --no-tk checks the same thing from
        # the other side, on the finished exe.
        'tkinter', 'tkinter.ttk', 'tkinter.filedialog', 'tkinter.messagebox',
        'tkinter.font', 'PIL.ImageTk', 'PIL._tkinter_finder',
        'torch', 'tensorflow', 'scipy', 'sklearn', 'matplotlib', 'pandas',
        'numpy.distutils', 'IPython', 'notebook',
    ],
    noarchive=False,
)

# SECAUDIT 057: Qt's SVG parser carries a vendor-rated HIGH advisory and this
# tool never renders SVG, so the parser and its image/icon plugins come out of
# the payload rather than merely being hidden from a file picker. Same removal
# as snap_slapper.spec.
_svg_runtime = ('qt6svg.dll', 'qsvg.dll', 'qsvgicon.dll')
a.binaries = [e for e in a.binaries
              if os.path.basename(e[0]).lower() not in _svg_runtime]
a.datas = [e for e in a.datas
           if os.path.basename(e[0]).lower() not in _svg_runtime]

# The Windows version resource is stamped from the ONE place the version lives,
# so Properties -> Details cannot disagree with the header's "build N.N.N".
# verify_exe.py requires this resource to be present.
_version_source = os.path.join(_src, 'unzucker_core.py')
_build_version = ''
with open(_version_source, encoding='utf-8') as _handle:
    for _line in _handle:
        if _line.startswith('BUILD_VERSION'):
            _build_version = _line.split('=', 1)[1].strip().strip('\'"')
            break
if not _build_version:
    raise SystemExit('unzucker.spec: BUILD_VERSION not found in %s'
                     % _version_source)
_parts = [int(p) for p in _build_version.split('.')[:4]]
while len(_parts) < 4:
    _parts.append(0)
_version_resource = os.path.join(_src, 'build', 'unzucker_version.txt')
os.makedirs(os.path.dirname(_version_resource), exist_ok=True)
with open(_version_resource, 'w', encoding='utf-8') as _handle:
    _handle.write("""VSVersionInfo(
  ffi=FixedFileInfo(filevers=%(t)s, prodvers=%(t)s, mask=0x3f, flags=0x0,
                    OS=0x40004, fileType=0x1, subtype=0x0, date=(0, 0)),
  kids=[
    StringFileInfo([StringTable('040904B0', [
        StringStruct('CompanyName', 'SnapSmack'),
        StringStruct('FileDescription', 'UNZUCKER'),
        StringStruct('FileVersion', '%(v)s'),
        StringStruct('InternalName', 'UNZUCKER'),
        StringStruct('OriginalFilename', 'unzucker.exe'),
        StringStruct('ProductName', 'UNZUCKER'),
        StringStruct('ProductVersion', '%(v)s')])]),
    VarFileInfo([VarStruct('Translation', [1033, 1200])])
  ]
)
""" % {'t': tuple(_parts), 'v': _build_version})

# UNZUCKER HAS NO ICON. tools/hub/icons/ holds one for eleven tools and none
# for this one, and the old build.bat passed no --icon either, so the Tk exe has
# always shipped the stock PyInstaller icon. An icon is a design asset in
# someone else's lane, so this uses one if it appears at either path the family
# would use and ships without one otherwise -- rather than inventing a picture
# or pretending the gap is not there. verify_exe.py is run with
# --no-icon-check until the asset exists.
_icon = None
for _candidate in (os.path.join(_src, 'assets', 'icon.ico'),
                   os.path.normpath(os.path.join(_src, '..', 'hub', 'icons', 'unzucker.ico'))):
    if os.path.isfile(_candidate):
        _icon = _candidate
        break

pyz = PYZ(a.pure)

exe = EXE(pyz, a.scripts, [], exclude_binaries=True, name='unzucker',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None, icon=_icon, version=_version_resource,
          contents_directory='.')

# Never collapse this back to onefile: the dynamic Qt libraries must stay
# separately inspectable and relinkable in the distributed directory, which is
# what the LGPLv3 asks of us.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False, name='unzucker')

# ===== SNAPSMACK EOF =====

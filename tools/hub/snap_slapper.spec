# -*- mode: python ; coding: utf-8 -*-
# Standalone SNAP SLAPPER (Qt / PySide6) build recipe.
import os
import sys
from PyInstaller.utils.hooks import collect_all, collect_submodules

_src = SPECPATH
_shared_dir = os.path.normpath(os.path.join(_src, '..', '_shared'))
_license_dir = os.path.normpath(os.path.join(_src, '..', '..', 'licenses'))
for _path in (_src, _shared_dir):
    if _path not in sys.path:
        sys.path.insert(0, _path)

_hidden = collect_submodules('slapper_qt') + [
    'editor_engine', 'built_in_lewks', 'found_textures', 'texture_assets',
    'highbit_image', 'gpu_acceleration', 'highbit_decode_worker', 'blog_copy_worker', 'subprocess_limits', 'render_graph',
    'core_release_gate',
    'photo_manager', 'raw_preview', 'hdr_processor', 'slapper_filters', 'lewk_again', 'gemini_image_edit',
    'slapper_qt.external_edit',
    'slapper_provenance',
    'snap_home', 'snap_log', 'snap_profiles', 'snap_creds', 'snap_vault',
    'snap_device_auth', 'snap_native_creds', 'cryptography',
    'PySide6.QtCore', 'PySide6.QtGui', 'PySide6.QtWidgets',
    'PIL', 'PIL.Image', 'PIL.TiffImagePlugin', 'psd_tools',
    'numpy', 'OpenImageIO',
]
_gpu_datas, _gpu_binaries = [], []
# gpu_acceleration imports cupy inside a function, which PyInstaller follows, so
# an ordinary build dragged in cupy's Python modules (3.4 MB) without the CUDA
# runtime they need to execute a single kernel. Exclude it unless this really is
# an NVIDIA build; gpu_acceleration already falls back to the NumPy path when
# the import fails, and its probe now reports that honestly.
_gpu_excludes = ['cupy', 'cupy_backends', 'cupyx', 'fastrlock']
if os.environ.get('SNAP_SLAPPER_NVIDIA_BUILD') == '1':
    _gpu_datas, _gpu_binaries, _gpu_hidden = collect_all('cupy')
    _hidden += _gpu_hidden
    _gpu_excludes = []

a = Analysis(
    [os.path.join(_src, 'run_slapper_qt.py')],
    pathex=[_src, _shared_dir],
    binaries=_gpu_binaries,
    datas=_gpu_datas + [(_license_dir, 'licenses')] +
           [(os.path.join(_src, 'local_ai', name), 'local_ai') for name in
            ('install_local_fill.py', 'local_fill_runner.py')],
    hiddenimports=_hidden,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=['torch', 'torchvision', 'tensorflow', 'keras', 'scipy', 'sklearn',
              'skimage', 'matplotlib', 'transformers', 'pandas', 'cv2',
              'google', 'googleapiclient', 'bs4', 'imagehash', 'IPython',
              'notebook', 'streamlit', 'gradio', 'tkinter'] + _gpu_excludes,
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

# Stamp the Windows version resource from the one place the version lives, so
# Properties -> Details agrees with the About box and a rebuild cannot silently
# ship an exe that reports nothing. verify_exe.py requires this resource.
_version_source = os.path.join(_src, 'slapper_qt', '__init__.py')
_build_version = ''
with open(_version_source, encoding='utf-8') as _handle:
    for _line in _handle:
        if _line.startswith('BUILD_VERSION'):
            _build_version = _line.split("=", 1)[1].strip().strip("'" + chr(34))
            break
if not _build_version:
    raise SystemExit('snap_slapper.spec: BUILD_VERSION not found in %s'
                     % _version_source)
_parts = [int(_p) for _p in _build_version.split('.')[:4]]
while len(_parts) < 4:
    _parts.append(0)
_version_resource = os.path.join(_src, 'build', 'snap_slapper_version.txt')
os.makedirs(os.path.dirname(_version_resource), exist_ok=True)
with open(_version_resource, 'w', encoding='utf-8') as _handle:
    _handle.write("""VSVersionInfo(
  ffi=FixedFileInfo(filevers=%(t)s, prodvers=%(t)s, mask=0x3f, flags=0x0,
                    OS=0x40004, fileType=0x1, subtype=0x0, date=(0, 0)),
  kids=[
    StringFileInfo([StringTable('040904B0', [
        StringStruct('CompanyName', 'SnapSmack'),
        StringStruct('FileDescription', 'SNAP SLAPPER'),
        StringStruct('FileVersion', '%(v)s'),
        StringStruct('InternalName', 'SNAP SLAPPER'),
        StringStruct('OriginalFilename', 'SNAP SLAPPER.exe'),
        StringStruct('ProductName', 'SNAP SLAPPER'),
        StringStruct('ProductVersion', '%(v)s')])]),
    VarFileInfo([VarStruct('Translation', [1033, 1200])])
  ]
)
""" % {'t': tuple(_parts), 'v': _build_version})

pyz = PYZ(a.pure)
exe = EXE(pyz, a.scripts, [], exclude_binaries=True, name='SNAP SLAPPER',
          debug=False, bootloader_ignore_signals=False, strip=False, upx=False,
          runtime_tmpdir=None, console=False, disable_windowed_traceback=False,
          argv_emulation=False, target_arch=None, codesign_identity=None,
          entitlements_file=None, icon='icons/snap-slapper.ico',
          version=_version_resource, contents_directory='.')

# Qt/PySide6 remains replaceable as required by LGPLv3.  Never collapse this
# recipe back to onefile: the dynamic Qt and OpenImageIO libraries must remain
# separately inspectable/relinkable in the distributed directory.
app = COLLECT(exe, a.binaries, a.datas, strip=False, upx=False,
              name='SNAP SLAPPER')

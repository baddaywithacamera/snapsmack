# -*- mode: python ; coding: utf-8 -*-
# Generic PyInstaller spec for Smack Up Your Backup.
# The exe name's version comes from the SUYB_BUILD_VER env var, which build.bat /
# build.sh set from bump_version.py. One spec for every build — no per-version
# copies. Falls back to "dev" when run directly without the env var.
import sys, os
sys.setrecursionlimit(sys.getrecursionlimit() * 5)

# Absolute path to the directory containing this spec and all .py source files.
_src = SPECPATH
_ver = os.environ.get('SUYB_BUILD_VER', 'dev')

# Bundle the shared tools/_shared modules (snap_home, snap_paths, …) that config.py
# imports dynamically by bare name after a sys.path insert. PyInstaller can't follow
# that, so include them explicitly — flat next to the exe modules — or the C:\snapsmack
# layout silently falls back to next-to-exe on the frozen build.
import glob as _glob
_shared_dir   = os.path.normpath(os.path.join(_src, '..', '_shared'))
_shared_files = _glob.glob(os.path.join(_shared_dir, '*.py'))
_shared_data  = [(f, '.') for f in _shared_files]
_shared_mods  = [os.path.splitext(os.path.basename(f))[0] for f in _shared_files]

a = Analysis(
    ['main.py'],
    pathex=[_src, _shared_dir],
    binaries=[],
    # Explicitly bundle every local .py file so PyInstaller cannot miss them.
    datas=[
        (os.path.join(_src, 'assets'),                'assets'),
        (os.path.join(_src, 'audit_engine.py'),       '.'),
        (os.path.join(_src, 'backup_engine.py'),      '.'),
        (os.path.join(_src, 'b2_integrity.py'),       '.'),
        (os.path.join(_src, 'checkpoint.py'),         '.'),
        (os.path.join(_src, 'cloud_client.py'),       '.'),
        (os.path.join(_src, 'cloud_manifest.py'),     '.'),
        (os.path.join(_src, 'cloud_sync_engine.py'),  '.'),
        (os.path.join(_src, 'config.py'),             '.'),
        (os.path.join(_src, 'coverage_engine.py'),    '.'),
        (os.path.join(_src, 'file_matcher.py'),       '.'),
        (os.path.join(_src, 'ftp_client.py'),         '.'),
        (os.path.join(_src, 'sftp_client.py'),        '.'),
        (os.path.join(_src, 'http_file_client.py'),   '.'),
        (os.path.join(_src, 'transport.py'),          '.'),
        (os.path.join(_src, 'hub_discovery.py'),      '.'),
        (os.path.join(_src, 'manifest_reader.py'),    '.'),
        (os.path.join(_src, 'path_safety.py'),        '.'),
        (os.path.join(_src, 'profile_manager.py'),    '.'),
        (os.path.join(_src, 'secret_vault.py'),       '.'),
        (os.path.join(_src, 'report_writer.py'),      '.'),
        (os.path.join(_src, 'restore_engine.py'),     '.'),
        (os.path.join(_src, 'scheduler.py'),          '.'),
        (os.path.join(_src, 'slap_happy.py'),         '.'),
        (os.path.join(_src, 'sync_manager.py'),       '.'),
        (os.path.join(_src, 'sync_manifest.py'),      '.'),
    ] + _shared_data,
    hiddenimports=_shared_mods + [
        # UI
        'tkinter', 'tkinter.ttk', 'tkinter.filedialog', 'tkinter.messagebox',
        # Network
        'requests',
        # HTTP media transport (lazy-imported inside backup_engine Stage 3)
        'http_file_client',
        # SFTP transport (paramiko + its crypto backends — dynamic imports)
        'paramiko', 'cryptography', 'cffi', 'nacl', 'bcrypt',
        # Google Drive
        'googleapiclient', 'google.auth', 'google.auth.transport.requests',
        'google.oauth2.credentials', 'google_auth_oauthlib.flow',
        'googleapiclient.discovery', 'googleapiclient.http',
        # System tray — pystray's Windows backend is imported dynamically;
        # name it explicitly or PyInstaller drops it and the icon never appears.
        'pystray', 'pystray._win32', 'pystray._base', 'pystray._util',
        'PIL', 'PIL.Image', 'PIL.ImageDraw',
        # MSAL (OneDrive auth)
        'msal',
        # Credential vault (SECAUDIT 037) + machine keychain for unattended runs.
        # keyring discovers backends via entry points, which PyInstaller misses —
        # name the backends explicitly so the frozen build can reach the keychain.
        'keyring', 'keyring.backends', 'keyring.backends.Windows',
        'keyring.backends.macOS', 'keyring.backends.SecretService',
        'keyring.backends.fail', 'keyring.backends.chainer',
        # Local modules
        'secret_vault',
        'audit_engine',
        'backup_engine',
        'b2_integrity',
        'checkpoint',
        'cloud_client',
        'cloud_manifest',
        'cloud_sync_engine',
        'config',
        'coverage_engine',
        'file_matcher',
        'ftp_client',
        'sftp_client',
        'transport',
        'hub_discovery',
        'manifest_reader',
        'path_safety',
        'profile_manager',
        'report_writer',
        'restore_engine',
        'scheduler',
        'slap_happy',
        'sync_manager',
        'sync_manifest',
    ],
    excludes=[
        # AI file matching — optional, too large to bundle (several GB)
        'sentence_transformers',
        'torch', 'torchvision', 'torchaudio',
        'transformers', 'tokenizers', 'huggingface_hub',
        'scipy', 'sklearn', 'scikit_learn',
        'numpy', 'pandas', 'matplotlib',
        'numba', 'llvmlite',
        'fsspec', 'pyarrow',
        'IPython', 'ipykernel', 'notebook',
        'pytest', 'setuptools', 'pkg_resources',
        'jinja2', 'pygments',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
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
    name='smackupyourbackup-' + _ver,
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,          # debug output goes to suyb-debug.log next to the exe
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=os.path.join(_src, 'assets', 'suyb.ico'),
)

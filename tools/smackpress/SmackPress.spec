# -*- mode: python ; coding: utf-8 -*-


a = Analysis(
    ['smackpress_qt_launcher.py'],
    pathex=['../coldsnap', 'smackpress', '../_shared'],
    binaries=[],
    datas=[],
    hiddenimports=['snap_connections', 'snap_profiles', 'snap_creds', 'snap_home', 'snap_site_settings', 'snap_vault', 'snap_single_instance', 'snap_imgsafe', 'sumna_offline', 'sumna_post', 'coldsnap_qt'],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
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
    name='SmackPress',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)

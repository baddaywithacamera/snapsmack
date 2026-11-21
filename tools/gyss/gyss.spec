import os
from PyInstaller.utils.hooks import collect_submodules
root=os.path.abspath('.')
a=Analysis(['gyss_qt.py'],pathex=[root,os.path.abspath('../_shared')],binaries=[],datas=[(os.path.join(root,'src-tauri','icons','icon.ico'),'.')],hiddenimports=collect_submodules('cryptography')+['app_cli','gyss_api','snap_cli','snap_connections','snap_creds','snap_enrichment_cache','snap_home','snap_hq_gate','snap_library','snap_paths','snap_profiles','snap_session_gate','snap_site_scope','snap_stepup','snap_vault'],hookspath=[],hooksconfig={},runtime_hooks=[],excludes=[],noarchive=False)
pyz=PYZ(a.pure)
exe=EXE(pyz,a.scripts,a.binaries,a.datas,[],name='GET YOUR SHIT SORTED',debug=False,bootloader_ignore_signals=False,strip=False,upx=True,console=False,icon=os.path.join(root,'src-tauri','icons','icon.ico'))
# ===== SNAPSMACK EOF =====

@echo off
REM SMACKPRESS -- build a portable Windows exe (SNAPSMACK). Qt shell on COLD SNAP's engine.
cd /d "%~dp0"
echo === Installing build deps (pyinstaller + PySide6) ===
python -m pip install --upgrade pyinstaller PySide6 requests keyring
echo === Building SmackPress.exe ===
python -m PyInstaller --noconfirm --clean --onefile --windowed --name SmackPress --paths smackpress --paths ..\coldsnap --paths ..\_shared --collect-all PySide6 --hidden-import snap_connections --hidden-import snap_profiles --hidden-import snap_creds --hidden-import snap_home --hidden-import snap_site_settings --hidden-import snap_vault --hidden-import snap_single_instance --hidden-import snap_imgsafe --hidden-import sumna_offline --hidden-import sumna_post --hidden-import coldsnap_qt smackpress_qt_launcher.py
if errorlevel 1 goto fail
set DEPLOY=C:\snapsmack\smackpress
if not exist "%DEPLOY%" mkdir "%DEPLOY%"
copy /y "dist\SmackPress.exe" "%DEPLOY%\SmackPress.exe"
C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py "%DEPLOY%\SmackPress.exe"
echo === Done: %DEPLOY%\SmackPress.exe ===
goto end
:fail
echo BUILD FAILED
exit /b 1
:end

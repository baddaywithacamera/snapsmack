@echo off
REM SmackPress -- build a portable Windows exe (SNAPSMACK)
cd /d "%~dp0"
echo === Installing build deps (pyinstaller + customtkinter) ===
python -m pip install --upgrade pyinstaller customtkinter
echo === Building SmackPress.exe ===
python -m PyInstaller --noconfirm --clean --onefile --windowed --name SmackPress --paths smackpress --collect-all customtkinter --hidden-import config --hidden-import db --hidden-import wp_client --hidden-import smacktalk_client --hidden-import ai_client app.py
if errorlevel 1 goto fail
set DEPLOY=C:\smackpress
if not exist "%DEPLOY%" mkdir "%DEPLOY%"
copy /y "dist\SmackPress.exe" "%DEPLOY%\SmackPress.exe"
echo.
echo === Done ===
echo Exe:   %DEPLOY%\SmackPress.exe
echo State: smackpress.db rides next to the exe and survives rebuilds.
pause
exit /b 0
:fail
echo BUILD FAILED -- see errors above.
pause
exit /b 1

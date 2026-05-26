@echo off
REM FLKR DCKR — build.bat
REM Builds a single-file Windows .exe via PyInstaller.
REM Run from the tools/flkr-dckr/ directory.
REM Forked from tools/unzucker/build.bat.

echo === FLKR DCKR build ===

REM Install / upgrade dependencies
pip install --upgrade pyinstaller pillow requests

REM Build single-file exe
pyinstaller --onefile --windowed --name flkrdckr --icon assets\icon.ico main.py

echo.
echo Done. Exe is in dist\flkrdckr.exe
pause
# ===== SNAPSMACK EOF =====

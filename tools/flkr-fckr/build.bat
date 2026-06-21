@echo off
REM FLKR FCKR — build.bat
REM Builds a single-file Windows .exe via PyInstaller.
REM Run from the tools/flkr-fckr/ directory.
REM Forked from tools/unzucker/build.bat.

echo === FLKR FCKR build ===

REM Install / upgrade dependencies
pip install --upgrade pyinstaller pillow requests

REM Auto-increment the 0.7.xx build version (main.py BUILD_VERSION) before building
python bump_version.py

REM Build single-file exe
pyinstaller --onefile --windowed --name flkrfckr --icon assets\icon.ico main.py

echo.
echo Done. Exe is in dist\flkrfckr.exe
pause
# ===== SNAPSMACK EOF =====

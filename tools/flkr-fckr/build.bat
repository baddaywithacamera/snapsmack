@echo off
REM FLKR FCKR — build.bat
REM Builds a single-file Windows .exe via PyInstaller.
REM Run from the tools/flkr-fckr/ directory.
REM Forked from tools/unzucker/build.bat.

echo === FLKR FCKR build ===

REM Install / upgrade dependencies
pip install --upgrade pyinstaller pillow requests

REM Auto-increment the 0.7.xx build version (main.py BUILD_VERSION) before building.
REM Try the py launcher first, then python; ABORT loudly if neither bumps so a
REM stale, un-versioned exe is never shipped silently (this was the bug: a bare
REM "python" call no-oped when only the py launcher was on PATH).
py bump_version.py || python bump_version.py
if errorlevel 1 (
    echo.
    echo *** VERSION BUMP FAILED - is Python on PATH? Build aborted. ***
    pause
    exit /b 1
)

REM Build single-file exe
pyinstaller --onefile --windowed --name flkrfckr --icon assets\icon.ico main.py

echo.
echo Done. Exe is in dist\flkrfckr.exe
pause
# ===== SNAPSMACK EOF =====

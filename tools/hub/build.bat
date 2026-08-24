@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  SNAP SLAPPER — build script
REM  Requires: Python 3.10+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
REM  hub.spec auto-bundles every local .py AND tools/_shared/*.py.
REM ─────────────────────────────────────────────────────────────────────────

set SPEC_FILE=hub.spec
if not exist %SPEC_FILE% (
    echo ERROR: %SPEC_FILE% not found.
    pause
    exit /b 1
)

if exist build rmdir /s /q build
if exist dist  rmdir /s /q dist

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building SNAP SLAPPER.exe...
if not exist C:\snapsmack\snap_slapper mkdir C:\snapsmack\snap_slapper
pyinstaller --clean %SPEC_FILE% --distpath "C:\snapsmack\snap_slapper"

echo.
if exist "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe" (
    echo Build successful: C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\SNAP SLAPPER.lnk'); $s.TargetPath='C:\snapsmack\snap_slapper\SNAP SLAPPER.exe'; $s.WorkingDirectory='C:\snapsmack\snap_slapper'; $s.Save()"
    echo Start Menu shortcut updated.
) else (
    echo Build FAILED — check output above.
    pause
    exit /b 1
)

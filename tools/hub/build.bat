@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  THE HUB + standalone SNAP SLAPPER — build script
REM  Requires: Python 3.10+, pip install -r requirements.txt
REM  Outputs:  C:\snapsmack\hub\hub.exe
REM            C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
REM ─────────────────────────────────────────────────────────────────────────

if not exist hub.spec (
    echo ERROR: hub.spec not found.
    pause
    exit /b 1
)
if not exist snap_slapper.spec (
    echo ERROR: snap_slapper.spec not found.
    pause
    exit /b 1
)

if exist build rmdir /s /q build
if exist dist  rmdir /s /q dist

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building THE HUB...
if not exist C:\snapsmack\hub mkdir C:\snapsmack\hub
pyinstaller --clean hub.spec --distpath "C:\snapsmack\hub"

echo.
echo Building standalone SNAP SLAPPER...
if not exist C:\snapsmack\snap_slapper mkdir C:\snapsmack\snap_slapper
pyinstaller --clean snap_slapper.spec --distpath "C:\snapsmack\snap_slapper"

echo.
if exist "C:\snapsmack\hub\hub.exe" if exist "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe" (
    echo Build successful: C:\snapsmack\hub\hub.exe
    echo Build successful: C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\SNAP SLAPPER.lnk'); $s.TargetPath='C:\snapsmack\snap_slapper\SNAP SLAPPER.exe'; $s.WorkingDirectory='C:\snapsmack\snap_slapper'; $s.Save()"
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\THE HUB.lnk'); $s.TargetPath='C:\snapsmack\hub\hub.exe'; $s.WorkingDirectory='C:\snapsmack\hub'; $s.Save()"
    echo Start Menu shortcuts updated.
) else (
    echo Build FAILED — check output above.
    pause
    exit /b 1
)

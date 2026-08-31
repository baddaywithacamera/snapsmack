@echo off
setlocal
cd /d "%~dp0"
set "BUILD_PYTHON=%~dp0..\..\.python-build\python.exe"
if not exist "%BUILD_PYTHON%" set "BUILD_PYTHON=python"
REM -------------------------------------------------------------------------
REM  THE HUB + standalone SNAP SLAPPER - build script
REM  Requires: Python 3.10+, pip install -r requirements.txt
REM  Outputs:  C:\snapsmack\hub\hub.exe
REM            C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
REM -------------------------------------------------------------------------

if not exist hub.spec (
    echo ERROR: hub.spec not found.
    pause
    exit /b 1
)
if not exist slapper_qt.spec (
    echo ERROR: slapper_qt.spec not found.
    pause
    exit /b 1
)

"%BUILD_PYTHON%" -c "from PySide6.QtWidgets import QApplication; app=QApplication([]); app.quit()"
if errorlevel 1 (
    echo ERROR: This Python installation does not contain a working Qt runtime.
    echo SNAP SLAPPER cannot be packaged into a usable desktop application here.
    echo Run: powershell -NoProfile -ExecutionPolicy Bypass -File bootstrap-build-runtime.ps1
    pause
    exit /b 1
)

if exist build rmdir /s /q build
if exist dist  rmdir /s /q dist

echo Installing dependencies...
"%BUILD_PYTHON%" -m pip install -r requirements.txt

echo.
echo Building THE HUB...
if not exist C:\snapsmack\hub mkdir C:\snapsmack\hub
"%BUILD_PYTHON%" -m PyInstaller --clean hub.spec --distpath "C:\snapsmack\hub"

echo.
echo Building standalone SNAP SLAPPER...
if not exist "dist\snap_slapper" mkdir "dist\snap_slapper"
"%BUILD_PYTHON%" -m PyInstaller --noconfirm --clean slapper_qt.spec --distpath "dist\snap_slapper"
if errorlevel 1 (
    echo ERROR: SNAP SLAPPER packaging failed.
    pause
    exit /b 1
)

set "SNAPSMACK_HOME=%TEMP%\snap_slapper_build_qa_home"
set "SNAP_SLAPPER_QA_IMAGE=%TEMP%\snap_slapper_build_qa.png"
set "SNAP_SLAPPER_QA_MARKER=%TEMP%\snap_slapper_build_qa.pass"
set "QT_QPA_PLATFORM=offscreen"
if exist "%SNAP_SLAPPER_QA_MARKER%" del /q "%SNAP_SLAPPER_QA_MARKER%"
"%BUILD_PYTHON%" -c "from PIL import Image; Image.new('RGB',(80,60),(180,40,20)).save(r'%SNAP_SLAPPER_QA_IMAGE%')"
if errorlevel 1 (
    echo ERROR: Could not create the packaged-build QA photograph.
    pause
    exit /b 1
)
start "" /wait "dist\snap_slapper\SNAP SLAPPER.exe"
if not exist "%SNAP_SLAPPER_QA_MARKER%" (
    echo ERROR: Packaged SNAP SLAPPER failed its real-image startup check.
    pause
    exit /b 1
)
del /q "%SNAP_SLAPPER_QA_IMAGE%" "%SNAP_SLAPPER_QA_MARKER%"
set "SNAP_SLAPPER_QA_IMAGE="
set "SNAP_SLAPPER_QA_MARKER="
set "QT_QPA_PLATFORM="

if not exist "C:\snapsmack\snap_slapper" mkdir "C:\snapsmack\snap_slapper"
copy /b /y "dist\snap_slapper\SNAP SLAPPER.exe" "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe.new" >nul
if errorlevel 1 (
    echo ERROR: Could not stage the verified SNAP SLAPPER executable for installation.
    pause
    exit /b 1
)
move /y "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe.new" "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe" >nul
if errorlevel 1 (
    echo ERROR: Could not promote the verified SNAP SLAPPER executable.
    echo The previously installed executable was left untouched.
    pause
    exit /b 1
)

echo.
if exist "C:\snapsmack\hub\hub.exe" if exist "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe" (
    echo Build successful: C:\snapsmack\hub\hub.exe
    echo Build successful: C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\SNAP SLAPPER.lnk'); $s.TargetPath='C:\snapsmack\snap_slapper\SNAP SLAPPER.exe'; $s.WorkingDirectory='C:\snapsmack\snap_slapper'; $s.Save()"
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\THE HUB.lnk'); $s.TargetPath='C:\snapsmack\hub\hub.exe'; $s.WorkingDirectory='C:\snapsmack\hub'; $s.Save()"
    echo Start Menu shortcuts updated.
) else (
    echo Build FAILED - check output above.
    pause
    exit /b 1
)

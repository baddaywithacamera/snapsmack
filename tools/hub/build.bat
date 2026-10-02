@echo off
setlocal
cd /d "%~dp0"
set "BUILD_PYTHON=%~dp0..\..\.python-build\python.exe"
if not exist "%BUILD_PYTHON%" set "BUILD_PYTHON=python"
REM -------------------------------------------------------------------------
REM  THE HUB + standalone SNAP SLAPPER - build script
REM  Requires: Python 3.10+, pip install -r requirements.txt
REM  Outputs:  C:\snapsmack\hub\SNAP HQ.exe
REM            C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
REM -------------------------------------------------------------------------

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
if errorlevel 1 exit /b 1
"%BUILD_PYTHON%" -m pip install pip-audit==2.10.1
if errorlevel 1 exit /b 1
"%BUILD_PYTHON%" -m pip_audit -r requirements.txt
if errorlevel 1 (
    echo ERROR: SNAP SLAPPER dependency vulnerability audit failed.
    exit /b 1
)

echo.
echo Building SNAP HQ...
if not exist C:\snapsmack\hub mkdir C:\snapsmack\hub
"%BUILD_PYTHON%" -m PyInstaller --clean hub.spec --distpath "C:\snapsmack\hub"

for /f "tokens=2 delims==" %%v in ('findstr /b "BUILD_VERSION" slapper_qt\__init__.py') do set "SNAP_SLAPPER_VERSION=%%~v"
set "SNAP_SLAPPER_VERSION=%SNAP_SLAPPER_VERSION: =%"
set "SNAP_SLAPPER_VERSION=%SNAP_SLAPPER_VERSION:"=%"
if "%SNAP_SLAPPER_VERSION%"=="" (
    echo ERROR: Could not read BUILD_VERSION from slapper_qt\__init__.py.
    exit /b 1
)

echo.
echo Building standalone SNAP SLAPPER... %SNAP_SLAPPER_VERSION%
if not exist "dist\snap_slapper" mkdir "dist\snap_slapper"
"%BUILD_PYTHON%" -m PyInstaller --noconfirm --clean snap_slapper.spec --distpath "dist\snap_slapper"
if errorlevel 1 (
    echo ERROR: SNAP SLAPPER packaging failed.
    pause
    exit /b 1
)

REM The build gate. SYBU 0.7.67 shipped the tkinter app with the right version
REM number on it, which is why the entry script is checked and not just the
REM version. SNAP SLAPPER had never been run through this.
"%BUILD_PYTHON%" "%~dp0..\_build\verify_exe.py" "dist\snap_slapper\SNAP SLAPPER.exe" --entry run_slapper_qt --no-tk --version %SNAP_SLAPPER_VERSION%
if errorlevel 1 (
    echo ERROR: SNAP SLAPPER failed its build gate. Nothing was installed.
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
"%BUILD_PYTHON%" trust-installed-exe.py "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe"
if errorlevel 1 exit /b 1

echo.
if exist "C:\snapsmack\hub\SNAP HQ.exe" if exist "C:\snapsmack\snap_slapper\SNAP SLAPPER.exe" (
    echo Build successful: C:\snapsmack\hub\SNAP HQ.exe
    echo Build successful: C:\snapsmack\snap_slapper\SNAP SLAPPER.exe
    if not exist "C:\snapsmack\hub\icons" mkdir "C:\snapsmack\hub\icons"
    copy /y "icons\*.ico" "C:\snapsmack\hub\icons\" >nul
    copy /y "icons\*.png" "C:\snapsmack\hub\icons\" >nul
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\SNAP SLAPPER.lnk'); $s.TargetPath='C:\snapsmack\snap_slapper\SNAP SLAPPER.exe'; $s.WorkingDirectory='C:\snapsmack\snap_slapper'; $s.Save()"
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('StartMenu')+'\Programs\SNAP HQ.lnk'); $s.TargetPath='C:\snapsmack\hub\SNAP HQ.exe'; $s.WorkingDirectory='C:\snapsmack\hub'; $s.IconLocation='C:\snapsmack\hub\icons\snap-hq.ico,0'; $s.Save()"
    echo Start Menu shortcuts updated.
) else (
    echo Build FAILED - check output above.
    pause
    exit /b 1
)

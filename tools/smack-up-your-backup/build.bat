@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Up Your Backup — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\suyb\smackupyourbackup-{version}.exe
REM  Portable app: the exe + its config.ini live together in this stable folder,
REM  so settings persist across rebuilds. Never deploy into %APPDATA%.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Auto-increment the patch version in main.py, then read it back ────────
REM  bump_version.py bumps BUILD_VERSION and prints the new value on stdout.
for /f "delims=" %%V in ('python bump_version.py') do set BUILD_VER=%%V
if "%BUILD_VER%"=="" (
    echo ERROR: version bump failed ^(bump_version.py^). Aborting.
    pause
    exit /b 1
)
set EXE_NAME=smackupyourbackup-%BUILD_VER%.exe
echo Build version: %BUILD_VER%  ^(auto-incremented^)
echo Output name:   %EXE_NAME%

REM ── Clean stale build artifacts (prevents OneDrive / AV lock errors) ──────
if exist build (
    echo Cleaning previous build folder...
    rmdir /s /q build
)
if exist dist (
    echo Cleaning previous dist folder...
    rmdir /s /q dist
)

echo Installing dependencies...
pip install -r requirements.txt

REM ── Build with the single generic spec (version comes from the env var) ──────
set SUYB_BUILD_VER=%BUILD_VER%
echo.
echo Building %EXE_NAME% using smackupyourbackup.spec...
pyinstaller --clean smackupyourbackup.spec

echo.
if exist dist\smackupyourbackup-%BUILD_VER%\suyb.exe (
    echo Build successful: dist\smackupyourbackup-%BUILD_VER%\suyb.exe
    echo.

    REM THE HOT SWAP IS GONE, AND THAT IS A REAL CHANGE (SS-1195).
    REM
    REM This used to copy the new exe to suyb.exe.new and MOVE it over the live
    REM suyb.exe, so the tool could be replaced while running. A COLLECT build
    REM is a DIRECTORY of a hundred-odd files and Windows cannot swap a
    REM directory atomically while a file inside it is open, so there is no
    REM equivalent. Copying over a running install would half-replace it: new
    REM DLLs beside the old locked exe. Refusing is better than that.
    tasklist /FI "IMAGENAME eq suyb.exe" 2>nul | find /I "suyb.exe" >nul
    if not errorlevel 1 (
        echo SMACK UP YOUR BACKUP is RUNNING. Close it and build again.
        echo The install was NOT touched.
        exit /b 1
    )

    echo Deploying to C:\snapsmack\suyb...
    if not exist C:\snapsmack\suyb mkdir C:\snapsmack\suyb
    REM /E copies the whole directory - Qt, pystray and paramiko libraries and
    REM licenses\ included. Copying only the exe leaves something that cannot
    REM start. suyb.exe lands at C:\snapsmack\suyb\suyb.exe, which is the path
    REM tools\hub\main.py launches.
    xcopy /E /I /Y "dist\smackupyourbackup-%BUILD_VER%\*" "C:\snapsmack\suyb\"
    if errorlevel 1 exit /b 1

    REM The version-named ARCHIVE the old version-named exe used to provide, now
    REM a sibling directory - the shape COLD SNAP already uses on this machine.
    REM NOTE: that is about 90 MB per build rather than one 90 MB file, so prune
    REM old ones when the disk gets tight.
    xcopy /E /I /Y "dist\smackupyourbackup-%BUILD_VER%\*" "C:\snapsmack\suyb-%BUILD_VER%\"
    if errorlevel 1 exit /b 1
    C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py C:\snapsmack\suyb\suyb.exe
    if errorlevel 1 exit /b 1
    echo Done. Launch: C:\snapsmack\suyb\suyb.exe
) else (
    echo Build FAILED. Check output above for errors.
)
pause

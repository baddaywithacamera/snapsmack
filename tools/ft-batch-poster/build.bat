@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  ft-batch-poster build script
REM  Requires: Python 3.11+, pip install -r requirements.txt, exiftool.exe
REM            AND exiftool_files\ folder in this folder.
REM  Output:   C:\tools\ss-batch-poster-{version}.exe
REM ─────────────────────────────────────────────────────────────────────────

echo Checking for exiftool.exe...
if not exist exiftool.exe (
    echo.
    echo ERROR: exiftool.exe not found in this folder.
    echo Download the Windows standalone build from https://exiftool.org
    echo and place exiftool.exe AND the exiftool_files\ folder here before building.
    echo.
    pause
    exit /b 1
)

echo Checking for exiftool_files\...
if not exist exiftool_files\ (
    echo.
    echo ERROR: exiftool_files\ folder not found in this folder.
    echo ExifTool requires this folder alongside exiftool.exe to function.
    echo Download the Windows standalone build from https://exiftool.org
    echo and place both exiftool.exe AND exiftool_files\ here before building.
    echo.
    pause
    exit /b 1
)

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
REM Strip surrounding quotes
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=ss-batch-poster-%BUILD_VER%.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building %EXE_NAME%...
pyinstaller ^
    --onefile ^
    --windowed ^
    --clean ^
    --name ss-batch-poster-%BUILD_VER% ^
    --hidden-import=tkinter ^
    --hidden-import=tkinter.ttk ^
    --hidden-import=PIL ^
    --hidden-import=PIL.Image ^
    --hidden-import=PIL.ImageTk ^
    --hidden-import=googleapiclient ^
    --hidden-import=google.auth ^
    --hidden-import=google.auth.transport.requests ^
    --hidden-import=google.oauth2.credentials ^
    --hidden-import=google_auth_oauthlib.flow ^
    --hidden-import=googleapiclient.discovery ^
    --hidden-import=googleapiclient.http ^
    main.py

echo.
if exist dist\%EXE_NAME% (
    echo Build successful: dist\%EXE_NAME%
    echo Copying exiftool.exe to dist\...
    copy /Y exiftool.exe dist\exiftool.exe
    echo Copying exiftool_files\ to dist\exiftool_files\...
    robocopy exiftool_files dist\exiftool_files /E /NFL /NDL /NJH /NJS >nul

    echo.
    echo Deploying to C:\tools...
    copy /Y dist\%EXE_NAME% C:\tools\%EXE_NAME%
    copy /Y dist\exiftool.exe C:\tools\exiftool.exe
    robocopy dist\exiftool_files C:\tools\exiftool_files /E /NFL /NDL /NJH /NJS >nul
    echo Done. Launch: C:\tools\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause

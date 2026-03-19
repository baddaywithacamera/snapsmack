@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  ft-batch-poster build script
REM  Requires: Python 3.11+, pip install -r requirements.txt, exiftool.exe
REM            AND exiftool_files\ folder in this folder.
REM  Output:   dist\ft-batch-poster.exe  (single file, no install needed)
REM  Distribute: dist\ft-batch-poster.exe + dist\exiftool.exe + dist\exiftool_files\
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

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building ft-batch-poster.exe...
pyinstaller ^
    --onefile ^
    --windowed ^
    --clean ^
    --name ss-batch-poster ^
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
if exist dist\ss-batch-poster.exe (
    echo Build successful: dist\ss-batch-poster.exe
    echo Copying exiftool.exe to dist\...
    copy /Y exiftool.exe dist\exiftool.exe
    echo Copying exiftool_files\ to dist\exiftool_files\...
    robocopy exiftool_files dist\exiftool_files /E /NFL /NDL /NJH /NJS >nul

    echo.
    echo Deploying to C:\tools...
    copy /Y dist\ss-batch-poster.exe C:\tools\ss-batch-poster.exe
    copy /Y dist\exiftool.exe C:\tools\exiftool.exe
    robocopy dist\exiftool_files C:\tools\exiftool_files /E /NFL /NDL /NJH /NJS >nul
    echo Done. C:\tools is up to date.
) else (
    echo Build FAILED. Check output above for errors.
)
pause

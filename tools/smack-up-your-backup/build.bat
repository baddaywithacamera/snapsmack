@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Up Your Backup — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\SmackUpYourBackup\smackupyourbackup-{version}.exe
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=smackupyourbackup-%BUILD_VER%.exe
echo Build version: %BUILD_VER%
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

echo.
echo Building %EXE_NAME%...
pyinstaller ^
    --onefile ^
    --windowed ^
    --clean ^
    --name smackupyourbackup-%BUILD_VER% ^
    --hidden-import=tkinter ^
    --hidden-import=tkinter.ttk ^
    --hidden-import=tkinter.filedialog ^
    --hidden-import=tkinter.messagebox ^
    --hidden-import=requests ^
    --hidden-import=googleapiclient ^
    --hidden-import=google.auth ^
    --hidden-import=google.auth.transport.requests ^
    --hidden-import=google.oauth2.credentials ^
    --hidden-import=google_auth_oauthlib.flow ^
    --hidden-import=googleapiclient.discovery ^
    --hidden-import=googleapiclient.http ^
    --hidden-import=msal ^
    --hidden-import=msal.authority ^
    --hidden-import=msal.application ^
    main.py

echo.
if exist dist\%EXE_NAME% (
    echo Build successful: dist\%EXE_NAME%
    echo.
    echo Deploying to C:\SmackUpYourBackup...
    if not exist C:\SmackUpYourBackup mkdir C:\SmackUpYourBackup
    copy /Y dist\%EXE_NAME% C:\SmackUpYourBackup\%EXE_NAME%
    echo Done. Launch: C:\SmackUpYourBackup\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause

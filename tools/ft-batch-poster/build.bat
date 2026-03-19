@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Your Batch Up — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\tools\smackyourbatchup-{version}.exe
REM  EXIF is handled by piexif (pure Python) — no external dependencies.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=smackyourbatchup-%BUILD_VER%.exe
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
    --name smackyourbatchup-%BUILD_VER% ^
    --hidden-import=tkinter ^
    --hidden-import=tkinter.ttk ^
    --hidden-import=PIL ^
    --hidden-import=PIL.Image ^
    --hidden-import=PIL.ImageTk ^
    --hidden-import=piexif ^
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
    echo.
    echo Deploying to C:\tools...
    copy /Y dist\%EXE_NAME% C:\tools\%EXE_NAME%
    echo Done. Launch: C:\tools\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause

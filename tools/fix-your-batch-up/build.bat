@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Fix Your Batch Up — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\FixYourBatchUp\fixyourbatchup-{version}.exe
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=fixyourbatchup-%BUILD_VER%.exe
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
pip install pyinstaller

echo.
echo Building %EXE_NAME%...
pyinstaller ^
    --onefile ^
    --windowed ^
    --clean ^
    --name fixyourbatchup-%BUILD_VER% ^
    --paths=. ^
    --paths=..\ft-batch-poster ^
    --hidden-import=tkinter ^
    --hidden-import=tkinter.ttk ^
    --hidden-import=PIL ^
    --hidden-import=PIL.Image ^
    --hidden-import=PIL.ImageTk ^
    --hidden-import=cv2 ^
    --hidden-import=imagehash ^
    --hidden-import=scipy ^
    --hidden-import=scipy.fftpack ^
    --hidden-import=pywt ^
    --hidden-import=numpy ^
    --hidden-import=requests ^
    --hidden-import=googleapiclient ^
    --hidden-import=google.auth ^
    --hidden-import=google.auth.transport.requests ^
    --hidden-import=google.oauth2.credentials ^
    --hidden-import=google_auth_oauthlib.flow ^
    --hidden-import=googleapiclient.discovery ^
    --hidden-import=googleapiclient.http ^
    --hidden-import=matcher ^
    --hidden-import=local_drive ^
    --hidden-import=drive ^
    --hidden-import=multiprocessing ^
    --hidden-import=multiprocessing.pool ^
    --hidden-import=concurrent.futures ^
    --collect-all=cv2 ^
    --collect-all=imagehash ^
    main.py

echo.
if exist dist\%EXE_NAME% (
    echo Build successful: dist\%EXE_NAME%
    echo.
    echo Deploying to C:\FixYourBatchUp...
    if not exist C:\FixYourBatchUp mkdir C:\FixYourBatchUp
    copy /Y dist\%EXE_NAME% C:\FixYourBatchUp\%EXE_NAME%
    echo Done. Launch: C:\FixYourBatchUp\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause
